export type ConversationMessage = {
    role: 'user' | 'assistant';
    content: string;
};

export type CatalogResult = {
    result_index: number;
    point_id: string;
    sku: string | null;
    name: string | null;
    brand: string | null;
    categories: string[];
    score: number | null;
    text_snippet: string;
};

export type CatalogAgentPageProps = {
    conversation: ConversationMessage[];
    lastResults: CatalogResult[];
    runtimeError: string | null;
    chatEndpoint: string;
    resetEndpoint: string;
    reloadStartEndpoint: string;
    reloadStatusEndpoint: string;
    examples: string[];
};

export type CatalogReloadBatchPayload = {
    id: string;
    name: string;
    total_jobs: number;
    pending_jobs: number;
    failed_jobs: number;
    progress: number;
    finished: boolean;
    cancelled: boolean;
    failed: boolean;
};

/** Fields merged from cache + server-side enrichReloadRunStatus */
export type CatalogReloadRunPayload = {
    phase?: string;
    phase_label?: string;
    export_detail?: string;
    export_step?: string;
    export_page?: number;
    export_total_pages?: number;
    export_progress?: number;
    product_count?: number;
    variant_count?: number;
    embedding_chunk_count?: number;
    chunk_count?: number;
    last_chunk_error?: string;
    error?: string;
    split_chunks_written?: number;
};

export type CatalogReloadStatusPayload = {
    ok: boolean;
    run: CatalogReloadRunPayload | null;
    batch: CatalogReloadBatchPayload | null;
};

export type ChatResponsePayload = {
    ok: boolean;
    reply?: string;
    error?: string;
    conversation: ConversationMessage[];
    lastResults: CatalogResult[];
    runtimeError: string | null;
};
