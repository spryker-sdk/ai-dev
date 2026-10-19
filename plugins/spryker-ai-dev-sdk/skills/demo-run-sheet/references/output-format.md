# Output format — what survives a paste, and the two templates

## Contents

- [The paste contract](#the-paste-contract)
- [What survives, what does not](#what-survives-what-does-not)
- [HTML template](#html-template)
- [Plain-text fallback](#plain-text-fallback)
- [Other targets](#other-targets)

Read this before writing the artefact. The person puts the sheet into the document the customer
will see in one paste, without re-formatting anything.

## The paste contract

The person opens the HTML file in a browser, presses select-all, copies, and pastes into an
**existing** Google Doc (or Confluence page, or Word). What arrives must keep:

- **headings as headings** (the doc's own outline/navigation picks them up),
- **tables as tables** (cells, not tab-separated text),
- **links as clickable links**,
- bold/emphasis where it carries meaning.

Everything else is decoration and may be lost.

## What survives, what does not

| Survives a rich-text paste | Does NOT survive — do not rely on it |
|---|---|
| `<h1>` / `<h2>` / `<h3>` (become the doc's heading styles) | CSS classes and `<style>` blocks (strip or apply unpredictably) |
| `<table>` / `<tr>` / `<td>` / `<th>` with **inline** `style="border:…;padding:…"` | `display:flex` / `grid` / `float` / `position` — layout collapses |
| `<a href="https://…">` (absolute URLs only) | relative URLs (`/DE/en/x`) — they paste as dead text |
| `<b>` / `<strong>`, `<i>` / `<em>`, `<u>` | `<pre>` / `<code>` blocks — arrive as one run of unstyled text |
| `<ul>` / `<ol>` / `<li>`, nested at most two deep | deep nesting, custom bullets, `<details>`, `<figure>` |
| inline `style="color:…;background-color:…;font-family:…"` on the element itself | external stylesheets, web fonts, SVG, icon fonts |
| `<p>` paragraphs | `<br>`-only spacing for structure |

Two consequences:

1. **Every style is an inline `style="…"` attribute on the element that needs it.** No classes.
2. **Every URL in an `href` is absolute**, including the scheme and host resolved from the deploy
   file — a relative path is not clickable anywhere outside the shop.

## HTML template

Write to `.ai-dev/demo-run-sheet.html`, then open it in the browser so the person can select-all
and copy. Substitute the real values; keep the structure.

```html
<!doctype html>
<html><head><meta charset="utf-8"><title>Demo run sheet</title></head>
<body style="font-family:Arial,Helvetica,sans-serif;font-size:11pt;color:#000;">

<h1 style="font-size:20pt;">Demo run sheet — ACME B2B</h1>
<p style="font-size:10pt;color:#555;">Store DE · locale en · currency EUR · built 2026-09-21 ·
every link and login on this sheet was opened and tested.</p>

<h2 style="font-size:15pt;">1. Environment</h2>
<table style="border-collapse:collapse;width:100%;">
  <tr style="background-color:#f2f2f2;">
    <th style="border:1px solid #999;padding:6px;text-align:left;">Surface</th>
    <th style="border:1px solid #999;padding:6px;text-align:left;">Link</th>
    <th style="border:1px solid #999;padding:6px;text-align:left;">Note</th>
  </tr>
  <tr>
    <td style="border:1px solid #999;padding:6px;">Storefront</td>
    <td style="border:1px solid #999;padding:6px;"><a href="https://yves.eu.acme.dev/DE/en/">https://yves.eu.acme.dev/DE/en/</a></td>
    <td style="border:1px solid #999;padding:6px;">Always keep the <b>/DE/en</b> prefix</td>
  </tr>
  <tr>
    <td style="border:1px solid #999;padding:6px;">Back Office</td>
    <td style="border:1px solid #999;padding:6px;"><a href="https://backoffice.eu.acme.dev/">https://backoffice.eu.acme.dev/</a></td>
    <td style="border:1px solid #999;padding:6px;">Only for the order-check beat</td>
  </tr>
</table>

<h2 style="font-size:15pt;">2. Personas</h2>
<table style="border-collapse:collapse;width:100%;">
  <tr style="background-color:#f2f2f2;">
    <th style="border:1px solid #999;padding:6px;text-align:left;">Login</th>
    <th style="border:1px solid #999;padding:6px;text-align:left;">Password</th>
    <th style="border:1px solid #999;padding:6px;text-align:left;">Company / unit / role</th>
    <th style="border:1px solid #999;padding:6px;text-align:left;">Use for</th>
    <th style="border:1px solid #999;padding:6px;text-align:left;">Do NOT use for</th>
  </tr>
  <tr>
    <td style="border:1px solid #999;padding:6px;">buyer@acme.dev</td>
    <td style="border:1px solid #999;padding:6px;">change123</td>
    <td style="border:1px solid #999;padding:6px;">Acme GmbH · Unit North · Buyer</td>
    <td style="border:1px solid #999;padding:6px;">Catalogue, contract price, cart, <b>place order</b> (verified — order 1042)</td>
    <td style="border:1px solid #999;padding:6px;">Company administration screens — 403</td>
  </tr>
  <!-- consumer / both demo: a guest row (no login) and a shopper row (no company), like this -->
  <tr>
    <td style="border:1px solid #999;padding:6px;">Guest — no login</td>
    <td style="border:1px solid #999;padding:6px;">—</td>
    <td style="border:1px solid #999;padding:6px;">—</td>
    <td style="border:1px solid #999;padding:6px;">Prices, add to cart, <b>guest checkout</b> (verified — order 1043)</td>
    <td style="border:1px solid #999;padding:6px;">Anything behind a company account</td>
  </tr>
</table>
<!-- Company / unit / role and the business rows apply when audience is business or both. -->

<h2 style="font-size:15pt;">3. Product walk</h2>
<h3 style="font-size:12pt;">1 · Product Alpha —
  <a href="https://yves.eu.acme.dev/DE/en/product-alpha-1001">open</a></h3>
<ul>
  <li><b>Why:</b> the contract-price beat.</li>
  <li><b>In stock:</b> S, M, XL. <b style="color:#b00;">Out of stock: L, XXL</b> — do not pick them.</li>
  <li><b>Price:</b> list 249.00 EUR · Buyer's contract price 199.20 EUR (visible after login only).</li>
</ul>

<h2 style="font-size:15pt;">4. Categories</h2>
<!-- one table: category | link | products visible | facets that narrow | avoid -->

<h2 style="font-size:15pt;">5. Script beats</h2>
<ol>
  <li><b>"Show the branded storefront"</b> — storefront home; point at header and hero.</li>
  <li><b>"Find a product as a logged-in buyer"</b> — log in as Buyer →
      <a href="https://yves.eu.acme.dev/DE/en/category-a">Category A</a> → Product Alpha.</li>
  <li><b>"Show the approval flow"</b> —
      <span style="color:#b00;">[UNTESTED — approval workflow not configured in this environment]</span></li>
</ol>

<h2 style="font-size:15pt;">6. Traps — what to avoid on stage</h2>
<ul>
  <li>The Approver persona <b>cannot place an order</b> — switch back to Buyer before beat 4.</li>
  <li>The AT store has no prices imported — do not switch stores on stage.</li>
</ul>

</body></html>
```

## Plain-text fallback

Write `.ai-dev/demo-run-sheet.txt` in the same run, for Slack/chat/email. Rules: no pipes, no
markdown syntax, two-space indentation for structure, one full absolute URL per line so every client
auto-links it, and the same content — including the `[UNTESTED — …]` markers and the out-of-stock
variants. The skeleton in `SKILL.md` § 6 is exactly this file's shape.

## Other targets

Every target also leaves a **local copy on disk** — `.ai-dev/demo-run-sheet.html`, `.md` or `.txt`
(the wizard marks the step `done` only once one exists).

- **Wiki page** (`markdown`) — the only case where markdown is the right answer. Write
  `.ai-dev/demo-run-sheet.md` in the flavour the preparer named; if none was named, plain CommonMark —
  never a question mid-run, and never GitHub-only extensions.
- **A fresh document** (`doc`) — if a document connector is available in the session, create the
  document directly and put the same structure in it; the paste contract above does not apply; the
  content rules still do. Write the local `.html` as well — the connector document is extra, never
  instead.
- **Chat message** (`chat`) — the plain-text file's content, pasted in, with `.ai-dev/demo-run-sheet.txt`
  written too; keep it under one screen by cutting the product walk to the demoed products only, never
  by dropping the traps section.
