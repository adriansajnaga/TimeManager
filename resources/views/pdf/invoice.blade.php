@php
    use App\Enums\InvoiceKind;
    use App\Services\Invoices\Parties;

    $kind = $invoice->kind;
    $due = $summary->gross();
@endphp
<div class="inv">

@if ($invoice->isDraft())
    <div class="draft">{{ $t('draft') }}</div>
@endif

{{-- Nagłówek: tytuł i numer po lewej, daty po prawej --}}
<table class="layout">
    <tr>
        <td style="width: 58%;">
            @if ($logo)
                <img src="{{ $logo }}" style="height: 10.4mm; margin-bottom: 2mm;" alt=""><br>
            @endif
            <h1>{{ $title }} {{ $invoice->number }}</h1>
            @if ($invoice->ksef_number)
                <div class="small" style="margin-top: 1mm;">{{ $t('ksef_number') }}: <span class="bold">{{ $invoice->ksef_number }}</span></div>
            @endif
            @if ($reverseCharge)
                <div class="annotation">{{ $t('reverse_charge') }}</div>
            @endif
            @if ($kind === InvoiceKind::Proforma)
                <div class="annotation">{{ $t('proforma_note') }}</div>
            @endif
        </td>
        <td style="width: 42%;">
            <table class="layout small">
                <tr><td class="label">{{ $t('issue_date') }}:</td><td class="right bold">{{ $date($invoice->issue_date) }}</td></tr>
                @if ($invoice->sale_date)
                    <tr><td class="label">{{ $t('sale_date') }}:</td><td class="right bold">{{ $date($invoice->sale_date) }}</td></tr>
                @endif
                <tr><td class="label">{{ $t('number') }}:</td><td class="right bold">{{ $invoice->number ?? '—' }}</td></tr>
                @if ($invoice->issue_place)
                    <tr><td class="label">{{ $t('issue_place') }}:</td><td class="right bold">{{ $invoice->issue_place }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

{{-- Strony --}}
<table class="layout" style="margin-top: 4mm;">
    <tr>
        @foreach ([['seller', $invoice->seller, true], ['buyer', $invoice->buyer, false]] as [$key, $party, $isSeller])
            @if (! $isSeller)
                <td style="width: 4mm;"></td>
            @endif
            <td class="party" style="width: 48%;">
                <span class="label small">{{ $t($key) }}:</span><br>
                <span class="bold">{{ $party['name'] ?? '' }}</span><br>
                @foreach (Parties::addressLines($party) as $line)
                    {{ $line }}<br>
                @endforeach
                {{ $taxLine($party, $isSeller) }}
            </td>
        @endforeach
    </tr>
</table>

{{-- Płatność --}}
@if ($kind !== InvoiceKind::Proforma || $invoice->due_date)
    <table class="data" style="margin-top: 4mm;">
        <tr>
            <th style="width: 18%;">{!! $th('payment_method') !!}</th>
            <th style="width: 16%;">{!! $th('due_date') !!}</th>
            <th>{!! $th('bank_account') !!}</th>
            <th style="width: 20%;">{!! $th($due->isNegative() ? 'amount_refund' : 'amount_due') !!}</th>
        </tr>
        <tr>
            <td class="ctr">{{ $t('payment.'.$invoice->payment_method->value) }}</td>
            <td class="ctr">{{ $date($invoice->due_date) ?: '—' }}</td>
            <td class="ctr small">
                @if ($invoice->bank_account)
                    {{ collect([$invoice->bank_account['swift'] ?? null, $invoice->bank_account['iban'] ?? null])->filter()->implode(' - ') }}
                @else
                    —
                @endif
            </td>
            <td class="num bold">{{ $money($due->abs()) }}</td>
        </tr>
    </table>
@endif

{{-- Korekta: dokument korygowany --}}
@if ($kind === InvoiceKind::Correction)
    <p class="section">{{ $t('corrected_invoice') }}: {{ $invoice->corrected_number }} {{ $t('of_date') }} {{ $date($invoice->corrected_issue_date) }}@if ($invoice->corrected_ksef_number), {{ $t('ksef_number') }}: {{ $invoice->corrected_ksef_number }}@endif</p>
    <p>{{ $t('correction_reason') }}: {{ $invoice->correction_reason }}</p>

    <p class="section">{{ $t('before_correction') }}</p>
    @include('pdf.partials.invoice-items', ['items' => $beforeRows])
    @include('pdf.partials.invoice-vat', ['vatSummary' => $beforeSummary])

    <p class="section">{{ $t('after_correction') }}</p>
@elseif ($kind === InvoiceKind::Advance || $kind === InvoiceKind::Final)
    <p class="section">{{ $t('order') }}</p>
@endif

{{-- Pozycje --}}
<div style="margin-top: 3mm;">
    @include('pdf.partials.invoice-items', ['items' => $rows])
</div>

@if ($kind === InvoiceKind::Correction)
    @include('pdf.partials.invoice-vat', ['vatSummary' => $orderSummary])
    <p class="section">{{ $t('difference') }}</p>
    @include('pdf.partials.invoice-vat', ['vatSummary' => $summary])
@elseif ($kind === InvoiceKind::Advance)
    @include('pdf.partials.invoice-vat', ['vatSummary' => $orderSummary, 'caption' => $t('order_value')])
    <p class="section">{{ $t('advance_received') }}</p>
    @include('pdf.partials.invoice-vat', ['vatSummary' => $summary])
@elseif ($kind === InvoiceKind::Final)
    @include('pdf.partials.invoice-vat', ['vatSummary' => $orderSummary, 'caption' => $t('order_value')])
    <p class="section">{{ $t('advances') }}</p>
    <table class="data">
        @foreach ($invoice->advances as $advance)
            <tr>
                <td>{{ $advance->number }} {{ $t('of_date') }} {{ $date($advance->issue_date) }}</td>
                <td class="num" style="width: 30%;">{{ $money($advance->gross) }}</td>
            </tr>
        @endforeach
    </table>
    <p class="section">{{ $t('remaining') }}</p>
    @include('pdf.partials.invoice-vat', ['vatSummary' => $summary])
@else
    @include('pdf.partials.invoice-vat', ['vatSummary' => $summary])
@endif

{{-- Do zapłaty --}}
<p class="right due" style="margin-top: 3mm;">{{ $t($due->isNegative() ? 'amount_refund' : 'amount_due') }}: {{ $money($due->abs()) }}</p>

@if ($vatInPln !== null)
    <p class="note">
        {{ $t('vat_in_pln') }}: <span class="bold">{{ $number($vatInPln) }} PLN</span>
        ({{ trans('invoicepdf.rate_note', ['rate' => $number($invoice->exchange_rate, 4), 'currency' => $invoice->currency, 'table' => $invoice->exchange_rate_table ?? '—', 'date' => $date($invoice->exchange_rate_date)], 'pl') }})
    </p>
@endif

{{-- Adnotacje i uwagi --}}
@if ($npServices)
    <p class="note">{{ trans('invoicepdf.np_services', [], 'pl') }}</p>
    @if ($invoice->language === \App\Enums\InvoiceLanguage::PolishEnglish)
        <p class="note">{{ trans('invoicepdf.np_services', [], 'en') }}</p>
    @endif
@endif

@if ($invoice->vat_exemption_basis)
    <p class="note">{{ $t('exemption') }}: {{ $invoice->vat_exemption_basis }}</p>
@endif

@if ($invoice->notes)
    <p class="note"><span class="bold">{{ $t('notes') }}:</span> {!! nl2br(e($invoice->notes)) !!}</p>
@endif

</div>

{{-- Strona weryfikacyjna (KOD I) jak w wizualizacji KSeF --}}
@if ($qrUrl)
    <pagebreak />
    <div class="inv">
        <p class="section" style="font-size: 11pt;">{{ $t('verify_title') }}</p>
        <table class="layout">
            <tr>
                <td style="width: 45mm;"><barcode code="{{ $qrUrl }}" type="QR" size="1.1" error="M" disableborder="1" /></td>
                <td class="small">{{ $t('verify_hint') }}</td>
            </tr>
        </table>
        <p class="tiny" style="margin-top: 3mm;"><a href="{{ $qrUrl }}">{{ $qrUrl }}</a></p>
        <p class="bold" style="margin-top: 3mm;">{{ $invoice->ksef_number }}</p>
        @if ($invoice->isFromTestKsef())
            <p class="center muted" style="margin-top: 40mm;">{{ $t('test_environment') }}</p>
        @endif
    </div>
@endif
