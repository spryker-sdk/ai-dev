---
name: demo-prep-wizard
description: "Use when a demo is coming and the job is to prepare it end to end — 'we have a demo coming up', 'help me prepare a demo for <customer>', 'prepare the demo', 'here is the briefing for the demo', 'what do we need for this demo'. The demo-side parallel of `project-starter-wizard`: that one turns a demoshop clone into a project, this one takes the demo the preparer already has and drives the gathering and shaping of everything it needs — intake, requirements, design reference, harvest, build, rehearsal, run sheet. It orchestrates: every phase is owned by a named skill (`demo-intake`, `match-reference-design`, `harvest-source-materials`, `project-data`, `demo-run-sheet`, …) and this skill never re-implements harvesting, design or data mechanics. Also the resume entry when a prior run left `.ai-dev/demo-prep.md`."
---

# demo-prep-wizard

`project-starter-wizard` turns an unmodified demoshop clone into the customer's project. **This skill
prepares the demo on it**: it takes the customer briefing — including whatever demo script the
preparer already has — and drives the gathering and shaping of everything it needs, so that
"we have a demo coming, help me prepare" is a single guided run.

**The demo's story is taken as supplied.** Whoever prepares a demo arrives with the story
already decided — in the brief, in a deck, in a demo script, or in their head. This skill does not ask
for it, propose one, or run a round of confirmation over it. It reads what was
supplied and turns it into the work that makes it real.

## Who runs this — a preparer, not a developer

The person running this — "the preparer" below — is usually a solution consultant or a salesperson. **The goal is a demo that
looks right, delivered fast, without pulling in a developer.** They own the story and judge the look
and feel; the engineering is yours.

- **Every question is about content, look or the story — never about mechanism.** No work classes, no
  namespaces, no services, no CI, no rebuild strategy. If a question cannot be phrased without a
  technical term, it is not theirs: decide it, record it in the decision log, and move on.
- **Recommend, then ask.** Every question puts your recommended option first, marked "(Recommended)",
  so accepting it is one click. When the brief is silent, recommend the shipped default.
- **Ask only for what only they know:** which products must appear (exact names or links), which pages
  the demo walks through, how it should look, and whether a result looks right.
- **When they must act, give one command** in its own code block, what it does in one sentence, and
  nothing else.
- The plain-language table in `../project-starter-wizard/references/interview.md` applies to every line
  shown to them.

**Open the local shop in Claude in Chrome (`mcp__claude-in-chrome__*`), not the built-in browser** — the built-in browser asks the person on every action for a local host, and `guard-browser.php` denies it there.

**You own the conversation and the flow; you own no mechanics.** Every phase below names the skill that
does the work. Read that skill, hand it its input, take its output — never restate its rules and never
do its job in-line.

The **Communication** rules and the **Tooling discipline** of `project-starter-wizard/SKILL.md` bind
this run unchanged: a required human action leads the message as a single `⚠ ACTION NEEDED:` line;
step reports are log lines, not deliverables; project and `.ai-dev/` files are written with
**Write/Edit**, never a shell redirect or heredoc.

---

## The spine: the needs list

**Every later phase keys off the needs list** (phase 2). It is the demo's specification, the harvest's
shopping list, the rehearsal's checklist and the run sheet's source of beats — so it is derived from
the supplied demo before anything is gathered, not discovered while building.

It is **derived, never agreed**: the beats come from the brief, and what this skill adds is the list
of things that have to exist for those beats to work. The preparer confirms the *routing* — which
class of work each item is, and whether it is in this run — never the demo.

Two rules:

- **Quoted UI strings are fixed wording from the moment the brief is read.** brand-project, the CMS
  content pass and the run sheet copy them character for character, out of the brief's own wording.
  A paraphrased string loses the exact wording, which then has to be asked for again.
- **Every beat's data needs are written down explicitly.** A beat needing "a range" in a category
  that holds two products, or "show colour options" on a hero product with one colour, is a gap;
  record it on the list before the import runs.

**Where the beats come from, in order:** the demo script in the brief; a script the preparer pastes
or points at; failing both, the brief's own prose. Read them where they are — nothing is re-authored
into a spec of our own. **If nothing supplied says what will be shown, say so in one line and ask for
the script** — treat it as a missing input and do not design the demo yourself. Record which of the
three it was in `.ai-dev/demo-prep.md`'s `answers_source:` line (`brief` | `script` | `paste`) and where
it lives in its `brief:` line, so a resume hours later does not have to ask again. The hook identifies
the state file's first write by its `answers_source:` line ([references/state-file.md](references/state-file.md)).

---

## Phases

| # | phase | owner | produces | gate to the next |
|---|---|---|---|---|
| 1 | Intake | **`demo-intake`** | the handoff block: platform pin, purpose, source URL, content list with paths and row counts, brand assets, open questions | none of its own — it flows straight into phase 2; its open questions are asked in the one sitting |
| 2 | Requirements derivation | **this skill** | the routing table + the explicit needs list (above) | the preparer confirms the routing table and sees the needs list |
| 3 | Design reference | **`match-reference-design`** | the composition spec (`spec.md`) | the spec is recorded; in an autonomous run your recommended version is taken, logged, and shown at the final review (that skill's §1.4) |
| 4 | Harvest | **`harvest-source-materials`** | the gathered material, its coverage record, the gap list | coverage recorded against phase 2; every gap named |
| 5 | Build | **the project track** — `project-data`, `define-stores`, `translate-content`, `match-reference-design`, `boot-and-verify` | a booted shop carrying the harvested material | `boot-and-verify`'s verdict (`.ai-dev/verifier-report.md`) |
| 6 | Rehearsal | **this skill** (+ `spryker-verifier` for anything behind a login) | `.ai-dev/rehearsal.md` — pass/gap per beat | every beat passes or is a named, accepted gap |
| 7 | Run sheet | **`demo-run-sheet`** | the sheet the salesperson presents from | the preparer has the artefact |

### Before phase 1 — what the machine needs, checked first

Run `project-starter-wizard`'s pre-flight checks read-only, before the first question: Docker running,
disk space, and the host names in `/etc/hosts` (`../project-starter-wizard/references/preflight.md`).
Anything the preparer must do goes into **one** up-front message — each item one sentence plus the
exact command to copy — so nothing surfaces an hour later. Nothing to do → say nothing and start. On an
unmodified clone, also capture the gate baseline now (`validate.php gate --save .ai-dev/gate-baseline.json`)
— later phases touch the clone, and the harvest's copies and `.ai-dev/` are expected changes, not a question.

### 1 · Intake

Delegate to `demo-intake` and hand over nothing but the briefing. It is read-only on the source, it
opens exactly `composer.json` in the clone, and it returns one pasteable block. **The block is not
confirmed on its own** — it flows straight into phase 2, and its open questions are asked in the one
sitting (Run modes), so the preparer never answers the same thing twice.

### 2 · Requirements derivation

**First the routing table, then the needs list. Nothing starts until the table is confirmed.**

**Start from the table `demo-intake` §2 already produced — do not rebuild it.** Add a row for any need
the beats create that the brief's items did not.

**Classify every row yourself** — the preparer is not asked — against
`../demo-intake/references/work-classes.md` — setup · data · design · customization, or *already works* —
then decide how the row reaches the demo:

- **setup, data, design, already works → "Shown — look and content".** No code. This is the default and covers most rows.
- **customization → "Shown — a small working version".** A beat that needs behaviour the platform does
  not have (a quick view, hotspots on a photo) gets a minimal proof of concept that works on screen:
  `spryker-customization` at the **PoC** quality bar, on this demo clone (phase 5). Say what it will
  look like and roughly how long it takes.
- **"Left out".** Recommend it only when a working version would cost more than the beat is worth to
  the story, and offer it as a talking point on the run sheet instead.

Show the preparer the table in **their** terms — what they asked for, how it will appear, your
recommendation — and confirm the routing table with one AskUserQuestion whose question text contains the word
"confirm" (`guard-files.php` looks for it in the question or header before the state file's first write). It is
the run's routing confirmation, which stamps `answers_confirmed_at`; the one sitting's confirmation is the only other:

| # | what you asked for | how it appears in the demo | recommended |
|---|---|---|---|
| 1 | "the homepage should read like their site" | restyled to match their site | Shown — look and content |
| 2 | "a seasonal banner across the top" | a banner with the brief's exact wording | Shown — look and content |
| 3 | "hotspots on the lifestyle photo" | a photo whose markers open the product | Shown — a small working version (about an hour) |

Record each row's class and owner skill in `.ai-dev/demo-prep.md`, never in the table they see.

**A consumer demo on the B2B clone always carries two rows of its own**, whether or not the brief spells
them out, because the clone ships as a business shop: **"shoppers see prices and can buy without an
account"** (customization — guest access and guest checkout in one row, a known recipe) and **"no business-only screens"** (design — company and
business-unit selection, quick order, quotes, approvals and shopping lists kept out of sight). Recommend the first as
"Shown — a small working version (about an hour)", the second as "Shown — look and content". A business demo needs neither. **A `both` demo** needs the first row, and
the second becomes **"business screens only for logged-in business customers"** — guests and shoppers
never see them, the business persona does.

**Read `../project-starter-wizard/references/autonomous-runs.md` before the first autonomous turn** — how a stop is judged, what a denied sub-agent call means for the task, what every sub-agent prompt must carry, and what "done" requires.

**Confirm the table before anything else**, then go straight on to the one sitting (Run modes) — it
happens in every run, before phase 3 starts. An item that spans classes is split into its parts, each
on its own row. An item you cannot place is yours to resolve by re-reading the brief — never a class
question for the preparer. The rule this enforces: *do the rows that were confirmed, in the way that was
confirmed, and nothing else.* (Why: `work-classes.md`.)

Then turn the supplied demo into an **explicit needs list** — mechanically, beat by beat, so each
line names the beat that demands it:

- **products** — each one with the attributes its story actually shows (the colour range, the size run,
  the stock pattern, the price the persona must see);
- **categories** — with the product count each must hold for its beat to work;
- **facets** — named, each with the values that must produce a non-empty narrowed result;
- **personas** — for a consumer demo, a guest and a shopper account; for a business demo, company users;
  for `both`, all three — each with what it must be able to *do*, not what role it carries (a role
  can exist without the place-order permission);
- **content blocks** — each with its exact wording, quoted from the brief character for character;
- **imagery** — what each page needs, and at which aspect ratios (phase 3 settles the ratios).

**Write the list into `.ai-dev/demo-prep.md` under `## Needs`** — one `### <id> — <title>` section per
beat, then `key: value` lines (`story`, `actor`, `products`, `categories`, `facets`, `blocks`,
`strings`), with prose free around them. That shape is what
`validate.php demo-needs` parses at phase 6 — once phase 5 has built the import, rewrite each beat's
`products`/`categories`/`blocks`/`actor` to the SKUs, keys and persona emails it produced — so the data
gaps are found by a command instead of by hand. Fields the brief does not state are left out, never guessed. Before phase 3, give the preparer
a **one-line summary** of it in plain words — e.g. "12 products in 3 categories, 2 banners with your
wording, 8 photos, one buyer account" — not to be agreed, but so they can see what the demo is about
to take. The list itself stays in the file.

**A need the source cannot supply is a gap line, never a value invented to fit the narrative.** On the
feature side, a beat that needs behaviour the platform does not have is a **customization row of its
own** — it becomes a small working version only once the preparer has confirmed that row, and it is
never slipped into the look-and-content work. `demo-intake` §3 states the same rule for data.

### 3 · Design reference

Delegate to `match-reference-design` for the composition spec: the sites in `up_front.reference_sites`,
section order, full-bleed versus contained, how many editorial panels and at what aspect ratios.

**It runs before harvesting because it decides how much editorial imagery to fetch.** Harvesting
first means fetching without the ratios and then fetching again.

### 4 · Harvest

Delegate to `harvest-source-materials`, driven by the phase-2 needs list and the phase-3 spec. Hand it
both; take back its coverage record and its gap list.

**Gate:** the harvest's coverage record read against the phase-2 list, line by line — every product,
category, facet, block and image either covered or on the gap list. A gap is carried forward named; it
is never closed by substitution.

### 5 · Build

Hand off to the project track. Nothing here is this skill's to do:

- **`project-data`** in dataset mode, with the path and field mapping the harvest produced;
- **`define-stores`** for the store/locale/currency shape the demo needs;
- **`translate-content`** when the demo names wording in another language or register;
- **`match-reference-design`** for the look, against the phase-3 spec;
- **`boot-and-verify`** for the boot and the independent verdict;
- **`spryker-customization`** for every row confirmed as "a small working version", invoked with the
  **demo preset** (its Step 0): PoC quality bar, screenshots on, everything else off. Hand it the row
  and the beat; it asks the preparer nothing technical.
- **The two consumer rows** (phase 2) — only when `audience` is `consumer` or `both`. A `business` demo has
  neither, runs no customization before the first boot, and boots as usual. Guest access and guest checkout → **`spryker-customization`**, demo
  preset, following [the B2B guest-checkout recipe](../spryker-customization/references/b2b-guest-checkout.md)
  from its part 1 (the guest-access installer defaults) through the checkout changes. The installer writes
  those defaults only into an empty table, so the clone's state decides when the row runs:
  - **Unmodified clone:** run this row after `project-starter-wizard`'s pre-boot steps (1–7) and before its
    step 8, and tell the skill to use the demo preset's **pre-boot mode** (file edits only). `boot-and-verify`
    asserts the guest journey after the first boot; the phase-6 rehearsal walk supplies the screenshots.
  - **Clone already booted:** its report says "needs a reset": that reset is yours to order — invoke
    `boot-and-verify` for it (§3b, a trusted rebuild on a demo clone), which then asserts the guest journey per store.
  Hiding the business-only screens → **`match-reference-design`** §12 (shown only to a logged-in company user).

If the demo runs on a clone that is still an unmodified demoshop, this phase **is**
`project-starter-wizard` with `purpose: demo`. Its **demo fast path**
(`../project-starter-wizard/references/interview.md`) takes the shop's name, the countries, languages
and currencies, what happens to the sample products and the reference sites from `up_front` — **it
asks nothing again**, and the one-sitting confirmation (`up_front_confirmed_at`) is its confirmation.
Everything technical is derived. Let it own its own state file.

**Trusted rebuilds** (a demo clone, first setup, or the preparer's explicit "rebuilds" approval) **run
without a prompt**; on a project with real data the `gate-docker-sdk.php` hook asks once. Announce each
in one line with its expected duration. From the **third rebuild on a project** (the count is per
project, not per step) the hook denies until the decision log has a line `rebuild #N: <why rung 1
cannot show it>` — write it and continue. It is not a hard stop and never interrupts the preparer.

### 6 · Rehearsal

**Walk the demo end to end, as the persona each beat names, in the browser.** Record pass/gap per beat
in `.ai-dev/rehearsal.md` — one line per beat, quoting the beat from the brief, then `pass` or
`gap: <what is missing>`.

**Run the mechanical check first, then walk the beats.** The check is cheap and catches the gaps that
cost the most to find by hand:

```bash
php .claude/skills/spryker-import-tools/scripts/validate.php demo-needs .ai-dev/demo-prep.md
```

That is the setup-install path; on a plugin install the script is
`${CLAUDE_PLUGIN_ROOT}/skills/spryker-import-tools/scripts/validate.php`. Invoke it by the literal path from
the project directory, as `boot-and-verify` describes.

It reads the phase-2 needs list and reports, per beat: a named product no row carries, a product whose
every variant has zero stock (the story dead-ends at add-to-basket), a category or block that does not
exist or is inactive, a facet whose own products give nothing to choose between, a persona with no
customer row, and a quoted string that is not present verbatim in any content row. Fix what it names
before spending a rehearsal on it.

It proves the data exists, not that the story reads well, so the walk follows:

- Walk each beat in the browser. A `curl | grep` does not count as evidence for a beat: markup shows
  that a string is present, not that the beat looks or works right.
- **Anything behind a login is delegated to the `spryker-verifier` agent** with the AC written out; this
  skill does not log in.

**Gate:** every beat either passes or is a **named, accepted gap** — named to the preparer, accepted
by the preparer (in an autonomous run, at the final review; the step is `done` once the walk is
recorded). `guard-files.php` denies this step `done` without `.ai-dev/rehearsal.md` carrying
pass/gap lines written after the last rebuild. A gap stays recorded as a gap; it is never reworded
into something the demo happens to do well.

**A rehearsal fix that edits a template or style re-runs `match-reference-design` §11a** before `build` or
`rehearsal` is marked `done`: `guard-files.php` requires a `.ai-dev/design-acceptance.md` newer than both
the newest template or style edit and the last rebuild.

### 7 · Run sheet

Delegate to `demo-run-sheet`. It takes its beats from the brief, in the brief's order and wording, and
the output format from `up_front.run_sheet_format`, in every run. Whatever the format, a local copy lands
in `.ai-dev/demo-run-sheet.html`, `.md` or `.txt`; a connector document is extra, never instead.

---

## The state file: `.ai-dev/demo-prep.md`

Same shape and same role as the wizard's `.ai-dev/project-setup.md`: the run **is** this file, written
with Write/Edit. **Read [references/state-file.md](references/state-file.md) before its first write** —
the frontmatter template, the Steps table, and how the plugin's hooks police the file: the first write
waits for the confirmed routing table, and the phase-5 step skills wait for `answers_confirmed_at`. A
denial from either hook means phase 2 is not closed: close it rather than working around the hook.

---

## Run modes — one sitting, then silence

**Autonomous (the recommended mode) runs from start to finish without the preparer in between.** Three
moments with the preparer: the routing-table confirmation, the one sitting (with its confirmation), and
the final review — nothing in between. After the routing table (phase 2):

1. **One sitting, up front** — straight after the routing table is confirmed, ask everything the run
   will ever need, in as few AskUserQuestion calls as possible (up to four questions each), each with
   the recommended option first:
   - **products that must appear** — names or links (unless the brief already names them);
   - **the intake block's open questions** — in plain words, each with your recommendation;
   - on an unmodified demoshop, **the demo fast path's questions** — the shop's name; the countries,
     languages and currencies; what happens to the sample products
     (`../project-starter-wizard/references/interview.md` → Demo fast path);
   - **the run-sheet format** — `demo-run-sheet` §0's question and options, stored as its token;
   - **run mode — last**: "Run it through and tell me when it's ready (Recommended)" or "Check in with
     me after each step".

   Then **one confirmation** listing every answer and every derived value (domain, region and store
   codes from the fast path; the harvest folder — `harvest-source-materials` §1's default beside the
   project; the site(s) the look is matched to — the intake's source URL unless the brief names
   others, shown here, not asked), write it all under `up_front:` and stamp `up_front_confirmed_at`.
   Every delegate reads its answer from there instead of asking again. The sitting happens in every
   run — run mode is its last question — so a collaborative run starts from the same answers.
2. **Silence until done.** No further questions. The two checkpoints that depend on material not seen
   yet — the design spec (phase 3) and the harvest fetch list (phase 4) — take your recommended version,
   get one decision-log line each, and are shown in the final review. Visual sign-offs and rehearsal
   gaps are collected, never asked mid-run.
3. **One final review** — see "The final review" below. Changes asked for there are a follow-up pass.

Only a hard-stop interrupts the silence: something only the preparer can do (starting Docker, the one
`/etc/hosts` command). A third rebuild is not one — the agent logs its reason and continues (phase 5).
The routing table is never autonomous — it comes before the sitting.

**Collaborative** — a one-line result and an explicit "Continue to `<next phase>`?" at each phase
boundary; every decision surfaced as a question with a recommendation, at the moment it arises.

Neither mode relaxes the hard-stops: a `⚠ ACTION NEEDED` prerequisite, an irrecoverable action, and a
phase failure return control in both.

## The final review — autonomous runs

After phase 7, one message and one question. Show, per page, the reference next to the result; each
small working version's screenshots; the gap list with what each gap means for the story; the look we
matched to and the list of pictures and texts we gathered, both taken as recommended; any decision
logged mid-run for this review; and the run sheet's path. Ask once: "Does this look
right, and are these gaps OK?" Record accepted gaps in `gaps_accepted`. Anything they want changed is a
follow-up pass through the phase that owns it.

---

## Skip-ahead — this skill is usable from any phase

**A demo can start at any phase.** Infer from what the preparer said and supplied which of these they
are in (never a question), state the entry phase in one line, write it to `entry_phase`, mark the skipped phases `skipped (<reason>)` in the Steps table so
resume never lands on one, and start there.

| the preparer says | entry | what you still need first |
|---|---|---|
| "here is the briefing for the demo" | **1** | nothing |
| "a second demo on the project we already built" | **2** | the new demo's beats, from wherever they were supplied; the earlier run's `## Needs` block stays as prior art and is never overwritten |
| "here is the demo, what do we need" | **2** | nothing — the beats are the input, and phase 2 derives the needs from them |
| "I already have the material — build it" | **5** | the phase-2 needs list, even if you derive it in one pass: phase 6 has no acceptance criterion without it, and the harvest coverage gate is skipped by a preparer who has done the harvesting themselves |
| "we ran this before, continue" | **resume** | read `run_mode` and `answers_confirmed_at` from `.ai-dev/demo-prep.md`, continue from the first phase that is not `done`/`skipped` |

**Resume rule (the same as the wizard's):** resume from the frontmatter **only when
`answers_confirmed_at` is present**. Without it the routing was never confirmed — say so in one line,
then re-show the table with the recorded rows as candidates. Without `up_front_confirmed_at`, re-run
the one sitting before any delegate starts. Resuming from a just-rejected state file treats it as an
accepted answer set and skips the re-asking entirely, which leaves deleting the state directory by
hand as the only way out.

---

## Delegation discipline

**Each phase names the skill that owns it and what it hands over. This skill never re-implements
harvesting, design or data mechanics.**

- A phase report names the skill it delegated to and what came back — never a description of work you
  did in its place.
- **Do not restate a delegate's rules here.** `match-reference-design` owns the cost ladder and the
  echo rule; `demo-run-sheet` owns the output-format question; `harvest-source-materials` owns how
  material is fetched and recorded; `project-data` owns every row under `data/import/**`. A restated
  rule drifts apart from its source.
- **What crosses a boundary is an artifact:** the intake block, the needs list,
  `spec.md`, the harvest coverage record, `.ai-dev/rehearsal.md`. If a delegate needs something that exists only
  in this conversation, write it to the state file's `## Required follow-ups` first — a resume hours
  later has the file and not the chat.
- **`harvest-source-materials` runs as its own skill.** Hand it the phase-2 needs list and the
  phase-3 spec, and take its coverage record; do not pre-fetch, do not fetch on its behalf, and do not reach into the
  source site yourself.

---

## What "prepared" means

The closing checklist. State each line at the close, with its evidence:

- [ ] **Every beat rehearsed** — walked end to end in the browser as the persona it names, logged-in
      paths delegated to `spryker-verifier`, pass/gap recorded per beat in `.ai-dev/rehearsal.md`.
- [ ] **Every gap named and accepted** — each one listed with the needs-list line it breaks and the
      preparer's acceptance recorded in `gaps_accepted`. A beat may be reported as untested; it is
      never reported as passing on a guess.
- [ ] **The run sheet produced** — by `demo-run-sheet`, in the chosen format, on disk, with its
      path given.
- [ ] **The material recorded with its coverage** — the harvest's coverage record read against the
      phase-2 needs list, with what was not covered stated rather than omitted.

Anything short of all four is reported as what it is. A demo is reported "prepared" only after every
beat was walked end to end.

---

## Pitfalls

Format: **signature → cause → fix.**

- **The demo scope arrives pasted halfway through the build, and every data gap is then found by playing
  the story out by hand** — a category with two products where the story needed a range, a hero product
  with one colour where the story said "show colour options" → the needs the beats create were never
  written down before the data was shaped, so nothing had a requirements list to be checked against →
  phase 2 comes before any harvesting or import; the needs list is the acceptance criterion.

- **The demo is re-designed instead of read** — a set of beats proposed, or the supplied script
  "tidied up" → the preparer already had the demo, and asking them to agree it again delays the run
  and lets the demo drift from the brief → the beats are an input: read them, derive from them, and ask only when
  nothing was supplied at all.

- **Quoted UI strings from the scope replaced with the agent's own phrasing** → the script was
  summarised instead of quoted → every quoted string is carried character for character out of the
  brief, and is fixed wording from that moment for brand-project, the CMS pass and the run sheet.

- **Content work answered with new features — product hotspots, quick view, a bundle builder slipped into
  the look-and-content work** → a demo-script wish was read as part of the restyle → phase 2's rule:
  behaviour the platform lacks is its own customization row, shown to the preparer as "a small working
  version" with its cost, and built only once confirmed.

- **The preparer is asked something they cannot answer** — a work class, a namespace, a rebuild
  strategy, a CI option → a technical decision was handed to the preparer → decide
  it yourself, log it, and ask only about content, look and the story.

- **Harvesting runs, then the design spec lands and asks for editorial panels at ratios nobody fetched
  for** → the composition spec was treated as a look-and-feel step at the end rather than as an input to
  gathering → phase 3 runs before phase 4, because it is what sizes the editorial imagery.

- **The first end-to-end walk of the demo is the preparer's, live** → there is no phase
  between "it booted" and "it is ready" → phase 6: every beat is walked, in the browser, as its
  persona, before the run sheet is written.

- **A phase 5 delegation is denied by `guard-skill.php`** → `.ai-dev/demo-prep.md` carries no
  `answers_confirmed_at`, which means the routing table was never confirmed → do not work around the
  hook and do not stamp the file to clear it: show the table, get the confirmation, then stamp.

- **The wizard's interview is run on the demo clone to collect demo scope** → the two tracks were
  conflated; `project-starter-wizard` collects *project setup* decisions and knows nothing about the
  demo → this skill owns the demo's needs; phase 5 hands the project-setup decisions to the wizard and
  lets it own its own state file and confirmation.
