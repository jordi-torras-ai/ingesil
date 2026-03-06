<?php

namespace App\Observers;

use App\Jobs\ComputeNoticeEmbedding;
use App\Models\Notice;

class NoticeObserver
{
    public function saved(Notice $notice): void
    {
        if (! config('services.openai.api_key')) {
            return;
        }

        $contentFieldsChanged = $notice->wasChanged(['title', 'category', 'department', 'content']);
        if (! $contentFieldsChanged) {
            return;
        }

        ComputeNoticeEmbedding::dispatch($notice->id);
    }
}

