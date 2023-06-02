<?php

declare(strict_types=1);

namespace loophp\ComposerLocalRepoPlugin\Service;

use Composer\Composer;
use Composer\Json\JsonFile;
use Composer\Package\Loader\ArrayLoader;

final class ManifestBuilder extends LocalBuilder
{
    public function build(Composer $composer, string $destination, bool $includeDevDeps = true): void
    {
        $packages = [];
        $loader = new ArrayLoader(null, true);

        foreach ($this->iterLockedPackages($composer->getLocker(), $includeDevDeps) as $packageInfo) {
            $sourceOrigin = null !== $loader->load($packageInfo)->getDistType() ? 'dist' : 'source';

            /** @var string $name */
            $name = $packageInfo['name'];
            /** @var string $version */
            $version = $packageInfo['version'];
            $source = $packageInfo[$sourceOrigin];

            // While Composer repositories only really require `name`, `version` and `source`/`dist` fields,
            // we will use the original contents of the package’s entry from `composer.lock`, modifying just the sources.
            // Package entries in Composer repositories correspond to `composer.json` files [1]
            // and Composer appears to use them when regenerating the lockfile.
            // If we just used the minimal info, stuff like `autoloading` or `bin` programs would be broken.
            //
            // We cannot use `source` since Composer does not support path sources:
            //     "PathDownloader" is a dist type downloader and can not be used to download source
            //
            // [1]: https://getcomposer.org/doc/05-repositories.md#packages>
            $packageInfo[$sourceOrigin] = 'path' !== $source['type']
                ? ['type' => 'path', 'url' => sprintf('%s/%s/%s', $destination, $name, $version)] + $source
                : $source;

            $packages[$name][$version] = $packageInfo;
        }

        ksort($packages);

        (new JsonFile($destination))->write(['packages' => $packages]);
    }
}
