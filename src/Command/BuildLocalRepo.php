<?php

declare(strict_types=1);

namespace loophp\ComposerLocalRepoPlugin\Command;

use Composer\Command\BaseCommand;
use Composer\Util\Filesystem;
use Exception;
use loophp\ComposerLocalRepoPlugin\Service\ManifestBuilder;
use loophp\ComposerLocalRepoPlugin\Service\RepoBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

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
        $fs = new Filesystem();
        $includeDevDeps = false === $input->getOption('no-dev');
        $repoBuilder = new RepoBuilder();
        $manifestBuilder = new ManifestBuilder();

        if (false === $composer->getLocker()->isLocked()) {
            throw new Exception('Composer lock file does not exist.');
        }

        $repoDir = $input->getArgument('repo-dir');

        if (false === is_dir($repoDir)) {
            $io
                ->writeError(
                    '<error>The provided `repo-dir` argument is not a directory.</error>'
                );

            return Command::FAILURE;
        }

        if (true === $input->getOption('only-print-manifest')) {
            $filepath = tempnam(sys_get_temp_dir(), '');

            if (false === $filepath) {
                $io
                    ->writeError(
                        '<error>Unable to create temporary file.</error>'
                    );

                return Command::FAILURE;
            }

            try {
                $manifestBuilder->build($composer, $repoDir, $filepath, $includeDevDeps);
            } catch (Throwable $exception) {
                $io
                    ->writeError(
                        sprintf('<error>Could not print manifest file: %s</error>', $exception->getMessage())
                    );

                return Command::FAILURE;
            }

            if (false === $fileContent = file_get_contents($filepath)) {
                $io
                    ->writeError(
                        '<error>Unable to read temporary manifest file.</error>'
                    );

                return Command::FAILURE;
            }

            $output->writeln($fileContent);
            $fs->unlink($filepath);

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
                $repoBuilder->build(
                    $composer,
                    $repoDir,
                    $includeDevDeps
                );
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
                $manifestBuilder->build($composer, $repoDir, sprintf('%s/packages.json', $repoDir), $includeDevDeps);
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
}
