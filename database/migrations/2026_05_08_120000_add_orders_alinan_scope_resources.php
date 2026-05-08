<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([
            [
                'code' => 'orders_alinan_all',
                'name' => 'Alınan Siparişler Tümü',
                'type' => 'scope',
                'description' => 'Alınan siparişlerde tüm temsilci kapsamını görme yetkisi.',
            ],
            [
                'code' => 'orders_alinan_temsilci',
                'name' => 'Alınan Siparişler Temsilci Kapsamı',
                'type' => 'scope',
                'description' => 'Alınan siparişlerde sadece kullanıcının temsilci kodu kapsamını görme yetkisi.',
            ],
        ] as $resource) {
            DB::table('panel.resources')->updateOrInsert(
                ['code' => $resource['code']],
                [
                    'name' => $resource['name'],
                    'type' => $resource['type'],
                    'description' => $resource['description'],
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
};
