<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Asset;

/**
 * Result of AssetCollectorIsolation::run(): the rendered content and the assets it registered.
 *
 * @phpstan-type Asset array{source: string, attributes: array<string, string>, options: array<string, mixed>}
 * @phpstan-type CollectedAssets array{styleSheets: array<string, Asset>, inlineStyleSheets: array<string, Asset>, javaScripts: array<string, Asset>, inlineJavaScripts: array<string, Asset>, media: array<string, array<string, mixed>>}
 */
final readonly class IsolatedRendering
{
    /**
     * @param CollectedAssets $assets
     */
    public function __construct(
        public string $content,
        public array $assets,
    ) {}
}
