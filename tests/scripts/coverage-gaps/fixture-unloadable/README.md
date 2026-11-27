# Fixture: classes and files that cannot be analysed

Used by `../CoverageGapsTest.php`. The script reports each of these under "Could not analyse" and exits with code 3.

| File | Problem |
|---|---|
| `src/Broken/Zed/Broken/Business/Model/IncompatibleStep.php` | Signature incompatible with the interface: a compile-time fatal error when the class loads |
| `src/Broken/Zed/Broken/Business/Model/MissingParentStep.php` | The parent class does not exist |
| `src/Broken/Zed/Broken/Business/Model/SyntaxErrorStep.php` | Parse error in a source file |
| `tests/BrokenTest/Zed/Broken/Business/SyntaxErrorTest.php` | Parse error in a test file |

`WorkingStep` loads without error and is analysed as usual.
