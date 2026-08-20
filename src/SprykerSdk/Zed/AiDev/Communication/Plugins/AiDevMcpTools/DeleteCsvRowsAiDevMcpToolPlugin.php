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
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory getBusinessFactory()
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class DeleteCsvRowsAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    public function getName(): string
    {
        return 'deleteCsvRows';
    }

    public function getDescription(): string
    {
        return 'Delete rows from CSV file based on filter criteria. IMPORTANT: File path must be relative to project root. All criteria use AND logic (all must match to delete row). Creates backup by default. Supported operators: equals, not_equals, in, not_in, contains, not_contains, starts_with, ends_with, empty, not_empty. Safety check prevents deleting all rows. Parameters: filePath (required, relative path), criteria (array of {column, operator, value}), createBackup (default true).';
    }

    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @param array<int, array<string, mixed>> $criteria
     */
    public function deleteCsvRows(string $filePath, array $criteria, bool $createBackup = true): string
    {
        return $this->getBusinessFactory()
            ->createCsvRowDeleter()
            ->deleteRows($filePath, $criteria, $createBackup);
    }
}
