<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Faktury sprzedaży i zakupu. Dane stron i rachunku są kopią z chwili wystawienia,
        // żeby późniejsza zmiana kartoteki nie zmieniała wystawionego dokumentu.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10)->default('sales');
            $table->string('kind', 10)->default('vat');
            $table->string('status', 10)->default('draft');
            $table->string('number', 100)->nullable();
            $table->foreignId('contractor_id')->nullable()->constrained()->nullOnDelete();

            $table->json('seller')->nullable();
            $table->json('buyer')->nullable();
            $table->string('counterparty_name')->nullable()->index();
            $table->string('counterparty_tax_id', 40)->nullable();

            $table->date('issue_date');
            $table->date('sale_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('issue_place')->nullable();
            $table->string('payment_method', 10)->default('transfer');
            $table->json('bank_account')->nullable();
            $table->date('paid_on')->nullable();

            $table->char('currency', 3)->default('PLN');
            $table->decimal('exchange_rate', 10, 4)->nullable();
            $table->date('exchange_rate_date')->nullable();
            $table->string('exchange_rate_table', 30)->nullable();
            $table->string('language', 5)->default('pl');

            // Sumy dokumentu (w korekcie — różnice, w zaliczkowej — otrzymana zaliczka).
            $table->decimal('net', 14, 2)->default(0);
            $table->decimal('vat', 14, 2)->default(0);
            $table->decimal('gross', 14, 2)->default(0);

            // Zaliczka: otrzymana kwota brutto; pozycje faktury są wtedy pozycjami zamówienia.
            $table->decimal('advance_amount', 14, 2)->nullable();

            // Korekta
            $table->foreignId('corrected_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('corrected_number', 100)->nullable();
            $table->date('corrected_issue_date')->nullable();
            $table->string('corrected_ksef_number', 50)->nullable();
            $table->string('correction_reason')->nullable();

            $table->string('vat_exemption_basis')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['direction', 'issue_date']);
            $table->index(['direction', 'number']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            // Korekta: wiersze stanu przed korektą (StanPrzed w FA(3)).
            $table->boolean('is_before')->default(false);
            $table->text('name');
            $table->string('unit', 20)->nullable();
            $table->decimal('quantity', 14, 4)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->string('vat_code', 10);
            $table->decimal('net', 14, 2);
            $table->timestamps();
        });

        // Faktura rozliczeniowa (ROZ) → faktury zaliczkowe (ZAL), które rozlicza.
        Schema::create('invoice_advances', function (Blueprint $table) {
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('advance_invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->primary(['invoice_id', 'advance_invoice_id']);
        });

        // Średnie kursy NBP (tabela A) pobrane dla faktur w walutach obcych.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->date('effective_date');
            $table->decimal('rate', 10, 4);
            $table->string('table_number', 30);
            $table->timestamps();

            $table->unique(['currency', 'effective_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('invoice_advances');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
