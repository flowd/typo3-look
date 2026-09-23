<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Preview;

use TYPO3\CMS\Core\Crypto\HashService;

/**
 * Signs and verifies preview descriptors and tokens. Both are stateless: a base64url encoded JSON
 * payload with an HMAC over the site's encryption key, nothing is persisted.
 *
 * Two stages, two secrets:
 *
 * - The descriptor is written into the page module. It grants nothing by itself; only an
 *   authenticated backend request (the token route) turns it into a token, only for the backend
 *   user it was issued for, only while that user may still see the record, and only for a limited
 *   time after the page module rendered it.
 * - The token is what the preview frame presents. It carries the descriptor plus an expiry a few
 *   seconds ahead, enough for the browser to request the frame right after receiving it. A token
 *   leaked through a log is worthless moments later and only ever rendered one element.
 */
final readonly class PreviewTokenService
{
    /** seconds a token stays valid after it was issued */
    public const TOKEN_LIFETIME = 5;

    /** seconds a descriptor in the page module can be exchanged for tokens; a page module open longer than that is reloaded */
    public const DESCRIPTOR_LIFETIME = 8 * 3600;

    private const DESCRIPTOR_SECRET = 'look/preview-descriptor';
    private const TOKEN_SECRET = 'look/preview-token';

    public function __construct(private HashService $hashService) {}

    public function signDescriptor(PreviewDescriptor $descriptor): string
    {
        return $this->encode($descriptor->toArray(), self::DESCRIPTOR_SECRET);
    }

    /**
     * @throws InvalidPreviewTokenException also for descriptors older than DESCRIPTOR_LIFETIME or from the future
     */
    public function verifyDescriptor(string $signedDescriptor, ?int $now = null): PreviewDescriptor
    {
        $descriptor = $this->descriptorFromPayload($this->decode($signedDescriptor, self::DESCRIPTOR_SECRET));
        $now ??= time();
        if ($descriptor->issued > $now + 60 || $descriptor->issued < $now - self::DESCRIPTOR_LIFETIME) {
            throw new InvalidPreviewTokenException('The preview descriptor is too old', 1790100018, expired: true);
        }

        return $descriptor;
    }

    public function issueToken(PreviewDescriptor $descriptor, ?int $now = null): string
    {
        return $this->encode(
            ['descriptor' => $descriptor->toArray(), 'expires' => ($now ?? time()) + self::TOKEN_LIFETIME],
            self::TOKEN_SECRET,
        );
    }

    /**
     * @throws InvalidPreviewTokenException
     */
    public function verifyToken(string $token, ?int $now = null): PreviewDescriptor
    {
        $payload = $this->decode($token, self::TOKEN_SECRET);
        $expires = $payload['expires'] ?? null;
        if (!is_int($expires)) {
            throw new InvalidPreviewTokenException('The preview token has no expiry', 1790100011);
        }
        if ($expires < ($now ?? time())) {
            throw new InvalidPreviewTokenException('The preview token has expired', 1790100012, expired: true);
        }
        $descriptor = $payload['descriptor'] ?? null;
        if (!is_array($descriptor)) {
            throw new InvalidPreviewTokenException('The preview token carries no descriptor', 1790100013);
        }

        return $this->descriptorFromPayload($descriptor);
    }

    /**
     * @param array<mixed> $payload
     */
    private function descriptorFromPayload(array $payload): PreviewDescriptor
    {
        try {
            return PreviewDescriptor::fromArray($payload);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidPreviewTokenException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param non-empty-string $secret
     */
    private function encode(array $payload, string $secret): string
    {
        $encoded = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $encoded . '.' . $this->hashService->hmac($encoded, $secret);
    }

    /**
     * @param non-empty-string $secret
     * @return array<mixed>
     * @throws InvalidPreviewTokenException
     */
    private function decode(string $value, string $secret): array
    {
        $parts = explode('.', $value, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidPreviewTokenException('Malformed preview token', 1790100014);
        }
        [$encoded, $signature] = $parts;
        if (!hash_equals($this->hashService->hmac($encoded, $secret), $signature)) {
            throw new InvalidPreviewTokenException('The preview token signature does not match', 1790100015);
        }
        $json = self::base64UrlDecode($encoded);
        try {
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidPreviewTokenException('The preview token payload is not valid JSON', 1790100016);
        }
        if (!is_array($payload)) {
            throw new InvalidPreviewTokenException('The preview token payload is not an object', 1790100017);
        }

        return $payload;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
