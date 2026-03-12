<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scope_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('data_type');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('feature_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('label');
            $table->text('help_text')->nullable();

            $table->unique(['feature_id', 'locale']);
        });

        Schema::create('feature_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['feature_id', 'code']);
        });

        Schema::create('feature_option_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feature_option_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('label');

            $table->unique(['feature_option_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_option_translations');
        Schema::dropIfExists('feature_options');
        Schema::dropIfExists('feature_translations');
        Schema::dropIfExists('features');
    }
};
