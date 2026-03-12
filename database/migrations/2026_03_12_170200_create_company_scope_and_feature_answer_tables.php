<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_scope', function (Blueprint $table): void {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scope_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['company_id', 'scope_id']);
        });

        Schema::create('company_feature_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_option_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('value_boolean')->nullable();
            $table->text('value_text')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_feature_answers');
        Schema::dropIfExists('company_scope');
    }
};
