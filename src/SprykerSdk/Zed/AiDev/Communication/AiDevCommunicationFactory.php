<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication;

use Spryker\Zed\Kernel\Communication\AbstractCommunicationFactory;
use Spryker\Zed\ModuleFinder\Business\ModuleFinderFacadeInterface;
use SprykerSdk\Zed\AiDev\AiDevDependencyProvider;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\AiToolArtifactGenerator;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\AiToolArtifactGeneratorInterface;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\AiToolDetector;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\AiToolDetectorInterface;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\RuleFrontmatterTransformer;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\RuleFrontmatterTransformerInterface;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\Step\AgentsFileGenerationStep;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\Step\AgentsGenerationStep;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\Step\RulesGenerationStep;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\Step\SkillsGenerationStep;

/**
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class AiDevCommunicationFactory extends AbstractCommunicationFactory
{
    /**
     * @return array<\SprykerSdk\Zed\AiDev\Dependency\AiDevMcpPromptPluginInterface>
     */
    public function getMcpPromptPlugins(): array
    {
        return $this->getProvidedDependency(AiDevDependencyProvider::PLUGINS_MCP_PROMPT);
    }

    /**
     * @return array<\SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface>
     */
    public function getMcpToolPlugins(): array
    {
        return $this->getProvidedDependency(AiDevDependencyProvider::PLUGINS_MCP_TOOL);
    }

    public function getModuleFinderFacade(): ModuleFinderFacadeInterface
    {
        return $this->getProvidedDependency(AiDevDependencyProvider::FACADE_MODULE_FINDER);
    }

    public function createAiToolDetector(): AiToolDetectorInterface
    {
        return new AiToolDetector(APPLICATION_ROOT_DIR);
    }

    public function createAiToolArtifactGenerator(): AiToolArtifactGeneratorInterface
    {
        return new AiToolArtifactGenerator(APPLICATION_ROOT_DIR, $this->getConfig(), $this->createRuleFrontmatterTransformer());
    }

    protected function createRuleFrontmatterTransformer(): RuleFrontmatterTransformerInterface
    {
        return new RuleFrontmatterTransformer();
    }

    /**
     * @return array<\SprykerSdk\Zed\AiDev\Communication\AiToolSetup\Step\AiToolSetupStepInterface>
     */
    public function getAiToolSetupSteps(): array
    {
        return [
            $this->createRulesGenerationStep(),
            $this->createAgentsFileGenerationStep(),
            $this->createSkillsGenerationStep(),
            $this->createAgentsGenerationStep(),
        ];
    }

    protected function createRulesGenerationStep(): RulesGenerationStep
    {
        return new RulesGenerationStep($this->createAiToolArtifactGenerator(), $this->getConfig());
    }

    protected function createAgentsFileGenerationStep(): AgentsFileGenerationStep
    {
        return new AgentsFileGenerationStep($this->createAiToolArtifactGenerator(), $this->getConfig());
    }

    protected function createSkillsGenerationStep(): SkillsGenerationStep
    {
        return new SkillsGenerationStep($this->createAiToolArtifactGenerator());
    }

    protected function createAgentsGenerationStep(): AgentsGenerationStep
    {
        return new AgentsGenerationStep($this->createAiToolArtifactGenerator(), $this->getConfig());
    }
}
