<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Communication\Helper;

class PromptCacheManager
{
    protected const string CACHE_DIR = APPLICATION_ROOT_DIR . '/data/cache';

    protected const string CACHE_FILE = self::CACHE_DIR . '/prompts_cache.json';

    protected const int CACHE_TTL = 86_400;

    protected static array $runtimeCache = [];

    /**
     * @return array|null
     */
    public static function getCache(): ?array
    {
        if (count(static::$runtimeCache) > 0) {
            return static::$runtimeCache;
        }

        if (!file_exists(static::CACHE_FILE)) {
            return null;
        }

        $cacheData = json_decode(file_get_contents(static::CACHE_FILE), true);

        if (!is_array($cacheData) || !isset($cacheData['timestamp'], $cacheData['prompts'])) {
            return null;
        }

        if ((time() - $cacheData['timestamp']) > static::CACHE_TTL) {
            return null;
        }

        return static::$runtimeCache = $cacheData['prompts'];
    }

    /**
     * @param array $prompts
     *
     * @return void
     */
    public static function setCache(array $prompts): void
    {
        static::$runtimeCache = $prompts;

        if (!is_dir(static::CACHE_DIR)) {
            mkdir(static::CACHE_DIR, 0755, true);
        }

        $cacheData = [
            'timestamp' => time(),
            'prompts' => $prompts,
        ];

        file_put_contents(static::CACHE_FILE, json_encode($cacheData, JSON_PRETTY_PRINT));
    }

    /**
     * @param string $filename
     *
     * @return array|null
     */
    public static function getPromptByFilename(string $filename): ?array
    {
        $cache = static::getCache();

        if ($cache === null) {
            return null;
        }

        foreach ($cache as $prompt) {
            if ($prompt['filename'] === $filename) {
                return $prompt;
            }
        }

        return null;
    }

    /**
     * @param string $query
     *
     * @return array
     */
    public static function searchPrompts(string $query): array
    {
        $cache = static::getCache();

        if ($cache === null) {
            return [];
        }

        $queryLower = mb_strtolower($query);
        $matches = [];

        foreach ($cache as $prompt) {
            if (static::matchesQuery($prompt, $queryLower)) {
                $matches[] = $prompt;
            }
        }

        return $matches;
    }

    /**
     * @param array $prompt
     * @param string $queryLower
     *
     * @return bool
     */
    private static function matchesQuery(array $prompt, string $queryLower): bool
    {
        if (isset($prompt['title']) && mb_strpos(mb_strtolower($prompt['title']), $queryLower) !== false) {
            return true;
        }

        if (isset($prompt['description']) && mb_strpos(mb_strtolower($prompt['description']), $queryLower) !== false) {
            return true;
        }

        if (isset($prompt['tags']) && is_array($prompt['tags'])) {
            foreach ($prompt['tags'] as $tag) {
                if (mb_strpos(mb_strtolower($tag), $queryLower) !== false) {
                    return true;
                }
            }
        }

        if (isset($prompt['when_to_use']) && mb_strpos(mb_strtolower($prompt['when_to_use']), $queryLower) !== false) {
            return true;
        }

        if (isset($prompt['author']) && mb_strpos(mb_strtolower($prompt['author']), $queryLower) !== false) {
            return true;
        }

        return false;
    }
}
