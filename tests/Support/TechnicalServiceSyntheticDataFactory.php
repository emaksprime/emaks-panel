<?php

namespace Tests\Support;

final class TechnicalServiceSyntheticDataFactory
{
    public const MARKER = 'synthetic';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function locksmith(int $sequence = 1, array $overrides = []): array
    {
        $suffix = str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
        $phone = '+9000000'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
        $city = 'Sentetik Şehir '.$suffix;

        return array_replace([
            'synthetic' => true,
            'source_key' => 'locksmith:'.$phone.':SENTETIK SEHIR '.$suffix,
            'name' => 'Sentetik Çilingir Örnek '.$suffix,
            'first_name' => 'Sentetik',
            'last_name' => 'Çilingir Örnek '.$suffix,
            'technician_type' => 'locksmith',
            'city_plate_code' => '00',
            'priority' => $sequence,
            'phone' => $phone,
            'phone_e164' => $phone,
            'phone_display' => $phone,
            'city' => $city,
            'address' => 'SENTETİK TEST ADRESİ '.$suffix,
            'location_code' => 'SYNTH-LOCATION-'.$suffix,
            'cari_code' => 'SYNTH-CARI-'.$suffix,
            'cari_title' => 'Synthetic Cari '.$suffix,
            'cari_address' => 'SENTETİK TEST ADRESİ '.$suffix,
            'cari_city_district_country' => $city.' / SYNTHETIC',
            'display_name' => 'Synthetic Locksmith '.$suffix,
            'import_status' => 'SYNTHETIC VALID',
            'import_note' => null,
            'needs_review' => false,
            'active' => true,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function coordinate(int $sequence = 1, array $overrides = []): array
    {
        $locksmith = self::locksmith($sequence);

        return array_replace([
            'synthetic' => true,
            'source_key' => $locksmith['source_key'],
            'phone_e164' => $locksmith['phone_e164'],
            'city' => $locksmith['city'],
            'name' => $locksmith['name'],
            'latitude' => 39.0 + ($sequence / 1000000),
            'longitude' => 35.0 + ($sequence / 1000000),
            'start_latitude' => 39.0 + ($sequence / 1000000),
            'start_longitude' => 35.0 + ($sequence / 1000000),
            'location_source' => 'synthetic_fixture',
            'route_note' => 'Synthetic coordinate fixture.',
            'needs_review' => false,
        ], $overrides);
    }

    /**
     * @return array<int, string>
     */
    public static function headers(): array
    {
        return [
            'Plaka Kodu',
            'Şehir',
            'Öncelik',
            'İsim Soyisim',
            'Telefon (90 format)',
            'Telefon (okunur)',
            'Konum / Adres Kodu',
            'Cari Kodu',
            'Cari Ünvan',
            'Cari Adres',
            'Cari İlçe İl Ülke',
            'Cari ADI',
            'Durum',
            'Kontrol Notu',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<int, mixed>
     */
    public static function xlsxRow(int $sequence = 1, array $overrides = []): array
    {
        $record = self::locksmith($sequence, $overrides);

        return [
            $record['city_plate_code'],
            $record['city'],
            $record['priority'],
            $record['name'],
            ltrim((string) $record['phone_e164'], '+'),
            $record['phone_display'],
            $record['location_code'],
            $record['cari_code'],
            $record['cari_title'],
            $record['cari_address'],
            $record['cari_city_district_country'],
            $record['display_name'],
            $record['import_status'],
            $record['import_note'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function dataset(array $items): array
    {
        return [
            'synthetic' => true,
            'schema_version' => 1,
            'items' => $items,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function duplicateIdentity(int $sequence = 1): array
    {
        $record = self::locksmith($sequence);

        return [$record, $record];
    }

    /** @return array<string, mixed> */
    public static function missingCoordinate(int $sequence = 1): array
    {
        return self::coordinate($sequence, [
            'latitude' => null,
            'longitude' => null,
            'start_latitude' => null,
            'start_longitude' => null,
        ]);
    }

    /** @return array<string, mixed> */
    public static function invalidCoordinate(int $sequence = 1): array
    {
        return self::coordinate($sequence, [
            'latitude' => 0.0,
            'longitude' => 0.0,
            'start_latitude' => 0.0,
            'start_longitude' => 0.0,
        ]);
    }

    /** @return array<string, mixed> */
    public static function blankFields(int $sequence = 1): array
    {
        return self::locksmith($sequence, [
            'cari_title' => '',
            'cari_address' => null,
            'import_note' => null,
        ]);
    }
}
