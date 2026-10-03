<?php

namespace App\Http\Controllers;

use App\Documents\InvoicePdf;
use App\Documents\PdfRenderer;
use App\Models\Invoice;
use App\Services\Ksef\InvoiceQrCode;
use Illuminate\Http\Response;

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
