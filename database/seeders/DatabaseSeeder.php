<?php

namespace Database\Seeders;

use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        $admin = User::query()->firstOrNew([
            'email' => 'jtorras@guanta.ai',
        ]);
        $admin->name = 'Jordi Torras';
        $admin->role = User::ROLE_ADMIN;
        $admin->locale = User::LOCALE_EN;
        $admin->email_verified_at = now();
        if (! $admin->exists) {
            $admin->password = Hash::make('T_xefu_laye_popo_9471');
        }
        $admin->save();

        $regular = User::query()->firstOrNew([
            'email' => 'test@example.com',
        ]);
        $regular->name = 'Test User';
        $regular->role = User::ROLE_REGULAR;
        $regular->locale = User::LOCALE_EN;
        $regular->email_verified_at = now();
        if (! $regular->exists) {
            $regular->password = Hash::make('password');
        }
        $regular->save();

        Source::query()->updateOrCreate([
            'slug' => 'dogc',
        ], [
            'name' => 'DOGC',
            'description' => 'Diari Oficial de la Generalitat de Catalunya',
            'base_url' => 'https://dogc.gencat.cat/ca',
            'start_at' => '2026-01-01',
            'comments' => null,
        ]);

        Source::query()->updateOrCreate([
            'slug' => 'boe',
        ], [
            'name' => 'BOE',
            'description' => 'Boletín Oficial del Estado (Spain)',
            'base_url' => 'https://www.boe.es/buscar/boe.php',
            'start_at' => '2026-01-01',
            'comments' => null,
        ]);

        Source::query()->updateOrCreate([
            'slug' => 'ojeu',
        ], [
            'name' => 'OJEU',
            'description' => 'Official Journal of the European Union',
            'base_url' => 'https://eur-lex.europa.eu',
            'start_at' => '2026-01-01',
            'comments' => null,
        ]);

        Source::query()->updateOrCreate([
            'slug' => 'bopb',
        ], [
            'name' => 'BOPB',
            'description' => 'Butlletí Oficial de la Província de Barcelona',
            'base_url' => 'https://bop.diba.cat',
            'start_at' => '2026-01-01',
            'comments' => null,
        ]);
    }
}
