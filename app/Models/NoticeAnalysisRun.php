<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class NoticeAnalysisRun extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'status',
        'requested_by_user_id',
        'scope_id',
        'issue_date',
        'locale',
        'total_notices',
        'processed_notices',
        'sent_count',
        'ignored_count',
        'failed_count',
        'model',
        'system_prompt_path',
        'user_prompt_path',
        'started_at',
        'finished_at',
        'company_runs_dispatched_at',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'company_runs_dispatched_at' => 'datetime',
        ];
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(NoticeAnalysis::class);
    }

    public function companyRuns(): HasMany
    {
        return $this->hasMany(CompanyNoticeAnalysisRun::class);
    }

    public function progressLabel(): string
    {
        return sprintf('%d / %d', $this->processed_notices, $this->total_notices);
    }

    public function refreshProgress(): void
    {
        $totals = $this->analyses()
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw("SUM(CASE WHEN status IN ('done', 'failed') THEN 1 ELSE 0 END) as processed_count")
            ->selectRaw("SUM(CASE WHEN decision = 'send' THEN 1 ELSE 0 END) as send_count")
            ->selectRaw("SUM(CASE WHEN decision = 'ignore' THEN 1 ELSE 0 END) as ignore_count")
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
            'sent_count' => (int) ($totals->send_count ?? 0),
            'ignored_count' => (int) ($totals->ignore_count ?? 0),
            'failed_count' => $failed,
            'finished_at' => $finishedAt,
        ])->save();

        if (
            in_array($status, [self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_ERRORS], true)
            && $this->company_runs_dispatched_at === null
        ) {
            try {
                app(\App\Services\CompanyNoticeAnalysisRunner::class)
                    ->dispatchForNoticeAnalysisRun($this, strictPrompt: false);
            } catch (\Throwable $exc) {
                report($exc);
            }
        }
    }
}
