<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Tests\Functional\Resource;

use Flowd\Typo3Look\Resource\PublicResourceUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\SystemResource\SystemResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Runs against TYPO3 13 and 14 (CI matrix): both resolve with the same rules, the URIs differ in
 * form only. TYPO3 13 adds no site path on the CLI (no leading slash) and busts the cache with the
 * file time, TYPO3 14 with a hash, hence the patterns. The test instance is not in Composer mode,
 * so extension resources live in typo3conf/ext instead of _assets.
 */
final class PublicResourceUriTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['flowd/typo3-look'];

    protected function setUp(): void
    {
        parent::setUp();
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin/look');
        file_put_contents(Environment::getPublicPath() . '/fileadmin/look/site.css', 'body{}');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function resolvableValues(): iterable
    {
        yield 'extension path' => ['EXT:look/Resources/Public/Css/Backend/iFramePreview.css', '#^/?typo3conf/ext/look/Resources/Public/Css/Backend/iFramePreview\.css\?\w+$#'];
        // the System Resource API does not check that a package file exists, neither does the frontend
        yield 'missing file in an extension' => ['EXT:look/Resources/Public/Css/missing.css', '#^/?typo3conf/ext/look/Resources/Public/Css/missing\.css$#'];
        yield 'public path' => ['fileadmin/look/site.css', '#^/?fileadmin/look/site\.css\?\w+$#'];
        yield 'public path with leading slash' => ['/fileadmin/look/site.css', '#^/?fileadmin/look/site\.css\?\w+$#'];
        yield 'absolute URL' => ['https://cdn.example.org/site.css', '#^https://cdn\.example\.org/site\.css$#'];
        yield 'protocol relative URL' => ['//cdn.example.org/site.css', '#^//cdn\.example\.org/site\.css$#'];
    }

    #[Test]
    #[DataProvider('resolvableValues')]
    public function resolvesToThePublicUri(string $value, string $expectedPattern): void
    {
        self::assertMatchesRegularExpression($expectedPattern, (string)(new PublicResourceUri())->resolve($value));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function systemResourceIdentifiers(): iterable
    {
        yield 'package identifier' => ['PKG:flowd/typo3-look:Resources/Public/Css/Backend/iFramePreview.css', '#^/typo3conf/ext/look/Resources/Public/Css/Backend/iFramePreview\.css\?\w+$#'];
        yield 'FAL identifier' => ['FAL:1:/look/site.css', '#^/fileadmin/look/site\.css\?\w+$#'];
        yield 'URI identifier' => ['URI:https://cdn.example.org/site.css', '#^https://cdn\.example\.org/site\.css$#'];
    }

    #[Test]
    #[DataProvider('systemResourceIdentifiers')]
    public function resolvesSystemResourceIdentifiersOnTypo3WithSystemResourceApi(string $value, string $expectedPattern): void
    {
        if (!class_exists(SystemResourceFactory::class)) {
            self::markTestSkipped('System resource identifiers exist since TYPO3 14');
        }
        self::assertMatchesRegularExpression($expectedPattern, (string)(new PublicResourceUri())->resolve($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unresolvableValues(): iterable
    {
        yield 'private extension resource' => ['EXT:look/Resources/Private/Templates/Backend/ContentPreview/IFramePreview.html'];
        yield 'unknown extension' => ['EXT:no_such_extension/Resources/Public/site.css'];
        yield 'missing public file' => ['fileadmin/look/missing.css'];
        yield 'data URI' => ['data:text/css,body{}'];
    }

    #[Test]
    #[DataProvider('unresolvableValues')]
    public function unresolvableValuesYieldNullAndLogAWarning(string $value): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string|\Stringable, array<mixed>}> */
            public array $records = [];

            /**
             * @param array<mixed> $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }
        };
        $subject = new PublicResourceUri();
        $subject->setLogger($logger);

        self::assertNull($subject->resolve($value));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertSame(['asset' => $value], $logger->records[0][2]);
    }
}
