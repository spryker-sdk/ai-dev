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
use SprykerSdk\Zed\AiDev\Business\DataImport\OdsReader;
use SprykerSdk\Zed\AiDev\Business\DataImport\OdsReaderInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\OdsSplitter;
use SprykerSdk\Zed\AiDev\Business\DataImport\OdsSplitterInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\ColumnMappingOperation;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\ColumnRemovalOperation;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\DefaultValuesOperation;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowOperationInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\TransformationOperation;
use SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\AbstractTransformStrategy;
use SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\AppendStrategy;
use SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\ReplaceStrategy;
use SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\UpdateStrategy;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ColumnMappingValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ColumnRemovalValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\FilterValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ModeValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\SourceFileValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\TargetFileValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\TransformationValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ValidatorInterface;
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

    public function createDatabaseQueryReader(): DatabaseQueryReaderInterface
    {
        return new DatabaseQueryReader();
    }

    public function getOmsFacade(): OmsFacadeInterface
    {
        return $this->getProvidedDependency(AiDevDependencyProvider::FACADE_OMS);
    }

    public function createCsvReader(): CsvReaderInterface
    {
        return new CsvReader();
    }

    public function createCsvWriter(): CsvWriterInterface
    {
        return new CsvWriter(
            $this->createCsvReader(),
        );
    }

    public function createFilterEvaluator(): FilterEvaluatorInterface
    {
        return new FilterEvaluator();
    }

    public function createCsvAnalyzer(): CsvAnalyzerInterface
    {
        return new CsvAnalyzer(
            $this->createCsvReader(),
        );
    }

    public function createCsvRowDeleter(): CsvRowDeleterInterface
    {
        return new CsvRowDeleter(
            $this->createCsvReader(),
            $this->createCsvWriter(),
            $this->createFilterEvaluator(),
        );
    }

    public function createCsvTransformer(): CsvTransformerInterface
    {
        return new CsvTransformer(
            $this->createCsvReader(),
            $this->createCsvWriter(),
            $this->createFilterEvaluator(),
            $this->getRowOperations(),
            $this->createTransformStrategies(),
            $this->createCsvValidators(),
        );
    }

    /**
     * @return array<\SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowOperationInterface>
     */
    public function getRowOperations(): array
    {
        return [
        $this->createColumnRemovalOperation(),
        $this->createColumnMappingOperation(),
        $this->createDefaultValuesOperation(),
        $this->createTransformationOperation(),
        ];
    }

    /**
     * @return array<\SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\AbstractTransformStrategy>
     */
    public function createTransformStrategies(): array
    {
        return [
        $this->createAppendStrategy(),
        $this->createReplaceStrategy(),
        $this->createUpdateStrategy(),
        ];
    }

    public function createAppendStrategy(): AbstractTransformStrategy
    {
        return new AppendStrategy(
            $this->createCsvReader(),
            $this->createCsvWriter(),
            $this->createFilterEvaluator(),
            $this->getRowOperations(),
        );
    }

    public function createReplaceStrategy(): AbstractTransformStrategy
    {
        return new ReplaceStrategy(
            $this->createCsvReader(),
            $this->createCsvWriter(),
            $this->createFilterEvaluator(),
            $this->getRowOperations(),
        );
    }

    public function createUpdateStrategy(): AbstractTransformStrategy
    {
        return new UpdateStrategy(
            $this->createCsvReader(),
            $this->createCsvWriter(),
            $this->createFilterEvaluator(),
            $this->getRowOperations(),
        );
    }

    public function createColumnRemovalOperation(): RowOperationInterface
    {
        return new ColumnRemovalOperation();
    }

    public function createColumnMappingOperation(): RowOperationInterface
    {
        return new ColumnMappingOperation();
    }

    public function createDefaultValuesOperation(): RowOperationInterface
    {
        return new DefaultValuesOperation();
    }

    public function createTransformationOperation(): RowOperationInterface
    {
        return new TransformationOperation();
    }

    public function createOdsReader(): OdsReaderInterface
    {
        return new OdsReader();
    }

    public function createOdsSplitter(): OdsSplitterInterface
    {
        return new OdsSplitter(
            $this->createOdsReader(),
            $this->createCsvWriter(),
        );
    }

    /**
     * @return array<\SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ValidatorInterface>
     */
    public function createCsvValidators(): array
    {
        return [
        $this->createModeValidator(),
        $this->createTargetFileValidator(),
        $this->createSourceFileValidator(),
        $this->createColumnMappingValidator(),
        $this->createFilterValidator(),
        $this->createTransformationValidator(),
        $this->createColumnRemovalValidator(),
        ];
    }

    public function createModeValidator(): ValidatorInterface
    {
        return new ModeValidator();
    }

    public function createTargetFileValidator(): ValidatorInterface
    {
        return new TargetFileValidator();
    }

    public function createSourceFileValidator(): ValidatorInterface
    {
        return new SourceFileValidator();
    }

    public function createColumnMappingValidator(): ValidatorInterface
    {
        return new ColumnMappingValidator();
    }

    public function createFilterValidator(): ValidatorInterface
    {
        return new FilterValidator(
            $this->createFilterEvaluator(),
        );
    }

    public function createTransformationValidator(): ValidatorInterface
    {
        return new TransformationValidator();
    }

    public function createColumnRemovalValidator(): ValidatorInterface
    {
        return new ColumnRemovalValidator();
    }
}
