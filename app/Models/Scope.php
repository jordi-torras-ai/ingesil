<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Scope extends Model
{
    use HasFactory;

    private const LEGACY_ANALYSIS_PROMPT_SCOPE_CODE = 'environment_industrial_safety';

    protected $fillable = [
        'code',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $scope): void {
            if (! $scope->is_active) {
                return;
            }

            $shouldValidatePrompt = ! $scope->exists || $scope->isDirty('is_active') || $scope->isDirty('code');
            if (! $shouldValidatePrompt || $scope->hasAnalysisPrompt()) {
                return;
            }

            $paths = $scope->analysisPromptPaths();

            throw ValidationException::withMessages([
                'is_active' => __('app.scope_catalog.validation.prompt_required_to_activate', [
                    'system' => $paths['system'],
                    'user' => $paths['user'],
                ]),
            ]);
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ScopeTranslation::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(Feature::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withTimestamps();
    }

    public function name(?string $locale = null): string
    {
        $translation = $this->resolveTranslation($locale);

        return $translation?->name ?? $this->code;
    }

    /**
     * @return array{system: string, user: string}
     */
    public function analysisPromptRelativePaths(): array
    {
        $scopedPaths = [
            'system' => "ai-prompts/notice-analysis/{$this->code}/system.md",
            'user' => "ai-prompts/notice-analysis/{$this->code}/user.md",
        ];

        if ($this->promptFilesExist($scopedPaths)) {
            return $scopedPaths;
        }

        if ($this->code === self::LEGACY_ANALYSIS_PROMPT_SCOPE_CODE) {
            return [
                'system' => 'ai-prompts/notice-analysis-system.md',
                'user' => 'ai-prompts/notice-analysis-user.md',
            ];
        }

        return $scopedPaths;
    }

    /**
     * @return array{system: string, user: string}
     */
    public function analysisPromptPaths(): array
    {
        $relativePaths = $this->analysisPromptRelativePaths();

        return [
            'system' => resource_path($relativePaths['system']),
            'user' => resource_path($relativePaths['user']),
        ];
    }

    public function hasAnalysisPrompt(): bool
    {
        return $this->promptFilesExist($this->analysisPromptRelativePaths());
    }

    /**
     * @return array{system: string, user: string}
     */
    public function companyAnalysisPromptRelativePaths(): array
    {
        return [
            'system' => "ai-prompts/company-notice-analysis/{$this->code}/system.md",
            'user' => "ai-prompts/company-notice-analysis/{$this->code}/user.md",
        ];
    }

    /**
     * @return array{system: string, user: string}
     */
    public function companyAnalysisPromptPaths(): array
    {
        $relativePaths = $this->companyAnalysisPromptRelativePaths();

        return [
            'system' => resource_path($relativePaths['system']),
            'user' => resource_path($relativePaths['user']),
        ];
    }

    public function hasCompanyAnalysisPrompt(): bool
    {
        return $this->promptFilesExist($this->companyAnalysisPromptRelativePaths());
    }

    private function resolveTranslation(?string $locale = null): ?ScopeTranslation
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

    /**
     * @param  array{system: string, user: string}  $relativePaths
     */
    private function promptFilesExist(array $relativePaths): bool
    {
        return is_file(resource_path($relativePaths['system']))
            && is_file(resource_path($relativePaths['user']));
    }
}
