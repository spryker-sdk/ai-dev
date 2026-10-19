<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\AiToolSetup;

interface AiToolArtifactGeneratorInterface
{
    /**
     * @return array<string>
     */
    public function listRuleTargetPaths(string $tool, ArtifactMode $mode = ArtifactMode::Real): array;

    /**
     * @param array<string> $skipPaths
     *
     * @return array<string>
     */
    public function generateRules(string $tool, array $skipPaths = [], ArtifactMode $mode = ArtifactMode::Real): array;

    public function listAgentsFileTargetPath(string $tool, ArtifactMode $mode = ArtifactMode::Real): string;

    /**
     * @param array<string> $skipPaths
     */
    public function generateAgentsFile(string $tool, array $skipPaths = [], ArtifactMode $mode = ArtifactMode::Real): ?string;

    /**
     * @return array<string>
     */
    public function listSkillsTargetPaths(string $tool, ArtifactMode $mode = ArtifactMode::Real): array;

    /**
     * @param array<string> $skipPaths
     *
     * @return array<string>
     */
    public function generateSkills(string $tool, array $skipPaths = [], ArtifactMode $mode = ArtifactMode::Real): array;

    /**
     * @return array<string>
     */
    public function listAgentsTargetPaths(string $tool, ArtifactMode $mode = ArtifactMode::Real): array;

    /**
     * @param array<string> $skipPaths
     *
     * @return array<string>
     */
    public function generateAgents(string $tool, array $skipPaths = [], ArtifactMode $mode = ArtifactMode::Real): array;

    public function toRelativePath(string $absolutePath): string;
}
