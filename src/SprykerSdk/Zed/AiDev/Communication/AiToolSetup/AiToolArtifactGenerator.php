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
    /**
     * @param string $projectRoot
     * @param \SprykerSdk\Zed\AiDev\AiDevConfig $config
     * @param \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\RuleFrontmatterTransformerInterface $frontmatterTransformer
     */
    public function __construct(
        protected string $projectRoot,
        protected AiDevConfig $config,
        protected RuleFrontmatterTransformerInterface $frontmatterTransformer,
    ) {
    }

    /**
     * @param string $tool
     * @param \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode $mode
     *
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
     * @param string $tool
     * @param array<string> $skipPaths
     * @param \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode $mode
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

    /**
     * @param string $tool
     * @param \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode $mode
     *
     * @return string
     */
    public function listAgentsFileTargetPath(string $tool, ArtifactMode $mode = ArtifactMode::Real): string
    {
        $artifactConfig = $this->config->getToolArtifacts($tool);
        $agentsFile = (string)$artifactConfig['agents_file'];
        $absoluteBase = $this->projectRoot . DIRECTORY_SEPARATOR . $agentsFile;
        $fileName = $mode === ArtifactMode::Example ? 'example.' . basename($agentsFile) : basename($agentsFile);

        return dirname($absoluteBase) . DIRECTORY_SEPARATOR . $fileName;
    }

    /**
     * @param string $tool
     * @param array<string> $skipPaths
     * @param \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode $mode
     *
     * @throws \RuntimeException
     *
     * @return string|null
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
     * @param string $tool
     * @param \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode $mode
     *
     * @return array<string>
     */
    public function listSkillsTargetPaths(string $tool, ArtifactMode $mode = ArtifactMode::Real): array
    {
        $artifactConfig = $this->config->getToolArtifacts($tool);
        $targetDir = $this->projectRoot . DIRECTORY_SEPARATOR . (string)$artifactConfig['skills_dir'];

        return $this->resolveSkillTargetPaths($targetDir, $mode);
    }

    /**
     * @param string $tool
     * @param array<string> $skipPaths
     * @param \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode $mode
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
     * @param string $absolutePath
     *
     * @return string
     */
    public function toRelativePath(string $absolutePath): string
    {
        $prefix = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (str_starts_with($absolutePath, $prefix)) {
            return substr($absolutePath, strlen($prefix));
        }

        return $absolutePath;
    }

    /**
     * @param string $path
     *
     * @throws \RuntimeException
     *
     * @return void
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
     * @param string $source
     * @param string $destination
     *
     * @throws \RuntimeException
     *
     * @return void
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

    /**
     * @param string $rulesDir
     * @param \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode $mode
     *
     * @return string
     */
    protected function resolveRulesTargetDir(string $rulesDir, ArtifactMode $mode): string
    {
        $dir = $mode === ArtifactMode::Example ? $rulesDir . '-example' : $rulesDir;

        return $this->projectRoot . DIRECTORY_SEPARATOR . $dir;
    }

    /**
     * @param string $targetDir
     * @param string $suffix
     *
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
     * @param string $targetDir
     * @param \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode $mode
     *
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
     * @param string $path
     *
     * @throws \InvalidArgumentException
     *
     * @return void
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
