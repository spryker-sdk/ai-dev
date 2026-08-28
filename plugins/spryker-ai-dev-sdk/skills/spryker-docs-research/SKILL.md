---
name: spryker-docs-research
description: Research and summarize official Spryker public documentation — module behavior, configuration options, API/Glue references, architecture concepts, supported actors, feature/PBC names, and integration/installation guides. Use this whenever a task needs to understand how a Spryker feature, module, or pattern works according to the official docs BEFORE planning, writing a PRD, or implementing — for example "how does the OMS state machine work", "what are the installation steps for MerchantPortal", "is this behavior supported in Spryker", or any time you're about to describe Spryker behavior from memory. The product-requirement-document skill invokes this to ground PRDs in documented reality. Searches docs via MCP tools (Algolia, context7) or a no-MCP curl fallback and returns relevant documented content with source links. Docs-only — it does NOT read the installed codebase, modules, transfers, or endpoint paths.
---

## Why this exists

Spryker is large, versioned, and convention-heavy. Guessing feature names, supported capabilities, actors, or documented behavior from memory produces requirements and plans that don't match the real platform. This skill grounds feature work in the **official public documentation** — the authoritative statement of what Spryker supports and recommends.

Run this research **first**, before any design, planning, or code investigation, so everything downstream is anchored in documented reality.

**Scope:** public documentation only. This skill does not read the installed codebase, modules, transfers, or endpoints — that belongs to a separate code-investigation step. Its job is to establish the official concept, terminology, supported actors, and documented behavior.

## Required tools — check availability first

This skill depends on MCP tools. **At the start, verify each is available. If any is missing, tell the user explicitly and suggest enabling it before continuing** — degraded research leads to wrong requirements.

Tools are referenced below by **tool name + the kind of MCP server that provides them**. Match tools by their **tool name**, not by a server name — **MCP server names are install-specific** (either may be named differently on a given setup). How a tool is invoked is also client-specific (e.g. some clients namespace it as `mcp__<server>__<tool>`); use whatever calling convention your client uses for the named tool.

| Tool | Provided by | Purpose | If missing, say |
|------|------------|---------|-----------------|
| `searchAlgoliaDocumentation` | Spryker tooling server | Search official docs (returns doc paths + GitHub URLs) | "Algolia doc search unavailable — enable the Spryker tooling MCP server." |
| `query-docs` | docs server (often `context7`) | Query the official Spryker docs corpus. **Always pass the fixed library ID `/spryker/spryker-docs`** (from `https://context7.com/spryker/spryker-docs`) — never call `resolve-library-id`. | "The docs MCP server (e.g. `context7`) isn't connected — enable it for richer documentation research." |

Do not silently skip a missing tool. Note it, then proceed with whatever remains and flag the gap in your findings.

**No MCP server at all?** You can still research: option (c) below searches the docs over plain `curl` against the public Algolia DocSearch API — no MCP required. Prefer the MCP tools when connected (richer, no public-key dependency); fall back to (c) otherwise.

## Running as one of several parallel research agents

Callers often fan this skill out across concepts. If your prompt states a scope boundary ("agent B covers Product Lists — don't"), **respect it**: research and report only your assigned concept, and where a neighbouring concept is clearly relevant, name it in one line under Open questions instead of researching it — duplicated deep-dives across parallel agents have cost ~300k tokens for the same ground. If your prompt states no boundary and the topic obviously spans concepts, say in your output which slice you covered so the caller can see the seams.

## Research workflow

Goal: understand the official concept, terminology, supported actors, and documented capabilities. Two complementary search options are available — use whichever are available, and combine them: Algolia finds the exact doc pages, and the docs corpus gives conceptual depth and examples.

**a) Algolia doc search (MCP)** — `searchAlgoliaDocumentation` (Spryker tooling server). Use **2–3 keyword queries** (the tool ranks short queries best). Run several focused searches rather than one long one:
- One for the domain concept (e.g. `cms block content`)
- One for the actor/area if relevant (e.g. `back office agent`)
- One for the API surface if relevant (e.g. `glue rest checkout`)

Pick only the relevant results and fetch their content from `github_api_url` (raw doc markdown) when you need detail.

**b) Docs corpus query (MCP)** — for conceptual depth and code examples, query the official corpus with `query-docs` (docs server, often `context7`). **Always pass `libraryId: "/spryker/spryker-docs"` directly** (the fixed Spryker docs library, from `https://context7.com/spryker/spryker-docs`). **Never call `resolve-library-id`** — the ID is known and constant, resolving wastes a call and risks selecting the wrong library. Keep to ≤3 queries; make each specific (e.g. "How does Spryker model agent assist for customers in the Back Office").

From this research, **extract the actual documentation content that is relevant to the task** — not just a one-line summary. Pull the passages, steps, lists, config snippets, and constraints the docs state, and keep each tied to the **source URL** it came from. Fetch full page content (via `query-docs` or `WebFetch` on the doc URL) when a snippet isn't enough to answer the task.

## Output format

Return the relevant **documentation content + its source link** — enough that the caller can act on it without re-opening the docs — followed by a short brief. Reproduce the substance (steps, requirements, supported options, constraints), not just headings:

```markdown
## Spryker Documentation Research: <topic>

**Tool availability:** <list any missing tools, or "all available">

### Relevant documentation

#### <Doc page title>
**Source:** <doc url>

<The content from this page that is relevant to the task — the actual steps / requirements / supported options / constraints / config the docs state, quoted or closely paraphrased. Include enough that the caller can act on it. Keep code/config snippets verbatim.>

#### <Next relevant doc page title>
**Source:** <doc url>

<relevant content…>

### Brief (synthesis)
- Feature/PBC name: <name>
- Supported actors: <from docs>
- Key behavior / constraints: <the essentials, each traceable to a Source above>
- Documented endpoints / APIs (if any): <resource> — <doc url>

### Open questions / gaps
- <anything the docs don't answer, that needs codebase investigation or a user decision>
```

Every piece of content carries its **Source** URL. Reproduce documentation substance faithfully (don't water it down to bullet headings), but stay within fair-use — quote what's needed for the task, summarise the rest, and never invent content the docs don't state. Clearly separate what is **documented** from what the docs leave open (flag the latter under Open questions — do not fill it from memory).

## Principles

- **Docs before opinion.** Never describe Spryker behavior from memory when the documentation can confirm it.
- **Always Return comprehensive relevant content, not just pointers.** Hand back the relevant documentation substance (steps, requirements, options, constraints, config) so the caller can act on it — each tied to its source link — rather than a list of URLs to go read.
- **Short Algolia queries** (2–3 words) outperform long ones.
- **Cite sources.** Every piece of content and every claim carries its doc URL.
- **Public docs only.** If a question needs the installed code (exact module names, transfer fields, real endpoint paths), say so in "Open questions / gaps" — that is out of scope here and belongs to a code-investigation step.
- **Docs are not trusted for identifier spellings.** The docs contradict themselves on exact names (`full-text` vs `full_text`, `string-facet` vs `string_facet`). Report the concept, but flag every field/index/route identifier as *"confirm in code or a live mapping"* — never present a doc spelling as the authoritative name.
- **Anything you clone or download is ephemeral.** A docs-repo clone in the scratchpad is a working copy, not a deliverable — say so if you mention it (or clean it up); never report its path as a durable artifact.
- **Surface gaps loudly.** A missing tool or an unanswered question is a finding, not something to paper over.
