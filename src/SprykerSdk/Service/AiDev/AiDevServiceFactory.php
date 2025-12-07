<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Service\AiDev;

use Spryker\Service\Kernel\AbstractServiceFactory;
use SprykerSdk\Service\AiDev\Service\Csv\CsvWriter;
use SprykerSdk\Service\AiDev\Service\Csv\CsvWriterInterface;
use SprykerSdk\Service\AiDev\Service\FileSystem\FilesFinder;
use SprykerSdk\Service\AiDev\Service\FileSystem\FilesFinderInterface;
use SprykerSdk\Service\AiDev\Service\FileSystem\FileWriter;
use SprykerSdk\Service\AiDev\Service\FileSystem\FileWriterInterface;
use SprykerSdk\Service\AiDev\Service\FileSystem\PathResolver;
use SprykerSdk\Service\AiDev\Service\FileSystem\PathResolverInterface;
use SprykerSdk\Service\AiDev\Service\Ods\OdsParser;
use SprykerSdk\Service\AiDev\Service\Ods\OdsParserInterface;
use SprykerSdk\Service\AiDev\Service\Ods\OdsToCsvConverter;
use SprykerSdk\Service\AiDev\Service\Ods\OdsToCsvConverterInterface;

class AiDevServiceFactory extends AbstractServiceFactory
{
    public function createPathResolver(): PathResolverInterface
    {
        return new PathResolver();
    }

    public function createFileWriter(): FileWriterInterface
    {
        return new FileWriter(
            $this->createPathResolver(),
        );
    }

    public function createFilesFinder(): FilesFinderInterface
    {
        return new FilesFinder(
            $this->createPathResolver(),
        );
    }

    public function createCsvWriter(): CsvWriterInterface
    {
        return new CsvWriter();
    }

    public function createOdsParser(): OdsParserInterface
    {
        return new OdsParser();
    }

    public function createOdsToCsvConverter(): OdsToCsvConverterInterface
    {
        return new OdsToCsvConverter(
            $this->createPathResolver(),
            $this->createCsvWriter(),
            $this->createOdsParser(),
        );
    }
}
