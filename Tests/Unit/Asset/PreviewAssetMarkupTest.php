<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Tests\Unit\Asset;

use Flowd\Typo3Look\Asset\IsolatedRendering;
use Flowd\Typo3Look\Asset\PreviewAssetMarkup;
use Flowd\Typo3Look\Resource\PublicResourceUri;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * @phpstan-import-type Asset from IsolatedRendering
 * @phpstan-import-type CollectedAssets from IsolatedRendering
 */
final class PreviewAssetMarkupTest extends UnitTestCase
{
    private PreviewAssetMarkup $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $publicResourceUri = self::createStub(PublicResourceUri::class);
        $publicResourceUri->method('resolve')->willReturnCallback(
            static fn(string $source): string => str_starts_with($source, 'EXT:') ? '/_assets/abc/Css/site.css' : $source,
        );
        $this->subject = new PreviewAssetMarkup($publicResourceUri);
    }

    /**
     * @param array<string, Asset> $styleSheets
     * @param array<string, Asset> $inlineStyleSheets
     * @param array<string, Asset> $javaScripts
     * @param array<string, Asset> $inlineJavaScripts
     * @return CollectedAssets
     */
    private function assets(array $styleSheets = [], array $inlineStyleSheets = [], array $javaScripts = [], array $inlineJavaScripts = []): array
    {
        return [
            'styleSheets' => $styleSheets,
            'inlineStyleSheets' => $inlineStyleSheets,
            'javaScripts' => $javaScripts,
            'inlineJavaScripts' => $inlineJavaScripts,
            'media' => [],
        ];
    }

    #[Test]
    public function stylesResolveExtPathsAndKeepAttributes(): void
    {
        $html = $this->subject->styles($this->assets(
            styleSheets: ['site' => ['source' => 'EXT:site/Resources/Public/Css/site.css', 'attributes' => ['media' => 'print'], 'options' => []]],
        ));

        self::assertSame('<link media="print" rel="stylesheet" href="/_assets/abc/Css/site.css" />', $html);
    }

    #[Test]
    public function stylesPassUrlsThroughUnchanged(): void
    {
        $html = $this->subject->styles($this->assets(
            styleSheets: ['cdn' => ['source' => 'https://cdn.example.org/site.css', 'attributes' => [], 'options' => []]],
        ));

        self::assertSame('<link rel="stylesheet" href="https://cdn.example.org/site.css" />', $html);
    }

    #[Test]
    public function stylesRenderPriorityAssetsFirstAndInlineStylesAfterFiles(): void
    {
        $html = $this->subject->styles($this->assets(
            styleSheets: [
                'later' => ['source' => 'https://example.org/later.css', 'attributes' => [], 'options' => []],
                'first' => ['source' => 'https://example.org/first.css', 'attributes' => [], 'options' => ['priority' => true]],
            ],
            inlineStyleSheets: ['inline' => ['source' => '.a{color:red}', 'attributes' => ['data-x' => '1'], 'options' => []]],
        ));

        self::assertSame(
            '<link rel="stylesheet" href="https://example.org/first.css" />' . "\n"
            . '<link rel="stylesheet" href="https://example.org/later.css" />' . "\n"
            . '<style data-x="1">.a{color:red}</style>',
            $html,
        );
    }

    #[Test]
    public function scriptsGetTheNonce(): void
    {
        $html = $this->subject->scripts($this->assets(
            javaScripts: ['app' => ['source' => 'https://example.org/app.js', 'attributes' => ['type' => 'module'], 'options' => []]],
            inlineJavaScripts: ['boot' => ['source' => 'boot();', 'attributes' => [], 'options' => []]],
        ), 'n0nce');

        self::assertSame(
            '<script type="module" src="https://example.org/app.js" nonce="n0nce"></script>' . "\n"
            . '<script nonce="n0nce">boot();</script>',
            $html,
        );
    }

    #[Test]
    public function emptyAssetsRenderNothing(): void
    {
        self::assertSame('', $this->subject->styles($this->assets()));
        self::assertSame('', $this->subject->scripts($this->assets(), 'n0nce'));
    }
}
