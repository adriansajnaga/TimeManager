<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Livewire\Livewire;

test('the dashboard shows monthly sales and purchases in PLN and only the number of weeks to close', function () {
    $admin = User::factory()->admin()->create();
    $issued = fn (Invoice $invoice, array $data) => $invoice->forceFill(['status' => InvoiceStatus::Issued, ...$data])->save();

    $issued(Invoice::factory()->create(), ['net' => '1000.00', 'currency' => 'PLN']);
    $issued(Invoice::factory()->purchase()->create(), ['net' => '100.00', 'currency' => 'EUR', 'exchange_rate' => '4.2500']);
    $issued(Invoice::factory()->purchase()->create(), ['net' => '50.00', 'currency' => 'EUR', 'exchange_rate' => null]);
    Invoice::factory()->create(['net' => '999.00']); // szkic — nie liczy się

    $page = Livewire::actingAs($admin)->test('pages::dashboard')->assertSee(__('Sales and purchases'));
    $monthly = $page->instance()->monthly;
    $current = collect($monthly['months'])->last();

    expect($monthly['months'])->toHaveCount(12)
        ->and($current['sales'])->toBe(1000.0)
        ->and($current['purchases'])->toBe(425.0)
        ->and($monthly['missing'])->toBe(1)
        ->and($page->instance()->openWeeks)->toBe(0);

    $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertDontSee('weeks.show');
});
