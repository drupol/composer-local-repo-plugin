<?php

declare(strict_types=1);

namespace loophp\ComposerLocalRepoPlugin\Service;

use Composer\Package\Locker;
use Generator;

abstract class LocalBuilder
{
    /**
     * @return Generator<int, array<string, mixed>>
     */
    protected function iterLockedPackages(Locker $locker, bool $includeDevDeps = true): Generator
    {
        $data = $locker->getLockData();

        $packages = $data['packages'] ?? [];
        ksort($packages);

        foreach ($packages as $packageInfo) {
            ksort($packageInfo);

            yield $packageInfo;
        }

        $devPackages = $data['packages-dev'] ?? [];
        ksort($devPackages);

        if (true === $includeDevDeps) {
            foreach ($devPackages as $packageInfo) {
                ksort($packageInfo);

                yield $packageInfo;
            }
        }
    }
}
