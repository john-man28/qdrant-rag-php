import { startTransition, useDeferredValue, useEffect, useId, useRef, useState } from 'react';
import type {
    CatalogAgentPageProps,
    CatalogReloadStatusPayload,
    CatalogResult,
    ChatResponsePayload,
    ConversationMessage,
} from '../types/catalog-agent';

const RELOAD_RUN_STORAGE_KEY = 'catalog_reload_run_id';

type ReloadProgressView = {
    phaseLabel: string;
    exportDetail: string | null;
    statsLine: string | null;
    batchProgress: number | null;
    batchLine: string | null;
    warning: string | null;
    barMode: 'none' | 'determinate' | 'indeterminate';
    barPercent: number | null;
};

function deriveReloadProgress(data: CatalogReloadStatusPayload): ReloadProgressView {
    const run = data.run;
    if (!run) {
        return {
            phaseLabel: 'Queued',
            exportDetail: null,
            statsLine: null,
            batchProgress: null,
            batchLine: null,
            warning: null,
            barMode: 'indeterminate',
            barPercent: null,
        };
    }

    const phase = typeof run.phase === 'string' ? run.phase : '';
    const phaseLabel =
        typeof run.phase_label === 'string' && run.phase_label !== '' ? run.phase_label : phase || '…';
    const exportDetail =
        typeof run.export_detail === 'string' && run.export_detail !== '' ? run.export_detail : null;

    const productCount = typeof run.product_count === 'number' ? run.product_count : undefined;
    const variantCount = typeof run.variant_count === 'number' ? run.variant_count : undefined;
    const embeddingChunkCount =
        typeof run.embedding_chunk_count === 'number' ? run.embedding_chunk_count : undefined;
    const chunkCount = typeof run.chunk_count === 'number' ? run.chunk_count : undefined;

    let statsLine: string | null = null;
    if (phase === 'indexing' && (productCount != null || embeddingChunkCount != null || chunkCount != null)) {
        const parts: string[] = [];
        if (productCount != null) {
            parts.push(`${productCount} products`);
        }
        if (variantCount != null) {
            parts.push(`${variantCount} variants`);
        }
        if (embeddingChunkCount != null) {
            parts.push(`${embeddingChunkCount} embedding chunks`);
        }
        if (chunkCount != null) {
            parts.push(`${chunkCount} chunk files to index`);
        }
        if (parts.length > 0) {
            statsLine = parts.join(' · ');
        }
    }

    const batch = data.batch;
    let batchProgress: number | null = null;
    let batchLine: string | null = null;
    if (batch && batch.total_jobs > 0) {
        batchProgress = Math.round(batch.progress);
        batchLine = `${batch.total_jobs - batch.pending_jobs} / ${batch.total_jobs} chunks`;
    }

    const exportProgress =
        typeof run.export_progress === 'number' && !Number.isNaN(run.export_progress)
            ? run.export_progress
            : null;

    let barMode: ReloadProgressView['barMode'] = 'none';
    let barPercent: number | null = null;

    if (batch && batch.total_jobs > 0) {
        barMode = 'determinate';
        barPercent = batchProgress;
    } else if (phase === 'export' && exportProgress !== null && exportProgress >= 0) {
        barMode = 'determinate';
        barPercent = exportProgress;
    } else if (phase === 'export' || phase === 'reset_qdrant') {
        barMode = 'indeterminate';
        barPercent = null;
    }

    const lastChunkErr =
        typeof run.last_chunk_error === 'string' && run.last_chunk_error !== ''
            ? run.last_chunk_error
            : null;
    const failedJobs = batch?.failed_jobs ?? 0;
    const warnings: string[] = [];
    if (failedJobs > 0) {
        warnings.push(`${failedJobs} batch job(s) failed`);
    }
    if (lastChunkErr) {
        warnings.push(lastChunkErr);
    }
    const warning = warnings.length > 0 ? warnings.join(' · ') : null;

    return {
        phaseLabel,
        exportDetail,
        statsLine,
        batchProgress,
        batchLine,
        warning,
        barMode,
        barPercent,
    };
}

export default function CatalogAgentApp({
    conversation: initialConversation,
    lastResults: initialLastResults,
    runtimeError: initialRuntimeError,
    chatEndpoint,
    resetEndpoint,
    reloadStartEndpoint,
    reloadStatusEndpoint,
    examples,
}: CatalogAgentPageProps) {
    const [conversation, setConversation] = useState<ConversationMessage[]>(initialConversation);
    const [lastResults, setLastResults] = useState<CatalogResult[]>(initialLastResults);
    const [runtimeError, setRuntimeError] = useState<string | null>(initialRuntimeError);
    const [draft, setDraft] = useState('');
    const [isSending, setIsSending] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);
    const [reloadBusy, setReloadBusy] = useState(false);
    const [reloadHint, setReloadHint] = useState<string | null>(null);
    const [reloadProgress, setReloadProgress] = useState<ReloadProgressView | null>(null);
    const reloadPollRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const deferredResults = useDeferredValue(lastResults);
    const bottomRef = useRef<HTMLDivElement | null>(null);

    const axios = window.axios;

    function setupReloadPolling(runId: string, options?: { skipInitialPoll?: boolean }): void {
        sessionStorage.setItem(RELOAD_RUN_STORAGE_KEY, runId);
        if (reloadPollRef.current !== null) {
            clearInterval(reloadPollRef.current);
            reloadPollRef.current = null;
        }

        const pollOnce = async (): Promise<void> => {
            try {
                const res = await axios.get<CatalogReloadStatusPayload>(reloadStatusEndpoint, {
                    params: { run_id: runId },
                });
                const phase = res.data.run?.phase as string | undefined;
                const err = (res.data.run?.error as string | undefined) ?? null;
                setReloadProgress(deriveReloadProgress(res.data));
                if (phase === 'completed' || phase === 'failed') {
                    if (reloadPollRef.current !== null) {
                        clearInterval(reloadPollRef.current);
                        reloadPollRef.current = null;
                    }
                    setReloadBusy(false);
                    setReloadProgress(null);
                    sessionStorage.removeItem(RELOAD_RUN_STORAGE_KEY);
                    setReloadHint(
                        phase === 'completed'
                            ? 'Catalog reload finished. Qdrant is up to date.'
                            : `Reload failed: ${err ?? 'unknown error'}`,
                    );
                }
            } catch {
                if (reloadPollRef.current !== null) {
                    clearInterval(reloadPollRef.current);
                    reloadPollRef.current = null;
                }
                setReloadBusy(false);
                setReloadProgress(null);
                sessionStorage.removeItem(RELOAD_RUN_STORAGE_KEY);
                setReloadHint('Could not read reload status.');
            }
        };

        if (!options?.skipInitialPoll) {
            void pollOnce();
        }
        reloadPollRef.current = setInterval(() => {
            void pollOnce();
        }, 5000);
    }

    useEffect(() => {
        return () => {
            if (reloadPollRef.current !== null) {
                clearInterval(reloadPollRef.current);
            }
        };
    }, []);

    useEffect(() => {
        const stored = sessionStorage.getItem(RELOAD_RUN_STORAGE_KEY);
        if (!stored) {
            return;
        }
        let cancelled = false;
        void (async () => {
            try {
                const res = await axios.get<CatalogReloadStatusPayload>(reloadStatusEndpoint, {
                    params: { run_id: stored },
                });
                if (cancelled) {
                    return;
                }
                const phase = res.data.run?.phase as string | undefined;
                if (!res.data.run || phase === 'completed' || phase === 'failed') {
                    sessionStorage.removeItem(RELOAD_RUN_STORAGE_KEY);
                    return;
                }
                setReloadBusy(true);
                setReloadProgress(deriveReloadProgress(res.data));
                setupReloadPolling(stored, { skipInitialPoll: true });
            } catch {
                sessionStorage.removeItem(RELOAD_RUN_STORAGE_KEY);
            }
        })();
        return () => {
            cancelled = true;
            if (reloadPollRef.current !== null) {
                clearInterval(reloadPollRef.current);
                reloadPollRef.current = null;
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- resume in-flight reload once on mount
    }, []);
    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }, [conversation]);

    const disableComposer = isSending || runtimeError !== null;

    async function submitMessage(messageOverride?: string): Promise<void> {
        const message = (messageOverride ?? draft).trim();
        if (!message || disableComposer) {
            return;
        }

        setIsSending(true);
        setLocalError(null);

        try {
            const response = await axios.post(chatEndpoint, {
                message,
            });

            const payload = response.data as ChatResponsePayload;

            startTransition(() => {
                setConversation(payload.conversation ?? []);
                setLastResults(payload.lastResults ?? []);
                setRuntimeError(payload.runtimeError ?? null);
                setLocalError(response.status === 200 ? null : payload.error ?? 'The chat request failed.');
            });

            if (response.status === 200) {
                setDraft('');
            }
        } catch {
            setLocalError('The browser could not reach the Laravel chat endpoint.');
        } finally {
            setIsSending(false);
        }
    }

    async function startCatalogReload(): Promise<void> {
        if (reloadBusy || runtimeError !== null) {
            return;
        }
        setReloadBusy(true);
        setReloadHint(null);
        setReloadProgress(
            deriveReloadProgress({
                ok: true,
                run: null,
                batch: null,
            }),
        );
        if (reloadPollRef.current !== null) {
            clearInterval(reloadPollRef.current);
            reloadPollRef.current = null;
        }
        try {
            const start = await axios.post<{ run_id: string }>(reloadStartEndpoint, {});
            const runId = start.data.run_id;
            setupReloadPolling(runId);
        } catch {
            setReloadBusy(false);
            setReloadProgress(null);
            sessionStorage.removeItem(RELOAD_RUN_STORAGE_KEY);
            setReloadHint('Could not start catalog reload.');
        }
    }

    async function resetConversation(): Promise<void> {
        setIsSending(true);
        setLocalError(null);

        try {
            const response = await axios.post(resetEndpoint, {
            });

            const payload = response.data as ChatResponsePayload;

            startTransition(() => {
                setConversation(payload.conversation ?? []);
                setLastResults(payload.lastResults ?? []);
                setRuntimeError(payload.runtimeError ?? null);
                setLocalError(response.status === 200 ? null : payload.error ?? 'Unable to reset this session.');
            });
        } catch {
            setLocalError('The browser could not reset the catalog chat session.');
        } finally {
            setIsSending(false);
        }
    }

    return (
        <div className="relative min-h-screen overflow-hidden bg-[radial-gradient(circle_at_top_left,rgba(246,173,85,0.2),transparent_26%),radial-gradient(circle_at_top_right,rgba(56,189,248,0.16),transparent_22%),linear-gradient(180deg,#f7f5ef_0%,#ebe7dd_38%,#d6d0c2_100%)] text-stone-950">
            <div className="pointer-events-none absolute inset-0 bg-[linear-gradient(to_right,rgba(28,25,23,0.08)_1px,transparent_1px),linear-gradient(to_bottom,rgba(28,25,23,0.08)_1px,transparent_1px)] bg-[size:34px_34px] opacity-30" />
            <div className="pointer-events-none absolute inset-x-0 top-0 h-72 bg-[radial-gradient(circle_at_top,rgba(255,255,255,0.85),transparent_70%)]" />

            <div className="relative mx-auto flex min-h-screen w-full max-w-7xl flex-col px-5 pb-12 sm:px-6 lg:px-8">
                <main className="mt-4 grid gap-6 lg:grid-cols-[minmax(0,1.42fr)_24rem]">
                    <section className="overflow-hidden rounded-[2rem] border border-stone-900/10 bg-[linear-gradient(160deg,rgba(255,255,255,0.82),rgba(247,243,236,0.7))] shadow-[0_28px_120px_rgba(68,64,60,0.14)] backdrop-blur">
                        <div className="border-b border-stone-900/8 px-6 py-2 sm:px-7">
                            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div className="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => void resetConversation()}
                                        disabled={isSending}
                                        className="inline-flex items-center justify-center gap-2 rounded-full border border-stone-900/12 bg-white/90 px-4 py-2 text-sm font-medium text-stone-700 transition hover:border-stone-900/20 hover:text-stone-950 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <ResetIcon spinning={isSending} />
                                        New session
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => void startCatalogReload()}
                                        disabled={reloadBusy || runtimeError !== null}
                                        className="inline-flex items-center justify-center gap-2 rounded-full border border-amber-900/25 bg-amber-100/90 px-4 py-2 text-sm font-medium text-amber-950 transition hover:border-amber-900/35 hover:bg-amber-50 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {reloadBusy ? <LoaderIcon /> : <SignalIcon />}
                                        Reload catalog
                                    </button>
                                </div>
                                {reloadBusy && reloadProgress !== null && (
                                    <div className="max-w-xl space-y-2">
                                        <p className="text-xs font-medium leading-6 text-stone-800">
                                            {reloadProgress.phaseLabel}
                                        </p>
                                        {reloadProgress.exportDetail !== null && (
                                            <p className="text-xs leading-6 text-stone-600">
                                                {reloadProgress.exportDetail}
                                            </p>
                                        )}
                                        {reloadProgress.statsLine !== null && (
                                            <p className="text-xs leading-6 text-stone-600">
                                                {reloadProgress.statsLine}
                                            </p>
                                        )}
                                        {reloadProgress.warning !== null && (
                                            <p className="text-xs leading-6 text-amber-900">{reloadProgress.warning}</p>
                                        )}
                                        {reloadProgress.barMode !== 'none' && (
                                            <div className="h-1.5 w-full overflow-hidden rounded-full bg-stone-200/90">
                                                {reloadProgress.barMode === 'determinate' &&
                                                reloadProgress.barPercent !== null ? (
                                                    <div
                                                        className="h-full rounded-full bg-amber-500 transition-[width] duration-300"
                                                        style={{
                                                            width: `${Math.min(100, Math.max(0, reloadProgress.barPercent))}%`,
                                                        }}
                                                    />
                                                ) : (
                                                    <div className="h-full w-full animate-pulse rounded-full bg-amber-300/80" />
                                                )}
                                            </div>
                                        )}
                                        {reloadProgress.batchLine !== null && (
                                            <p className="text-xs leading-6 text-stone-500">
                                                {reloadProgress.batchLine}
                                                {reloadProgress.batchProgress !== null
                                                    ? ` · ${reloadProgress.batchProgress}%`
                                                    : ''}
                                            </p>
                                        )}
                                    </div>
                                )}
                                {!reloadBusy && reloadHint !== null && (
                                    <p className="max-w-xl text-xs leading-6 text-stone-600">{reloadHint}</p>
                                )}
                            </div>
                        </div>

                        <div className="space-y-4 px-6 py-6 sm:px-7">
                            {(runtimeError || localError) && (
                                <div className="rounded-[1.5rem] border border-red-500/20 bg-red-50 px-4 py-4 text-sm text-red-900 shadow-sm">
                                    <div className="flex items-start gap-3">
                                        <AlertIcon />
                                        <div>
                                            <div className="font-semibold">Agent unavailable</div>
                                            <div className="mt-1 leading-6">{runtimeError ?? localError}</div>
                                        </div>
                                    </div>
                                </div>
                            )}

                            <div className="max-h-[30rem] min-h-[26rem] space-y-4 overflow-y-auto rounded-[1.75rem] border border-stone-900/8 bg-white/60 p-4 shadow-inner shadow-white/40">
                                {conversation.length === 0 ? (
                                    <div className="rounded-[1.5rem] border border-dashed border-stone-900/12 bg-stone-50/80 p-6">
                                        <div className="flex items-start gap-4">
                                            <div className="rounded-2xl bg-stone-900 p-3 text-stone-50 shadow-lg shadow-stone-900/15">
                                                <SparkIcon />
                                            </div>
                                            <div>
                                                <h3 className="font-display text-lg font-semibold text-stone-950">
                                                    Ready for catalog questions
                                                </h3>
                                                <p className="mt-2 max-w-xl text-sm leading-7 text-stone-600">
                                                    Ask for a product need, or anchor a recommendation with a SKU like
                                                    <span className="font-semibold text-stone-900"> OSFHU-ITW</span> or
                                                    a previous result number like
                                                    <span className="font-semibold text-stone-900"> #2</span>.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                ) : (
                                    conversation.map((message, index) => (
                                        <div
                                            key={`${message.role}-${index}-${message.content.slice(0, 24)}`}
                                            className={`flex ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}
                                        >
                                            <div
                                                className={`max-w-[86%] rounded-[1.7rem] px-4 py-3 text-sm leading-7 shadow-sm ${
                                                    message.role === 'user'
                                                        ? 'bg-stone-950 text-stone-50 shadow-stone-900/20'
                                                        : 'border border-stone-900/8 bg-white text-stone-800'
                                                }`}
                                            >
                                                <div className="mb-1 text-[11px] font-semibold uppercase tracking-[0.22em] opacity-60">
                                                    {message.role === 'user' ? 'You' : 'Agent'}
                                                </div>
                                                <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">
                                                    {message.content}
                                                </p>
                                            </div>
                                        </div>
                                    ))
                                )}

                                {isSending && (
                                    <div className="flex justify-start">
                                        <div className="inline-flex items-center gap-2 rounded-full border border-stone-900/10 bg-white px-4 py-2 text-sm text-stone-600 shadow-sm">
                                            <LoaderIcon />
                                            Thinking through tools and retrieval...
                                        </div>
                                    </div>
                                )}

                                <div ref={bottomRef} />
                            </div>

                            <div className="rounded-[1.75rem] border border-stone-900/10 bg-stone-950 p-4 text-stone-50 shadow-[0_24px_60px_rgba(28,25,23,0.32)]">
                                <div className="mt-4 rounded-[1.5rem] border border-white/10 bg-white/6 p-3">
                                    <textarea
                                        value={draft}
                                        onChange={(event) => setDraft(event.target.value)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter' && !event.shiftKey) {
                                                event.preventDefault();
                                                void submitMessage();
                                            }
                                        }}
                                        placeholder="Ask for a product need, compare by SKU, or say “show similar to #2”."
                                        className="min-h-28 w-full resize-none border-0 bg-transparent px-1 py-1 text-sm leading-7 text-stone-50 outline-none placeholder:text-stone-400"
                                        disabled={disableComposer}
                                    />

                                    <div className="mt-3 flex flex-col gap-3 border-t border-white/10 pt-3 text-xs text-stone-400 sm:flex-row sm:items-center sm:justify-between">
                                        <div>Press Enter to send, Shift+Enter for a new line.</div>
                                        <button
                                            type="button"
                                            onClick={() => void submitMessage()}
                                            disabled={disableComposer || draft.trim() === ''}
                                            className="inline-flex items-center justify-center gap-2 rounded-full bg-amber-300 px-4 py-2 text-sm font-semibold text-stone-950 transition hover:bg-amber-200 disabled:cursor-not-allowed disabled:bg-stone-700 disabled:text-stone-400"
                                        >
                                            {isSending ? <LoaderIcon /> : <ArrowIcon />}
                                            Send
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <aside className="space-y-6">
                        <section className="rounded-[1.9rem] border border-stone-900/10 bg-white/75 p-5 shadow-[0_18px_70px_rgba(68,64,60,0.12)] backdrop-blur">
                            <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.24em] text-stone-500">
                                <BoardIcon />
                                Latest retrieved results
                            </div>
                            <p className="mt-3 text-sm leading-7 text-stone-600">
                                These are the references available for follow-ups like
                                <span className="font-semibold text-stone-900"> show similar to #2</span>.
                            </p>

                            <div className="mt-4 space-y-3">
                                {deferredResults.length === 0 ? (
                                    <div className="rounded-[1.4rem] border border-dashed border-stone-900/12 px-4 py-5 text-sm leading-7 text-stone-500">
                                        Search results will appear here once the agent runs a catalog query.
                                    </div>
                                ) : (
                                    deferredResults.map((result) => (
                                        <CollapsibleResultCard
                                            key={`${result.point_id}-${result.result_index}`}
                                            result={result}
                                        />
                                    ))
                                )}
                            </div>
                        </section>

                        <section className="rounded-[1.9rem] border border-stone-950/10 bg-stone-950 p-5 text-stone-50 shadow-[0_18px_90px_rgba(28,25,23,0.28)]">
                            <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.24em] text-stone-300">
                                <TipIcon />
                                Prompting tips
                            </div>

                            <div className="mt-4 space-y-3 text-sm leading-7 text-stone-200">
                                <TipCard
                                    title="Natural language search"
                                    body="I need a high bay occupancy sensor for a warehouse."
                                />
                                <TipCard title="Anchor by SKU" body="Show alternatives to OSFHU-ITW." />
                                <TipCard title="Pivot from results" body="Show similar to #2." />
                            </div>
                        </section>
                    </aside>
                </main>
            </div>
        </div>
    );
}

function TipCard({ title, body }: { title: string; body: string }) {
    return (
        <div className="rounded-[1.3rem] bg-white/6 p-4">
            <div className="text-xs font-semibold uppercase tracking-[0.2em] text-stone-400">{title}</div>
            <div className="mt-2 font-medium text-stone-50">{body}</div>
        </div>
    );
}

function CollapsibleResultCard({ result }: { result: CatalogResult }) {
    const [expanded, setExpanded] = useState(false);
    const detailsId = useId();

    return (
        <article className="rounded-[1.4rem] border border-stone-900/8 bg-stone-50/80 shadow-sm">
            <button
                type="button"
                aria-expanded={expanded}
                aria-controls={detailsId}
                onClick={() => setExpanded((open) => !open)}
                className="flex w-full items-start justify-between gap-3 rounded-[1.4rem] p-4 text-left outline-none ring-stone-900/15 transition hover:bg-stone-100/80 focus-visible:ring-2"
            >
                <div className="flex min-w-0 flex-1 items-start gap-3">
                    <span className="shrink-0 rounded-full border border-stone-900/10 bg-white px-2.5 py-1 text-xs font-semibold text-stone-700">
                        #{result.result_index}
                    </span>
                    <div className="min-w-0 flex-1">
                        <h3 className="font-display text-base font-semibold leading-snug text-stone-950 [overflow-wrap:anywhere] sm:text-lg">
                            {result.name ?? 'Unknown product'}
                        </h3>
                        {!expanded && (result.sku || result.brand) && (
                            <p className="mt-1 line-clamp-2 text-xs leading-5 text-stone-500 [overflow-wrap:anywhere]">
                                {[result.sku, result.brand].filter(Boolean).join(' · ')}
                            </p>
                        )}
                    </div>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1">
                    {result.score !== null && (
                        <span className="text-xs font-medium text-stone-500">score {result.score.toFixed(3)}</span>
                    )}
                    <ChevronToggleIcon expanded={expanded} />
                </div>
            </button>

            <div
                id={detailsId}
                role="region"
                hidden={!expanded}
                className={expanded ? 'space-y-2 border-t border-stone-900/10 px-4 pb-4 pt-3' : undefined}
            >
                <div className="text-xs font-semibold uppercase tracking-[0.2em] text-stone-500 [overflow-wrap:anywhere]">
                    {result.sku ?? 'n/a'}
                </div>
                {result.brand && (
                    <div className="text-sm text-stone-600 [overflow-wrap:anywhere]">{result.brand}</div>
                )}
                {result.categories.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {result.categories.slice(0, 3).map((category) => (
                            <span
                                key={category}
                                className="rounded-full bg-white px-3 py-1 text-xs font-medium text-stone-700 shadow-sm"
                            >
                                {category}
                            </span>
                        ))}
                    </div>
                )}
                {result.text_snippet ? (
                    <p className="text-sm leading-7 text-stone-600 [overflow-wrap:anywhere]">{result.text_snippet}</p>
                ) : null}
            </div>
        </article>
    );
}

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function SignalIcon() {
    return (
        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path d="M4 18a8 8 0 0 1 16 0" />
            <path d="M8 18a4 4 0 0 1 8 0" />
            <circle cx="12" cy="18" r="1.4" fill="currentColor" stroke="none" />
        </svg>
    );
}

function SparkIcon() {
    return (
        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6L12 3Z" />
            <path d="M18.5 15.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8.8-2.2Z" />
        </svg>
    );
}

function SearchIcon() {
    return (
        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="1.8">
            <circle cx="11" cy="11" r="6.5" />
            <path d="m16 16 4.5 4.5" />
        </svg>
    );
}

function ResetIcon({ spinning }: { spinning: boolean }) {
    return (
        <svg
            viewBox="0 0 24 24"
            className={`h-4 w-4 ${spinning ? 'animate-spin' : ''}`}
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
        >
            <path d="M20 11a8 8 0 1 0 2 5.3" />
            <path d="M20 4v7h-7" />
        </svg>
    );
}

function AlertIcon() {
    return (
        <svg viewBox="0 0 24 24" className="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path d="M12 9v4" />
            <circle cx="12" cy="17" r="0.8" fill="currentColor" stroke="none" />
            <path d="M10.3 4.5 3.9 16.2A2 2 0 0 0 5.7 19h12.6a2 2 0 0 0 1.8-2.8L13.7 4.5a2 2 0 0 0-3.4 0Z" />
        </svg>
    );
}

function LoaderIcon() {
    return (
        <svg viewBox="0 0 24 24" className="h-4 w-4 animate-spin" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path d="M21 12a9 9 0 1 1-2.64-6.36" />
        </svg>
    );
}

function ArrowIcon() {
    return (
        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path d="m12 5 0 14" />
            <path d="m6.5 10.5 5.5-5.5 5.5 5.5" />
        </svg>
    );
}

function BoardIcon() {
    return (
        <svg viewBox="0 0 24 24" className="h-4 w-4 shrink-0" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path d="M4 5.5h16v13H4z" />
            <path d="M8 9h8" />
            <path d="M8 13h5" />
        </svg>
    );
}

function ChevronToggleIcon({ expanded }: { expanded: boolean }) {
    return (
        <svg
            viewBox="0 0 24 24"
            className={`h-4 w-4 shrink-0 text-stone-500 transition-transform duration-200 ${expanded ? '-rotate-180' : ''}`}
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            aria-hidden
        >
            <path d="m6 9 6 6 6-6" />
        </svg>
    );
}

function TipIcon() {
    return (
        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path d="M9 18h6" />
            <path d="M10 21h4" />
            <path d="M8.3 14.5A6.5 6.5 0 1 1 15.7 14.5c-.8.7-1.3 1.5-1.5 2.5h-4.4c-.2-1-.7-1.8-1.5-2.5Z" />
        </svg>
    );
}
