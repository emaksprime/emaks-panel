<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Models\WarrantyCard;
use App\Models\WarrantyTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class WarrantyService
{
    public const STATUS_NOT_STARTED = 'Garanti Başlamadı';
    public const STATUS_ACTIVE = 'Garanti Aktif';
    public const STATUS_EXPIRED = 'Garanti Bitti';
    public const STATUS_REPLACEMENT_CLOSED = 'Değişimle Kapandı';
    public const STATUS_TRANSFERRED_TO_NEW_SERIAL = 'Yeni SN’ye Devredildi';
    public const STATUS_WAITING_FOR_RESALE = 'Yeniden Satış Bekliyor';

    public const DEFAULT_PERIOD_MONTHS = 24;

    public function __construct(
        private readonly MikroSerialNumberService $serialNumbers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function statusForSerial(string $serialNo): array
    {
        $serialNo = trim($serialNo);
        $warnings = [];
        $latestSale = $this->serialNumbers->latestValidSale($serialNo);

        if ($latestSale === null) {
            $localCard = $this->localCompletedInstallationCard($serialNo);

            if ($localCard instanceof WarrantyCard) {
                $localCard = $this->recalculateCard($localCard);

                return $this->response($serialNo, $localCard, null, [
                    'Mikro satış bilgisi okunamadı; garanti panelde tamamlanan montaj kaydından hesaplandı.',
                ]);
            }

            return $this->response($serialNo, null, null, [
                'Mikro’da son geçerli satış bulunamadı.',
            ]);
        }

        $card = $this->findOrCreateCardForSale($serialNo, $latestSale);
        $warnings = $this->warningsForSale($latestSale);
        $card = $this->applyCompletedInstallationRequest($card, $latestSale, $warnings);
        $card = $this->restoreInstallationFromStartEvent($card);
        $card = $this->recalculateCard($card);
        $this->appendReopenedInstallationWarning($card, $warnings);

        return $this->response($serialNo, $card, $latestSale, $warnings);
    }

    public function transferToReplacement(WarrantyCard $oldCard, string $newSerialNo, string $replacementDate, ?string $reason = null, ?int $userId = null): WarrantyTransfer
    {
        $newSerialNo = trim($newSerialNo);
        $replacement = CarbonImmutable::parse($replacementDate)->startOfDay();
        $oldEndsAt = $oldCard->warranty_ends_at?->toImmutable();
        $remainingDays = $oldEndsAt && $oldEndsAt->greaterThan($replacement)
            ? (int) $replacement->diffInDays($oldEndsAt)
            : 0;
        $newEndsAt = $remainingDays > 0 ? $replacement->addDays($remainingDays) : $replacement;

        return DB::transaction(function () use ($oldCard, $newSerialNo, $replacement, $remainingDays, $oldEndsAt, $newEndsAt, $reason, $userId) {
            $previousStatus = $oldCard->status;
            $oldCard->forceFill([
                'status' => self::STATUS_REPLACEMENT_CLOSED,
                'updated_by_user_id' => $userId,
            ])->save();

            $oldCard->events()->create([
                'event_type' => 'replacement_closed',
                'title' => 'Garanti değişimle kapandı',
                'note' => $reason,
                'from_status' => $previousStatus,
                'to_status' => self::STATUS_REPLACEMENT_CLOSED,
                'author_user_id' => $userId,
                'metadata' => ['new_serial_no' => $newSerialNo],
            ]);

            $newCard = WarrantyCard::query()->create([
                'serial_no' => $newSerialNo,
                'warranty_started_at' => $replacement->toDateString(),
                'warranty_ends_at' => $newEndsAt->toDateString(),
                'warranty_period_months' => self::DEFAULT_PERIOD_MONTHS,
                'status' => $remainingDays > 0 ? self::STATUS_ACTIVE : self::STATUS_EXPIRED,
                'source' => 'warranty_replacement',
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]);

            $newCard->events()->create([
                'event_type' => 'replacement_received',
                'title' => 'Garanti eski SN’den devredildi',
                'note' => $reason,
                'from_status' => null,
                'to_status' => $newCard->status,
                'author_user_id' => $userId,
                'metadata' => [
                    'old_serial_no' => $oldCard->serial_no,
                    'remaining_warranty_days' => $remainingDays,
                ],
            ]);

            return WarrantyTransfer::query()->create([
                'old_warranty_card_id' => $oldCard->id,
                'new_warranty_card_id' => $newCard->id,
                'old_serial_no' => $oldCard->serial_no,
                'new_serial_no' => $newSerialNo,
                'replacement_date' => $replacement->toDateString(),
                'remaining_warranty_days' => $remainingDays,
                'old_warranty_ends_at' => $oldEndsAt?->toDateString(),
                'new_warranty_started_at' => $replacement->toDateString(),
                'new_warranty_ends_at' => $newEndsAt->toDateString(),
                'reason' => $reason,
                'created_by_user_id' => $userId,
            ]);
        });
    }

    public function revokeCompletedInstallationForRequest(TechnicalServiceRequest $request, ?User $user = null): ?WarrantyCard
    {
        $serialNo = trim((string) $request->serial_number);

        if ($serialNo === '') {
            return null;
        }

        return DB::transaction(function () use ($request, $user, $serialNo): ?WarrantyCard {
            $cards = WarrantyCard::query()
                ->where('serial_no', $serialNo)
                ->whereHas('events', function ($query) use ($request) {
                    $query->where('event_type', 'warranty_started_from_completed_installation')
                        ->where('metadata->technical_service_request_id', $request->id);
                })
                ->lockForUpdate()
                ->get();

            $latestCard = null;

            foreach ($cards as $card) {
                $revokedAny = false;
                $events = $card->events()
                    ->where('event_type', 'warranty_started_from_completed_installation')
                    ->where('metadata->technical_service_request_id', $request->id)
                    ->get();

                foreach ($events as $event) {
                    $metadata = is_array($event->metadata) ? $event->metadata : [];

                    if (! empty($metadata['revoked_at'])) {
                        continue;
                    }

                    $metadata['revoked_at'] = now()->toISOString();
                    $metadata['revoked_by_user_id'] = $user?->id;
                    $metadata['revoked_reason'] = 'accidental_completion_reopen';
                    $event->forceFill(['metadata' => $metadata])->save();
                    $revokedAny = true;
                }

                if (! $revokedAny) {
                    continue;
                }

                $replacementRequest = $this->completedInstallationRequestFor(
                    $serialNo,
                    $card->last_sale_date?->toDateString(),
                );
                $replacementCompletedAt = $replacementRequest?->installation_completed_at
                    ?? $replacementRequest?->completed_at
                    ?? $replacementRequest?->updated_at;

                $card->forceFill([
                    'installation_completed_at' => $replacementCompletedAt?->toDateString(),
                    'source' => $replacementCompletedAt ? 'panel_completed_installation' : $card->source,
                    'updated_by_user_id' => $user?->id,
                ])->save();

                $card = $this->recalculateCard($card);
                $card->events()->create([
                    'event_type' => 'warranty_wrong_completion_revoked',
                    'title' => 'Yanlış kapanış garanti başlangıcı geri alındı',
                    'note' => $request->mrn,
                    'from_status' => null,
                    'to_status' => $card->status,
                    'author_user_id' => $user?->id,
                    'metadata' => [
                        'technical_service_request_id' => $request->id,
                        'mrn' => $request->mrn,
                        'serial_no' => $serialNo,
                        'replacement_request_id' => $replacementRequest?->id,
                        'replacement_mrn' => $replacementRequest?->mrn,
                        'replacement_installation_completed_at' => $replacementCompletedAt?->toDateString(),
                    ],
                ]);

                $latestCard = $card->refresh();
            }

            return $latestCard;
        });
    }

    /**
     * @param array<string, mixed> $latestSale
     */
    private function findOrCreateCardForSale(string $serialNo, array $latestSale): WarrantyCard
    {
        $fingerprint = (string) ($latestSale['fingerprint'] ?? '');

        $query = WarrantyCard::query()->where('serial_no', $serialNo);

        if ($fingerprint !== '') {
            $query->where('last_sale_mikro_fingerprint', $fingerprint);
        } else {
            $query->whereNull('last_sale_mikro_fingerprint');
        }

        $card = $query->latest('id')->first();

        if ($card) {
            return $card;
        }

        return WarrantyCard::query()->create([
            'serial_no' => $serialNo,
            'stock_code' => $this->nullableString($latestSale['stock_code'] ?? null),
            'stock_name' => $this->nullableString($latestSale['stock_name'] ?? null),
            'last_sale_date' => $this->nullableDate($latestSale['date'] ?? null),
            'last_sale_customer_code' => $this->nullableString($latestSale['customer_code'] ?? null),
            'last_sale_customer_name' => $this->nullableString($latestSale['customer_name'] ?? null),
            'last_sale_document_type' => $this->nullableString($latestSale['document_type'] ?? null),
            'last_sale_document_no' => $this->nullableString($latestSale['document_no'] ?? null),
            'last_sale_mikro_fingerprint' => $fingerprint !== '' ? $fingerprint : null,
            'warranty_period_months' => self::DEFAULT_PERIOD_MONTHS,
            'status' => self::STATUS_NOT_STARTED,
            'source' => 'mikro_latest_sale',
        ]);
    }

    private function recalculateCard(WarrantyCard $card): WarrantyCard
    {
        if (in_array($card->status, [
            self::STATUS_REPLACEMENT_CLOSED,
            self::STATUS_TRANSFERRED_TO_NEW_SERIAL,
            self::STATUS_WAITING_FOR_RESALE,
        ], true)) {
            return $card;
        }

        $startedAt = $card->installation_completed_at?->toImmutable();
        $endsAt = $startedAt?->addMonthsNoOverflow($card->warranty_period_months);
        $status = self::STATUS_NOT_STARTED;

        if ($startedAt && $endsAt) {
            $status = $endsAt->lessThan(CarbonImmutable::today()) ? self::STATUS_EXPIRED : self::STATUS_ACTIVE;
        }

        $card->forceFill([
            'warranty_started_at' => $startedAt?->toDateString(),
            'warranty_ends_at' => $endsAt?->toDateString(),
            'status' => $status,
        ])->save();

        return $card->refresh();
    }

    private function localCompletedInstallationCard(string $serialNo): ?WarrantyCard
    {
        $request = $this->completedInstallationRequestFor($serialNo, null);

        if (! $request instanceof TechnicalServiceRequest) {
            return null;
        }

        $completedAt = $request->installation_completed_at
            ?? $request->completed_at
            ?? $request->updated_at;

        if (! $completedAt) {
            return null;
        }

        $card = WarrantyCard::query()
            ->where('serial_no', $serialNo)
            ->whereNull('last_sale_mikro_fingerprint')
            ->latest('id')
            ->first();

        if (! $card instanceof WarrantyCard) {
            $card = WarrantyCard::query()->create([
                'serial_no' => $serialNo,
                'warranty_period_months' => self::DEFAULT_PERIOD_MONTHS,
                'status' => self::STATUS_NOT_STARTED,
                'source' => 'panel_completed_installation',
            ]);
        }

        if ($card->installation_completed_at === null) {
            $card->forceFill([
                'installation_completed_at' => $completedAt->toDateString(),
                'source' => 'panel_completed_installation',
            ])->save();

            if (! $this->hasActiveCompletedInstallationStartEvent($card, (int) $request->id)) {
                $card->events()->create([
                    'event_type' => 'warranty_started_from_completed_installation',
                    'title' => 'Garanti montaj tamamlanma tarihiyle başlatıldı',
                    'note' => 'Mikro satış bilgisi okunamadığı için panel tamamlanma kaydı kullanıldı.',
                    'from_status' => self::STATUS_NOT_STARTED,
                    'to_status' => null,
                    'metadata' => [
                        'technical_service_request_id' => $request->id,
                        'mrn' => $request->mrn,
                        'serial_no' => $request->serial_number,
                        'completed_at' => $completedAt->toDateString(),
                        'technical_service_completed_at' => $request->completed_at?->toDateString(),
                        'installation_completed_at' => $completedAt->toDateString(),
                        'mikro_unavailable_fallback' => true,
                    ],
                    'author_user_id' => null,
                ]);
            }
        }

        return $card->refresh();
    }

    /**
     * @param array<string, mixed> $latestSale
     * @param list<string> $warnings
     */
    private function applyCompletedInstallationRequest(WarrantyCard $card, array $latestSale, array &$warnings): WarrantyCard
    {
        if ($card->installation_completed_at !== null) {
            return $card;
        }

        $saleDate = $this->nullableDate($latestSale['date'] ?? null);
        $request = $this->completedInstallationRequestFor($card->serial_no, $saleDate);

        if (! $request) {
            return $card;
        }

        $completedAt = $request->installation_completed_at?->toImmutable();
        $usedCompletedAtFallback = false;
        $usedUpdatedAtFallback = false;

        if (! $completedAt && $request->completed_at) {
            $completedAt = $request->completed_at->toImmutable();
            $usedCompletedAtFallback = true;
        }

        if (! $completedAt && $request->status === 'Tamamlandı') {
            $completedAt = $request->updated_at?->toImmutable();
            $usedUpdatedAtFallback = $completedAt !== null;
        }

        if (! $completedAt) {
            return $card;
        }

        if ($usedCompletedAtFallback) {
            $warnings[] = 'Fiili montaj tarihi bulunamadı; eski kayıt için kapanış tarihi kullanıldı.';
        }

        if ($usedUpdatedAtFallback) {
            $warnings[] = 'Tamamlanmış montaj talebinde completed_at yok; garanti başlangıcı için updated_at kullanıldı.';
        }

        $previousStatus = $card->status;
        $card->forceFill([
            'installation_completed_at' => $completedAt->toDateString(),
            'source' => 'panel_completed_installation',
        ])->save();

        if (! $this->hasActiveCompletedInstallationStartEvent($card, (int) $request->id)) {
            $card->events()->create([
                'event_type' => 'warranty_started_from_completed_installation',
                'title' => 'Garanti montaj tamamlanma tarihiyle başlatıldı',
                'note' => null,
                'from_status' => $previousStatus,
                'to_status' => null,
                'metadata' => [
                    'technical_service_request_id' => $request->id,
                    'mrn' => $request->mrn,
                    'serial_no' => $request->serial_number,
                    'completed_at' => $completedAt->toDateString(),
                    'technical_service_completed_at' => $request->completed_at?->toDateString(),
                    'installation_completed_at' => $completedAt->toDateString(),
                    'used_completed_at_fallback' => $usedCompletedAtFallback,
                    'used_updated_at_fallback' => $usedUpdatedAtFallback,
                ],
                'author_user_id' => null,
            ]);
        }

        return $card->refresh();
    }

    private function restoreInstallationFromStartEvent(WarrantyCard $card): WarrantyCard
    {
        if ($card->installation_completed_at !== null) {
            return $card;
        }

        $event = $card->events()
            ->where('event_type', 'warranty_started_from_completed_installation')
            ->latest('id')
            ->get()
            ->first(fn ($event): bool => ! $this->warrantyStartEventIsRevoked($event));
        $completedAt = $event?->metadata['completed_at'] ?? null;

        if (! $completedAt) {
            return $card;
        }

        $card->forceFill([
            'installation_completed_at' => CarbonImmutable::parse($completedAt)->toDateString(),
            'source' => 'panel_completed_installation',
        ])->save();

        return $card->refresh();
    }

    private function hasActiveCompletedInstallationStartEvent(WarrantyCard $card, int $requestId): bool
    {
        return $card->events()
            ->where('event_type', 'warranty_started_from_completed_installation')
            ->where('metadata->technical_service_request_id', $requestId)
            ->get()
            ->contains(fn ($event): bool => ! $this->warrantyStartEventIsRevoked($event));
    }

    private function warrantyStartEventIsRevoked($event): bool
    {
        $metadata = is_array($event->metadata) ? $event->metadata : [];

        return ! empty($metadata['revoked_at']);
    }

    /**
     * @param list<string> $warnings
     */
    private function appendReopenedInstallationWarning(WarrantyCard $card, array &$warnings): void
    {
        if ($card->installation_completed_at === null) {
            return;
        }

        $hasReopenedInstallation = TechnicalServiceRequest::query()
            ->where('serial_number', $card->serial_no)
            ->where('service_type', 'Montaj')
            ->where('status', '<>', 'Tamamlandı')
            ->whereNotNull('completed_at')
            ->where(function ($query) use ($card) {
                $query->whereDate('installation_completed_at', $card->installation_completed_at->toDateString())
                    ->orWhere(function ($query) use ($card) {
                        $query->whereNull('installation_completed_at')
                            ->whereDate('completed_at', $card->installation_completed_at->toDateString());
                    });
            })
            ->exists();

        if (! $hasReopenedInstallation) {
            $hasReopenedInstallation = TechnicalServiceRequest::query()
                ->where('serial_number', $card->serial_no)
                ->where('service_type', 'Montaj')
                ->whereNotNull('reopened_at')
                ->whereNotNull('completed_at')
                ->where(function ($query) use ($card) {
                    $query->whereDate('installation_completed_at', $card->installation_completed_at->toDateString())
                        ->orWhere(function ($query) use ($card) {
                            $query->whereNull('installation_completed_at')
                                ->whereDate('completed_at', $card->installation_completed_at->toDateString());
                        });
                })
                ->exists();
        }

        if ($hasReopenedInstallation) {
            $warnings[] = 'Montaj daha önce tamamlandığı için garanti başlangıcı korunuyor; talep sonradan yeniden açılmış.';
        }
    }

    private function completedInstallationRequestFor(string $serialNo, ?string $saleDate): ?TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()
            ->where('serial_number', $serialNo)
            ->where('service_type', 'Montaj')
            ->where(function ($query) {
                $query->whereNotNull('installation_completed_at')
                    ->orWhere(function ($query) {
                        $query->where('status', 'Tamamlandı')
                            ->whereNotNull('completed_at');
                    })
                    ->orWhere(function ($query) {
                        $query->where('status', 'Tamamlandı')
                            ->whereNotNull('updated_at');
                    });
            })
            ->when($saleDate, function ($query) use ($saleDate) {
                $query->where(function ($query) use ($saleDate) {
                    $query->whereDate('installation_completed_at', '>=', $saleDate)
                        ->orWhere(function ($query) use ($saleDate) {
                            $query->whereNull('installation_completed_at')
                                ->whereDate('completed_at', '>=', $saleDate);
                        })
                        ->orWhere(function ($query) use ($saleDate) {
                            $query->whereNull('installation_completed_at')
                                ->whereNull('completed_at')
                                ->whereDate('updated_at', '>=', $saleDate);
                        });
                });
            })
            ->orderByRaw('COALESCE(installation_completed_at, completed_at, updated_at) ASC')
            ->first();
    }

    /**
     * @param array<string, mixed> $latestSale
     * @return list<string>
     */
    private function warningsForSale(array $latestSale): array
    {
        $warnings = [];

        if (! empty($latestSale['installation_signal_date'])) {
            $warnings[] = 'Mikro’da sonradan montaj sinyali var; garanti otomatik başlatılmadı.';
        }

        if (! empty($latestSale['different_customer_installation_warning'])) {
            $warnings[] = 'Farklı cari ile sonradan montaj uyarısı var.';
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed>|null $latestSale
     * @param list<string> $warnings
     * @return array<string, mixed>
     */
    private function response(string $serialNo, ?WarrantyCard $card, ?array $latestSale, array $warnings): array
    {
        $remainingDays = null;

        if ($card?->warranty_ends_at) {
            $today = CarbonImmutable::today();
            $endsAt = $card->warranty_ends_at->toImmutable();
            $remainingDays = $endsAt->greaterThan($today) ? (int) $today->diffInDays($endsAt) : 0;
        }

        return [
            'serial_no' => $serialNo,
            'status' => $card?->status ?? self::STATUS_NOT_STARTED,
            'warranty_started_at' => $card?->warranty_started_at?->toDateString(),
            'warranty_ends_at' => $card?->warranty_ends_at?->toDateString(),
            'remaining_days' => $remainingDays,
            'warranty_period_months' => $card?->warranty_period_months ?? self::DEFAULT_PERIOD_MONTHS,
            'source' => $card?->source,
            'last_sale' => $latestSale ? [
                'date' => $this->nullableDate($latestSale['date'] ?? null),
                'customer_code' => $this->nullableString($latestSale['customer_code'] ?? null),
                'customer_name' => $this->nullableString($latestSale['customer_name'] ?? null),
                'document_no' => $this->nullableString($latestSale['document_no'] ?? null),
                'fingerprint' => $this->nullableString($latestSale['fingerprint'] ?? null),
            ] : null,
            'installation' => [
                'completed_at' => $card?->installation_completed_at?->toDateString(),
                'source' => $card?->installation_completed_at ? 'panel' : null,
            ],
            'warnings' => $warnings,
            'card' => $card ? [
                'id' => $card->id,
                'serial_no' => $card->serial_no,
                'stock_code' => $card->stock_code,
                'stock_name' => $card->stock_name,
                'last_sale_date' => $card->last_sale_date?->toDateString(),
                'last_sale_customer_code' => $card->last_sale_customer_code,
                'last_sale_customer_name' => $card->last_sale_customer_name,
                'last_sale_document_type' => $card->last_sale_document_type,
                'last_sale_document_no' => $card->last_sale_document_no,
                'last_sale_mikro_fingerprint' => $card->last_sale_mikro_fingerprint,
                'installation_completed_at' => $card->installation_completed_at?->toDateString(),
                'warranty_started_at' => $card->warranty_started_at?->toDateString(),
                'warranty_ends_at' => $card->warranty_ends_at?->toDateString(),
                'warranty_period_months' => $card->warranty_period_months,
                'status' => $card->status,
                'source' => $card->source,
                'notes' => $card->notes,
            ] : null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->toDateString();
    }
}
