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
use SprykerSdk\Zed\AiDev\Business\Database\Reader\DatabaseQueryReader;
use SprykerSdk\Zed\AiDev\Business\Database\Reader\DatabaseQueryReaderInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvAnalyzer;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvAnalyzerInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvReader;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvReaderInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvRowDeleter;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvRowDeleterInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvTransformer;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvTransformerInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvWriter;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvWriterInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\FilterEvaluator;
use SprykerSdk\Zed\AiDev\Business\DataImport\FilterEvaluatorInterface;
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
    /**
     * @return \SprykerSdk\Zed\AiDev\Business\Prompts\PromptsFetcherInterface
     */
    public function createGitHubPromptsFetcher(): PromptsFetcherInterface
    {
        return new GitHubPromptsFetcher($this->createMarkdownPromptParser());
    }

    /**
     * @return \SprykerSdk\Zed\AiDev\Business\Prompts\PromptsFetcherInterface
     */
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

    /**
     * @return \SprykerSdk\Zed\AiDev\Business\Prompts\MarkdownPromptParserInterface
     */
    public function createMarkdownPromptParser(): MarkdownPromptParserInterface
    {
        return new MarkdownPromptParser();
    }

    /**
     * @return \SprykerSdk\Zed\AiDev\Business\Prompts\PromptsGeneratorInterface
     */
    public function createPromptsGenerator(): PromptsGeneratorInterface
    {
        return new PromptsGenerator(
            $this->getPromptsFetchers(),
            $this->getConfig(),
        );
    }

    /**
     * @return \SprykerSdk\Zed\AiDev\Business\Oms\Reader\OmsTransitionsReaderInterface
     */
    public function createOmsTransitionsReader(): OmsTransitionsReaderInterface
    {
        return new OmsTransitionsReader(
            $this->getOmsFacade(),
        );
    }

    /**
     * @return \SprykerSdk\Zed\AiDev\Business\Database\Reader\DatabaseQueryReaderInterface
     */
    public function createDatabaseQueryReader(): DatabaseQueryReaderInterface
    {
        return new DatabaseQueryReader();
    }

    /**
     * @return \Spryker\Zed\Oms\Business\OmsFacadeInterface
     */
    public function getOmsFacade(): OmsFacadeInterface
    {
        return $this->getProvidedDependency(AiDevDependencyProvider::FACADE_OMS);
    }

    /**
     * @return \SprykerSdk\Zed\AiDev\Business\DataImport\CsvReaderInterface
     */
    public function createCsvReader(): CsvReaderInterface
    {
        return new CsvReader();
    }

    /**
     * @return \SprykerSdk\Zed\AiDev\Business\DataImport\CsvWriterInterface
     */
    public function createCsvWriter(): CsvWriterInterface
    {
        return new CsvWriter(
            $this->createCsvReader(),
        );
    }

    /**
     * @return \SprykerSdk\Zed\AiDev\Business\DataImport\FilterEvaluatorInterface
     */
    public function createFilterEvaluator(): FilterEvaluatorInterface
    {
        return new FilterEvaluator();
    }

    /**
     * @return \SprykerSdk\Zed\AiDev\Business\DataImport\CsvAnalyzerInterface
     */
    public function createCsvAnalyzer(): CsvAnalyzerInterface
    {
        return new CsvAnalyzer(
            $this->createCsvReader(),
        );
    }

    /**
     * @return \SprykerSdk\Zed\AiDev\Business\DataImport\CsvRowDeleterInterface
     */
    public function createCsvRowDeleter(): CsvRowDeleterInterface
    {
        return new CsvRowDeleter(
            $this->createCsvReader(),
            $this->createCsvWriter(),
            $this->createFilterEvaluator(),
        );
    }

    /**
     * @return \SprykerSdk\Zed\AiDev\Business\DataImport\CsvTransformerInterface
     */
    public function createCsvTransformer(): CsvTransformerInterface
    {
        return new CsvTransformer(
            $this->createCsvReader(),
            $this->createCsvWriter(),
            $this->createFilterEvaluator(),
        );
    }
}
