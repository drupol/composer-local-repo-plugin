<?php

declare(strict_types=1);

namespace loophp\ComposerLocalRepoPlugin\Command;

use Composer\Command\BaseCommand;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Util\Filesystem;
use Generator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class BuildLocalRepo extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('build-local-repo')
            ->setDescription('Create local composer repositories for offline use')
            ->addArgument('repo-dir', InputArgument::REQUIRED, 'Target directory to create repo in')
            ->addOption('no-dev', null, InputOption::VALUE_NONE, 'Disables installation of require-dev packages.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer(true, true);
        $downloadManager = $composer->getDownloadManager();
        $fs = new Filesystem();

        $packages = [];
        foreach ($this->iterLockedPackages($input) as [$packageInfo, $package]) {
            $version = $package->getVersion();
            $infos = [
                'dist' => [
                    'reference' => $package->getDistReference(),
                    'type' => 'path',
                    'url' => sprintf('%s/%s/%s', $input->getArgument('repo-dir'), $package->getName(), $version),
                ],
                'source' => [
                    'reference' => $package->getSourceReference() ?? $package->getDistReference(),
                    'type' => 'path',
                    'url' => sprintf('%s/%s', $input->getArgument('repo-dir'), $package->getName()),
                ],
            ] + $packageInfo;

            ksort($infos);

            $packages[$package->getPrettyName()] = [
                $version => $infos,
            ];

            $downloadManager
                ->setPreferSource(true)
                ->download(
                    $package,
                    sprintf('%s/%s/%s', $input->getArgument('repo-dir'), $package->getName(), $version),
                );

            $downloadManager
                ->setPreferSource(true)
                ->install(
                    $package,
                    sprintf('%s/%s/%s', $input->getArgument('repo-dir'), $package->getName(), $version),
                )
                ->then(
                    static function () use ($fs, $input, $package, $version): void {
                        $fs->removeDirectory(sprintf('%s/%s/%s/.git', $input->getArgument('repo-dir'), $package->getName(), $version));
                    }
                );
        }



        (new JsonFile(sprintf('%s/packages.json', $input->getArgument('repo-dir'))))->write(['packages' => $packages]);

        $output->writeln(
            sprintf('Local composer repository has been successfully created in %s', $input->getArgument('repo-dir'))
        );

        return Command::SUCCESS;
    }

    /**
     * @return Generator<int, CompletePackage>
     */
    private function iterLockedPackages(InputInterface $input): Generator
    {
        $locker = $this->requireComposer(true, true)->getLocker();

        if ($locker->isLocked() === false) {
            return;
        }

        $data = $locker->getLockData();
        $loader = new ArrayLoader(null, true);

        foreach ($data['packages'] ?? [] as $info) {
            yield [$info, $loader->load($info)];
        }

        if (false === $input->getOption('no-dev')) {
            foreach ($data['packages-dev'] ?? [] as $info) {
                yield [$info, $loader->load($info)];
            }
        }
    }
}
