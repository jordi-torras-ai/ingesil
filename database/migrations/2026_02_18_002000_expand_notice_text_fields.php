<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notices ALTER COLUMN title TYPE TEXT');
        DB::statement('ALTER TABLE notices ALTER COLUMN category TYPE TEXT');
        DB::statement('ALTER TABLE notices ALTER COLUMN department TYPE TEXT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notices ALTER COLUMN title TYPE VARCHAR(255)');
        DB::statement('ALTER TABLE notices ALTER COLUMN category TYPE VARCHAR(255)');
        DB::statement('ALTER TABLE notices ALTER COLUMN department TYPE VARCHAR(255)');
    }
};
