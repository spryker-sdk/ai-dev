# Release steps

**Goal:** find the commands that staging and production must run after the normal deployment.

## 1. Keep the log

During the whole upgrade, write each release action into `.spryker-upgrade/state/release-steps.md`:
a migration, an environment variable, a one-time command, a new job, or an action that a guide names
— a migration guide, the integration guide of a new feature, a performance fix, or a security release
note.

```
| <action> | <why> | to verify / verified / not needed |
```

## 2. Prove the steps (Phase 6.8)

**Ask the developer first:**

- Which branch does production run?
- Which commands does the normal deployment run? Read them from the pipeline if it is in the
  repository.
- May you run `docker/sdk reset` two times? It deletes the local data.

**Then do these steps in this order.** Use the same deploy file in both parts. For a clean state,
prefer `docker/sdk reset`: it imports the project data, so load no other data. Wait until the
queues are empty after each reset and after the deployment.

| # | Do | State |
|---|---|---|
| 1 | Commit or stash all changes. Check out the production branch. | |
| 2 | `docker/sdk reset` | production today |
| 3 | Check out the upgrade branch. | |
| 4 | Run only the normal deployment commands. | |
| 5 | Dump the database, Redis and the search engine (documents, mappings, index settings). | **deployed** |
| 6 | `docker/sdk reset` | |
| 7 | Dump the same set as in step 5. | **fresh** |
| 8 | Compare **deployed** with **fresh**. Ignore IDs, UUIDs and timestamps. | |

**For each difference:**

- **A command fixes it** → find the smallest command, for example
  `publish:trigger-events -r <resource>`. Do steps 1–5 again, run only that command, and compare
  again. If the difference is gone, it is a release step.
- **No command fixes it** → write down what differs and what it changes.
- **Your own tests caused it** → ignore it.

**Never:**

- `publish:trigger-events` without `-r`.
- A reset before the dump of the step before is saved.
- A command on the deployed state that the normal deployment does not run — ask first.
- Two Docker builds, resets or imports at the same time.
- Tests or page checks instead of the comparison.

## 3. Write the steps

In the handover report, use only the `verified` steps.

- Staging first. Production after the check on staging passes.
- Per step: the command, the reason, and that the team runs it as a pipeline job.
- Environment variables: make sure they are set, or set them, or use your own names.
- After the deployment: run the tests against the environment and check the critical workflows by
  hand.

If step 2 did not run, write that the release steps are not verified.
