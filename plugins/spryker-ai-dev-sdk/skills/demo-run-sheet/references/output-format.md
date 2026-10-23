# Output format — what survives a paste, and the HTML template

## Contents

- [The paste contract](#the-paste-contract)
- [What survives, what does not](#what-survives-what-does-not)
- [HTML template](#html-template)
- [Markdown](#markdown)

Read this before writing the report. With the `html` format, the person puts the report into a
document in one paste, without re-formatting anything.

## The paste contract

The person opens the HTML file in a browser, presses select-all, copies, and pastes into an
**existing** Google Doc (or Confluence page, or Word). What arrives must keep:

- **headings as headings** (the doc's own outline/navigation picks them up),
- **tables as tables** (cells, not tab-separated text),
- **links as clickable links**,
- bold/emphasis and the status labels where they carry meaning.

Everything else is decoration and may be lost.

## What survives, what does not

| Survives a rich-text paste | Does NOT survive — do not rely on it |
|---|---|
| `<h1>` / `<h2>` / `<h3>` (become the doc's heading styles) | CSS classes and `<style>` blocks (strip or apply unpredictably) |
| `<table>` / `<tr>` / `<td>` / `<th>` with **inline** `style="border:…;padding:…"`, and `colspan` | `display:flex` / `grid` / `float` / `position` — layout collapses |
| `<a href="https://…">` (absolute URLs only) | relative URLs (`/DE/en/x`) — they paste as dead text |
| `<b>` / `<strong>`, `<i>` / `<em>`, `<u>` | `<pre>` / `<code>` blocks — arrive as one run of unstyled text |
| `<ul>` / `<ol>` / `<li>`, nested at most two deep | deep nesting, custom bullets, `<details>`, `<figure>` |
| inline `style="color:…;background-color:…;font-family:…"` on the element itself, including a `<span>` | external stylesheets, web fonts, SVG, icon fonts |
| `<p>` paragraphs | `<br>`-only spacing for structure |

Two consequences:

1. **Every style is an inline `style="…"` attribute on the element that needs it.** No classes.
2. **Every URL in an `href` is absolute**, including the scheme and host resolved from the deploy
   file — a relative path is not clickable anywhere outside the shop.

## HTML template

Write to `.ai-dev/demo-handover-report.html`, then open it in the browser so the person can
select-all and copy. The content is the template in `SKILL.md` § 6; substitute the real values and
keep the structure. Every cell carries the same inline style: `border:1px solid #999;padding:6px;
vertical-align:top;` (header cells and group rows add `text-align:left;background-color:#f2f2f2;`).
The snippet shortens it to `…` after the first row; the file writes it out on every cell.

```html
<!doctype html>
<html><head><meta charset="utf-8"><title>ACME × Spryker demo</title></head>
<body style="font-family:Arial,Helvetica,sans-serif;font-size:11pt;color:#000;">

<h1 style="font-size:20pt;">ACME × Spryker demo</h1>

<h2 style="font-size:15pt;">1. Environment</h2>
<table style="border-collapse:collapse;width:100%;">
  <tr>
    <th style="border:1px solid #999;padding:6px;vertical-align:top;text-align:left;background-color:#f2f2f2;">What</th>
    <th style="…">Link</th>
    <th style="…">Note</th>
  </tr>
  <tr>
    <td style="border:1px solid #999;padding:6px;vertical-align:top;">Shop Germany</td>
    <td style="…"><a href="https://www.demo.acme.example/DE/de">https://www.demo.acme.example/DE/de</a></td>
    <td style="…">Always keep <b>/DE/de</b> or <b>/DE/en</b> at the start of a link</td>
  </tr>
</table>
<p>The environment asks for a site password first (browser pop-up). Have it ready.</p>

<h2 style="font-size:15pt;">2. Demo accounts</h2>
<p>All demo accounts share one password, handed over separately.</p>
<!-- table: Person | Login | Company / role | Use for | Do not -->

<h2 style="font-size:15pt;">3. Products</h2>
<!-- table: Product | Link (<a href="…">open</a>) | Why | Stock per warehouse | Price -->

<h2 style="font-size:15pt;">4. Categories</h2>
<!-- table: Category | Link | Note -->

<h2 style="font-size:15pt;">5. Demo points</h2>
<p><b>Ready</b> = tested in the shop · <b>Ready, with a note</b> = tested; one point is named in the
row · <b>Not yet tested</b> · <b>Not built yet</b> = planned · <b>Not built</b> = not part of this demo
build; the row says why</p>
<table style="border-collapse:collapse;width:100%;">
  <tr>
    <th style="…">Point</th><th style="…">How to show</th><th style="…">Status</th>
  </tr>
  <tr>
    <td colspan="3" style="border:1px solid #999;padding:6px;vertical-align:top;text-align:left;background-color:#f2f2f2;"><b>Use case 1 — ACME Retail</b></td>
  </tr>
  <tr>
    <td style="…"><b>Anna orders up to €500: placed directly</b></td>
    <td style="…">Log in as Anna Buyer (anna.buyer@acme-retail.example) →
      <a href="https://www.demo.acme.example/DE/de/drill-x200-acme-dr-200">Drill X200</a>, add 2 →
      checkout → <i>Buy now</i>. The order is placed without approval.</td>
    <td style="…"><span style="color:#1f7a3a;background-color:#e4f3e8;font-weight:bold;padding:2px 6px;">Ready</span></td>
  </tr>
</table>

<h2 style="font-size:15pt;">6. Watch out during the demo</h2>
<ul>
  <li>Paul cannot place orders. Switch back to Anna before the checkout point.</li>
</ul>

<h2 style="font-size:15pt;">7. How each part is built</h2>
<!-- table: Feature | How it is done; a colspan="2" group row per area -->

</body></html>
```

Status labels, as a `<span>` with these inline styles:

| Status | `style` |
|---|---|
| Ready | `color:#1f7a3a;background-color:#e4f3e8;font-weight:bold;padding:2px 6px;` |
| Ready, with a note | `color:#1f7a3a;background-color:#eef6e8;font-weight:bold;padding:2px 6px;` — the note follows the label in the same cell |
| Not yet tested | `color:#8a5a00;background-color:#fdf3e1;font-weight:bold;padding:2px 6px;` |
| Not built yet | `color:#1f4f8a;background-color:#e6eef8;font-weight:bold;padding:2px 6px;` |
| Not built | `color:#4f4e48;background-color:#f4f3ef;font-weight:bold;padding:2px 6px;` |

## Markdown

Write `.ai-dev/demo-handover-report.md` exactly as the template in `SKILL.md` § 6: plain CommonMark
with pipe tables, a group row as `| **Group** | | |`, the status as plain text, and every link
absolute.
