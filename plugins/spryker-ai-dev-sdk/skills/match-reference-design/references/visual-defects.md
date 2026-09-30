# Visual defects — the checklist every design sweep runs

The defects below pass a green build, present markup and a filled `design-acceptance.md` row; only
looking at the page shows them. Each one has a measurable check. Run the list **per
surface and per width** in the §11a sweep, and the rows the change touched at the end of every §4
cycle. A check's result is the evidence token for its sweep row (`sw=768/768, cut=[]`), not the word
"checked".

**Widths:** 390, 768, 900, 1100, 1440 — set with Claude in Chrome's `resize_window`, then read
`innerWidth` back (window width is not viewport width; record the value you got). Add one width just
below any breakpoint where a row changes shape: a row class that scrolls sideways below `$lg` is
designed to do so, and the width just below it is where a cut card shows.

**Before any check:** hard-reload after a build (§7), and load every lazy image first — a grey
placeholder measures like a missing photo:

```js
for (let y = 0; y < document.body.scrollHeight; y += 600) { scrollTo(0, y); await new Promise(r => setTimeout(r, 150)); }
scrollTo(0, 0); [...document.images].filter(i => !i.complete || !i.naturalWidth).map(i => i.currentSrc || i.src)   // []
```

Replace `<…>` selectors with the ones the page actually uses (read them off the rendered DOM). A
snippet that declares a helper (`const vis = …`) can collide with itself on a second run in the same
page — reload first.

---

## (a) Cut faces and heads

A photo in a fixed-ratio box is cropped from the centre, so a person loses the top of the head. Two
causes, often together: the file itself was cropped through the head (`harvest-source-materials` §7),
and the box ratio does not match the photo.

**Check:** rendered ratio vs natural ratio — more than ~5 % apart under `object-fit: cover` means
something is cut (under `contain` it means empty bars, see (b)). Then zoom-screenshot every image in
its box and confirm head, face and — when in shot — feet are inside.

```js
[...document.images].filter(i => i.getBoundingClientRect().width > 80 && i.naturalWidth).map(i => {
  const r = i.getBoundingClientRect(), box = r.width / r.height, nat = i.naturalWidth / i.naturalHeight;
  return { src: (i.currentSrc || i.src).split('/').pop(), box: +box.toFixed(2), nat: +nat.toFixed(2), fit: getComputedStyle(i).objectFit, pos: getComputedStyle(i).objectPosition };
}).filter(x => Math.abs(x.box / x.nat - 1) > 0.05)
```

Background images (hero bands) — same comparison:

```js
await Promise.all([...document.querySelectorAll('body *')].filter(e => /^url\(/.test(getComputedStyle(e).backgroundImage) && e.getBoundingClientRect().width > 80).map(e => new Promise(res => {
  const im = new Image(), r = e.getBoundingClientRect();
  im.onload = () => res({ el: e.className, box: +(r.width / r.height).toFixed(2), nat: +(im.width / im.height).toFixed(2), pos: getComputedStyle(e).backgroundPosition });
  im.onerror = () => res(null); im.src = getComputedStyle(e).backgroundImage.slice(4, -1).replace(/^["']|["']$/g, '');
})))
```

**Fix, in this order:** the source first (re-crop from the original with the subject in frame); then
the box — `aspect-ratio` = the file's ratio — and `object-position` / `background-position` toward
the subject (a hero with people: about `50% 25%`). A fixed `height` / `min-height` on a flexible-width
box is the usual CSS cause.

## (b) Images not filling their block

Portrait product photos shown `object-fit: contain` in a short box render as a thin strip between
white bars; a PDP main image sits at half size in a padded box.

**Check:** image width ≈ card width (≥ 0.95), box ratio = photo ratio. Run the same snippet on the
reference and compare: **measure the reference's tile and PDP image sizes** and hold the shop to them.

```js
[...document.querySelectorAll('<card selector>')].map(c => { const i = c.querySelector('img'); if (!i) return 'no img';
  const cr = c.getBoundingClientRect(), r = i.getBoundingClientRect();
  return { fill: +(r.width / cr.width).toFixed(2), w: Math.round(r.width), h: Math.round(r.height), box: +(r.width / r.height).toFixed(2), nat: +(i.naturalWidth / i.naturalHeight).toFixed(2), fit: getComputedStyle(i).objectFit };
})
```

**Default for product tiles:** full-bleed, no inner padding, `aspect-ratio: <the photos' ratio>;
object-fit: cover` (`2/3` for a catalogue of consistent 2:3 portraits) — only when the product photos
share one ratio; with mixed ratios, keep `contain` and give the box the ratio most photos have.

## (c) Images not aligned

One card still grey or lazy, or tiles of different heights in one row.

**Check:** per visual row, every image box has the same top and height (after the lazy-load scroll
above).

```js
[...document.querySelectorAll('<row selector>')].map(row => { const b = [...row.querySelectorAll('img')].map(i => i.getBoundingClientRect()).filter(r => r.width);
  return { tops: new Set(b.map(r => Math.round(r.top))).size, heights: new Set(b.map(r => Math.round(r.height))).size };   // both 1 per row
})
```

## (d) Empty blocks

A fixed-size panel with nothing in half of it, a footer strip of broken sprite icons (`<use
href="…sprite.svg#:">` from an empty `css_class`), a heading with nothing under it. Run it with each
dropdown **open** — a closed panel measures 0×0.

```js
const vis = e => { const r = e.getBoundingClientRect(), s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.opacity !== '0'; };
({
  brokenIcons: [...document.querySelectorAll('svg use')].map(u => u.getAttribute('href') || u.getAttribute('xlink:href') || '').filter(h => /#:?$/.test(h)),
  emptyBoxes: [...document.querySelectorAll('body *')].filter(e => { const r = e.getBoundingClientRect(), m = 'img,svg,video,picture,iframe,canvas,input,button,select,textarea';
    return e instanceof HTMLElement && vis(e) && r.width > 100 && r.height > 100 && !e.innerText.trim() && !e.matches(m) && !e.querySelector(m) && getComputedStyle(e).backgroundImage === 'none'; }).map(e => e.className).slice(0, 20),
  headingOnly: [...document.querySelectorAll('h1,h2,h3,h4')].filter(h => vis(h) && ![...h.parentElement.children].some(s => s !== h && vis(s) && ((s.innerText || '').trim() || s.querySelector('img,svg')))).map(h => h.innerText.trim()),
})   // all three []
```

**Fix:** fill it, hide it, or size it by content (`width: max-content; height: auto`) — §11b.

## (e) Affordances that promise nothing

Chevrons on menu rows with no submenu; a "browse by category" header over a two-item list.

```js
[...document.querySelectorAll('<menu row selector>')].filter(row => row.querySelector('svg use[href*="arrow"], svg use[href*="chevron"], <chevron selector>') && !row.querySelector('ul li, <submenu selector>')).length   // 0
```

## (f) Navigation structure not like the reference

Top-level entries that should be parents rendered as siblings of their own children; the same
category twice (menu and filter tree). **Before the first design edit**, print the shop's menu tree
and the PLP filter tree next to the reference's menu tree (same snippet on the reference):

```js
const tree = (ul, d = 0) => [...ul.children].flatMap(li => { const a = li.querySelector('a'), sub = li.querySelector('ul');
  return [`${'  '.repeat(d)}${a ? a.innerText.trim() : li.innerText.trim().split('\n')[0]}`, ...(sub && sub !== ul ? tree(sub, d + 1) : [])]; });
tree(document.querySelector('<top-level menu ul>')).join('\n')
```

**Rule:** a category that is a subset of another does not sit beside it at the same level. The tree is
data — a structure change is a `project-data` change, made explicitly (§5), never a template trick.

## (g) Loose, unstyled CMS text

A CMS line rendered as bare paragraph text between two designed bands. **Check:** walk the homepage's
bands (the §2 map) — a band with text but no image, a transparent background and default typography,
between two designed bands, is a finding: put it in a styled container or remove it.

```js
[...document.querySelectorAll('<band selector>')].filter(b => b.getBoundingClientRect().height).map(b => { const s = getComputedStyle(b);
  return { band: b.className, text: b.innerText.trim().slice(0, 40), img: b.querySelectorAll('img').length, bg: s.backgroundColor, font: s.fontSize + ' ' + s.fontWeight };
})
```

## (h) Low contrast on a dark header

After theming the header dark, components that carry their own colours keep dark-grey text (for the
logged-in components, see `brand-project` → dark header). **Check:** every text and icon in the header
and nav ≥ 4.5:1 against its background, **for the guest and the logged-in state** (logged in via
`spryker-verifier` — this skill does not log in).

```js
const lum = c => { const [r, g, b] = c.match(/[\d.]+/g).slice(0, 3).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
const bgOf = e => { for (; e; e = e.parentElement) { const c = getComputedStyle(e).backgroundColor; if (!/^rgba\(.*,\s*0\)$|^transparent$/.test(c)) return c; } return 'rgb(255, 255, 255)'; };
const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m); return (x + 0.05) / (y + 0.05); };
[...document.querySelectorAll('<header root> *')].filter(e => e.getBoundingClientRect().width && (e.tagName === 'svg' || [...e.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())))
  .map(e => { const s = getComputedStyle(e), fg = e.tagName === 'svg' && /^rgb/.test(s.fill) ? s.fill : s.color;
    return { t: (e.innerText || e.getAttribute('class') || '').trim().slice(0, 30), fg, bg: bgOf(e), r: +ratio(fg, bgOf(e)).toFixed(2) }; })
  .filter(x => x.r < 4.5)   // []
```

A background image or gradient behind the header is not read by `bgOf` — zoom-screenshot those.

## (i) Layout breaks between phone and desktop

Rows that turn into sideways strips and cut the second card at the screen edge; an arrow that pushes
the page wider than the screen. **Check at every sweep width:**

```js
const box = document.querySelector('.container')?.getBoundingClientRect() ?? { right: innerWidth };
({ w: innerWidth, sw: document.documentElement.scrollWidth,   // sw === w
   cut: [...document.querySelectorAll('<card selector>')].filter(c => { const r = c.getBoundingClientRect(); return r.width && (r.right > Math.min(innerWidth, box.right) + 1 || r.left < -1); }).map(c => c.className).slice(0, 10) })   // []
```

A card cut inside an `overflow-x: auto` row does not widen the page, so `sw === w` alone misses it —
the `cut` list catches it. It is a finding unless the reference scrolls the same row at that width.
A row modifier that scrolls below a breakpoint (a `grid--sm-scroll`-style class) can win over a
project rule on `> .col` — read which rule applies in the computed styles, and change the class at the
include site rather than out-specifying it in CSS.

## (j) Report only after looking

A measurement alone does not show that an item above is fixed. **A cycle ends with a zoomed screenshot of the band that changed, at each width, next to
the reference screenshot** — the measurement says where to look; the screenshot is the evidence.
