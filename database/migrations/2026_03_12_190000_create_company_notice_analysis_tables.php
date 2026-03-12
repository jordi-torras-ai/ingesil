<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notice_analysis_runs', function (Blueprint $table): void {
            $table->timestamp('company_runs_dispatched_at')->nullable()->after('finished_at');
        });

        Schema::create('company_notice_analysis_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notice_analysis_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('total_notices')->default(0);
            $table->unsignedInteger('processed_notices')->default(0);
            $table->unsignedInteger('relevant_count')->default(0);
            $table->unsignedInteger('not_relevant_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('model')->nullable();
            $table->string('system_prompt_path')->nullable();
            $table->string('user_prompt_path')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['notice_analysis_run_id', 'company_id'], 'company_notice_analysis_runs_run_company_unique');
            $table->index(['company_id', 'status']);
        });

        Schema::create('company_notice_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_notice_analysis_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notice_analysis_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('queued');
            $table->boolean('is_applicable')->default(true);
            $table->string('decision', 32)->nullable();
            $table->text('reason')->nullable();
            $table->text('requirements')->nullable();
            $table->date('compliance_due_at')->nullable();
            $table->boolean('confirmed_relevant')->nullable();
            $table->boolean('compliance')->nullable();
            $table->text('compliance_evaluation')->nullable();
            $table->date('compliance_date')->nullable();
            $table->text('action_plan')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('model')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['company_notice_analysis_run_id', 'notice_analysis_id'], 'company_notice_analyses_run_analysis_unique');
            $table->index(['decision', 'status']);
            $table->index(['is_applicable', 'compliance']);
        });

        Schema::create('company_notice_analysis_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_notice_analysis_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 64);
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_notice_analysis_id', 'created_at'], 'company_notice_analysis_events_analysis_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_notice_analysis_events');
        Schema::dropIfExists('company_notice_analyses');
        Schema::dropIfExists('company_notice_analysis_runs');

        Schema::table('notice_analysis_runs', function (Blueprint $table): void {
            $table->dropColumn('company_runs_dispatched_at');
        });
    }
};
