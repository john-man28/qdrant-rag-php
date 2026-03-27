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

    'dense_embedding' => [
        'api_key' => env('DENSE_EMBEDDING_API_KEY', env('OPENAI_API_KEY', 'lm-studio')),
        'base_url' => env('DENSE_EMBEDDING_BASE_URL', env('OPENAI_BASE_URL', 'http://localhost:1234/v1')),
        'model' => env('DENSE_EMBEDDING_MODEL', env('EMBEDDING_MODEL', 'text-embedding-mxbai-embed-large-v1')),
        'timeout' => (int) env('DENSE_EMBEDDING_TIMEOUT', env('OPENAI_TIMEOUT', 60)),
    ],

    'sparse_embedding' => [
        'api_key' => env('SPARSE_EMBEDDING_API_KEY', ''),
        'base_url' => env('SPARSE_EMBEDDING_BASE_URL', ''),
        'model' => env('SPARSE_EMBEDDING_MODEL', ''),
        'timeout' => (int) env('SPARSE_EMBEDDING_TIMEOUT', 60),
    ],

    'late_interaction' => [
        'api_key' => env('LATE_INTERACTION_API_KEY', ''),
        'base_url' => env('LATE_INTERACTION_BASE_URL', ''),
        'model' => env('LATE_INTERACTION_MODEL', ''),
        'timeout' => (int) env('LATE_INTERACTION_TIMEOUT', 60),
    ],

    'qdrant' => [
        'url' => env('QDRANT_URL', 'http://localhost:6333'),
        'collection' => env('QDRANT_COLLECTION', 'catalog'),
        'timeout' => (int) env('QDRANT_TIMEOUT', 60),
        'vector_size' => (int) env('QDRANT_VECTOR_SIZE', 1024),
        'dense_vector_size' => (int) env('QDRANT_DENSE_VECTOR_SIZE', env('QDRANT_VECTOR_SIZE', 1024)),
        'late_vector_size' => env('QDRANT_LATE_VECTOR_SIZE'),
        'dense_vector_name' => env('QDRANT_DENSE_VECTOR_NAME', 'dense'),
        'sparse_vector_name' => env('QDRANT_SPARSE_VECTOR_NAME', 'sparse'),
        'late_vector_name' => env('QDRANT_LATE_VECTOR_NAME', 'late'),
        'sparse_modifier' => env('QDRANT_SPARSE_MODIFIER', 'idf'),
    ],

    'bigcommerce' => [
        'api_token' => env('BC_API_TOKEN_PROD'),
        'store_hash' => env('BC_STORE_HASH_PROD'),
    ],
];
