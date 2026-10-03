<?php

namespace App\Documents;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Składa dokumenty w jeden PDF (mPDF, czysty PHP). Każdy dokument zaczyna nową stronę
 * we własnej orientacji, więc w jednym pliku mogą być strony pionowe i poziome.
 */
final class PdfRenderer
{
    /**
     * @param  list<Document>  $documents
     */
    public function render(array $documents, string $title = ''): string
    {
        if ($documents === []) {
            throw new InvalidArgumentException('Nothing to render: the document list is empty.');
        }

        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $company = CompanySetting::current();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => $documents[0]->orientation(),
            'tempDir' => $tempDir,
            'default_font' => 'dejavusanscondensed',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 12,
            'margin_bottom' => 16,
            'margin_footer' => 6,
        ]);

        $mpdf->SetTitle($title);
        $mpdf->SetAuthor((string) $company->name);
        $mpdf->SetCreator((string) config('app.name'));

        $mpdf->WriteHTML(view('pdf.partials.styles')->render(), HTMLParserMode::HEADER_CSS);
        $mpdf->SetHTMLFooter(view('pdf.partials.footer', ['company' => $company])->render());

        foreach ($documents as $index => $document) {
            if ($index > 0) {
                $mpdf->AddPage($document->orientation());
            }

            $mpdf->WriteHTML(view($document->view(), $document->data())->render(), HTMLParserMode::HTML_BODY);
        }

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
