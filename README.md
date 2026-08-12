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

Register the console commands in your `ConsoleDependencyProvider`. The `class_exists()` guards keep the project bootable on environments where the dev dependency is absent (e.g. production):

```php
use SprykerSdk\Zed\AiDev\Communication\Console\AiToolSetupConsole;
use SprykerSdk\Zed\AiDev\Communication\Console\McpServerConsole;

protected function getConsoleCommands(Container $container): array
{
    if (class_exists(McpServerConsole::class)) {
        $commands[] = new McpServerConsole();
    }

    if (class_exists(AiToolSetupConsole::class)) {
        $commands[] = new AiToolSetupConsole();
    }
}
```

> The previously documented `GenerateAgentsFileConsole`, `GenerateSkillsConsole`, and `GeneratePromptsConsole` commands are outdated. Their functionality is delivered through the Claude plugin (see below) and `ai-dev:setup` — do not wire them.

## Quick Start

Connect to AI assistants:

**Claude Code**
```bash
claude mcp add spryker-project  -- $(pwd)/docker/sdk console ai-dev:mcp-server -q
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

## Claude Plugin

This repository also ships a Claude Code plugin — `spryker-ai-dev-sdk` — that bundles Spryker-aware skills (e.g. `propel-schema`, `data-import`, `code-review`, `cypress-e2e-test`, `codecept-functional`, `static-validation`, `spryker-upgrade`, `yves-atomic-frontend`, `ai-dev-setup`) and a `spryker-code-reviewer` agent. Source layout:

- `.claude-plugin/marketplace.json` — marketplace manifest (`spryker-plugins-official`).
- `plugins/spryker-ai-dev-sdk/.claude-plugin/plugin.json` — plugin manifest.
- `plugins/spryker-ai-dev-sdk/skills/<name>/SKILL.md` — individual skills.
- `plugins/spryker-ai-dev-sdk/agents/*.md` — bundled subagents.

### Install from the Spryker marketplace

Inside Claude Code:

```text
/plugin marketplace add spryker-sdk/ai-dev
/plugin install spryker-ai-dev-sdk@spryker-plugins-official
```

After install, restart the Claude Code session for the new skills and agents to appear. Verify with `/plugin` (Claude Code lists installed plugins) and by invoking a user-facing skill such as `/ai-dev-setup`.

### Test the plugin locally

Useful while developing skills or agents in this repo without publishing first.

1. Launch Claude Code with the plugin loaded directly from this checkout:

   ```bash
   claude --plugin-dir /absolute/path/to/vendor/spryker-sdk/ai-dev/plugins/spryker-ai-dev-sdk
   ```

   The path must point at the plugin directory (the one containing `.claude-plugin/plugin.json`), not at the repository root.

2. Verify the plugin is loaded with `/plugin` and by invoking a user-facing skill such as `/ai-dev-setup`.

3. After editing a `SKILL.md` or an agent definition, restart the session — skill frontmatter is parsed at load time and is not hot-reloaded.

Reference: [Discover plugins — Claude Code docs](https://code.claude.com/docs/en/discover-plugins).

## Prompts

Prompts are auto-generated from the [Spryker Prompt Library](https://github.com/spryker-dev/prompt-library) on first run. To regenerate:

```bash
docker/sdk console ai-dev:generate-prompts
```

## Documentation

For detailed setup, configuration, and extension points:
- [AI Dev Overview](https://docs.spryker.com/docs/dg/dev/ai/ai-dev/ai-dev-overview.html)
- [MCP Server Configuration](https://docs.spryker.com/docs/dg/dev/ai/ai-dev/ai-dev-mcp-server.html)
- [Claude Code — Discover plugins](https://code.claude.com/docs/en/discover-plugins)

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
