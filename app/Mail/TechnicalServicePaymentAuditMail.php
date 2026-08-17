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
        $mountPayment = (bool) ($this->details['mount_payment'] ?? false);
        $record = trim((string) ($mountPayment
            ? ($this->details['mrn'] ?? $this->details['srv'] ?? '')
            : ($this->details['srv'] ?? $this->details['mrn'] ?? '')));
        $series = trim((string) ($this->details['desired_series'] ?? 'S'));
        $subject = $mountPayment
            ? sprintf(
                '[SANDBOX][%s] Montaj ödemesi alındı%s',
                $series !== '' ? $series : 'S',
                $record !== '' ? ' · '.$record : '',
            )
            : sprintf(
                '[SANDBOX][%s] Ödeme alındı%s · Mikro test sipariş simülasyonu',
                $series !== '' ? $series : 'S',
                $record !== '' ? ' · '.$record : '',
            );

        return $this
            ->subject($subject)
            ->view('mail.technical-service.payment-audit')
            ->with([
                'details' => $this->details,
                'payment' => $this->payment,
            ]);
    }
}
