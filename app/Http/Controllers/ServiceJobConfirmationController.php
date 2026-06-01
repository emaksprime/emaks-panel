<?php

namespace App\Http\Controllers;

use App\Models\TechnicalServiceCustomerConfirmation;
use App\Models\TechnicalServicePartnerJobAction;
use App\Services\Messaging\EvolutionWhatsAppMessageService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ServiceJobConfirmationController extends Controller
{
    public function __construct(
        private readonly EvolutionWhatsAppMessageService $messages,
    ) {}

    public function show(string $token): Response
    {
        $confirmation = $this->confirmation($token);

        $state = match ($confirmation->status) {
            TechnicalServiceCustomerConfirmation::STATUS_APPROVED => 'approved',
            TechnicalServiceCustomerConfirmation::STATUS_REJECTED => 'rejected',
            default => 'pending',
        };

        return response($this->html($confirmation, $state));
    }

    public function approve(Request $request, string $token): Response
    {
        $confirmation = $this->confirmation($token);
        $note = trim((string) $request->input('customer_note', ''));

        if ($confirmation->status !== TechnicalServiceCustomerConfirmation::STATUS_APPROVED) {
            $confirmation->forceFill([
                'status' => TechnicalServiceCustomerConfirmation::STATUS_APPROVED,
                'approved_at' => now(),
                'rejected_at' => null,
                'customer_note' => $note !== '' ? $note : null,
                'payload' => [
                    ...(is_array($confirmation->payload) ? $confirmation->payload : []),
                    'approved_from' => 'public_confirmation_link',
                    'approved_at' => now()->toIso8601String(),
                ],
            ])->save();

            $job = $confirmation->request;
            $pendingCompletion = $job->partnerJobActions()
                ->where('action', TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED)
                ->where('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
                ->latest()
                ->first();
            $jobPayload = [
                'customer_closure_approval_status' => 'onaylandı',
                'customer_closure_approval_method' => 'customer_link',
                'customer_closure_approved_at' => now(),
                'customer_closure_approval_code' => substr($confirmation->token, 0, 12),
            ];

            if ($pendingCompletion instanceof TechnicalServicePartnerJobAction) {
                $jobPayload['workflow_status'] = 'Müşteri Kapanış Onayı Bekleyen';
                $jobPayload['status'] = 'Son Kontrol';
                $jobPayload['completed_at'] = null;
                $jobPayload['technician_completed_at'] = null;
            }

            $job->forceFill($jobPayload)->save();

            $payload = is_array($confirmation->payload) ? $confirmation->payload : [];
            $sourceAction = isset($payload['partner_action_id'])
                ? TechnicalServicePartnerJobAction::query()->find($payload['partner_action_id'])
                : null;

            if ($sourceAction instanceof TechnicalServicePartnerJobAction) {
                $sourcePayload = is_array($sourceAction->payload) ? $sourceAction->payload : [];
                $sourceAction->forceFill([
                    'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
                    'payload' => [
                        ...$sourcePayload,
                        'confirmation_status' => TechnicalServiceCustomerConfirmation::STATUS_APPROVED,
                        'customer_approved_at' => now()->toIso8601String(),
                        'ops_final_check_required' => $pendingCompletion instanceof TechnicalServicePartnerJobAction,
                    ],
                ])->save();

                $approvalAction = TechnicalServicePartnerJobAction::query()->create([
                    'technical_service_request_id' => $job->id,
                    'partner_id' => $sourceAction->partner_id,
                    'user_id' => $sourceAction->user_id,
                    'technical_service_technician_id' => $sourceAction->technical_service_technician_id,
                    'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_CONFIRMED,
                    'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
                    'payload' => [
                        'confirmation_id' => $confirmation->id,
                        'customer_note' => $confirmation->customer_note,
                    ],
                    'note' => 'Müşteri montajı onayladı.',
                ]);

                $this->messages->send(
                    'customer_installation_approved_ops',
                    'ops',
                    null,
                    "Müşteri montajı onayladı. MRN: {$job->mrn}.",
                    ['confirmation_id' => $confirmation->id],
                    $job,
                    null,
                    $approvalAction,
                );
            }

            $job->events()->create([
                'event_type' => 'customer_installation_approved',
                'title' => 'Müşteri montajı onayladı',
                'note' => $note !== '' ? $note : null,
                'from_status' => $job->workflow_status,
                'to_status' => $job->workflow_status,
                'author_user_id' => null,
                'metadata' => [
                    'confirmation_id' => $confirmation->id,
                    'source' => 'public_confirmation_link',
                ],
            ]);
        }

        return response($this->html($confirmation->refresh(), 'approved'));
    }

    public function reject(Request $request, string $token): Response
    {
        $confirmation = $this->confirmation($token);
        $data = $request->validate([
            'customer_note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        if ($confirmation->status !== TechnicalServiceCustomerConfirmation::STATUS_REJECTED) {
            $confirmation->forceFill([
                'status' => TechnicalServiceCustomerConfirmation::STATUS_REJECTED,
                'approved_at' => null,
                'rejected_at' => now(),
                'customer_note' => $data['customer_note'],
                'payload' => [
                    ...(is_array($confirmation->payload) ? $confirmation->payload : []),
                    'rejected_from' => 'public_confirmation_link',
                    'rejected_at' => now()->toIso8601String(),
                ],
            ])->save();

            $job = $confirmation->request;
            $job->forceFill([
                'customer_closure_approval_status' => 'reddedildi',
                'customer_closure_approval_method' => 'customer_link',
                'customer_closure_approved_at' => null,
                'completion_block_reason' => 'Müşteri montaj onayını reddetti.',
            ])->save();

            $payload = is_array($confirmation->payload) ? $confirmation->payload : [];
            $sourceAction = isset($payload['partner_action_id'])
                ? TechnicalServicePartnerJobAction::query()->find($payload['partner_action_id'])
                : null;

            if ($sourceAction instanceof TechnicalServicePartnerJobAction) {
                $sourcePayload = is_array($sourceAction->payload) ? $sourceAction->payload : [];
                $sourceAction->forceFill([
                    'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
                    'payload' => [
                        ...$sourcePayload,
                        'confirmation_status' => TechnicalServiceCustomerConfirmation::STATUS_REJECTED,
                        'customer_rejected_at' => now()->toIso8601String(),
                        'customer_rejection_note' => $data['customer_note'],
                    ],
                ])->save();

                $rejectedAction = TechnicalServicePartnerJobAction::query()->create([
                    'technical_service_request_id' => $job->id,
                    'partner_id' => $sourceAction->partner_id,
                    'user_id' => $sourceAction->user_id,
                    'technical_service_technician_id' => $sourceAction->technical_service_technician_id,
                    'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED,
                    'status' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
                    'payload' => [
                        'confirmation_id' => $confirmation->id,
                        'customer_note' => $data['customer_note'],
                        'ops_review_required' => true,
                    ],
                    'note' => $data['customer_note'],
                ]);

                $this->messages->send(
                    'customer_installation_rejected_ops',
                    'ops',
                    null,
                    "Müşteri montaj onayını reddetti. MRN: {$job->mrn}. Açıklama: {$data['customer_note']}",
                    ['confirmation_id' => $confirmation->id],
                    $job,
                    null,
                    $rejectedAction,
                );
            }

            $job->events()->create([
                'event_type' => 'customer_installation_rejected',
                'title' => 'Müşteri montaj onayını reddetti',
                'note' => $data['customer_note'],
                'from_status' => $job->workflow_status,
                'to_status' => $job->workflow_status,
                'author_user_id' => null,
                'metadata' => [
                    'confirmation_id' => $confirmation->id,
                    'source' => 'public_confirmation_link',
                ],
            ]);
        }

        return response($this->html($confirmation->refresh(), 'rejected'));
    }

    private function confirmation(string $token): TechnicalServiceCustomerConfirmation
    {
        return TechnicalServiceCustomerConfirmation::query()
            ->with('request')
            ->where('token', $token)
            ->firstOrFail();
    }

    private function html(TechnicalServiceCustomerConfirmation $confirmation, string $state): string
    {
        $job = $confirmation->request;
        $approved = $state === 'approved';
        $rejected = $state === 'rejected';
        $title = $approved
            ? 'Montaj onayınız alındı'
            : ($rejected ? 'Montaj onayı reddedildi' : 'Montaj onayı');
        $approvalText = (string) config('services.evolution.customer_approval_text');
        $legalNote = (string) config('services.evolution.customer_approval_legal_note');
        $approveAction = route('service-job-confirmation.approve', ['token' => $confirmation->token], false);
        $rejectAction = route('service-job-confirmation.reject', ['token' => $confirmation->token], false);
        $button = match (true) {
            $approved => '<div class="success"><strong>Montaj onayınız alınmıştır.</strong><br>Teşekkür ederiz. Operasyon ekibi süreci kontrol edecektir.</div>',
            $rejected => '<div class="danger"><strong>Geri bildiriminiz alınmıştır.</strong><br>Operasyon ekibimiz sizinle iletişime geçecektir.</div>',
            default => '<div class="notice">'.e($approvalText).'</div><div class="actions"><form method="POST" action="'.e($approveAction).'"><input type="hidden" name="_token" value="'.e(csrf_token()).'"><button type="submit">Onaylıyorum</button></form><button type="button" class="danger-button" id="reject-open">Onaylamıyorum</button></div><dialog id="reject-dialog"><form method="dialog" class="dialog-head"><strong>Onaylamama açıklaması</strong><button type="submit" aria-label="Kapat">×</button></form><form method="POST" action="'.e($rejectAction).'"><input type="hidden" name="_token" value="'.e(csrf_token()).'"><label>Açıklama zorunlu <textarea required name="customer_note" rows="4" minlength="3"></textarea></label><button class="danger-button" type="submit">Geri bildirimi gönder</button></form></dialog>',
        };

        return '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            .e($title).
            '</title><style>*,*::before,*::after{box-sizing:border-box}html,body{max-width:100%;overflow-x:hidden}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f3f7fb;color:#0f172a}.wrap{width:100%;max-width:680px;margin:40px auto;padding:24px;overflow-x:hidden}.card{min-width:0;max-width:100%;overflow:hidden;background:white;border:1px solid #dbe4ef;border-radius:24px;padding:24px;box-shadow:0 24px 70px rgba(15,23,42,.08)}h1{font-size:28px;margin:0 0 12px;overflow-wrap:anywhere}.muted{color:#64748b;overflow-wrap:anywhere}.grid{display:grid;min-width:0;gap:12px;margin:20px 0}.row{min-width:0;max-width:100%;overflow-wrap:anywhere;border:1px solid #e2e8f0;border-radius:16px;padding:12px;background:#f8fafc}.notice{min-width:0;max-width:100%;overflow-wrap:anywhere;border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:16px;padding:14px;font-weight:650}.actions{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px;margin-top:16px}.actions form{min-width:0;margin:0}label{display:grid;min-width:0;gap:8px;font-weight:600;overflow-wrap:anywhere}textarea{width:100%;max-width:100%;border:1px solid #cbd5e1;border-radius:12px;padding:10px;font:inherit;min-width:0;resize:vertical}button{width:100%;max-width:100%;white-space:normal;overflow-wrap:anywhere;border:0;border-radius:14px;background:#06143a;color:white;font-weight:700;padding:13px 16px;cursor:pointer}.danger-button{background:#be123c}.success{min-width:0;max-width:100%;overflow-wrap:anywhere;border:1px solid #a7f3d0;background:#ecfdf5;color:#047857;border-radius:16px;padding:14px;font-weight:700}.danger{min-width:0;max-width:100%;overflow-wrap:anywhere;border:1px solid #fecdd3;background:#fff1f2;color:#be123c;border-radius:16px;padding:14px;font-weight:700}.legal{margin-top:16px;color:#64748b;font-size:12px;overflow-wrap:anywhere}dialog{width:min(92vw,440px);max-width:calc(100vw - 32px);border:0;border-radius:24px;padding:20px;box-shadow:0 24px 70px rgba(15,23,42,.22);overflow-x:hidden}dialog::backdrop{background:rgba(15,23,42,.45)}.dialog-head{display:flex;min-width:0;align-items:center;justify-content:space-between;gap:12px;margin:0 0 14px}.dialog-head strong{min-width:0;overflow-wrap:anywhere}.dialog-head button{flex:0 0 auto;width:40px;height:40px;padding:0;border-radius:999px;background:#f1f5f9;color:#0f172a}@media(max-width:520px){.wrap{margin:0;padding:16px}.card{border-radius:20px;padding:18px}.actions{grid-template-columns:1fr}h1{font-size:24px}dialog{width:calc(100vw - 32px);padding:16px}}</style></head><body><main class="wrap"><section class="card"><h1>'
            .e($title).
            '</h1><p class="muted">'.($approved || $rejected ? 'Bu sayfayı kapatabilirsiniz.' : 'Montaj işleminizin tamamlandığını onaylamak için bilgileri kontrol edin.').'</p><div class="grid"><div class="row"><strong>MRN</strong><br>'
            .e((string) $job->mrn).
            '</div><div class="row"><strong>Müşteri</strong><br>'
            .e((string) $job->customer_name).
            '</div><div class="row"><strong>Ürün / Model</strong><br>'
            .e(trim((string) $job->product_name.' '.(string) $job->product_model)).
            '</div></div>'.$button.'<p class="legal">'.e($legalNote).'</p></section></main><script>const rejectOpen=document.getElementById("reject-open");const rejectDialog=document.getElementById("reject-dialog");if(rejectOpen&&rejectDialog){rejectOpen.addEventListener("click",()=>rejectDialog.showModal());}</script></body></html>';
    }
}
