<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scopes', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('scope_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scope_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name');
            $table->text('description')->nullable();

            $table->unique(['scope_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scope_translations');
        Schema::dropIfExists('scopes');
    }
};
