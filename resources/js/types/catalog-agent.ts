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
    examples: string[];
};

export type ChatResponsePayload = {
    ok: boolean;
    reply?: string;
    error?: string;
    conversation: ConversationMessage[];
    lastResults: CatalogResult[];
    runtimeError: string | null;
};
