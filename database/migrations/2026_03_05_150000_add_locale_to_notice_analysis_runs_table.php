<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notice_analysis_runs', function (Blueprint $table): void {
            $table->string('locale', 5)->default('en')->index()->after('issue_date');
        });
    }

    public function down(): void
    {
        Schema::table('notice_analysis_runs', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
