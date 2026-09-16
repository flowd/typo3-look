<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Tests\Unit\Resource;

use Flowd\Typo3Look\Resource\PublicResourceUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PublicResourceUriTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function passThroughValues(): iterable
    {
        yield 'absolute URL' => ['https://cdn.example.org/site.css'];
        yield 'protocol relative URL' => ['//cdn.example.org/site.css'];
        yield 'site relative path' => ['/typo3temp/assets/site.css'];
        yield 'relative path' => ['fileadmin/site.css'];
        yield 'data URI' => ['data:text/css,body{}'];
    }

    #[Test]
    #[DataProvider('passThroughValues')]
    public function valuesWithoutExtPrefixPassThroughUnchanged(string $value): void
    {
        self::assertSame($value, (new PublicResourceUri())->resolve($value));
    }
}
