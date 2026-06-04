<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev;

use Spryker\Zed\Kernel\AbstractBundleConfig;

class AiDevConfig extends AbstractBundleConfig
{
    /**
     * @var array
     */
    protected const MCP_SERVER_INFO = [
        'name' => 'AI Dev Sdk',
        'version' => '0.1.0',
    ];

    protected const string TOOL_CLAUDE_CODE = 'Claude Code';

    protected const string TOOL_WINDSURF = 'Windsurf';

    protected const string TOOL_COPILOT = 'GitHub Copilot';

    protected const string TOOL_CURSOR = 'Cursor';

    protected const string TOOL_OPENCODE = 'OpenCode';

    protected const string TOOL_CODEX = 'Codex CLI';

    /**
     * Per-tool frontmatter transformation spec applied when publishing source rules.
     *
     * scope_key: target field name for the file scope pattern (null = omit the field entirely)
     * keep_name: include the `name` field in the output
     * keep_description: include the `description` field in the output
     * trigger_with_scope: value for the `trigger` field when the rule has a paths value (null = omit)
     * trigger_no_scope: value for the `trigger` field when the rule has no paths value (null = omit)
     * fallback_scope: scope value used when the source rule has no paths field (null = omit)
     *
     * @var array<string, array<string, mixed>>
     */
    protected const array TOOL_FRONTMATTER_SPEC = [
        self::TOOL_CLAUDE_CODE => [
            'scope_key' => 'paths',
            'keep_name' => false,
            'keep_description' => false,
            'trigger_with_scope' => null,
            'trigger_no_scope' => null,
            'fallback_scope' => null,
        ],
        self::TOOL_WINDSURF => [
            'scope_key' => 'globs',
            'keep_name' => false,
            'keep_description' => true,
            'trigger_with_scope' => 'glob',
            'trigger_no_scope' => 'always_on',
            'fallback_scope' => null,
        ],
        self::TOOL_COPILOT => [
            'scope_key' => 'applyTo',
            'keep_name' => false,
            'keep_description' => false,
            'trigger_with_scope' => null,
            'trigger_no_scope' => null,
            'fallback_scope' => '**',
        ],
        self::TOOL_CURSOR => [
            'scope_key' => 'globs',
            'keep_name' => true,
            'keep_description' => true,
            'trigger_with_scope' => null,
            'trigger_no_scope' => null,
            'fallback_scope' => null,
        ],
        self::TOOL_OPENCODE => [
            'scope_key' => 'globs',
            'keep_name' => true,
            'keep_description' => true,
            'trigger_with_scope' => null,
            'trigger_no_scope' => null,
            'fallback_scope' => null,
        ],
        self::TOOL_CODEX => [
            'scope_key' => null,
            'keep_name' => false,
            'keep_description' => false,
            'trigger_with_scope' => null,
            'trigger_no_scope' => null,
            'fallback_scope' => null,
        ],
    ];

    /**
     * Detection order: indicator path (relative to project root) => tool name.
     * First match wins; .codex requires an extra confirmation step in the command.
     *
     * @var array<string, string>
     */
    protected const array TOOL_DETECTION_MAP = [
        '.claude' => self::TOOL_CLAUDE_CODE,
        '.windsurf' => self::TOOL_WINDSURF,
        '.github' => self::TOOL_COPILOT,
        '.cursor' => self::TOOL_CURSOR,
        'opencode.json' => self::TOOL_OPENCODE,
        '.codex' => self::TOOL_CODEX,
    ];

    /**
     * Per-tool artifact configuration.
     * rules_dir: null means rules generation is skipped for that tool.
     * rules_file_suffix: suffix applied to the source filename (source .md extension is stripped first).
     * agents_file: path relative to project root.
     * skills_dir: path relative to project root.
     *
     * @var array<string, array<string, string|null>>
     */
    protected const array TOOL_ARTIFACT_MAP = [
        self::TOOL_CLAUDE_CODE => [
            'rules_dir' => '.claude/rules',
            'rules_file_suffix' => '.md',
            'agents_file' => 'CLAUDE.md',
            'skills_dir' => '.claude/skills',
        ],
        self::TOOL_WINDSURF => [
            'rules_dir' => '.windsurf/rules',
            'rules_file_suffix' => '.md',
            'agents_file' => '.windsurfrules',
            'skills_dir' => '.windsurf/skills',
        ],
        self::TOOL_COPILOT => [
            'rules_dir' => '.github/instructions',
            'rules_file_suffix' => '.instructions.md',
            'agents_file' => '.github/copilot-instructions.md',
            'skills_dir' => '.github/skills',
        ],
        self::TOOL_CURSOR => [
            'rules_dir' => '.cursor/rules',
            'rules_file_suffix' => '.mdc',
            'agents_file' => 'AGENTS.md',
            'skills_dir' => '.cursor/skills',
        ],
        self::TOOL_OPENCODE => [
            'rules_dir' => '.opencode/rules',
            'rules_file_suffix' => '.md',
            'agents_file' => 'AGENTS.md',
            'skills_dir' => '.agents/skills',
        ],
        self::TOOL_CODEX => [
            'rules_dir' => null,
            'rules_file_suffix' => null,
            'agents_file' => 'AGENTS.md',
            'skills_dir' => '.agents/skills',
        ],
    ];

    /**
     * @api
     *
     * @return array<string, string>
     */
    public function getMcpServerInfo(): array
    {
        return static::MCP_SERVER_INFO;
    }

    /**
     * @api
     *
     * @return string
     */
    public function getPromptClassTargetDirectory(): string
    {
        return rtrim(APPLICATION_SOURCE_DIR, DIRECTORY_SEPARATOR) . '/Generated/Shared/Prompts/';
    }

    /**
     * @api
     *
     * @return string
     */
    public function getPromptsDirectory(): string
    {
        return APPLICATION_ROOT_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'prompts';
    }

    /**
     * Specification:
     * - Returns absolute path to the bundled AGENTS example markdown file.
     *
     * @api
     *
     * @return string
     */
    public function getAgentsExampleFilePath(): string
    {
        return APPLICATION_VENDOR_DIR . '/spryker-sdk/ai-dev/data/agents/AGENTS.example.md';
    }

    /**
     * Specification:
     * - Returns the project root directory where the generated file will be written.
     *
     * @api
     *
     * @return string
     */
    public function getAgentsFileOutputDirectory(): string
    {
        return APPLICATION_ROOT_DIR;
    }

    /**
     * Specification:
     * - Returns absolute path to the bundled skill examples directory.
     *
     * @api
     *
     * @return string
     */
    public function getSkillsExamplesDirectory(): string
    {
        return APPLICATION_VENDOR_DIR . '/spryker-sdk/ai-dev/plugins/spryker-ai-dev-sdk/skills';
    }

    /**
     * Specification:
     * - Returns the ordered map of indicator path => tool name used for auto-detection.
     *
     * @api
     *
     * @return array<string, string>
     */
    public function getToolDetectionMap(): array
    {
        return static::TOOL_DETECTION_MAP;
    }

    /**
     * Specification:
     * - Returns per-tool artifact configuration (rules dir, agents file, skills dir).
     *
     * @api
     *
     * @param string $tool
     *
     * @return array<string, string|null>
     */
    public function getToolArtifacts(string $tool): array
    {
        return static::TOOL_ARTIFACT_MAP[$tool] ?? [];
    }

    /**
     * Specification:
     * - Returns the absolute path to the bundled rules source directory.
     *
     * @api
     *
     * @return string
     */
    public function getRulesSourceDirectory(): string
    {
        return APPLICATION_VENDOR_DIR . '/spryker-sdk/ai-dev/data/rules';
    }

    /**
     * Specification:
     * - Returns the tool name that requires extra confirmation on detection.
     *
     * @api
     *
     * @return string
     */
    public function getToolRequiringConfirmation(): string
    {
        return static::TOOL_CODEX;
    }

    /**
     * Specification:
     * - Returns the frontmatter transformation spec for the given tool.
     * - Returns an empty array when no spec is defined for the tool.
     *
     * @api
     *
     * @param string $tool
     *
     * @return array<string, mixed>
     */
    public function getToolFrontmatterSpec(string $tool): array
    {
        return static::TOOL_FRONTMATTER_SPEC[$tool] ?? [];
    }

    /**
     * @api
     *
     * @return array<string>
     */
    public function getRuleCompatibleTools(): array
    {
        return array_values(array_keys(array_filter(
            static::TOOL_ARTIFACT_MAP,
            static fn (array $config): bool => $config['rules_dir'] !== null,
        )));
    }
}
