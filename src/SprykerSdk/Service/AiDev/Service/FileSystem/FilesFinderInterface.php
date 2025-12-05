<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SprykerSdk\Service\AiDev\Service\FileSystem;

interface FilesFinderInterface
{
    public function findFiles(string $path, string $extension, string $searchString = ''): array;
}
