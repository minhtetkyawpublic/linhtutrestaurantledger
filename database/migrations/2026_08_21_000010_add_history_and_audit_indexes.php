<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->index(['sale_at', 'id'], 'sales_history_date_id_index');
        });

        Schema::table('customer_ledger_entries', function (Blueprint $table) {
            $table->index(['event_type', 'occurred_at'], 'ledger_event_date_index');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['action', 'id'], 'audit_action_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_history_date_id_index');
        });

        Schema::table('customer_ledger_entries', function (Blueprint $table) {
            $table->dropIndex('ledger_event_date_index');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_action_id_index');
        });
    }
};
