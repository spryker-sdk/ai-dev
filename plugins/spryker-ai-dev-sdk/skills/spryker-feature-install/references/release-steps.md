# Release steps

**Goal:** find the commands that staging and production must run, once or on every deploy, so that
an existing environment gets the feature that the local install got.

A guide is written for a local install. It runs imports, installers and republishes by hand. An
existing environment gets only what its deploy recipe runs, and it already has data: a new publisher
does not publish the rows that exist, and a glossary import that the deploy does not run never
happens.

## 1. List the candidates

```bash
php $FI/diff-release-steps.php $STATE/checklist.json
```

It compares the checklist with `config/install/production.yml` (all sections) and the `build` and
`build-static` sections of `config/install/docker.yml`. Check those defaults against the project:

- Which recipe does each deploy file name (`pipeline:` in `deploy.*.yml`, the Docker SDK default
  otherwise)?
- Which sections run on the image build, and which on the deploy? Read the pipeline in the
  repository if it is there.

Pass the real ones with `--recipe <file>[:<section>,<section>]`.

| Candidate | Comes from |
|---|---|
| A console command | A `console` row the guide runs |
| `data:import ...` | A `feature-data` or `project-content` row |
| `publish:trigger-events -r <resource>` | A new publisher plugin: existing rows are not published |
| `queue:setup` | A new queue |
| `propel:migrate` | A schema row |
| `env <VARIABLE>` | A config snippet that reads an environment variable |

Write every candidate that is not `covered` into `$STATE/release-steps.md`:

```
| <action> | <why> | rows | to verify / verified / not needed |
```

Replace each `<resource of …>` placeholder with the resource name: the publisher plugin's
`getSubscribedEvents()` events, and the resource names that `publish:trigger-events --help` lists.

## 2. Prove the steps

**Ask the developer first:**

- Which branch does production run?
- Which commands does the normal deployment run? Read them from the pipeline if it is in the
  repository.
- May you run `docker/sdk reset` two times? It deletes the local data.

**Then do these steps in this order.** Use the same deploy file in both parts. For a clean state,
use `docker/sdk reset`: it imports the project data, so load no other data. Wait until the queues
are empty after each reset and after the deployment.

| # | Do | State |
|---|---|---|
| 1 | Commit all changes on the feature branch. Check out the production branch. | |
| 2 | `docker/sdk reset` | production today |
| 3 | Check out the feature branch. | |
| 4 | Build the images and assets as the pipeline does, without data: `docker/sdk up --build --assets`. Then run only the normal deployment commands. | |
| 5 | Dump the database, Redis and the search engine (documents, mappings, index settings). | **deployed** |
| 6 | `docker/sdk reset` | |
| 7 | Dump the same set as in step 5. | **fresh** |
| 8 | Compare **deployed** with **fresh**. Ignore IDs, UUIDs and timestamps. | |

**For each difference:**

- **A command fixes it** → find the smallest command, for example
  `publish:trigger-events -r <resource>`. Do steps 1–5 again, run only that command, and compare
  again. If the difference is gone, it is a release step: set the log row to `verified`.
- **No command fixes it** → write down what differs and what it changes.
- **Demo data you added on purpose** → the deployed state lacks it by design. Record it as `not
  needed` for production, with that reason.

**Never:**

- `publish:trigger-events` without `-r`.
- A reset before the dump of the step before is saved.
- A command on the deployed state that the normal deployment does not run — ask first.
- Two Docker builds, resets or imports at the same time.
- Tests or page checks instead of the comparison.

If the developer does not allow the resets, the log rows stay `to verify`, and the report says that
the release steps are not verified.

## 3. Recipe or one-time job

For each verified step, recommend one of these:

| Recommend | When | Example |
|---|---|---|
| A recipe entry | Every deploy needs it | A new queue, an import of configuration data that changes with releases |
| A one-time job | Only the first deploy of the feature needs it | A republish of existing rows, a first import of `feature-data` |

Environment variables: the team sets them in each environment before the deploy. Name the variable,
the file that reads it, and what it holds.

## The report

`$STATE/report.md`, in this order:

1. **Feature and release** — the feature, the release, the guides used (with their source URLs).
2. **Installed** — per guide section: the rows applied, with their decision.
3. **Differences from the guide** — every row with `apply-reference`, `merge` or `skip:<reason>`,
   with the reason.
4. **Data** — the hand-offs, per `data_class`, and the placeholders listed for `curate-golive-data`.
5. **Storefront placement** — the placements applied (widget, project file), declined, or the
   suggestion table as a follow-up; "no Yves code" when the step did not apply.
6. **Verification** — each `verification` row: PASS, or ACCEPTED with its note. The baseline
   comparison: PHPStan, sniffer, tests, Back Office crawl, storage and search counts.
7. **Release steps** — staging first, production after the check on staging passes. Per step: the
   command, the reason, recipe entry or one-time job, and `verified` or `to verify`.
8. **Not verified** — every check that did not run, and what it would have found.

Use only `verified` steps as instructions; list the `to verify` steps under their own heading.
