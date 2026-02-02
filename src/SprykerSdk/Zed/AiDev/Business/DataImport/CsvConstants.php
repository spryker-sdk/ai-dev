<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

class CsvConstants
{
    public const string FILE_NOT_FOUND = 'FILE_NOT_FOUND';

    public const string FILE_NOT_READABLE = 'FILE_NOT_READABLE';

    public const string FILE_NOT_WRITABLE = 'FILE_NOT_WRITABLE';

    public const string NO_HEADERS = 'NO_HEADERS';

    public const string EMPTY_FILE = 'EMPTY_FILE';

    public const string COLUMN_NOT_FOUND = 'COLUMN_NOT_FOUND';

    public const string INVALID_CSV_FORMAT = 'INVALID_CSV_FORMAT';

    public const string INVALID_CRITERIA = 'INVALID_CRITERIA';

    public const string INVALID_MAPPINGS = 'INVALID_MAPPINGS';

    public const string INVALID_FILTERS = 'INVALID_FILTERS';

    public const string INVALID_TRANSFORMATIONS = 'INVALID_TRANSFORMATIONS';

    public const string WOULD_DELETE_ALL_ROWS = 'WOULD_DELETE_ALL_ROWS';

    public const string OPERATION_FAILED = 'OPERATION_FAILED';

    public const string DEFAULT_ENCODING = 'UTF-8';

    /**
     * @var array<string>
     */
    public const array SUPPORTED_ENCODINGS = [
        'UTF-8',
        'ISO-8859-1',
        'Windows-1252',
    ];

    /**
     * @var array<string>
     */
    public const array SUPPORTED_DELIMITERS = [
        ',',
        ';',
        "\t",
    ];

    public const string DEFAULT_DELIMITER = ',';

    public const int LARGE_FILE_THRESHOLD = 1000;

    public const string BACKUP_EXTENSION = '.backup';

    public const string TEMP_EXTENSION = '.tmp';

    public const string MODE_APPEND = 'append';

    public const string MODE_REPLACE = 'replace';

    /**
     * @var array<string>
     */
    public const array SUPPORTED_MODES = [
        self::MODE_APPEND,
        self::MODE_REPLACE,
    ];

    public const string OPERATION_ADD = 'add';

    public const string OPERATION_SUBTRACT = 'subtract';

    public const string OPERATION_MULTIPLY = 'multiply';

    public const string OPERATION_DIVIDE = 'divide';

    /**
     * @var array<string>
     */
    public const array SUPPORTED_OPERATIONS = [
        self::OPERATION_ADD,
        self::OPERATION_SUBTRACT,
        self::OPERATION_MULTIPLY,
        self::OPERATION_DIVIDE,
    ];
}
