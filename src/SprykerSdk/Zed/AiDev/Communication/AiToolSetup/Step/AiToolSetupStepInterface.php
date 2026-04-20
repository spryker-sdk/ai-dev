<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\AiToolSetup\Step;

use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode;
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
     * @param \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode $mode
     *
     * @return array<string>
     */
    public function listTargetPaths(string $tool, ArtifactMode $mode = ArtifactMode::Real): array;

    /**
     * @param string $tool
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param array<string> $skipPaths
     * @param \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode $mode
     *
     * @return void
     */
    public function execute(string $tool, OutputInterface $output, array $skipPaths = [], ArtifactMode $mode = ArtifactMode::Real): void;
}
