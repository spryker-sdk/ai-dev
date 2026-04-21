---
name: client-zed-communication
description: Use when writing or reviewing communication between Yves/Glue and Zed through the Client layer via Zed Stub and GatewayController.
paths: "src/**/Yves/**/*.php,src/**/Glue/**/*.php,src/**/Client/**/*.php,src/**/Zed/**/Communication/Controller/GatewayController.php"
---

**Architecture rule**
Communication between Yves/Glue and Zed MUST go through the Client layer using a Zed Stub and a GatewayController. Direct calls from Yves/Glue to Zed are forbidden.

More than one Zed call per request is strongly discouraged — each call is an HTTP round-trip and degrades performance significantly.

## Full communication flow

```
Yves/Glue
  → Client (public method)
    → Zed Stub (via ZedRequestClient::call())
      → Zed GatewayController action
        → Facade method
```

## Client

- Exposes a public method that delegates to the Zed Stub via Factory
- MUST NOT contain business logic

## Zed Stub

- Lives in `Client/[Module]/Zed/[Module]Stub.php`
- Calls Zed via `ZedRequestClient::call('/module/gateway/action', $transferObject)`
- MUST accept and return Transfer Objects only
- `ZedRequestClient` MUST be injected via DependencyProvider

## GatewayController

- Lives in `Zed/[Module]/Communication/Controller/GatewayController.php`
- MUST extend `AbstractGatewayController`
- Each action MUST accept exactly ONE Transfer Object and return a Transfer Object or null
- MUST only delegate to the Facade — no business logic
