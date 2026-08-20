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
    public function getLabel(): string;

    public function canExecuteForTool(string $tool): bool;

    /**
     * @return array<string>
     */
    public function getFallbackToolOptions(): array;

    public function supportsExampleMode(): bool;

    /**
     * @return array<string>
     */
    public function listTargetPaths(string $tool, ArtifactMode $mode = ArtifactMode::Real): array;

    /**
     * @param array<string> $skipPaths
     */
    public function execute(string $tool, OutputInterface $output, array $skipPaths = [], ArtifactMode $mode = ArtifactMode::Real): void;
}
