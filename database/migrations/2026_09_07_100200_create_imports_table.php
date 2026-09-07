<?php

use App\Enums\ImportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('external_import_id', 128);
            // When the supplier built the batch (not when we received it).
            $table->timestamp('sent_at');
            $table->enum('status', ImportStatus::values())->default(ImportStatus::Pending->value);
            $table->unsignedInteger('total_offers')->default(0);
            $table->unsignedInteger('processed_offers')->default(0);
            $table->text('error')->nullable();
            // Raw offers payload, so the job is self-contained and survives retries.
            $table->json('payload')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Idempotency key: re-sending the same batch never creates a second import.
            $table->unique(['supplier_id', 'external_import_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
