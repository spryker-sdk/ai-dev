---
name: spryker-profiler
description: Read and configure the Spryker/Symfony WebProfiler — everything it recorded about a request, across every collector. Performance numbers (SQL query counts and N+1 duplicates, Redis/key-value operations, Elasticsearch calls, Yves→Zed requests, external HTTP calls, duration, memory, Twig rendering, event listeners, session size) and diagnostics (application logs, audit logs, thrown exceptions, controller and route, redirects, PHP/Xdebug runtime). Use this whenever someone asks why a page, endpoint, or Back Office screen is slow, wants the slowest or heaviest request, asks "how many queries does this page run", suspects an N+1, wants Redis or storage call counts, wants before/after evidence that a performance fix worked, or asks what a request logged, what it threw, which controller handled it, or what the audit log recorded. Also for profiler setup — turning the web profiler on, a missing toolbar or profile data, enabling profiling for Zed, Back Office, Backend Gateway, Backend API, Glue, Merchant Portal or Yves, a collector showing no data, adding SQL/Redis/Elasticsearch collectors. Use it proactively before claiming any Spryker change improved or hurt performance — the profiler already recorded every request, so read real measurements instead of guessing.
---

# Reading Spryker profiler data

Spryker ships Symfony's WebProfiler with extra Spryker collectors, and it records
**every** request automatically. That history is the cheapest source of truth for
performance work: instead of guessing which code is slow, read what actually ran.

The bundled script reduces a stored profile to the numbers that matter and prints
JSON, so you never have to scrape the profiler's HTML or open a browser.

## Running it

Always run through `docker/sdk cli` (PHP and the collector classes only resolve inside
the container) and always **from the Spryker project root** — the script locates the
project from the working directory, not from its own path.

The container mounts only the project directory, so first set `SCRIPT` based on where
this skill actually lives. Resolve the base directory in this order — do **not** hardcode
an install path, since this skill ships as a plugin and its location varies:

1. `.claude/skills/spryker-profiler` (setup install, relative to the project cwd)
2. `${CLAUDE_PLUGIN_ROOT}/skills/spryker-profiler` (plugin install)
3. the "Base directory for this skill" line shown when the skill loaded, or failing that
   the directory containing this SKILL.md

Then pick the matching case below:

- **Base directory is inside the project** (e.g. `<project>/.claude/skills/spryker-profiler`):
  use the script in place.

  ```bash
  SCRIPT=.claude/skills/spryker-profiler/scripts/profiler-read.php
  ```

- **Base directory is outside the project** (installed as a plugin, e.g. under
  `~/.claude/plugins/cache/...`): PHP inside the container cannot open that path —
  running it directly fails with "Could not open input file". Copy the script into the
  project once and use the copy (it is disposable; recreate it any time):

  ```bash
  mkdir -p .claude/tmp
  # <base-directory> = whichever resolved above, e.g. ${CLAUDE_PLUGIN_ROOT}/skills/spryker-profiler
  cp "<base-directory>/scripts/profiler-read.php" .claude/tmp/
  SCRIPT=.claude/tmp/profiler-read.php
  ```

Everything else is identical in both cases:

```bash
docker/sdk cli php $SCRIPT --list --limit=10
docker/sdk cli php $SCRIPT --url=/en/cart --verbose
docker/sdk cli php $SCRIPT --token=85a44b
docker/sdk cli php $SCRIPT --worst=queries --scan=200 --limit=10
```

The SDK prints a `--> DEVELOPMENT MODE` banner before the JSON. Strip it when piping
into a parser:

```bash
docker/sdk cli php $SCRIPT --token=85a44b 2>&1 | sed -n '/^{/,$p'
```

Errors also arrive as JSON on stdout (`{"error": "..."}`), so they survive that filter —
an empty result after the `sed` means the command itself failed before PHP ran.

### Modes

| Mode | Use it for |
|---|---|
| `--list` | See what requests were recorded, newest first |
| `--url=<substring>` | Metrics for the most recent request matching a URL |
| `--token=<token>` | Metrics for one specific profile |
| `--worst=<metric>` | Rank recent profiles to find the outlier |
| `--trace=<token>` | **The full picture for one page load** — entry request + its Zed calls + related AJAX |
| `--help` | Usage: every mode, option and rankable metric |
| `--project-root=<path>` | Project root holding `vendor/autoload.php`, when the cwd is not inside it |

Unrecognised flags are rejected with the valid list — a typo can never silently fall
through to "describe the newest profile" and hand you the wrong request's numbers.

Options: `--limit` (results), `--scan` (profiles to examine in `--worst`, default 100),
`--verbose` (adds the top repeated SQL statements), `--dir` (override the profiler
directory), `--window=<seconds>` (sibling grouping window for `--trace`, default 10),
`--no-siblings` (restrict `--trace` to the proven Zed chain only).

**`--url` matches by substring, newest first.** `--url=/sales` happily returns
`/sales/index/table` if that was requested more recently — the wrong request, silently.
When routes share a prefix, run `--list` first and pick the exact row's `--token`.
Rows flagged `"expired": true` in `--list` are pruned from disk and can no longer be
read — reproduce the request instead of picking their token.

Rankable metrics: `queries`, `duplicate_queries`, `redis`, `elasticsearch`,
`zed_requests`, `external_http`, `memory_mb`, `duration_ms`.

## One user action is many profiles — always establish the tree first

**This is the single most common way to reach a wrong conclusion.** A profile is one
*HTTP request*, not one *user action*. A single page load or button click routinely
produces several profiles across two or more applications:

```
User clicks "Login"                      ← one action
└── POST /login_check          (Yves)    ← the entry profile: 0 queries, "collector absent"
    ├── Zed call → customer/gateway/get-customer-for-authentication   45 queries
    ├── Zed call → multi-factor-auth/gateway/validate-...              1 query
    ├── Zed call → persistent-cart/gateway/sync-storage-quote        147 queries ← the real cost
    └── Zed call → customer/gateway/customer                          45 queries
    (separate top-level profiles, same page load)
    ├── GET /newsletter/subscribe   ← ESI/widget sub-request
    └── GET /...                    ← AJAX fired by the browser
```

Read only the Yves profile and you report **0 queries**. The action actually ran **238**.

Three separate mechanisms produce these profiles, and they link differently:

| Relationship | How it is linked | Recoverable? |
|---|---|---|
| **Yves/Glue → Zed** (server-side, blocking) | The callee returns `X-Debug-Token`; the caller stores it in the `zed_request` log | **Yes — exactly.** `--trace` follows it |
| **ESI / widget sub-requests** | Separate top-level profiles, no parent link stored | Heuristic: same time window |
| **Browser AJAX** (`/cart/quantity`, widgets, JSON) | Issued by the browser, so PHP records no relation at all | Heuristic: same time window |

**Parent and children live in different directories.** Yves **and Glue** write to
`data/cache/codeBucket/profiler`; Zed/Backend Gateway writes to `data/tmp/profiler`.
`--trace` searches both automatically — this is why reading a child by hand needs the
right `--dir` and why the linked profiles carry a `source` field.

### Use `--trace` whenever the question is about a page or an action

```bash
docker/sdk cli php $SCRIPT --trace=c7cac1              # entry + Zed calls + likely-related requests
docker/sdk cli php $SCRIPT --trace=c7cac1 --no-siblings  # only the proven Zed chain
```

It returns `entry_request`, `zed_calls[]`, `related_requests[]`, and `totals` for the
whole tree. **Every profile in the tree carries its own `token` and `profiler_url`** —
the entry request and each Zed call — so link them all when you report. Zed-call URLs
come from the callee's `X-Debug-Token-Link` header, which is why they resolve on the
Backend Gateway host rather than the caller's.

Interpreting it honestly:

- **`zed_calls` are proven.** The token came from the actual response header — this is a
  real causal link, safe to state as fact.
- **`related_requests` are a heuristic.** Time-window grouping cannot distinguish a
  page's own AJAX from unrelated concurrent traffic, and `likely_ajax` is a URL guess.
  Say "recorded within Ns of the entry request", not "this page fired these calls",
  unless the URL makes it obvious. Confirm by reproducing the action in isolation.
- **`summed_duration_ms` double-counts.** Zed time is nested inside the Yves request's
  own duration. Use it to compare branches, never as wall-clock for the action.
- **A missing link is informative.** `"not linked"` means the callee returned no debug
  token — usually the profiler is off for that application (see `references/setup.md`).
  `"expired"` means the child was pruned; reproduce the action to get a fresh tree.

Where a slow action's cost actually lives, in rough order of likelihood: a single heavy
Zed gateway call, many chatty Zed calls that should be one, or an AJAX endpoint the page
fires per item. The entry profile alone shows none of these.

## Reading the output

```json
{
  "source": "/data/data/cache/codeBucket/profiler",
  "newest_profile": "35s ago",
  "url": "http://yves.eu.spryker.local/en",
  "profiler_url": "http://yves.eu.spryker.local/_profiler/85a44b",
  "duration_ms": 302.8,
  "memory_mb": 36,
  "database": {"queries": 0, "unique": 0, "duplicates": 0, "collector": "absent"},
  "redis": {"calls": 5},
  "zed_requests": {"calls": 0},
  "logs": {"errors": 2, "total": 14},
  "audit_log": {"entries": 1, "channels": ["security"]},
  "exception": {"thrown": true, "message": "…", "status_code": 500},
  "twig": {"templates": 39, "render_ms": 13.4},
  "events": {"called_listeners": 53},
  "http": {"controller": "…::indexAction", "route": "home"},
  "session": {"attributes": 15, "bytes": 100978},
  "runtime": {"debug": true}
}
```

**Diagnostic blocks appear only when they carry signal.** `logs`, `audit_log`,
`exception`, `session`, and `runtime` are omitted entirely when there is nothing to
report — no `exception` key means nothing was thrown, no `runtime` means neither debug
mode nor Xdebug was on, and zero counts inside `logs`/`audit_log` are dropped rather
than printed. An absent diagnostic key is good news, not missing data. (`http` also
skips `method`/`path` — the envelope above already shows them.)

### Beyond the I/O counters

The I/O metrics answer "how much work", the rest answer "why" and "is this
comparable". Reach for them when the counters alone do not explain the number:

| Field | Reads | Use it when |
|---|---|---|
| `logs` | application log | A request is slow or wrong — errors and warnings often name the cause outright. `deprecations` surfaces upgrade debt nothing else reports. |
| `audit_log` | Spryker audit log | Security/compliance events (logins, permission changes). A separate stream from `logs`, grouped by channel. |
| `exception` | thrown exception | Explaining a 4xx/5xx. `thrown: true` carries the message and status code. |
| `twig` | template rendering | Counts are fine but the page is slow — hundreds of templates points at the view layer, not storage. |
| `events` | listeners + Spryker application events | Listeners run synchronously inside the request; a large `called_listeners` is unattributed cost. |
| `http` | request + router | Tying a profile back to code: which controller and route ran. `redirect_to` explains a 3xx. |
| `session` | session payload | A large session is re-written every request — cost that no query or storage counter shows. |
| `runtime` | PHP config | **Check before comparing any timings.** `xdebug: true` inflates wall-clock several-fold; `debug: true` disables caches. Only true flags print — no `runtime` block means both were off. |

`--verbose` adds the top repeated log and audit messages, ranked by frequency.

Three fields protect you from the most common ways this analysis goes wrong:

**`collector: "absent"` is not zero.** It means that collector did not run for this
application, so the metric is unknown. Yves has no `propel` collector, and Back Office
has no `redis`/`zed_request` collectors. Reporting "0 queries, great!" from an absent
collector is a false conclusion — say "not measured on this application" instead, and
if you need that number, profile the application that actually does the work.

Only the I/O metrics report themselves when absent, because a missing one is itself the
finding. The rest are simply omitted when the application never registered them, so an
absent key means "not measured here", never "measured as zero".

**`collector: "incompatible"` is a different problem.** The collector *is* recording, but
its API is not the one this reader expects — almost always a Spryker or Symfony upgrade
that renamed a method. It reports the offending class so you can fix the reader. Never
read it as an absent collector: the data exists, the script just cannot see it. Open the
`profiler_url` to read that panel in the browser meanwhile.

**`external_http` is opt-in and easily misread.** Unlike SQL and Redis, external calls
are only recorded when the calling code uses `ExternalHttpInMemoryLoggerTrait`. A Guzzle
client that does not is invisible, so `external_http: 0` means "no *instrumented* calls",
never "no calls". Before concluding a slow request makes none, check whether the client
instruments itself — see `references/setup.md`.

**`newest_profile` guards against stale data.** If the newest profile is hours old and
you just reproduced something, the request was not recorded and you are reading history.
Check this before drawing conclusions.

**`expired_or_missing` guards against a hollow ranking.** Symfony deletes stored
profiles after two days but never trims `index.csv`, so the index routinely lists
thousands of requests when only a few dozen files survive. `--worst` reports how many
it could actually analyse; if that number is small, you are ranking a thin recent slice,
not "everything recorded". Reproduce the requests you care about and re-run.

### Each application writes to its own directory

This is the single most common way to waste time here.

| Application | Directory |
|---|---|
| Yves, **Glue (storefront + backend)** | `data/cache/codeBucket/profiler` |
| Zed, Back Office, Backend Gateway, Merchant Portal | `data/tmp/profiler` |

**Glue lives with Yves, not with Zed** — `Spryker\Glue\WebProfiler\WebProfilerConfig`
defaults to the codeBucket path. Pointing a Glue investigation at `data/tmp/profiler` either
errors ("no profile matched") or, worse, silently ranks Back Office requests and reports them
as the Glue endpoint's cost.

The script auto-picks whichever directory was written most recently, which is often not the
one you want — a Back Office investigation can silently read Yves data and find nothing.

Check `source` in the output, and pass `--dir` when you know which application you are
investigating. Note `source` only echoes back the directory you asked for, so it confirms
rather than catches a wrong `--dir` — verify the `url` host too:

```bash
docker/sdk cli php $SCRIPT --dir=/data/data/tmp/profiler --worst=queries              # Zed / BO / BG / MP
docker/sdk cli php $SCRIPT --dir=/data/data/cache/codeBucket/profiler --url=/en       # Yves
docker/sdk cli php $SCRIPT --dir=/data/data/cache/codeBucket/profiler --url=glue      # Glue
```

Paths are container paths (`/data/...`) because the script runs inside the container.

## The workflow that works

1. **Reproduce the request first**, then read the profile. Hit the URL (curl or the
   browser), then immediately `--url=<path>` and confirm `age` is seconds, not hours.
2. **Trace before you conclude.** If the question is about a *page* or an *action* rather
   than one specific HTTP request, run `--trace=<token>` on the entry profile. On Yves and
   Glue the entry request is usually the cheap part; the cost sits in its Zed calls, and
   Yves reports no SQL at all. Skip this only when you are deliberately measuring one
   isolated endpoint.
3. **Start with counts, not time.** Wall-clock duration is noisy locally — cold caches,
   container warmup, and Xdebug all distort it. I/O counts are stable and are what
   actually scales badly in production.
4. **Compare against a baseline.** One number means little. The useful comparisons are
   the same URL before vs. after a change, or one URL against a similar one. A finding
   is only real when you can state both numbers.
5. **Escalate only if counts look fine.** If I/O counts are reasonable but the request
   is genuinely slow, the cost is in userland CPU and you need an actual profiler
   (Xdebug `profile` mode) — this script cannot see function-level cost.

### Pages behind a login record the redirect, not the page

Zed, Back Office, and Merchant Portal redirect unauthenticated requests to the login
form — and that 302 is itself profiled. Its handful of queries looks like a plausible
answer for the page and is completely wrong. Reproduce with an authenticated session
(browser, or curl with a cookie jar and the CSRF token):

```bash
JAR=$(mktemp)
CSRF=$(curl -s -c $JAR http://backoffice.eu.spryker.local/security-gui/login \
  | grep -o 'name="auth\[_token\]" value="[^"]*"' | sed 's/.*value="//;s/"$//')
curl -s -b $JAR -c $JAR -o /dev/null \
  -d "auth[username]=admin@spryker.com" -d "auth[password]=change123" -d "auth[_token]=$CSRF" \
  http://backoffice.eu.spryker.local/login_check
curl -s -b $JAR -o /dev/null http://backoffice.eu.spryker.local/sales
```

Then check `status` in the profile you read: a 302 where you expected 200 means you
profiled the redirect, not the page.

### Back Office list screens are two requests

BO tables render an empty grid shell and fetch their rows through a separate AJAX call
at `<route>/table` (e.g. `/sales` → `/sales/index/table`). The shell is usually cheap;
the table request holds the real cost — order grids, product lists, customer lists all
follow this pattern. Profile both and report them together, or a "fast" shell hides a
heavy grid.

### Finding a problem you cannot name

When someone reports "the shop feels slow" without a specific page, rank instead of
guess, then compare the winner against its neighbours:

```bash
docker/sdk cli php $SCRIPT --worst=queries --scan=300 --limit=10
docker/sdk cli php $SCRIPT --token=<worst> --verbose
```

A big number means nothing alone. What makes it a finding is the gap: if the suspect
runs 200 queries while every other screen in the same application runs under 25, that
contrast is the evidence. The ranking output gives you both for free.

### Attributing queries to a code path

A count says the request runs 261 queries; it does not say which part is responsible.
When several code paths could own the cost, wrap them in segments and re-run:

```php
use Spryker\Shared\Propel\Logger\PropelInMemoryLogger;

PropelInMemoryLogger::startSegment('order-validation');
try {
    $this->validateOrder($orderTransfer);
} finally {
    PropelInMemoryLogger::endSegment();
}
```

The reader reports these under `database.segments`, each with its own counts. Always
pair the calls in `try`/`finally` — the logger is static, so a segment left open
attributes every later query in the request to it. Treat segments as temporary
debugging scaffolding and remove them once the culprit is found.

`references/setup.md` covers the mechanics and the gotchas.

### Separating fixed waste from a real N+1

`duplicates` counts the same statement running repeatedly. That catches redundant
re-loads, but it systematically misses the more damaging pattern — per-item queries
that differ only by parameter, which are all "unique" and never register as duplicates.

So do not stop at the duplicate count. Profile the same page for a small entity and a
large one (a product with one variant and one with six, say) and compare:

- **Total grows with item count** — a real N+1. This is what degrades as the catalog
  grows, and it is the important finding.
- **Total stays flat while duplicates are high** — fixed redundant work. Cheaper to fix
  and still worth fixing, but it will not get worse with scale.

Both are usually present at once, and they need different fixes. Expressing the result
as a shape (`queries ≈ fixed + n × items`) tells whoever fixes it which half to attack.

## The script is a starting point, not a boundary

`profiler-read.php` surfaces the metrics that answer most questions, but a stored profile
holds far more than it prints — full SQL text with timestamps, request and response
headers, routing, events, Twig render data, logger entries, and every collector the
application registered. If the question needs something the script does not expose,
change the script. That is expected, not a workaround.

Reach for an edit when you find yourself wanting to:

- group or normalise SQL (strip literals so `IN (1)` and `IN (2)` collapse to one shape,
  which turns "22 similar queries" into a countable pattern)
- sort or filter on a field there is no flag for
- pull a collector the script ignores, or a field inside one it already reads
- correlate across profiles — the same URL over time, or a request against its
  sub-requests

How to do it without wasting the run:

- **Look at the data first.** `$profile->getCollector('<name>')` exposes the collector's
  own methods; check what is actually there before writing code against an assumed shape.
- **Prefer a throwaway script for one-off questions.** Copy the loading logic
  (`readProfileWithoutTree()`) into a scratch file rather than growing the shared tool
  with single-use flags.
- **Fold it back in when it generalises.** If an addition would help the next
  investigation too, add it properly — with the same care about distinguishing "absent"
  from "zero".
- **Do not hydrate the profile tree.** `FileProfilerStorage::read()` recursively loads
  parents and children and exhausts memory across many profiles; that is why the script
  decodes the stored payload directly.

Say what you changed and why when you report findings, so the numbers can be reproduced.

## Presenting results

Match the output to why you are profiling. Most of the time a short answer in the
conversation is the right call; a document is for when someone needs to read, keep, or
circulate the findings.

### Always give the user the live profiler links

**Non-negotiable, in every mode of presentation — inline answers included.** Any time you
state a profiling number, give the user the URL of the profile it came from. They cannot
verify, explore, or act on a number whose source they cannot open, and the rendered
profiler page always shows more than you reported.

The script hands you these — never hand-build them:

- every profile carries `profiler_url`
- each entry in `zed_calls` carries its own `profiler_url` (from the response header)
- deep-link a panel with `?panel=<collector>`: `propel`, `redis`, `elasticsearch`,
  `zed_request`, `external_http`, `time`, `memory`, `logger`, `audit_log`, `exception`,
  `twig`, `events`, `request`, `router`, `session`, `config`

Even a one-line answer includes the link:

> Cart page runs **147 queries**, 33 duplicated — http://yves.eu.spryker.local/_profiler/9d3251?panel=propel

**Write link text that says where it goes.** A bare token like `2f5356` tells the reader
nothing and hides the URL in plain-text views. Use `2f5356 → propel panel`, or the plain
URL. When a trace spans applications, link **every** profile in the tree, not just the
entry — the entry is usually the least interesting one.

**Default — answer inline.** When profiling is a step inside a larger task (checking a
change did no harm, confirming a suspicion, gathering a number you needed anyway), report
the handful of figures that matter and move on. A full report for a two-number answer
buries the point and wastes the reader's time — but it still carries its links.

**Write a report when it will actually be read.** Produce one when the user asks for a
report, doc, summary or "something I can share"; when the finding is significant enough
to deserve attention on its own (a page running hundreds of queries, a regression against
a previous measurement); or when the investigation covered enough ground that the
reasoning matters as much as the numbers.

Ask which format they want if it is not obvious, and default to Markdown when there is no
signal — it renders anywhere and diffs cleanly. Use standalone HTML when the user asks for
HTML, or when the result is visual enough (comparison tables, before/after) that styling
helps. Write it to `.claude/local-trash/` unless the user names a location.

### Link to the profiler, do not copy it

The rendered profiler page already presents every query, header and timing, far better
than a report can restate. Copying that content produces a document that is long, stale
the moment it is written, and impossible to verify.

So reference instead of reproduce. Every profile the script reports carries a
`profiler_url`; use it, and deep-link to the relevant panel with `?panel=<collector>`
(`propel`, `redis`, `elasticsearch`, `zed_request`, `external_http`, `time`, `memory`,
`logger`, `audit_log`, `exception`, `twig`, `events`, `request`, `router`, `session`,
`config`):

```markdown
Back Office product edit ran **261 queries** (44 duplicated) — [full SQL breakdown](http://backoffice.eu.spryker.local/_profiler/cf708c?panel=propel)
```

A good report contains the numbers, the comparison that makes them meaningful, the
conclusion, and links out for the raw detail. Quote at most a line or two of SQL when a
specific statement *is* the finding — never a dump of the panel.

**Open every report with a "Profiles this report is based on" table** — one row per
profile, with token, request, application, headline metric, and links to the relevant
panel and the overview. Sourcing scattered through the prose reads as unsourced. For a
traced action, that table *is* the request tree: list the entry request and every Zed
call beneath it, so the reader sees the cross-application shape before the analysis.
Add the per-application `/_profiler/latest` links so they can browse from there.

Two caveats worth stating in any report: profiler links only work while the profile is on
disk (Symfony prunes after two days), and they resolve only on the machine running the
environment. If the report needs to outlive that, include the few numbers it depends on
inline as well.

## Interpreting findings

Report the metric, the number, and the rule it violates — the project's performance
rules live in `.claude/rules/performance.md` and are the reference for what "too many"
means. In short: per-item work inside a loop is the core anti-pattern, whether that is
a query, a storage read, or an HTTP call.

Be careful about what a single local profile can support. Cold-start effects, an empty
cache, and a dev container all inflate numbers, so distinguish "this request did 239
queries" (a fact from the data) from "this page is broken in production" (a claim
needing more evidence). Say which one you are making.

## When there is no data, or a metric is missing

Three layers must all be in place for a number to appear, and they fail differently:

1. **`IS_WEB_PROFILER_ENABLED`** — off by default, and set in the gitignored
   `config/Shared/config_local.php`, so it is routinely missing on a fresh checkout or a
   colleague's machine. Symptom: no profiles at all.
2. **The application plugin** — registered per application, and Zed alone has four
   separate stacks (Zed, Back Office, Backend Gateway, Backend API). Symptom: one
   application records nothing while others work.
3. **The data collector** — one plugin per metric. Symptom: profiles exist but a metric
   reports `collector: "absent"`.

**Read `references/setup.md` for the full picture**: how to enable each layer, which
applications this project currently profiles and where each writes, the `class_exists()`
guard that keeps `require-dev` plugins out of production, and the cache clear that must
follow any change. It also links the official integration docs per application — fetch
those when the installed module version disagrees with the reference.

Console commands, queue workers, and cron jobs are never profiled — the WebProfiler is
request-scoped. Use Xdebug profiling or explicit timing for those.
