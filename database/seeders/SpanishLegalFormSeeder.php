<?php

namespace Database\Seeders;

use App\Models\SpanishLegalForm;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use JsonException;

class SpanishLegalFormSeeder extends Seeder
{
    /**
     * @throws JsonException
     */
    public function run(): void
    {
        $rows = json_decode(
            file_get_contents(database_path('data/spanish_legal_forms.json')) ?: '[]',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $now = Carbon::now();

        $payload = array_map(
            fn (array $row): array => [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'sort_order' => (int) $row['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $rows,
        );

        SpanishLegalForm::query()->upsert(
            $payload,
            ['code'],
            ['name', 'sort_order', 'updated_at'],
        );
    }
}
