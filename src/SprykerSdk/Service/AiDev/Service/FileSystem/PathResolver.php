<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
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
