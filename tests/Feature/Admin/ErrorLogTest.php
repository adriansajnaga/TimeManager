<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;

test('administrators see the latest errors from the log', function () {
    $marker = 'Testowy błąd '.uniqid();
    Log::channel('single')->error($marker, ['exception' => 'stack line']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.logs'))
        ->assertOk()
        ->assertSee($marker);

    $this->actingAs(User::factory()->create())->get(route('admin.logs'))->assertForbidden();
});
