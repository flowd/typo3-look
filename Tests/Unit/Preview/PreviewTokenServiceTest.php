<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Tests\Unit\Preview;

use Flowd\Typo3Look\Preview\InvalidPreviewTokenException;
use Flowd\Typo3Look\Preview\PreviewDescriptor;
use Flowd\Typo3Look\Preview\PreviewTokenService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PreviewTokenServiceTest extends UnitTestCase
{
    private PreviewTokenService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS'] = ['SYS' => ['encryptionKey' => str_repeat('k', 96)]];
        $this->subject = new PreviewTokenService(new HashService());
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    #[Test]
    public function aSignedDescriptorRoundTrips(): void
    {
        $descriptor = $this->descriptor();

        $verified = $this->subject->verifyDescriptor($this->subject->signDescriptor($descriptor), now: 1000);

        self::assertEquals($descriptor, $verified);
    }

    #[Test]
    public function anOldDescriptorIsRefusedAsExpired(): void
    {
        $signed = $this->subject->signDescriptor($this->descriptor());

        self::assertSame(42, $this->subject->verifyDescriptor($signed, now: 1000 + PreviewTokenService::DESCRIPTOR_LIFETIME)->uid);
        try {
            $this->subject->verifyDescriptor($signed, now: 1000 + PreviewTokenService::DESCRIPTOR_LIFETIME + 1);
            self::fail('expected the descriptor to be refused');
        } catch (InvalidPreviewTokenException $e) {
            self::assertTrue($e->expired);
            self::assertSame(1790100018, $e->getCode());
        }
    }

    #[Test]
    public function aDescriptorFromTheFutureIsRefused(): void
    {
        $signed = $this->subject->signDescriptor($this->descriptor());

        $this->expectException(InvalidPreviewTokenException::class);
        $this->subject->verifyDescriptor($signed, now: 1000 - 61);
    }

    #[Test]
    public function aTamperedDescriptorIsRefused(): void
    {
        $signed = $this->subject->signDescriptor($this->descriptor());
        [$payload, $signature] = explode('.', $signed);
        $other = $this->descriptor(uid: 99);
        [$otherPayload] = explode('.', $this->subject->signDescriptor($other));

        $this->expectException(InvalidPreviewTokenException::class);
        $this->expectExceptionCode(1790100015);
        $this->subject->verifyDescriptor($otherPayload . '.' . $signature, now: 1000);
    }

    #[Test]
    public function aDescriptorIsNotAcceptedAsToken(): void
    {
        $this->expectException(InvalidPreviewTokenException::class);
        $this->subject->verifyToken($this->subject->signDescriptor($this->descriptor()));
    }

    #[Test]
    public function aTokenRoundTripsWithinItsLifetime(): void
    {
        $descriptor = $this->descriptor();
        $token = $this->subject->issueToken($descriptor, now: 1000);

        self::assertEquals($descriptor, $this->subject->verifyToken($token, now: 1000 + PreviewTokenService::TOKEN_LIFETIME));
    }

    #[Test]
    public function anExpiredTokenIsRefusedAndMarkedAsExpired(): void
    {
        $token = $this->subject->issueToken($this->descriptor(), now: 1000);

        try {
            $this->subject->verifyToken($token, now: 1000 + PreviewTokenService::TOKEN_LIFETIME + 1);
            self::fail('expected the token to be expired');
        } catch (InvalidPreviewTokenException $e) {
            self::assertTrue($e->expired);
            self::assertSame(1790100012, $e->getCode());
        }
    }

    #[Test]
    public function aTokenWithAnotherSignatureIsNotExpiredButInvalid(): void
    {
        $token = $this->subject->issueToken($this->descriptor(), now: 1000);
        $forged = substr($token, 0, -4) . 'abcd';

        try {
            $this->subject->verifyToken($forged, now: 1000);
            self::fail('expected the token to be refused');
        } catch (InvalidPreviewTokenException $e) {
            self::assertFalse($e->expired);
        }
    }

    #[Test]
    public function malformedValuesAreRefused(): void
    {
        foreach (['', 'no-dot', '.', 'a.', '.b', 'not-base64!.sig'] as $value) {
            try {
                $this->subject->verifyDescriptor($value);
                self::fail('expected "' . $value . '" to be refused');
            } catch (InvalidPreviewTokenException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function descriptorFieldsAreTypeChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1790100002);
        PreviewDescriptor::fromArray(['table' => 'tt_content', 'uid' => '42']);
    }

    private function descriptor(int $uid = 42): PreviewDescriptor
    {
        return new PreviewDescriptor(
            table: 'tt_content',
            uid: $uid,
            pid: 7,
            workspace: 0,
            language: 1,
            typoScriptObjectPath: 'tt_content',
            scale: 0.5,
            height: 300,
            bodyClass: 'theme-forest',
            css: ['EXT:my_site/Resources/Public/Css/main.css'],
            js: [],
            backendUserId: 3,
            issued: 1000,
        );
    }
}
