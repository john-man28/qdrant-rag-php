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
        'api_key' => env('OPENAI_API_KEY', 'lm-studio'),
        'base_url' => env('OPENAI_BASE_URL', 'http://localhost:1234/v1'),
        'model' => env('OPENAI_MODEL', 'qwen3.5-4b-mlx@4bit'),
        'embedding_model' => env('EMBEDDING_MODEL', 'text-embedding-mxbai-embed-large-v1'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
    ],

    'qdrant' => [
        'url' => env('QDRANT_URL', 'http://localhost:6333'),
        'collection' => env('QDRANT_COLLECTION', 'catalog'),
        'timeout' => (int) env('QDRANT_TIMEOUT', 60),
        'vector_size' => (int) env('QDRANT_VECTOR_SIZE', 1024),
    ],

    'bigcommerce' => [
        'api_token' => env('BC_API_TOKEN_PROD'),
        'store_hash' => env('BC_STORE_HASH_PROD'),
    ],

    'catalog_reload' => [
        'lines_per_chunk_file' => (int) env('CATALOG_RELOAD_LINES_PER_CHUNK', 256),
        'embed_batch_size' => (int) env('CATALOG_EMBED_BATCH_SIZE', 64),
        'qdrant_upload_batch_size' => (int) env('CATALOG_QDRANT_UPLOAD_BATCH_SIZE', 64),
    ],

    'catalog_agent' => [
        'chat_top_k' => (int) env('CHAT_TOP_K', 10),
    ],

];
