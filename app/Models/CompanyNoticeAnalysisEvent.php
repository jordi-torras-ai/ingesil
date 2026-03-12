<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyNoticeAnalysisEvent extends Model
{
    public const TYPE_AI_PROCESSED = 'ai_processed';
    public const TYPE_USER_UPDATED = 'user_updated';

    public $timestamps = false;

    protected $fillable = [
        'company_notice_analysis_id',
        'user_id',
        'event_type',
        'changes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function companyNoticeAnalysis(): BelongsTo
    {
        return $this->belongsTo(CompanyNoticeAnalysis::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
