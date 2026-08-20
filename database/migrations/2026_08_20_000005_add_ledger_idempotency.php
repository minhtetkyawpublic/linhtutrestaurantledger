<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_ledger_entries', function (Blueprint $table) {
            $table->string('idempotency_key', 120)->nullable()->after('event_type');
            $table->unique(['actor_user_id', 'idempotency_key'], 'ledger_actor_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_ledger_entries', function (Blueprint $table) {
            $table->dropUnique('ledger_actor_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
