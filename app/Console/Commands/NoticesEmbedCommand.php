<?php

namespace App\Console\Commands;

use App\Jobs\ComputeNoticeEmbedding;
use App\Models\Notice;
use App\Services\OpenAIEmbeddings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class NoticesEmbedCommand extends Command
{
    protected $signature = 'notices:embed
        {--stale : Also (re)embed notices updated since last embedding}
        {--limit=0 : Max notices to enqueue/process}
        {--chunk=200 : Chunk size for scanning}
        {--sync : Compute embeddings inline (no queue)}
        {--queue=default : Queue name (dispatch only)}';

    protected $description = 'Compute OpenAI embeddings for notices missing them (and optionally stale ones).';

    public function handle(OpenAIEmbeddings $embeddings): int
    {
        if (! config('services.openai.api_key')) {
            $this->error('Missing OPENAI_API_KEY (services.openai.api_key).');
            return self::FAILURE;
        }

        $includeStale = (bool) $this->option('stale');
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(10, (int) $this->option('chunk'));
        $sync = (bool) $this->option('sync');
        $queue = (string) $this->option('queue');

        $baseQuery = Notice::query()
            ->select(['id'])
            ->where(function (Builder $q) use ($includeStale): void {
                $embeddingColumn = Schema::hasColumn('notices', 'embedding_vector') ? 'embedding_vector' : 'embedding';

                $q->whereNull($embeddingColumn);

                if ($includeStale) {
                    $q->orWhereNull('embedding_updated_at')
                        ->orWhereColumn('embedding_updated_at', '<', 'updated_at');
                }
            })
            ->orderBy('id');

        $processed = 0;
        $baseQuery->chunkById($chunk, function ($notices) use (
            &$processed,
            $limit,
            $sync,
            $queue,
            $embeddings,
        ): bool {
            foreach ($notices as $notice) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }

                if ($sync) {
                    /** @var Notice $full */
                    $full = Notice::query()->findOrFail($notice->id);
                    $input = trim(implode("\n", array_filter([
                        $full->title ? 'Title: '.$full->title : null,
                        $full->category ? 'Category: '.$full->category : null,
                        $full->department ? 'Department: '.$full->department : null,
                        $full->content ? "Content:\n".$full->content : null,
                    ])));

                    if ($input !== '') {
                        $vector = $embeddings->embed($input);
                        $full->embedding = $vector;
                        if (Schema::hasColumn('notices', 'embedding_vector')) {
                            $full->embedding_vector = $vector;
                        }
                        $full->embedding_model = (string) config('services.openai.embedding_model', 'text-embedding-3-small');
                        $full->embedding_updated_at = Carbon::now();
                        $full->save();
                    }
                } else {
                    ComputeNoticeEmbedding::dispatch((int) $notice->id)->onQueue($queue);
                }

                $processed++;
            }

            $this->info("Processed: {$processed}");
            return true;
        });

        $this->info("Done. Total processed: {$processed}");
        return self::SUCCESS;
    }
}
