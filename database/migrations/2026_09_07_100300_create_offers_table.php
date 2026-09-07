<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            // Import that last touched this offer; kept for traceability only.
            $table->foreignId('import_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id', 128);
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('max_guests');
            // Minor units (72500 == 725.00 EUR) so money never touches a float.
            $table->unsignedBigInteger('price');
            $table->char('currency', 3);
            // Unsigned: a booking can never push the stock below zero.
            $table->unsignedInteger('available_units')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();

            // An offer is identified by the supplier that sent it.
            $table->unique(['supplier_id', 'external_id']);
            // Covers the availability filter of GET /api/properties.
            $table->index(['check_in', 'check_out', 'max_guests', 'expires_at'], 'offers_search_index');
            // Lets MySQL pick the cheapest offer per property without a filesort.
            $table->index(['property_id', 'check_in', 'check_out', 'price'], 'offers_cheapest_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
