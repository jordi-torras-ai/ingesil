<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notices', function (Blueprint $table): void {
            $table->json('embedding')->nullable()->after('extra_info');
            $table->string('embedding_model')->nullable()->after('embedding');
            $table->timestamp('embedding_updated_at')->nullable()->after('embedding_model');
        });
    }

    public function down(): void
    {
        Schema::table('notices', function (Blueprint $table): void {
            $table->dropColumn(['embedding', 'embedding_model', 'embedding_updated_at']);
        });
    }
};

