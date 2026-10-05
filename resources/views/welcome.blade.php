<x-layouts::auth>
    <div class="flex flex-col gap-5">
        <p class="text-sm leading-relaxed" style="color: #d2d3d3;">
            {{ __('Time tracking application: hours on projects, weekly reports and mileage logs, client settlements and invoices sent to KSeF.') }}
        </p>

        <p class="text-xs" style="color: #8f9192;">
            {{ __('Access for office staff only. New accounts are created by the administrator.') }}
        </p>

        <flux:button variant="primary" class="w-full" :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:button>
    </div>
</x-layouts::auth>
