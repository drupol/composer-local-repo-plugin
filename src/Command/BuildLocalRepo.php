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
use Composer\PartialComposer;
use Composer\Util\Filesystem;
use Composer\Util\Loop;
use Composer\Util\SyncHelper;
use Exception;
use Generator;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

final class BuildLocalRepo extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('build-local-repo')
            ->setDescription(<<<EOF
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
        $composer = $this->requireComposer(true, true);
        $locker = $this->requireComposer(true, true)->getLocker();
        $fs = new Filesystem();

        if (false === $locker->isLocked()) {
            throw new Exception('Composer lock file does not exist.');
        }

        $repoDir = $input->getArgument('repo-dir');

        if (true === $input->getOption('only-print-manifest')) {
            try {
                $packages = $this->buildManifest($input, $locker, $repoDir);
                $output->writeln(json_encode(['packages' => $packages], JSON_PRETTY_PRINT));
            } catch (Throwable $exception) {
                $output->writeln(
                    sprintf('Could not print manifest file: %s', $exception->getMessage())
                );

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        if (false === $input->getOption('only-manifest')) {
            if (false === $repoDir = realpath($repoDir)) {
                $output->writeln(
                    sprintf('Target repository directory "%s" does not exist.', $input->getArgument('repo-dir'))
                );

                return Command::FAILURE;
            }

            if (false === $fs->isDirEmpty($repoDir)) {
                $output->writeln(
                    sprintf('Target repository directory "%s" is not empty.', $input->getArgument('repo-dir'))
                );

                return Command::FAILURE;
            }

            try {
                $this->buildRepo($composer, $input, $locker, $repoDir);
            } catch (Throwable $exception) {
                $output->writeln(
                    sprintf('Could not build repository: %s', $exception->getMessage())
                );

                return Command::FAILURE;
            }

            $output->writeln(
                sprintf('Local repository has been successfully created in %s', $repoDir)
            );
        }

        if (false === $input->getOption('only-repo')) {
            try {
                $packages = $this->buildManifest($input, $locker, $repoDir);
                (new JsonFile(sprintf('%s/packages.json', $repoDir)))->write(['packages' => $packages]);
            } catch (Throwable $exception) {
                $output->writeln(
                    sprintf('Could not build manifest file: %s', $exception->getMessage())
                );

                return Command::FAILURE;
            }

            $output->writeln(
                sprintf('Local repository manifest "packages.json" has been successfully created in %s', $repoDir)
            );
        }

        return Command::SUCCESS;
    }

    private function buildManifest(InputInterface $input, Locker $locker, string $repoDir): array
    {
        $packages = [];
        foreach ($this->iterLockedPackages($input, $locker) as $packageInfo) {
            unset($packageInfo['source']);
            $version = $packageInfo['version'];
            $reference = $packageInfo['dist']['reference'];
            $name = $packageInfo['name'];
            $packagePath = sprintf('%s/%s/%s', $repoDir, $name, $version);

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
            if ($packageInfo['dist']['type'] !== 'path') {
                $packageInfo['dist'] = [
                    'reference' => $reference,
                    'type' => 'path',
                    'url' => $packagePath,
                ];
            }

            $packages[$name][$version] = $packageInfo;
        }

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
     * Shamelessly copied from https://github.com/composer/composer/blob/52f6f74b7c342f7b90be9c1a87e183092e8ab452/src/Composer/Util/SyncHelper.php
     *
     * Uses the `DownloaderManager` instead of a `Downloader` to download and install a package.
     */
    private function downloadAndInstallPackageSync(Loop $loop, DownloadManager $downloadManager, string $path, PackageInterface $package, ?PackageInterface $prevPackage = null): void
    {
        $type = $prevPackage ? 'update' : 'install';

        try {
            $this->await($loop, $downloadManager->download($package, $path, $prevPackage));

            $this->await($loop, $downloadManager->prepare($type, $package, $path, $prevPackage));

            if ($type === 'update') {
                $this->await($loop, $downloadManager->update($package, $prevPackage, $path));
            } else {
                $this->await($loop, $downloadManager->install($package, $path));
            }
        } catch (\Exception $e) {
            $this->await($loop, $downloadManager->cleanup($type, $package, $path, $prevPackage));
            throw $e;
        }

        $this->await($loop, $downloadManager->cleanup($type, $package, $path, $prevPackage));
    }

    private function await(Loop $loop, ?PromiseInterface $promise = null): void
    {
        if ($promise) {
            $loop->wait([$promise]);
        }
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
