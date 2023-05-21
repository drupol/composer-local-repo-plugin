<?php

declare(strict_types=1);

namespace loophp\ComposerLocalRepoPlugin\Command;

use Composer\Command\BaseCommand;
use Composer\Json\JsonFile;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Locker;
use Composer\Util\Filesystem;
use Exception;
use Generator;
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
            ->addOption('only-repo', 'r', InputOption::VALUE_NONE, 'Generate only the repository, without the manifest file "packages.json".')
            ->addOption('only-manifest', 'm', InputOption::VALUE_NONE, 'Generate only the manifest "packages.json", without the repository.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (false === $repoDir = realpath($input->getArgument('repo-dir'))) {
            $output->writeln(
                sprintf('Target repository directory "%s" does not exist.', $input->getArgument('repo-dir'))
            );

            return Command::FAILURE;
        }

        $locker = $this->requireComposer(true, true)->getLocker();

        if (false === $locker->isLocked()) {
            throw new Exception('Composer lock file does not exist.');
        }

        if (false === $input->getOption('only-manifest')) {
            try {
                $this->buildRepo($locker, $repoDir);
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
                $this->buildManifest($locker, $repoDir);
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

    private function buildManifest(Locker $locker, string $repoDir): void
    {
        $packages = [];
        foreach ($this->iterLockedPackages($locker) as $packageInfo) {
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
            $packageInfo['dist'] = [
                'reference' => $reference,
                'type' => 'path',
                'url' => $packagePath,
            ];

            $packages[$name][$version] = $packageInfo;
        }

        (new JsonFile(sprintf('%s/packages.json', $repoDir)))->write(['packages' => $packages]);
    }

    private function buildRepo(Locker $locker, string $repoDir): void
    {
        $composer = $this->requireComposer(true, true);
        $downloadManager = $composer->getDownloadManager()->setPreferSource(true);
        $fs = new Filesystem();
        $loader = new ArrayLoader(null, true);

        foreach ($this->iterLockedPackages($locker) as $packageInfo) {
            unset($packageInfo['source']);
            $version = $packageInfo['version'];
            $name = $packageInfo['name'];
            $packagePath = sprintf('%s/%s/%s', $repoDir, $name, $version);
            $package = $loader->load($packageInfo);

            $downloadManager
                ->download(
                    $package,
                    $packagePath,
                );

            $downloadManager
                ->install(
                    $package,
                    $packagePath,
                )
                ->then(
                    static fn (): bool => $fs->removeDirectory(sprintf('%s/.git', $packagePath))
                );
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function iterLockedPackages(Locker $locker): Generator
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

        foreach ($devPackages as $packageInfo) {
            ksort($packageInfo);
            yield $packageInfo;
        }
    }
}
