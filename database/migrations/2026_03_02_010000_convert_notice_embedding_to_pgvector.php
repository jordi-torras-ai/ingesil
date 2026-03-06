<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $context = DB::selectOne('SELECT current_user AS u, current_database() AS db');
        $currentUser = is_object($context) && isset($context->u) ? (string) $context->u : '<unknown>';
        $currentDb = is_object($context) && isset($context->db) ? (string) $context->db : '<unknown>';

        $available = DB::selectOne("SELECT default_version, installed_version FROM pg_available_extensions WHERE name = 'vector'");
        $availableDefault = is_object($available) && isset($available->default_version) ? (string) $available->default_version : '';

        $installed = (bool) DB::scalar("SELECT 1 FROM pg_extension WHERE extname = 'vector' LIMIT 1");
        if (! $installed) {
            if (! $availableDefault) {
                throw new \RuntimeException(
                    "pgvector extension is required, but it's not available on this Postgres server.\n".
                    "Install pgvector (server-side) so it appears in pg_available_extensions, then run:\n".
                    "  CREATE EXTENSION vector;\n".
                    "Database: {$currentDb}\n".
                    "User: {$currentUser}"
                );
            }

            try {
                DB::statement('CREATE EXTENSION vector');
            } catch (QueryException $exc) {
                throw new \RuntimeException(
                    "pgvector extension is installed on the server (available default={$availableDefault}) but isn't enabled in this database.\n".
                    "Enable it as a superuser (or ask your DBA) and then re-run migrations:\n".
                    "  CREATE EXTENSION vector;\n".
                    "Database: {$currentDb}\n".
                    "User: {$currentUser}",
                    previous: $exc,
                );
            }
        }

        $dimensions = (int) (config('services.openai.embedding_dimensions') ?: 1536);
        if ($dimensions <= 0) {
            $dimensions = 1536;
        }

        DB::statement("ALTER TABLE notices ADD COLUMN IF NOT EXISTS embedding_vector vector({$dimensions})");

        if (Schema::hasColumn('notices', 'embedding')) {
            DB::statement(
                "UPDATE notices
                 SET embedding_vector = (embedding::text)::vector
                 WHERE embedding_vector IS NULL AND embedding IS NOT NULL"
            );
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS notices_embedding_vector_ivfflat_cosine_idx
             ON notices USING ivfflat (embedding_vector vector_cosine_ops) WITH (lists = 100)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notices_embedding_vector_ivfflat_cosine_idx');
        DB::statement('ALTER TABLE notices DROP COLUMN IF EXISTS embedding_vector');
    }
};
