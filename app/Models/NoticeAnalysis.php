<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NoticeAnalysis extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'notice_analysis_run_id',
        'notice_id',
        'status',
        'decision',
        'reason',
        'vector',
        'jurisdiction',
        'title',
        'summary',
        'repealed_provisions',
        'link',
        'raw_response',
        'error_message',
        'started_at',
        'processed_at',
        'model',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_response' => 'array',
            'started_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function noticeAnalysisRun(): BelongsTo
    {
        return $this->belongsTo(NoticeAnalysisRun::class);
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }

    public function companyAnalyses(): HasMany
    {
        return $this->hasMany(CompanyNoticeAnalysis::class);
    }
}
