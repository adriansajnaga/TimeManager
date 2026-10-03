{{-- Nagłówek dokumentów ASCOMM: dane firmy po lewej, logo po prawej. --}}
<table class="layout">
    <tr>
        <td class="small">
            <span class="bold">{{ $company->name }}</span><br>
            {{ collect([$company->street, trim($company->zip.' '.$company->city), $company->country_code])->filter()->implode(', ') }}<br>
            {{ $company->website }}
        </td>
        <td class="right" style="width: 45mm;">
            @if ($companyLogo)
                <img src="{{ $companyLogo }}" style="height: 12.8mm;" alt="">
            @endif
        </td>
    </tr>
</table>
