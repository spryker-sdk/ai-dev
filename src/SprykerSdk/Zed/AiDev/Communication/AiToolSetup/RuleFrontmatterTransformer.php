<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\AiToolSetup;

class RuleFrontmatterTransformer implements RuleFrontmatterTransformerInterface
{
    protected const string FRONTMATTER_PATTERN = '/^---\r?\n(.*?)\r?\n---\r?\n(.*)/s';

    /**
     * @param string $content
     * @param array<string, mixed> $spec
     *
     * @return string
     */
    public function transform(string $content, array $spec): string
    {
        if ($spec === []) {
            return $content;
        }

        $parsed = $this->parseFrontmatter($content);

        if ($parsed === null) {
            return $content;
        }

        [$fields, $body] = $parsed;

        $transformed = $this->applySpec($fields, $spec);

        return $this->serializeFrontmatter($transformed, $body);
    }

    /**
     * @param string $content
     *
     * @return array{array<string, string>, string}|null
     */
    protected function parseFrontmatter(string $content): ?array
    {
        if (!preg_match(static::FRONTMATTER_PATTERN, $content, $matches)) {
            return null;
        }

        return [$this->parseFields($matches[1]), $matches[2]];
    }

    /**
     * @param string $block
     *
     * @return array<string, string>
     */
    protected function parseFields(string $block): array
    {
        $fields = [];

        foreach (explode("\n", trim($block)) as $line) {
            $line = trim($line);

            if (!str_contains($line, ':')) {
                continue;
            }

            [$key, $rawValue] = explode(':', $line, 2);
            $fields[trim($key)] = trim(trim($rawValue), '"\'');
        }

        return $fields;
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed> $spec
     *
     * @return array<string, string>
     */
    protected function applySpec(array $fields, array $spec): array
    {
        $result = [];
        $scopeKey = $spec['scope_key'] ?? null;
        $scopeValue = $fields['paths'] ?? ($spec['fallback_scope'] ?? null);
        $hasScope = isset($fields['paths']);

        if ($spec['keep_name'] && isset($fields['name'])) {
            $result['name'] = $fields['name'];
        }

        if ($spec['keep_description'] && isset($fields['description'])) {
            $result['description'] = $fields['description'];
        }

        $triggerKey = $hasScope ? 'trigger_with_scope' : 'trigger_no_scope';
        $trigger = $spec[$triggerKey] ?? null;

        if ($trigger !== null) {
            $result['trigger'] = $trigger;
        }

        if ($scopeKey === null) {
            return $result;
        }

        if ($scopeValue !== null) {
            $result[$scopeKey] = $scopeValue;
        }

        return $result;
    }

    /**
     * @param array<string, string> $fields
     * @param string $body
     *
     * @return string
     */
    protected function serializeFrontmatter(array $fields, string $body): string
    {
        if ($fields === []) {
            return $body;
        }

        $lines = [];

        foreach ($fields as $key => $value) {
            $lines[] = sprintf('%s: "%s"', $key, $value);
        }

        return sprintf("---\n%s\n---\n%s", implode("\n", $lines), $body);
    }
}
