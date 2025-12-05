<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SprykerSdk\Service\AiDev\Service\FileSystem;

class PathResolver implements PathResolverInterface
{
    public function resolvePath(string $path): string
    {
        if ($path[0] === '/') {
            return $path;
        }

        return APPLICATION_ROOT_DIR . DIRECTORY_SEPARATOR . $path;
    }
}
