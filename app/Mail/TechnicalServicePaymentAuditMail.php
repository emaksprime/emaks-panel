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
        $record = trim((string) ($this->details['srv'] ?? $this->details['mrn'] ?? ''));
        $series = trim((string) ($this->details['desired_series'] ?? 'S'));

        return $this
            ->subject(sprintf(
                '[SANDBOX][%s] Ödeme alındı%s · Mikro test sipariş simülasyonu',
                $series !== '' ? $series : 'S',
                $record !== '' ? ' · '.$record : '',
            ))
            ->view('mail.technical-service.payment-audit')
            ->with([
                'details' => $this->details,
                'payment' => $this->payment,
            ]);
    }
}
