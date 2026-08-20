<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('invoice_number')->unique();
            $table->dateTime('sale_at');
            $table->boolean('is_walk_in')->default(false);
            $table->integer('subtotal_kyat')->default(0);
            $table->integer('discount_kyat')->default(0);
            $table->integer('total_kyat')->default(0);
            $table->integer('paid_kyat')->default(0);
            $table->integer('unpaid_kyat')->default(0);
            $table->boolean('is_reversed')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'sale_at']);
            $table->index('invoice_number');
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curry_item_id')->nullable()->constrained('curry_items')->nullOnDelete();
            $table->string('curry_name_snapshot');
            $table->integer('quantity')->default(1);
            $table->integer('unit_price_snapshot_kyat')->unsigned();
            $table->integer('line_total_kyat')->unsigned();
            $table->timestamps();
            $table->index(['sale_id', 'curry_item_id']);
        });

        Schema::create('customer_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('customer_ledger_entries')->nullOnDelete();
            $table->string('event_type', 60);
            $table->integer('amount_kyat');
            $table->integer('balance_after_kyat');
            $table->string('reason', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
            $table->index(['customer_id', 'occurred_at']);
            $table->index('event_type');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('customer_ledger_entries');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
