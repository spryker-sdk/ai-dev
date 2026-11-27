<?php

namespace Example\Zed\Gizmo\Business;

use Example\Zed\Gizmo\Business\Model\Calculator;
use Example\Zed\Gizmo\Business\Model\DefaultStep;
use Example\Zed\Gizmo\Business\Model\Expander;
use Example\Zed\Gizmo\Business\Model\ExtendedStorageProvider;
use Example\Zed\Gizmo\Business\Model\Formatter;
use Example\Zed\Gizmo\Business\Model\HelloGreeter;
use Example\Zed\Gizmo\Business\Model\Legacy;
use Example\Zed\Gizmo\Business\Model\OtherStep;
use Example\Zed\Gizmo\Business\Model\Rounder;
use Example\Zed\Gizmo\Business\Model\StepImporter;
use Example\Zed\Gizmo\GizmoDependencyProvider;
use Spryker\Zed\Kernel\Business\AbstractBusinessFactory;

class GizmoBusinessFactory extends AbstractBusinessFactory
{
    public function createCalculator(): Calculator
    {
        return new Calculator($this->createRounder());
    }

    public function createRounder(): Rounder
    {
        return new Rounder();
    }

    public function createFormatter(): Formatter
    {
        return new Formatter();
    }

    public function createDefaultImporter(): StepImporter
    {
        $importer = new StepImporter();
        $importer->addStep(new DefaultStep());

        return $importer;
    }

    public function createOtherImporter(): StepImporter
    {
        $importer = new StepImporter();
        $importer->addStep(new OtherStep());

        return $importer;
    }

    public function createExpander(): Expander
    {
        return new Expander($this->getExpanderPlugins());
    }

    /**
     * @return array<\Example\Zed\Gizmo\Dependency\Plugin\GizmoExpanderPluginInterface>
     */
    public function getExpanderPlugins(): array
    {
        return $this->getProvidedDependency(GizmoDependencyProvider::PLUGINS_EXPANDER);
    }

    public function createGreeter(): HelloGreeter
    {
        return new HelloGreeter();
    }

    public function createStorageProvider(): ExtendedStorageProvider
    {
        return new ExtendedStorageProvider([], []);
    }

    public function createLegacy(): Legacy
    {
        return new Legacy();
    }
}
