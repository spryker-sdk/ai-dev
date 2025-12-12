# AiDev Module

[![Latest Stable Version](https://poser.pugx.org/spryker-sdk/ai-dev/v/stable.svg)](https://packagist.org/packages/spryker-sdk/ai-dev)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%208.3-8892BF.svg)](https://php.net/)

> **Experimental Module**: This module is experimental and not stable. There is no backward compatibility promise.

Connect your Spryker application to AI assistants through the Model Context Protocol (MCP).

## Installation

```bash
composer require spryker-sdk/ai-dev --dev
docker/sdk console transfer:generate
```

## Quick Start

Start the MCP server:

```bash
docker/sdk console ai-dev:mcp-server -q
```

Connect to AI assistants:

**Claude Code**
```bash
claude mcp add spryker-project "$(pwd)/docker/sdk console ai-dev:mcp-server -q"
```

**Claude Desktop** - Add to `claude_desktop_config.json`:
```json
{
  "mcpServers": {
    "spryker-ai-dev": {
      "command": "/path/to/your/project/docker/sdk",
      "args": ["console", "ai-dev:mcp-server", "-q"]
    }
  }
}
```

## Prompts

Prompts are auto-generated from the [Spryker Prompt Library](https://github.com/spryker-dev/prompt-library) on first run. To regenerate:

```bash
docker/sdk console ai-dev:generate-prompts
```

## Documentation

For detailed setup, configuration, and extension points:
- [AI Dev Overview](https://docs.spryker.com/docs/dg/dev/ai/ai-dev/ai-dev-overview.html)
- [MCP Server Configuration](https://docs.spryker.com/docs/dg/dev/ai/ai-dev/ai-dev-mcp-server.html)

## Debugging

Use [MCP Inspector](https://modelcontextprotocol.io/docs/tools/inspector) to test your MCP server:

```bash
npx @modelcontextprotocol/inspector docker/sdk console ai-dev:mcp-server -q
```

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
