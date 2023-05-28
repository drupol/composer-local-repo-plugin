<?php

declare(strict_types=1);

namespace test\loophp\ComposerLocalRepoPlugin\Integration\Command\BuildLocalRepo;

use Composer\Console\Application;
use Error;
use InvalidArgumentException;
use loophp\ComposerLocalRepoPlugin\Plugin as ComposerPlugin;
use PHPUnit\Framework;
use RuntimeException;
use Symfony\Component\Console;
use Symfony\Component\Filesystem;
use test\loophp\ComposerLocalRepoPlugin\Util\CommandInvocation;
use test\loophp\ComposerLocalRepoPlugin\Util\Directory;
use test\loophp\ComposerLocalRepoPlugin\Util\Scenario;
use test\loophp\ComposerLocalRepoPlugin\Util\State;

use function is_string;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

/**
 * @internal
 *
 * @coversNothing
 */
abstract class AbstractTestCase extends Framework\TestCase
{
    private string $currentWorkingDirectory;

    final protected function setUp(): void
    {
        self::fileSystem()->remove(self::temporaryDirectory());
        self::fileSystem()->remove(self::temporaryRepoDirectory());

        $currentWorkingDirectory = getcwd();

        if (false === $currentWorkingDirectory) {
            throw new RuntimeException('Unable to determine current working directory.');
        }

        $this->currentWorkingDirectory = $currentWorkingDirectory;
    }

    final protected function tearDown(): void
    {
        self::fileSystem()->remove(self::temporaryDirectory());
        self::fileSystem()->remove(self::temporaryRepoDirectory());

        chdir($this->currentWorkingDirectory);
    }

    public function getRandomRepoDirectory(): string
    {
        $directory = realpath($this->temporaryRepoDirectory());

        if (false === $directory) {
            throw new Error('Unable to determine temporary repo directory.');
        }

        return $directory;
    }

    public static function temporaryRepoDirectory(): string
    {
        return __DIR__ . '/../../../../.build/test/repo';
    }

    final protected static function assertComposerJsonFileExists(State $state): void
    {
        self::assertFileExists($state->composerJsonFile()->path());
    }

    final protected static function assertComposerJsonFileModified(
        State $expected,
        State $actual,
    ): void {
        self::assertComposerJsonFileExists($actual);

        self::assertNotEquals(
            $expected->composerJsonFile()->contents(),
            $actual->composerJsonFile()->contents(),
            'Failed asserting that initial composer.json has been modified.',
        );
    }

    final protected static function assertComposerLockFileExists(State $state): void
    {
        self::assertFileExists($state->composerLockFile()->path());
    }

    final protected static function assertComposerLockFileFresh(State $state): void
    {
        self::assertComposerJsonFileExists($state);
        self::assertComposerLockFileExists($state);

        $exitCode = self::validateComposer($state);

        self::assertSame(0, $exitCode, sprintf(
            'Failed asserting that composer.lock is fresh in %s.',
            $state->directory()->path(),
        ));
    }

    final protected static function assertComposerLockFileModified(
        State $expected,
        State $actual,
    ): void {
        self::assertComposerLockFileExists($actual);

        self::assertJsonStringNotEqualsJsonString(
            self::normalizeLockFileContents($expected->composerLockFile()->contents()),
            self::normalizeLockFileContents($actual->composerLockFile()->contents()),
            'Failed asserting that initial composer.lock has been modified.',
        );
    }

    final protected static function assertComposerLockFileNotExists(State $state): void
    {
        self::assertFileDoesNotExist($state->composerLockFile()->path());
    }

    final protected static function assertComposerLockFileNotFresh(State $state): void
    {
        self::assertComposerJsonFileExists($state);
        self::assertComposerLockFileExists($state);

        $exitCode = self::validateComposer($state);

        self::assertNotSame(0, $exitCode, sprintf(
            'Failed asserting that composer.lock is not fresh in %s.',
            $state->directory()->path(),
        ));
    }

    final protected static function assertComposerLockFileNotModified(
        State $expected,
        State $actual,
    ): void {
        self::assertComposerLockFileExists($actual);

        self::assertJsonStringEqualsJsonString(
            self::normalizeLockFileContents($expected->composerLockFile()->contents()),
            self::normalizeLockFileContents($actual->composerLockFile()->contents()),
            'Failed asserting that initial composer.lock has not been modified.',
        );
    }

    final protected static function assertExitCodeSame(
        int $expected,
        int $actual,
    ): void {
        self::assertSame($expected, $actual, sprintf(
            'Failed asserting that exit code %d is identical to %d.',
            $actual,
            $expected,
        ));
    }

    final protected static function createApplication(): Application
    {
        $application = new Application();

        foreach ((new ComposerPlugin())->getCommands() as $command) {
            $application->add($command);
        }

        $application->setAutoExit(false);

        return $application;
    }

    final protected static function createScenario(
        CommandInvocation $commandInvocation,
        string $fixtureDirectory,
    ): Scenario {
        if (!is_dir($fixtureDirectory)) {
            throw new InvalidArgumentException(sprintf(
                'Fixture directory "%s" does not exist',
                $fixtureDirectory,
            ));
        }

        $fileSystem = self::fileSystem();

        $fileSystem->remove(self::temporaryDirectory());

        $fileSystem->mirror(
            $fixtureDirectory,
            self::temporaryDirectory(),
        );

        $fileSystem->mkdir(self::temporaryRepoDirectory());

        $scenario = Scenario::fromCommandInvocationAndInitialState(
            $commandInvocation,
            State::fromDirectory(Directory::fromPath(self::temporaryDirectory())),
        );

        if ($commandInvocation->is(CommandInvocation::inCurrentWorkingDirectory())) {
            chdir($scenario->directory()->path());
        }

        return $scenario;
    }

    private static function fileSystem(): Filesystem\Filesystem
    {
        return new Filesystem\Filesystem();
    }

    private static function normalizeLockFileContents(string $contents): string
    {
        $decoded = json_decode(
            $contents,
            true,
        );

        unset($decoded['plugin-api-version']);

        $normalized = json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        if (!is_string($normalized)) {
            throw new RuntimeException('Failed normalizing contents of lock file.');
        }

        return $normalized;
    }

    private static function temporaryDirectory(): string
    {
        return __DIR__ . '/../../../../.build/test/';
    }

    private static function validateComposer(State $state): int
    {
        $application = new Application();

        $application->setAutoExit(false);

        return $application->run(
            new Console\Input\ArrayInput([
                'command' => 'validate',
                '--no-check-publish' => true,
                '--working-dir' => $state->directory()->path(),
            ]),
            new Console\Output\BufferedOutput(),
        );
    }
}
