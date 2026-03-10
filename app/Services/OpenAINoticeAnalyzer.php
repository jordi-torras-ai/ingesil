<?php

namespace App\Services;

use App\Models\Notice;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class OpenAINoticeAnalyzer
{
    /**
     * @var list<string>
     */
    private const ALLOWED_VECTORS = [
        'Water',
        'Waste',
        'Air',
        'Soil',
        'Noise and Vibrations',
        'Environmental Management',
        'Chemical Substances',
        'Industrial Safety',
        'Energy',
        'Radioactivity',
        'Emergencies',
        'Climate Change',
        'Occupational Health and Safety',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_SCOPES = [
        'Catalonia',
        'Spain',
        'European Union',
    ];

    /**
     * @return array<string, mixed>
     */
    public function analyze(Notice $notice, string $outputLocale = 'en'): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new \RuntimeException('Missing OPENAI_API_KEY (services.openai.api_key).');
        }

        $model = (string) config('services.openai.api_model', 'gpt-5-mini');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com'), '/');
        $maxCompletionTokens = (int) config('services.openai.max_completion_tokens', 16384);
        $maxInputChars = (int) config('services.openai.notice_analysis_input_max_chars', 35000);
        $normalizedLocale = $this->normalizeLocale($outputLocale);

        $systemPrompt = $this->loadPrompt((string) config('services.openai.notice_analysis_system_prompt', 'ai-prompts/notice-analysis-system.md'));
        $userTemplate = $this->loadPrompt((string) config('services.openai.notice_analysis_user_prompt', 'ai-prompts/notice-analysis-user.md'));

        $userPrompt = $this->renderTemplate($userTemplate, [
            'notice_id' => (string) $notice->id,
            'notice_title' => trim((string) $notice->title),
            'notice_category' => trim((string) ($notice->category ?? '')),
            'notice_department' => trim((string) ($notice->department ?? '')),
            'notice_url' => trim((string) ($notice->url ?? '')),
            'notice_source' => trim((string) ($notice->dailyJournal?->source?->name ?? '')),
            'notice_issue_date' => (string) ($notice->dailyJournal?->issue_date?->format('Y-m-d') ?? ''),
            'notice_content' => $this->truncate((string) ($notice->content ?? ''), $maxInputChars),
            'output_locale' => $normalizedLocale,
            'output_language_name' => $this->localeLabel($normalizedLocale),
        ]);

        $payloads = [
            [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'max_completion_tokens' => $maxCompletionTokens,
            ],
        ];

        $lastError = 'OpenAI analysis request failed.';
        $maxIterations = max(1, (int) config('services.openai.max_iterations', 100));
        $attempts = min($maxIterations, count($payloads));

        for ($index = 0; $index < $attempts; $index++) {
            $payload = $payloads[$index];
            $response = $this->sendRequest($baseUrl, $apiKey, $payload);

            if (! $response->successful()) {
                $lastError = sprintf(
                    'OpenAI analysis request failed: HTTP %d %s',
                    $response->status(),
                    (string) $response->body()
                );
                continue;
            }

            try {
                $json = $this->extractJsonFromResponse($response);
                $normalized = $this->normalizeResult($json, $notice);
                $normalized['model'] = $model;

                return $normalized;
            } catch (Throwable $exc) {
                $lastError = sprintf(
                    'OpenAI analysis response parse failed: %s',
                    $exc->getMessage()
                );
            }
        }

        throw new \RuntimeException($lastError);
    }

    private function normalizeLocale(string $locale): string
    {
        $value = trim(strtolower($locale));
        if ($value === '') {
            return 'en';
        }

        if (! in_array($value, User::supportedLocales(), true)) {
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
            throw new \RuntimeException('OpenAI analysis request failed (connection).', previous: $exc);
        }
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
            throw new \RuntimeException('OpenAI analysis response missing message content.');
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new \RuntimeException('OpenAI analysis response did not contain valid JSON.');
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('OpenAI analysis response contained invalid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function normalizeResult(array $json, Notice $notice): array
    {
        $decision = Str::lower(trim((string) ($json['decision'] ?? '')));
        if (! in_array($decision, ['send', 'ignore'], true)) {
            throw new \RuntimeException("Invalid analysis decision: '{$decision}'.");
        }

        $reason = trim((string) ($json['reason'] ?? ''));

        if ($decision === 'ignore') {
            if ($reason === '') {
                throw new \RuntimeException('Analysis decision "ignore" requires a reason.');
            }

            return [
                'decision' => 'ignore',
                'reason' => $reason,
                'vector' => null,
                'scope' => null,
                'title' => null,
                'summary' => null,
                'repealed_provisions' => null,
                'link' => null,
                'raw_response' => $json,
            ];
        }

        $vector = trim((string) ($json['vector'] ?? ''));
        if (! in_array($vector, self::ALLOWED_VECTORS, true)) {
            throw new \RuntimeException("Invalid vector value: '{$vector}'.");
        }

        $scope = trim((string) ($json['scope'] ?? ''));
        if (! in_array($scope, self::ALLOWED_SCOPES, true)) {
            throw new \RuntimeException("Invalid scope value: '{$scope}'.");
        }

        $title = trim((string) ($json['title'] ?? ''));
        $summary = trim((string) ($json['summary'] ?? ''));
        if ($title === '' || $summary === '') {
            throw new \RuntimeException('Analysis decision "send" requires title and summary.');
        }

        if ($reason === '') {
            throw new \RuntimeException('Analysis decision "send" requires a reason.');
        }

        $repealedProvisions = trim((string) ($json['repealed_provisions'] ?? ''));
        if ($repealedProvisions === '') {
            $repealedProvisions = 'No repealed provisions mentioned.';
        }

        $link = trim((string) ($json['link'] ?? ''));
        if ($link === '') {
            $link = (string) ($notice->url ?? '');
        }

        return [
            'decision' => 'send',
            'reason' => $reason,
            'vector' => $vector,
            'scope' => $scope,
            'title' => $title,
            'summary' => $summary,
            'repealed_provisions' => $repealedProvisions,
            'link' => $link,
            'raw_response' => $json,
        ];
    }
}
