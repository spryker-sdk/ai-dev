<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Shared\AiDev;

class AbstractPrompt
{
    /**
     * @param array<string, string>|string $params
     */
    protected function replacePlaceholders(string $template, array $params): string
    {
        $search = [];
        $replace = [];

        foreach ($params as $key => $value) {
            $search[] = '{{' . $key . '}}';
            $replace[] = $value;

            $search[] = '{' . $key . '}';
            $replace[] = $value;
        }

        return str_replace($search, $replace, $template);
    }
}
