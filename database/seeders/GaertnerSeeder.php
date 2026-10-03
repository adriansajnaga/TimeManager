<?php

namespace Database\Seeders;

use App\Enums\ContractorType;
use App\Enums\InvoiceLanguage;
use App\Enums\InvoiceLineMode;
use App\Enums\Language;
use App\Enums\PackageDocument;
use App\Enums\ProjectBillingType;
use App\Enums\ProjectStatus;
use App\Enums\VatCode;
use App\Models\BankAccount;
use App\Models\Contractor;
use App\Models\Project;
use App\Support\DefaultTemplates;
use Illuminate\Database\Seeder;

/**
 * Klient Gärtner Elektrotechnik GmbH i projekty z pakietu 4/8/2026 (KW 31–32)
 * oraz projekt Lürssen z przykładu KW 35.
 */
class GaertnerSeeder extends Seeder
{
    public function run(): void
    {
        $gaertner = Contractor::query()->updateOrCreate(['name' => 'Gärtner Elektrotechnik GmbH'], [
            'type' => ContractorType::Client,
            'street' => 'Zum Brook 9',
            'zip' => '24143',
            'city' => 'Kiel',
            'country_code' => 'DE',
            'vat_prefix' => 'DE',
            'tax_id' => '286771111',
            'email' => 'info@gaertner-elektro-kiel.de',
            'phone' => '0431 / 570 918 100',
            'fax' => '0431 / 570 918 226',
            'website' => 'www.gaertner-elektro-kiel.de',
            'logo_path' => SeederAssets::store('gaertner-logo.png', 'contractors/gaertner-logo.png'),
            'document_language' => Language::German,
            'invoice_language' => InvoiceLanguage::PolishEnglish,
            'currency' => 'EUR',
            'vat_code' => VatCode::OutsideScopeEuServices,
            'invoice_line_mode' => InvoiceLineMode::Single,
            'invoice_description_template' => DefaultTemplates::invoiceDescription(Language::German),
            'payment_days' => 14,
            'bank_account_id' => BankAccount::query()->value('id'),
            'package_documents' => PackageDocument::defaultOrder(),
            'hourly_rate' => '38.00',
            'km_rate' => '0.3000',
            'base_address' => 'Zum Brook 24113 Kiel',
            'email_to' => ['160_rechnungen_gaertner@handwerksgruppe.de'],
            'email_subject_template' => DefaultTemplates::emailSubject(Language::German),
            'email_body_template' => DefaultTemplates::emailBody(Language::German),
            'is_active' => true,
        ]);

        $projects = [
            ['160406010', 'Zuleitung Dampf Luftbefeuchter', 'H-TEC Hamburg', 'H-Tec GmbH', 'Victoriaring 23', '22143', 'Hamburg', '90.5', true],
            ['160226006', 'Fliegerhorst Schleswig - Jagel Halle 2', 'BW Jagel, Kropp, Hohn', 'Bundeswehr', 'Bundesstraße 77', '24878', 'Jagel', '56', true],
            ['160226039', 'Fliegerhorst Schleswig - Jagel Kantine', 'BW Jagel, Kropp, Hohn', 'Bundeswehr', 'Bundesstraße 77', '24878', 'Jagel', '56', true],
            ['160226043', 'Fliegerhorst Hohn - Halle GFD', 'BW Jagel, Kropp, Hohn', 'Bundeswehr', 'Königsbach', '24806', 'Hohn', null, false],
            ['160226025', 'Kropp Geb. 53, Kantine Lichtbänder', 'BW Jagel, Kropp, Hohn', 'Gebäudemanagement GMSH', 'Bennebeker Chaussee', '24848', 'Kropp', null, false],
            ['160226044', 'WTD 71, Geb. 14, BR Kanäle', 'WTD71 ECK', 'Bundeswehr', 'Berlinerstr. 115', '24340', 'Eckernförde', null, false],
            ['160245002', 'Halle 9,Profinetleitung, NYY-J 4x25/16, 110V Anlage', 'TKMS Halle 9', 'TKMS GmbH', 'Werftstraße 112-114', '24143', 'Kiel', null, false],
            ['160556005', 'Telefonanlage - Notdienst', 'MADEC', 'Mobac GmbH', 'Kieler Str. 23', '24247', 'Mielkendorf', null, false],
            ['160226047', 'Dock1 - Kameraüberwachungsanlage', 'Lürssen Krüger Werft Rendsburg', 'Lürssen Kröger Werft', 'Hüttenstraße 25', '24790', 'Schacht-Audorf', '34', true],
        ];

        foreach ($projects as [$number, $name, $label, $site, $street, $zip, $city, $km, $mileage]) {
            Project::query()->updateOrCreate(['contractor_id' => $gaertner->id, 'number' => $number], [
                'name' => $name,
                'invoice_label' => $label,
                'site_name' => $site,
                'site_street' => $street,
                'site_zip' => $zip,
                'site_city' => $city,
                'site_country' => 'DE',
                'billing_type' => ProjectBillingType::Hourly,
                'km_one_way' => $km,
                'mileage_default' => $mileage,
                'status' => ProjectStatus::Active,
            ]);
        }
    }
}
