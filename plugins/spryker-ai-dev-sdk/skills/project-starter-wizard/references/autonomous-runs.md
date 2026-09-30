# Autonomous runs — stopping, delegating, and what "done" requires

Every rule below is also enforced by a hook in `hooks/`. This file is the readable version of the
same rules, so a run can follow them *before* tripping one.

| rule | enforced by |
|---|---|
| a progress report does not end the turn | `guard-stop.php` |
| a denied sub-agent call does not cancel the task | `guard-stop.php` |
| a blocked command is recorded in the report and the turn continues | `guard-stop.php` |
| a sub-agent prompt carries the tooling, the skill and the work class | `guard-agent.php` |
| a design step is done against an evidenced sweep of every surface | `guard-files.php` |
| a theme template/stylesheet is design work and has a skill | `guard-files.php` |

---

## 1. When `run_mode: autonomous`, three things follow

Answering `autonomous` means the operator will not shepherd the run.

**A progress report does not end the turn.** "Step 6 is in progress, not done" is a reason to
carry on. The Stop guard blocks a turn that hands back only to report on unfinished rows, because
such a turn leaves the developer asking why the run stopped.


**A denied tool call does not cancel the task.** When a sub-agent's call is denied, the
parent sees `Agent "…" was stopped by user` — a notification that cannot distinguish a cancelled
task from a denied command. Re-dispatch with tools inside the allowlist (replacing shell assembly
with the named scripts usually resolves it), or record it blocked and continue. Never tell the
developer they stopped your work: the message does not mean the developer withdrew the instruction.


**A missing permission is a line in the report.** Finish every part the allowlist reaches — usually
most of the task — then table the rest: what you needed, the exact command a human runs, why it is
blocked, and what is now *unverified* rather than *failing*. A complete report takes minutes.

Stop for exactly the three hard-stops in `SKILL.md` §3, and end the turn **on** the first of them when
it applies: (a) a `⚠ ACTION NEEDED` prerequisite only the developer can do (a permission they must
grant included); (b) an irrecoverable action outside the first-setup carve-out; (c) a step failure.

## 2. Sub-agents inherit the work, not the discipline

A sub-agent loads **no skill, no project `CLAUDE.md`, and none of this session's conventions.** Every
constraint you hold exists for that worker only if you wrote it into the prompt.

A parent that uses the named tools consistently still loses that discipline at the dispatch if the
prompt omits it. For example, a `general-purpose` worker told to "hand-write RFC4180 CSV with the
Write tool" hand-rolls quoting, reaches for python to verify it, and produces both malformed data and
a stream of permission prompts.

Every dispatch prompt carries three blocks:

```
TOOLING — by literal command, with a "do not hand-write / do not use python or a heredoc" line.
SKILL   — "per `<skill>`" or "load `<skill>`" naming the SDK skill that owns this work, and its rules
          pasted literally (the worker cannot read them).
CLASS   — setup | data | design | customization, plus the exact files this worker may touch.
```

Prefer dispatching to the **named** sub-agent type over `general-purpose` whenever one exists
(`spryker-verifier` for anything behind a login). A `general-purpose` worker starts with none of
these constraints.

## 3. Done means every surface checked after the last change

A fix verified only against the surface that was just reported does not pass. A sweep limited to
that surface reports "fixed and verified" while other surfaces still carry defects. The surfaces most
often skipped are the dropdowns (not opened), the footer (not scrolled to) and the mobile width (not
tried).

Sweep **every** surface after the **last** change, not incrementally, and write the evidence down: a
measurement, a rendered value, a URL fetched, or a file path. The word "checked" on its own is not
evidence. For design work the artifact is `.ai-dev/design-acceptance.md` — see
`match-reference-design` §11a for the table and the surface list.

## 4. Load the skill that owns the work

Before editing Twig/SCSS files, load the design skill that owns them. Without it, the design loop
re-derives from screenshots what `yves-atomic-frontend` states outright (a molecule's real markup is
required; the built entry points are `critical.css` + `util.css`, never `app.css`).

Loading a skill takes one tool call; skipping it costs repeated round-trips.
