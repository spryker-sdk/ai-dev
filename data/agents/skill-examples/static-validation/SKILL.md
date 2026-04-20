---
name: static-validation
description: >
  Run static code analysis (phpcbf, phpcs, phpstan) on PHP files after implementing or editing code.
  Use this after any PHP code changes to catch style issues, architecture violations, and type errors before committing.
  Trigger whenever the user asks to "validate", "lint", "check code", "run static analysis", "fix phpcs/phpstan", or "run QA on changes".
---

## Step 1: Determine paths to validate

Choose the source for PHP file paths (in priority order):

1. **User-provided paths** — use them directly if the user listed specific files
2. **Git diff ahead of master** — files changed vs master branch:
   ```bash
   git diff $(git merge-base HEAD master) --name-only | grep '\.php$'
   ```
   Filter out deleted files: only include paths where the file exists on disk.
3. **Uncommitted new files** — untracked PHP files:
   ```bash
   git ls-files --others --exclude-standard | grep '\.php$'
   ```

## Step 2: Run validation

```bash
# "${paths[@]}"comma separated list of paths

docker/sdk cli vendor/bin/phpcbf --standard=vendor/spryker/code-sniffer/Spryker/ruleset.xml -p -s --extensions=php "${paths[@]}"
docker/sdk cli vendor/bin/phpcs --standard=phpcs.xml -p -s --extensions=php "${paths[@]}"
docker/sdk cli vendor/bin/phpstan analyse "${paths[@]}" -c phpstan.neon
```

