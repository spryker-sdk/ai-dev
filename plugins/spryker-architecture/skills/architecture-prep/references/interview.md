# The Intake Interview

The quality of the whole document is decided here. Sections are only as good as what you learned, so
the interview is deliberately **broad and front-loaded**: ask for real material first, then run a wide
batched interview, then research to fill what people couldn't answer. Read this before Step 1.

## The question bank lives in [questionnaire.md](questionnaire.md) — this file is HOW to collect it

The canonical list of questions is the standalone, fillable **[questionnaire.md](questionnaire.md)**
(progressive levels L1–L4; each question typed and most carrying a condition naming the earlier
answer that opens it). This file defines the *collection procedure* that feeds those questions in.
Always drive the interview from questionnaire.md so the live
interview, a pre-filled questionnaire, and a TAD all populate the same fields.

## Rule 0 — A structured brief replaces the interview

If the intake already includes a Spryker **TAD** (Target Architecture Definition) or an equivalently
structured architecture brief, **the interview is skipped** — the document IS the intake, and it is
usually richer than a live interview. Read [tad-mapping.md](tad-mapping.md) and follow its fast-path
instead of the rules below. The rest of this file applies only when no structured document exists,
or (Gated mode only) for targeted follow-ups on gaps a provided doc genuinely leaves open.

## Rule 0b — A filled questionnaire also replaces (or shortens) the interview

Before interviewing, check whether the user already gave answers to
[questionnaire.md](questionnaire.md) — as a filled copy (a path or pasted text), or inline in their
request:

- **Fully filled** (all of L1 and L2 answered, including explicit unknowns): treat it exactly
  like a TAD — **skip the interview**, copy the answers verbatim into `intake.md`, and log
  `INTERVIEW | SKIP (questionnaire pre-filled)`.
- **Partially filled:** parse which questions are answered, and **ask ONLY the still-blank ones** —
  never re-ask something already answered. Map each remaining blank to its question ID (Q6, Q13…)
  so the follow-up batches stay coherent.
- **Not provided:** offer the user the choice in Rule 1b before defaulting to a full interview.
- **Derived at Step 0b:** answers pre-filled from the user's own documents
  ([prefill.md](prefill.md)) count as answered here — confirmed ones outright, unconfirmed ones as
  answered-but-flagged. Either way **do not re-ask them.** Re-evaluate the gates against the derived
  set *before* choosing what to ask: a derived answer can close a gate and remove questions from the
  run entirely.

Parsing rule: match answers to question IDs (Q1, Q13, …). A blank, "?", or omitted line = unanswered.
A line saying "unknown"/"TBD"/"n/a" = ANSWERED (an honest gap), not a question to re-ask.

## Rule 1b — Offer "fill the list yourself" vs "interview me"

When no filled questionnaire and no TAD exist, don't just launch into questions. First offer the user
both paths (a single `AskUserQuestion` is fine):

> "I can either (a) hand you the intake questionnaire to fill in at your own pace and then run with no
> interview, or (b) interview you now in a few batched question sets. Which do you prefer?"

- If they choose **fill-it-yourself**: point them at [questionnaire.md](questionnaire.md) (or paste its
  content), tell them **Level 1 alone (Q1–Q9) is enough for a grounded first draft**, and **wait**
  for their filled answers before proceeding. Then treat the result per Rule 0b.
- If they choose **interview**: run the batched interview below.
- In a **fully autonomous / no-questions** run, do not offer the choice — use whatever answers/TAD were
  provided and mark the rest as TODOs.

## Rule 1 — Ask for existing documents first, before any structured questions

The single most valuable input is material the user already has. Before the multi-tab questions, ask
plainly for it and read everything they give you:

> "Before I ask anything, can you share whatever already exists — a business brief / RFP, discovery or
> workshop notes, a requirements doc or backlog, existing diagrams (even photos of a whiteboard),
> slide decks, a solution proposal, relevant ticket or wiki links, or the contract's scope section?
> Paste the text or give me file paths. The more you drop here, the fewer questions I need to ask and
> the more grounded the document will be."

- Read every provided file. Extract answers to the question bank below **from the documents first**,
  and only ask a question when the docs don't already answer it — this respects the user's time and
  makes the interview feel informed, not robotic.
- If a wiki or ticket link is given and a connector for it is available, pull it. If not, ask the user
  to paste the relevant text.
- Note in `intake.md` which facts came from which document, so section writers can cite sources.

## Rule 2 — Batch the questions (the "multi-tab" intake)

Use `AskUserQuestion` with up to 4 questions per call, several calls back-to-back, so the user gets a
carefully structured interview in one pass rather than a slow drip. Keep each call within one level so each
call reads like a coherent "tab" of the interview. Only ask about themes relevant to the **selected**
sections — don't interview about deployment if section 7 wasn't chosen.

Prefer multi-select and give sensible default options with a "(Recommended)" first option where a
common default exists; the user can always choose "Other" to type free-form. For anything open-ended
(volumes, integration specifics, prose descriptions), ask it as an option like "Let me type the
details" and then collect the free text conversationally, or accept that it becomes a `TODO` the user
fills later.

## The question bank = [questionnaire.md](questionnaire.md) — asked level by level

The questions themselves live in [questionnaire.md](questionnaire.md); read it, don't re-type it
here. The bank is **progressive**, not flat:

| Level | Count | Rule |
|---|---|---|
| **L1** — high-level vision | 9 | Always ask, always first. On its own it yields a grounded first draft. |
| **L2** — core foundation | 21 | Always ask, unless the run is explicitly L1-only. This is what makes the doc decision-grade. |
| **L3** — design intelligence | 21 | **Never ask wholesale.** Every question is gated by an L1/L2 answer. |
| **L4** — full | 2 | Only for a hand-over-grade document. |

Run-configuration — which sections, autonomy mode, output target — is **not**
in the bank. It is architecture-run plumbing, asked in the SKILL.md Step 0 opening batch. Don't look
for it here and don't invent questionnaire IDs for it.

### Rule 2a — Walk the levels in order, and honour the gates

**Do not flatten the bank into themed tabs.** The level order is the interview order, because L3
questions are unanswerable until their gate is known.

1. **Ask L1 first** (2–3 `AskUserQuestion` calls). Q1 — existing documents — leads, per Rule 1.
2. **Evaluate gates**, then ask L2. Several L2 questions are already suppressed by L1 answers.
3. **Evaluate gates again**, then ask **only the L3 questions whose gate actually opened.** A single-
   market project answering "standard" to all four capability gates skips almost all of L3 — that is
   the design working, not a shortfall.
4. **L4 only if** the user asked for a complete/hand-over document.

The gates, in one place:

| Gate answer | Opens |
|---|---|
| `Q6` ≠ "no orders" | Q15, Q18 |
| `Q15` ≠ "no payment" | Q17 |
| `Q7` names 2+ systems | Q19 |
| `Q5` includes business buyers | Q22 |
| `Q5` includes third-party sellers | Q23 |
| `Q4` ≠ new build | Q24, Q27, Q50 |
| `Q13` ≠ standard *(pricing gate)* | Q31, Q32 |
| `Q14` ≠ standard and ≠ not applicable *(availability gate)* | Q33, Q34, Q35, Q36 |
| `Q16` ≠ standard *(assortment gate)* | Q37 |
| `Q8` lists >1 market | Q38, Q39, Q40, Q41, Q42 |
| `Q21` ≠ nobody | Q51 |

**Never ask a question whose gate is closed.** It signals you weren't listening, and it burns the
user's patience before the questions that matter.

### Rule 2b — Respect the answer types

The bank types every question. Map them to the tool honestly:

- **`pick`** → `AskUserQuestion`, options as written. Most picks carry `Other — please describe`, and
  the ones where the answer may genuinely not exist yet also carry `Too complex to decide now —
  needs investigation` and/or `Not decided yet` — **reproduce these exactly, never drop them.** They
  are load-bearing (see Rule 2c). `AskUserQuestion` always adds its own "Other" escape, so a user is
  never trapped; but do not *invent* an unknown option on a question the bank didn't give one.
- **`shorttext` / `number`** → an option like "Let me type it" then collect free text.
- **`document`** → ask for a path or paste; read everything provided before continuing.
- **`confirm`** (Q29, Q30) → **not a question.** Show what you derived and invite correction.
  Q29 gates diagram work: confirm the context diagram *before drawing anything else*. Q30 runs
  *after* generation, on the derived risks.
- **`table`** → see Rule 2d.

### Rule 2c — The answer conventions are behaviour, not decoration

**Two kinds of unknown, and they are not the same** — this is the bank's own framing, and it decides
where the answer lands in the deliverable:

| Answer | What you must then do |
|---|---|
| `Too complex to decide now — needs investigation` | Recommend a **Solution Design** and create the stub in `04-solution-designs/`. Never write a paragraph pretending the decision is made. |
| `Not decided yet` | Record an **open item** with an owner and a decide-by date; promote to §11 if it is launch-blocking. |
| `Other — please describe` | Carry the free text verbatim; flag it for the reviewer. |
| No answer at all (skipped, or the user simply doesn't know) | Treat as `Not decided yet`. **Assert no default.** The element is still drawn, but **provisionally and labelled as such.** |

Neither kind of unknown is ever silently dropped, and neither is ever resolved by inventing a
plausible value.

**Derive the open-items list from the unknowns above.** The bank does not ask "which decisions are
still open?" — that list is your output, not a question. Skip the derivation and the information is
lost.

### Rule 2d — Four questions are homework files, not interview questions

`Q17` (payment methods per market), `Q20` (the integration arrows), `Q25` (volumes per phase), and
`Q26` (targets per phase) are too large to ask live.

For each: **emit a pre-filled file** — `Q20` pre-filled from the systems named in `Q7`, `Q25`/`Q26`
with one column per phase named in `Q8` — **assign an owner and a return date, and continue without
it.** Note in `intake.md` that it is outstanding.

These four are the most common reason a finished document is thin. Chase them early, and tell the
user plainly that §03 and §10 stay shallow until they come back.

### Rule 2e — Partial questionnaires

When only SOME questions are unanswered (per Rule 0b), batch just those, labelling each by its
questionnaire ID (`Q6`, `Q13`…) — and still evaluate the gates, so you don't ask a blank question
whose gate turns out to be closed.

## After the interview — always write answers to the input artifact

**Every answer collected — however it arrived (live interview, a filled/partly-filled questionnaire,
or extracted from a provided doc/TAD) — is written down to `intake.md` under the run directory.** This
is non-negotiable: `intake.md` is the single shared input every section-writer teammate reads, and the
run's durable record of what was actually said. Rules:
- Organize `intake.md` by level (L1–L4) and the arc42 section each answer feeds, keyed by question
  ID (Q1, Q13, …) so nothing is lost and gaps are visible. Record gated-out questions explicitly as
  `skipped — gate closed (<gate answer>)`, so a reader can tell "not applicable" from "never asked".
- Record each of the four homework files (Q17, Q20, Q25, Q26) as `outstanding — owner <x>, due <date>`
  until it comes back.
- Note the **source** of each fact: `interview`, `questionnaire (pre-filled)`, `<document name>`, or
  `unknown — TODO`. Section writers cite these sources.
- Capture answers as they come in during a live interview (append after each batch), so a long or
  interrupted interview never loses what was already answered.
- If a whole selected section has almost no input, say so to the user (Gated) or record it as a
  TODO-heavy section (Autonomous) — never pad it.
