<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cnae_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 4)->unique();
            $table->string('integrated_code', 8)->unique();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cnae_codes');
    }
};
