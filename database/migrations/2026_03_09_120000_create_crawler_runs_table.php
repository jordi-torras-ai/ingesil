<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawler_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_slug', 120);
            $table->date('issue_date')->nullable();
            $table->string('run_id')->unique();
            $table->string('mode', 20)->default('headless');
            $table->string('triggered_by', 40)->default('manual');
            $table->string('status', 20)->default('running');
            $table->integer('exit_code')->nullable();
            $table->string('run_directory', 2048);
            $table->string('log_path', 2048);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source_slug', 'issue_date']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawler_runs');
    }
};
