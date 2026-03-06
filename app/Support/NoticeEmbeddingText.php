<?php

namespace App\Support;

use App\Models\Notice;
use Illuminate\Support\Str;

class NoticeEmbeddingText
{
    public static function build(Notice $notice, ?int $maxChars = null): string
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

        $extraInfo = trim((string) ($notice->extra_info ?? ''));
        if ($extraInfo !== '') {
            $parts[] = "Extra info:\n".$extraInfo;
        }

        $text = trim(implode("\n", $parts));
        if ($text === '') {
            return '';
        }

        if (($maxChars !== null) && ($maxChars > 0) && (Str::length($text) > $maxChars)) {
            return Str::substr($text, 0, $maxChars);
        }

        return $text;
    }
}
