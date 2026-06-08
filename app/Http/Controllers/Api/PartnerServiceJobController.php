<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2B\B2BPartner;
use App\Models\TechnicalServiceAssignmentArchive;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceCustomerConfirmation;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\User;
use App\Services\B2B\B2BPartnerPortalDataService;
use App\Services\B2B\B2BPartnerServiceJobScopeService;
use App\Services\PanelAccessService;
use App\Services\Messaging\EvolutionWhatsAppMessageService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use App\Services\TechnicalService\TechnicalServicePartRequestService;
use App\Services\TechnicalService\TechnicalServiceUiLabelService;
use App\Support\PartnerPortalPublicUrl;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
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

    private const PORTAL_PHOTO_ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'];

    private const PORTAL_PHOTO_STANDARD_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private const PORTAL_PHOTO_HEIC_MIMES = [
        'image/heic',
        'image/heif',
        'image/heic-sequence',
        'image/heif-sequence',
        'application/octet-stream',
    ];

    public function __construct(
        private readonly B2BPartnerServiceJobScopeService $scope,
        private readonly B2BPartnerPortalDataService $portalData,
        private readonly TechnicalServiceWorkflowService $workflow,
        private readonly TechnicalServicePartRequestService $partRequests,
        private readonly PanelAccessService $panelAccess,
        private readonly EvolutionWhatsAppMessageService $messages,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authorizedUser($request, 'partner.service_jobs.view');
        $partner = $this->scope->selectedLocksmithPartnerForPortal(
            $user,
            $request->integer('partner_id') ?: null,
        );

        $jobs = $this->scope
            ->serviceJobsQuery($partner)
            ->with([
                'partnerJobActions' => fn ($query) => $query->latest(),
                'partRequests' => fn ($query) => $query->latest(),
                'uploads',
            ])
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (TechnicalServiceRequest $job): array => $this->portalData->safeServiceJobSummary($job, $partner))
            ->values()
            ->all();

        return response()->json([
            'status' => 'ok',
            'partner_id' => $partner->id,
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
            'job' => $this->portalData->safeServiceJobSummary($job, $partner),
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
            $result = DB::transaction(function () use ($job, $partner, $user, $data): array {
                $from = $job->workflow_status;
                $job->forceFill([
                    'technician_approved_at' => $job->technician_approved_at ?? now(),
                    'technician_approval_status' => 'kabul edildi',
                ])->save();

                $this->recordAction($job->refresh(), $partner, $user, TechnicalServicePartnerJobAction::ACTION_ACCEPTED, TechnicalServicePartnerJobAction::STATUS_APPLIED, [
                    'note' => $data['note'] ?? null,
                    'accepted_without_ops_appointment' => true,
                    'accepted_at' => now()->toISOString(),
                ], $data['note'] ?? null, $from);

                return $this->jobResponse($job->refresh(), TechnicalServicePartnerJobAction::STATUS_APPLIED, $partner);
            });

            return response()->json($result);
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

            return $this->jobResponse($job->refresh(), $status, $partner);
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
            $actionType = $this->hasOpsAppointment($job)
                ? TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED
                : TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED;
            $currentAppointment = $this->hasOpsAppointment($job) ? [
                'scheduled_date' => $job->scheduled_date?->toDateString(),
                'scheduled_time' => $job->scheduled_time,
                'scheduled_at' => $job->scheduled_at?->toISOString(),
            ] : null;

            return $this->recordAction($job, $partner, $user, $actionType, TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW, [
                'slots' => $slots,
                'note' => $data['note'] ?? null,
                'submitted_at' => now()->toISOString(),
                'ops_review_required' => true,
                'current_appointment' => $currentAppointment,
            ], $data['note'] ?? null, $job->workflow_status);
        });

        return response()->json([
            'status' => $action->status,
            'action' => $action->action,
            'job' => $this->portalData->safeServiceJobSummary($job->refresh(), $partner),
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
            $action = $this->recordAction($job, $partner, $user, TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED, TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW, [
                'reason' => $data['reason'],
                'reason_label' => $this->rejectReasonLabel($data['reason']),
                'note' => $data['note'] ?? null,
                'submitted_at' => now()->toISOString(),
                'ops_review_required' => true,
            ], $data['note'] ?? $this->rejectReasonLabel($data['reason']), $job->workflow_status);

            $this->archiveRejectedAssignment($job->refresh(), $partner, $user, $action, $data['reason']);

            return $action;
        });

        $this->messages->send(
            'job_rejected_ops',
            'ops',
            null,
            "Usta işi reddetti. MRN: {$job->mrn}. Neden: ".$this->rejectReasonLabel($data['reason']).". Açıklama: {$data['note']}",
            ['reason' => $data['reason'], 'partner_id' => $partner->id],
            $job,
            $user,
            $action,
        );

        return response()->json([
            'status' => $action->status,
            'action' => $action->action,
            'job' => $this->portalData->safeServiceJobSummary($job->refresh(), $partner),
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

        $action = DB::transaction(function () use ($job, $partner, $user, $data): TechnicalServicePartnerJobAction {
            return $this->recordAction($job, $partner, $user, TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED, TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW, [
                'reason' => $data['reason'],
                'preferred_date' => $data['preferred_date'] ?? null,
                'note' => $data['note'] ?? null,
                'ops_review_required' => true,
                'submitted_at' => now()->toISOString(),
            ], $data['note'] ?? $data['reason'], $job->workflow_status);
        });

        return response()->json([
            'status' => $action->status,
            'action' => $action->action,
            'job' => $this->portalData->safeServiceJobSummary($job->refresh(), $partner),
        ]);
    }
    public function submitCompletion(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        $this->ensureFieldActionStage($job, 'Tamamlama gönderimi sadece randevu onaylandıktan sonra yapılabilir.');
        $data = $request->validate([
            'result' => ['required', 'string', Rule::in(['completed', 'revisit_required', 'customer_not_available', 'missing_info_or_photo', 'parts_pending'])],
            'checklist' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:2000'],
            'customer_confirmation_method' => ['nullable', 'string', 'max:128'],
            'customer_confirmation_note' => ['nullable', 'string', 'max:1000'],
            'photo_upload_ids' => ['nullable', 'array'],
            'photo_upload_ids.*' => ['integer'],
        ]);
        $checklist = $data['checklist'] ?? $this->portalCompletionChecklist();
        $this->validateSimpleChecklist($checklist);
        $this->validateNoOpenPartRequest($job);
        $this->validateCompletionEvidence($job, $data);
        $completionNote = trim((string) ($data['note'] ?? ''));

        $result = DB::transaction(function () use ($job, $partner, $user, $data, $checklist, $completionNote): array {
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

            $job->forceFill([
                'status' => 'Son Kontrol',
                'workflow_status' => 'Son Kontrol',
                'field_status' => 'son_kontrol',
                'completed_at' => null,
                'installation_completed_at' => null,
                'field_completed_at' => null,
                'technician_completed_at' => null,
                'completion_block_reason' => null,
                'checklist_payload' => $this->technicalChecklistPayload(),
                'checklist_status' => 'tamamlandı',
                'checklist_completed_at' => now(),
            ])->save();
            $payload['checklist_applied'] = true;

            $this->recordAction($job->refresh(), $partner, $user, TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED, $status, $payload, $completionNote !== '' ? $completionNote : null, $from);

            return $this->jobResponse($job->refresh(), $status, $partner);
        });

        $message = "Usta işi son kontrole gönderdi. MRN: {$job->mrn}.";
        if ($completionNote !== '') {
            $message .= " Not: {$completionNote}";
        }

        $this->messages->send(
            'completion_submitted_ops',
            'ops',
            null,
            $message,
            ['partner_id' => $partner->id, 'result' => $data['result']],
            $job,
            $user,
            $job->partnerJobActions()->latest()->first(),
        );

        return response()->json($result);
    }

    public function uploadPhotos(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        if (! $this->hasOpsAppointment($job)) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Fotoğraf yükleme sadece randevu onaylandıktan sonra yapılabilir.',
            ]);
        }
        $this->ensureFieldActionStage($job, 'Fotoğraf yükleme sadece randevu onaylandıktan sonra yapılabilir.');
        $this->ensurePhotoUploadIsOpen($job);
        $portalPhotoRule = $this->portalPhotoRule();
        $data = $request->validate([
            'before_photo' => $portalPhotoRule,
            'after_photo' => $portalPhotoRule,
            'warranty_document_photo' => $portalPhotoRule,
            'door_front_photo' => $portalPhotoRule,
            'door_side_photo' => $portalPhotoRule,
            'door_back_photo' => $portalPhotoRule,
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

        $replacedFields = $this->currentPortalPhotoFields($job, $files->keys()->all());

        $uploads = DB::transaction(function () use ($files, $job, $partner, $user, $replacedFields): array {
            $created = [];

            foreach ($files as $fieldCode => $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $extension = $this->portalPhotoExtension($file);
                $filename = $fieldCode.'-'.Str::uuid().'.'.$extension;
                $path = $file->storeAs("technical-service/requests/{$job->id}/partner-portal", $filename, 'public');
                $uploadedAt = now();
                if ($job->reopened_at instanceof \Carbon\CarbonInterface
                    && $uploadedAt->lessThanOrEqualTo($job->reopened_at->copy()->addSecond())) {
                    $uploadedAt = $job->reopened_at->copy()->addSecond();
                }
                $upload = TechnicalServiceRequestUpload::query()->create([
                    'technical_service_request_id' => $job->id,
                    'field_code' => (string) $fieldCode,
                    'category' => TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT,
                    'original_name' => self::REQUIRED_PORTAL_PHOTO_FIELDS[(string) $fieldCode].' - '.$file->getClientOriginalName(),
                    'path' => $path,
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize() ?: 0,
                ]);
                $upload->forceFill([
                    'created_at' => $uploadedAt,
                    'updated_at' => $uploadedAt,
                ])->save();
                $created[] = $upload->refresh();
            }

            $photoReadiness = $this->portalPhotoReadiness($job->refresh());
            $job->forceFill([
                'before_photo_count' => in_array('before_photo', $photoReadiness['present_fields'], true) ? max(1, (int) ($job->before_photo_count ?? 0)) : (int) ($job->before_photo_count ?? 0),
                'after_photo_count' => in_array('after_photo', $photoReadiness['present_fields'], true) ? max(1, (int) ($job->after_photo_count ?? 0)) : (int) ($job->after_photo_count ?? 0),
                'general_photo_count' => in_array('warranty_document_photo', $photoReadiness['present_fields'], true) ? max(1, (int) ($job->general_photo_count ?? 0)) : (int) ($job->general_photo_count ?? 0),
                'photo_status' => $photoReadiness['ready'] ? 'tamamlandı' : 'eksik',
            ])->save();
            $customerApprovalReset = $replacedFields !== []
                && $this->resetCustomerApprovalAfterPhotoReplacement($job->refresh());

            $this->recordAction($job->refresh(), $partner, $user, TechnicalServicePartnerJobAction::ACTION_PHOTOS_UPLOADED, TechnicalServicePartnerJobAction::STATUS_SUBMITTED, [
                'visibility' => 'ops',
                'photo_upload_ids' => collect($created)->pluck('id')->all(),
                'photo_fields' => collect($created)->pluck('field_code')->all(),
                'replaced_photo_fields' => $replacedFields,
                'customer_approval_reset' => $customerApprovalReset,
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
            'job' => $this->portalData->safeServiceJobSummary($job->refresh(), $partner),
        ]);
    }

    public function customerOtpRequest(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        $this->ensureFieldActionStage($job, 'Müşteri onayı sadece randevu onaylandıktan sonra istenebilir.');
        if (! $this->portalPhotoReadiness($job)['ready']) {
            throw ValidationException::withMessages([
                'photos' => 'Müşteri onayı için önce 3 fotoğrafı yükleyin.',
            ]);
        }

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'message_text' => ['nullable', 'string', 'max:5000'],
        ]);

        [$action, $dispatchSummary] = DB::transaction(function () use ($job, $partner, $user, $data): array {
            TechnicalServiceCustomerConfirmation::query()
                ->where('technical_service_request_id', $job->id)
                ->where('status', TechnicalServiceCustomerConfirmation::STATUS_PENDING)
                ->update(['status' => TechnicalServiceCustomerConfirmation::STATUS_CANCELLED]);

            $confirmation = TechnicalServiceCustomerConfirmation::query()->create([
                'technical_service_request_id' => $job->id,
                'token' => Str::random(64),
                'status' => TechnicalServiceCustomerConfirmation::STATUS_PENDING,
                'payload' => [
                    'partner_id' => $partner->id,
                    'requested_by_user_id' => $user->id,
                    'technical_service_technician_id' => $job->technical_service_technician_id,
                ],
            ]);
            $approvalUrl = PartnerPortalPublicUrl::route('service-job-confirmation.show', ['token' => $confirmation->token]);
            $publicUrlWarning = $this->messages->testMode() && PartnerPortalPublicUrl::isLocalUrl($approvalUrl)
                ? 'Onay linki lokal URL içeriyor; telefondan açılamaz. PARTNER_PORTAL_PUBLIC_URL ayarlanmalı.'
                : null;
            $messageText = $this->customerApprovalMessageText($job, $approvalUrl, $data['message_text'] ?? null);
            $whatsappUrl = 'https://wa.me/'.$this->normalizedPhoneForWa($job->customer_phone).'?text='.rawurlencode($messageText);

            $action = $this->recordAction($job, $partner, $user, TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED, TechnicalServicePartnerJobAction::STATUS_SUBMITTED, [
                'confirmation_method' => 'customer_link',
                'provider' => 'system_payload',
                'confirmation_id' => $confirmation->id,
                'approval_url' => $approvalUrl,
                'requested_at' => now()->toISOString(),
                'message_payload' => [
                    'recipient' => 'customer',
                    'channel' => 'system_payload',
                    'mrn' => $job->mrn,
                    'customer_phone' => $job->customer_phone,
                    'message_text' => $messageText,
                    'approval_url' => $approvalUrl,
                    'confirmation_url' => $approvalUrl,
                    'whatsapp_url' => $whatsappUrl,
                    'public_url_warning' => $publicUrlWarning,
                ],
            ], $data['note'] ?? 'Müşteri montaj onay bağlantısı hazırlandı.', $job->workflow_status);

            $dispatch = $this->messages->send(
                'customer_approval_request',
                'customer',
                $job->customer_phone,
                $messageText,
                [
                    'confirmation_url' => $approvalUrl,
                    'approval_url' => $approvalUrl,
                    'confirmation_id' => $confirmation->id,
                    'whatsapp_url' => $whatsappUrl,
                    'public_url_warning' => $publicUrlWarning,
                    'manual_ui_send' => true,
                ],
                $job,
                $user,
                $action,
            );
            $dispatchSummary = $this->dispatchSummary($dispatch);
            $messagePayload = [
                ...($action->payload['message_payload'] ?? []),
                ...$dispatchSummary,
                'dispatch_id' => $dispatch->id,
            ];

            $actionPayload = [
                ...(is_array($action->payload) ? $action->payload : []),
                ...$dispatchSummary,
                'message_payload' => $messagePayload,
            ];

            $action->forceFill(['payload' => $actionPayload])->save();

            $confirmation->forceFill([
                'payload' => [
                    ...(is_array($confirmation->payload) ? $confirmation->payload : []),
                    'partner_action_id' => $action->id,
                    'message_payload' => $messagePayload,
                ],
            ])->save();

            return [$action, $dispatchSummary];
        });

        return response()->json([
            'status' => $action->status,
            'action' => $action->action,
            'dispatch' => $dispatchSummary,
            'message' => $this->dispatchUserMessage($dispatchSummary),
            'job' => $this->portalData->safeServiceJobSummary($job->refresh(), $partner),
        ]);
    }

    public function supportRequest(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        $this->ensureSupportRequestStage($job, 'Ek talep sadece randevu onaylandıktan veya tekrar ziyaret aşamasına geçtikten sonra gönderilebilir.');
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(['spare_part', 'technical_support', 'extra_product', 'missing_info', 'customer_call', 'other'])],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $action = DB::transaction(function () use ($job, $partner, $user, $data): TechnicalServicePartnerJobAction {
            $action = $this->recordAction($job, $partner, $user, TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED, TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW, [
                'type' => $data['type'],
                'type_label' => $this->supportTypeLabel($data['type']),
                'description' => $data['description'],
                'product_name' => $data['product_name'] ?? null,
                'quantity' => $data['quantity'] ?? null,
                'submitted_at' => now()->toISOString(),
                'ops_review_required' => true,
            ], $data['description'], $job->workflow_status);

            if ($data['type'] === 'spare_part') {
                $partRequest = $this->partRequests->createFromPartnerSupport($job->refresh(), $user, $action, $data);
                $action->forceFill([
                    'payload' => [
                        ...(is_array($action->payload) ? $action->payload : []),
                        'part_request_id' => $partRequest->id,
                        'part_request_status' => $partRequest->status,
                    ],
                ])->save();
            }

            return $action;
        });

        $this->messages->send(
            'support_request_ops',
            'ops',
            null,
            "Usta ek talep oluşturdu. MRN: {$job->mrn}. Talep: ".$this->supportTypeLabel($data['type']).". Açıklama: {$data['description']}",
            ['partner_id' => $partner->id, 'type' => $data['type']],
            $job,
            $user,
            $action,
        );

        return response()->json([
            'status' => $action->status,
            'action' => $action->action,
            'job' => $this->portalData->safeServiceJobSummary($job->refresh(), $partner),
        ]);
    }

    public function receivePart(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServicePartRequest $partRequest,
    ): JsonResponse {
        $user = $this->authorizedUser($request, 'partner.service_jobs.view');
        $job = $technicalServiceRequest;
        $partner = $this->partnerForPartReceive($user, $job, $partRequest);
        abort_unless((int) $partRequest->technical_service_request_id === (int) $job->id, 404);

        $result = $this->partRequests->receiveAndPrepareServiceVisit($partRequest, $user);
        $partRequest = $result['part_request'];
        $serviceVisit = $result['service_visit'];

        return response()->json([
            'status' => $partRequest->status,
            'part_request' => $this->partRequests->serialize($partRequest, forPartner: true),
            'parent_job' => $this->portalData->safeServiceJobSummary($job->refresh(), $partner),
            'job' => $this->portalData->safeServiceJobSummary($serviceVisit->refresh(), $partner),
        ]);
    }

    public function priceRevisionRequest(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        $data = $request->validate([
            'labor_amount' => ['nullable', 'numeric', 'min:0'],
            'route_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        if (! array_key_exists('labor_amount', $data) && ! array_key_exists('route_fee_amount', $data)) {
            throw ValidationException::withMessages([
                'amount' => 'En az bir hakediş tutarı girilmelidir.',
            ]);
        }

        $action = DB::transaction(function () use ($job, $partner, $user, $data): TechnicalServicePartnerJobAction {
            return $this->recordAction($job, $partner, $user, TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED, TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW, [
                'labor_amount' => array_key_exists('labor_amount', $data) ? round((float) $data['labor_amount'], 2) : null,
                'route_fee_amount' => array_key_exists('route_fee_amount', $data) ? round((float) $data['route_fee_amount'], 2) : null,
                'note' => $data['note'],
                'submitted_at' => now()->toISOString(),
                'ops_review_required' => true,
            ], $data['note'], $job->workflow_status);
        });

        $this->messages->send(
            'price_revision_requested_ops',
            'ops',
            null,
            "Usta hakediş revize talebi oluşturdu. MRN: {$job->mrn}. Açıklama: {$data['note']}",
            [
                'labor_amount' => $data['labor_amount'] ?? null,
                'route_fee_amount' => $data['route_fee_amount'] ?? null,
                'partner_id' => $partner->id,
            ],
            $job,
            $user,
            $action,
        );

        return response()->json([
            'status' => $action->status,
            'action' => $action->action,
            'job' => $this->portalData->safeServiceJobSummary($job->refresh(), $partner),
        ]);
    }

    public function note(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        [$user, $job, $partner] = $this->authorizedJob($request, $technicalServiceRequest);
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'visibility' => ['nullable', 'string', Rule::in(['partner_to_ops', 'ops', 'ops_internal', 'internal_partner'])],
        ]);
        $visibility = match ($data['visibility'] ?? 'partner_to_ops') {
            'ops', 'partner_to_ops' => 'partner_to_ops',
            default => 'ops_internal',
        };

        $action = DB::transaction(function () use ($job, $partner, $user, $data, $visibility): TechnicalServicePartnerJobAction {
            return $this->recordAction($job, $partner, $user, TechnicalServicePartnerJobAction::ACTION_NOTE_ADDED, TechnicalServicePartnerJobAction::STATUS_SUBMITTED, [
                'visibility' => $visibility,
            ], $data['note'], $job->workflow_status);
        });

        return response()->json([
            'status' => 'ok',
            'action' => $action->action,
            'job' => $this->portalData->safeServiceJobSummary($job->refresh(), $partner),
        ]);
    }

    public function earnings(Request $request): JsonResponse
    {
        $user = $this->authorizedUser($request, 'partner.earnings.view');
        $partner = $this->scope->selectedLocksmithPartnerForPortal(
            $user,
            $request->integer('partner_id') ?: null,
        );
        $rows = collect([[
            'partner_id' => $partner->id,
            'partner_name' => $partner->display_name,
            'earnings' => $this->portalData->earningsFor($partner),
        ]]);

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

    private function partnerForPartReceive(
        User $user,
        TechnicalServiceRequest $parent,
        TechnicalServicePartRequest $partRequest,
    ): B2BPartner {
        try {
            return $this->scope->assertCanViewServiceJob($user, $parent);
        } catch (AuthorizationException $exception) {
            $serviceVisitId = $partRequest->service_visit_request_id
                ?? (is_array($partRequest->metadata) ? ($partRequest->metadata['service_visit_created']['request_id'] ?? null) : null);

            if (! is_numeric($serviceVisitId)) {
                throw $exception;
            }

            $serviceVisit = TechnicalServiceRequest::query()->find((int) $serviceVisitId);
            if (! $serviceVisit instanceof TechnicalServiceRequest
                || (int) $serviceVisit->parent_request_id !== (int) $parent->id) {
                throw $exception;
            }

            return $this->scope->assertCanViewServiceJob($user, $serviceVisit);
        }
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

    private function archiveRejectedAssignment(
        TechnicalServiceRequest $job,
        B2BPartner $partner,
        User $user,
        TechnicalServicePartnerJobAction $action,
        string $reason,
    ): void {
        $oldTechnicianId = (int) ($job->technical_service_technician_id ?? 0);

        if ($oldTechnicianId <= 0) {
            return;
        }

        TechnicalServiceAssignmentArchive::query()->create([
            'technical_service_request_id' => $job->id,
            'old_technician_id' => $oldTechnicianId,
            'new_technician_id' => null,
            'old_partner_id' => $partner->id,
            'new_partner_id' => null,
            'reason' => 'job_rejected',
            'archived_by' => $user->id,
            'archived_at' => now(),
            'metadata' => [
                'source' => 'partner_portal_reject',
                'partner_job_action_id' => $action->id,
                'reject_reason' => $reason,
                'source_status' => $job->workflow_status,
            ],
        ]);

        TechnicalServiceAssignmentOffer::query()
            ->where('technical_service_request_id', $job->id)
            ->where('technical_service_technician_id', $oldTechnicianId)
            ->whereIn('status', [
                TechnicalServiceAssignmentOffer::STATUS_SENT,
                TechnicalServiceAssignmentOffer::STATUS_REVISED,
            ])
            ->update([
                'status' => TechnicalServiceAssignmentOffer::STATUS_CANCELLED,
                'updated_at' => now(),
            ]);

        TechnicalServiceCustomerConfirmation::query()
            ->where('technical_service_request_id', $job->id)
            ->where('status', TechnicalServiceCustomerConfirmation::STATUS_PENDING)
            ->update(['status' => TechnicalServiceCustomerConfirmation::STATUS_CANCELLED]);

        $job->forceFill([
            'technician_approved_at' => null,
            'technician_approval_status' => 'reddedildi',
        ])->save();
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
            ['key' => 'ops_review', 'label' => 'Operasyon incelemede', 'tone' => 'violet'],
            ['key' => 'revisit', 'label' => 'Tekrar ziyaret', 'tone' => 'amber'],
            ['key' => 'final_check', 'label' => 'Son kontrol bekliyor', 'tone' => 'violet'],
            ['key' => 'completed', 'label' => 'Tamamlanan işler', 'tone' => 'slate'],
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

        $job->touch();

        return $record;
    }

    private function eventType(string $action): string
    {
        return match ($action) {
            TechnicalServicePartnerJobAction::ACTION_ACCEPTED => 'Çilingir portalı: iş kabul edildi',
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED => 'Çilingir portalı: randevu değişikliği istendi',
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_ACCEPTED_BY_TECHNICIAN => 'partner_portal_appointment_accepted',
            default => 'partner_portal_'.$action,
        };
    }

    private function eventTitle(string $action): string
    {
        return match ($action) {
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_ACCEPTED_BY_TECHNICIAN => 'Çilingir portalı: randevu onaylandı',
            TechnicalServicePartnerJobAction::ACTION_ACCEPTED => 'Çilingir portalı: iş kabul edildi',
            TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => 'Çilingir portalı: tekrar ziyaret istendi',
            TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED => 'Çilingir portalı: tamamlama gönderildi',
            TechnicalServicePartnerJobAction::ACTION_NOTE_ADDED => 'Çilingir portalı: not eklendi',
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => 'Çilingir portalı: randevu önerildi',
            TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED => 'Çilingir portalı: iş reddedildi',
            TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED => 'Çilingir portalı: müşteri OTP/onay isteği oluşturuldu',
            TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED => 'Çilingir portalı: ek talep oluşturuldu',
            TechnicalServicePartnerJobAction::ACTION_PHOTOS_UPLOADED => 'Çilingir portalı: fotoğraf yüklendi',
            default => 'Çilingir portalı aksiyonu',
        };
    }

    /**
     * @return array<int, mixed>
     */
    private function portalPhotoRule(): array
    {
        return [
            'nullable',
            'file',
            'max:10240',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value instanceof UploadedFile) {
                    return;
                }

                $extension = $this->portalPhotoExtension($value);
                if (! in_array($extension, self::PORTAL_PHOTO_ALLOWED_EXTENSIONS, true)) {
                    $fail('Fotoğraf JPG, PNG, WEBP, GIF, HEIC veya HEIF formatında olmalıdır.');

                    return;
                }

                $mimes = array_values(array_filter(array_map(
                    fn (?string $mime): string => strtolower((string) $mime),
                    [$value->getMimeType(), $value->getClientMimeType()],
                )));

                if (array_intersect($mimes, self::PORTAL_PHOTO_STANDARD_MIMES) !== []) {
                    return;
                }

                if (in_array($extension, ['heic', 'heif'], true)
                    && array_intersect($mimes, self::PORTAL_PHOTO_HEIC_MIMES) !== []) {
                    return;
                }

                $fail('Fotoğraf dosyası desteklenen bir görsel formatında olmalıdır.');
            },
        ];
    }

    private function portalPhotoExtension(UploadedFile $file): string
    {
        $extension = strtolower(trim($file->getClientOriginalExtension()
            ?: $file->extension()
            ?: $file->guessExtension()
            ?: 'jpg'));

        return preg_replace('/[^a-z0-9]/', '', $extension) ?: 'jpg';
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
            'not_available' => 'Uygun değilim',
            'region_not_suitable' => 'Bölge uygun değil',
            'time_not_suitable' => 'Zaman uygun değil',
            'customer_unreachable' => 'Müşteriyle iletişim kurulamadı',
            'customer_disagreement' => 'Müşteriyle anlaşamadım',
            'other' => 'Diğer',
            default => $reason,
        };
    }

    private function supportTypeLabel(string $type): string
    {
        return match ($type) {
            'technical_support' => 'Teknik destek',
            'spare_part' => 'Yedek parça',
            'extra_product' => 'Ek ürün',
            'missing_info' => 'Eksik bilgi',
            'customer_call' => 'Müşteri aransın',
            'other' => 'Diğer',
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

    private function ensurePhotoUploadIsOpen(TechnicalServiceRequest $job): void
    {
        if ($this->hasActiveCompletionSubmission($job)) {
            throw ValidationException::withMessages([
                'photos' => 'Tamamlamaya gönderilen işte belge değiştirilemez.',
            ]);
        }
    }

    private function hasActiveCompletionSubmission(TechnicalServiceRequest $job): bool
    {
        $query = $job->partnerJobActions()
            ->where('action', TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED)
            ->where('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW);

        if ($job->reopened_at instanceof \Carbon\CarbonInterface) {
            $query->where(function ($query) use ($job): void {
                $query->where('created_at', '>=', $job->reopened_at)
                    ->orWhere('updated_at', '>=', $job->reopened_at);
            });
        }

        return $query->exists();
    }

    private function resetCustomerApprovalAfterPhotoReplacement(TechnicalServiceRequest $job): bool
    {
        $status = TechnicalServiceUiLabelService::cleanDisplayText((string) $job->customer_closure_approval_status);
        $closureApprovalIsCurrent = $job->reopened_at === null
            || ($job->customer_closure_approved_at !== null && $job->customer_closure_approved_at->greaterThan($job->reopened_at));
        $hasCurrentClosureApproval = $closureApprovalIsCurrent
            && in_array($status, ['onaylandı', 'onaylandi', 'approved'], true);
        $approvedConfirmationQuery = TechnicalServiceCustomerConfirmation::query()
            ->where('technical_service_request_id', $job->id)
            ->where('status', TechnicalServiceCustomerConfirmation::STATUS_APPROVED);

        if ($job->reopened_at instanceof \Carbon\CarbonInterface) {
            $approvedConfirmationQuery->where(function ($query) use ($job): void {
                $query->where('created_at', '>', $job->reopened_at)
                    ->orWhere('updated_at', '>', $job->reopened_at)
                    ->orWhere('approved_at', '>', $job->reopened_at);
            });
        }

        if (! $hasCurrentClosureApproval && ! (clone $approvedConfirmationQuery)->exists()) {
            return false;
        }

        $approvedConfirmationQuery->update([
            'status' => TechnicalServiceCustomerConfirmation::STATUS_CANCELLED,
            'approved_at' => null,
        ]);
        $job->forceFill([
            'customer_closure_approval_status' => null,
            'customer_closure_approval_method' => null,
            'customer_closure_approval_code' => null,
            'customer_closure_approved_at' => null,
        ])->save();

        return true;
    }

    private function canUseFieldActions(TechnicalServiceRequest $job): bool
    {
        if ($job->completed_at !== null && ! $this->isActiveReopenedWork($job)) {
            return false;
        }

        $workflowStatus = TechnicalServiceUiLabelService::cleanDisplayText((string) $job->workflow_status);
        $status = TechnicalServiceUiLabelService::cleanDisplayText((string) $job->status);

        if (in_array($workflowStatus, ['Son Kontrol', 'Tamamlandı', 'İptal'], true)
            || in_array($status, ['Son Kontrol', 'Tamamlandı', 'İptal'], true)) {
            return false;
        }

        if ($this->hasOpsAppointment($job)) {
            return true;
        }

        return in_array($workflowStatus, [
            'Planlı',
            'Yolda',
            'Sahada',
            'Belge / Fotoğraf Bekleyen',
            'Müşteri Kapanış Onayı Bekleyen',
        ], true);
    }

    private function isActiveReopenedWork(TechnicalServiceRequest $job): bool
    {
        if ($job->reopened_at === null) {
            return false;
        }

        $workflowStatus = TechnicalServiceUiLabelService::cleanDisplayText((string) $job->workflow_status);
        $status = TechnicalServiceUiLabelService::cleanDisplayText((string) $job->status);
        $terminalStatuses = ['Tamamlandı', 'Tamamlandi', 'İptal', 'Iptal'];

        return ! in_array($workflowStatus, $terminalStatuses, true)
            && ! in_array($status, $terminalStatuses, true);
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

    private function jobResponse(TechnicalServiceRequest $job, string $status, ?B2BPartner $partner = null): array
    {
        return [
            'status' => $status,
            'job' => $this->portalData->safeServiceJobSummary($job, $partner),
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
                'customer_confirmation' => 'Müşteri onayı olmadan tamamlamaya gönderilemez.',
            ]);
        }
    }

    private function validateNoOpenPartRequest(TechnicalServiceRequest $job): void
    {
        if (! $this->partRequests->hasOpenBlockingPartRequest($job)) {
            return;
        }

        throw ValidationException::withMessages([
            'part_request' => 'Açık parça talebi varken iş son kontrole gönderilemez.',
        ]);
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
        $job->load('uploads');
        $uploadedFields = $job->uploads
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $this->isPortalFieldDocument($upload)
                && ! $this->portalDocumentPredatesActiveReopen($job, $upload->created_at ?? $upload->updated_at))
            ->map(fn (TechnicalServiceRequestUpload $upload): ?string => $this->canonicalPortalPhotoField($upload->field_code))
            ->filter(fn (?string $field): bool => $field !== null && array_key_exists($field, self::REQUIRED_PORTAL_PHOTO_FIELDS))
            ->unique()
            ->values();

        $presentFields = $uploadedFields;

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

    /**
     * @param  array<int, string>  $fieldCodes
     * @return array<int, string>
     */
    private function currentPortalPhotoFields(TechnicalServiceRequest $job, array $fieldCodes): array
    {
        $fields = collect($fieldCodes)
            ->map(fn (string $field): ?string => $this->canonicalPortalPhotoField($field))
            ->filter(fn (?string $field): bool => $field !== null && array_key_exists($field, self::REQUIRED_PORTAL_PHOTO_FIELDS))
            ->unique()
            ->values();

        if ($fields->isEmpty()) {
            return [];
        }

        $job->load('uploads');

        return $job->uploads
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $this->isPortalFieldDocument($upload)
                && ! $this->portalDocumentPredatesActiveReopen($job, $upload->created_at ?? $upload->updated_at))
            ->map(fn (TechnicalServiceRequestUpload $upload): ?string => $this->canonicalPortalPhotoField($upload->field_code))
            ->filter(fn (?string $field): bool => $field !== null && $fields->contains($field))
            ->unique()
            ->values()
            ->all();
    }

    private function canonicalPortalPhotoField(?string $fieldCode): ?string
    {
        $field = trim((string) $fieldCode);

        if ($field === '') {
            return null;
        }

        return $field;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasCustomerConfirmation(TechnicalServiceRequest $job, array $data): bool
    {
        $latestConfirmationQuery = $job->customerConfirmations()
            ->latest('id');

        if ($job->reopened_at !== null) {
            $latestConfirmationQuery->where(function ($query) use ($job): void {
                $query->where('created_at', '>', $job->reopened_at)
                    ->orWhere('updated_at', '>', $job->reopened_at);
            });
        }

        $latestConfirmation = $latestConfirmationQuery->first();

        if ($latestConfirmation instanceof TechnicalServiceCustomerConfirmation) {
            return $latestConfirmation->status === TechnicalServiceCustomerConfirmation::STATUS_APPROVED;
        }

        $status = TechnicalServiceUiLabelService::cleanDisplayText((string) $job->customer_closure_approval_status);
        $approvalIsCurrent = $job->reopened_at === null
            || ($job->customer_closure_approved_at !== null && $job->customer_closure_approved_at->greaterThan($job->reopened_at));

        return $approvalIsCurrent && in_array($status, ['onaylandı', 'onaylandi', 'onaylandÄ±'], true);
    }

    private function isPortalFieldDocument(TechnicalServiceRequestUpload $upload): bool
    {
        if ($upload->category === TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT) {
            return true;
        }

        return $upload->category === TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO
            && array_key_exists((string) $upload->field_code, self::REQUIRED_PORTAL_PHOTO_FIELDS);
    }

    private function customerApprovalMessageText(TechnicalServiceRequest $job, string $approvalUrl, ?string $customMessage = null): string
    {
        $customMessage = trim((string) $customMessage);

        if ($customMessage !== '') {
            return $this->messageTextWithApprovalUrl($customMessage, $approvalUrl);
        }

        $product = trim(implode(' / ', array_filter([
            (string) $job->product_name,
            (string) $job->product_model,
        ])));

        return $this->messageTextWithApprovalUrl(implode("\n", [
            'Emaks Prime Teknik Servis',
            '',
            'Sayın '.($job->customer_name ?: 'müşterimiz').',',
            ($product !== '' ? $product.' montaj işleminiz için onayınız gerekmektedir.' : 'Montaj işleminiz için onayınız gerekmektedir.'),
            '',
            'Talep No: '.$job->mrn,
            '',
            'Montajın tamamlandığını ve üründe görünür hasar/kusur olmadığını kontrol ettiyseniz aşağıdaki bağlantıdan onay verebilirsiniz:',
            '',
            $approvalUrl,
            '',
            'Bu işlemi siz yapmadıysanız operasyon ekibimizle iletişime geçiniz.',
        ]), $approvalUrl);
    }

    private function messageTextWithApprovalUrl(string $messageText, string $approvalUrl): string
    {
        $messageText = trim($messageText);

        if (str_contains($messageText, $approvalUrl)) {
            return $messageText;
        }

        return rtrim($messageText)."\n\n".$approvalUrl;
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchSummary(TechnicalServiceMessageDispatch $dispatch): array
    {
        $responsePayload = is_array($dispatch->response_payload) ? $dispatch->response_payload : [];
        $requestPayload = is_array($dispatch->request_payload) ? $dispatch->request_payload : [];
        $responseStatusCode = $responsePayload['status'] ?? null;
        $responseBody = $responsePayload['body'] ?? null;
        $status = $dispatch->status;
        $errorMessage = $dispatch->error_message;

        if ($dispatch->status === TechnicalServiceMessageDispatch::STATUS_NOT_CONFIGURED && ! filled($errorMessage)) {
            $errorMessage = 'WhatsApp webhook ayarı eksik.';
        }

        return [
            'dispatch_status' => $status,
            'dispatch_provider' => 'evolution_n8n',
            'target_phone' => $dispatch->target_phone,
            'test_mode' => (bool) $dispatch->test_mode,
            'response_status_code' => is_numeric($responseStatusCode) ? (int) $responseStatusCode : null,
            'response_body_summary' => $this->summarizeDispatchBody($responseBody),
            'error_message' => filled($errorMessage) ? $errorMessage : null,
            'public_url_warning' => $requestPayload['public_url_warning']
                ?? ($requestPayload['context']['public_url_warning'] ?? null),
        ];
    }

    /**
     * @param  mixed  $body
     */
    private function summarizeDispatchBody($body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        $summary = is_string($body)
            ? $body
            : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return mb_substr((string) $summary, 0, 500);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function dispatchUserMessage(array $summary): string
    {
        if (($summary['dispatch_status'] ?? null) === TechnicalServiceMessageDispatch::STATUS_SENT) {
            $warning = trim((string) ($summary['public_url_warning'] ?? ''));

            return 'WhatsApp onay mesajı gönderildi.'.($warning !== '' ? ' '.$warning : '');
        }

        if (($summary['dispatch_status'] ?? null) === TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_DUPLICATE) {
            return 'Bu mesaj daha önce gönderildi; tekrar WhatsApp gönderilmedi.';
        }

        if (($summary['dispatch_status'] ?? null) === TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_RATE_LIMITED) {
            return 'WhatsApp gönderimi güvenlik limiti nedeniyle bastırıldı. Biraz sonra tekrar deneyin.';
        }

        if (in_array(($summary['dispatch_status'] ?? null), [
            TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_TEST_FIXTURE,
            TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_TESTING_ENVIRONMENT,
            TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED,
        ], true)) {
            return 'Test mesajı gerçek WhatsApp’a gönderilmedi.';
        }

        $reason = trim((string) ($summary['error_message'] ?? ''));

        return 'WhatsApp mesajı gönderilemedi'.($reason !== '' ? ': '.$reason : '.');
    }

    private function normalizedPhoneForWa(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';

        if (str_starts_with($digits, '0')) {
            return '90'.substr($digits, 1);
        }

        return $digits;
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
        $workflowStatus = TechnicalServiceUiLabelService::cleanDisplayText((string) $job->workflow_status);
        $checklistStatus = TechnicalServiceUiLabelService::cleanDisplayText((string) $job->checklist_status);
        $documentStatus = TechnicalServiceUiLabelService::cleanDisplayText((string) $job->document_status);

        return in_array($workflowStatus, ['Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen'], true)
            && $checklistStatus === 'tamamlandı'
            && $this->portalPhotoReadiness($job)['ready']
            && in_array($documentStatus, ['tamamlandı', 'tamam', 'gerekli_degil'], true)
            && $this->hasCustomerConfirmation($job, []);
    }

    private function recordPredatesActiveReopen(TechnicalServiceRequest $job, mixed $recordAt): bool
    {
        return $job->reopened_at !== null
            && $recordAt instanceof \Carbon\CarbonInterface
            && $recordAt->lessThan($job->reopened_at);
    }

    private function portalDocumentPredatesActiveReopen(TechnicalServiceRequest $job, mixed $recordAt): bool
    {
        return $job->reopened_at !== null
            && $recordAt instanceof \Carbon\CarbonInterface
            && $recordAt->lessThanOrEqualTo($job->reopened_at);
    }
}
