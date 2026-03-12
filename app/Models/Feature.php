<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Feature extends Model
{
    use HasFactory;

    public const DATA_TYPE_BOOLEAN = 'boolean';
    public const DATA_TYPE_TEXT = 'text';
    public const DATA_TYPE_SINGLE_CHOICE = 'single_choice';

    protected $fillable = [
        'scope_id',
        'code',
        'data_type',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(FeatureTranslation::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(FeatureOption::class)->orderBy('sort_order');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(CompanyFeatureAnswer::class);
    }

    public function label(?string $locale = null): string
    {
        $translation = $this->resolveTranslation($locale);

        return $translation?->label ?? $this->code;
    }

    public function helpText(?string $locale = null): ?string
    {
        return $this->resolveTranslation($locale)?->help_text;
    }

    public static function dataTypeOptions(): array
    {
        return [
            self::DATA_TYPE_BOOLEAN => __('app.features.data_types.boolean'),
            self::DATA_TYPE_TEXT => __('app.features.data_types.text'),
            self::DATA_TYPE_SINGLE_CHOICE => __('app.features.data_types.single_choice'),
        ];
    }

    private function resolveTranslation(?string $locale = null): ?FeatureTranslation
    {
        $locale ??= app()->getLocale();

        $translations = $this->translations;

        foreach ([$locale, config('app.fallback_locale', 'en'), 'es', 'en'] as $candidate) {
            $translation = $translations->firstWhere('locale', $candidate);

            if ($translation) {
                return $translation;
            }
        }

        return $translations->first();
    }
}
