# Pre-fill — derive answers before asking

**Read at Step 0b, after documents are collected and before any question is asked.**

**Never ask a person something the material they already gave you answers.**

## Sources

Derive from the material the user supplied, in this order:

1. **Documents the user provided** at Q1 — RFP, tender response, requirements doc, discovery notes,
   interface specs, slide decks, diagrams.
2. **A structured brief** that is not a full TAD. A TAD triggers the
   [tad-mapping.md](tad-mapping.md) fast-path instead, which replaces the interview outright.
3. **An `architecture/` folder that is already partly filled** — a previous run, or hand-written
   sections. Read what is genuinely project content; ignore the shipped template examples (SAP,
   Akeneo, Auth0, the sample volume table) — they are illustrative, not this project's facts.
4. **Anything else the user explicitly points you at** — including project files, if they ask for
   the architecture to be derived from what they already run.

With no documents provided, **skip this step** and go straight to the interview.

## How it runs — one Explore teammate

Spawn a single `subagent_type: "Explore"` teammate. It reads the provided material and the question
bank, and returns **only** a compact derivation table — never the documents' contents.

Its brief:

> Read the documents at `<paths>` and `references/questionnaire.md`. For every question in L1 and L2
> you can answer **from the documents alone**, return one row: question ID, the proposed answer, a
> verbatim quote or precise location backing it, and a confidence of `high` or `low`. Omit any
> question the documents don't address — a short honest table beats a long speculative one. Never
> infer a target-state decision from a description of the current system.
> Return the table only; write nothing to the deliverable.

## Confidence — two levels

| Level | Meaning | What happens |
|---|---|---|
| **high** | The document states it plainly. | Shown as a proposed answer to accept. |
| **low** | It's implied, or assembled from more than one place. | Shown explicitly flagged as an inference. |

Do not invent finer gradations — they imply a precision the derivation cannot support.

## Confirmation — one batch, not a stream

Show everything derived in **one** pass, grouped by level, before the interview starts:

> "I read the three documents you gave me and pulled these answers out. Correct anything wrong,
> then I'll only ask about what's genuinely missing."
>
> **From the RFP (high confidence)**
> - **Q4** Replaces a commercial commerce platform — *"migrating away from Hybris by Q3"* (RFP §1.2)
> - **Q8** Markets: DE, AT, CH — *"DACH launch, Germany first"* (RFP §2.1)
>
> **Inferred — please check (low confidence)**
> - **Q13** Pricing: negotiated per customer — the RFP mentions "customer-specific contract pricing"
>   in a functional requirement, but never states it as a pricing model.

Rules:

- **One confirmation, not per-question.** The user corrects a list; they don't answer twenty
  questions about answers.
- **Every row carries its evidence.** A derived answer with no quote or location is a guess — drop
  it rather than showing it.
- **Silence is not consent for a low-confidence row.** If the user accepts the batch without
  addressing an inference, it stays labelled *derived, unconfirmed* in `intake.md` and the document
  marks it accordingly. High-confidence rows may be treated as accepted.
- **A correction wins outright.** Never argue the document's version against the person's.

In **Autonomous** mode there is no confirmation stop: high-confidence rows are accepted, low-confidence
rows are logged as CRITICAL DECISIONs and carried as *derived, unconfirmed*.

## Then the interview

Hand the confirmed set to Step 1 as pre-answered questions. The interview then follows
[interview.md](interview.md) Rule 0b exactly as if the user had pre-filled the bank by hand — **it
asks only the blanks**, and re-evaluates the gates first, since a derived answer can close a gate and
remove questions from the run.

Record every answer in `intake.md` with its source, using the vocabulary in
[run-lean.md](run-lean.md). A `derived (unconfirmed)` fact that reaches a finished section without
being marked as an assumption is a review defect.

## What this step must never do

- Ask the user to confirm something no document supports.
- Turn an as-is description into a target-state decision.
- Fill a number (volume, target, date) that is not stated. Volumes come from the homework tables
  (Q25/Q26) or stay open — never from a plausible-sounding sentence.
- Silently overwrite an answer the user already gave. The user's own answer always wins.
