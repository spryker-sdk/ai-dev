# spryker-runtime

**Run the local Spryker app and observe what it really does** — log in as any actor, drive the UI in
Chrome, call endpoints over HTTP, and execute `docker/sdk cli console` commands against the running
Docker environment.

This is the **shared building block** the other runtime skills delegate to rather than
re-implementing. `spryker-qa-coverage` invokes it to execute every test case (its whole mode table
points here); `spryker-bugfix` uses it to reproduce and to verify a fix; the PRD and verification
skills use it to confirm how a flow behaves today. Host resolution, per-actor login, the five modes,
and the Chrome safety rules live here once.

## When it triggers

"Run the app", "manually test this", "do a QA pass", "log in to the back office", "call this
endpoint", "execute this console command" — and any time you need to walk a flow as a specific
actor, confirm how an existing flow behaves before writing a PRD or plan, reproduce a bug, verify a
fix end-to-end, inspect what an endpoint returns, or check which actors and roles exist at `/user`.

## Flow schema

```mermaid
flowchart TD
    A([Runtime session starts]) --> H["runtime.php env — hosts, cookies,<br/>containers from the deploy file<br/>NEVER hard-code — a user-supplied<br/>target always wins"]

    H --> Q{"What does the<br/>question need?"}

    Q -- "does data/command exist" --> M1["Mode 1 — Console CLI<br/>docker/sdk cli console …<br/>+ mariadb / redis-cli / rabbitmqadmin"]
    Q -- "what does an endpoint return" --> M2["Mode 2 — HTTP/API<br/>curl with cookie session<br/>or Glue bearer token"]
    Q -- "rendered UI, JS, real login" --> M3["Mode 3 — Browser<br/>needs a browser MCP server"]
    Q -- "many requests / CSRF flow" --> M4["Mode 4 — Browser-seeded curl<br/>log in once, harvest the REAL<br/>request, replay in the shell"]

    M3 --> L["Pick the actor + its OWN login URL<br/>account from runtime.php accounts"]
    L --> LOGIN{"Login OK?<br/>fill &amp; submit ATOMICALLY —<br/>no navigate in between"}
    LOGIN -- "no" --> DB["Look up real accounts read-only<br/>spy_user / spy_customer / spy_merchant_user"]
    DB --> LOGIN2{"Still blocked?"}
    LOGIN2 -- "yes" --> ASK([Ask the user for<br/>account + password])
    LOGIN2 -- "no" --> ACT
    LOGIN -- "yes" --> ACT["Drive the flow step by step<br/>happy path + obvious negatives"]

    ACT --> EV["Observe concrete evidence<br/>status · key fields · rendered result<br/>read_console_messages<br/>read_network_requests"]

    M4 --> HARVEST["Read the real request:<br/>method+URL, Cookie, _token,<br/>Content-Type, custom headers"]
    HARVEST --> BLOCKED{"Harness returns<br/>BLOCKED for<br/>cookie/token?"}
    BLOCKED -- "yes" --> M5["Mode 5 — Page-context fetch<br/>run the page's own fetch<br/>proves the SERVER, not the user flow"]
    BLOCKED -- "no" --> REPLAY["Replay + iterate with curl"]
    REPLAY --> R403{"302 to login<br/>or 403/CSRF?"}
    R403 -- "yes · re-harvest" --> HARVEST
    R403 -- "no" --> EV
    M5 --> EV
    M1 --> EV
    M2 --> EV

    EV --> OK{"Behaves as<br/>expected?"}
    OK -- "no · 500, stale, 404, dead button" --> TS["Troubleshoot: cache:empty-all first,<br/>then the targeted cache<br/>twig / navigation / router /<br/>class-resolver / transfer / propel"]
    TS --> RELOAD["Rebuilt assets?<br/>ALWAYS hard-reload cmd+shift+r —<br/>bundles ship at a stable ?v=current URL"]
    RELOAD --> ACT
    OK -- "yes" --> REP([Report per step:<br/>what you did, expected, actual,<br/>URL/endpoint + evidence<br/>note anything BLOCKED, never 'passed'])

    classDef step fill:#1f6feb,stroke:#0b3d91,color:#fff;
    classDef decision fill:#f0ad4e,stroke:#8a6d3b,color:#000;
    classDef terminal fill:#2ea043,stroke:#176f2c,color:#fff;
    class H,M1,M2,M3,M4,M5,L,DB,ACT,EV,HARVEST,REPLAY,TS,RELOAD step;
    class Q,LOGIN,LOGIN2,BLOCKED,R403,OK decision;
    class A,ASK,REP terminal;
```

## The five modes

| Mode | Use it for | Cost |
|------|-----------|------|
| **1 · Console (CLI)** | Does the data/command exist, run a console command, inspect DB / Redis / queues. `console list` prints the full catalogue — find the real command name instead of guessing. | Cheapest |
| **2 · HTTP / API** | Exactly what an endpoint returns. `runtime.php login` leaves a cookie jar for Yves/Back Office/Merchant Portal or a token file for Glue; curl reuses it. | Cheap |
| **3 · Browser** | Rendered UI, client JS, establishing a login session. Needs a browser-automation MCP server; see the Browser tool map in SKILL.md. | Expensive |
| **4 · Browser-seeded curl** | Multi-step, CSRF-protected, or repeated scenarios. Log in **once**, perform the action once and read the request it *actually* sent, then replay scriptably. | Fast after setup |
| **5 · Page-context fetch** | A CSRF/session endpoint whose token the harness won't let you read, or proving the server when a UI control is dead. Runs the page's own `fetch` — the cookie attaches implicitly, the secret is never printed. | Fallback |

## The script

`scripts/runtime.php`, run from the project root:

| Call | What it does |
|---|---|
| `runtime.php env [--deploy-file <file>] [--app <application>] [--region <region or store>]` | One line per application endpoint of the deploy file (`deploy.dev.yml` by default): application, store or region, URL with scheme (`docker.ssl.enabled`) and port, session cookie name (the host with `.` → `-`), container (`<namespace>_<application key>_1`); then the services with their containers and endpoints. No credentials of the deploy file. |
| `runtime.php accounts [--role <role>] [--user <text>] [--limit N]` | The seeded accounts: Back Office users and agents from `src/<Ns>/Zed/User/UserConfig.php` (else the vendor `UserConfig.php`), merchant users and customers from `data/import/**` (`data/import/common` first). One line per user name under one header line per file, with the line and where its password is; at most 200 lines per role. Never a password. |
| `runtime.php login <app> <user> [--region …] [--host …] [--path …] [--insecure]` | Logs in to a host the deploy file names: a session for `yves`, `agent`, `backoffice`, `merchant-portal` (cookie jar), a token for `glue` (`/access-tokens`), `glue-storefront`, `glue-backend` (`/token`). Redirects go only to hosts of the deploy file, as GET requests. Password from `SPRYKER_RUNTIME_PASSWORD`, else from the project's files (a hashed one is matched against the plain passwords in the project's files and E2E fixtures). Writes `.ai-dev/runtime/sessions/` (ignored by git) and prints one line, never a secret. |

`scripts/deploy-file.php` reads the deploy file: with `symfony/yaml` when the project's `vendor/` has it, else with a YAML subset that stops with an error on anchors, aliases, merge keys and flow collections.

Dependencies inside the plugin: `runtime.php` requires `spryker-upgrade/scripts/backoffice-smoke.php` (the login form parser), and `spryker-refresher/scripts/refresh.php` requires `scripts/deploy-file.php`. Copy the skills together. Tests:
`php scripts/runtime.test.php` (fixture deploy and data files, a local `php -S` server for the login).

## Actors and login

Every demo account shares the password **`change123`**, but each actor has its **own** login URL —
using the wrong one fails silently.

| Actor | Path (on the resolved host) | Role in `runtime.php accounts` | Scope |
|-------|------------------------------|--------------------------------|-------|
| Back Office user (admin) | `<host:backoffice>/security-gui/login` | `backoffice-user` | Back Office dashboard |
| Customer | `<host:yves>/DE/en/login` | `customer` | `/DE/en/customer/overview` |
| Agent | `<host:yves>/agent/login` | `backoffice-user, agent` | Agent dashboard; can impersonate a customer |
| Merchant user | `<host:merchant-portal>/security-merchant-portal-gui/login` | `merchant-user` | **One** merchant, via `spy_merchant_user.fk_merchant` |
| Merchant Agent | same URL as Merchant user | `merchant-agent` | **Across** merchants |

When the defaults don't work, the skill runs read-only lookups against `spy_user`, `spy_customer`,
and `spy_merchant_user` — and if authentication still fails, it **asks the user** rather than
guessing. Passwords are hashed and cannot be read back.

## Design decisions baked in

- **Hosts are resolved, never hard-coded.** `runtime.php env` reads them from
  `groups.<group>.applications.<app>.endpoints` of the active deploy file, with the scheme, the port,
  the cookie name and the container. A user-named target — a cloud or staging URL — always wins over
  the local default.
- **No secret on the output.** The accounts call names where a password is; the login call puts the
  session or token in a file under `.ai-dev/runtime/` and prints its path.
- **Pick the lightest mode that answers the question.** Chrome only when rendered UI or a real
  session is genuinely the thing under test.
- **Mode 5 proves the server, not the user flow.** The JS that builds the payload, fires the
  request, and applies the response is exactly what a page-context fetch bypasses.
- **A dead Zed button is a race until proven otherwise.** The `DOMContentLoaded` controller-init
  failure is intermittent — the same page can be inert on one load and fine on the next. Reload at
  least 3 times, check whether a control sharing the same `#init()` binding is *also* dead, and rule
  out your own driver (`form_input` sets `.value` without firing `input`/`change`) before blaming
  the code.
- **Instrument the network *before* the action.** Prime `read_network_requests` with `clear:true`
  immediately before the click; a `window.fetch` monkey-patch can miss a request the bundle issued
  via its own captured reference. "No browser-layer request **and** no server-side row" is the
  conclusive proof.
- **Rebuild assets → hard reload → then test, as one inseparable sequence.** Spryker serves bundles
  at a stable `?v=current` URL, so a normal reload keeps serving the cached bundle. This is the #1
  cause of "I rebuilt but nothing changed".
- **Never trigger `alert`/`confirm`/`prompt`** — modal dialogs freeze the Chrome extension. If a
  tool misbehaves after 2–3 tries, stop and ask rather than looping.
- **Stale cache before broken feature.** A 500, a stale menu, a 404 route, or "my change isn't
  showing" is usually generated code or cache: `cache:empty-all` first, then the targeted command
  (`twig:cache:warmer`, `navigation:cache:remove`, the per-app `router:cache:warm-up:*`,
  `composer dump-autoload -o` + `cache:class-resolver:build`, `transfer:generate`, `propel:install`).

## Output

A factual, reproducible account of what happened: command output, HTTP status and key response
fields, the resolved endpoint path, or the rendered behavior — plus per-step pass/fail with
evidence, an optional `gif_creator` recording for flows worth sharing, and an explicit note for
anything blocked. Never a paraphrase, never "passed" for something that couldn't be run.

## Packaging note

This skill ships in the `spryker-ai-dev-sdk` plugin under `vendor/spryker-sdk/ai-dev/…`, which is
Composer-managed — `composer update spryker-sdk/ai-dev` may overwrite it. The durable home for edits
is the plugin's own repository (`github.com/spryker-sdk/ai-dev`).
