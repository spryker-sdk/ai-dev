<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business;

use Spryker\Zed\Kernel\Business\AbstractBusinessFactory;
use Spryker\Zed\Oms\Business\OmsFacadeInterface;
use SprykerSdk\Zed\AiDev\AiDevDependencyProvider;
use SprykerSdk\Zed\AiDev\Business\Oms\Reader\OmsTransitionsReader;
use SprykerSdk\Zed\AiDev\Business\Oms\Reader\OmsTransitionsReaderInterface;
use SprykerSdk\Zed\AiDev\Business\Prompts\GitHubPromptsFetcher;
use SprykerSdk\Zed\AiDev\Business\Prompts\LocalPromptsFetcher;
use SprykerSdk\Zed\AiDev\Business\Prompts\MarkdownPromptParser;
use SprykerSdk\Zed\AiDev\Business\Prompts\MarkdownPromptParserInterface;
use SprykerSdk\Zed\AiDev\Business\Prompts\PromptsFetcherInterface;
use SprykerSdk\Zed\AiDev\Business\Prompts\PromptsGenerator;
use SprykerSdk\Zed\AiDev\Business\Prompts\PromptsGeneratorInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 */
class AiDevBusinessFactory extends AbstractBusinessFactory
{
    public function createGitHubPromptsFetcher(): PromptsFetcherInterface
    {
        return new GitHubPromptsFetcher($this->createMarkdownPromptParser());
    }

    public function createLocalPromptsFetcher(): PromptsFetcherInterface
    {
        return new LocalPromptsFetcher(
            $this->getConfig(),
            $this->createMarkdownPromptParser(),
        );
    }

    /**
     * @return array<\SprykerSdk\Zed\AiDev\Business\Prompts\PromptsFetcherInterface>
     */
    public function getPromptsFetchers(): array
    {
        return [
            $this->createLocalPromptsFetcher(),
            $this->createGitHubPromptsFetcher(),
        ];
    }

    public function createMarkdownPromptParser(): MarkdownPromptParserInterface
    {
        return new MarkdownPromptParser();
    }

    public function createPromptsGenerator(): PromptsGeneratorInterface
    {
        return new PromptsGenerator(
            $this->getPromptsFetchers(),
            $this->getConfig(),
        );
    }

    public function createOmsTransitionsReader(): OmsTransitionsReaderInterface
    {
        return new OmsTransitionsReader(
            $this->getOmsFacade(),
        );
    }

    public function getOmsFacade(): OmsFacadeInterface
    {
        return $this->getProvidedDependency(AiDevDependencyProvider::FACADE_OMS);
    }
}
