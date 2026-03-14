<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyFeatureAnswer;
use App\Models\CompanyNoticeAnalysis;
use App\Models\NoticeAnalysis;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class OpenAICompanyNoticeAnalyzer
{
    public function analyze(
        NoticeAnalysis $noticeAnalysis,
        Company $company,
        Scope $scope,
        string $outputLocale = 'en',
        ?string $systemPromptPath = null,
        ?string $userPromptPath = null,
    ): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new \RuntimeException('Missing OPENAI_API_KEY (services.openai.api_key).');
        }

        $model = (string) config('services.openai.api_model', 'gpt-5-mini');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com'), '/');
        $maxCompletionTokens = (int) config('services.openai.max_completion_tokens', 16384);
        $maxInputChars = (int) config('services.openai.company_notice_analysis_input_max_chars', 35000);
        $normalizedLocale = $this->normalizeLocale($outputLocale);
        [$resolvedSystemPromptPath, $resolvedUserPromptPath] = $this->resolvePromptPaths(
            $scope,
            $systemPromptPath,
            $userPromptPath,
        );

        $systemPrompt = $this->loadPrompt($resolvedSystemPromptPath);
        $userTemplate = $this->loadPrompt($resolvedUserPromptPath);

        $company->loadMissing([
            'spanishLegalForm',
            'cnaeCode',
            'featureAnswers' => fn ($query) => $query->with([
                'feature.translations',
                'feature.scope.translations',
                'feature.options.translations',
                'featureOption.translations',
            ]),
        ]);
        $noticeAnalysis->loadMissing('notice.dailyJournal.source', 'noticeAnalysisRun.scope.translations');

        $userPrompt = $this->renderTemplate($userTemplate, [
            'company_name' => $company->name,
            'company_country' => $company->country,
            'company_country_name' => Company::countryOptions($normalizedLocale)[$company->country] ?? strtoupper((string) $company->country),
            'company_currency' => (string) ($company->currency ?? Company::DEFAULT_CURRENCY),
            'company_yearly_revenue' => $this->nullableValue($company->yearly_revenue),
            'company_total_assets' => $this->nullableValue($company->total_assets),
            'company_address' => $this->nullableValue($company->address),
            'company_spanish_legal_form' => $this->nullableValue($company->spanishLegalForm?->name),
            'company_cnae_code' => $this->nullableValue($company->cnaeCode?->code),
            'company_cnae_description' => $this->nullableValue($company->cnaeCode?->title),
            'scope_name' => $scope->name($normalizedLocale),
            'feature_answers' => $this->featureAnswersMarkdown($company, $scope, $normalizedLocale),
            'notice_id' => (string) $noticeAnalysis->notice_id,
            'notice_title' => trim((string) ($noticeAnalysis->notice?->title ?? '')),
            'notice_category' => trim((string) ($noticeAnalysis->notice?->category ?? '')),
            'notice_department' => trim((string) ($noticeAnalysis->notice?->department ?? '')),
            'notice_url' => trim((string) ($noticeAnalysis->notice?->url ?? '')),
            'notice_source' => trim((string) ($noticeAnalysis->notice?->dailyJournal?->source?->name ?? '')),
            'notice_issue_date' => (string) ($noticeAnalysis->notice?->dailyJournal?->issue_date?->format('Y-m-d') ?? ''),
            'notice_content' => $this->truncate((string) ($noticeAnalysis->notice?->content ?? ''), $maxInputChars),
            'scope_analysis_decision' => trim((string) ($noticeAnalysis->decision ?? '')),
            'scope_analysis_reason' => trim((string) ($noticeAnalysis->reason ?? '')),
            'scope_analysis_summary' => trim((string) ($noticeAnalysis->summary ?? '')),
            'scope_analysis_jurisdiction' => trim((string) ($noticeAnalysis->jurisdiction ?? '')),
            'scope_analysis_vector' => trim((string) ($noticeAnalysis->vector ?? '')),
            'output_locale' => $normalizedLocale,
            'output_language_name' => $this->localeLabel($normalizedLocale),
        ]);

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'max_completion_tokens' => $maxCompletionTokens,
        ];

        $response = $this->sendRequest($baseUrl, $apiKey, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'OpenAI company analysis request failed: HTTP %d %s',
                $response->status(),
                (string) $response->body()
            ));
        }

        $json = $this->extractJsonFromResponse($response);
        $normalized = $this->normalizeResult($json);
        $normalized['model'] = $model;

        return $normalized;
    }

    private function normalizeLocale(string $locale): string
    {
        $value = trim(strtolower($locale));
        if ($value === '' || ! in_array($value, User::supportedLocales(), true)) {
            return 'en';
        }

        return $value;
    }

    private function localeLabel(string $locale): string
    {
        return match ($locale) {
            'es' => 'Spanish',
            'ca' => 'Catalan',
            default => 'English',
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolvePromptPaths(Scope $scope, ?string $systemPromptPath, ?string $userPromptPath): array
    {
        $defaultPaths = $scope->companyAnalysisPromptRelativePaths();

        return [
            trim((string) $systemPromptPath) !== '' ? trim((string) $systemPromptPath) : $defaultPaths['system'],
            trim((string) $userPromptPath) !== '' ? trim((string) $userPromptPath) : $defaultPaths['user'],
        ];
    }

    private function loadPrompt(string $relativePath): string
    {
        $path = resource_path($relativePath);
        if (! is_file($path)) {
            throw new \RuntimeException("Prompt file not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            throw new \RuntimeException("Prompt file is empty: {$path}");
        }

        return trim($content);
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function renderTemplate(string $template, array $variables): string
    {
        $rendered = $template;

        foreach ($variables as $key => $value) {
            $rendered = str_replace('{{'.$key.'}}', $value, $rendered);
        }

        return trim($rendered);
    }

    private function truncate(string $text, int $maxChars): string
    {
        if ($maxChars > 0 && Str::length($text) > $maxChars) {
            return Str::substr($text, 0, $maxChars);
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendRequest(string $baseUrl, string $apiKey, array $payload): Response
    {
        try {
            return Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.openai.http_timeout', 360))
                ->post($baseUrl.'/v1/chat/completions', $payload);
        } catch (ConnectionException $exc) {
            throw new \RuntimeException('OpenAI company analysis request failed (connection).', previous: $exc);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractJsonFromResponse(Response $response): array
    {
        $content = data_get($response->json(), 'choices.0.message.content');

        $raw = '';
        if (is_string($content)) {
            $raw = trim($content);
        } elseif (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                    continue;
                }

                if (! is_array($part)) {
                    continue;
                }

                $text = $part['text'] ?? data_get($part, 'content.0.text');
                if (is_string($text) && trim($text) !== '') {
                    $parts[] = $text;
                }
            }

            $raw = trim(implode("\n", $parts));
        }

        if ($raw === '') {
            throw new \RuntimeException('OpenAI company analysis response missing message content.');
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new \RuntimeException('OpenAI company analysis response did not contain valid JSON.');
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('OpenAI company analysis response contained invalid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function normalizeResult(array $json): array
    {
        $decision = Str::lower(trim((string) ($json['decision'] ?? '')));
        if (! in_array($decision, [CompanyNoticeAnalysis::DECISION_RELEVANT, CompanyNoticeAnalysis::DECISION_NOT_RELEVANT], true)) {
            throw new \RuntimeException("Invalid company analysis decision: '{$decision}'.");
        }

        $reason = trim((string) ($json['reason'] ?? ''));
        if ($reason === '') {
            throw new \RuntimeException('Company analysis requires a reason.');
        }

        $requirements = trim((string) ($json['requirements'] ?? ''));
        $complianceDueAt = trim((string) ($json['compliance_due_at'] ?? ''));

        if ($decision === CompanyNoticeAnalysis::DECISION_RELEVANT && $requirements === '') {
            throw new \RuntimeException('Relevant company analysis requires requirements.');
        }

        return [
            'decision' => $decision,
            'reason' => $reason,
            'requirements' => $requirements !== '' ? $requirements : null,
            'compliance_due_at' => $this->normalizeDate($complianceDueAt),
            'raw_response' => $json,
        ];
    }

    private function normalizeDate(string $value): ?string
    {
        if ($value === '' || Str::lower($value) === 'null') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableValue(mixed $value): string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : 'Not set';
    }

    private function featureAnswersMarkdown(Company $company, Scope $scope, string $locale): string
    {
        $answers = $company->featureAnswers
            ->filter(fn (CompanyFeatureAnswer $answer): bool => $answer->feature?->scope_id === $scope->id)
            ->keyBy('feature_id');

        /** @var Collection<int, \App\Models\Feature> $features */
        $features = $scope->features()
            ->with(['translations', 'options.translations'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $lines = [];

        foreach ($features as $feature) {
            /** @var CompanyFeatureAnswer|null $answer */
            $answer = $answers->get($feature->id);

            $answerLabel = $answer?->answerLabel($locale) ?? 'Not set';
            $lines[] = sprintf('- %s: %s', $feature->label($locale), $answerLabel);
        }

        return $lines === [] ? '- No scope features configured.' : implode("\n", $lines);
    }
}
