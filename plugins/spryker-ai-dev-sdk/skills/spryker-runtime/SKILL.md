---
name: spryker-runtime
description: Use to actually RUN the Spryker application and observe real behavior, and to perform manual testing / QA against a real authenticated session — log in to Yves storefront, the Back Office, Merchant Portal, drive the UI in Chrome, run `docker/sdk cli console` commands, and call Yves/Glue endpoints over HTTP. Trigger whenever you need to manually test or QA a feature, walk through a user flow as a specific actor, confirm how an existing flow behaves before writing a PRD/spec/plan, reproduce a bug, verify a fix end-to-end, inspect what an endpoint returns, check which actors/roles exist at /user, or "run the app", "manually test this", "do a QA pass", "log in to backoffice", "call this endpoint", "execute this console command". Reusable building block for the PRD and verification skills.
allowed-tools: Bash, mcp__claude-in-chrome__*
---

# Spryker Local Runtime — Run, Log In, Operate

This skill executes against the **local Docker environment**. Three operating modes: **console (CLI)**, **HTTP/API**, and **browser (Chrome)**. Pick the lightest mode that answers the question — only drive Chrome when you genuinely need rendered UI or a real login session.

## Manual testing / QA

Beyond ad-hoc inspection, use this skill to **manually test and QA a feature against the running app** — walking a flow end-to-end as the right actor and recording what actually happened. A QA pass here is:

1. **Pick the actor** the scenario targets and log in as them (Back Office user / Customer / Agent / Merchant user / Merchant Agent — see the login reference below). Test each actor the feature touches, not just admin.
2. **Walk the flow step by step** — drive the UI (Mode 3) for rendered/JS behavior, or replay the requests fast with browser-seeded curl (Mode 4) for multi-step/CSRF flows. Cover the **happy path and the obvious negative paths** (missing input, wrong permissions, empty state).
3. **Observe concrete evidence** at each step: HTTP status, response/key fields, the rendered result, console errors (`read_console_messages`), and the real endpoint hit (`read_network_requests`).
4. **Report pass/fail per step** with the evidence — what you did, what you expected, what actually happened, and the URL/endpoint. For a fix being verified, confirm the bug no longer reproduces. Capture a `gif_creator` recording for flows worth sharing.
5. **Note anything blocked** (missing credentials, feature not installed, env not booted) instead of marking it passed — and ask the user when a real account/password is needed (see the DB-fallback rule below).

Keep findings factual and reproducible: someone should be able to repeat your steps from the report. This makes the skill the manual-test/QA stage that complements automated tests (Codeception/Cypress live in their own skills).

## Environments

**Hosts are NOT hard-coded truth — resolve them from `deploy.dev.yml` first.** The endpoint hostnames live under `groups.<region>.applications.<app>.endpoints` in `deploy.dev.yml`. Read that file at the start of a runtime session and use the hosts it declares; the defaults below are only the typical values for the EU group and may differ on this install.

Also: **if the user names a specific endpoint to use — e.g. a real cloud URL like `https://<env>.cloud.spryker.com` or a staging host — use that instead of the local one.** A user-provided target always wins over the local default.

Each surface maps to a `deploy.dev.yml` **application** — resolve the host from that application's `endpoints` key, never hard-code it. The notation `<host:yves>` below means "the endpoint host of the `yves` application in `deploy.dev.yml`"; substitute the real value you resolved before issuing any request.

| Surface | `deploy.dev.yml` application | Host token | Login URL | Credentials |
|---------|------------------------------|------------|-----------|-------------|
| Yves storefront | `yves` | `<host:yves>` | `/DE/en/login` | `sonia@acme.com` / `change123` |
| Back Office (Zed) | `backoffice` | `<host:backoffice>` | `/security-gui/login` | `admin@spryker.com` / `change123` |
| Glue REST API | `glue` | `<host:glue>` | token via `/access-tokens` | customer credentials as above |
| Glue Storefront | `glue-storefront` | `<host:glue-storefront>` | token | as above |
| Glue Backend | `glue-backend` | `<host:glue-backend>` | token | Back Office user |
| Backend API (Zed) | `zed` (entry-point `BackendApi`) | `<host:backend-api>` | — | Back Office user |
| Merchant Portal | `merchant-portal` | `<host:merchant-portal>` | Merchant Portal login | merchant user |

**Resolve the hosts at the start of the session** and reuse the values for every request. Each `endpoints:` block under an application lists its host(s); pick the one whose `region` matches your target store:
```bash
# Dump every application + its endpoint host(s) from the deploy file
grep -nE 'application:|endpoints:|region:|entry-point:|\.spryker\.local|\.cloud\.' deploy.dev.yml

# Or resolve the host for one application — picks the endpoint that carries `region:`
# (the real store host), so it skips secondary endpoints like configurator entry-points.
# Set APP to the deploy.dev.yml application name: yves | backoffice | glue | glue-storefront | glue-backend | merchant-portal | zed
APP=yves; awk -v app="$APP" '
  $0 ~ ("^[[:space:]]+application: " app "$"){f=1; next}
  f && /^[[:space:]]+application:/ {exit}
  f && /^[[:space:]]+[a-z0-9.-]+:[[:space:]]*$/ && !/endpoints:|services:|http:|session:/ {cand=$1; gsub(/[[:space:]:]/,"",cand)}
  f && cand && /region:/ {print cand; exit}
' deploy.dev.yml
```
The hosts may be `*.spryker.local` on this install or anything else on another — always use what the file reports, not the literal values shown in the examples below.

## Login per role — full reference

All demo accounts share the **same default password: `change123`**. Each actor has its own login URL and entry point — using the wrong one fails silently. Combine the login path with the **host resolved from `deploy.dev.yml`** (or the user-specified target).

Each row is `http://<resolved-host>/<path>` — substitute the host token (resolved from `deploy.dev.yml` per the table above) for the actual hostname.

| Actor | Login URL (`http://<host>` + path) | Demo account | Lands on |
|-------|------------------------------------|--------------|----------|
| **Back Office user (admin)** | `<host:backoffice>/security-gui/login` | `admin@spryker.com` | Back Office dashboard |
| **Customer** | `<host:yves>/DE/en/login` | `sonia@acme.com` | `/DE/en/customer/overview` |
| **Agent** (customer assist) | `<host:yves>/agent/login` | `agent123@spryker.com` | Agent dashboard; can then impersonate a customer |
| **Merchant user** | `<host:merchant-portal>/security-merchant-portal-gui/login` | `harald@spryker.com` | Merchant Portal — **one** merchant only (`harald` → "Spryker") |
| **Merchant Agent** | `<host:merchant-portal>/security-merchant-portal-gui/login` | (merchant-agent account — see DB lookup below) | Merchant Portal — **across multiple** merchants |

> The password is the **same `change123`** for every account above.
>
> **Merchant user vs Merchant Agent** share the same login URL; the difference is **scope**. A Merchant user is bound to exactly one merchant via `spy_merchant_user.fk_merchant` (e.g. `harald@spryker.com` → "Spryker", `michele@sony-experts.com` → "Sony Experts") and sees only that seller's data. A Merchant Agent acts across many merchants. Pick the account by the scope the task needs.

### When the default credentials don't work

Demo data differs across installs and the default password may have been changed. If a login fails, **don't guess** — find the real account in the database, and if you still can't authenticate, **ask the user** for credentials.

Find real accounts (read-only):
```bash
# Back Office users
docker/sdk cli "mariadb -h database -u spryker -psecret -D eu-docker -e \
  'SELECT username, first_name, last_name, is_active FROM spy_user LIMIT 20;'"

# Customers (storefront / Glue)
docker/sdk cli "mariadb -h database -u spryker -psecret -D eu-docker -e \
  'SELECT email, first_name, last_name FROM spy_customer LIMIT 20;'"

# Merchant users (Merchant Portal)
docker/sdk cli "mariadb -h database -u spryker -psecret -D eu-docker -e \
  'SELECT mu.username, m.name AS merchant FROM spy_merchant_user mu \
   JOIN spy_merchant m ON m.id_merchant = mu.fk_merchant LIMIT 20;'"
```
Passwords are hashed in the DB and cannot be read back. Use a known account with the default password; if none works, **ask the user which account and password to use** before proceeding.

To see which roles/users exist and their permissions on this install: log in to the Back Office and open `/user` (users) and `/acl` (roles).

## Mode 1 — Console (CLI)

Fastest way to inspect/operate without a browser. Run inside the CLI container:

```bash
docker/sdk cli console <command>                 # run a console command
docker/sdk cli console                           # list all commands (short)
docker/sdk cli console list                      # list ALL available commands — run this first to discover what's available for the task
docker/sdk cli console cache:empty-all           # clear caches
docker/sdk cli console cache:class-resolver:build # rebuild the class-resolver cache (after adding/moving classes, factories, plugins)
docker/sdk cli composer dump-autoload -o         # regenerate the optimized Composer autoloader (after new classes/namespaces not yet autoloaded)
docker/sdk cli console transfer:generate         # regenerate transfers
docker/sdk cli console propel:install            # apply DB schema
```

> **`console list`** prints the full command catalogue (grouped by namespace) — use it to find the exact command name for a task instead of guessing. **`cache:class-resolver:build`** and **`composer dump-autoload -o`** resolve "class not found" / "method not found on resolved class" errors after adding new classes, factories, or plugins: dump the autoloader first so PHP can find the file, then rebuild the class-resolver cache so Spryker's runtime resolution picks it up.

Database / Redis / queue inspection (read-only checks for "does X exist / how is it stored"):

```bash
docker/sdk cli "mariadb -h database -u spryker -psecret -D eu-docker -e 'SELECT 1 as test;'"
docker/sdk cli redis-cli -h key_value_store -n 1
docker exec spryker_broker_1 rabbitmqadmin -u spryker -p secret list queues
```

## Mode 2 — HTTP / API (with a real session)

Use this to call an endpoint and see exactly what it returns, without rendering UI.

**Storefront / Back Office (cookie session)** — first obtain a session cookie (log in via Mode 3 once, copy the cookie), then:

The session **cookie name is derived from the host**: take the resolved host and replace every `.` with `-` (e.g. host `yves.eu.spryker.local` → cookie `yves-eu-spryker-local`). Never hard-code the cookie name — derive it from the host you resolved from `deploy.dev.yml`.

```bash
# <host:yves> and its cookie name both come from deploy.dev.yml (cookie = host with dots → dashes)
curl -s -b '<cookie:yves>=<session_id>' \
  http://<host:yves>/DE/en/customer/overview
```
The Back Office cookie follows the same rule: `<cookie:backoffice>` is `<host:backoffice>` with dots replaced by dashes.

**Glue REST API (token)**:

```bash
# 1) get an access token (<host:glue> resolved from deploy.dev.yml)
curl -s -X POST http://<host:glue>/access-tokens \
  -H 'Content-Type: application/json' \
  -d '{"data":{"type":"access-tokens","attributes":{"username":"sonia@acme.com","password":"change123"}}}'

# 2) call a resource with the token
curl -s http://<host:glue>/<resource> \
  -H 'Authorization: Bearer <accessToken>'
```

Capture the **HTTP status, response shape, and key fields** — that's what downstream requirements/verification care about.

## Mode 3 — Browser (Chrome)

Use only when you need rendered UI, JS behavior, or to establish a login session.

**Before any Chrome tool, load it via ToolSearch** (these are deferred MCP tools):
`ToolSearch "select:mcp__claude-in-chrome__tabs_context_mcp"` (and similarly for `navigate`, `find`, `form_input`, `read_page`, `get_page_text`, `read_network_requests`, `gif_creator`, etc.), then call it.

Session startup:
1. `tabs_context_mcp` first — see existing tabs. **Do not reuse a tab unless the user asks**; create a fresh one with `tabs_create_mcp`.
2. `navigate` to the login URL for the target surface.
3. Fill the credentials and submit **in one atomic sequence** (see the tip below) — do not navigate between filling and submitting.
4. Verify success: Yves redirects to `/DE/en/customer/overview`; Back Office lands on the dashboard.

> **Chrome forms lose typed values on navigation — fill and submit atomically.** A common failure: setting field values, then `navigate`-ing elsewhere (or letting a batch navigate) before submitting — the page reloads and the form is blank, so you land back on the login page. Type the email + password **and** click submit in a single `browser_batch`, with no `navigate` in between; only navigate *after* you've confirmed login succeeded. Likewise, `form_input`/`find` set or locate values but **do not submit** — you still need an actual click (`computer left_click`) or Enter to submit. Verify with a screenshot or `get_page_text` that you're past the login page before continuing.

Login as a specific actor: use the exact login URL + demo account for that role from the **"Login per role" reference** above (each role has a different login path; the password is `change123` for all). If the default credentials fail, run the DB lookup there and, if still blocked, ask the user.

Reading state: prefer `get_page_text` / `read_page` for content, `read_network_requests` to see the actual XHR/endpoint a UI action hits (useful for resolving the real endpoint behind a button), and `read_console_messages` (with a `pattern` filter) for app logs.

**Get auth cookies** (to hand off to Mode 2): after login, read cookies from a network request. Format for reuse:
```
Cookie: <cookie:yves>=<session_id>; last-visit=<ts>
```
(`<cookie:yves>` = the resolved Yves host with dots replaced by dashes.)

## Mode 4 — Browser-seeded curl (hybrid) — FAST PATH for complex scenarios

When a scenario needs **many requests, repeated runs, or auth that's painful to reproduce by hand** (CSRF-protected Back Office forms, multi-step flows, anything behind a logged-in session), don't click through the UI every time. **Log in ONCE in the browser, harvest every header the request actually needs, then replay with `curl`.** This is far faster than driving Chrome per request and gives you exact, scriptable repro.

**Step 1 — Log in once (Mode 3).** Establish the session for the right actor.

**Step 2 — Perform the real action once in the browser, then read the actual request it sent.** This is the reliable way to learn *exactly* which headers/tokens the endpoint requires — guessing them leads to 302-redirects-to-login or 403s.

```
ToolSearch "select:mcp__claude-in-chrome__read_network_requests"
```
Call `read_network_requests` and inspect the matching request for:
- **Method + full URL** (including query string)
- **`Cookie`** header — the whole thing (session cookie + any others like `last-visit`)
- **CSRF / form token** — Back Office Zed forms post a `_token` field (or `X-CSRF-Token` header); Glue may need none beyond the bearer token
- **`Content-Type`** and the **request body** (form-encoded vs JSON)
- Any custom headers the app sets (`X-Requested-With: XMLHttpRequest` for AJAX endpoints, `Referer` for some POSTs)

> Tip: trigger the action via an actual button/form submit so the captured request carries the real, server-accepted token — tokens read out of the static HTML can be stale or scoped to a different form.

**Step 3 — Replay with curl using the harvested headers.**

GET (read an endpoint with the live session):
```bash
curl -s -i \
  -H 'Cookie: <cookie:backoffice>=<session_id>; last-visit=<ts>' \
  -H 'X-Requested-With: XMLHttpRequest' \
  'http://<host:backoffice>/<module>/<controller>/<action>?<query>'
```

POST a CSRF-protected Back Office form (token + cookie from Step 2):
```bash
curl -s -i -X POST \
  -H 'Cookie: <cookie:backoffice>=<session_id>' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode '<form>[field]=value' \
  --data-urlencode '<form>[_token]=<csrf_token>' \
  'http://<host:backoffice>/<module>/<controller>/<action>'
```

Glue (bearer token from `/access-tokens`, no cookie/CSRF):
```bash
curl -s -i http://<host:glue>/<resource> \
  -H 'Authorization: Bearer <accessToken>' \
  -H 'Content-Type: application/json'
```

**Step 4 — Iterate in the shell.** Now loop / vary inputs / run the whole scenario via curl. Re-harvest from the browser only if you get a `302` to login (session expired — Yves session ~30 min) or a `403`/CSRF error (token consumed/rotated — re-trigger the action in the browser and grab a fresh `_token`).

**Reuse the cookie across modes:** the `Cookie` header you harvest here is the same one Mode 2 uses — capture once, drive both. Save it to a shell variable for the session:
```bash
COOKIE='<cookie:backoffice>=<session_id>; last-visit=<ts>'
curl -s -H "Cookie: $COOKIE" 'http://<host:backoffice>/<...>'
```

**When NOT to use Mode 4:** if the behavior under test is client-side (JS rendering, in-page validation, redirects the browser follows automatically), curl won't exercise it — stay in Mode 3. Mode 4 tests the *server* response, not the rendered page.

> **Heads-up: the Chrome harness blocks reading `cookie`/`token` from page JS.** `document.cookie` and any DOM read whose key/value touches `token` come back as `[BLOCKED]`. So you often **cannot harvest a CSRF token or session cookie out of the page** to hand to `curl`. When Mode 4 is blocked this way, fall back to **Mode 5** — drive the request from inside the page context, where the browser attaches the cookie and the page already holds the token, without you ever reading the secret.

## Mode 5 — Page-context fetch (when curl can't get the credentials)

Use this when you need to hit a **CSRF-protected / session-bound endpoint** but the harness won't let you read the cookie/token for Mode 4, **or** when a UI control that should call the endpoint is broken (see "Inert Zed JS controller") and you want to verify the *server* independently of the dead button. You run the page's own `fetch` via `javascript_tool`, reusing the live session implicitly.

```
ToolSearch "select:mcp__claude-in-chrome__javascript_tool"
```
```js
// Runs in the logged-in page; cookie is attached automatically, token is read from the page's own config — never printed.
(async () => {
  const cfg = window.SomeFeatureConfig;            // the inline config the panel renders (endpoint + csrfToken + context)
  const t0 = performance.now();
  const r = await fetch(cfg.endpoint, {
    method: 'POST', redirect: 'manual',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({ _token: cfg.csrfToken, /* …real payload… */ }),
  });
  const text = await r.text();
  return { status: r.status, ms: Math.round(performance.now() - t0), body: text.slice(0, 1500) };
})()
```
This gives you the **real HTTP status + response body** (e.g. 200 with the structured JSON, 403 `disabled`, 422 validation) for negative cases (missing/invalid `_token` → 403), toggle-off (→ 403, and confirm **0** downstream side effects via a storage/log check), and entity-context correctness — all without touching the secret.

> **Mode 5 proves the *server*, NOT the *user flow*.** A 200 from a page-context fetch is **not** evidence that clicking the button works — the JS that builds the payload, fires the request, and applies the response is exactly the part you bypassed. When you use Mode 5 because a control is broken, say so explicitly and keep the UI-flow case marked FAIL/BLOCKED. Only let a synthesized request stand in for the real one when the *client* path is independently confirmed working (or is genuinely not what's under test).

### Instrument the network at the browser layer BEFORE the action

For "did clicking X actually fire a request?", don't rely on a `window.fetch` monkey-patch alone — a bundle that captured its own `fetch`/`XHR` reference will slip past it, giving a false "no request". Instead:
- Call **`read_network_requests` with `clear:true` immediately *before*** the click (tracking starts when first called, so prime it first), perform the action, then read again filtered by the endpoint path. This captures the request at the browser layer regardless of how the page issued it.
- **Corroborate with the server side** (an interaction/audit log row, a DB change, `data/logs/*`). The combination "no browser-layer request **and** no new server-side row" is conclusive that the click did nothing.

### Chrome safety rules
- **Never trigger `alert`/`confirm`/`prompt` or modal dialogs** — they freeze the extension. Avoid clicking destructive buttons that confirm.
- If a tool errors or the extension is unresponsive after 2–3 tries, **stop and ask the user** — don't loop.
- Record multi-step flows with `gif_creator` when the user may want to review/share; capture a few frames before and after each action.

### ⚠️ ALWAYS hard-reload / clear cache after rebuilding assets

**After ANY frontend asset compilation — `docker/sdk cli npm run zed`, `npm run yves`, `yves:watch`, or any build that regenerates JS/CSS bundles — you MUST hard-reload the browser (cache-bypassing) before testing the UI. Always do this; do not skip it.**

Why this bites silently: Spryker serves bundles at a **stable URL** (e.g. `/assets/js/<bundle>.js?v=current` — the `?v=current` does **not** change across builds). So a normal reload keeps serving the **old cached bundle** from the browser's HTTP disk cache, and your JS/CSS change appears to "not take effect" even though the server has the new code. This is the #1 cause of "I rebuilt but nothing changed" — it's stale cache, not a broken build.

What to do, every time after a rebuild:
- **Hard reload bypassing cache** — `Cmd+Shift+R` (Mac) / `Ctrl+Shift+R`. Via the Chrome MCP, send the `computer` `key` action `cmd+shift+r` (Mac) on the tab, or re-`navigate` the tab which also refetches.
- **Verify the server actually has the new code** (rules out "build didn't run"): fetch the real bundle URL with cache disabled and grep for a new symbol you just added, e.g.
  ```bash
  curl -s 'http://<host:backoffice>/assets/js/spryker-zed-<name>.js?v=current' | grep -c '<new-symbol-you-added>'
  ```
  If the server has it but the page doesn't show it → cache; hard-reload. If the server doesn't have it → the build didn't run / wrong entry; rebuild.
- This is **HTTP disk cache**, not service workers / CacheStorage (those are empty here) — a hard reload is sufficient; no need to clear site data.

When QA-testing a frontend change, treat "rebuild assets → hard reload → then test" as a single inseparable sequence. Skipping the reload makes you test the old bundle and report a false negative.

## Choosing a mode

- "Does this console command / data exist?" → **Mode 1**.
- "What does this endpoint return / what status?" → **Mode 2**.
- "How does the UI actually behave / what endpoint does this button call / log me in as actor X" → **Mode 3** (then often hand the cookie to Mode 2).
- "Complex/multi-step or repeated scenario behind a login or CSRF form, and I want it fast & scriptable" → **Mode 4** — log in once in Chrome, harvest the real headers/cookie/CSRF token, replay with `curl`.
- "CSRF/session endpoint but the harness won't let me read the cookie/token, or the button that calls it is broken and I need to prove the server" → **Mode 5** — page-context `fetch` (and remember: it proves the server, not the user flow).

Report what you observed concretely: command output, HTTP status + key response fields, the resolved endpoint path, or the rendered behavior — not a paraphrase.

## Troubleshooting — when the app misbehaves

When a page 500s, a change "doesn't take effect", navigation/menu looks stale, a route 404s, or behaviour seems cached, the cause is usually **stale generated code or cache**, not the feature itself. Clear the relevant cache, re-test, *then* conclude. These are the project's commands (run via `docker/sdk cli`):

| Symptom | Command |
| --- | --- |
| General "my change isn't showing" / stale app cache | `docker/sdk cli console cache:empty-all` |
| Twig template change / new `.twig` not picked up, or template-not-found | `docker/sdk cli console twig:cache:warmer` |
| Back Office menu / navigation looks wrong or stale | `docker/sdk cli console navigation:cache:remove` |
| Route 404 / new controller-action URL not resolving (Zed) | `docker/sdk cli console router:cache:warm-up` (regenerate Zed router cache) |
| Route 404 specifically in Back Office / Merchant Portal / Backend Gateway (each has its OWN router cache — the generic warm-up does not cover them) | `docker/sdk cli console router:cache:warm-up:backoffice` / `router:cache:warm-up:merchant-portal` / `router:cache:warm-up:backend-gateway` |
| Diagnose a 404: confirm whether a route is actually registered / which one matches a path | `docker/sdk cli console router:match <path>`, or `debug:router` / `router:debug:backoffice` / `router:debug:backend-gateway` to dump registered routes |
| Newly added class/namespace "class not found" (file exists but not autoloaded) | `docker/sdk cli composer dump-autoload -o` |
| New factory/plugin not picked up, or "method not found" on a resolved class | `docker/sdk cli console cache:class-resolver:build` (after `dump-autoload -o`) |
| New/changed transfer not available (missing getter, `Generated\…` error) | `docker/sdk cli console transfer:generate` |
| DB schema change / Propel entity not reflecting a column | `docker/sdk cli console propel:install` |
| Frontend JS/CSS change not visible | rebuild assets (`npm run zed`/`yves`) **then hard-reload the browser** — see the asset-cache warning in Mode 3 |
| A Zed panel/widget renders but its buttons do nothing (no JS error, no request) | the JS controller was likely **never constructed** — see the inert-controller note below |
| Published data missing from storefront (Redis/Elasticsearch) | ensure the P&S worker runs: `docker/sdk cli console queue:worker:start` (and/or `publish:trigger-events`) |

### ⚠️ Inert Zed JS controller — buttons render but do nothing

A common, **silent** Spryker Zed-JS failure: the panel/widget HTML renders correctly, the bundle loads (200), `window.*Config` data is present, there are **no console errors** — yet clicking a button fires **no request and shows no state change**. The cause is usually that the **controller class was never instantiated**, so `#init()` never ran and no event listeners were bound. Typical trigger: the entry constructs the controller inside `document.addEventListener('DOMContentLoaded', …)`, but the bundle executes **after** `DOMContentLoaded` has already fired in that runtime → the callback never runs.

> ⚠️ **This `DOMContentLoaded` bootstrap is a RACE, not a deterministic failure.** Whether the listener fires depends on load order, which can differ between a fresh load, a re-navigation, a hard reload, and a slow module resolution. So the SAME page can be inert on one load and fully working on the next. **A single dead load proves nothing on its own** — it is the most-faked "blocker" in Spryker QA. Treat "buttons do nothing" as *unconfirmed* until you have reproduced it across reloads (see step 0).

**Do not conclude "the backend is broken" — OR even "the button is broken" — from a dead button on ONE load.** Diagnose at the client, reload-first:
0. **RELOAD AND RE-TEST FIRST — at least 3 fresh loads** (hard reload `cmd+shift+r`, and at least one full `navigate` away-and-back, since they exercise different load orders). Drive the *real control* each time and watch `read_network_requests` (primed with `clear:true` immediately before the click). If it works on any load, it is **NOT a hard blocker** — it is an **intermittent bootstrap race** (report as a *minor/flaky* finding with the `readyState`-guard fix, not a blocker). Only if it is dead on **every** reload do you have a consistent failure worth deeper diagnosis. Do not skip this step because the first load looked broken — that is exactly how an intermittent race gets mis-reported as a blocker.
1. **Before claiming the controller never constructed, prove it positively:** check that a control sharing the same `#init()` binding (e.g. a collapse/expand toggle) is *also* dead. If the toggle WORKS, the controller DID initialize on this load → the Ask button issue is a per-handler bug (early-return guard, wrong selector, disabled state) or your click method, NOT an uninitialized controller — go re-examine how you drove the button. If the toggle is also dead AND it stays dead across reloads (step 0), then the controller genuinely wasn't constructed.
2. **Re-check your own click method before blaming the code.** `form_input` sets `.value` without firing the `input`/`change` events some handlers gate on; an MCP `.click()` may land before the bundle finished initializing. Drive a *genuine* user sequence (real `computer left_click` on the located ref, after confirming the control is enabled and the panel's controller is live per step 1), and prime the network monitor right before the click. A "no request" result is only meaningful once you've ruled out your own input path.
3. **Confirm a handler is actually bound** in the page context (wrap `window.fetch`/`XMLHttpRequest`, click, check it's hit) — but treat the **server-side log as ground truth**, since a `window.fetch` monkey-patch can miss a request the bundle made via its own captured reference. "No new server-side row after a *real* click, across multiple reloads" is the conclusive proof.
4. Once isolated to the client AND confirmed reproducible, you can **verify the server endpoint independently** via Mode 5 — but Mode 5 is a *workaround to prove the server*, NEVER a substitute for driving the real button. A green Mode 5 fetch must not let you stop investigating the client: keep pushing the real UI click to completion. Report clearly which layer you actually proved.

Sequence to recover from a confusing state: **`cache:empty-all`** first (cheapest, fixes most), then the targeted one for the symptom (twig / navigation / router), then re-run the test. If a code change still isn't reflected after `cache:empty-all`, suspect the **generated artifact** — regenerate transfers (`transfer:generate`) or, for Zed Twig overrides, the template path cache may need clearing beyond `cache:empty-all` (`rm -f src/Generated/Zed/Twig/codeBucket/.pathCache` then `twig:cache:warmer`).

**Verify the command names on this install** if any is rejected — list them with `docker/sdk cli console` (no args). Command names can differ slightly across Spryker versions; don't keep retrying a name that errors — list and match.

Other quick probes (read-only) for diagnosing during a test:
- App logs: `read_console_messages` (Chrome) for client-side; container logs / `data/logs/*` for server-side (see the `ai-runtime-debugging` skill for deeper runtime inspection).
- Network: `read_network_requests` to confirm which endpoint a UI action actually hit and its status.
- Storage: `redis-cli`/`mariadb` (Mode 1) to confirm whether data was persisted vs. served stale.
