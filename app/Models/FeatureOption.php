<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeatureOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'feature_id',
        'code',
        'sort_order',
    ];

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(FeatureOptionTranslation::class);
    }

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        $translations = $this->translations;

        foreach ([$locale, config('app.fallback_locale', 'en'), 'es', 'en'] as $candidate) {
            $translation = $translations->firstWhere('locale', $candidate);

            if ($translation) {
                return $translation->label;
            }
        }

        return $translations->first()?->label ?? $this->code;
    }
}
