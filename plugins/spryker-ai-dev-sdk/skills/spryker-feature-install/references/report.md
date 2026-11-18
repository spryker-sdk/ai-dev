# The install report

Write `$STATE/report.md` only when `check-dod.php` says `DONE` (SKILL.md Phase 8). It is the report
the developer shares with colleagues: short, for readers who did not follow the run. Working detail
(checklist, decisions per row, evidence files, baselines) stays in `$STATE`.

## Rules

- **Releases, not package versions.** Name the project and the feature by their release number
  (`202608.0`). Never by a package's own version tag (`4.0.0`).
- **The guide link is the guide that was followed.** When the install release is older than the
  release the docs site shows, link the guide file in `spryker/spryker-docs` pinned to the commit
  that was used, and say which release it matches. The docs site and its archive show only the
  latest guide.
- **Every claim is measured.** "No new failures" only for a check that has a baseline; a check that
  could not run is not in this report, because the run is not DONE.
- **No internal working detail:** no commands that were tried, no files not to commit, no account
  names or passwords.
- **Problems found in the project that this install did not cause** are not in the report. List
  them for the developer as ticket candidates.

## Template

````markdown
# <Feature> — install report

The <Feature> feature is installed in the project at release <install release>[, the same release as
the rest of the project].

| Version | Release |
| --- | --- |
| Project (all Spryker features) | <project release> |
| <Feature> feature, installed | <install release> |
| <Feature> feature, latest available | <latest feature release> |

## What was done

<One sentence: what a customer or a Back Office user can do now.>

- <one line per visible capability: storefront, customer account, Back Office, e-mails, jobs>
- <one line per project-wide change the install made, with its reason (for example a mailer switch)>
- <one line for modules kept at their current versions, with the reason>

## Branch and pull request

Branch: `<branch>`. <Pull request: <link> | The change is not committed yet, so there is no pull request for it.>

## Steps after the deploy

On each existing environment, run once after the deploy:

1. `<command>`

<Or: "None: the normal deploy covers this feature.">

## Integration guide

[Install the <Feature> feature, version used for this install](<pinned guide link>) (<source>, matching release <install release>).

## Not installed

The install guide also contains steps that connect <Feature> with other Spryker features. These steps
are needed only when the project uses those features. This project does not use them, so the
following steps were not installed:

| Part of the guide | Reason |
| --- | --- |
| <part> | <the feature or data it needs, which the project does not have> |

<Or: "Every step of the guide was installed.">

## What was validated and how

| What | How | Result |
| --- | --- | --- |
| All <n> applicable checks of the guide | run on the local shop: <where> | pass |
| <flows> | <n> new <Cypress / functional> tests | pass |
| Code quality | PHPStan level <n>, code sniffer | no new findings |
| Existing behaviour | <test suite> compared with a run before the install; crawl of <n> Back Office pages | no new failures |
| Steps after the deploy | local comparison of a deployed environment with a fresh install | equal after the steps above |
| <texts the agent wrote> | <who approved them> | approved |

## Upgrading the project to release <latest release>

<Only when a newer release has more of this feature.>

The project runs Spryker release <project release>. The latest available release is <latest
release>. Upgrading the project to <latest release> makes the latest version of <Feature>
available, which adds these capabilities:

| Capability | What it gives |
| --- | --- |
| <capability> | <benefit, in the reader's words> |

Sources: [release notes <latest release>](<link>), [module release notes](<link>).
````
