<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('afripay.table', 'afripay_transactions'), function (Blueprint $table) {
            $table->id();

            // Gateway & reference
            $table->string('gateway', 30)->index();
            $table->string('reference')->nullable();
            $table->string('gateway_reference')->nullable();

            // Amount
            $table->decimal('amount', 12, 2);
            $table->string('currency', 5)->default('XOF');

            // Status
            $table->string('status', 20)->default('pending');

            // Gateway response (raw JSON from the payment provider)
            $table->json('gateway_response')->nullable();

            // Custom metadata (your app's data — order_id, user_id, etc.)
            $table->json('metadata')->nullable();

            // Polymorphic relation to your payable model
            $table->string('payable_type')->nullable();
            $table->unsignedBigInteger('payable_id')->nullable();

            // Idempotence flag — set once the transaction has been processed
            // Prevents double-activation from webhook + success URL race condition
            $table->timestamp('processed_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // Indexes for common queries
            $table->index(['gateway', 'status']);
            $table->index(['gateway', 'reference']);
            $table->index(['payable_type', 'payable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('afripay.table', 'afripay_transactions'));
    }
};
