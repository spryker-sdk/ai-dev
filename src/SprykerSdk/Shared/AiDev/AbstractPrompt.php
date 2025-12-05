<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
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
