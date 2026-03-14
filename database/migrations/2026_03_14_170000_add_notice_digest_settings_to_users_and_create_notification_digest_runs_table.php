<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('notice_digest_frequency', 20)->default('weekly')->after('locale');
            $table->boolean('notify_if_pending_tasks')->default(true)->after('notice_digest_frequency');
            $table->boolean('notify_if_new_relevant_notices')->default(true)->after('notify_if_pending_tasks');
            $table->timestamp('last_notice_digest_sent_at')->nullable()->after('notify_if_new_relevant_notices');
        });

        DB::table('users')->update([
            'notice_digest_frequency' => 'weekly',
            'notify_if_pending_tasks' => true,
            'notify_if_new_relevant_notices' => true,
        ]);

        Schema::create('notification_digest_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('frequency', 20);
            $table->string('locale', 10);
            $table->timestamp('window_started_at')->nullable();
            $table->timestamp('window_ended_at')->nullable();
            $table->unsignedInteger('pending_tasks_count')->default(0);
            $table->unsignedInteger('new_relevant_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->string('status', 20)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_digest_runs');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'notice_digest_frequency',
                'notify_if_pending_tasks',
                'notify_if_new_relevant_notices',
                'last_notice_digest_sent_at',
            ]);
        });
    }
};
