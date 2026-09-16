<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Asset;

use Flowd\Typo3Look\Resource\PublicResourceUri;
use TYPO3Fluid\Fluid\Core\ViewHelper\TagBuilder;

/**
 * Renders assets collected while rendering a preview (see AssetCollectorIsolation) as tags for
 * the head of the preview iframe. Scripts get the nonce of the backend request, so they pass the
 * iframe's Content Security Policy.
 *
 * @phpstan-import-type Asset from IsolatedRendering
 * @phpstan-import-type CollectedAssets from IsolatedRendering
 */
final readonly class PreviewAssetMarkup
{
    public function __construct(private PublicResourceUri $publicResourceUri) {}

    /**
     * @param CollectedAssets $assets
     */
    public function styles(array $assets): string
    {
        $tags = [];
        foreach ($this->byPriority($assets['styleSheets']) as $asset) {
            $tag = new TagBuilder('link');
            $tag->addAttributes($asset['attributes']);
            $tag->addAttribute('rel', 'stylesheet');
            $tag->addAttribute('href', $this->publicResourceUri->resolve($asset['source']));
            $tags[] = $tag->render();
        }
        foreach ($this->byPriority($assets['inlineStyleSheets']) as $asset) {
            $tag = new TagBuilder('style', $asset['source']);
            $tag->addAttributes($asset['attributes']);
            $tag->forceClosingTag(true);
            $tags[] = $tag->render();
        }

        return implode("\n", $tags);
    }

    /**
     * @param CollectedAssets $assets
     */
    public function scripts(array $assets, string $nonce): string
    {
        $tags = [];
        foreach ($this->byPriority($assets['javaScripts']) as $asset) {
            $tag = new TagBuilder('script');
            $tag->addAttributes($asset['attributes']);
            $tag->addAttribute('src', $this->publicResourceUri->resolve($asset['source']));
            $tag->addAttribute('nonce', $nonce);
            $tag->forceClosingTag(true);
            $tags[] = $tag->render();
        }
        foreach ($this->byPriority($assets['inlineJavaScripts']) as $asset) {
            $tag = new TagBuilder('script', $asset['source']);
            $tag->addAttributes($asset['attributes']);
            $tag->addAttribute('nonce', $nonce);
            $tag->forceClosingTag(true);
            $tags[] = $tag->render();
        }

        return implode("\n", $tags);
    }

    /**
     * Assets with option priority first, like the PageRenderer does.
     *
     * @param array<string, Asset> $assets
     * @return list<Asset>
     */
    private function byPriority(array $assets): array
    {
        $assets = array_values($assets);
        usort($assets, static fn(array $a, array $b): int => (int)(($b['options']['priority'] ?? false) === true) <=> (int)(($a['options']['priority'] ?? false) === true));

        return $assets;
    }
}
