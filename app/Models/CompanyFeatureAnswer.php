<?php

namespace App\Models;

use App\Models\Concerns\LogsAdminActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyFeatureAnswer extends Model
{
    use HasFactory;
    use LogsAdminActivity;

    protected $fillable = [
        'company_id',
        'feature_id',
        'feature_option_id',
        'value_boolean',
        'value_text',
    ];

    protected function casts(): array
    {
        return [
            'value_boolean' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CompanyFeatureAnswer $answer): void {
            $feature = $answer->feature()->with('options')->first();

            if (! $feature) {
                return;
            }

            if ($feature->data_type !== Feature::DATA_TYPE_BOOLEAN) {
                $answer->value_boolean = null;
            }

            if ($feature->data_type !== Feature::DATA_TYPE_TEXT) {
                $answer->value_text = null;
            }

            if ($feature->data_type !== Feature::DATA_TYPE_SINGLE_CHOICE) {
                $answer->feature_option_id = null;
            }

            if (
                $feature->data_type === Feature::DATA_TYPE_SINGLE_CHOICE
                && $answer->feature_option_id !== null
                && ! $feature->options->contains('id', $answer->feature_option_id)
            ) {
                $answer->feature_option_id = null;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function featureOption(): BelongsTo
    {
        return $this->belongsTo(FeatureOption::class);
    }

    public function answerLabel(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return match ($this->feature?->data_type) {
            Feature::DATA_TYPE_BOOLEAN => $this->value_boolean === null
                ? '—'
                : ($this->value_boolean ? __('app.common.yes') : __('app.common.no')),
            Feature::DATA_TYPE_SINGLE_CHOICE => $this->featureOption?->label($locale) ?? '—',
            default => trim((string) $this->value_text) !== '' ? (string) $this->value_text : '—',
        };
    }

    protected function activityLogAttributes(): array
    {
        return [
            'company_id',
            'feature_id',
            'feature_option_id',
            'value_boolean',
            'value_text',
        ];
    }
}
