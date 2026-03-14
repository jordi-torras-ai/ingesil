<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class CompanyNoticeAnalysisRun extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    protected $fillable = [
        'notice_analysis_run_id',
        'company_id',
        'company_scope_subscription_id',
        'locale',
        'status',
        'total_notices',
        'processed_notices',
        'relevant_count',
        'not_relevant_count',
        'failed_count',
        'model',
        'system_prompt_path',
        'user_prompt_path',
        'started_at',
        'finished_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function noticeAnalysisRun(): BelongsTo
    {
        return $this->belongsTo(NoticeAnalysisRun::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companyScopeSubscription(): BelongsTo
    {
        return $this->belongsTo(CompanyScopeSubscription::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(CompanyNoticeAnalysis::class);
    }

    public function refreshProgress(): void
    {
        $totals = $this->analyses()
            ->where('is_applicable', true)
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw("SUM(CASE WHEN status IN ('done', 'failed') THEN 1 ELSE 0 END) as processed_count")
            ->selectRaw("SUM(CASE WHEN decision = 'relevant' THEN 1 ELSE 0 END) as relevant_count")
            ->selectRaw("SUM(CASE WHEN decision = 'not_relevant' THEN 1 ELSE 0 END) as not_relevant_count")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->first();

        if (! $totals) {
            return;
        }

        $total = (int) ($totals->total_count ?? 0);
        $processed = (int) ($totals->processed_count ?? 0);
        $failed = (int) ($totals->failed_count ?? 0);

        $status = self::STATUS_PROCESSING;
        $finishedAt = null;

        if ($total === 0) {
            $status = self::STATUS_COMPLETED;
            $finishedAt = Carbon::now();
        } elseif ($processed >= $total) {
            $status = $failed > 0 ? self::STATUS_COMPLETED_WITH_ERRORS : self::STATUS_COMPLETED;
            $finishedAt = Carbon::now();
        }

        $this->forceFill([
            'status' => $status,
            'total_notices' => $total,
            'processed_notices' => $processed,
            'relevant_count' => (int) ($totals->relevant_count ?? 0),
            'not_relevant_count' => (int) ($totals->not_relevant_count ?? 0),
            'failed_count' => $failed,
            'finished_at' => $finishedAt,
        ])->save();
    }
}
