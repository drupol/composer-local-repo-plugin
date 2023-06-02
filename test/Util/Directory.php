<?php

declare(strict_types=1);

namespace test\loophp\ComposerLocalRepoPlugin\Util;

final class Directory
{
    private bool $exists;

    private function __construct(private string $path)
    {
        $this->exists = file_exists($path) && is_dir($path);
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public static function fromPath(string $path): self
    {
        return new self($path);
    }

    public function path(): string
    {
        return $this->path;
    }
}
