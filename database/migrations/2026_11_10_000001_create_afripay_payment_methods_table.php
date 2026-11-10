<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment method the business may charge later, without the customer.
 *
 * Polymorphic like transactions: the method belongs to whatever the host
 * application bills, an organisation, a tenant, a user. The package does not
 * need to know which.
 *
 * No card number is ever stored. The gateway holds the card; this table holds
 * only the handles needed to charge it again, plus what a human needs to
 * recognise the card on screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('afripay_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 30)->index();

            // Les deux poignées du prélèvement : le client chez la passerelle,
            // et le moyen de paiement qu'il a autorisé.
            $table->string('gateway_customer_id', 100);
            $table->string('gateway_method_id', 100);

            // De quoi reconnaître la carte à l'écran, rien de plus.
            $table->string('brand', 30)->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();

            $table->string('payable_type')->nullable();
            $table->unsignedBigInteger('payable_id')->nullable();

            $table->boolean('is_default')->default(true);
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['gateway', 'gateway_method_id'], 'afripay_methods_unique');
            $table->index(['payable_type', 'payable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('afripay_payment_methods');
    }
};
