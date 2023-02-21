<?php

declare(strict_types=1);

namespace Packeton\Mirror\Model;

class JsonMetadata
{
    use GZipTrait;

    protected bool $notModified = false;

    public function __construct(
        private string $content,
        private ?int $unix = null,
        private ?string $hash = null,
        private array $options = [],
    ) {
        if (null === $this->unix) {
            $this->unix = \time();
        }
    }

    public function lastModified(): \DateTimeInterface
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->setTimestamp($this->unix);
    }

    public function isNotModified(): bool
    {
        return $this->notModified;
    }

    public function getContent(): string
    {
        return $this->decode($this->content);
    }

    public function decodeJson(): array
    {
        $data = \json_decode($this->getContent(), true);
        return \is_array($data) ? $data : [];
    }

    public function hash(): ?string
    {
        return $this->hash;
    }

    public function setOptions(array $options): void
    {
        $this->options = \array_merge($this->options, $options);
    }

    public function setOption(string $name, mixed $value): void
    {
        $this->options[$name] = $value;
    }

    public function getOptions(): MetadataOptions
    {
        return new MetadataOptions($this->options);
    }

    public function withContent(string|array|callable $content, int $flags = \JSON_UNESCAPED_SLASHES): self
    {
        if (\is_callable($content)) {
            $content = $content($this->decodeJson());
        }

        $content = \is_array($content) ? \json_encode($content, $flags) : $content;

        $clone = clone $this;
        $clone->content = $content;
        $clone->hash = null;

        return $clone;
    }

    public static function createNotModified(int $unix): static
    {
        $object = new static('', $unix);
        $object->notModified = true;

        return $object;
    }
}
