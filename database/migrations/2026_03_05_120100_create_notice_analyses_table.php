<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notice_analysis_run_id')->constrained('notice_analysis_runs')->cascadeOnDelete();
            $table->foreignId('notice_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued')->index();
            $table->string('decision')->nullable()->index();
            $table->text('reason')->nullable();
            $table->string('vector')->nullable();
            $table->string('scope')->nullable();
            $table->text('title')->nullable();
            $table->text('summary')->nullable();
            $table->text('repealed_provisions')->nullable();
            $table->text('link')->nullable();
            $table->json('raw_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('model')->nullable();
            $table->timestamps();

            $table->unique(['notice_analysis_run_id', 'notice_id'], 'notice_analyses_run_notice_unique');
            $table->index(['notice_analysis_run_id', 'status'], 'notice_analyses_run_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_analyses');
    }
};
