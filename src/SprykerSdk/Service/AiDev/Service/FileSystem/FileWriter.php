<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
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
