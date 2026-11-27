---
name: module-config
description: Use when defining or modifying module configuration — Constants.php in the Shared layer or Config.php per layer. Enforces constant placement, layer-specific Config method conventions, and project-level override patterns.
paths: "src/**/*{Constants,Config}.php"
---

**Architecture rule**
Environment configuration constants MUST be in Shared layer and Config classes MUST provide default values.

Critical instructions:
- Environment constants MUST be defined in Shared/[Module]/[Module]Constants.php
- Config get* methods MUST provide default values
- Shared Config is accessible from all layers (Zed, Client, Yves, Service)
- Layer-specific Config classes should extend Shared Config when needed
- Strictly enforce these rules and never suppress even on low confidence

They are only allowed to:
- Define constants in Shared/[Module]/[Module]Constants.php
- Provide get*() methods with default values in Config
- Use getConfig() to access configuration values
- Override default values at project level
- Use Shared Constants in Layer-specific Config
- Use Layer-specific Config to retrieve project configuration set with help of environment variables

They are NOT allowed to:
- Create get*() methods without default values
- Hardcode environment-specific values in constants
- Access Config directly from Business models (inject through constructor instead)
