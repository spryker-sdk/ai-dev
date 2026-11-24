<?php

namespace Example\Zed\Gizmo\Communication\Table;

use Spryker\Zed\Gui\Communication\Table\AbstractTable;
use Spryker\Zed\Gui\Communication\Table\TableConfiguration;

// With --skip-ui-definitions configure() is skipped; prepareData() always stays.
class GizmoTable extends AbstractTable
{
    protected function configure(TableConfiguration $config)
    {
        $config->setHeader(['name' => 'Name']);

        return $config;
    }

    protected function prepareData(TableConfiguration $config)
    {
        return array_map(static fn (array $row): array => $row, []);
    }
}
