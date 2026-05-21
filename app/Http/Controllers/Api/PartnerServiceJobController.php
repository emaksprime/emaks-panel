<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2B\B2BPartner;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\User;
use App\Services\B2B\B2BPartnerPortalDataService;
use App\Services\B2B\B2BPartnerServiceJobScopeService;
use App\Services\PanelAccessService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class PartnerServiceJobController extends Controller
{
    private const SIMPLE_CHECKLIST = [
        'customer_contacted',
        'address_confirmed',
        'appointment_confirmed',
        'door_product_checked',
        'job_completed',
        'customer_informed',
    ];

    private const TECHNICAL_CHECKLIST = [
        'Ürün seri numarası kontrol edildi',
        'Kapı / montaj yeri kontrol edildi',
        'Montaj uygunluğu kontrol edildi',
        'Ürün çalışır durumda test edildi',
        'Müşteriye kullanım bilgisi verildi',
        'Garanti / servis formu bilgisi kontrol edildi',
    ];

    private const REQUIRED_DOOR_PHOTO_FIELDS = [
        'door_front_photo',
        'door_side_photo',
        'door_back_photo',
    ];

    public function __construct(
        private readonly B2BPartnerServiceJobScopeService $scope,
        private readonly B2BPartnerPortalDataService $portalData,
        private readonly TechnicalServiceWorkflowService $workflow,
        private readonly PanelAccessService $panelAccess,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authorizedUser($request, 'partner.service_jobs.view');

        $jobs = $this->scope
            ->queryVisibleServiceJobs($user)
            ->with([
                'partnerJobActions' => fn ($query) => $query->latest(),
                'uploads',
            ])
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (TechnicalServiceRequest $job): array => $this->portalData->safeServiceJobSummary($job))
            ->values()
            ->all();

        return response()->json([
            'status' => 'ok',
            'columns' => $this->kanbanColumns($jobs),
            'jobs' => $jobs,
        ]);
    }

    public function show(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);

        return response()->json([
            'status' => 'ok',
            'partner_id' => $partner->id,
            'job' => $this->portalData->safeServiceJobSummary($job),
        ]);
    }

    public function accept(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($job, $partner, $user, $data): array {
            $from = $job->workflow_status;
            $status = TechnicalServicePartnerJobAction::STATUS_SUBMITTED;
            $payload = ['note' => $data['note'] ?? null];

            if (in_array($job->workflow_status, ['Usta Onayı Bekleyen', 'Usta OnayÄ± Bekleyen'], true)) {
                try {
                    $job = $this->workflow->transition(
                        $job,
                        'Planlı',
                        [
                            'technician_approved_at' => now(),
                            'note' => $data['note'] ?? 'Partner portalından randevu onaylandı.',
                        ],
                        $user,
                        'partner_portal_accepted',
                    );
                    $status = TechnicalServicePartnerJobAction::STATUS_APPLIED;
                } catch (Throwable $exception) {
                    $payload['workflow_error'] = $exception->getMessage();
                    $status = TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW;
                }
            }

            $this->recordAction($job->refresh(), $partner, $user, TechnicalServicePartnerJobAction::ACTION_ACCEPTED, $status, $payload, $data['note'] ?? null, $from);

            return $this->jobResponse($job->refresh(), $status);
        });

        return response()->json($result);
    }

    public function appointmentProposal(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        $data = $request->validate([
            'slots' => ['required', 'array', 'min:1', 'max:3'],
            'slots.*.date' => ['required', 'date', 'after_or_equal:today'],
            'slots.*.start_time' => ['required', 'date_format:H:i'],
            'slots.*.end_time' => ['required', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $slots = $this->validatedAppointmentSlots($data['slots']);

        $action = DB::transaction(function () use ($job, $partner, $user, $data, $slots): TechnicalServicePartnerJobAction {
            return $this->recordAction($job, $partner, $user, TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED, TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW, [
                'slots' => $slots,
                'note' => $data['note'] ?? null,
                'submitted_at' => now()->toISOString(),
                'ops_review_required' => true,
            ], $data['note'] ?? null, $job->workflow_status);
        });

        return response()->json([
            'status' => $action->status,
            'action' => $action->action,
            'job' => $this->portalData->safeServiceJobSummary($job->refresh()),
        ]);
    }

    public function reject(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(['not_available', 'region_not_suitable', 'time_not_suitable', 'customer_unreachable', 'customer_disagreement', 'other'])],
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $action = DB::transaction(function () use ($job, $partner, $user, $data): TechnicalServicePartnerJobAction {
            return $this->recordAction($job, $partner, $user, TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED, TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW, [
                'reason' => $data['reason'],
                'reason_label' => $this->rejectReasonLabel($data['reason']),
                'note' => $data['note'] ?? null,
                'submitted_at' => now()->toISOString(),
                'ops_review_required' => true,
            ], $data['note'] ?? $this->rejectReasonLabel($data['reason']), $job->workflow_status);
        });

        return response()->json([
            'status' => $action->status,
            'action' => $action->action,
            'job' => $this->portalData->safeServiceJobSummary($job->refresh()),
        ]);
    }

    public function requestRevisit(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'preferred_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = DB::transaction(function () use ($job, $partner, $user, $data): array {
            $from = $job->workflow_status;
            $status = TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW;
            $payload = [
                'reason' => $data['reason'],
                'preferred_date' => $data['preferred_date'] ?? null,
                'note' => $data['note'] ?? null,
            ];

            if (in_array($job->workflow_status, ['Planlı', 'PlanlÄ±', 'Yolda', 'Sahada', 'Belge / Fotoğraf Bekleyen', 'Belge / FotoÄŸraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen', 'MÃ¼ÅŸteri KapanÄ±ÅŸ OnayÄ± Bekleyen'], true)) {
                try {
                    $job = $this->workflow->updateFieldWorkflow($job, 'mark-incomplete', [
                        'workflow_status' => 'Beklemede',
                        'incomplete_reason' => $data['reason'],
                        'pending_reason' => $data['reason'],
                        'requires_second_visit' => true,
                        'second_visit_reason' => $data['reason'],
                        'note' => $data['note'] ?? $data['reason'],
                    ], $user);
                    $status = TechnicalServicePartnerJobAction::STATUS_APPLIED;
                } catch (Throwable $exception) {
                    $payload['workflow_error'] = $exception->getMessage();
                }
            }

            $this->recordAction($job->refresh(), $partner, $user, TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED, $status, $payload, $data['note'] ?? $data['reason'], $from);

            return $this->jobResponse($job->refresh(), $status);
        });

        return response()->json($result);
    }

    public function submitCompletion(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        $data = $request->validate([
            'result' => ['required', 'string', Rule::in(['completed', 'revisit_required', 'customer_not_available', 'missing_info_or_photo', 'parts_pending'])],
            'checklist' => ['required', 'array'],
            'note' => ['required', 'string', 'max:2000'],
            'customer_confirmation_method' => ['nullable', 'string', 'max:128'],
            'customer_confirmation_note' => ['nullable', 'string', 'max:1000'],
            'photo_upload_ids' => ['nullable', 'array'],
            'photo_upload_ids.*' => ['integer'],
        ]);
        $this->validateSimpleChecklist($data['checklist']);
        $this->validateCompletionEvidence($job, $data);

        $result = DB::transaction(function () use ($job, $partner, $user, $data): array {
            $from = $job->workflow_status;
            $status = TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW;
            $payload = [
                'result' => $data['result'],
                'checklist' => $data['checklist'],
                'customer_confirmation_method' => $data['customer_confirmation_method'] ?? null,
                'customer_confirmation_note' => $data['customer_confirmation_note'] ?? null,
                'photo_upload_ids' => $data['photo_upload_ids'] ?? [],
                'ops_final_check_required' => true,
            ];

            if (in_array($job->workflow_status, ['Sahada', 'Belge / Fotoğraf Bekleyen', 'Belge / FotoÄŸraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen', 'MÃ¼ÅŸteri KapanÄ±ÅŸ OnayÄ± Bekleyen'], true)) {
                try {
                    $job = $this->workflow->updateFieldWorkflow($job, 'checklist', [
                        'checklist_payload' => $this->technicalChecklistPayload(),
                        'note' => $data['note'],
                    ], $user);
                    $payload['checklist_applied'] = true;
                } catch (Throwable $exception) {
                    $payload['checklist_apply_error'] = $exception->getMessage();
                }
            }

            $this->recordAction($job->refresh(), $partner, $user, TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED, $status, $payload, $data['note'], $from);

            return $this->jobResponse($job->refresh(), $status);
        });

        return response()->json($result);
    }

    public function uploadPhotos(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        $data = $request->validate([
            'door_front_photo' => ['nullable', 'file', 'image', 'max:10240'],
            'door_side_photo' => ['nullable', 'file', 'image', 'max:10240'],
            'door_back_photo' => ['nullable', 'file', 'image', 'max:10240'],
        ]);

        $files = collect(self::REQUIRED_DOOR_PHOTO_FIELDS)
            ->mapWithKeys(fn (string $fieldCode): array => [$fieldCode => $data[$fieldCode] ?? null])
            ->filter(fn (mixed $file): bool => $file instanceof UploadedFile);

        if ($files->isEmpty()) {
            throw ValidationException::withMessages([
                'photos' => 'En az bir kapÃ„Â± fotoÃ„Å¸rafÃ„Â± yÃƒÂ¼kleyin.',
            ]);
        }

        $uploads = DB::transaction(function () use ($files, $job, $partner, $user): array {
            $created = [];

            foreach ($files as $fieldCode => $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $extension = $file->extension() ?: $file->guessExtension() ?: 'jpg';
                $filename = $fieldCode.'-'.Str::uuid().'.'.$extension;
                $path = $file->storeAs("technical-service/requests/{$job->id}/partner-portal", $filename, 'public');
                $created[] = TechnicalServiceRequestUpload::query()->create([
                    'technical_service_request_id' => $job->id,
                    'field_code' => (string) $fieldCode,
                    'category' => TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO,
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize() ?: 0,
                ]);
            }

            $photoCount = $this->doorPhotoEvidenceCount($job->refresh());
            $readyPhotoCount = min(3, $photoCount);
            $job->forceFill([
                'before_photo_count' => max((int) ($job->before_photo_count ?? 0), $readyPhotoCount),
                'after_photo_count' => max((int) ($job->after_photo_count ?? 0), $readyPhotoCount),
                'general_photo_count' => max((int) ($job->general_photo_count ?? 0), $readyPhotoCount),
                'photo_status' => $readyPhotoCount >= 3 ? 'tamamlandÄ±' : 'eksik',
            ])->save();

            $this->recordAction($job->refresh(), $partner, $user, TechnicalServicePartnerJobAction::ACTION_NOTE_ADDED, TechnicalServicePartnerJobAction::STATUS_SUBMITTED, [
                'visibility' => 'ops',
                'photo_upload_ids' => collect($created)->pluck('id')->all(),
                'photo_fields' => collect($created)->pluck('field_code')->all(),
            ], 'Ãƒâ€¡ilingir portalÃ„Â±ndan fotoÃ„Å¸raf yÃƒÂ¼klendi.', $job->workflow_status);

            return collect($created)
                ->map(fn (TechnicalServiceRequestUpload $upload): array => [
                    'id' => $upload->id,
                    'label' => $upload->original_name,
                    'category' => $upload->category,
                    'field_code' => $upload->field_code,
                ])
                ->values()
                ->all();
        });

        return response()->json([
            'status' => 'ok',
            'uploads' => $uploads,
            'job' => $this->portalData->safeServiceJobSummary($job->refresh()),
        ]);
    }

    public function customerOtpRequest(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $action = DB::transaction(function () use ($job, $partner, $user, $data): TechnicalServicePartnerJobAction {
            return $this->recordAction($job, $partner, $user, TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED, TechnicalServicePartnerJobAction::STATUS_SUBMITTED, [
                'confirmation_method' => 'otp',
                'provider' => 'system_payload',
                'otp_reference' => 'portal-otp-'.Str::uuid(),
                'requested_at' => now()->toISOString(),
                'message_payload' => [
                    'recipient' => 'customer',
                    'channel' => 'system_payload',
                    'mrn' => $job->mrn,
                    'customer_phone' => $job->customer_phone,
                    'message_text' => "{$job->mrn} servis tamamlanma onayÃ„Â± iÃƒÂ§in mÃƒÂ¼Ã…Å¸teri OTP isteÃ„Å¸i hazÃ„Â±rlandÃ„Â±.",
                ],
            ], $data['note'] ?? 'MÃƒÂ¼Ã…Å¸teri OTP/onay isteÃ„Å¸i oluÃ…Å¸turuldu.', $job->workflow_status);
        });

        return response()->json([
            'status' => $action->status,
            'action' => $action->action,
            'job' => $this->portalData->safeServiceJobSummary($job->refresh()),
        ]);
    }

    public function supportRequest(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(['spare_part', 'extra_product', 'missing_info', 'customer_call', 'other'])],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $action = DB::transaction(function () use ($job, $partner, $user, $data): TechnicalServicePartnerJobAction {
            return $this->recordAction($job, $partner, $user, TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED, TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW, [
                'type' => $data['type'],
                'type_label' => $this->supportTypeLabel($data['type']),
                'description' => $data['description'],
                'product_name' => $data['product_name'] ?? null,
                'quantity' => $data['quantity'] ?? null,
                'submitted_at' => now()->toISOString(),
                'ops_review_required' => true,
            ], $data['description'], $job->workflow_status);
        });

        return response()->json([
            'status' => $action->status,
            'action' => $action->action,
            'job' => $this->portalData->safeServiceJobSummary($job->refresh()),
        ]);
    }

    public function note(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'visibility' => ['nullable', 'string', Rule::in(['ops', 'internal_partner'])],
        ]);

        $action = DB::transaction(function () use ($job, $partner, $user, $data): TechnicalServicePartnerJobAction {
            return $this->recordAction($job, $partner, $user, TechnicalServicePartnerJobAction::ACTION_NOTE_ADDED, TechnicalServicePartnerJobAction::STATUS_SUBMITTED, [
                'visibility' => $data['visibility'] ?? 'ops',
            ], $data['note'], $job->workflow_status);
        });

        return response()->json([
            'status' => 'ok',
            'action' => $action->action,
            'job' => $this->portalData->safeServiceJobSummary($job->refresh()),
        ]);
    }

    public function earnings(Request $request): JsonResponse
    {
        $user = $this->authorizedUser($request, 'partner.earnings.view');
        $partners = $this->scope->visibleLocksmithPartnersForPortal($user);
        $rows = $partners
            ->map(fn (B2BPartner $partner): array => [
                'partner_id' => $partner->id,
                'partner_name' => $partner->display_name,
                'earnings' => $this->portalData->earningsFor($partner),
            ])
            ->values();

        return response()->json([
            'status' => 'ok',
            'items' => $rows->all(),
            'summary' => [
                'job_count' => $rows->sum(fn (array $row): int => (int) ($row['earnings']['summary']['job_count'] ?? 0)),
                'labor_total' => $rows->sum(fn (array $row): float => (float) ($row['earnings']['summary']['labor_total'] ?? 0)),
                'travel_fee_total' => $rows->sum(fn (array $row): float => (float) ($row['earnings']['summary']['travel_fee_total'] ?? 0)),
                'grand_total' => $rows->sum(fn (array $row): float => (float) ($row['earnings']['summary']['grand_total'] ?? 0)),
            ],
        ]);
    }

    private function authorizedUser(Request $request, string $resourceCode): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->panelAccess->userCanAccess($user, $resourceCode), 403);

        return $user;
    }

    /**
     * @return array{0: User, 1: TechnicalServiceRequest, 2: B2BPartner}
     */
    private function authorizedJob(Request $request, TechnicalServiceRequest $job): array
    {
        $user = $this->authorizedUser($request, 'partner.service_jobs.view');
        $partner = $this->scope->assertCanViewServiceJob($user, $job);

        return [$user, $job, $partner];
    }

    /**
     * @param  array<int, array<string, mixed>>  $jobs
     * @return array<int, array<string, mixed>>
     */
    private function kanbanColumns(array $jobs): array
    {
        return collect([
            ['key' => 'new_jobs', 'label' => 'Yeni iÅŸler', 'tone' => 'blue'],
            ['key' => 'appointment_confirmed', 'label' => 'Randevu onaylandÄ±', 'tone' => 'green'],
            ['key' => 'revisit', 'label' => 'Tekrar ziyaret', 'tone' => 'amber'],
            ['key' => 'final_check', 'label' => 'Son kontrol bekliyor', 'tone' => 'violet'],
            ['key' => 'completed', 'label' => 'Tamamlanan iÅŸler', 'tone' => 'slate'],
        ])->map(function (array $column) use ($jobs): array {
            $column['jobs'] = collect($jobs)
                ->where('kanban_column', $column['key'])
                ->sortBy([
                    ['card_priority', 'asc'],
                    ['updated_at', 'desc'],
                ])
                ->values()
                ->all();
            $column['count'] = count($column['jobs']);

            return $column;
        })->values()->all();
    }

    private function recordAction(
        TechnicalServiceRequest $job,
        B2BPartner $partner,
        User $user,
        string $action,
        string $status,
        array $payload,
        ?string $note,
        ?string $fromStatus,
    ): TechnicalServicePartnerJobAction {
        $record = TechnicalServicePartnerJobAction::query()->create([
            'technical_service_request_id' => $job->id,
            'partner_id' => $partner->id,
            'user_id' => $user->id,
            'technical_service_technician_id' => $job->technical_service_technician_id,
            'action' => $action,
            'status' => $status,
            'payload' => $payload,
            'note' => $note,
        ]);

        $job->events()->create([
            'event_type' => 'partner_portal_'.$action,
            'title' => $this->eventTitle($action),
            'note' => $note,
            'from_status' => $fromStatus,
            'to_status' => $job->workflow_status,
            'author_user_id' => $user->id,
            'metadata' => [
                'partner_id' => $partner->id,
                'partner_name' => $partner->display_name,
                'portal_action_status' => $status,
                'payload' => $payload,
            ],
        ]);

        return $record;
    }

    private function eventTitle(string $action): string
    {
        return match ($action) {
            TechnicalServicePartnerJobAction::ACTION_ACCEPTED => 'Ã‡ilingir portalÄ±: randevu onaylandÄ±',
            TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => 'Ã‡ilingir portalÄ±: tekrar ziyaret istendi',
            TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED => 'Ã‡ilingir portalÄ±: tamamlama gÃ¶nderildi',
            TechnicalServicePartnerJobAction::ACTION_NOTE_ADDED => 'Ã‡ilingir portalÄ±: not eklendi',
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => 'Ã‡ilingir portalÄ±: randevu Ã¶nerildi',
            TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED => 'Ã‡ilingir portalÄ±: iÅŸ reddedildi',
            TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED => 'Çilingir portalı: müşteri OTP/onay isteği oluşturuldu',
            TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED => 'Çilingir portalı: ek talep oluşturuldu',
            default => 'Ã‡ilingir portalÄ± aksiyonu',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function slotLabel(string $slot, array $payload = []): string
    {
        return match ($slot) {
            'morning' => 'Ã–ÄŸleden Ã¶nce',
            'afternoon' => 'Ã–ÄŸleden sonra',
            'full_day' => 'Tam gÃ¼n / operasyon belirlesin',
            'custom' => trim(($payload['proposed_time_start'] ?? '').' - '.($payload['proposed_time_end'] ?? '')) ?: 'Ã–zel saat',
            default => $slot,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     * @return array<int, array<string, mixed>>
     */
    private function validatedAppointmentSlots(array $slots): array
    {
        $today = CarbonImmutable::today();

        return collect($slots)
            ->values()
            ->map(function (array $slot, int $index) use ($today): array {
                $date = CarbonImmutable::parse((string) $slot['date'])->startOfDay();
                $start = substr((string) $slot['start_time'], 0, 5);
                $end = substr((string) $slot['end_time'], 0, 5);

                if ($date->lt($today)) {
                    throw ValidationException::withMessages([
                        "slots.{$index}.date" => 'GeÃ§miÅŸ tarih iÃ§in randevu Ã¶nerilemez.',
                    ]);
                }

                if ($end <= $start) {
                    throw ValidationException::withMessages([
                        "slots.{$index}.end_time" => 'BitiÅŸ saati baÅŸlangÄ±Ã§tan sonra olmalÄ±dÄ±r.',
                    ]);
                }

                return [
                    'date' => $date->toDateString(),
                    'start_time' => $start,
                    'end_time' => $end,
                    'label' => $date->format('d.m.Y').' '.$start.' - '.$end,
                ];
            })
            ->all();
    }

    private function rejectReasonLabel(string $reason): string
    {
        return match ($reason) {
            'not_available' => 'Uygun deÄŸilim',
            'region_not_suitable' => 'BÃ¶lge uygun deÄŸil',
            'time_not_suitable' => 'Zaman uygun deÄŸil',
            'customer_unreachable' => 'Müşteriyle iletişim kurulamadı',
            'customer_disagreement' => 'MÃ¼ÅŸteriyle anlaÅŸamadÄ±m',
            'other' => 'DiÄŸer',
            default => $reason,
        };
    }

    private function supportTypeLabel(string $type): string
    {
        return match ($type) {
            'spare_part' => 'Yedek parÃ§a',
            'extra_product' => 'Ek Ã¼rÃ¼n',
            'missing_info' => 'Eksik bilgi',
            'customer_call' => 'MÃ¼ÅŸteri aransÄ±n',
            'other' => 'DiÄŸer',
            default => $type,
        };
    }

    private function jobResponse(TechnicalServiceRequest $job, string $status): array
    {
        return [
            'status' => $status,
            'job' => $this->portalData->safeServiceJobSummary($job),
        ];
    }

    /**
     * @param  array<string, mixed>  $checklist
     */
    private function validateSimpleChecklist(array $checklist): void
    {
        $missing = collect(self::SIMPLE_CHECKLIST)
            ->filter(fn (string $key): bool => filter_var($checklist[$key] ?? false, FILTER_VALIDATE_BOOL) !== true)
            ->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'checklist' => 'Tamamlamaya gÃ¶ndermek iÃ§in tÃ¼m checklist maddeleri iÅŸaretlenmelidir.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateCompletionEvidence(TechnicalServiceRequest $job, array $data): void
    {
        if ($this->doorPhotoEvidenceCount($job) < 3) {
            throw ValidationException::withMessages([
                'photos' => '3 fotoÄŸraf yÃ¼klenmeden tamamlamaya gÃ¶nderilemez.',
            ]);
        }

        if (! $this->hasCustomerConfirmation($job, $data)) {
            throw ValidationException::withMessages([
                'customer_confirmation' => 'MÃ¼ÅŸteri onayÄ± olmadan tamamlamaya gÃ¶nderilemez.',
            ]);
        }
    }

    private function doorPhotoEvidenceCount(TechnicalServiceRequest $job): int
    {
        $job->loadMissing('uploads');
        $fieldCodes = $job->uploads
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $upload->category === TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO)
            ->pluck('field_code')
            ->filter()
            ->unique()
            ->count();
        $legacyCount = max(
            0,
            (int) ($job->before_photo_count ?? 0),
            (int) ($job->after_photo_count ?? 0),
            (int) ($job->general_photo_count ?? 0),
        );

        return max($fieldCodes, $legacyCount);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasCustomerConfirmation(TechnicalServiceRequest $job, array $data): bool
    {
        if (in_array($job->customer_closure_approval_status, ['onaylandı', 'onaylandi', 'onaylandÄ±', 'onaylandÃ„Â±'], true)) {
            return true;
        }

        return $job->partnerJobActions()
            ->where('action', TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED)
            ->exists();
    }

    /**
     * @return array<string, bool>
     */
    private function technicalChecklistPayload(): array
    {
        return collect(self::TECHNICAL_CHECKLIST)
            ->mapWithKeys(fn (string $item): array => [$item => true])
            ->all();
    }

    private function canCompleteDirectly(TechnicalServiceRequest $job): bool
    {
        return in_array($job->workflow_status, ['Sahada', 'Belge / FotoÄŸraf Bekleyen', 'MÃ¼ÅŸteri KapanÄ±ÅŸ OnayÄ± Bekleyen'], true)
            && $job->checklist_status === 'tamamlandÄ±'
            && (int) ($job->before_photo_count ?? 0) >= 3
            && (int) ($job->after_photo_count ?? 0) >= 3
            && (int) ($job->general_photo_count ?? 0) >= 1
            && in_array($job->document_status, ['tamamlandÄ±', 'tamam', 'gerekli_degil'], true)
            && $job->customer_closure_approval_status === 'onaylandÄ±';
    }
}
