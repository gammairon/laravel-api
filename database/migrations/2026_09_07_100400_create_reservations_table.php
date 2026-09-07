<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            // Caller supplied key: a retried request can never book twice.
            $table->string('client_reference', 128)->unique();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->unsignedInteger('units')->default(1);
            // Price snapshot: the offer may be re-imported with a new price later.
            $table->unsignedBigInteger('price');
            $table->char('currency', 3);
            $table->timestamps();

            $table->index('offer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
