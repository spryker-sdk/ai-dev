<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Validator;

use SprykerSdk\Zed\AiDev\Business\DataImport\CsvReaderInterface;

class ValidationContext
{
    /**
     * @var array<string>|null
     */
    protected ?array $sourceHeaders = null;

    /**
     * @var array<string>|null
     */
    protected ?array $targetHeaders = null;

    /**
     * @param array<string, string> $columnMappings
     * @param array<int, array<string, mixed>> $rowFilters
     * @param array<int, array<string, mixed>> $valueTransformations
     * @param array<string, mixed> $defaultValues
     * @param array<string> $columnsToRemove
     */
    public function __construct(
        public string $mode,
        public string $sourcePath,
        public string $targetPath,
        public array $columnMappings,
        public array $rowFilters,
        public array $valueTransformations,
        public array $defaultValues,
        public array $columnsToRemove,
        protected CsvReaderInterface $csvReader,
    ) {
    }

    public function hasSourceFile(): bool
    {
        return $this->sourcePath !== '';
    }

    /**
     * @return array<string>
     */
    public function getSourceHeaders(): array
    {
        if ($this->sourceHeaders === null && $this->hasSourceFile()) {
            $this->sourceHeaders = $this->csvReader->getHeaders($this->sourcePath);
        }

        return $this->sourceHeaders ?? [];
    }

    /**
     * @return array<string>
     */
    public function getTargetHeaders(): array
    {
        if ($this->targetHeaders === null) {
            $this->targetHeaders = $this->csvReader->getHeaders($this->targetPath);
        }

        return $this->targetHeaders;
    }
}
