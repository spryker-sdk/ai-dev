<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SprykerSdk\Service\AiDev\Service\FileSystem;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

class FilesFinder implements FilesFinderInterface
{
    public function __construct(
        protected PathResolverInterface $pathResolver
    ) {
    }

    public function findFiles(string $path, string $extension, string $searchString = ''): array
    {
        $resolvedPath = $this->pathResolver->resolvePath($path);

        if (!is_dir($resolvedPath)) {
            return [
                'error' => sprintf('Directory not found: %s (resolved to: %s)', $path, $resolvedPath),
                'files' => [],
            ];
        }

        $files = $this->scanFiles($resolvedPath, $searchString, $extension);

        return [
            'path' => $resolvedPath,
            'searchString' => $searchString,
            'extension' => $extension,
            'totalFiles' => count($files),
            'files' => $files,
        ];
    }

    protected function scanFiles(string $path, string $searchString, string $extension): array
    {
        $files = [];
        $pattern = sprintf('/^.+\.%s$/i', preg_quote($extension, '/'));

        $directoryIterator = new RecursiveDirectoryIterator($path);
        $iterator = new RecursiveIteratorIterator($directoryIterator);
        $fileIterator = new RegexIterator($iterator, $pattern, RegexIterator::GET_MATCH);

        foreach ($fileIterator as $file) {
            $filePath = $file[0];

            if ($this->shouldIncludeFile($filePath, $searchString)) {
                $files[] = $this->normalizeFilePath($filePath);
            }
        }

        sort($files);

        return $files;
    }

    protected function shouldIncludeFile(string $filePath, string $searchString): bool
    {
        if ($searchString === '') {
            return true;
        }

        $fileName = basename($filePath);
        $searchTerms = $this->parseSearchString($searchString);

        foreach ($searchTerms as $term) {
            if (stripos($fileName, $term) === false) {
                return false;
            }
        }

        return true;
    }

    protected function parseSearchString(string $searchString): array
    {
        return array_filter(
            array_map('trim', explode(' ', $searchString)),
            fn ($term) => $term !== '',
        );
    }

    protected function normalizeFilePath(string $filePath): string
    {
        return str_replace('\\', '/', $filePath);
    }
}
