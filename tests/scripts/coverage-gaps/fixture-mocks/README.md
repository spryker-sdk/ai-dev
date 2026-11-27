# Fixture: test methods with mocks and skips

Used by `../CoverageGapsTest.php`. Each file is one test class for the `src/` of `../fixture`. A test run copies one
file into a temporary project and checks how `GizmoFacade::format()` and `Formatter::format()` are counted. The class
comment of each file states the expected result.
