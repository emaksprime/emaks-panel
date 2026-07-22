<?php

namespace App\Mail;

use App\Models\TechnicalServiceMountPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TechnicalServicePaymentAuditMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly TechnicalServiceMountPayment $payment,
        public readonly array $details,
    ) {}

    public function build(): self
    {
        $mrn = trim((string) ($this->details['mrn'] ?? ''));

        return $this
            ->subject('EMAKS Teknik Servis Ödeme Bildirimi'.($mrn !== '' ? ' - '.$mrn : ''))
            ->view('mail.technical-service.payment-audit')
            ->with([
                'details' => $this->details,
                'payment' => $this->payment,
            ]);
    }
}
