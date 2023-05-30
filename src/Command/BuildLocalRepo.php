<?php

declare(strict_types=1);

namespace loophp\ComposerLocalRepoPlugin\Command;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Downloader\DownloadManager;
use Composer\Json\JsonFile;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\Loop;
use Exception;
use Generator;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use const JSON_PRETTY_PRINT;

final class BuildLocalRepo extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('build-local-repo')
            ->setDescription(
                <<<'EOF'
                    Create local repositories with type "composer" for offline use.
                      This command will create a repository in a given existing directory.
                      By default, the repository and its manifest file "packages.json" will be created in the same target directory.
                    EOF
            )
            ->addArgument('repo-dir', InputArgument::REQUIRED, 'Target directory to create repository in, it must exist already.')
            ->addOption('no-dev', null, InputOption::VALUE_NONE, 'Skip development dependencies.')
            ->addOption('only-repo', 'r', InputOption::VALUE_NONE, 'Generate only the repository, without the manifest file "packages.json".')
            ->addOption('only-manifest', 'm', InputOption::VALUE_NONE, 'Generate only the manifest "packages.json", without the repository.')
            ->addOption('only-print-manifest', 'p', InputOption::VALUE_NONE, 'Print the manifest for a given arbitrary repository directory.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $composer = $this->requireComposer(true, true);
        $locker = $composer->getLocker();
        $fs = new Filesystem();

        if (false === $locker->isLocked()) {
            throw new Exception('Composer lock file does not exist.');
        }

        $repoDir = $input->getArgument('repo-dir');

        if (true === $input->getOption('only-print-manifest')) {
            try {
                $output->writeln((string) json_encode(
                    ['packages' => $this->buildManifest($input, $locker, $repoDir)],
                    JSON_PRETTY_PRINT
                ));
            } catch (Throwable $exception) {
                $io
                    ->writeError(
                        sprintf('<error>Could not print manifest file: %s</error>', $exception->getMessage())
                    );

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        if (false === $input->getOption('only-manifest')) {
            if (false === $repoDir = realpath($repoDir)) {
                $io
                    ->writeError(
                        sprintf('<error>Target repository directory "%s" does not exist.</error>', $input->getArgument('repo-dir'))
                    );

                return Command::FAILURE;
            }

            if (false === $fs->isDirEmpty($repoDir)) {
                $io
                    ->writeError(
                        sprintf('<error>Target repository directory "%s" is not empty.</error>', $input->getArgument('repo-dir'))
                    );

                return Command::FAILURE;
            }

            try {
                $this->buildRepo($composer, $input, $locker, $repoDir);
            } catch (Throwable $exception) {
                $io
                    ->writeError(
                        sprintf('<error>Could not build repository: %s</error>', $exception->getMessage())
                    );

                return Command::FAILURE;
            }

            $io->write(
                sprintf('<info>Local repository has been successfully created in %s</info>', $repoDir)
            );
        }

        if (false === $input->getOption('only-repo')) {
            try {
                (new JsonFile(sprintf('%s/packages.json', $repoDir)))
                    ->write(['packages' => $this->buildManifest($input, $locker, $repoDir)]);
            } catch (Throwable $exception) {
                $io
                    ->writeError(
                        sprintf('<error>Could not build manifest file: %s</error>', $exception->getMessage())
                    );

                return Command::FAILURE;
            }

            $io->write(
                sprintf('<info>Local repository manifest "packages.json" has been successfully created in %s</info>', $repoDir)
            );
        }

        return Command::SUCCESS;
    }

    private function await(Loop $loop, ?PromiseInterface $promise = null): void
    {
        if (null !== $promise) {
            $loop->wait([$promise]);
        }
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function buildManifest(InputInterface $input, Locker $locker, string $repoDir): array
    {
        $packages = [];
        $loader = new ArrayLoader(null, true);

        foreach ($this->iterLockedPackages($input, $locker) as $packageInfo) {
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
                ? ['type' => 'path', 'url' => sprintf('%s/%s/%s', $repoDir, $name, $version)] + $source
                : $source;

            $packages[$name][$version] = $packageInfo;
        }

        ksort($packages);

        return $packages;
    }

    private function buildRepo(Composer $composer, InputInterface $input, Locker $locker, string $repoDir): void
    {
        $loop = $composer->getLoop();
        $loader = new ArrayLoader(null, true);
        $downloadManager = $composer->getDownloadManager();

        foreach ($this->iterLockedPackages($input, $locker) as $packageInfo) {
            $this->downloadAndInstallPackageSync(
                $loop,
                $downloadManager,
                sprintf('%s/%s/%s', $repoDir, $packageInfo['name'], $packageInfo['version']),
                $loader->load($packageInfo)
            );
        }
    }

    /**
     * Shamelessly copied from https://github.com/composer/composer/blob/52f6f74b7c342f7b90be9c1a87e183092e8ab452/src/Composer/Util/SyncHelper.php.
     *
     * Uses the `DownloaderManager` instead of a `Downloader` to download and install a package.
     */
    private function downloadAndInstallPackageSync(Loop $loop, DownloadManager $downloadManager, string $path, PackageInterface $package, ?PackageInterface $prevPackage = null): void
    {
        $type = null !== $prevPackage ? 'update' : 'install';

        try {
            $this->await($loop, $downloadManager->download($package, $path, $prevPackage));

            $this->await($loop, $downloadManager->prepare($type, $package, $path, $prevPackage));

            if ('update' === $type && null !== $prevPackage) {
                $this->await($loop, $downloadManager->update($package, $prevPackage, $path));
            } else {
                $this->await($loop, $downloadManager->install($package, $path));
            }
        } catch (Exception $e) {
            $this->await($loop, $downloadManager->cleanup($type, $package, $path, $prevPackage));

            throw $e;
        }

        $this->await($loop, $downloadManager->cleanup($type, $package, $path, $prevPackage));
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function iterLockedPackages(InputInterface $input, Locker $locker): Generator
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

        if (false === $input->getOption('no-dev')) {
            foreach ($devPackages as $packageInfo) {
                ksort($packageInfo);

                yield $packageInfo;
            }
        }
    }
}
