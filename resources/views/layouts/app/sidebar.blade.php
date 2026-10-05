<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Work')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    @can('log-own-time')
                        <flux:sidebar.item icon="clock" :href="route('time.week')" :current="request()->routeIs('time.*')" wire:navigate>
                            {{ __('Working time') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="calendar-days" :href="route('weeks.index')" :current="request()->routeIs('weeks.*')" wire:navigate>
                            {{ __('Weeks') }}
                        </flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>

                @can('use-mailbox')
                    <flux:sidebar.group :heading="__('Mail')" class="grid">
                        <flux:sidebar.item icon="inbox" :href="route('mailbox.index')" :current="request()->routeIs('mailbox.*')" wire:navigate>
                            {{ __('Inbox') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan

                @can('manage-invoices')
                    <flux:sidebar.group :heading="__('Finance')" class="grid">
                        <flux:sidebar.item icon="document-text" :href="route('invoices.index')" :current="request()->routeIs('invoices.*') && request('direction') !== 'purchase'" wire:navigate>
                            {{ __('Sales invoices') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="inbox-arrow-down" :href="route('invoices.index', ['direction' => 'purchase'])" :current="request()->routeIs('invoices.*') && request('direction') === 'purchase'" wire:navigate>
                            {{ __('Purchase invoices') }}
                        </flux:sidebar.item>
                        @can('manage-settlements')
                            <flux:sidebar.item icon="calculator" :href="route('settlements.index')" :current="request()->routeIs('settlements.*')" wire:navigate>
                                {{ __('Settlements') }}
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcan

                @canany(['manage-projects', 'manage-contractors', 'manage-notes'])
                    <flux:sidebar.group :heading="__('Records')" class="grid">
                        @can('manage-projects')
                            <flux:sidebar.item icon="folder" :href="route('projects.index')" :current="request()->routeIs('projects.*')" wire:navigate>
                                {{ __('Projects') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('manage-contractors')
                            <flux:sidebar.item icon="building-office" :href="route('contractors.index')" :current="request()->routeIs('contractors.*')" wire:navigate>
                                {{ __('Contractors') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('manage-notes')
                            <flux:sidebar.item icon="pencil-square" :href="route('notes.index')" :current="request()->routeIs('notes.*')" wire:navigate>
                                {{ __('Notes') }}
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany

                @can('manage-measurements')
                    <flux:sidebar.group :heading="__('Measurements')" class="grid">
                        <flux:sidebar.item icon="bolt" :href="route('measurements.index')" :current="request()->routeIs('measurements.*') && ! request()->routeIs('measurements.equipment', 'measurements.instrument', 'measurements.performer')" wire:navigate>
                            {{ __('Protocols') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="wrench-screwdriver" :href="route('measurements.equipment')" :current="request()->routeIs('measurements.equipment', 'measurements.instrument', 'measurements.performer')" wire:navigate>
                            {{ __('Instruments and people') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan

                @canany(['manage-users', 'manage-settings'])
                    {{-- Domyślnie zwinięta; rozwinięta, gdy jesteś na jednej z jej stron. --}}
                    <flux:sidebar.group :heading="__('Administration')" icon="cog-6-tooth" expandable :expanded="request()->routeIs('admin.*')" class="grid">
                        @can('manage-users')
                            <flux:sidebar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>
                                {{ __('Users') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('manage-settings')
                            <flux:sidebar.item icon="building-storefront" :href="route('admin.company')" :current="request()->routeIs('admin.company')" wire:navigate>
                                {{ __('Company') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="banknotes" :href="route('admin.bank-accounts')" :current="request()->routeIs('admin.bank-accounts')" wire:navigate>
                                {{ __('Bank accounts') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="truck" :href="route('admin.vehicles')" :current="request()->routeIs('admin.vehicles')" wire:navigate>
                                {{ __('Vehicles') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="sparkles" :href="route('admin.ai')" :current="request()->routeIs('admin.ai')" wire:navigate>
                                {{ __('AI assistant') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="shield-check" :href="route('admin.ksef')" :current="request()->routeIs('admin.ksef')" wire:navigate>
                                {{ __('KSeF') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="envelope" :href="route('admin.mail')" :current="request()->routeIs('admin.mail')" wire:navigate>
                                {{ __('E-mail') }}
                            </flux:sidebar.item>
                            {{-- Import tylko do czasu pierwszego importu ze starej aplikacji. --}}
                            @if (\App\Services\LegacyImport\LegacyImporter::isConfigured() && ! \App\Models\TimeEntry::query()->whereNotNull('legacy_id')->exists())
                                <flux:sidebar.item icon="arrow-down-tray" :href="route('admin.legacy-import')" :current="request()->routeIs('admin.legacy-import')" wire:navigate>
                                    {{ __('Import') }}
                                </flux:sidebar.item>
                            @endif
                        @endcan
                    </flux:sidebar.group>
                @endcanany
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
