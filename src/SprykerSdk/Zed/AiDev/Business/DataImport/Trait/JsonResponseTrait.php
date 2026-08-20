<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Trait;

trait JsonResponseTrait
{
    /**
     * @param array<string, mixed> $data
     */
    protected function successResponse(array $data): string
    {
        return json_encode(array_merge(['success' => true], $data), JSON_PRETTY_PRINT);
    }

    /**
     * @param array<string, mixed> $details
     */
    protected function errorResponse(string $errorCode, string $errorMessage, array $details = []): string
    {
        return json_encode([
            'success' => false,
            'error' => $errorMessage,
            'error_code' => $errorCode,
            'details' => $details,
        ], JSON_PRETTY_PRINT);
    }
}
