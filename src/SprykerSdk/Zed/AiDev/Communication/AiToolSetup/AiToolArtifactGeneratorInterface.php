<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\AiToolSetup;

interface AiToolArtifactGeneratorInterface
{
    /**
     * @param string $tool
     * @param bool $asExample
     *
     * @return array<string>
     */
    public function listRuleTargetPaths(string $tool, bool $asExample = true): array;

    /**
     * @param string $tool
     * @param array<string> $skipPaths
     * @param bool $asExample
     *
     * @return array<string>
     */
    public function generateRules(string $tool, array $skipPaths = [], bool $asExample = true): array;

    /**
     * @param string $tool
     * @param bool $asExample
     *
     * @return string
     */
    public function listAgentsFileTargetPath(string $tool, bool $asExample = true): string;

    /**
     * @param string $tool
     * @param array<string> $skipPaths
     * @param bool $asExample
     *
     * @return string|null
     */
    public function generateAgentsFile(string $tool, array $skipPaths = [], bool $asExample = true): ?string;

    /**
     * @param string $tool
     * @param bool $asExample
     *
     * @return array<string>
     */
    public function listSkillsTargetPaths(string $tool, bool $asExample = true): array;

    /**
     * @param string $tool
     * @param array<string> $skipPaths
     * @param bool $asExample
     *
     * @return array<string>
     */
    public function generateSkills(string $tool, array $skipPaths = [], bool $asExample = true): array;

    /**
     * @param string $absolutePath
     *
     * @return string
     */
    public function toRelativePath(string $absolutePath): string;
}
