<?php

namespace App\Models;

use App\Models\Concerns\LogsAdminActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Locale;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory;
    use LogsAdminActivity;

    public const COUNTRY_SPAIN = 'es';
    public const DEFAULT_CURRENCY = 'EUR';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'country',
        'spanish_legal_form_id',
        'cnae_code_id',
        'currency',
        'yearly_revenue',
        'address',
        'total_assets',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'yearly_revenue' => 'decimal:2',
            'total_assets' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Company $company): void {
            $company->country = Str::lower((string) ($company->country ?: self::COUNTRY_SPAIN));
            $company->currency = Str::upper((string) ($company->currency ?: self::DEFAULT_CURRENCY));

            if ($company->country !== self::COUNTRY_SPAIN) {
                $company->spanish_legal_form_id = null;
                $company->cnae_code_id = null;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public static function countryOptions(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        $options = [];

        foreach (self::countryCodes() as $countryCode) {
            $name = Locale::getDisplayRegion('und_'.strtoupper($countryCode), $locale);

            $options[$countryCode] = $name !== '' ? $name : strtoupper($countryCode);
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /**
     * @return list<string>
     */
    private static function countryCodes(): array
    {
        return [
            'ad', 'ae', 'af', 'ag', 'ai', 'al', 'am', 'ao', 'ar', 'at', 'au', 'az',
            'ba', 'bb', 'bd', 'be', 'bf', 'bg', 'bh', 'bi', 'bj', 'bn', 'bo', 'br',
            'bs', 'bt', 'bw', 'by', 'bz', 'ca', 'cd', 'cf', 'cg', 'ch', 'ci', 'cl',
            'cm', 'cn', 'co', 'cr', 'cu', 'cv', 'cy', 'cz', 'de', 'dj', 'dk', 'dm',
            'do', 'dz', 'ec', 'ee', 'eg', 'er', 'es', 'et', 'fi', 'fj', 'fm', 'fr',
            'ga', 'gb', 'gd', 'ge', 'gh', 'gm', 'gn', 'gq', 'gr', 'gt', 'gw', 'gy',
            'hk', 'hn', 'hr', 'ht', 'hu', 'id', 'ie', 'il', 'in', 'iq', 'ir', 'is',
            'it', 'jm', 'jo', 'jp', 'ke', 'kg', 'kh', 'ki', 'km', 'kn', 'kr', 'kw',
            'kz', 'la', 'lb', 'lc', 'li', 'lk', 'lr', 'ls', 'lt', 'lu', 'lv', 'ly',
            'ma', 'mc', 'md', 'me', 'mg', 'mk', 'ml', 'mm', 'mn', 'mr', 'mt', 'mu',
            'mv', 'mw', 'mx', 'my', 'mz', 'na', 'ne', 'ng', 'ni', 'nl', 'no', 'np',
            'nz', 'om', 'pa', 'pe', 'pg', 'ph', 'pk', 'pl', 'pt', 'py', 'qa', 'ro',
            'rs', 'ru', 'rw', 'sa', 'sb', 'sc', 'sd', 'se', 'sg', 'si', 'sk', 'sl',
            'sm', 'sn', 'so', 'sr', 'ss', 'st', 'sv', 'sy', 'sz', 'td', 'tg', 'th',
            'tj', 'tl', 'tm', 'tn', 'to', 'tr', 'tt', 'tv', 'tw', 'tz', 'ua', 'ug',
            'us', 'uy', 'uz', 'va', 'vc', 've', 'vn', 'vu', 'ws', 'ye', 'za', 'zm',
            'zw',
        ];
    }

    public function spanishLegalForm(): BelongsTo
    {
        return $this->belongsTo(SpanishLegalForm::class);
    }

    public function cnaeCode(): BelongsTo
    {
        return $this->belongsTo(CnaeCode::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function scopes(): BelongsToMany
    {
        return $this->belongsToMany(Scope::class, 'company_scope_subscriptions')
            ->withPivot('locale')
            ->withTimestamps();
    }

    public function scopeSubscriptions(): HasMany
    {
        return $this->hasMany(CompanyScopeSubscription::class);
    }

    public function featureAnswers(): HasMany
    {
        return $this->hasMany(CompanyFeatureAnswer::class);
    }

    public function noticeAnalysisRuns(): HasMany
    {
        return $this->hasMany(CompanyNoticeAnalysisRun::class);
    }

    public function noticeAnalyses(): HasMany
    {
        return $this->hasManyThrough(
            CompanyNoticeAnalysis::class,
            CompanyNoticeAnalysisRun::class
        );
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'country',
            'spanish_legal_form_id',
            'cnae_code_id',
            'currency',
            'yearly_revenue',
            'address',
            'total_assets',
        ];
    }
}
