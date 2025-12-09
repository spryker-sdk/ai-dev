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
        'name' => 'AI Synapse',
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
}
