<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev;

use Spryker\Zed\Kernel\AbstractBundleConfig;

class AiDevConfig extends AbstractBundleConfig
{
    public const string CSV_MODE_APPEND = 'a';

    public const string CSV_MODE_OVERWRITE = 'w';

    public const string FILTER_LOGIC_AND = 'AND';

    public const string FILTER_LOGIC_OR = 'OR';

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
}
