<?php

declare(strict_types=1);

namespace App\Catalog;

use DOMDocument;
use DOMNode;
use DOMXPath;

final class ProductDescriptionNormalizer
{
    private const PRIMARY_CHUNK_KEY = 'main';

    /**
     * @var list<string>
     */
    private const SECTION_ORDER = [
        'Introduction',
        'Key Features and Benefits',
        'Specifications',
        'Common Uses',
        'Other Details',
    ];

    public static function normalizeProductDescriptionToText(
        ?string $html,
        ?string $productName = null,
        ?string $productSku = null,
        ?string $brand = null,
        array|string|null $categories = null,
        array|string|null $priceLines = null
    ): string {
        return self::buildProductTextArtifacts(
            $html,
            $productName,
            $productSku,
            $brand,
            $categories,
            $priceLines,
        )['full_text'];
    }

    /**
     * @return array{
     *     full_text: string,
     *     chunks: list<array{key:string,text:string,rank:int,is_primary:bool}>
     * }
     */
    public static function buildProductTextArtifacts(
        ?string $html,
        ?string $productName = null,
        ?string $productSku = null,
        ?string $brand = null,
        array|string|null $categories = null,
        array|string|null $priceLines = null
    ): array {
        $header = self::buildMetadataHeader($productName, $productSku, $brand, $categories, $priceLines);
        $sections = self::extractSections($html);

        if ($sections === null || ! self::hasSectionContent($sections)) {
            $fullText = self::fallbackPlainText(
                (string) ($html ?? ''),
                $productName,
                $productSku,
                $brand,
                $categories,
                $priceLines,
            );

            return [
                'full_text' => $fullText,
                'chunks' => [[
                    'key' => self::PRIMARY_CHUNK_KEY,
                    'text' => $fullText,
                    'rank' => 1,
                    'is_primary' => true,
                ]],
            ];
        }

        $fullText = self::buildFullTextFromSections($header, $sections);

        return [
            'full_text' => $fullText,
            'chunks' => self::buildChunksFromSections($header, $sections, $fullText),
        ];
    }

    /**
     * @return array<string, list<string>>|null
     */
    private static function extractSections(?string $html): ?array
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        libxml_use_internal_errors(true);

        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = trim($html);

        $wrappedHtml = '<!DOCTYPE html><html><body><div id="root">'.$html.'</div></body></html>';

        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$wrappedHtml, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dom);
        $root = $xpath->query('//*[@id="root"]')->item(0);

        if (! $root) {
            return null;
        }

        self::removeNodesByClass($xpath, [
            'resources_and_downloads',
            'block_ResDown',
        ]);

        $sections = self::emptySections();
        $currentSection = 'Introduction';

        foreach ($root->childNodes as $node) {
            self::processNodeIntoSections($node, $sections, $currentSection);
        }

        foreach ($sections as $sectionName => $items) {
            $sections[$sectionName] = self::cleanSectionItems($items);
        }

        return $sections;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function emptySections(): array
    {
        return [
            'Introduction' => [],
            'Key Features and Benefits' => [],
            'Specifications' => [],
            'Common Uses' => [],
            'Other Details' => [],
        ];
    }

    /**
     * @param  array<string, list<string>>  $sections
     */
    private static function hasSectionContent(array $sections): bool
    {
        foreach ($sections as $items) {
            if ($items !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, list<string>>  $sections
     */
    private static function buildFullTextFromSections(string $header, array $sections): string
    {
        $parts = [];

        if ($header !== '') {
            $parts[] = $header;
        }

        foreach (self::SECTION_ORDER as $sectionName) {
            $sectionText = self::formatSection($sectionName, $sections[$sectionName] ?? []);
            if ($sectionText !== '') {
                $parts[] = $sectionText;
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * @param  array<string, list<string>>  $sections
     * @return list<array{key:string,text:string,rank:int,is_primary:bool}>
     */
    private static function buildChunksFromSections(string $header, array $sections, string $fullText): array
    {
        $chunks = [];
        $rank = 1;

        $mainBody = self::formatSection('Introduction', $sections['Introduction'] ?? []);
        if ($mainBody === '') {
            $mainBody = self::formatSectionGroup($sections, [
                'Key Features and Benefits',
                'Specifications',
            ]);
        }
        if ($mainBody === '') {
            $mainBody = self::formatSectionGroup($sections, [
                'Common Uses',
                'Other Details',
            ]);
        }
        if ($mainBody === '') {
            $mainBody = $fullText;
        }

        $mainText = self::composeChunkText($header, $mainBody);
        if ($mainText !== '') {
            $chunks[] = [
                'key' => self::PRIMARY_CHUNK_KEY,
                'text' => $mainText,
                'rank' => $rank++,
                'is_primary' => true,
            ];
        }

        $featuresSpecsText = self::composeChunkText(
            $header,
            self::formatSectionGroup($sections, [
                'Key Features and Benefits',
                'Specifications',
            ]),
        );
        if ($featuresSpecsText !== '') {
            $chunks[] = [
                'key' => 'features_specs',
                'text' => $featuresSpecsText,
                'rank' => $rank++,
                'is_primary' => false,
            ];
        }

        $usesOtherText = self::composeChunkText(
            $header,
            self::formatSectionGroup($sections, [
                'Common Uses',
                'Other Details',
            ]),
        );
        if ($usesOtherText !== '') {
            $chunks[] = [
                'key' => 'uses_other',
                'text' => $usesOtherText,
                'rank' => $rank++,
                'is_primary' => false,
            ];
        }

        if ($chunks !== []) {
            return $chunks;
        }

        return [[
            'key' => self::PRIMARY_CHUNK_KEY,
            'text' => $fullText,
            'rank' => 1,
            'is_primary' => true,
        ]];
    }

    private static function composeChunkText(string $header, string $body): string
    {
        $parts = [];

        if ($header !== '') {
            $parts[] = $header;
        }

        $body = trim($body);
        if ($body !== '') {
            $parts[] = $body;
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * @param  array<string, list<string>>  $sections
     * @param  list<string>  $sectionNames
     */
    private static function formatSectionGroup(array $sections, array $sectionNames): string
    {
        $parts = [];

        foreach ($sectionNames as $sectionName) {
            $sectionText = self::formatSection($sectionName, $sections[$sectionName] ?? []);
            if ($sectionText !== '') {
                $parts[] = $sectionText;
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * @param  list<string>  $items
     */
    private static function formatSection(string $sectionName, array $items): string
    {
        if ($items === []) {
            return '';
        }

        return match ($sectionName) {
            'Introduction', 'Common Uses' => $sectionName.":\n".implode("\n\n", $items),
            'Key Features and Benefits', 'Specifications', 'Other Details' => $sectionName.":\n"
                .implode("\n", self::prefixBullets($items)),
            default => $sectionName.":\n".implode("\n", $items),
        };
    }

    private static function removeNodesByClass(DOMXPath $xpath, array $classNames): void
    {
        foreach ($classNames as $className) {
            $nodes = $xpath->query(
                "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$className} ')]"
            );

            if (! $nodes) {
                continue;
            }

            $toRemove = [];
            foreach ($nodes as $node) {
                $toRemove[] = $node;
            }

            foreach ($toRemove as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    /**
     * @param  array<string, list<string>>  $sections
     */
    private static function processNodeIntoSections(DOMNode $node, array &$sections, string &$currentSection): void
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = self::normalizeWhitespace($node->textContent);
            if ($text !== '') {
                $sections[$currentSection][] = $text;
            }

            return;
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            $heading = self::normalizeWhitespace($node->textContent);
            $currentSection = self::mapHeadingToSection($heading);

            return;
        }

        if ($tag === 'p') {
            if (self::isDownloadOnlyParagraph($node)) {
                return;
            }

            $text = self::normalizeParagraphWithStrongHeading($node, $currentSection);

            if ($text !== '') {
                $sections[$currentSection][] = $text;
            }

            return;
        }

        if (in_array($tag, ['ul', 'ol'], true)) {
            foreach ($node->childNodes as $li) {
                if ($li->nodeType === XML_ELEMENT_NODE && strtolower($li->nodeName) === 'li') {
                    $text = self::normalizeWhitespace($li->textContent);
                    if ($text !== '') {
                        $sections[$currentSection][] = $text;
                    }
                }
            }

            return;
        }

        if ($tag === 'div') {
            foreach ($node->childNodes as $child) {
                self::processNodeIntoSections($child, $sections, $currentSection);
            }

            return;
        }

        if ($tag === 'a') {
            return;
        }

        $text = self::normalizeWhitespace($node->textContent);
        if ($text !== '') {
            $sections[$currentSection][] = $text;
        }
    }

    private static function isDownloadOnlyParagraph(DOMNode $node): bool
    {
        $text = self::normalizeWhitespace($node->textContent);

        if ($text === '') {
            return true;
        }

        $linkCount = 0;
        $elementCount = 0;

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $elementCount++;
                if (strtolower($child->nodeName) === 'a') {
                    $linkCount++;
                }
            }
        }

        if ($elementCount > 0 && $linkCount === $elementCount) {
            return true;
        }

        return false;
    }

    private static function normalizeParagraphWithStrongHeading(DOMNode $node, string &$currentSection): string
    {
        $strongText = '';
        $fullText = self::normalizeWhitespace($node->textContent);

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && in_array(strtolower($child->nodeName), ['strong', 'b'], true)) {
                $strongText = self::normalizeWhitespace($child->textContent);
                break;
            }
        }

        if ($strongText !== '') {
            $mapped = self::mapHeadingToSection($strongText);

            if (trim(rtrim($fullText, ':')) === trim(rtrim($strongText, ':'))) {
                $currentSection = $mapped;

                return '';
            }

            $pattern = '/^\s*'.preg_quote($strongText, '/').'\s*:?\s*/iu';
            $remaining = preg_replace($pattern, '', $fullText);

            if ($remaining !== null && trim($remaining) !== '') {
                $currentSection = $mapped;

                if ($mapped === 'Specifications') {
                    return $strongText.': '.trim($remaining);
                }

                return trim($remaining);
            }
        }

        return $fullText;
    }

    private static function mapHeadingToSection(string $heading): string
    {
        $normalized = mb_strtolower(trim(rtrim($heading, ':')), 'UTF-8');

        $map = [
            'description' => 'Introduction',
            'product description' => 'Introduction',
            'overview' => 'Introduction',
            'introduction' => 'Introduction',

            'key features and benefits' => 'Key Features and Benefits',
            'key features' => 'Key Features and Benefits',
            'features and benefits' => 'Key Features and Benefits',
            'features' => 'Key Features and Benefits',
            'benefits' => 'Key Features and Benefits',

            'specifications' => 'Specifications',
            'specification' => 'Specifications',
            'specs' => 'Specifications',
            'technical specifications' => 'Specifications',
            'technical data' => 'Specifications',
            'product specifications' => 'Specifications',
            'container size' => 'Specifications',
            'un-rating' => 'Specifications',
            'un rating' => 'Specifications',
            'dimensions' => 'Specifications',
            'size' => 'Specifications',

            'common uses' => 'Common Uses',
            'applications' => 'Common Uses',
            'typical applications' => 'Common Uses',
            'uses' => 'Common Uses',
            'recommended applications' => 'Common Uses',
        ];

        return $map[$normalized] ?? 'Other Details';
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private static function cleanSectionItems(array $items): array
    {
        $cleaned = [];

        foreach ($items as $item) {
            $item = self::normalizeWhitespace($item);
            $item = trim($item, "-• \t\n\r\0\x0B");

            if ($item === '') {
                continue;
            }

            if (! in_array($item, $cleaned, true)) {
                $cleaned[] = $item;
            }
        }

        return $cleaned;
    }

    private static function normalizeWhitespace(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\x{00A0}/u', ' ', $text);
        $text = preg_replace('/[ \t\r\n]+/u', ' ', $text);

        return trim($text ?? '');
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private static function prefixBullets(array $items): array
    {
        return array_map(
            static fn (string $item): string => '- '.ltrim($item, "-• \t"),
            $items
        );
    }

    public static function buildMetadataHeader(
        ?string $productName,
        ?string $productSku,
        ?string $brand,
        array|string|null $categories,
        array|string|null $priceLines = null
    ): string {
        $lines = [];

        if ($productName !== null && trim($productName) !== '') {
            $lines[] = 'Product: '.trim($productName);
        }

        if ($productSku !== null && trim($productSku) !== '') {
            $lines[] = 'SKU: '.trim($productSku);
        }

        if ($brand !== null && trim($brand) !== '') {
            $lines[] = 'Brand: '.trim($brand);
        }

        $categoryItems = self::normalizeMetadataItems($categories);
        if (! empty($categoryItems)) {
            $label = count($categoryItems) === 1 ? 'Category' : 'Categories';
            $lines[] = $label.': '.implode(' | ', $categoryItems);
        }

        foreach (self::normalizeMetadataItems($priceLines) as $priceLine) {
            $lines[] = $priceLine;
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private static function normalizeMetadataItems(array|string|null $value): array
    {
        if (is_string($value)) {
            $value = [trim($value)];
        } elseif (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '' && ! in_array($item, $items, true)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public static function fallbackPlainText(
        string $html,
        ?string $productName = null,
        ?string $productSku = null,
        ?string $brand = null,
        array|string|null $categories = null,
        array|string|null $priceLines = null
    ): string {
        $html = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\s*\/p\s*>/i', "\n\n", $html);
        $html = preg_replace('/<\s*li[^>]*>/i', '- ', $html);
        $html = preg_replace('/<\s*\/li\s*>/i', "\n", $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text ?? '');

        $header = self::buildMetadataHeader($productName, $productSku, $brand, $categories, $priceLines);

        if ($header !== '' && $text !== '') {
            return $header."\n\nDescription:\n".$text;
        }

        if ($header !== '') {
            return $header;
        }

        return $text;
    }
}
