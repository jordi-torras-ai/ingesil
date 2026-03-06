<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        'timeout_seconds' => env('OPENAI_TIMEOUT_SECONDS', env('OPENAI_HTTP_TIMEOUT', 60)),
        'http_timeout' => env('OPENAI_HTTP_TIMEOUT', 360),
        'api_model' => env('OPENAI_API_MODEL', 'gpt-5-mini'),
        'max_completion_tokens' => env('OPENAI_MAX_COMPLETION_TOKENS', 16384),
        'max_iterations' => env('OPENAI_MAX_ITERATIONS', 100),
        'job_timeout' => env('OPENAI_JOB_TIMEOUT', 900),
        'screening_delay_ms' => env('OPENAI_SCREENING_DELAY_MS', 750),
        'notice_analysis_queue' => env('OPENAI_NOTICE_ANALYSIS_QUEUE', 'default'),
        'notice_analysis_system_prompt' => env('OPENAI_NOTICE_ANALYSIS_SYSTEM_PROMPT', 'ai-prompts/notice-analysis-system.md'),
        'notice_analysis_user_prompt' => env('OPENAI_NOTICE_ANALYSIS_USER_PROMPT', 'ai-prompts/notice-analysis-user.md'),
        'notice_analysis_input_max_chars' => env('OPENAI_NOTICE_ANALYSIS_INPUT_MAX_CHARS', 35000),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'embedding_dimensions' => env('OPENAI_EMBEDDING_DIMENSIONS', 1536),
        'embedding_input_max_chars' => env('OPENAI_EMBEDDING_INPUT_MAX_CHARS', 8000),
        'embedding_queue' => env('OPENAI_EMBEDDING_QUEUE', 'default'),
    ],

];
