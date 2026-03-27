<?php

return [
    'reload' => [
        'status_ttl_seconds' => (int) env('CATALOG_RELOAD_STATUS_TTL', 86400),
        'lock_ttl_seconds' => (int) env('CATALOG_RELOAD_LOCK_TTL', 120),
        'lock_wait_seconds' => (int) env('CATALOG_RELOAD_LOCK_WAIT', 5),
        'lines_per_chunk_file' => (int) env('CATALOG_RELOAD_LINES_PER_CHUNK', 256),
        'embed_batch_size' => (int) env('CATALOG_EMBED_BATCH_SIZE', 64),
        'qdrant_upload_batch_size' => (int) env('CATALOG_QDRANT_UPLOAD_BATCH_SIZE', 64),
    ],

    'agent' => [
        'chat_top_k' => (int) env('CHAT_TOP_K', 10),
    ],

    'search' => [
        'hybrid_enabled' => filter_var(env('CATALOG_SEARCH_HYBRID_ENABLED', false), FILTER_VALIDATE_BOOL),
        'hybrid_prefetch_limit' => (int) env('CATALOG_SEARCH_HYBRID_PREFETCH_LIMIT', 20),
    ],
];
