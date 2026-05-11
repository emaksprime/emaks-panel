<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('panel.users')
            ->where(function ($query): void {
                $query
                    ->where(function ($nameQuery): void {
                        $nameQuery
                            ->where(function ($firstNameQuery): void {
                                $firstNameQuery
                                    ->whereRaw("LOWER(COALESCE(full_name, '')) LIKE ?", ['%bülent%'])
                                    ->orWhereRaw("LOWER(COALESCE(full_name, '')) LIKE ?", ['%bulent%']);
                            })
                            ->where(function ($lastNameQuery): void {
                                $lastNameQuery
                                    ->whereRaw("LOWER(COALESCE(full_name, '')) LIKE ?", ['%sağlam%'])
                                    ->orWhereRaw("LOWER(COALESCE(full_name, '')) LIKE ?", ['%saglam%']);
                            });
                    })
                    ->orWhere(function ($usernameQuery): void {
                        $usernameQuery
                            ->where(function ($firstNameQuery): void {
                                $firstNameQuery
                                    ->whereRaw("LOWER(COALESCE(username, '')) LIKE ?", ['%bülent%'])
                                    ->orWhereRaw("LOWER(COALESCE(username, '')) LIKE ?", ['%bulent%']);
                            })
                            ->where(function ($lastNameQuery): void {
                                $lastNameQuery
                                    ->whereRaw("LOWER(COALESCE(username, '')) LIKE ?", ['%sağlam%'])
                                    ->orWhereRaw("LOWER(COALESCE(username, '')) LIKE ?", ['%saglam%']);
                            });
                    });
            })
            ->whereRaw("LOWER(COALESCE(full_name, '')) NOT LIKE ?", ['%salih%'])
            ->whereRaw("LOWER(COALESCE(username, '')) NOT LIKE ?", ['%salih%'])
            ->update([
                'temsilci_kodu' => '0035',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Production-safe: do not overwrite live representative codes on rollback.
    }
};
