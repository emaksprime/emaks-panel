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

    private const REQUIRED_PORTAL_PHOTO_FIELDS = [
        'before_photo' => 'Öncesi',
        'after_photo' => 'Sonrası',
        'warranty_document_photo' => 'Garanti Belgesi',
    ];

    private const LEGACY_PORTAL_PHOTO_FIELDS = [
        'door_front_photo' => 'before_photo',
        'door_side_photo' => 'after_photo',
        'door_back_photo' => 'warranty_document_photo',
    ];

    private const APPOINTMENT_SLOT_OPTIONS = [
        '10:00-11:00' => ['start' => '10:00', 'end' => '11:00'],
        '11:00-12:00' => ['start' => '11:00', 'end' => '12:00'],
        '12:00-13:00' => ['start' => '12:00', 'end' => '13:00'],
        '13:00-14:00' => ['start' => '13:00', 'end' => '14:00'],
        '14:00-15:00' => ['start' => '14:00', 'end' => '15:00'],
        '15:00-16:00' => ['start' => '15:00', 'end' => '16:00'],
        '16:00-17:00' => ['start' => '16:00', 'end' => '17:00'],
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
            'appointment_slot_options' => $this->appointmentSlotOptions(),
        ]);
    }

    public function show(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);

        return response()->json([
            'status' => 'ok',
            'partner_id' => $partner->id,
            'job' => $this->portalData->safeServiceJobSummary($job),
            'appointment_slot_options' => $this->appointmentSlotOptions(),
        ]);
    }

    public function accept(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $this->hasOpsAppointment($job)) {
            throw ValidationException::withMessages([
                'appointment' => 'Operasyon randevu tarihi ve saat aralığı vermeden randevu onaylanamaz. Randevu saati önerin.',
            ]);
        }

        $result = DB::transaction(function () use ($job, $partner, $user, $data): array {
            $from = $job->workflow_status;
            $status = TechnicalServicePartnerJobAction::STATUS_SUBMITTED;
            $payload = [
                'note' => $data['note'] ?? null,
                'scheduled_date' => $job->scheduled_date?->toDateString(),
                'scheduled_time' => $job->scheduled_time,
                'scheduled_at' => $job->scheduled_at?->toISOString(),
            ];

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
                        'partner_portal_appointment_accepted',
                    );
                    $status = TechnicalServicePartnerJobAction::STATUS_APPLIED;
                } catch (Throwable $exception) {
                    $payload['workflow_error'] = $exception->getMessage();
                    $status = TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW;
                }
            }

            $this->recordAction($job->refresh(), $partner, $user, TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_ACCEPTED_BY_TECHNICIAN, $status, $payload, $data['note'] ?? null, $from);

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
            'slots.*.slot' => ['nullable', 'string', Rule::in(array_keys(self::APPOINTMENT_SLOT_OPTIONS))],
            'slots.*.start_time' => ['nullable', 'date_format:H:i'],
            'slots.*.end_time' => ['nullable', 'date_format:H:i'],
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
        $this->ensureFieldActionStage($job, 'Tekrar ziyaret talebi sadece randevu onaylandıktan sonra gönderilebilir.');
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
        $this->ensureFieldActionStage($job, 'Tamamlama gönderimi sadece randevu onaylandıktan sonra yapılabilir.');
        $data = $request->validate([
            'result' => ['required', 'string', Rule::in(['completed', 'revisit_required', 'customer_not_available', 'missing_info_or_photo', 'parts_pending'])],
            'checklist' => ['nullable', 'array'],
            'note' => ['required', 'string', 'max:2000'],
            'customer_confirmation_method' => ['nullable', 'string', 'max:128'],
            'customer_confirmation_note' => ['nullable', 'string', 'max:1000'],
            'photo_upload_ids' => ['nullable', 'array'],
            'photo_upload_ids.*' => ['integer'],
        ]);
        $checklist = $data['checklist'] ?? $this->portalCompletionChecklist();
        $this->validateSimpleChecklist($checklist);
        $this->validateCompletionEvidence($job, $data);

        $result = DB::transaction(function () use ($job, $partner, $user, $data, $checklist): array {
            $from = $job->workflow_status;
            $status = TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW;
            $payload = [
                'result' => $data['result'],
                'checklist_gate' => 'server_checked',
                'checklist' => $checklist,
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
        $this->ensureFieldActionStage($job, 'Fotoğraf yükleme sadece randevu onaylandıktan sonra yapılabilir.');
        $data = $request->validate([
            'before_photo' => ['nullable', 'file', 'image', 'max:10240'],
            'after_photo' => ['nullable', 'file', 'image', 'max:10240'],
            'warranty_document_photo' => ['nullable', 'file', 'image', 'max:10240'],
            'door_front_photo' => ['nullable', 'file', 'image', 'max:10240'],
            'door_side_photo' => ['nullable', 'file', 'image', 'max:10240'],
            'door_back_photo' => ['nullable', 'file', 'image', 'max:10240'],
        ]);

        $fieldFiles = [];
        foreach (array_keys(self::REQUIRED_PORTAL_PHOTO_FIELDS) as $fieldCode) {
            $fieldFiles[$fieldCode] = $data[$fieldCode] ?? null;
        }
        foreach (self::LEGACY_PORTAL_PHOTO_FIELDS as $legacyField => $fieldCode) {
            $fieldFiles[$fieldCode] ??= $data[$legacyField] ?? null;
        }

        $files = collect($fieldFiles)->filter(fn (mixed $file): bool => $file instanceof UploadedFile);

        if ($files->isEmpty()) {
            throw ValidationException::withMessages([
                'photos' => 'En az bir fotoğraf yükleyin.',
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
                    'original_name' => self::REQUIRED_PORTAL_PHOTO_FIELDS[(string) $fieldCode].' - '.$file->getClientOriginalName(),
                    'path' => $path,
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize() ?: 0,
                ]);
            }

            $photoReadiness = $this->portalPhotoReadiness($job->refresh());
            $job->forceFill([
                'before_photo_count' => in_array('before_photo', $photoReadiness['present_fields'], true) ? max(1, (int) ($job->before_photo_count ?? 0)) : (int) ($job->before_photo_count ?? 0),
                'after_photo_count' => in_array('after_photo', $photoReadiness['present_fields'], true) ? max(1, (int) ($job->after_photo_count ?? 0)) : (int) ($job->after_photo_count ?? 0),
                'general_photo_count' => in_array('warranty_document_photo', $photoReadiness['present_fields'], true) ? max(1, (int) ($job->general_photo_count ?? 0)) : (int) ($job->general_photo_count ?? 0),
                'photo_status' => $photoReadiness['ready'] ? 'tamamlandı' : 'eksik',
            ])->save();

            $this->recordAction($job->refresh(), $partner, $user, TechnicalServicePartnerJobAction::ACTION_PHOTOS_UPLOADED, TechnicalServicePartnerJobAction::STATUS_SUBMITTED, [
                'visibility' => 'ops',
                'photo_upload_ids' => collect($created)->pluck('id')->all(),
                'photo_fields' => collect($created)->pluck('field_code')->all(),
            ], 'Çilingir portalından fotoğraf yüklendi.', $job->workflow_status);

            return collect($created)
                ->map(fn (TechnicalServiceRequestUpload $upload): array => [
                    'id' => $upload->id,
                    'label' => self::REQUIRED_PORTAL_PHOTO_FIELDS[$upload->field_code] ?? $upload->original_name,
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
        $this->ensureFieldActionStage($job, 'Müşteri onayı sadece randevu onaylandıktan sonra istenebilir.');
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
        $this->ensureSupportRequestStage($job, 'Ek talep sadece randevu onaylandıktan veya tekrar ziyaret aşamasına geçtikten sonra gönderilebilir.');
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
            'event_type' => $this->eventType($action),
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

    private function eventType(string $action): string
    {
        return match ($action) {
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_ACCEPTED_BY_TECHNICIAN => 'partner_portal_appointment_accepted',
            default => 'partner_portal_'.$action,
        };
    }

    private function eventTitle(string $action): string
    {
        return match ($action) {
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_ACCEPTED_BY_TECHNICIAN => 'Çilingir portalı: randevu onaylandı',
            TechnicalServicePartnerJobAction::ACTION_ACCEPTED => 'Ã‡ilingir portalÄ±: randevu onaylandÄ±',
            TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => 'Ã‡ilingir portalÄ±: tekrar ziyaret istendi',
            TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED => 'Ã‡ilingir portalÄ±: tamamlama gÃ¶nderildi',
            TechnicalServicePartnerJobAction::ACTION_NOTE_ADDED => 'Ã‡ilingir portalÄ±: not eklendi',
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => 'Ã‡ilingir portalÄ±: randevu Ã¶nerildi',
            TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED => 'Ã‡ilingir portalÄ±: iÅŸ reddedildi',
            TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED => 'Çilingir portalı: müşteri OTP/onay isteği oluşturuldu',
            TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED => 'Çilingir portalı: ek talep oluşturuldu',
            TechnicalServicePartnerJobAction::ACTION_PHOTOS_UPLOADED => 'Çilingir portalı: fotoğraf yüklendi',
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
     * @return array<int, array<string, string>>
     */
    private function appointmentSlotOptions(): array
    {
        return collect(self::APPOINTMENT_SLOT_OPTIONS)
            ->map(fn (array $range, string $value): array => [
                'value' => $value,
                'label' => $value,
                'start_time' => $range['start'],
                'end_time' => $range['end'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     * @return array<int, array<string, mixed>>
     */
    private function validatedAppointmentSlots(array $slots): array
    {
        $today = CarbonImmutable::today();

        $validated = collect($slots)
            ->values()
            ->map(function (array $slot, int $index) use ($today): array {
                $date = CarbonImmutable::parse((string) $slot['date'])->startOfDay();
                $slotValue = trim((string) ($slot['slot'] ?? ''));
                $startInput = substr((string) ($slot['start_time'] ?? ''), 0, 5);
                $endInput = substr((string) ($slot['end_time'] ?? ''), 0, 5);

                if ($slotValue === '' && $startInput !== '' && $endInput !== '') {
                    $slotValue = $startInput.'-'.$endInput;
                }

                if (! isset(self::APPOINTMENT_SLOT_OPTIONS[$slotValue])) {
                    throw ValidationException::withMessages([
                        "slots.{$index}.slot" => 'Randevu saati 10:00-17:00 arasındaki hazır aralıklardan seçilmelidir.',
                    ]);
                }

                $start = self::APPOINTMENT_SLOT_OPTIONS[$slotValue]['start'];
                $end = self::APPOINTMENT_SLOT_OPTIONS[$slotValue]['end'];

                if ($date->lt($today)) {
                    throw ValidationException::withMessages([
                        "slots.{$index}.date" => 'Geçmiş tarih için randevu önerilemez.',
                    ]);
                }

                return [
                    'date' => $date->toDateString(),
                    'slot' => $slotValue,
                    'start_time' => $start,
                    'end_time' => $end,
                    'label' => $date->format('d.m.Y').' '.$start.' - '.$end,
                ];
            })
            ->all();

        $sorted = collect($validated)->sortBy(fn (array $slot): string => $slot['date'].' '.$slot['start_time'])->values();
        for ($index = 1; $index < $sorted->count(); $index++) {
            $previous = $sorted[$index - 1];
            $current = $sorted[$index];

            if ($previous['date'] === $current['date'] && $current['start_time'] < $previous['end_time']) {
                throw ValidationException::withMessages([
                    'slots' => 'Randevu saatleri aynı gün içinde çakışamaz.',
                ]);
            }
        }

        return $validated;
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

    private function ensureFieldActionStage(TechnicalServiceRequest $job, string $message): void
    {
        if (! $this->canUseFieldActions($job)) {
            throw ValidationException::withMessages([
                'workflow_status' => $message,
            ]);
        }
    }

    private function canUseFieldActions(TechnicalServiceRequest $job): bool
    {
        if ($job->completed_at !== null) {
            return false;
        }

        return in_array($job->workflow_status, [
            'Planlı',
            'PlanlÄ±',
            'Yolda',
            'Sahada',
            'Belge / Fotoğraf Bekleyen',
            'Belge / FotoÄŸraf Bekleyen',
            'Müşteri Kapanış Onayı Bekleyen',
            'MÃ¼ÅŸteri KapanÄ±ÅŸ OnayÄ± Bekleyen',
        ], true);
    }

    private function ensureSupportRequestStage(TechnicalServiceRequest $job, string $message): void
    {
        if ($this->canUseFieldActions($job)) {
            return;
        }

        if (
            (bool) $job->requires_second_visit
            || in_array($job->workflow_status, [
                'Beklemede',
                'Müşteri Yerinde Yok',
                'Montaj Yeri Hazır Değil',
                'Parça Bekleniyor',
                'Usta Tarih Revize Talebi',
            ], true)
        ) {
            return;
        }

        throw ValidationException::withMessages([
            'workflow_status' => $message,
        ]);
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
     * @return array<string, bool>
     */
    private function portalCompletionChecklist(): array
    {
        return collect(self::SIMPLE_CHECKLIST)
            ->mapWithKeys(fn (string $key): array => [$key => true])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateCompletionEvidence(TechnicalServiceRequest $job, array $data): void
    {
        $photoReadiness = $this->portalPhotoReadiness($job);

        if (! $photoReadiness['ready']) {
            throw ValidationException::withMessages([
                'photos' => implode(', ', $photoReadiness['missing_labels']).' eksik. 3 fotoğraf tamamlanmadan iş son kontrole gönderilemez.',
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
        return $this->portalPhotoReadiness($job)['count'];
    }

    /**
     * @return array{present_fields: array<int, string>, missing_fields: array<int, string>, missing_labels: array<int, string>, count: int, required: int, ready: bool}
     */
    private function portalPhotoReadiness(TechnicalServiceRequest $job): array
    {
        $job->loadMissing('uploads');
        $uploadedFields = $job->uploads
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $upload->category === TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO)
            ->map(fn (TechnicalServiceRequestUpload $upload): ?string => $this->canonicalPortalPhotoField($upload->field_code))
            ->filter(fn (?string $field): bool => $field !== null && array_key_exists($field, self::REQUIRED_PORTAL_PHOTO_FIELDS))
            ->unique()
            ->values();

        $presentFields = $uploadedFields->isNotEmpty()
            ? $uploadedFields
            : collect([
                (int) ($job->before_photo_count ?? 0) > 0 ? 'before_photo' : null,
                (int) ($job->after_photo_count ?? 0) > 0 ? 'after_photo' : null,
                (int) ($job->general_photo_count ?? 0) > 0 ? 'warranty_document_photo' : null,
            ])->filter()->values();

        $missingFields = collect(array_keys(self::REQUIRED_PORTAL_PHOTO_FIELDS))
            ->reject(fn (string $field): bool => $presentFields->contains($field))
            ->values();

        return [
            'present_fields' => $presentFields->all(),
            'missing_fields' => $missingFields->all(),
            'missing_labels' => $missingFields
                ->map(fn (string $field): string => self::REQUIRED_PORTAL_PHOTO_FIELDS[$field])
                ->all(),
            'count' => $presentFields->count(),
            'required' => count(self::REQUIRED_PORTAL_PHOTO_FIELDS),
            'ready' => $missingFields->isEmpty(),
        ];
    }

    private function canonicalPortalPhotoField(?string $fieldCode): ?string
    {
        $field = trim((string) $fieldCode);

        if ($field === '') {
            return null;
        }

        return self::LEGACY_PORTAL_PHOTO_FIELDS[$field] ?? $field;
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

    private function hasOpsAppointment(TechnicalServiceRequest $job): bool
    {
        return $job->scheduled_at !== null
            || ($job->scheduled_date !== null && filled($job->scheduled_time));
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
            && $this->portalPhotoReadiness($job)['ready']
            && in_array($job->document_status, ['tamamlandÄ±', 'tamam', 'gerekli_degil'], true)
            && $job->customer_closure_approval_status === 'onaylandÄ±';
    }
}
