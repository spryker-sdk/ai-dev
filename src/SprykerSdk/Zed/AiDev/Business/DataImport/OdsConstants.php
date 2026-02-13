<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

class OdsConstants
{
    public const string NAMESPACE_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

    public const string NAMESPACE_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

    public const string NAMESPACE_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    public const string ODS_CONTENT_FILE = 'content.xml';

    public const string XPATH_TABLE_ROW = './/table:table-row';

    public const string XPATH_TABLE_CELL = './/table:table-cell';

    public const string XPATH_TEXT_PARAGRAPH = './/text:p';

    public const string VALUE_TYPE_STRING = 'string';

    public const string VALUE_TYPE_FLOAT = 'float';

    public const string VALUE_TYPE_PERCENTAGE = 'percentage';

    public const string VALUE_TYPE_CURRENCY = 'currency';

    public const string VALUE_TYPE_DATE = 'date';

    public const string VALUE_TYPE_TIME = 'time';

    public const string VALUE_TYPE_BOOLEAN = 'boolean';

    public const string BOOLEAN_TRUE = 'true';

    public const string BOOLEAN_VALUE_TRUE = '1';

    public const string BOOLEAN_VALUE_FALSE = '0';

    public const int REPEAT_OFFSET = 1;

    public const string DEFAULT_SHEET_NAME = 'Sheet';

    public const int DIRECTORY_PERMISSIONS = 0755;

    public const string FILE_NOT_FOUND = 'ods_file_not_found';

    public const string FILE_NOT_READABLE = 'ods_file_not_readable';

    public const string DIRECTORY_CREATE_FAILED = 'directory_create_failed';

    public const string INVALID_PATH = 'invalid_path';
}
