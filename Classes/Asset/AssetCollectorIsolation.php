<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Asset;

use TYPO3\CMS\Core\Page\AssetCollector;

/**
 * Runs a rendering with an empty AssetCollector, so assets registered while rendering
 * (f:asset.css, f:asset.script, ...) can be taken over into the preview iframe instead of leaking
 * into the backend page. The collector's previous content is put back afterwards.
 *
 * @phpstan-import-type Asset from IsolatedRendering
 * @phpstan-import-type CollectedAssets from IsolatedRendering
 */
final readonly class AssetCollectorIsolation
{
    public function __construct(private AssetCollector $assetCollector) {}

    /**
     * @param callable(): string $render
     */
    public function run(callable $render): IsolatedRendering
    {
        $outerAssets = $this->takeAll();
        try {
            $content = $render();
            $collectedAssets = $this->takeAll();
        } catch (\Throwable $e) {
            $this->takeAll();
            throw $e;
        } finally {
            $this->restore($outerAssets);
        }

        return new IsolatedRendering($content, $collectedAssets);
    }

    /**
     * Returns everything the collector holds and empties it.
     *
     * @return CollectedAssets
     */
    private function takeAll(): array
    {
        $assets = [
            'styleSheets' => self::assets($this->assetCollector->getStyleSheets()),
            'inlineStyleSheets' => self::assets($this->assetCollector->getInlineStyleSheets()),
            'javaScripts' => self::assets($this->assetCollector->getJavaScripts()),
            'inlineJavaScripts' => self::assets($this->assetCollector->getInlineJavaScripts()),
            'media' => self::media($this->assetCollector->getMedia()),
        ];
        foreach (array_keys($assets['styleSheets']) as $identifier) {
            $this->assetCollector->removeStyleSheet($identifier);
        }
        foreach (array_keys($assets['inlineStyleSheets']) as $identifier) {
            $this->assetCollector->removeInlineStyleSheet($identifier);
        }
        foreach (array_keys($assets['javaScripts']) as $identifier) {
            $this->assetCollector->removeJavaScript($identifier);
        }
        foreach (array_keys($assets['inlineJavaScripts']) as $identifier) {
            $this->assetCollector->removeInlineJavaScript($identifier);
        }
        foreach (array_keys($assets['media']) as $fileName) {
            $this->assetCollector->removeMedia($fileName);
        }

        return $assets;
    }

    /**
     * @param CollectedAssets $assets
     */
    private function restore(array $assets): void
    {
        foreach ($assets['styleSheets'] as $identifier => $asset) {
            $this->assetCollector->addStyleSheet($identifier, $asset['source'], $asset['attributes'], $asset['options']);
        }
        foreach ($assets['inlineStyleSheets'] as $identifier => $asset) {
            $this->assetCollector->addInlineStyleSheet($identifier, $asset['source'], $asset['attributes'], $asset['options']);
        }
        foreach ($assets['javaScripts'] as $identifier => $asset) {
            $this->assetCollector->addJavaScript($identifier, $asset['source'], $asset['attributes'], $asset['options']);
        }
        foreach ($assets['inlineJavaScripts'] as $identifier => $asset) {
            $this->assetCollector->addInlineJavaScript($identifier, $asset['source'], $asset['attributes'], $asset['options']);
        }
        foreach ($assets['media'] as $fileName => $additionalInformation) {
            $this->assetCollector->addMedia($fileName, $additionalInformation);
        }
    }

    /**
     * The collector's untyped arrays as typed asset shapes.
     *
     * @param array<mixed> $raw
     * @return array<string, Asset>
     */
    private static function assets(array $raw): array
    {
        $assets = [];
        foreach ($raw as $identifier => $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $attributes = [];
            foreach (is_array($asset['attributes'] ?? null) ? $asset['attributes'] : [] as $name => $value) {
                $attributes[(string)$name] = is_scalar($value) ? (string)$value : '';
            }
            $options = [];
            foreach (is_array($asset['options'] ?? null) ? $asset['options'] : [] as $name => $value) {
                $options[(string)$name] = $value;
            }
            $assets[(string)$identifier] = [
                'source' => is_scalar($asset['source'] ?? null) ? (string)$asset['source'] : '',
                'attributes' => $attributes,
                'options' => $options,
            ];
        }

        return $assets;
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, array<string, mixed>>
     */
    private static function media(array $raw): array
    {
        $media = [];
        foreach ($raw as $fileName => $additionalInformation) {
            $information = [];
            foreach (is_array($additionalInformation) ? $additionalInformation : [] as $name => $value) {
                $information[(string)$name] = $value;
            }
            $media[(string)$fileName] = $information;
        }

        return $media;
    }
}
