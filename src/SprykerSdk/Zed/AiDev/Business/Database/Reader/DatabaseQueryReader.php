<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\Database\Reader;

use PDO;
use Propel\Runtime\Propel;
use Throwable;

class DatabaseQueryReader implements DatabaseQueryReaderInterface
{
    protected const int DEFAULT_ROW_LIMIT = 1000;

    protected const string ERROR_NOT_READ_ONLY = 'Only read-only queries are allowed. Query must start with one of: SELECT, SHOW, DESCRIBE, or EXPLAIN.';

    protected const string ERROR_MULTIPLE_STATEMENTS = 'Multiple statements are not allowed';

    protected const string ERROR_EXECUTION = 'Query execution failed';

    protected const string ERROR_QUERY_IS_EMPTY = 'Query is empty';

    public function executeQuery(string $query): string
    {
        $query = trim($query);

        if ($query === '') {
            return $this->buildErrorResponse(static::ERROR_EXECUTION, static::ERROR_QUERY_IS_EMPTY);
        }

        if (!$this->isReadOnlyQuery($query)) {
            return $this->buildErrorResponse(static::ERROR_NOT_READ_ONLY);
        }

        if ($this->hasMultipleStatements($query)) {
            return $this->buildErrorResponse(static::ERROR_MULTIPLE_STATEMENTS);
        }

        $limitedQuery = $this->applyLimit($query);

        try {
            $connection = Propel::getConnection();
            $statement = $connection->prepare($limitedQuery);
            $statement->execute();

            $data = $statement->fetchAll(PDO::FETCH_ASSOC);

            return $this->buildSuccessResponse($data);
        } catch (Throwable $exception) {
            return $this->buildErrorResponse(static::ERROR_EXECUTION, $exception->getMessage());
        }
    }

    protected function isReadOnlyQuery(string $query): bool
    {
        $normalizedQuery = strtoupper(preg_replace('/\s+/', ' ', $query));

        return str_starts_with($normalizedQuery, 'SELECT ')
            || str_starts_with($normalizedQuery, 'SHOW ')
            || str_starts_with($normalizedQuery, 'DESCRIBE ')
            || str_starts_with($normalizedQuery, 'EXPLAIN ');
    }

    protected function hasMultipleStatements(string $query): bool
    {
        $trimmedQuery = rtrim($query, " \t\n\r\0\x0B;");
        $length = strlen($trimmedQuery);
        $inSingleQuote = false;
        $inDoubleQuote = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $trimmedQuery[$i];
            $prevChar = $i > 0 ? $trimmedQuery[$i - 1] : '';

            if ($char === "'" && $prevChar !== '\\' && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;

                continue;
            }

            if ($char === '"' && $prevChar !== '\\' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;

                continue;
            }

            if ($char === ';' && !$inSingleQuote && !$inDoubleQuote) {
                return true;
            }
        }

        return false;
    }

    protected function applyLimit(string $query): string
    {
        $normalizedQuery = strtoupper($query);

        if (str_contains($normalizedQuery, ' LIMIT ')) {
            return $query;
        }

        if (!str_starts_with($normalizedQuery, 'SELECT ')) {
            return $query;
        }

        return sprintf('%s LIMIT %d', rtrim($query, ';'), static::DEFAULT_ROW_LIMIT);
    }

    /**
     * @param array<int, array<string, mixed>> $data
     *
     * @return string
     */
    protected function buildSuccessResponse(array $data): string
    {
        return json_encode([
            'data' => $data,
            'error' => null,
        ], JSON_PRETTY_PRINT);
    }

    protected function buildErrorResponse(string $error, string $details = ''): string
    {
        $errorMessage = $error;

        if ($details !== '') {
            $errorMessage = sprintf('%s: %s', $error, $details);
        }

        return json_encode([
            'data' => null,
            'error' => $errorMessage,
        ], JSON_PRETTY_PRINT);
    }
}
