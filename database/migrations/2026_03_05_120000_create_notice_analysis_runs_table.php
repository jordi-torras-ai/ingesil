<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_analysis_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('queued')->index();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_notices')->default(0);
            $table->unsignedInteger('processed_notices')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('ignored_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('model')->nullable();
            $table->string('system_prompt_path')->nullable();
            $table->string('user_prompt_path')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_analysis_runs');
    }
};
