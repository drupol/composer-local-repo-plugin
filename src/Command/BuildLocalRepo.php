<?php

declare(strict_types=1);

namespace loophp\ComposerLocalRepoPlugin\Command;

use Composer\Command\BaseCommand;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Locker;
use Composer\Util\Filesystem;
use Exception;
use Generator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

final class BuildLocalRepo extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('build-local-repo')
            ->setDescription('Create local repositories with type "composer" for offline use.')
            ->addArgument('repo-dir', InputArgument::REQUIRED, 'Target directory to create repo in');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer(true, true);
        $downloadManager = $composer->getDownloadManager();
        $fs = new Filesystem();
        $repoDir = $input->getArgument('repo-dir');

        if (false === realpath($repoDir)) {
            throw new Exception('Repository path directory does not exist.');
        }

        $locker = $this->requireComposer(true, true)->getLocker();

        if (false === $locker->isLocked()) {
            throw new Exception('Composer lock file does not exist.');
        }

        $packages = [];
        foreach ($this->iterLockedPackages($locker) as [$packageInfo, $package]) {
            unset($packageInfo['source']);
            $version = $packageInfo['version'];
            $reference = $packageInfo['dist']['reference'];
            $name = $packageInfo['name'];
            $packagePath = sprintf('%s/%s/%s', $repoDir, $name, $version);
            $downloadManager = $downloadManager->setPreferSource(true);

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
            $packages[$name][$version] = [
                'dist' => [
                    'reference' => $reference,
                    'type' => 'path',
                    'url' => $packagePath,
                ],
            ] + $packageInfo;

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

        (new JsonFile(sprintf('%s/packages.json', $repoDir)))->write(['packages' => $packages]);

        $output->writeln(
            sprintf('Local composer repository has been successfully created in %s', $repoDir)
        );

        return Command::SUCCESS;
    }

    /**
     * @return Generator<int, CompletePackage>
     */
    private function iterLockedPackages(Locker $locker): Generator
    {
        $data = $locker->getLockData();
        $loader = new ArrayLoader(null, true);

        foreach ($data['packages'] ?? [] as $info) {
            yield [$info, $loader->load($info)];
        }

        foreach ($data['packages-dev'] ?? [] as $info) {
            yield [$info, $loader->load($info)];
        }
    }
}
