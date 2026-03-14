<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_scope_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scope_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->timestamps();

            $table->unique(['company_id', 'scope_id', 'locale'], 'company_scope_subscriptions_unique');
            $table->index(['company_id', 'locale'], 'company_scope_subscriptions_company_locale_index');
        });

        $now = now();
        $companyScopes = DB::table('company_scope')->get(['company_id', 'scope_id', 'created_at', 'updated_at']);
        foreach ($companyScopes as $companyScope) {
            DB::table('company_scope_subscriptions')->updateOrInsert(
                [
                    'company_id' => $companyScope->company_id,
                    'scope_id' => $companyScope->scope_id,
                    'locale' => 'en',
                ],
                [
                    'created_at' => $companyScope->created_at ?? $now,
                    'updated_at' => $companyScope->updated_at ?? $now,
                ],
            );
        }

        Schema::table('company_notice_analysis_runs', function (Blueprint $table): void {
            $table->foreignId('company_scope_subscription_id')
                ->nullable()
                ->after('company_id')
                ->constrained('company_scope_subscriptions')
                ->nullOnDelete();
            $table->string('locale', 5)->nullable()->after('company_scope_subscription_id');
        });

        Schema::table('company_notice_analysis_runs', function (Blueprint $table): void {
            $table->dropUnique('company_notice_analysis_runs_run_company_unique');
        });

        $runs = DB::table('company_notice_analysis_runs')
            ->join('notice_analysis_runs', 'notice_analysis_runs.id', '=', 'company_notice_analysis_runs.notice_analysis_run_id')
            ->select(
                'company_notice_analysis_runs.id',
                'company_notice_analysis_runs.company_id',
                'notice_analysis_runs.scope_id',
                'notice_analysis_runs.locale as run_locale',
            )
            ->orderBy('company_notice_analysis_runs.id')
            ->get();

        foreach ($runs as $run) {
            $locale = trim((string) ($run->run_locale ?: 'en')) ?: 'en';

            $subscriptionId = DB::table('company_scope_subscriptions')
                ->where('company_id', $run->company_id)
                ->where('scope_id', $run->scope_id)
                ->where('locale', $locale)
                ->value('id');

            if ($subscriptionId === null) {
                $subscriptionId = DB::table('company_scope_subscriptions')
                    ->where('company_id', $run->company_id)
                    ->where('scope_id', $run->scope_id)
                    ->orderByRaw("CASE WHEN locale = 'en' THEN 0 ELSE 1 END")
                    ->value('id');
            }

            DB::table('company_notice_analysis_runs')
                ->where('id', $run->id)
                ->update([
                    'company_scope_subscription_id' => $subscriptionId,
                    'locale' => $locale,
                ]);
        }

        Schema::table('company_notice_analysis_runs', function (Blueprint $table): void {
            $table->unique(
                ['notice_analysis_run_id', 'company_scope_subscription_id'],
                'company_notice_analysis_runs_run_subscription_unique'
            );
            $table->index(['company_id', 'locale'], 'company_notice_analysis_runs_company_locale_index');
        });

        Schema::dropIfExists('company_scope');
    }

    public function down(): void
    {
        Schema::create('company_scope', function (Blueprint $table): void {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scope_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['company_id', 'scope_id']);
        });

        $subscriptions = DB::table('company_scope_subscriptions')
            ->select('company_id', 'scope_id')
            ->distinct()
            ->get();

        foreach ($subscriptions as $subscription) {
            DB::table('company_scope')->updateOrInsert(
                [
                    'company_id' => $subscription->company_id,
                    'scope_id' => $subscription->scope_id,
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        Schema::table('company_notice_analysis_runs', function (Blueprint $table): void {
            $table->dropUnique('company_notice_analysis_runs_run_subscription_unique');
            $table->dropIndex('company_notice_analysis_runs_company_locale_index');
            $table->dropConstrainedForeignId('company_scope_subscription_id');
            $table->dropColumn('locale');
            $table->unique(['notice_analysis_run_id', 'company_id'], 'company_notice_analysis_runs_run_company_unique');
        });

        Schema::dropIfExists('company_scope_subscriptions');
    }
};
