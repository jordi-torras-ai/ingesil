<?php

namespace App\Models;

use App\Models\Concerns\LogsAdminActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CompanyScopeSubscription extends Model
{
    use LogsAdminActivity;

    protected $fillable = [
        'company_id',
        'scope_id',
        'locale',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $subscription): void {
            $subscription->locale = Str::lower((string) $subscription->locale);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function noticeAnalysisRuns(): HasMany
    {
        return $this->hasMany(CompanyNoticeAnalysisRun::class);
    }

    public function localeLabel(): string
    {
        return User::localeOptions()[$this->locale] ?? strtoupper((string) $this->locale);
    }

    protected function activityLogAttributes(): array
    {
        return [
            'company_id',
            'scope_id',
            'locale',
        ];
    }
}
