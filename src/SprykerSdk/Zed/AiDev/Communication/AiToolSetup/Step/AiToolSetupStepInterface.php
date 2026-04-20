<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\AiToolSetup\Step;

use Symfony\Component\Console\Output\OutputInterface;

interface AiToolSetupStepInterface
{
    /**
     * @return string
     */
    public function getLabel(): string;

    /**
     * @param string $tool
     *
     * @return bool
     */
    public function canExecuteForTool(string $tool): bool;

    /**
     * @return array<string>
     */
    public function getFallbackToolOptions(): array;

    /**
     * @return bool
     */
    public function supportsExampleMode(): bool;

    /**
     * @param string $tool
     * @param bool $asExample
     *
     * @return array<string>
     */
    public function listTargetPaths(string $tool, bool $asExample = true): array;

    /**
     * @param string $tool
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param array<string> $skipPaths
     * @param bool $asExample
     *
     * @return void
     */
    public function execute(string $tool, OutputInterface $output, array $skipPaths = [], bool $asExample = true): void;
}
