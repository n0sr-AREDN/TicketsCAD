# UI conventions

TicketsCAD is maintained by very few people over a long time, and it has to
read as one application to the dispatcher using it at 3am. This page states the
interface conventions the codebase already follows, with the evidence each one
was derived from, and it is the reference behind the automated gate:

```bash
php tools/ui_consistency_audit.php          # report + exit code
php tools/ui_consistency_audit.php --rules  # one-line statement of every rule
php tools/ui_consistency_audit.php --all    # include baseline-listed findings
```

The audit is wired into `.github/workflows/qa.yml` and the pre-commit hook
(`bash tools/install-git-hooks.sh`, once per clone). It fails **only** on
findings that are not already recorded in `tools/ui_consistency_baseline.txt`,
so existing debt does not block your change but new drift does.

> **Why this is enforced rather than just written down.** These conventions
> were all documented somewhere already, and a widget still shipped with its
> hotkeys as a run of text along the bottom, a dismiss control with no way to
> reopen it, and per-user state in a store invented for the occasion while
> `dashboard_layouts` and `user_screen_prefs` sat unused. Eric, reviewing it on
> a live system: *"I do not want the software to appear as if we have multiple
> developers who have never seen this software before or ever talked to each
> other, working on the same codebase."* Gates are what has actually caught
> things in this project.

None of these rules was invented for the audit. Each one describes what the
majority of the code already does, and a candidate rule that most of the
product violated was discarded rather than enforced — the rejected list is at
the bottom of `tools/ui_consistency_audit.php`.

---

## The dashboard widget

### `widget-registry` — a widget id lives in six places at once

A dashboard tile is described by six parallel lists, and forgetting one is
silent: `widget-manager.js` skips a widget with no `<template>` without a word,
and `api/layout.php` strips ids the permission filter does not recognise.

| Registry | Where |
|---|---|
| `DEFAULT_LAYOUT` — the roster of grid tiles, and the anchor | `assets/js/widget-manager.js:21` |
| `WIDGET_ICONS` | `assets/js/widget-manager.js:49` |
| `WIDGET_LABELS_EN` | `assets/js/widget-manager.js:61` |
| `$__allowedWidgets` (RBAC) | `index.php:552` |
| `DASH_WIDGET_TITLES` (i18n) | `index.php:646` |
| `<template id="tpl-ID">` and a `.widget-toggle` button | `index.php` |

An id in `DEFAULT_LAYOUT` must appear in all of them. An id registered
*outside* `DEFAULT_LAYOUT` gets a single, different finding: that is the shape
of a **floating panel given a toolbar toggle**, which is a legitimate way to
give a non-tile panel a re-open path — baseline it saying so.

### `widget-header` — the header comes from the shared emitter

`assets/js/widget-manager.js:272-282` is the one place a dashboard tile header
is built. It is what gives every widget the same title treatment, the same drag
handle (`handle: '.card-header'`), and the same refresh control. Do not
hand-roll a `card-header` inside a `grid-stack-item`.

### `widget-header-control` — the top-right control is refresh

Every tile gets exactly one header control, appended last so it is always
rightmost:

```html
<span class="widget-refresh text-body-secondary" data-widget="ID" title="Refresh">
  <i class="bi bi-arrow-clockwise"></i>
</span>
```

There is **no per-widget close button**. Showing and hiding is the widgets
toolbar's job (`index.php:133-182` → `toggleWidget()`), which is what makes it
reversible. A bare dismiss control in a panel header leaves the user no way
back — that was the second of the three drifts that prompted this gate.

(A `btn-close` inside a modal is correct and is not flagged.)

### `hotkey-affordance` — keyboard actions are buttons in the header

Three widgets carry action bars, 17 buttons between them, all one shape
(`assets/js/widget-manager.js:196-250`):

```html
<button class="btn btn-xs btn-outline-info responder-action-btn" data-resp-action="view">
  <i class="bi bi-eye me-1"></i><span class="action-label">View</span><kbd>V</kbd>
</button>
```

So: an icon, a label, and the keycap, inside a real button, in the **header**.
Not a legend of `<kbd>` glyphs along the bottom of the panel. The whole bar
carries `d-none` and is shown or hidden as a unit when a row is selected
(`assets/js/app.js:1674-1700`).

This rule is scoped to dashboard panels. `help.php`'s keyboard-shortcut table
is a reference, not a control, and is not flagged.

### `action-bar-css` — a new bar must join the shared selector lists

The compact sizing lives in **`assets/css/action-bar.css`**, which enumerates
the bars by name in three selector groups (`.btn-xs` sizing, `.btn-xs kbd`,
`.action-label`). A bar missing from them falls back to full Bootstrap `.btn`
sizing and towers over the rest of the header.

That has now happened three times — the Responders bar (a beta tester, 2026-06-29),
the Facilities bar (Phase 115), and then the check-in bar, which also exposed
that the rules had been sitting in `widgets.css`, a file `index.php` loads and
`situation.php` does not. Add the bar to the lists; never copy the rules into a
page-local stylesheet.

---

## Everywhere else

### `theme-color` — colours come from Bootstrap variables

Both themes must work. A hardcoded `#hex` on a themed property is the same
colour in dark mode as in light. Use `var(--bs-body-color)`,
`var(--bs-border-color)`, `rgba(var(--bs-emphasis-color-rgb), 0.08)`, or pair
the value with a `[data-bs-theme="dark"]` override.

A hex **inside** a `[data-bs-theme]` block is the theme-specific half of a
correct pair and is not flagged. `print.css` is exempt — print output has no
theme. Inline `style="…#hex…"` is flagged wherever it appears, because no
stylesheet can override it without `!important`.

Measured: 518 `var(--bs-*)` against 289 hardcoded hex on a themed property.
Several of the latter are the Bootstrap palette values typed out by hand
(`#0d6efd`, `#198754`, `#6c757d`), which is the drift in its purest form.

### `control-size` — the compact `-sm` variants

`form-control` and `form-select` take `form-control-sm` / `form-select-sm`.
Dispatch is keyboard-first and screen-dense: speed over beauty, every pixel
earns its place. Measured: 659 of 732 and 298 of 306 already comply.

### `icon-source` — Bootstrap Icons, and only Bootstrap Icons

2305 `bi bi-*` in the tree and not one icon from any other family. A second
icon font is a second download, a second set of metrics, and a visibly
different drawing style.

### `form-button-type` — every `<button>` in a `<form>` needs `type`

A `<button>` with no `type` inside a `<form>` defaults to `type="submit"`, so a
button that runs JavaScript instead submits the form and reloads the page. This
is a live bug class, not a style point: it is GH #84, reported as *"the
unit-edit OwnTracks button immediately refreshes the page."* Fix these; do not
baseline them.

### `state-store` — per-user layout state belongs on the server

Two sanctioned homes:

| Mechanism | Table | Entry point |
|---|---|---|
| Screen prefs (columns, sort, options) | `user_screen_prefs` | `inc/screen-prefs.php` → `api/screen-prefs.php`, `ScreenPrefs.save()` |
| Dashboard layout + hidden widgets | `dashboard_layouts` | `api/layout.php` |

`localStorage` is fine as a **render-speed cache alongside** the server write —
that is exactly what `assets/js/widget-manager.js:416-419` does, writing both.
`localStorage` as the only home means the dispatcher's columns do not follow
them to the next machine.

The rule is deliberately narrow: only keys holding *layout* or *column* state
are flagged. Theme, mute, GPS on/off and floating-panel position are properties
of the device, and 120 `localStorage` references across the tree say the
project agrees.

### `es5` — browser JavaScript is ES5 in an IIFE

No arrow functions, `let`/`const`, template literals or destructuring. There is
no build step and no transpiler, and the browsers this dispatches on are
whatever a volunteer agency has. Wrap each file:

```js
(function () {
    'use strict';
    // ...
})();
```

The audit strips comments **and** string literals before applying this rule.
That is not a detail: a naive scan reports roughly a hundred "template
literals" in `assets/js` and every one is a backtick inside a comment, and
`assets/js/mesh-console.js` contains `"==> Installing"` inside a shell-script
string that a naive scan reads as six arrow functions.

---

## Adding to the audit

The tool is `tools/ui_consistency_audit.php`; the shared markup extractor is
`tools/ui_extract.php`; the gate is `tests/test_ui_consistency_audit.php`.

Two things to know before you add a rule:

1. **Markup is assembled, not written.** Attributes are routinely split across
   concatenated string literals (`assets/js/widget-manager.js:277` puts an
   attribute's name in one literal and its value in another) or interrupted by
   an inline `<?php echo … ?>` in the middle of a tag. `tools/ui_extract.php`
   puts them back together; scanning raw strings finds nothing. This is the
   same trap that made `tools/schema_audit.php` blind to all 89 writer
   `INSERT`s until Phase 125.

2. **Prove the detector both fires and stays quiet.** Every rule in
   `tests/test_ui_consistency_audit.php` is driven against a known-bad fixture
   *and* a known-good one, through the real tool via `--path=`. A rule that
   also flags the correct form is a rule that gets muted, and a gate that only
   ever runs on a clean tree proves nothing.
