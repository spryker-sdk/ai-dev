<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business;

use Spryker\Zed\Kernel\Business\AbstractBusinessFactory;
use SprykerSdk\Zed\AiDev\Business\Prompts\GitHubPromptsFetcher;
use SprykerSdk\Zed\AiDev\Business\Prompts\GitHubPromptsFetcherInterface;
use SprykerSdk\Zed\AiDev\Business\Prompts\PromptReader;
use SprykerSdk\Zed\AiDev\Business\Prompts\PromptReaderInterface;
use SprykerSdk\Zed\AiDev\Business\Prompts\PromptRenderer;
use SprykerSdk\Zed\AiDev\Business\Prompts\PromptRendererInterface;
use SprykerSdk\Zed\AiDev\Business\Prompts\PromptsGenerator;
use SprykerSdk\Zed\AiDev\Business\Prompts\PromptsGeneratorInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 */
class AiDevBusinessFactory extends AbstractBusinessFactory
{
    public function createGitHubPromptsFetcher(): GitHubPromptsFetcherInterface
    {
        return new GitHubPromptsFetcher();
    }

    public function createPromptReader(): PromptReaderInterface
    {
        return new PromptReader();
    }

    public function createPromptRenderer(): PromptRendererInterface
    {
        return new PromptRenderer($this->createPromptReader());
    }

    public function createPromptsGenerator(): PromptsGeneratorInterface
    {
        return new PromptsGenerator(
            $this->createGitHubPromptsFetcher(),
            $this->getConfig(),
        );
    }
}
