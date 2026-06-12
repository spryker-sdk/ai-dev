# Temporary debug logging (Part 1)

This file is the detail reference for the **logging** technique described in `SKILL.md`. Load this when you need to add `[AI-DEBUG]`-tagged log lines to inspect Spryker runtime state.

## The two log destinations (CRITICAL)

Spryker runs across multiple Docker containers and **the log destination depends on which container is executing the code**:

| Code runs in | Log destination | How you read it back |
|---|---|---|
| `docker/sdk cli` (console commands, scripts) | File on disk: `/data/data/logs/<APP>/application.log` inside container, mapped to host at `<project-root>/data/logs/<APP>/application.log` | `tail`/`grep` the host file |
| `spryker_*_yves_*`, `spryker_*_glue_*`, `spryker_*_boffice_*`, `spryker_*_backgw_*` (FPM containers serving HTTP requests) | `php://stderr` → captured by Docker | `docker logs <container_name>` |

The FPM containers have `SPRYKER_LOG_STDERR=php://stderr` in their env, so `LoggerTrait` output goes there. The cli container has `SPRYKER_LOG_DIRECTORY=/var/log/spryker` and Spryker's `config_logs-files.php` also points file logs at `/data/data/logs/<APP>/application.log`.

If you wrote a log line and don't see it on disk, **check `docker logs`** — that's where it is.

Container names vary per project (compose project prefix differs). Always discover the real names:

```bash
docker ps --format '{{.Names}}' | grep -E 'yves|glue|boffice|backgw'
```

## LoggerTrait — the preferred approach

`\Spryker\Shared\Log\LoggerTrait` (file: `vendor/spryker/log/src/Spryker/Shared/Log/LoggerTrait.php`) is the canonical way to log. It exposes one method:

```php
protected function getLogger(?LoggerConfigInterface $loggerConfig = null): \Psr\Log\LoggerInterface
```

Channel routing is automatic based on the runtime `APPLICATION` constant (`ZED` / `YVES` / `GLUE`). You don't pick the channel — Spryker picks it for you. The plugin classes wiring this are configured in `config/Shared/config_default.php` (search for `LOGGER_CONFIG_ZED`).

**Usage pattern** (works in any non-static method of a class):

```php
use Spryker\Shared\Log\LoggerTrait;

class FooFacade extends AbstractFacade implements FooFacadeInterface
{
    use LoggerTrait;

    public function doSomething(BarTransfer $barTransfer): BarTransfer
    {
        $this->getLogger()->info('[AI-DEBUG] FooFacade::doSomething entered', [
            'idBar' => $barTransfer->getIdBar(),
            'payload' => $barTransfer->toArray(),
        ]);

        return $this->getFactory()->createBarProcessor()->process($barTransfer);
    }
}
```

Three rules:
1. **Use `->info()`, not `->debug()`.** `config_default-docker.dev.php` sets `LOG_LEVEL = Logger::INFO` when `SPRYKER_DEBUG_ENABLED=1` (the default in this dev setup), which filters out `->debug()`.
2. **Tag every line with `[AI-DEBUG]`** (or a unique short tag) so you can later `grep -rn '\[AI-DEBUG\]' src/` and remove your instrumentation cleanly.
3. **Pass structured context as the second arg**, not concatenated into the message string. Monolog renders it as JSON, much easier to scan.

**Where you can use it:**

| Layer/Component | LoggerTrait works? | Notes |
|---|---|---|
| Zed Facade | ✅ | First-class use case |
| Zed Business model | ✅ | |
| Zed Communication Controller | ✅ | |
| Zed Console Command | ✅ | Output also visible via `--verbose` |
| Glue Processor / Controller / Mapper | ✅ | Channel auto-routes to GLUE |
| Yves Controller / Widget | ✅ | Channel auto-routes to YVES |
| API Platform Provider / Processor | ✅ | Same constraint as Glue |
| Static utility method | ❌ | `getLogger()` is non-static. Either make it instance, or use `LoggerFactory::getInstance()` directly. |
| Transfer object / Generated class | ❌ | Files regenerated; changes lost. |

## file_put_contents fallback

Use this only when LoggerTrait won't work — early bootstrap, static utility, or you suspect the queue handler is delaying log writes:

```php
file_put_contents(
    '/data/data/tmp/ai-debug.log',
    sprintf("[%s] %s %s\n", date('c'), __METHOD__, json_encode($context)),
    FILE_APPEND
);
```

This writes inside the container; **on the host the file appears at `<project-root>/data/tmp/ai-debug.log`** (the `/data` mount). Read it with:

```bash
tail -f data/tmp/ai-debug.log
```

Never use `error_log()` — it lands in PHP's SAPI log inside the container, which you have no easy way to read.

## Reading logs back — copy-paste commands

```bash
# === FPM (Yves / Glue / Backoffice / Backend GW) requests ===
# Logs go to stderr → docker logs. First discover the real container names:
docker ps --format '{{.Names}}' | grep -E 'yves|glue|boffice|backgw'

# Then tail / grep them (substitute the names you found):
docker logs --tail 200 <yves-container> 2>&1 | grep -F '[AI-DEBUG]'
docker logs --tail 200 <glue-container> 2>&1 | grep -F '[AI-DEBUG]'
docker logs -f <glue-container> 2>&1 | grep -F '[AI-DEBUG]'

# === CLI commands run via docker/sdk cli ===
# Logs (if any) go to /data/data/logs/<APP>/application.log inside container
# = data/logs/<APP>/application.log on host (relative to project root)
tail -f data/logs/ZED/application.log
grep -F '[AI-DEBUG]' data/logs/ZED/application.log

# Inside container:
docker/sdk cli "tail -f /data/data/logs/ZED/application.log"
docker/sdk cli "grep -F '[AI-DEBUG]' /data/data/logs/GLUE/application.log /data/data/logs/ZED/application.log /data/data/logs/YVES/application.log 2>/dev/null"

# === file_put_contents fallback ===
tail -f data/tmp/ai-debug.log
```

**Note on this dev setup:** the host folders `data/logs/{ZED,YVES,GLUE}/` may be empty even after requests — that's normal here because the FPM containers route to stderr. Check `docker logs` first.

## Pitfalls (logging-specific)

| Symptom | Cause | Fix |
|---|---|---|
| `->debug()` log line never appears | `LOG_LEVEL = Logger::INFO` is the default | Use `->info()` for ad-hoc debug logs |
| Log line missing from host `data/logs/<APP>/application.log` | FPM containers write to stderr, not file | `docker logs <container_name>` instead |
| Log line delayed by minutes from Glue/Yves | `QueueHandler` async-writes via RabbitMQ; consumed by `queue:task:start` | Either run the worker, or write through Zed (synchronous handler), or use `file_put_contents` fallback |
| Cannot use `LoggerTrait` in a static helper | `getLogger()` is non-static | Make the helper non-static, or call `LoggerFactory::getInstance()` directly |
| Sensitive field appears as `***` | Spryker's `LogConstants::LOG_SANITIZE_FIELDS` masks `password`, tokens, etc. | Don't depend on them appearing — log a non-sensitive proxy (e.g. id, length, hash) |
| Trait added inside `src/Generated/` or transfer class — disappears | These are regenerated by `transfer:generate` / `propel:install` | Never log from generated classes. Log from the caller. |
