<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\AiToolSetup;

use DirectoryIterator;
use InvalidArgumentException;
use RuntimeException;
use SprykerSdk\Zed\AiDev\AiDevConfig;

class AiToolArtifactGenerator implements AiToolArtifactGeneratorInterface
{
    public function __construct(
        protected string $projectRoot,
        protected AiDevConfig $config,
        protected RuleFrontmatterTransformerInterface $frontmatterTransformer,
    ) {
    }

    /**
     * @return array<string>
     */
    public function listRuleTargetPaths(string $tool, ArtifactMode $mode = ArtifactMode::Real): array
    {
        $artifactConfig = $this->config->getToolArtifacts($tool);

        if ($artifactConfig['rules_dir'] === null) {
            return [];
        }

        $targetDir = $this->resolveRulesTargetDir($artifactConfig['rules_dir'], $mode);
        $suffix = (string)$artifactConfig['rules_file_suffix'];

        return $this->resolveRuleTargetPaths($targetDir, $suffix);
    }

    /**
     * @param array<string> $skipPaths
     *
     * @throws \RuntimeException
     *
     * @return array<string>
     */
    public function generateRules(string $tool, array $skipPaths = [], ArtifactMode $mode = ArtifactMode::Real): array
    {
        $artifactConfig = $this->config->getToolArtifacts($tool);

        if ($artifactConfig['rules_dir'] === null) {
            return [];
        }

        $targetDir = $this->resolveRulesTargetDir($artifactConfig['rules_dir'], $mode);
        $suffix = (string)$artifactConfig['rules_file_suffix'];
        $generated = [];

        $this->ensureDirectory($targetDir);

        foreach (new DirectoryIterator($this->config->getRulesSourceDirectory()) as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $baseName = pathinfo($item->getFilename(), PATHINFO_FILENAME);
            $targetPath = sprintf('%s%s%s%s', $targetDir, DIRECTORY_SEPARATOR, $baseName, $suffix);

            $this->assertPathIsWithinProjectRoot($targetPath);

            if (in_array($targetPath, $skipPaths, true)) {
                continue;
            }

            $content = file_get_contents($item->getPathname());

            if ($content === false) {
                throw new RuntimeException(sprintf('Failed to read rule source "%s".', $item->getPathname()));
            }

            $spec = $this->config->getToolFrontmatterSpec($tool);
            $transformed = $this->frontmatterTransformer->transform($content, $spec);

            if (file_put_contents($targetPath, $transformed) === false) {
                throw new RuntimeException(sprintf('Failed to write rule to "%s".', $targetPath));
            }

            $generated[] = $targetPath;
        }

        return $generated;
    }

    public function listAgentsFileTargetPath(string $tool, ArtifactMode $mode = ArtifactMode::Real): string
    {
        $artifactConfig = $this->config->getToolArtifacts($tool);
        $agentsFile = (string)$artifactConfig['agents_file'];
        $absoluteBase = $this->projectRoot . DIRECTORY_SEPARATOR . $agentsFile;
        $fileName = $mode === ArtifactMode::Example ? 'example.' . basename($agentsFile) : basename($agentsFile);

        return dirname($absoluteBase) . DIRECTORY_SEPARATOR . $fileName;
    }

    /**
     * @param array<string> $skipPaths
     *
     * @throws \RuntimeException
     */
    public function generateAgentsFile(string $tool, array $skipPaths = [], ArtifactMode $mode = ArtifactMode::Real): ?string
    {
        $examplePath = $this->listAgentsFileTargetPath($tool, $mode);

        if (in_array($examplePath, $skipPaths, true)) {
            return null;
        }

        $this->assertPathIsWithinProjectRoot($examplePath);
        $this->ensureDirectory(dirname($examplePath));

        $sourcePath = $this->config->getAgentsExampleFilePath();

        if (!is_readable($sourcePath)) {
            throw new RuntimeException(sprintf('Agents example file is not readable: "%s".', $sourcePath));
        }

        $content = file_get_contents($sourcePath);

        if ($content === false) {
            throw new RuntimeException(sprintf('Failed to read agents example file: "%s".', $sourcePath));
        }

        if (file_put_contents($examplePath, $content) === false) {
            throw new RuntimeException(sprintf('Failed to write agents file to "%s".', $examplePath));
        }

        return $examplePath;
    }

    /**
     * @return array<string>
     */
    public function listSkillsTargetPaths(string $tool, ArtifactMode $mode = ArtifactMode::Real): array
    {
        $artifactConfig = $this->config->getToolArtifacts($tool);
        $targetDir = $this->projectRoot . DIRECTORY_SEPARATOR . (string)$artifactConfig['skills_dir'];

        return $this->resolveSkillTargetPaths($targetDir, $mode);
    }

    /**
     * @param array<string> $skipPaths
     *
     * @return array<string>
     */
    public function generateSkills(string $tool, array $skipPaths = [], ArtifactMode $mode = ArtifactMode::Real): array
    {
        $artifactConfig = $this->config->getToolArtifacts($tool);
        $targetDir = $this->projectRoot . DIRECTORY_SEPARATOR . (string)$artifactConfig['skills_dir'];
        $generated = [];

        $this->ensureDirectory($targetDir);

        foreach (new DirectoryIterator($this->config->getSkillsExamplesDirectory()) as $item) {
            if (!$item->isDir() || $item->isDot()) {
                continue;
            }

            $dirName = $mode === ArtifactMode::Example ? $item->getFilename() . '-example' : $item->getFilename();
            $targetSkillDir = sprintf('%s%s%s', $targetDir, DIRECTORY_SEPARATOR, $dirName);

            $this->assertPathIsWithinProjectRoot($targetSkillDir);

            if (in_array($targetSkillDir, $skipPaths, true)) {
                continue;
            }

            $this->copyDirectory($item->getPathname(), $targetSkillDir);

            $generated[] = $targetSkillDir;
        }

        return $generated;
    }

    /**
     * @return array<string>
     */
    public function listAgentsTargetPaths(string $tool, ArtifactMode $mode = ArtifactMode::Real): array
    {
        $artifactConfig = $this->config->getToolArtifacts($tool);
        $targetDir = $this->projectRoot . DIRECTORY_SEPARATOR . (string)$artifactConfig['agents_dir'];

        return $this->resolveAgentTargetPaths($targetDir, $mode);
    }

    /**
     * @param array<string> $skipPaths
     *
     * @throws \RuntimeException
     *
     * @return array<string>
     */
    public function generateAgents(string $tool, array $skipPaths = [], ArtifactMode $mode = ArtifactMode::Real): array
    {
        $artifactConfig = $this->config->getToolArtifacts($tool);
        $targetDir = $this->projectRoot . DIRECTORY_SEPARATOR . (string)$artifactConfig['agents_dir'];
        $generated = [];

        $this->ensureDirectory($targetDir);

        foreach (new DirectoryIterator($this->config->getAgentsExamplesDirectory()) as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $targetPath = sprintf('%s%s%s', $targetDir, DIRECTORY_SEPARATOR, $this->resolveAgentFileName($item->getFilename(), $mode));

            $this->assertPathIsWithinProjectRoot($targetPath);

            if (in_array($targetPath, $skipPaths, true)) {
                continue;
            }

            if (!copy($item->getPathname(), $targetPath)) {
                throw new RuntimeException(sprintf('Failed to copy "%s" to "%s".', $item->getPathname(), $targetPath));
            }

            $generated[] = $targetPath;
        }

        return $generated;
    }

    public function toRelativePath(string $absolutePath): string
    {
        $prefix = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (str_starts_with($absolutePath, $prefix)) {
            return substr($absolutePath, strlen($prefix));
        }

        return $absolutePath;
    }

    /**
     * @throws \RuntimeException
     */
    protected function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0755, true)) {
            throw new RuntimeException(sprintf('Failed to create directory "%s".', $path));
        }
    }

    /**
     * @throws \RuntimeException
     */
    protected function copyDirectory(string $source, string $destination): void
    {
        $this->ensureDirectory($destination);

        foreach (new DirectoryIterator($source) as $item) {
            if ($item->isDot()) {
                continue;
            }

            $destinationPath = sprintf('%s%s%s', $destination, DIRECTORY_SEPARATOR, $item->getFilename());

            if ($item->isDir()) {
                $this->copyDirectory($item->getPathname(), $destinationPath);

                continue;
            }

            if (!copy($item->getPathname(), $destinationPath)) {
                throw new RuntimeException(sprintf('Failed to copy "%s" to "%s".', $item->getPathname(), $destinationPath));
            }
        }
    }

    protected function resolveRulesTargetDir(string $rulesDir, ArtifactMode $mode): string
    {
        $dir = $mode === ArtifactMode::Example ? $rulesDir . '-example' : $rulesDir;

        return $this->projectRoot . DIRECTORY_SEPARATOR . $dir;
    }

    /**
     * @return array<string>
     */
    protected function resolveRuleTargetPaths(string $targetDir, string $suffix): array
    {
        $paths = [];

        foreach (new DirectoryIterator($this->config->getRulesSourceDirectory()) as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $baseName = pathinfo($item->getFilename(), PATHINFO_FILENAME);

            $paths[] = sprintf('%s%s%s%s', $targetDir, DIRECTORY_SEPARATOR, $baseName, $suffix);
        }

        return $paths;
    }

    /**
     * @return array<string>
     */
    protected function resolveSkillTargetPaths(string $targetDir, ArtifactMode $mode): array
    {
        $paths = [];

        foreach (new DirectoryIterator($this->config->getSkillsExamplesDirectory()) as $item) {
            if (!$item->isDir() || $item->isDot()) {
                continue;
            }

            $dirName = $mode === ArtifactMode::Example ? $item->getFilename() . '-example' : $item->getFilename();

            $paths[] = sprintf('%s%s%s', $targetDir, DIRECTORY_SEPARATOR, $dirName);
        }

        return $paths;
    }

    /**
     * @return array<string>
     */
    protected function resolveAgentTargetPaths(string $targetDir, ArtifactMode $mode): array
    {
        $paths = [];

        foreach (new DirectoryIterator($this->config->getAgentsExamplesDirectory()) as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $paths[] = sprintf('%s%s%s', $targetDir, DIRECTORY_SEPARATOR, $this->resolveAgentFileName($item->getFilename(), $mode));
        }

        return $paths;
    }

    protected function resolveAgentFileName(string $fileName, ArtifactMode $mode): string
    {
        if ($mode !== ArtifactMode::Example) {
            return $fileName;
        }

        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        return $extension === '' ? $baseName . '-example' : sprintf('%s-example.%s', $baseName, $extension);
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function assertPathIsWithinProjectRoot(string $path): void
    {
        $realProjectRoot = realpath($this->projectRoot);
        $resolvedDir = realpath(dirname($path)) ?: dirname($path);

        if ($realProjectRoot === false || !str_starts_with($resolvedDir, $realProjectRoot)) {
            throw new InvalidArgumentException(sprintf('Resolved path "%s" is outside the project root.', $path));
        }
    }
}
