<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('country', 2)->default(Company::COUNTRY_SPAIN);
            $table->foreignId('spanish_legal_form_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cnae_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3)->default(Company::DEFAULT_CURRENCY);
            $table->decimal('yearly_revenue', 15, 2)->nullable();
            $table->text('address')->nullable();
            $table->decimal('total_assets', 15, 2)->nullable();
            $table->timestamps();

            $table->index('country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
