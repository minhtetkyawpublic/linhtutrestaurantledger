<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curry_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('curry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curry_category_id')->nullable()->constrained('curry_categories')->nullOnDelete();
            $table->string('name', 200);
            $table->integer('current_price_kyat')->unsigned();
            $table->boolean('is_available')->default(true);
            $table->integer('display_order')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('phone_number', 80)->nullable()->index();
            $table->text('address_or_note')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->integer('opening_balance_kyat')->default(0);
            $table->string('opening_balance_reason', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['name', 'phone_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
        Schema::dropIfExists('curry_items');
        Schema::dropIfExists('curry_categories');
    }
};
