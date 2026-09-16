<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Tests\Unit\Asset;

use Flowd\Typo3Look\Asset\AssetCollectorIsolation;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AssetCollectorIsolationTest extends UnitTestCase
{
    private AssetCollector $assetCollector;
    private AssetCollectorIsolation $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assetCollector = new AssetCollector();
        $this->subject = new AssetCollectorIsolation($this->assetCollector);
    }

    #[Test]
    public function returnsRenderedContent(): void
    {
        $rendering = $this->subject->run(static fn(): string => '<p>preview</p>');

        self::assertSame('<p>preview</p>', $rendering->content);
    }

    #[Test]
    public function renderingStartsWithAnEmptyCollectorAndCollectsWhatTheRenderingRegisters(): void
    {
        $this->assetCollector->addStyleSheet('outer', 'EXT:site/outer.css');

        $seenDuringRendering = null;
        $rendering = $this->subject->run(function () use (&$seenDuringRendering): string {
            $seenDuringRendering = $this->assetCollector->getStyleSheets();
            $this->assetCollector->addStyleSheet('inner', 'EXT:site/inner.css', ['media' => 'print'], ['priority' => true]);
            $this->assetCollector->addInlineStyleSheet('inner-inline', '.a{}');
            $this->assetCollector->addJavaScript('inner-js', 'EXT:site/inner.js');
            $this->assetCollector->addInlineJavaScript('inner-inline-js', 'boot();');
            $this->assetCollector->addMedia('EXT:site/image.png', ['width' => 10]);
            return '';
        });

        self::assertSame([], $seenDuringRendering);
        self::assertSame(['inner'], array_keys($rendering->assets['styleSheets']));
        $inner = $rendering->assets['styleSheets']['inner'] ?? null;
        self::assertIsArray($inner);
        self::assertSame(['media' => 'print'], $inner['attributes']);
        self::assertSame(['priority' => true], $inner['options']);
        self::assertSame(['inner-inline'], array_keys($rendering->assets['inlineStyleSheets']));
        self::assertSame(['inner-js'], array_keys($rendering->assets['javaScripts']));
        self::assertSame(['inner-inline-js'], array_keys($rendering->assets['inlineJavaScripts']));
        self::assertSame(['EXT:site/image.png'], array_keys($rendering->assets['media']));
    }

    #[Test]
    public function restoresThePreviousCollectorContentAndDropsTheCollectedAssets(): void
    {
        $this->assetCollector->addStyleSheet('outer', 'EXT:site/outer.css', ['media' => 'screen'], ['priority' => true]);
        $this->assetCollector->addInlineJavaScript('outer-inline', 'outer();');
        $this->assetCollector->addMedia('EXT:site/outer.png', ['width' => 20]);

        $this->subject->run(function (): string {
            $this->assetCollector->addStyleSheet('inner', 'EXT:site/inner.css');
            return '';
        });

        self::assertSame(['outer'], array_keys($this->assetCollector->getStyleSheets()));
        $outer = $this->assetCollector->getStyleSheets()['outer'] ?? null;
        self::assertIsArray($outer);
        self::assertSame(['media' => 'screen'], $outer['attributes']);
        self::assertSame(['priority' => true], $outer['options']);
        self::assertSame(['outer-inline'], array_keys($this->assetCollector->getInlineJavaScripts()));
        self::assertSame(['EXT:site/outer.png' => ['width' => 20]], $this->assetCollector->getMedia());
        self::assertSame([], $this->assetCollector->getInlineStyleSheets());
        self::assertSame([], $this->assetCollector->getJavaScripts());
    }

    #[Test]
    public function restoresThePreviousCollectorContentWhenRenderingThrows(): void
    {
        $this->assetCollector->addStyleSheet('outer', 'EXT:site/outer.css');

        try {
            $this->subject->run(function (): string {
                $this->assetCollector->addStyleSheet('inner', 'EXT:site/inner.css');
                throw new \RuntimeException('rendering failed', 1789600000);
            });
            self::fail('exception expected');
        } catch (\RuntimeException $e) {
            self::assertSame(1789600000, $e->getCode());
        }

        self::assertSame(['outer'], array_keys($this->assetCollector->getStyleSheets()));
    }
}
