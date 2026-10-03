<?php

namespace App\Http\Controllers;

use App\Documents\InvoicePdf;
use App\Documents\PdfRenderer;
use App\Documents\SettlementPackage;
use App\Models\Invoice;
use App\Services\Ksef\InvoiceQrCode;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * PDF faktury sprzedaży (szkic z dopiskiem „Projekt”).
 */
class InvoiceController extends Controller
{
    public function pdf(Invoice $invoice, PdfRenderer $renderer): Response
    {
        abort_unless($invoice->isSales(), 404);

        $document = new InvoicePdf($invoice, app(InvoiceQrCode::class)->url($invoice));

        return response($renderer->render([$document], $invoice->kind->label().' '.$invoice->displayNumber()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->filename().'"',
        ]);
    }

    /**
     * Pakiet dla klienta: faktura + Stundenzettel + kilometrówka + Montageaufträge (jeden PDF).
     */
    public function package(Invoice $invoice, SettlementPackage $package, PdfRenderer $renderer): Response
    {
        abort_unless($invoice->isSales(), 404);

        return response($renderer->render($package->documents($invoice), $invoice->displayNumber()), 200, [
            'Content-Type' => 'application/pdf',
            // „zusammengefügt” — nazwa UTF-8 z zapasową wersją ASCII dla starszych programów.
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $package->filename($invoice), Str::ascii($package->filename($invoice))),
        ]);
    }

    /**
     * XML faktury w postaci, w jakiej trafiła do KSeF albo z niego przyszła.
     */
    public function xml(Invoice $invoice): Response
    {
        abort_if(blank($invoice->xml), 404);

        $name = $invoice->ksef_number ?? str_replace(['/', ' '], ['-', '_'], (string) $invoice->number);

        return response((string) $invoice->xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'.xml"',
        ]);
    }
}
