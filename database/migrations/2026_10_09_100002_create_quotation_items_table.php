<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The breakdown behind a quotation's headline figure (spec §9).
     *
     * A single number tells a customer nothing about whether it is fair. Labour
     * at four hours, two bags of cement and a matatu fare does — it is the
     * difference between a quote and an estimate, and it is what the platform
     * asks professionals for.
     */
    public function up(): void
    {
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();

            // labour / materials / transport / other — see QuotationItemCategory.
            // The category is what the quotation's four cached totals group by.
            $table->string('category', 20);

            $table->string('description');

            /*
             * Decimal rather than integer: materials are quoted in metres, bags
             * and litres, and rounding a part-unit up to a whole one would
             * misprice the job.
             */
            $table->decimal('quantity', 8, 2)->default(1);

            $table->unsignedBigInteger('unit_price_cents');

            // quantity x unit_price, computed in the model rather than accepted
            // from the payload. Stored so the quotation's cached totals can be a
            // plain aggregate over this table.
            $table->unsignedBigInteger('total_cents');

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['quotation_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
    }
};
