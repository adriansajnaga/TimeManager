<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\User;
use App\Support\Registration;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * Rejestracja działa tylko dla pierwszego konta, które zostaje administratorem.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        abort_unless(Registration::isOpen(), 403);

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'role' => Role::Admin,
            'locale' => app()->getLocale(),
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
