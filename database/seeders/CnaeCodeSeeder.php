<?php

namespace Database\Seeders;

use App\Models\CnaeCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use JsonException;

class CnaeCodeSeeder extends Seeder
{
    /**
     * @throws JsonException
     */
    public function run(): void
    {
        $rows = json_decode(
            file_get_contents(database_path('data/cnae_2009_classes.json')) ?: '[]',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $now = Carbon::now();

        $payload = array_map(
            fn (array $row): array => [
                'code' => (string) $row['code'],
                'integrated_code' => (string) $row['integrated_code'],
                'title' => (string) $row['title'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $rows,
        );

        CnaeCode::query()->upsert(
            $payload,
            ['code'],
            ['integrated_code', 'title', 'updated_at'],
        );
    }
}
