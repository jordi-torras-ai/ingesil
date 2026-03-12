<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_SCOPE_CODE = 'environment_industrial_safety';

    public function up(): void
    {
        $defaultScopeId = $this->ensureDefaultScopeId();

        Schema::table('notice_analysis_runs', function (Blueprint $table) use ($defaultScopeId): void {
            $table->foreignId('scope_id')
                ->nullable()
                ->default($defaultScopeId)
                ->after('requested_by_user_id')
                ->constrained('scopes')
                ->cascadeOnDelete();
        });

        DB::table('notice_analysis_runs')
            ->whereNull('scope_id')
            ->update(['scope_id' => $defaultScopeId]);

        Schema::table('notice_analysis_runs', function (Blueprint $table): void {
            $table->index(['scope_id', 'issue_date', 'locale'], 'notice_analysis_runs_scope_date_locale_idx');
        });

        DB::statement('ALTER TABLE notice_analysis_runs ALTER COLUMN scope_id SET NOT NULL');
        DB::statement('ALTER TABLE notice_analysis_runs ALTER COLUMN scope_id DROP DEFAULT');

        Schema::table('notice_analyses', function (Blueprint $table): void {
            $table->string('jurisdiction')->nullable()->after('vector');
        });

        DB::table('notice_analyses')->update([
            'jurisdiction' => DB::raw('scope'),
        ]);

        Schema::table('notice_analyses', function (Blueprint $table): void {
            $table->dropColumn('scope');
            $table->index('jurisdiction');
        });
    }

    public function down(): void
    {
        Schema::table('notice_analyses', function (Blueprint $table): void {
            $table->string('scope')->nullable()->after('vector');
        });

        DB::table('notice_analyses')->update([
            'scope' => DB::raw('jurisdiction'),
        ]);

        Schema::table('notice_analyses', function (Blueprint $table): void {
            $table->dropIndex(['jurisdiction']);
            $table->dropColumn('jurisdiction');
        });

        Schema::table('notice_analysis_runs', function (Blueprint $table): void {
            $table->dropIndex('notice_analysis_runs_scope_date_locale_idx');
            $table->dropConstrainedForeignId('scope_id');
        });
    }

    private function ensureDefaultScopeId(): int
    {
        $existingId = DB::table('scopes')
            ->where('code', self::DEFAULT_SCOPE_CODE)
            ->value('id');

        if ($existingId !== null) {
            return (int) $existingId;
        }

        $now = now();

        return (int) DB::table('scopes')->insertGetId([
            'code' => self::DEFAULT_SCOPE_CODE,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
