<?php

declare(strict_types=1);

namespace loophp\ComposerLocalRepoPlugin\Command;

use Composer\Command\BaseCommand;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
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

        $packages = [];
        foreach ($this->iterLockedPackages($input) as $package) {
            $packages[$package->getPrettyName()] = [
                $package->getPrettyVersion() => [
                    'name' => $package->getPrettyName(),
                    'version' => $package->getPrettyVersion(),
                    'dist' => [
                        'url' => sprintf('%s/%s/%s', $input->getArgument('repo-dir'), $package->getName(), $package->getPrettyVersion()),
                        'type' => 'path',
                        'reference' => $package->getDistReference(),
                    ],
                    'source' => [
                        'type' => 'path',
                        'url' => sprintf('%s/%s', $input->getArgument('repo-dir'), $package->getName()),
                        'reference' => $package->getSourceReference() ?? $package->getDistReference(),
                    ],
                ],
            ];

            $downloadManager
                ->setPreferSource(true)
                ->download(
                    $package,
                    sprintf('%s/%s/%s', $input->getArgument('repo-dir'), $package->getName(), $package->getPrettyVersion()),
                )
                ->then(
                    $output->writeln(
                        sprintf('Package %s has been downloaded...', $package->getPrettyName())
                    )
                );

            $downloadManager
                ->setPreferSource(true)
                ->install(
                    $package,
                    sprintf('%s/%s/%s', $input->getArgument('repo-dir'), $package->getName(), $package->getPrettyVersion()),
                )
                ->then(
                    $output->writeln(
                        sprintf('Package %s has been installed...', $package->getPrettyName())
                    )
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
            yield $loader->load($info);
        }

        if (true === $input->getOption('no-dev')) {
            foreach ($data['packages-dev'] ?? [] as $info) {
                yield $loader->load($info);
            }
        }
    }
}
