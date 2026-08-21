<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curry_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('curry_category_id');
        });

        Schema::dropIfExists('curry_categories');
    }

    public function down(): void
    {
        Schema::create('curry_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('curry_items', function (Blueprint $table) {
            $table->foreignId('curry_category_id')
                ->nullable()
                ->constrained('curry_categories')
                ->nullOnDelete();
        });
    }
};
