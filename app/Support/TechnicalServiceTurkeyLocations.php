<?php

namespace App\Support;

use Illuminate\Support\Str;

class TechnicalServiceTurkeyLocations
{
    /**
     * @var array<int, array{name: string, plateCode: int, latitude: float, longitude: float, districts: array<int, string>}>
     */
    private const PROVINCES = [
        ['name' => 'Adana', 'plateCode' => 1, 'latitude' => 37.0, 'longitude' => 35.3213, 'districts' => []],
        ['name' => 'Adıyaman', 'plateCode' => 2, 'latitude' => 37.7648, 'longitude' => 38.2763, 'districts' => []],
        ['name' => 'Afyonkarahisar', 'plateCode' => 3, 'latitude' => 38.7567, 'longitude' => 30.5433, 'districts' => []],
        ['name' => 'Ağrı', 'plateCode' => 4, 'latitude' => 39.7191, 'longitude' => 43.0513, 'districts' => []],
        ['name' => 'Amasya', 'plateCode' => 5, 'latitude' => 40.6539, 'longitude' => 35.8333, 'districts' => []],
        ['name' => 'Ankara', 'plateCode' => 6, 'latitude' => 39.9334, 'longitude' => 32.8597, 'districts' => []],
        ['name' => 'Antalya', 'plateCode' => 7, 'latitude' => 36.8969, 'longitude' => 30.7133, 'districts' => []],
        ['name' => 'Artvin', 'plateCode' => 8, 'latitude' => 41.183, 'longitude' => 41.82, 'districts' => []],
        ['name' => 'Aydın', 'plateCode' => 9, 'latitude' => 37.856, 'longitude' => 27.8416, 'districts' => []],
        ['name' => 'Balıkesir', 'plateCode' => 10, 'latitude' => 39.6484, 'longitude' => 27.8826, 'districts' => []],
        ['name' => 'Bilecik', 'plateCode' => 11, 'latitude' => 40.1428, 'longitude' => 29.9793, 'districts' => []],
        ['name' => 'Bingöl', 'plateCode' => 12, 'latitude' => 38.8854, 'longitude' => 40.4989, 'districts' => []],
        ['name' => 'Bitlis', 'plateCode' => 13, 'latitude' => 38.3941, 'longitude' => 42.1232, 'districts' => []],
        ['name' => 'Bolu', 'plateCode' => 14, 'latitude' => 40.735, 'longitude' => 31.612, 'districts' => []],
        ['name' => 'Burdur', 'plateCode' => 15, 'latitude' => 37.7203, 'longitude' => 30.2908, 'districts' => []],
        ['name' => 'Bursa', 'plateCode' => 16, 'latitude' => 40.1828, 'longitude' => 29.0665, 'districts' => []],
        ['name' => 'Çanakkale', 'plateCode' => 17, 'latitude' => 40.1553, 'longitude' => 26.4142, 'districts' => []],
        ['name' => 'Çankırı', 'plateCode' => 18, 'latitude' => 40.602, 'longitude' => 33.6134, 'districts' => []],
        ['name' => 'Çorum', 'plateCode' => 19, 'latitude' => 40.5489, 'longitude' => 34.9556, 'districts' => []],
        ['name' => 'Denizli', 'plateCode' => 20, 'latitude' => 37.7765, 'longitude' => 29.0864, 'districts' => []],
        ['name' => 'Diyarbakır', 'plateCode' => 21, 'latitude' => 37.9144, 'longitude' => 40.2306, 'districts' => []],
        ['name' => 'Edirne', 'plateCode' => 22, 'latitude' => 41.6771, 'longitude' => 26.5557, 'districts' => []],
        ['name' => 'Elazığ', 'plateCode' => 23, 'latitude' => 38.6743, 'longitude' => 39.2232, 'districts' => []],
        ['name' => 'Erzincan', 'plateCode' => 24, 'latitude' => 39.75, 'longitude' => 39.49, 'districts' => []],
        ['name' => 'Erzurum', 'plateCode' => 25, 'latitude' => 39.9043, 'longitude' => 41.2679, 'districts' => []],
        ['name' => 'Eskişehir', 'plateCode' => 26, 'latitude' => 39.7767, 'longitude' => 30.5206, 'districts' => []],
        ['name' => 'Gaziantep', 'plateCode' => 27, 'latitude' => 37.0662, 'longitude' => 37.3833, 'districts' => []],
        ['name' => 'Giresun', 'plateCode' => 28, 'latitude' => 40.9128, 'longitude' => 38.3895, 'districts' => []],
        ['name' => 'Gümüşhane', 'plateCode' => 29, 'latitude' => 40.4602, 'longitude' => 39.4814, 'districts' => []],
        ['name' => 'Hakkari', 'plateCode' => 30, 'latitude' => 37.5839, 'longitude' => 43.7333, 'districts' => []],
        ['name' => 'Hatay', 'plateCode' => 31, 'latitude' => 36.2021, 'longitude' => 36.1606, 'districts' => []],
        ['name' => 'Isparta', 'plateCode' => 32, 'latitude' => 37.7648, 'longitude' => 30.5566, 'districts' => []],
        ['name' => 'Mersin', 'plateCode' => 33, 'latitude' => 36.8121, 'longitude' => 34.6415, 'districts' => []],
        ['name' => 'İstanbul', 'plateCode' => 34, 'latitude' => 41.0082, 'longitude' => 28.9784, 'districts' => []],
        ['name' => 'İzmir', 'plateCode' => 35, 'latitude' => 38.4237, 'longitude' => 27.1428, 'districts' => []],
        ['name' => 'Kars', 'plateCode' => 36, 'latitude' => 40.5989, 'longitude' => 43.0858, 'districts' => []],
        ['name' => 'Kastamonu', 'plateCode' => 37, 'latitude' => 41.3887, 'longitude' => 33.7827, 'districts' => []],
        ['name' => 'Kayseri', 'plateCode' => 38, 'latitude' => 38.7312, 'longitude' => 35.4787, 'districts' => []],
        ['name' => 'Kırklareli', 'plateCode' => 39, 'latitude' => 41.7351, 'longitude' => 27.2257, 'districts' => []],
        ['name' => 'Kırşehir', 'plateCode' => 40, 'latitude' => 39.146, 'longitude' => 34.16, 'districts' => []],
        ['name' => 'Kocaeli', 'plateCode' => 41, 'latitude' => 40.8533, 'longitude' => 29.8815, 'districts' => []],
        ['name' => 'Konya', 'plateCode' => 42, 'latitude' => 37.8746, 'longitude' => 32.4932, 'districts' => []],
        ['name' => 'Kütahya', 'plateCode' => 43, 'latitude' => 39.4208, 'longitude' => 29.9833, 'districts' => []],
        ['name' => 'Malatya', 'plateCode' => 44, 'latitude' => 38.3552, 'longitude' => 38.3095, 'districts' => []],
        ['name' => 'Manisa', 'plateCode' => 45, 'latitude' => 38.6191, 'longitude' => 27.4289, 'districts' => []],
        ['name' => 'Kahramanmaraş', 'plateCode' => 46, 'latitude' => 37.5753, 'longitude' => 36.9228, 'districts' => []],
        ['name' => 'Mardin', 'plateCode' => 47, 'latitude' => 37.3125, 'longitude' => 40.736, 'districts' => []],
        ['name' => 'Muğla', 'plateCode' => 48, 'latitude' => 37.2153, 'longitude' => 28.3636, 'districts' => []],
        ['name' => 'Muş', 'plateCode' => 49, 'latitude' => 38.9462, 'longitude' => 41.7539, 'districts' => []],
        ['name' => 'Nevşehir', 'plateCode' => 50, 'latitude' => 38.6244, 'longitude' => 34.714, 'districts' => []],
        ['name' => 'Niğde', 'plateCode' => 51, 'latitude' => 37.9662, 'longitude' => 34.6798, 'districts' => []],
        ['name' => 'Ordu', 'plateCode' => 52, 'latitude' => 40.9862, 'longitude' => 37.8797, 'districts' => []],
        ['name' => 'Rize', 'plateCode' => 53, 'latitude' => 41.0245, 'longitude' => 40.5219, 'districts' => []],
        ['name' => 'Sakarya', 'plateCode' => 54, 'latitude' => 40.7731, 'longitude' => 30.3948, 'districts' => []],
        ['name' => 'Samsun', 'plateCode' => 55, 'latitude' => 41.2867, 'longitude' => 36.33, 'districts' => []],
        ['name' => 'Siirt', 'plateCode' => 56, 'latitude' => 37.9333, 'longitude' => 41.95, 'districts' => []],
        ['name' => 'Sinop', 'plateCode' => 57, 'latitude' => 42.0268, 'longitude' => 35.1551, 'districts' => []],
        ['name' => 'Sivas', 'plateCode' => 58, 'latitude' => 39.7477, 'longitude' => 37.0179, 'districts' => []],
        ['name' => 'Tekirdağ', 'plateCode' => 59, 'latitude' => 40.978, 'longitude' => 27.511, 'districts' => []],
        ['name' => 'Tokat', 'plateCode' => 60, 'latitude' => 40.3167, 'longitude' => 36.55, 'districts' => []],
        ['name' => 'Trabzon', 'plateCode' => 61, 'latitude' => 41.0015, 'longitude' => 39.7178, 'districts' => []],
        ['name' => 'Tunceli', 'plateCode' => 62, 'latitude' => 39.1061, 'longitude' => 39.5486, 'districts' => []],
        ['name' => 'Şanlıurfa', 'plateCode' => 63, 'latitude' => 37.1674, 'longitude' => 38.7955, 'districts' => []],
        ['name' => 'Uşak', 'plateCode' => 64, 'latitude' => 38.6743, 'longitude' => 29.4058, 'districts' => []],
        ['name' => 'Van', 'plateCode' => 65, 'latitude' => 38.4891, 'longitude' => 43.4089, 'districts' => []],
        ['name' => 'Yozgat', 'plateCode' => 66, 'latitude' => 39.8197, 'longitude' => 34.8147, 'districts' => []],
        ['name' => 'Zonguldak', 'plateCode' => 67, 'latitude' => 41.4564, 'longitude' => 31.7987, 'districts' => []],
        ['name' => 'Aksaray', 'plateCode' => 68, 'latitude' => 38.3687, 'longitude' => 34.037, 'districts' => []],
        ['name' => 'Bayburt', 'plateCode' => 69, 'latitude' => 40.2552, 'longitude' => 40.2249, 'districts' => []],
        ['name' => 'Karaman', 'plateCode' => 70, 'latitude' => 37.1759, 'longitude' => 33.2287, 'districts' => []],
        ['name' => 'Kırıkkale', 'plateCode' => 71, 'latitude' => 39.8468, 'longitude' => 33.5153, 'districts' => []],
        ['name' => 'Batman', 'plateCode' => 72, 'latitude' => 37.8812, 'longitude' => 41.1351, 'districts' => []],
        ['name' => 'Şırnak', 'plateCode' => 73, 'latitude' => 37.4187, 'longitude' => 42.4918, 'districts' => []],
        ['name' => 'Bartın', 'plateCode' => 74, 'latitude' => 41.5811, 'longitude' => 32.461, 'districts' => []],
        ['name' => 'Ardahan', 'plateCode' => 75, 'latitude' => 41.1105, 'longitude' => 42.7022, 'districts' => []],
        ['name' => 'Iğdır', 'plateCode' => 76, 'latitude' => 39.888, 'longitude' => 44.004, 'districts' => []],
        ['name' => 'Yalova', 'plateCode' => 77, 'latitude' => 40.655, 'longitude' => 29.276, 'districts' => []],
        ['name' => 'Karabük', 'plateCode' => 78, 'latitude' => 41.2061, 'longitude' => 32.6204, 'districts' => []],
        ['name' => 'Kilis', 'plateCode' => 79, 'latitude' => 36.7184, 'longitude' => 37.1212, 'districts' => []],
        ['name' => 'Osmaniye', 'plateCode' => 80, 'latitude' => 37.213, 'longitude' => 36.176, 'districts' => []],
        ['name' => 'Düzce', 'plateCode' => 81, 'latitude' => 40.8438, 'longitude' => 31.1565, 'districts' => []],
    ];

    /**
     * @return array<int, array{name: string, normalizedName: string, plateCode: int, latitude: float, longitude: float, districts: array<int, array{name: string, normalizedName: string}>}>
     */
    public static function provinces(): array
    {
        return array_map(static function (array $province): array {
            $province['normalizedName'] = self::normalizeLocationText($province['name']);
            $province['districts'] = array_map(static function (string $district): array {
                return [
                    'name' => $district,
                    'normalizedName' => self::normalizeLocationText($district),
                ];
            }, $province['districts']);

            return $province;
        }, self::PROVINCES);
    }

    public static function normalizeLocationText(?string $value): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return '';
        }

        $text = strtr($text, [
            'ç' => 'c',
            'Ç' => 'c',
            'ğ' => 'g',
            'Ğ' => 'g',
            'ı' => 'i',
            'I' => 'i',
            'İ' => 'i',
            'i' => 'i',
            'ö' => 'o',
            'Ö' => 'o',
            'ş' => 's',
            'Ş' => 's',
            'ü' => 'u',
            'Ü' => 'u',
        ]);

        return Str::of($text)
            ->lower()
            ->replaceMatches('/\p{Mn}+/u', '')
            ->replaceMatches('/[^a-z0-9]+/u', '')
            ->toString();
    }

    public static function findProvinceByName(?string $value): ?array
    {
        $normalized = self::normalizeLocationText($value);

        if ($normalized === '') {
            return null;
        }

        foreach (self::provinces() as $province) {
            if ($province['normalizedName'] === $normalized) {
                return $province;
            }
        }

        return null;
    }

    public static function standardizeProvinceName(?string $value): ?string
    {
        return self::findProvinceByName($value)['name'] ?? null;
    }

    public static function getDistrictsForProvince(?string $provinceName): array
    {
        return self::findProvinceByName($provinceName)['districts'] ?? [];
    }

    public static function findDistrictByName(?string $provinceName, ?string $districtName): ?array
    {
        $normalizedDistrict = self::normalizeLocationText($districtName);

        if ($normalizedDistrict === '') {
            return null;
        }

        foreach (self::getDistrictsForProvince($provinceName) as $district) {
            if ($district['normalizedName'] === $normalizedDistrict) {
                return $district;
            }
        }

        return null;
    }

    public static function standardizeDistrictName(?string $provinceName, ?string $districtName): ?string
    {
        return self::findDistrictByName($provinceName, $districtName)['name'] ?? null;
    }

    public static function haversineKm(?float $fromLat, ?float $fromLng, ?float $toLat, ?float $toLng): ?float
    {
        if ($fromLat === null || $fromLng === null || $toLat === null || $toLng === null) {
            return null;
        }

        $earthKm = 6371;
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return round($earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }

    public static function provinceDistanceKm(?string $fromProvinceName, ?string $toProvinceName): ?float
    {
        $fromProvince = self::findProvinceByName($fromProvinceName);
        $toProvince = self::findProvinceByName($toProvinceName);

        if (! $fromProvince || ! $toProvince) {
            return null;
        }

        return self::haversineKm(
            (float) $fromProvince['latitude'],
            (float) $fromProvince['longitude'],
            (float) $toProvince['latitude'],
            (float) $toProvince['longitude'],
        );
    }
}

