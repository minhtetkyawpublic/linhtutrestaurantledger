<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curry_items', function (Blueprint $table) {
            $table->unsignedBigInteger('current_price_kyat')->change();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->bigInteger('opening_balance_kyat')->default(0)->change();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->bigInteger('subtotal_kyat')->default(0)->change();
            $table->bigInteger('discount_kyat')->default(0)->change();
            $table->bigInteger('total_kyat')->default(0)->change();
            $table->bigInteger('paid_kyat')->default(0)->change();
            $table->bigInteger('unpaid_kyat')->default(0)->change();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_price_snapshot_kyat')->change();
            $table->unsignedBigInteger('line_total_kyat')->change();
        });

        Schema::table('customer_ledger_entries', function (Blueprint $table) {
            $table->bigInteger('amount_kyat')->change();
            $table->bigInteger('balance_after_kyat')->change();
        });
    }

    public function down(): void
    {
        Schema::table('curry_items', function (Blueprint $table) {
            $table->unsignedInteger('current_price_kyat')->change();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->integer('opening_balance_kyat')->default(0)->change();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->integer('subtotal_kyat')->default(0)->change();
            $table->integer('discount_kyat')->default(0)->change();
            $table->integer('total_kyat')->default(0)->change();
            $table->integer('paid_kyat')->default(0)->change();
            $table->integer('unpaid_kyat')->default(0)->change();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->unsignedInteger('unit_price_snapshot_kyat')->change();
            $table->unsignedInteger('line_total_kyat')->change();
        });

        Schema::table('customer_ledger_entries', function (Blueprint $table) {
            $table->integer('amount_kyat')->change();
            $table->integer('balance_after_kyat')->change();
        });
    }
};
