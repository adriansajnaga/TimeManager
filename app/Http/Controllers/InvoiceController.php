<?php

namespace App\Http\Controllers;

use App\Documents\InvoicePdf;
use App\Documents\PdfRenderer;
use App\Models\Invoice;
use Illuminate\Http\Response;

/**
 * PDF faktury sprzedaży (szkic z dopiskiem „Projekt”).
 */
class InvoiceController extends Controller
{
    public function pdf(Invoice $invoice, PdfRenderer $renderer): Response
    {
        abort_unless($invoice->isSales(), 404);

        $document = new InvoicePdf($invoice);

        return response($renderer->render([$document], $invoice->kind->label().' '.$invoice->displayNumber()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->filename().'"',
        ]);
    }
}
