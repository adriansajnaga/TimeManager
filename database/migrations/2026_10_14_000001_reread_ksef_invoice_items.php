<?php

use App\Models\InvoiceItem;
use App\Services\Ksef\Fa3InvoiceReader;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pozycje faktur pobranych z KSeF odczytane ponownie z zapisanego XML — pierwszy odczyt
     * dawał 0 przy fakturach liczonych od brutto (P_9B/P_11A). Nieczytelne dokumenty pomijamy.
     */
    public function up(): void
    {
        $reader = app(Fa3InvoiceReader::class);

        DB::table('invoices')->where('source', 'ksef')->whereNotNull('xml')->orderBy('id')->each(function (object $invoice) use ($reader) {
            try {
                $items = $reader->read((string) $invoice->xml)['items'];
            } catch (Throwable) {
                return;
            }

            DB::transaction(function () use ($invoice, $items) {
                DB::table('invoice_items')->where('invoice_id', $invoice->id)->delete();

                foreach ($items as $index => $item) {
                    $created = new InvoiceItem;
                    $created->forceFill([...$item, 'invoice_id' => $invoice->id, 'position' => $index + 1])->save();
                    // Wartość wiersza z XML, nie przeliczona z ceny jednostkowej.
                    DB::table('invoice_items')->where('id', $created->id)->update(['net' => $item['net']]);
                }
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Nie przywracamy błędnie odczytanych pozycji.
    }
};
