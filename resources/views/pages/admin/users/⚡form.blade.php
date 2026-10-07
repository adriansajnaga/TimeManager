<?php

use App\Enums\Language;
use App\Enums\Role;
use App\Livewire\Forms\UserForm;
use App\Models\User;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public UserForm $form;

    public function mount(?User $user = null): void
    {
        $this->form->setUser($user?->exists ? $user : null);
    }

    public function save(): void
    {
        $this->authorize('manage-users');

        $creating = $this->form->user === null;
        $user = $this->form->save();

        Flux::toast(variant: 'success', text: __('User saved.'));

        if ($creating) {
            $this->redirectRoute('admin.users.edit', $user, navigate: true);
        }
    }

    public function render()
    {
        return $this->view()->title($this->form->user?->name ?? __('New user'));
    }
}; ?>

<section class="w-full max-w-3xl">
    <form wire:submit="save" class="space-y-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ $form->user?->name ?? __('New user') }}</flux:heading>
                <flux:subheading>
                    <flux:link :href="route('admin.users.index')" wire:navigate>{{ __('Users') }}</flux:link>
                </flux:subheading>
            </div>

            <flux:button variant="primary" type="submit" data-test="save-user-button">{{ __('Save') }}</flux:button>
        </div>

        <flux:card class="space-y-6">
            <div class="grid gap-6 lg:grid-cols-2">
                <flux:input wire:model="form.name" :label="__('Full name (as on documents)')" required />
                <flux:input wire:model="form.email" :label="__('Email')" type="email" required />

                <flux:select wire:model.live="form.role" :label="__('Role')">
                    @foreach (Role::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($form->role === Role::Client->value)
                    <flux:select wire:model="form.contractor_id" :label="__('Client company')"
                        :description="__('The account sees only the projects, hours and weekly reports of this company — no amounts.')">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach (\App\Models\Contractor::query()->clients()->orderBy('name')->get(['id', 'name']) as $contractor)
                            <flux:select.option :value="(string) $contractor->id">{{ $contractor->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:select wire:model="form.locale" :label="__('Interface language')">
                    @foreach (Language::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="form.personnel_no" :label="__('Personnel number')" />

                <div class="self-end">
                    <flux:switch wire:model="form.is_active" :label="__('Active')" />
                </div>
            </div>
        </flux:card>

        <flux:card class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Password') }}</flux:heading>
                @if ($form->user)
                    <flux:subheading>{{ __('Leave empty to keep the current password.') }}</flux:subheading>
                @endif
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <flux:input wire:model="form.password" :label="__('Password')" type="password" viewable autocomplete="new-password" :required="$form->user === null" />
                <flux:input wire:model="form.password_confirmation" :label="__('Confirm password')" type="password" viewable autocomplete="new-password" />
            </div>
        </flux:card>

        <div class="flex justify-end gap-2">
            <flux:button :href="route('admin.users.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
