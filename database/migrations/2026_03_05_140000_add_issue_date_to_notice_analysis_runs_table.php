<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notice_analysis_runs', function (Blueprint $table): void {
            $table->date('issue_date')->nullable()->index()->after('requested_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('notice_analysis_runs', function (Blueprint $table): void {
            $table->dropColumn('issue_date');
        });
    }
};
