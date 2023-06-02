<?php

declare(strict_types=1);

namespace test\loophp\ComposerLocalRepoPlugin\Integration\Command\BuildLocalRepo\Simple;

use Symfony\Component\Console;
use test\loophp\ComposerLocalRepoPlugin\Integration\Command\BuildLocalRepo\AbstractTestCase;
use test\loophp\ComposerLocalRepoPlugin\Util;

/**
 * @internal
 *
 * @coversNothing
 */
final class Test extends AbstractTestCase
{
    /**
     * @dataProvider \test\loophp\ComposerLocalRepoPlugin\DataProvider\Command\BuildLocalRepoProvider::simpleCommandInvocation
     */
    public function testSucceeds(
        Util\CommandInvocation $commandInvocation
    ): void {
        $scenario = self::createScenario(
            $commandInvocation,
            __DIR__ . '/fixture',
        );

        $initialState = $scenario->initialState();

        self::assertComposerJsonFileExists($initialState);
        self::assertComposerLockFileExists($initialState);

        $application = self::createApplication();

        $tempDir = $this->getRandomRepoDirectory();

        $input = new Console\Input\ArrayInput(
            $scenario->consoleParameters() + [
                'repo-dir' => $tempDir,
            ]
        );
        $output = new Console\Output\BufferedOutput();

        $exitCode = $application->run($input, $output);
        self::assertExitCodeSame(0, $exitCode);
        $display = $output->fetch();
        self::assertStringContainsString(
            sprintf(
                'Local repository has been successfully created in %s',
                $tempDir
            ),
            $display
        );
        self::assertStringContainsString(
            sprintf(
                'Local repository manifest "packages.json" has been successfully created in %s',
                $tempDir
            ),
            $display
        );

        $exitCode = $application->run(
            new Console\Input\ArrayInput([
                '--working-dir' => $scenario->initialState()->directory()->path(),
                'command' => 'config',
                'setting-key' => 'repo.packagist',
                'setting-value' => ['false'],
            ]),
            $output
        );
        self::assertExitCodeSame(0, $exitCode);

        $exitCode = $application->run(
            new Console\Input\ArrayInput([
                '--working-dir' => $scenario->initialState()->directory()->path(),
                'command' => 'config',
                'setting-key' => 'repo.local',
                'setting-value' => ['composer', sprintf('file://%s/packages.json', $tempDir)],
            ]),
            $output
        );
        $display = $output->fetch();
        self::assertExitCodeSame(0, $exitCode);

        $exitCode = $application->run(
            new Console\Input\ArrayInput([
                '--working-dir' => $scenario->initialState()->directory()->path(),
                'command' => 'install',
                '--no-autoloader',
            ]),
            $output
        );
        self::assertExitCodeSame(0, $exitCode);

        $display = $output->fetch();
        self::assertStringContainsString(
            'Generating autoload files',
            $display
        );

        self::assertDirectoryExists($tempDir);
        self::assertFileExists($tempDir . '/packages.json');

        $packages = json_decode(
            (string) file_get_contents($tempDir . '/packages.json'),
            true
        );

        self::assertCount(26, $packages['packages']);

        foreach ($packages['packages'] as $versionPackage) {
            $package = current($versionPackage);
            self::assertDirectoryExists($package['dist']['url']);
        }
    }
}
