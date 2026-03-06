<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAIEmbeddings
{
    /**
     * @return list<float>
     */
    public function embed(string $input): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new \RuntimeException('Missing OPENAI_API_KEY (services.openai.api_key).');
        }

        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com'), '/');
        $model = (string) config('services.openai.embedding_model', 'text-embedding-3-small');

        $payload = [
            'model' => $model,
            'input' => $input,
        ];

        $dimensions = config('services.openai.embedding_dimensions');
        if (is_numeric($dimensions) && (int) $dimensions > 0) {
            $payload['dimensions'] = (int) $dimensions;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.openai.timeout_seconds', 60))
                ->post($baseUrl.'/v1/embeddings', $payload);
        } catch (ConnectionException $exc) {
            throw new \RuntimeException('OpenAI embeddings request failed (connection).', previous: $exc);
        }

        if (! $response->successful()) {
            $msg = (string) $response->body();
            throw new \RuntimeException("OpenAI embeddings request failed: HTTP {$response->status()} {$msg}");
        }

        $data = $response->json('data.0.embedding');
        if (! is_array($data) || $data === []) {
            throw new \RuntimeException('OpenAI embeddings response missing data.0.embedding.');
        }

        $embedding = [];
        foreach ($data as $value) {
            if (! is_numeric($value)) {
                continue;
            }
            $embedding[] = (float) $value;
        }

        if ($embedding === []) {
            throw new \RuntimeException('OpenAI embeddings response contained no numeric values.');
        }

        return $embedding;
    }
}

