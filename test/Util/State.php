<?php

declare(strict_types=1);

namespace test\loophp\ComposerLocalRepoPlugin\Util;

final class State
{
    private function __construct(
        private Directory $directory,
        private File $composerJsonFile,
        private File $composerLockFile,
    ) {
    }

    public function composerJsonFile(): File
    {
        return $this->composerJsonFile;
    }

    public function composerLockFile(): File
    {
        return $this->composerLockFile;
    }

    public function directory(): Directory
    {
        return $this->directory;
    }

    public static function fromDirectory(Directory $directory): self
    {
        return new self(
            $directory,
            File::fromPath(sprintf(
                '%s/composer.json',
                $directory->path(),
            )),
            File::fromPath(sprintf(
                '%s/composer.lock',
                $directory->path(),
            )),
        );
    }
}
