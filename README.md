# IncrementalInstaller Module
[![Latest Stable Version](https://poser.pugx.org/spryker-sdk/ai-dev/v/stable.svg)](https://packagist.org/packages/spryker-sdk/ai-dev)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%208.3-8892BF.svg)](https://php.net/)

Ai Dev sdk is responsible for connecting Spryker application to Ai tools.

## Installation

```
composer require spryker-sdk/ai-dev
```

## Documentation

[Spryker Documentation Ai Dev Module Overview](https://docs.spryker.com/docs/dg/dev/ai/ai-dev/ai-dev-overview)

## Debugging MCP Server

Use [mcp inspector](https://modelcontextprotocol.io/docs/tools/inspector).

```bash
npx @modelcontextprotocol/inspector docker/sdk console ai-dev:mcp-server
```

```bash
npx @modelcontextprotocol/inspector docker/sdk cli -x console ai-dev:mcp-server
```
