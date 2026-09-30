# Demo-intake micro-step — short requests that name a UI symptom

The full procedure behind `SKILL.md` Step 1 → Demo-intake micro-step.

The full intake (PRD, actors, phases) is skipped for a short ask such as "hide this label", but a short ask still gets a one-line intake before any edit. Before any change whose request is short (roughly under 15 words) and names a UI symptom — whether or not the rest of this workflow runs — restate it in **one line, no tool calls**: **who** (actor / store / locale), **where** (the exact page), **what must be true after**. All three known → proceed; otherwise ask for the single missing one — never guess it, never ask for more than one.

Then: **is the stated fix the goal, or a symptom of one?** Typical gaps between the words and the goal: "hide the merchant label on the cart page" can mean *one shipment at checkout instead of two* (emptying the Twig block hides the label and keeps both shipments); "remove asset assignment from b2c customer" can mean *the PDP*, for B2C shoppers only; "removing merchant from added item" can mean *drop the merchant↔product link in the data*, not a cart expander nulling `merchantReference`. When the answer is "symptom", state the goal you infer and confirm it in the same line. A next message that starts with "no", "I meant" or "I asked about…" indicates that the request was misread.

**When the developer names a mechanism, implement that mechanism — or ask exactly one question.** Do not substitute a different lever or argue for one. If the named mechanism genuinely cannot work, say why in one line and ask once; otherwise build what was named. A repeated instruction indicates that the named mechanism was not built.

**The developer's proposed data shape wins over a cleaner alternative unless it cannot work.** "Not needed technically" is a preference, not a blocker, and the shape is the developer's to choose. Arguing a proposed shape down and rebuilding it differently doubles the work.

**Reference supplied → read its structure before its content.** When a screenshot or reference URL arrives with the request, describe its structure in one line before building: how many cells, what each contains, how it is bounded (container vs full-bleed). Content and structure are separate reads of the same reference; reading only the content (headline, copy, image URLs) misses the layout the screenshot shows, such as its grid. If `.ai-dev/composition.md` exists (`brand-project`), build from it. The reference is the acceptance criterion: compare against it before reporting.
