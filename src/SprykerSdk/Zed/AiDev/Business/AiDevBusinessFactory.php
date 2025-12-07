<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business;

use Spryker\Service\UtilDataReader\UtilDataReaderServiceInterface;
use Spryker\Zed\Kernel\Business\AbstractBusinessFactory;
use Spryker\Zed\Oms\Business\OmsFacadeInterface;
use SprykerSdk\Service\AiDev\AiDevServiceInterface;
use SprykerSdk\Zed\AiDev\AiDevDependencyProvider;
use SprykerSdk\Zed\AiDev\Business\DataImport\Copier\DataImportCsvCopier;
use SprykerSdk\Zed\AiDev\Business\DataImport\Copier\DataImportCsvCopierInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\Deleter\DataImportCsvRowsDeleter;
use SprykerSdk\Zed\AiDev\Business\DataImport\Deleter\DataImportCsvRowsDeleterInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\Filter\RowFilter;
use SprykerSdk\Zed\AiDev\Business\DataImport\Filter\RowFilterInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\Finder\DataImportCsvFilesFinder;
use SprykerSdk\Zed\AiDev\Business\DataImport\Finder\DataImportCsvFilesFinderInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\Mapper\ColumnMapper;
use SprykerSdk\Zed\AiDev\Business\DataImport\Mapper\ColumnMapperInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\Reader\DataImportCsvFileReader;
use SprykerSdk\Zed\AiDev\Business\DataImport\Reader\DataImportCsvFileReaderInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ColumnMappingValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ColumnMappingValidatorInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\Writer\DataImportCsvFileWriter;
use SprykerSdk\Zed\AiDev\Business\DataImport\Writer\DataImportCsvFileWriterInterface;
use SprykerSdk\Zed\AiDev\Business\GoogleSpreadsheet\Downloader\GoogleSpreadsheetDownloader;
use SprykerSdk\Zed\AiDev\Business\GoogleSpreadsheet\Downloader\GoogleSpreadsheetDownloaderInterface;
use SprykerSdk\Zed\AiDev\Business\GoogleSpreadsheet\Processor\GoogleSpreadsheetProcessor;
use SprykerSdk\Zed\AiDev\Business\GoogleSpreadsheet\Processor\GoogleSpreadsheetProcessorInterface;
use SprykerSdk\Zed\AiDev\Business\Oms\Reader\OmsTransitionsReader;
use SprykerSdk\Zed\AiDev\Business\Oms\Reader\OmsTransitionsReaderInterface;
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

    public function getAiDevService(): AiDevServiceInterface
    {
        return $this->getProvidedDependency(AiDevDependencyProvider::SERVICE_AI_DEV);
    }

    public function createDataImportCsvFileReader(): DataImportCsvFileReaderInterface
    {
        return new DataImportCsvFileReader(
            $this->getUtilDataReaderService()->createCsvReader(),
            $this->createRowFilter(),
            $this->getAiDevService(),
        );
    }

    public function createDataImportCsvFileWriter(): DataImportCsvFileWriterInterface
    {
        return new DataImportCsvFileWriter(
            $this->getUtilDataReaderService()->createCsvReader(),
            $this->getAiDevService(),
            $this->createColumnMappingValidator(),
            $this->createColumnMapper(),
        );
    }

    public function createDataImportCsvCopier(): DataImportCsvCopierInterface
    {
        return new DataImportCsvCopier(
            $this->createDataImportCsvFileReader(),
            $this->createDataImportCsvFileWriter(),
            $this->getAiDevService(),
            $this->createColumnMappingValidator(),
            $this->createColumnMapper(),
        );
    }

    public function createColumnMappingValidator(): ColumnMappingValidatorInterface
    {
        return new ColumnMappingValidator();
    }

    public function createColumnMapper(): ColumnMapperInterface
    {
        return new ColumnMapper();
    }

    public function createDataImportCsvRowsDeleter(): DataImportCsvRowsDeleterInterface
    {
        return new DataImportCsvRowsDeleter(
            $this->createDataImportCsvFileReader(),
            $this->createRowFilter(),
            $this->getAiDevService(),
        );
    }

    public function createDataImportCsvFilesFinder(): DataImportCsvFilesFinderInterface
    {
        return new DataImportCsvFilesFinder(
            $this->getAiDevService(),
        );
    }

    public function createRowFilter(): RowFilterInterface
    {
        return new RowFilter();
    }

    public function createGoogleSpreadsheetProcessor(): GoogleSpreadsheetProcessorInterface
    {
        return new GoogleSpreadsheetProcessor(
            $this->createGoogleSpreadsheetDownloader(),
            $this->getAiDevService(),
        );
    }

    public function createGoogleSpreadsheetDownloader(): GoogleSpreadsheetDownloaderInterface
    {
        return new GoogleSpreadsheetDownloader(
            $this->getAiDevService(),
        );
    }

    public function createOmsTransitionsReader(): OmsTransitionsReaderInterface
    {
        return new OmsTransitionsReader(
            $this->getOmsFacade(),
        );
    }

    public function getUtilDataReaderService(): UtilDataReaderServiceInterface
    {
        return $this->getProvidedDependency(AiDevDependencyProvider::SERVICE_UTIL_DATA_READER);
    }

    public function getOmsFacade(): OmsFacadeInterface
    {
        return $this->getProvidedDependency(AiDevDependencyProvider::FACADE_OMS);
    }
}
