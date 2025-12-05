<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SprykerSdk\Service\AiDev\Service\FileSystem;

class FileWriter implements FileWriterInterface
{
    public function __construct(
        protected PathResolverInterface $pathResolver
    ) {
    }

    public function writeFile(string $filePath, string $content): bool
    {
        $resolvedPath = $this->pathResolver->resolvePath($filePath);
        $directory = dirname($resolvedPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return file_put_contents($resolvedPath, $content) !== false;
    }
}
