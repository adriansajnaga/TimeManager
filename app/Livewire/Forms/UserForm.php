<?php

namespace App\Livewire\Forms;

use App\Concerns\PasswordValidationRules;
use App\Enums\Language;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Form;

class UserForm extends Form
{
    use PasswordValidationRules;

    public ?User $user = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'employee';

    public string $locale = 'pl';

    public bool $is_active = true;

    public string $personnel_no = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function setUser(?User $user): void
    {
        $this->user = $user;

        if ($user === null) {
            return;
        }

        $this->fill([
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'locale' => $user->locale->value,
            'is_active' => $user->is_active,
            'personnel_no' => (string) $user->personnel_no,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $editingSelf = $this->user !== null && $this->user->is(Auth::user());

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique(User::class)->ignore($this->user?->id),
            ],
            // Administrator nie może odebrać sobie roli ani zablokować własnego konta.
            'role' => ['required', Rule::enum(Role::class), $editingSelf ? Rule::in([Role::Admin->value]) : 'nullable'],
            'locale' => ['required', Rule::enum(Language::class)],
            'is_active' => ['boolean', $editingSelf ? 'accepted' : 'nullable'],
            'personnel_no' => ['nullable', 'string', 'max:30'],
            // Przy edycji puste hasło = bez zmiany.
            'password' => $this->user === null
                ? $this->passwordRules()
                : ['nullable', 'string', Password::default(), 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'role.in' => __('You cannot remove your own administrator role.'),
            'is_active.accepted' => __('You cannot deactivate your own account.'),
        ];
    }

    public function save(): User
    {
        $this->validate();

        $user = $this->user ?? new User;

        $user->fill([
            'name' => trim($this->name),
            'email' => $this->email,
            'role' => $this->role,
            'locale' => $this->locale,
            'is_active' => $this->is_active,
            'personnel_no' => trim($this->personnel_no) === '' ? null : trim($this->personnel_no),
        ]);

        if ($this->password !== '') {
            $user->password = $this->password;
        }

        // Konto zakłada administrator, więc adres uznajemy za potwierdzony.
        if (! $user->exists) {
            $user->email_verified_at = now();
        }

        $user->save();

        $this->reset('password', 'password_confirmation');

        return $this->user = $user;
    }
}
