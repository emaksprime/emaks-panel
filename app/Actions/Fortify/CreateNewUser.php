<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'username' => ['required', 'string', 'max:255', Rule::unique(User::class, 'username')],
            'full_name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ])->validate();

        $role = Role::query()
            ->where('code', config('panel.default_registration_role'))
            ->first();

        return User::create([
            'username' => $input['username'],
            'full_name' => $input['full_name'],
            'password' => $input['password'],
            'role_code' => $role?->code,
            'aktif' => true,
        ]);
    }
}
