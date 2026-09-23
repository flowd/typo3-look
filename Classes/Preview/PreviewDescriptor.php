<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Preview;

/**
 * Everything the preview request needs to render one content element: the record and its page,
 * workspace and language, the content object to render it with, the frame options of the preview
 * template, the backend user it was issued for and when. Created in the page module, signed, exchanged for a short-lived token in the browser
 * and read back in the isolated render request. Stateless: nothing is stored on the server.
 */
final readonly class PreviewDescriptor
{
    /**
     * @param list<string> $css
     * @param list<string> $js
     */
    public function __construct(
        public string $table,
        public int $uid,
        public int $pid,
        public int $workspace,
        public int $language,
        public string $typoScriptObjectPath,
        public float $scale,
        public int $height,
        public string $bodyClass,
        public array $css,
        public array $js,
        public int $backendUserId,
        /** unix timestamp the descriptor was written into the page module */
        public int $issued,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'uid' => $this->uid,
            'pid' => $this->pid,
            'workspace' => $this->workspace,
            'language' => $this->language,
            'typoScriptObjectPath' => $this->typoScriptObjectPath,
            'scale' => $this->scale,
            'height' => $this->height,
            'bodyClass' => $this->bodyClass,
            'css' => $this->css,
            'js' => $this->js,
            'backendUserId' => $this->backendUserId,
            'issued' => $this->issued,
        ];
    }

    /**
     * @param array<mixed> $data
     * @throws \InvalidArgumentException when a field is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::string($data, 'table'),
            self::int($data, 'uid'),
            self::int($data, 'pid'),
            self::int($data, 'workspace'),
            self::int($data, 'language'),
            self::string($data, 'typoScriptObjectPath'),
            self::float($data, 'scale'),
            self::int($data, 'height'),
            self::string($data, 'bodyClass'),
            self::stringList($data, 'css'),
            self::stringList($data, 'js'),
            self::int($data, 'backendUserId'),
            self::int($data, 'issued'),
        );
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Preview descriptor field "' . $key . '" must be a string', 1790100001);
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new \InvalidArgumentException('Preview descriptor field "' . $key . '" must be an integer', 1790100002);
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function float(array $data, string $key): float
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) && !is_float($value)) {
            throw new \InvalidArgumentException('Preview descriptor field "' . $key . '" must be a number', 1790100003);
        }

        return (float)$value;
    }

    /**
     * @param array<mixed> $data
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Preview descriptor field "' . $key . '" must be a list', 1790100004);
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('Preview descriptor field "' . $key . '" must contain strings only', 1790100005);
            }
            $result[] = $item;
        }

        return $result;
    }
}
