# AiDev Module

[![Latest Stable Version](https://poser.pugx.org/spryker-sdk/ai-dev/v/stable.svg)](https://packagist.org/packages/spryker-sdk/ai-dev)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%208.3-8892BF.svg)](https://php.net/)

> **Experimental Module**: This module is experimental and not stable. There is no backward compatibility promise.

Connect your Spryker application to AI assistants through the Model Context Protocol (MCP).

## Documentation

- [Install AI Dev](https://docs.spryker.com/docs/dg/dev/ai/ai-dev/ai-dev-installation) — installation and setup.
- [AI Dev SDK](https://docs.spryker.com/docs/dg/dev/ai/ai-dev/ai-dev) — overview, configuration, usage, and extension points.

## Claude Code plugin — enforcement hooks

The `spryker-ai-dev-sdk` plugin ships hooks (`plugins/spryker-ai-dev-sdk/hooks/`) that make the
project-starter run's load-bearing rules checkable instead of remembered: the static data gate
(`validate.php gate`) runs before every `docker/sdk reset|clean-data|up|console data:import` and
denies a known-broken data set, every rebuild is confirmed by the developer and counted, a wizard
step cannot be marked `done` while the gate fails, and installed skill copies are read-only.
See [hooks/README.md](plugins/spryker-ai-dev-sdk/hooks/README.md) and the design note in
[docs/plans/2026-09-16-enforcement-hooks-design.md](docs/plans/2026-09-16-enforcement-hooks-design.md).
Setup installs (no plugin) merge `hooks/settings.example.json` into `.claude/settings.json`.

## Contribution

We welcome contributions to improve this experimental module.

### How to Contribute

1. Fork the repository
2. Create a feature branch
3. Make your changes following Spryker coding standards
4. Submit a pull request with a clear description of your changes

### Reporting Issues

Please report issues through the GitHub issue tracker with:
- Clear description of the problem
- Steps to reproduce
- Expected vs actual behavior
- Environment details (PHP version, Spryker version, etc.)

### Development

**Prerequisites**
- Docker SDK `^1.71.0`
- PHP `^8.3`

**Setup for Development**
```bash
composer install
vendor/bin/phpstan analyze
vendor/bin/phpcs --standard=phpcs.xml
```

## License

This module is released under the Spryker Evaluation License Agreement. See LICENSE file for details.
