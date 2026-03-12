<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class CompanyNoticeAnalysis extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public const DECISION_RELEVANT = 'relevant';
    public const DECISION_NOT_RELEVANT = 'not_relevant';

    protected $fillable = [
        'company_notice_analysis_run_id',
        'notice_analysis_id',
        'status',
        'is_applicable',
        'decision',
        'reason',
        'requirements',
        'compliance_due_at',
        'confirmed_relevant',
        'compliance',
        'compliance_evaluation',
        'compliance_date',
        'action_plan',
        'raw_response',
        'started_at',
        'processed_at',
        'model',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'is_applicable' => 'boolean',
            'compliance_due_at' => 'date',
            'confirmed_relevant' => 'boolean',
            'compliance' => 'boolean',
            'compliance_date' => 'date',
            'raw_response' => 'array',
            'started_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $analysis): void {
            if ($analysis->compliance === true && $analysis->compliance_date === null) {
                $analysis->compliance_date = Carbon::today();
            }
        });

        static::updated(function (self $analysis): void {
            $analysis->recordEventIfNeeded();
        });
    }

    public function companyNoticeAnalysisRun(): BelongsTo
    {
        return $this->belongsTo(CompanyNoticeAnalysisRun::class);
    }

    public function noticeAnalysis(): BelongsTo
    {
        return $this->belongsTo(NoticeAnalysis::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CompanyNoticeAnalysisEvent::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    private function recordEventIfNeeded(): void
    {
        $dirty = array_keys($this->getChanges());
        $dirty = array_values(array_diff($dirty, ['updated_at']));

        if ($dirty === []) {
            return;
        }

        $userFields = [
            'confirmed_relevant',
            'compliance',
            'compliance_evaluation',
            'compliance_date',
            'action_plan',
        ];

        $trackedFields = [
            'is_applicable',
            'decision',
            'reason',
            'requirements',
            'compliance_due_at',
            'confirmed_relevant',
            'compliance',
            'compliance_evaluation',
            'compliance_date',
            'action_plan',
            'error_message',
        ];

        $changes = [];
        foreach (array_intersect($dirty, $trackedFields) as $field) {
            $changes[$field] = [
                'old' => $this->getOriginal($field),
                'new' => $this->getAttribute($field),
            ];
        }

        if ($changes === []) {
            return;
        }

        $eventType = auth()->check() && array_intersect(array_keys($changes), $userFields) !== []
            ? CompanyNoticeAnalysisEvent::TYPE_USER_UPDATED
            : CompanyNoticeAnalysisEvent::TYPE_AI_PROCESSED;

        $this->events()->create([
            'user_id' => auth()->id(),
            'event_type' => $eventType,
            'changes' => $changes,
        ]);
    }
}
