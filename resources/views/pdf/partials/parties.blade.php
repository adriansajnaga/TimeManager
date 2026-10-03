{{-- Auftraggeber (klient) i Auftragnehmer (ASCOMM) obok siebie. --}}
<table class="layout">
    <tr>
        <td style="width: 50%;">
            <p class="section-title">{{ $t('client') }}:</p>
            {{ $client->name }}<br>
            {{ $client->street }}<br>
            {{ trim($client->zip.' '.$client->city) }}{{ $client->country_code !== 'PL' ? ', '.$client->country_code : '' }}<br>
            {{ $clientTaxLine }}
        </td>
        <td style="width: 50%;">
            <p class="section-title">{{ $t('contractor') }}:</p>
            {{ $company->name }}<br>
            {{ $company->street }}<br>
            {{ trim($company->zip.' '.$company->city) }}, {{ $company->country_code }}<br>
            {{ $t('tax_id_pl') }}: {{ $company->nip }}
        </td>
    </tr>
</table>
