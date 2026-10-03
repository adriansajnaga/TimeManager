<?php

use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Users')] class extends Component {
    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::query()->withCount('projects')->orderByDesc('is_active')->orderBy('name')->get();
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>
            <flux:subheading>{{ __('Accounts, roles and interface language.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('admin.users.create')" wire:navigate>
            {{ __('New user') }}
        </flux:button>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Role') }}</flux:table.column>
            <flux:table.column>{{ __('Language') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Projects') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->users as $user)
                <flux:table.row :key="$user->id">
                    <flux:table.cell variant="strong">
                        <flux:link :href="route('admin.users.edit', $user)" wire:navigate>{{ $user->name }}</flux:link>
                        @unless ($user->is_active)
                            <flux:badge size="sm" color="zinc" class="ms-2">{{ __('Inactive') }}</flux:badge>
                        @endunless
                    </flux:table.cell>
                    <flux:table.cell>{{ $user->email }}</flux:table.cell>
                    <flux:table.cell>{{ $user->role->label() }}</flux:table.cell>
                    <flux:table.cell>{{ $user->locale->label() }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $user->projects_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" :href="route('admin.users.edit', $user)" wire:navigate :aria-label="__('Edit')" />
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
