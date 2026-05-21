<?php

namespace App\Http\Controllers;

use App\Models\TechnicalServiceCustomerConfirmation;
use App\Models\TechnicalServicePartnerJobAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ServiceJobConfirmationController extends Controller
{
    public function show(string $token): Response
    {
        $confirmation = $this->confirmation($token);
        $job = $confirmation->request;
        $approved = $confirmation->status === TechnicalServiceCustomerConfirmation::STATUS_APPROVED;

        return response($this->html($confirmation, $approved ? 'approved' : 'pending'));
    }

    public function approve(Request $request, string $token): Response
    {
        $confirmation = $this->confirmation($token);
        $note = trim((string) $request->input('customer_note', ''));

        if ($confirmation->status !== TechnicalServiceCustomerConfirmation::STATUS_APPROVED) {
            $confirmation->forceFill([
                'status' => TechnicalServiceCustomerConfirmation::STATUS_APPROVED,
                'approved_at' => now(),
                'customer_note' => $note !== '' ? $note : null,
                'payload' => [
                    ...(is_array($confirmation->payload) ? $confirmation->payload : []),
                    'approved_from' => 'public_confirmation_link',
                    'approved_at' => now()->toIso8601String(),
                ],
            ])->save();

            $job = $confirmation->request;
            $job->forceFill([
                'customer_closure_approval_status' => 'onaylandı',
                'customer_closure_approval_method' => 'customer_link',
                'customer_closure_approved_at' => now(),
                'customer_closure_approval_code' => substr($confirmation->token, 0, 12),
            ])->save();

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
                    ],
                ])->save();

                TechnicalServicePartnerJobAction::query()->create([
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
        $title = $approved ? 'Montaj onayınız alındı' : 'Montaj onayı';
        $button = $approved
            ? '<div class="success">Montajı onayladınız. Teşekkür ederiz.</div>'
            : '<form method="POST"><input type="hidden" name="_token" value="'.e(csrf_token()).'"><label>Müşteri notu <textarea name="customer_note" rows="3"></textarea></label><button type="submit">Montajı onaylıyorum</button></form>';

        return '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            .e($title).
            '</title><style>body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f3f7fb;color:#0f172a}.wrap{max-width:680px;margin:40px auto;padding:24px}.card{background:white;border:1px solid #dbe4ef;border-radius:24px;padding:24px;box-shadow:0 24px 70px rgba(15,23,42,.08)}h1{font-size:28px;margin:0 0 12px}.muted{color:#64748b}.grid{display:grid;gap:12px;margin:20px 0}.row{border:1px solid #e2e8f0;border-radius:16px;padding:12px;background:#f8fafc}label{display:grid;gap:8px;font-weight:600}textarea{border:1px solid #cbd5e1;border-radius:12px;padding:10px;font:inherit}button{margin-top:14px;border:0;border-radius:14px;background:#06143a;color:white;font-weight:700;padding:12px 16px}.success{border:1px solid #a7f3d0;background:#ecfdf5;color:#047857;border-radius:16px;padding:14px;font-weight:700}</style></head><body><main class="wrap"><section class="card"><h1>'
            .e($title).
            '</h1><p class="muted">Montaj işleminizin tamamlandığını onaylamak için bilgileri kontrol edin.</p><div class="grid"><div class="row"><strong>MRN</strong><br>'
            .e((string) $job->mrn).
            '</div><div class="row"><strong>Müşteri</strong><br>'
            .e((string) $job->customer_name).
            '</div><div class="row"><strong>Ürün / Model</strong><br>'
            .e(trim((string) $job->product_name.' '.(string) $job->product_model)).
            '</div></div>'.$button.'</section></main></body></html>';
    }
}
