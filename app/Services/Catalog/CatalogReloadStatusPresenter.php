<?php

declare(strict_types=1);

namespace App\Services\Catalog;

final class CatalogReloadStatusPresenter
{
    /**
     * @param  array<string, mixed>|null  $status
     * @return array<string, mixed>|null
     */
    public static function enrich(?array $status): ?array
    {
        if ($status === null) {
            return null;
        }

        $status['phase_label'] = self::phaseLabel($status);
        $detail = self::exportDetail($status);
        if ($detail !== null) {
            $status['export_detail'] = $detail;
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    public static function phaseLabel(array $status): string
    {
        $phase = isset($status['phase']) && is_string($status['phase']) ? $status['phase'] : '';
        $step = isset($status['export_step']) && is_string($status['export_step']) ? $status['export_step'] : null;

        return match ($phase) {
            'reset_qdrant' => 'Resetting vector collection',
            'export' => match ($step) {
                'categories' => 'Fetching categories',
                'brands' => 'Fetching brands',
                'products' => 'Fetching products from BigCommerce',
                'splitting_chunks' => 'Splitting products into chunk files',
                default => 'Exporting catalog',
            },
            'indexing' => 'Embedding chunks and uploading to Qdrant',
            'completed' => 'Catalog reload complete',
            'failed' => 'Catalog reload failed',
            default => $phase !== '' ? $phase : '…',
        };
    }

    /**
     * @param  array<string, mixed>  $status
     */
    public static function exportDetail(array $status): ?string
    {
        $phase = isset($status['phase']) && is_string($status['phase']) ? $status['phase'] : '';
        if ($phase !== 'export') {
            return null;
        }

        $step = isset($status['export_step']) && is_string($status['export_step']) ? $status['export_step'] : null;

        if ($step === 'products') {
            $page = isset($status['export_page']) && is_numeric($status['export_page']) ? (int) $status['export_page'] : null;
            $total = isset($status['export_total_pages']) && is_numeric($status['export_total_pages']) ? (int) $status['export_total_pages'] : null;
            if ($page !== null && $total !== null && $total > 0) {
                $pct = isset($status['export_progress']) && is_numeric($status['export_progress']) ? (int) $status['export_progress'] : null;

                return $pct !== null
                    ? "Product pages {$page} / {$total} ({$pct}%)"
                    : "Product pages {$page} / {$total}";
            }
        }

        if ($step === 'splitting_chunks') {
            $written = isset($status['split_chunks_written']) && is_numeric($status['split_chunks_written'])
                ? (int) $status['split_chunks_written']
                : null;
            if ($written !== null && $written > 0) {
                return "Chunk files written: {$written}";
            }
        }

        return null;
    }
}
