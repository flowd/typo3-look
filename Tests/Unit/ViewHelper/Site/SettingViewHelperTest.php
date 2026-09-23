<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Tests\Unit\ViewHelper\Site;

use Flowd\Typo3Look\ViewHelper\Site\SettingViewHelper;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SettingViewHelperTest extends UnitTestCase
{
    #[Test]
    public function returnsTheSettingOfTheSiteOfThePage(): void
    {
        $site = new Site('main', 1, ['base' => '/', 'settings' => ['theme' => ['colorScheme' => 'forest']]]);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects($this->once())->method('getSiteByPageId')->with(7)->willReturn($site);

        self::assertSame('forest', $this->render($siteFinder, ['pageUid' => 7, 'name' => 'theme.colorScheme']));
    }

    #[Test]
    public function returnsTheDefaultWhenTheSettingIsMissing(): void
    {
        $site = new Site('main', 1, ['base' => '/']);
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn($site);

        self::assertSame('light', $this->render($siteFinder, ['pageUid' => 7, 'name' => 'theme.colorScheme', 'default' => 'light']));
    }

    #[Test]
    public function returnsTheDefaultWhenThePageHasNoSite(): void
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('no site', 1790000010));

        self::assertSame('', $this->render($siteFinder, ['pageUid' => 99, 'name' => 'theme.colorScheme']));
    }

    #[Test]
    public function returnsTheDefaultForANonScalarSetting(): void
    {
        $site = new Site('main', 1, ['base' => '/', 'settings' => ['theme' => ['colors' => ['a', 'b']]]]);
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn($site);

        self::assertSame('none', $this->render($siteFinder, ['pageUid' => 7, 'name' => 'theme.colors', 'default' => 'none']));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function render(SiteFinder $siteFinder, array $arguments): string
    {
        $subject = new SettingViewHelper($siteFinder);
        $subject->setArguments($arguments + ['default' => '']);

        return $subject->render();
    }
}
