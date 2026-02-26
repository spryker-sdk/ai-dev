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
}
