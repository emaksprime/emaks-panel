<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;

class MountFlowDecisionService
{
    public const DECISION_SHOW_FORM = 'show_form';
    public const DECISION_SHOW_PAYMENT = 'show_payment';
    public const DECISION_SHOW_MULTI_PRODUCT_FORM_WITHOUT_PAYMENT = 'show_multi_product_form_without_payment';
    public const DECISION_SHOW_INVALID_LINK = 'show_invalid_link';
    public const DECISION_SHOW_CHECK_FAILED_BUT_ALLOW_SUBMIT = 'show_check_failed_but_allow_submit';
    public const DECISION_SHOW_UNKNOWN_ERROR = 'show_unknown_error';

    /**
     * @return array{decision:string,sale_mount_status:?string,mount_payment_status:?string,customer_entry_mode:?string,decision_status:?string}
     */
    public function decide(TechnicalServiceMountSession $session): array
    {
        $link = $session->qrLink;

        if (! $link instanceof TechnicalServiceQrLink || ! $link->isActiveForOpen()) {
            return $this->decision($session, self::DECISION_SHOW_INVALID_LINK);
        }

        if ($session->customer_entry_mode === TechnicalServiceMountSession::ENTRY_MULTI_PRODUCT_WITHOUT_PAYMENT) {
            $session->forceFill([
                'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
                'decision_status' => TechnicalServiceMountSession::DECISION_FORM_OPEN,
            ])->save();

            return $this->decision($session->fresh(), self::DECISION_SHOW_MULTI_PRODUCT_FORM_WITHOUT_PAYMENT);
        }

        if (
            $session->sale_mount_status === TechnicalServiceMountSession::SALE_CHECK_FAILED
            || $session->decision_status === TechnicalServiceMountSession::DECISION_CHECK_TIMEOUT
        ) {
            return $this->decision($session, self::DECISION_SHOW_CHECK_FAILED_BUT_ALLOW_SUBMIT);
        }

        if (in_array($session->sale_mount_status, [
            TechnicalServiceMountSession::SALE_MONTAJ_DAHIL,
            TechnicalServiceMountSession::SALE_MONTAJ_SONRADAN_DAHIL,
        ], true)) {
            $session->forceFill([
                'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_NOT_REQUIRED,
                'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_INCLUDED_MOUNT,
                'decision_status' => TechnicalServiceMountSession::DECISION_FORM_OPEN,
            ])->save();

            return $this->decision($session->fresh(), self::DECISION_SHOW_FORM);
        }

        if ($session->sale_mount_status === TechnicalServiceMountSession::SALE_MONTAJ_HARIC) {
            $paymentStatus = $this->paymentStatus($session);

            if ($paymentStatus === TechnicalServiceMountSession::PAYMENT_PAID) {
                $session->forceFill([
                    'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
                    'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_PAID_SINGLE_PRODUCT,
                    'decision_status' => TechnicalServiceMountSession::DECISION_FORM_OPEN,
                ])->save();

                return $this->decision($session->fresh(), self::DECISION_SHOW_FORM);
            }

            if ($session->mount_payment_status === null) {
                $session->forceFill([
                    'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
                    'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_SINGLE_PRODUCT,
                    'decision_status' => TechnicalServiceMountSession::DECISION_READY,
                ])->save();
            }

            return $this->decision($session->fresh(), self::DECISION_SHOW_PAYMENT);
        }

        return $this->decision($session, self::DECISION_SHOW_UNKNOWN_ERROR);
    }

    private function paymentStatus(TechnicalServiceMountSession $session): ?string
    {
        if ($session->mount_payment_status !== null) {
            return $session->mount_payment_status;
        }

        $latestPayment = $session->payments()
            ->latest('id')
            ->first();

        if (! $latestPayment instanceof TechnicalServiceMountPayment) {
            return null;
        }

        return match ($latestPayment->status) {
            TechnicalServiceMountPayment::STATUS_PAID => TechnicalServiceMountSession::PAYMENT_PAID,
            TechnicalServiceMountPayment::STATUS_FAILED => TechnicalServiceMountSession::PAYMENT_FAILED,
            TechnicalServiceMountPayment::STATUS_CANCELLED => TechnicalServiceMountSession::PAYMENT_CANCELLED,
            default => TechnicalServiceMountSession::PAYMENT_PENDING,
        };
    }

    /**
     * @return array{decision:string,sale_mount_status:?string,mount_payment_status:?string,customer_entry_mode:?string,decision_status:?string}
     */
    private function decision(TechnicalServiceMountSession $session, string $decision): array
    {
        return [
            'decision' => $decision,
            'sale_mount_status' => $session->sale_mount_status,
            'mount_payment_status' => $session->mount_payment_status,
            'customer_entry_mode' => $session->customer_entry_mode,
            'decision_status' => $session->decision_status,
        ];
    }
}
