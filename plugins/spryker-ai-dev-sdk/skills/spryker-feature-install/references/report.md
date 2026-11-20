# The install report

Write `$STATE/report.md` only when `check-dod.php` says `DONE` (SKILL.md Phase 8), in exactly the
format below: the same sections, in the same order, with the same headings and fixed sentences. It
is the report the developer shares with colleagues. Offer to publish it as a shared document; working
detail (checklist, decisions per row, evidence, baselines) stays in `$STATE`.

- Name the project and the feature by release number only (`202608.0`), never by a package's own
  version tag.
- The guide link points to the guide that was followed: when the install release is older than the
  release the docs site shows, the guide file in `spryker/spryker-docs` pinned to the commit used.
- A section with nothing in it keeps its heading and says so in one line ("None.").
- Leave out "Upgrading the project to release …" when the installed release is the latest.
- Problems in the project that this install did not cause are not in the report: list them for the
  developer as ticket candidates.

## Template

```markdown
# <Feature> — install report

<date> · <author>

The <Feature> feature is installed in the project at release <install release>, the same release as the rest of the project.

| Version | Release |
| --- | --- |
| Project (all Spryker features) | <project release> |
| <Feature> feature, installed | <install release> |
| <Feature> feature, latest available | <latest feature release> |

## What was done

<One sentence: what a customer or a Back Office user can do now.>

- <one line per visible capability>
- <one line per project-wide change the install made, and why>
- <the modules kept at their current versions, and why>

## Branch and pull request

Branch: `<branch>`. <Pull request: <link>. | The change is not committed yet, so there is no pull request for it.>

## Steps after the deploy

On each existing environment, run once after the deploy (the deploy itself does not import data):

1. `<command>`

## Integration guide

[Install the <Feature> feature, version used for this install](<pinned guide link>) (spryker-docs, state of <date>, matching release <install release>).

## Not installed

The install guide also contains steps that connect <Feature> with other Spryker features. These steps are needed only when the project uses those features. This project does not use them, so the following steps were not installed:

| Part of the guide | Reason |
| --- | --- |
| <part> | <feature or data> is not in the project |

## What was validated and how

| What | How | Result |
| --- | --- | --- |
| All <n> applicable checks of the guide | run on the local shop: <where> | pass |
| <flows> | <n> new <Cypress journeys / functional tests> | pass, twice in a row on a fresh install |
| Code quality | PHPStan level <n>, code sniffer | no new findings |
| Existing behaviour | full Codecept suite (compared with a run before the install); crawl of <n> Back Office pages | no new test failures |
| Steps after the deploy | local comparison of a deployed environment with a fresh install | equal after the <n> imports |
| <texts written for the project, for example French texts> | not validated | need a review by <who: a French speaker> |

## Upgrading the project to release <latest release>

The project runs Spryker release <project release>. The latest available release is <latest release>. Upgrading the project to <latest release> makes the latest version of <Feature> available, which adds these capabilities:

| Capability | What it gives |
| --- | --- |
| <capability> | <what it gives, in the reader's words> |

Sources: [release notes <latest release>](<link>), [module release notes](<link>).
```

## Example

The Recurring Orders report, as shared with the team:

```markdown
# Recurring Orders — install report

2026-11-18 · Veronika

The Recurring Orders feature is installed in the project at release 202608.0, the same release as the rest of the project.

| Version | Release |
| --- | --- |
| Project (all Spryker features) | 202608 |
| Recurring Orders feature, installed | 202608.0 |
| Recurring Orders feature, latest available | 202610.0 |

## What was done

A business customer can now turn an order into a schedule that the shop places again on a fixed rhythm.

- Checkout option "Set up as recurring order" (weekly, every two weeks, monthly, every N weeks)
- Recurring Orders page in the customer account (details, history, pause, skip, cancel)
- Automatic placement and reminder e-mails through two Jenkins jobs
- Back Office overview of all recurring orders (Sales > Recurring Order Schedules)
- French translations added for all new storefront and e-mail texts
- All shop e-mails now sent with Symfony Mailer, so that e-mail subjects are translated
- `spryker/gui`, `spryker/cms`, `spryker/customer` and `spryker/mail` stay at their current versions, because their newer versions are not compatible with the project's Back Office build, customer override and e-mail setup

## Branch and pull request

Branch: `ai-dev-sdk-integration`. The change is not committed yet, so there is no pull request for it.

## Steps after the deploy

On each existing environment, run once after the deploy (the deploy itself does not import data):

1. `vendor/bin/console data:import:glossary`
2. `vendor/bin/console data:import:cms-block`
3. `vendor/bin/console data:import:cms-block-store`

## Integration guide

[Install the Recurring Orders feature, version used for this install](https://github.com/spryker/spryker-docs/blob/693430ceea/docs/pbc/all/order-experience-management/latest/base-shop/install-and-upgrade/install-features/install-the-recurring-orders-feature.md) (spryker-docs, state of 17 August 2026, matching release 202608).

## Not installed

The install guide also contains steps that connect Recurring Orders with other Spryker features. These steps are needed only when the project uses those features. This project does not use them, so the following steps were not installed:

| Part of the guide | Reason |
| --- | --- |
| Cost center and budget on recurring orders | Purchasing Control is not in the project |
| Restrictions for service products | Self-Service Portal is not in the project |
| Back Office setting "Schedule Grace Period" | the Configuration feature is not in the project; the default of 48 hours applies |
| Shipment copy for product bundles | the shop sells no product bundles |

## What was validated and how

| What | How | Result |
| --- | --- | --- |
| All 17 applicable checks of the guide | run on the local shop: checkout, customer account, Back Office, database, e-mails in the mail catcher, Jenkins jobs | pass |
| Checkout to customer account, and the Back Office list | 2 new Cypress journeys | pass, twice in a row on a fresh install |
| Code quality | PHPStan level 6, code sniffer | no new findings |
| Existing behaviour | full Codecept suite (compared with a run before the install); crawl of 77 Back Office pages | no new test failures; 1 crawl error, in a module this change did not touch |
| Steps after the deploy | local comparison of a deployed environment with a fresh install | equal after the 3 imports |
| French texts | not validated | need a review by a French speaker |

## Upgrading the project to release 202610

The project runs Spryker release 202608. The latest available release is 202610. Upgrading the project to 202610 makes the latest version of Recurring Orders available, which adds these capabilities:

| Capability | What it gives |
| --- | --- |
| Multi-day schedules | one recurring order can deliver on several days, for example every Tuesday and Thursday |
| Price tolerances | buyers set an accepted price increase for the order total and for single products; small price changes no longer stop the schedule for review |
| Price tolerances in the Back Office | tolerance column, filter and details on the schedules list and detail page |
| "Processing" status | a schedule shows "processing" while its order is being placed, and buyer actions are hidden during that time |
| Recovery of stuck schedules | a console command restarts schedules stuck in the state machine and reports the others |
| Orders Backend API | read, list and create orders, trigger order state transitions and add order comments, for example for an ERP integration |
| Fixes | schedules no longer get stuck in validation; a price accepted for "just this order" no longer replaces the regular price; a price change with a budget warning goes to review instead of being placed |
| Experience | a more consistent design across checkout, order review and the customer account |

Sources: [release notes 202610.0](https://docs.spryker.com/docs/about/all/releases/release-notes-202610.0.html), [module release notes](https://github.com/spryker-feature/order-experience-management/releases).
```
