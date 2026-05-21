<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2B\B2BPartner;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\B2B\B2BPartnerPortalDataService;
use App\Services\B2B\B2BPartnerServiceJobScopeService;
use App\Services\PanelAccessService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

            if ($job->workflow_status === 'Usta Onayı Bekleyen') {
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
            'proposed_date' => ['required', 'date'],
            'proposed_slot' => ['required', 'string', Rule::in(['morning', 'afternoon', 'full_day', 'custom'])],
            'proposed_time_start' => ['nullable', 'date_format:H:i', 'required_if:proposed_slot,custom'],
            'proposed_time_end' => ['nullable', 'date_format:H:i', 'required_if:proposed_slot,custom'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $action = DB::transaction(function () use ($job, $partner, $user, $data): TechnicalServicePartnerJobAction {
            return $this->recordAction($job, $partner, $user, TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED, TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW, [
                'proposal' => [
                    'proposed_date' => $data['proposed_date'],
                    'proposed_slot' => $data['proposed_slot'],
                    'proposed_time_start' => $data['proposed_time_start'] ?? null,
                    'proposed_time_end' => $data['proposed_time_end'] ?? null,
                    'slot_label' => $this->slotLabel($data['proposed_slot'], $data),
                    'note' => $data['note'] ?? null,
                    'submitted_at' => now()->toISOString(),
                ],
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
            'reason' => ['required', 'string', Rule::in(['not_available', 'region_not_suitable', 'time_not_suitable', 'customer_disagreement', 'other'])],
            'note' => ['nullable', 'string', 'max:2000', 'required_if:reason,other'],
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

            if (in_array($job->workflow_status, ['Planlı', 'Yolda', 'Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen'], true)) {
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

        $result = DB::transaction(function () use ($job, $partner, $user, $data): array {
            $from = $job->workflow_status;
            $status = TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW;
            $payload = [
                'result' => $data['result'],
                'checklist' => $data['checklist'],
                'customer_confirmation_method' => $data['customer_confirmation_method'] ?? null,
                'customer_confirmation_note' => $data['customer_confirmation_note'] ?? null,
                'photo_upload_ids' => $data['photo_upload_ids'] ?? [],
            ];

            if (in_array($job->workflow_status, ['Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen'], true)) {
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

            if ($data['result'] === 'completed' && $this->canCompleteDirectly($job->refresh())) {
                try {
                    $job = $this->workflow->updateFieldWorkflow($job, 'complete', [
                        'note' => $data['note'],
                    ], $user);
                    $status = TechnicalServicePartnerJobAction::STATUS_APPLIED;
                } catch (Throwable $exception) {
                    $payload['workflow_error'] = $exception->getMessage();
                }
            }

            $this->recordAction($job->refresh(), $partner, $user, TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED, $status, $payload, $data['note'], $from);

            return $this->jobResponse($job->refresh(), $status);
        });

        return response()->json($result);
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
            ['key' => 'new_jobs', 'label' => 'Yeni işler', 'tone' => 'blue'],
            ['key' => 'appointment_confirmed', 'label' => 'Randevu onaylandı', 'tone' => 'green'],
            ['key' => 'revisit', 'label' => 'Tekrar ziyaret', 'tone' => 'amber'],
            ['key' => 'completed', 'label' => 'Tamamlanan işler', 'tone' => 'slate'],
        ])->map(function (array $column) use ($jobs): array {
            $column['jobs'] = collect($jobs)->where('kanban_column', $column['key'])->values()->all();
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
            TechnicalServicePartnerJobAction::ACTION_ACCEPTED => 'Çilingir portalı: randevu onaylandı',
            TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => 'Çilingir portalı: tekrar ziyaret istendi',
            TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED => 'Çilingir portalı: tamamlama gönderildi',
            TechnicalServicePartnerJobAction::ACTION_NOTE_ADDED => 'Çilingir portalı: not eklendi',
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => 'Çilingir portalı: randevu önerildi',
            TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED => 'Çilingir portalı: iş reddedildi',
            default => 'Çilingir portalı aksiyonu',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function slotLabel(string $slot, array $payload = []): string
    {
        return match ($slot) {
            'morning' => 'Öğleden önce',
            'afternoon' => 'Öğleden sonra',
            'full_day' => 'Tam gün / operasyon belirlesin',
            'custom' => trim(($payload['proposed_time_start'] ?? '').' - '.($payload['proposed_time_end'] ?? '')) ?: 'Özel saat',
            default => $slot,
        };
    }

    private function rejectReasonLabel(string $reason): string
    {
        return match ($reason) {
            'not_available' => 'Uygun değilim',
            'region_not_suitable' => 'Bölge uygun değil',
            'time_not_suitable' => 'Zaman uygun değil',
            'customer_disagreement' => 'Müşteriyle anlaşamadım',
            'other' => 'Diğer',
            default => $reason,
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
                'checklist' => 'Tamamlamaya göndermek için tüm checklist maddeleri işaretlenmelidir.',
            ]);
        }
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
        return in_array($job->workflow_status, ['Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen'], true)
            && $job->checklist_status === 'tamamlandı'
            && (int) ($job->before_photo_count ?? 0) >= 3
            && (int) ($job->after_photo_count ?? 0) >= 3
            && (int) ($job->general_photo_count ?? 0) >= 1
            && in_array($job->document_status, ['tamamlandı', 'tamam', 'gerekli_degil'], true)
            && $job->customer_closure_approval_status === 'onaylandı';
    }
}
