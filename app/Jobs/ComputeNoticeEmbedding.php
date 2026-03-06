<?php

namespace App\Jobs;

use App\Models\Notice;
use App\Support\NoticeEmbeddingText;
use App\Services\OpenAIEmbeddings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

class ComputeNoticeEmbedding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 600, 1800, 3600];

    public function __construct(public int $noticeId)
    {
        $this->onQueue((string) config('services.openai.embedding_queue', 'default'));
    }

    public function handle(OpenAIEmbeddings $embeddings): void
    {
        /** @var Notice|null $notice */
        $notice = Notice::query()->find($this->noticeId);
        if (! $notice) {
            return;
        }

        $maxChars = (int) config('services.openai.embedding_input_max_chars', 8000);
        $input = NoticeEmbeddingText::build($notice, $maxChars);
        if ($input === '') {
            return;
        }

        $vector = $embeddings->embed($input);

        $notice->embedding = $vector;
        if (Schema::hasColumn('notices', 'embedding_vector')) {
            $notice->embedding_vector = $vector;
        }
        $notice->embedding_model = (string) config('services.openai.embedding_model', 'text-embedding-3-small');
        $notice->embedding_updated_at = Carbon::now();
        $notice->save();
    }
}
