<?php

declare(strict_types=1);

namespace test\loophp\ComposerLocalRepoPlugin\Util;

final class Scenario
{
    private function __construct(
        private CommandInvocation $commandInvocation,
        private State $initialState,
    ) {
    }

    public function commandInvocation(): CommandInvocation
    {
        return $this->commandInvocation;
    }

    /**
     * @return array<string, string>
     */
    public function consoleParameters(): array
    {
        $parameters = [
            'command' => 'build-local-repo',
        ];

        if ($this->commandInvocation->is(CommandInvocation::usingWorkingDirectoryOption())) {
            return $parameters + [
                '--working-dir' => $this->initialState->directory()->path(),
            ];
        }

        return $parameters;
    }

    /**
     * @param array<string, bool|int|string> $parameters
     *
     * @return array<string, bool|int|string>
     */
    public function consoleParametersWith(array $parameters): array
    {
        return array_merge(
            $this->consoleParameters(),
            $parameters,
        );
    }

    public function currentState(): State
    {
        return State::fromDirectory($this->initialState->directory());
    }

    public function directory(): Directory
    {
        return $this->initialState->directory();
    }

    public static function fromCommandInvocationAndInitialState(
        CommandInvocation $commandInvocation,
        State $initialState,
    ): self {
        return new self(
            $commandInvocation,
            $initialState,
        );
    }

    public function initialState(): State
    {
        return $this->initialState;
    }
}
