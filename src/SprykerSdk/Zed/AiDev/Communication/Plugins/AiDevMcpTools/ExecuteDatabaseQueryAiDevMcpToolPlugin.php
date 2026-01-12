<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory getBusinessFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class ExecuteDatabaseQueryAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Tool to execute read-only database queries (SELECT, SHOW, DESCRIBE, and EXPLAIN statements only). Query must start with one of: SELECT, SHOW, DESCRIBE, or EXPLAIN. Returns data in JSON format with {data, error} structure. Automatically applies a limit of 1000 rows if not specified. Useful for exploring database schema, checking table structures, and querying current data state.';
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'executeDatabaseQuery';
    }

    /**
     * @param string $query
     *
     * @return string
     */
    public function executeDatabaseQuery(string $query): string
    {
        return $this->getBusinessFactory()
            ->createDatabaseQueryReader()
            ->executeQuery($query);
    }
}
