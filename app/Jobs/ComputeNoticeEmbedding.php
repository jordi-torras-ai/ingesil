<?php

namespace App\Jobs;

use App\Models\Notice;
use App\Services\OpenAIEmbeddings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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

        $input = $this->buildInput($notice);
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

    private function buildInput(Notice $notice): string
    {
        $parts = [];

        $title = trim((string) $notice->title);
        if ($title !== '') {
            $parts[] = 'Title: '.$title;
        }

        $category = trim((string) $notice->category);
        if ($category !== '') {
            $parts[] = 'Category: '.$category;
        }

        $department = trim((string) $notice->department);
        if ($department !== '') {
            $parts[] = 'Department: '.$department;
        }

        $content = trim((string) ($notice->content ?? ''));
        if ($content !== '') {
            $parts[] = "Content:\n".$content;
        }

        $text = trim(implode("\n", $parts));
        if ($text === '') {
            return '';
        }

        $maxChars = (int) config('services.openai.embedding_input_max_chars', 8000);
        if ($maxChars > 0 && Str::length($text) > $maxChars) {
            $text = Str::substr($text, 0, $maxChars);
        }

        return $text;
    }
}
