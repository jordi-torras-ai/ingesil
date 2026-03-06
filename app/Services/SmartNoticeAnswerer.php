<?php

namespace App\Services;

use App\Models\Notice;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SmartNoticeAnswerer
{
    /**
     * @param  Collection<int, Notice>  $notices
     */
    public function answer(string $question, Collection $notices, string $outputLocale = 'en'): string
    {
        $normalizedQuestion = trim($question);
        if ($normalizedQuestion === '') {
            throw new \RuntimeException('Smart search question cannot be empty.');
        }

        if ($notices->isEmpty()) {
            return '';
        }

        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new \RuntimeException('Missing OPENAI_API_KEY (services.openai.api_key).');
        }

        $model = (string) config('services.openai.api_model', 'gpt-5-mini');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com'), '/');
        $maxCompletionTokens = (int) config('services.openai.max_completion_tokens', 16384);
        $maxInputChars = (int) config('services.openai.smart_search_input_max_chars', 28000);
        $normalizedLocale = $this->normalizeLocale($outputLocale);

        $systemPrompt = $this->loadPrompt((string) config('services.openai.smart_search_system_prompt', 'ai-prompts/smart-search-system.md'));
        $userTemplate = $this->loadPrompt((string) config('services.openai.smart_search_user_prompt', 'ai-prompts/smart-search-user.md'));

        $context = $this->buildContext($notices, $maxInputChars);
        $userPrompt = $this->renderTemplate($userTemplate, [
            'question' => $normalizedQuestion,
            'results_count' => (string) $notices->count(),
            'output_locale' => $normalizedLocale,
            'output_language_name' => $this->localeLabel($normalizedLocale),
            'notices_context' => $context,
        ]);

        $response = $this->sendRequest($baseUrl, $apiKey, [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'max_completion_tokens' => $maxCompletionTokens,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'OpenAI smart search request failed: HTTP %d %s',
                $response->status(),
                (string) $response->body()
            ));
        }

        $content = $this->extractContent($response);
        if ($content === '') {
            throw new \RuntimeException('OpenAI smart search response was empty.');
        }

        return trim($content);
    }

    private function normalizeLocale(string $locale): string
    {
        $value = trim(strtolower($locale));
        if (! in_array($value, User::supportedLocales(), true)) {
            return User::LOCALE_EN;
        }

        return $value;
    }

    private function localeLabel(string $locale): string
    {
        return match ($locale) {
            User::LOCALE_ES => 'Spanish',
            User::LOCALE_CA => 'Catalan',
            default => 'English',
        };
    }

    /**
     * @param  Collection<int, Notice>  $notices
     */
    private function buildContext(Collection $notices, int $maxInputChars): string
    {
        $parts = [];
        $currentLength = 0;

        foreach ($notices as $notice) {
            $block = trim(implode("\n", array_filter([
                'Notice ID: '.(string) $notice->id,
                'Source: '.trim((string) ($notice->dailyJournal?->source?->name ?? '')),
                'Issue date: '.(string) ($notice->dailyJournal?->issue_date?->format('Y-m-d') ?? ''),
                'Title: '.trim((string) $notice->title),
                'Category: '.trim((string) ($notice->category ?? '')),
                'Department: '.trim((string) ($notice->department ?? '')),
                'URL: '.trim((string) ($notice->url ?? '')),
                "Content:\n".$this->truncate(trim((string) ($notice->content ?? '')), 4500),
                "Extra info:\n".$this->truncate(trim((string) ($notice->extra_info ?? '')), 1500),
            ])));

            if ($block === '') {
                continue;
            }

            $separator = $parts === [] ? '' : "\n\n---\n\n";
            $candidateLength = $currentLength + Str::length($separator.$block);
            if ($maxInputChars > 0 && $candidateLength > $maxInputChars) {
                break;
            }

            $parts[] = $block;
            $currentLength = $candidateLength;
        }

        return implode("\n\n---\n\n", $parts);
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
            throw new \RuntimeException('OpenAI smart search request failed (connection).', previous: $exc);
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

    private function extractContent(Response $response): string
    {
        $content = data_get($response->json(), 'choices.0.message.content');

        if (is_string($content)) {
            return trim($content);
        }

        if (! is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_string($part) && trim($part) !== '') {
                $parts[] = trim($part);
                continue;
            }

            if (! is_array($part)) {
                continue;
            }

            $text = $part['text'] ?? data_get($part, 'content.0.text');
            if (is_string($text) && trim($text) !== '') {
                $parts[] = trim($text);
            }
        }

        return trim(implode("\n", $parts));
    }

    private function truncate(string $text, int $maxChars): string
    {
        if ($maxChars > 0 && Str::length($text) > $maxChars) {
            return Str::substr($text, 0, $maxChars);
        }

        return $text;
    }
}
