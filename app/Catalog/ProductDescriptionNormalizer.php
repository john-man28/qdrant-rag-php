<?php

declare(strict_types=1);

namespace App\Catalog;

use DOMDocument;
use DOMNode;
use DOMXPath;

final class ProductDescriptionNormalizer
{
    public static function normalizeProductDescriptionToText(
        ?string $html,
        ?string $productName = null,
        ?string $productSku = null,
        ?string $brand = null,
        array|string|null $categories = null,
        array|string|null $priceLines = null
    ): string {
        if ($html === null || trim($html) === '') {
            return self::buildMetadataHeader($productName, $productSku, $brand, $categories, $priceLines);
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
            return self::fallbackPlainText($html, $productName, $productSku, $brand, $categories, $priceLines);
        }

        self::removeNodesByClass($xpath, [
            'resources_and_downloads',
            'block_ResDown',
        ]);

        $sections = [
            'Introduction' => [],
            'Key Features and Benefits' => [],
            'Specifications' => [],
            'Common Uses' => [],
            'Other Details' => [],
        ];

        $currentSection = 'Introduction';

        foreach ($root->childNodes as $node) {
            self::processNodeIntoSections($node, $sections, $currentSection);
        }

        foreach ($sections as $sectionName => $items) {
            $sections[$sectionName] = self::cleanSectionItems($items);
        }

        $hasContent = false;
        foreach ($sections as $items) {
            if (! empty($items)) {
                $hasContent = true;
                break;
            }
        }

        if (! $hasContent) {
            return self::fallbackPlainText($html, $productName, $productSku, $brand, $categories, $priceLines);
        }

        $parts = [];

        $header = self::buildMetadataHeader($productName, $productSku, $brand, $categories, $priceLines);
        if ($header !== '') {
            $parts[] = $header;
        }

        if (! empty($sections['Introduction'])) {
            $parts[] = "Introduction:\n".implode("\n\n", $sections['Introduction']);
        }

        if (! empty($sections['Key Features and Benefits'])) {
            $parts[] = "Key Features and Benefits:\n".implode("\n", self::prefixBullets($sections['Key Features and Benefits']));
        }

        if (! empty($sections['Specifications'])) {
            $parts[] = "Specifications:\n".implode("\n", self::prefixBullets($sections['Specifications']));
        }

        if (! empty($sections['Common Uses'])) {
            $parts[] = "Common Uses:\n".implode("\n\n", $sections['Common Uses']);
        }

        if (! empty($sections['Other Details'])) {
            $parts[] = "Other Details:\n".implode("\n", self::prefixBullets($sections['Other Details']));
        }

        return trim(implode("\n\n", $parts));
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
