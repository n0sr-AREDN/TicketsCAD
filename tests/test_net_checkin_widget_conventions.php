<?php
/**
 * test_net_checkin_widget_conventions.php — Phase 131 widget, owner review
 * 2026-08-01.
 *
 * Four defects, all of them one complaint: the check-in panel did not look or
 * behave like the rest of the application. "I do not want the software to
 * appear as if we have multiple developers who have never seen this software
 * before or ever talked to each other, working on the same codebase."
 *
 *   1. "Floating" was a fixed corner. It could not be moved or resized and it
 *      remembered nothing.
 *   2. Selecting a check-in moved and zoomed the map — to nothing, because a
 *      check-in has no location.
 *   3. It had a dismiss button and no way back.
 *   4. The hotkeys were a run of plain text along the bottom instead of the
 *      Responders widget's labelled buttons with keycap badges.
 *
 * Defect 2's cause is the one worth stating, because grepping for wiring would
 * have missed it entirely: assets/js/keyboard-nav.js and assets/js/
 * net-checkins.js BOTH listen for keydown on `document`, and preventDefault()
 * does not stop the other listener. keyboard-nav's notion of "the focused
 * widget" was sticky — only a click somewhere else cleared it — so a panel that
 * focuses itself (which `/net` does) left the dashboard handler still live. One
 * ArrowDown then moved the check-in selection AND panned the map to a unit
 * nobody had selected; `d` deleted a check-in AND opened the Responders
 * dispatch screen, navigating the operator off the dashboard in the middle of a
 * net. Verified on the running application before the fix, at
 * setView([44.9778,-93.27], 15) from a single ArrowDown.
 *
 * So the sections below EXECUTE the real code — the real keyboard-nav handler,
 * the real widget-manager, the real exported functions from net-checkins.js —
 * and section 6 is a NEGATIVE CONTROL that strips the fix back out and requires
 * the assertions to go red. A test that cannot fail proves nothing.
 *
 * Covered:
 *   1. Per-user position/size/open through the REAL write and read paths (DB)
 *   2. ScreenPrefs.saveOptions merges instead of clobbering (real JS, node)
 *   3. Ownership of the keyboard — the real keyboard-nav handler, executed
 *   4. The widgets toolbar can close AND re-open it — real widget-manager
 *   5. The hotkeys are the shared action-bar component, keys and buttons bound
 *      to one code path
 *   6. Negative controls
 */

require_once __DIR__ . '/../config.php';
require_once NEWUI_ROOT . '/inc/screen-prefs.php';

$base = realpath(__DIR__ . '/..');

echo "=== Net-control check-in widget conventions (owner review 2026-08-01) ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }
function eq($expected, $actual, string $name): void {
    if ($expected === $actual) { ok($name); return; }
    bad($name, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. Position, size and open/closed persist per user (real DB paths) --\n";
// ─────────────────────────────────────────────────────────────────────────

// The catalog has to carry the keys, or prefs_get() drops them on every read:
// its options merge starts from the screen defaults.
$dash = prefs_screen_defaults()['dashboard'] ?? null;
is_true(is_array($dash), "the 'dashboard' screen exists in the catalog");
foreach (['net_panel_left', 'net_panel_top', 'net_panel_w', 'net_panel_h', 'net_panel_open'] as $k) {
    is_true(isset($dash['options'][$k]), "catalog default for $k");
}
// The panel must open CENTRED for someone who has never moved it. -1/0 are the
// "not placed / no explicit size" sentinels the stylesheet's centring relies on.
eq(-1, $dash['options']['net_panel_left'] ?? null, 'an unplaced panel defaults to centred (left = -1)');
eq(-1, $dash['options']['net_panel_top'] ?? null, 'an unplaced panel defaults to centred (top = -1)');
eq(1,  $dash['options']['net_panel_open'] ?? null, 'the panel is open by default');
// It must not have disturbed the option that was already there.
eq(30, $dash['options']['recent_close_mins'] ?? null, "the Incidents widget's recent-close default is untouched");

$dbOk = true;
try { db_fetch_value("SELECT COUNT(*) FROM `" . ($GLOBALS['db_prefix'] ?? '') . "user_screen_prefs`"); }
catch (Exception $e) { $dbOk = false; }

if (!$dbOk) {
    echo "SKIP: user_screen_prefs table absent — the persistence checks were not run\n";
} else {
    // A synthetic id so no real operator's preferences are touched. There is no
    // foreign key on this table, and the row is removed at the end.
    $uid = 2100000001;
    prefs_reset($uid, 'dashboard');

    // WRITE exactly what api/screen-prefs.php writes when the operator drags
    // the panel to the lower right and grows it — the values the running
    // application actually stored during verification.
    prefs_set($uid, 'dashboard', [
        'columns' => [],
        'sort'    => ['col' => '', 'dir' => 'asc'],
        'options' => [
            'recent_close_mins' => 45,
            'net_panel_left' => 933, 'net_panel_top' => 728,
            'net_panel_w' => 600,    'net_panel_h' => 234,
            'net_panel_open' => 1,
        ],
    ]);

    // READ with the same function every page load uses.
    $got = prefs_get($uid, 'dashboard');
    eq(933, (int) ($got['options']['net_panel_left'] ?? 0), 'left survives the round trip');
    eq(728, (int) ($got['options']['net_panel_top'] ?? 0),  'top survives the round trip');
    eq(600, (int) ($got['options']['net_panel_w'] ?? 0),    'width survives the round trip');
    eq(234, (int) ($got['options']['net_panel_h'] ?? 0),    'height survives the round trip');
    eq(45,  (int) ($got['options']['recent_close_mins'] ?? 0),
        'the other writer on this screen still reads its own value back');

    // Closing is remembered, and 0 must survive — a falsy value that a lazy
    // merge would drop, which would resurrect the panel the operator closed.
    prefs_set($uid, 'dashboard', [
        'columns' => [], 'sort' => ['col' => '', 'dir' => 'asc'],
        'options' => array_merge($got['options'], ['net_panel_open' => 0]),
    ]);
    $got2 = prefs_get($uid, 'dashboard');
    eq(0, (int) ($got2['options']['net_panel_open'] ?? 1), 'a closed panel stays closed across a reload');
    eq(933, (int) ($got2['options']['net_panel_left'] ?? 0), 'closing does not forget where it was');

    // Another user must not inherit this one's desk layout.
    $other = prefs_get($uid + 1, 'dashboard');
    eq(-1, (int) ($other['options']['net_panel_left'] ?? 0), 'a different user gets the centred default');

    prefs_reset($uid, 'dashboard');
    eq(-1, (int) (prefs_get($uid, 'dashboard')['options']['net_panel_left'] ?? 0),
        'reset returns the panel to centred');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2..6. The real JavaScript, executed (node) --\n";
// ─────────────────────────────────────────────────────────────────────────

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

/**
 * Run a node harness and parse its `PASS|name|detail` lines.
 *
 * @param string   $node
 * @param string   $harnessJs  harness source
 * @param string[] $sources    absolute paths handed to the harness as argv
 */
function njs_run(string $node, string $harnessJs, array $sources): array {
    $h = tempnam(sys_get_temp_dir(), 'njs') . '.js';
    file_put_contents($h, $harnessJs);
    $args = '';
    foreach ($sources as $s) $args .= ' ' . escapeshellarg(str_replace('\\', '/', $s));
    $raw = @shell_exec($node . ' ' . escapeshellarg($h) . $args . ' 2>&1');
    @unlink($h);
    $out = [];
    foreach (explode("\n", trim((string) $raw)) as $line) {
        $parts = explode('|', trim($line), 3);
        if (count($parts) < 2) continue;
        if ($parts[0] !== 'PASS' && $parts[0] !== 'FAIL') continue;
        $out[$parts[1]] = ['ok' => $parts[0] === 'PASS', 'detail' => $parts[2] ?? ''];
    }
    $out['__raw'] = ['ok' => true, 'detail' => (string) $raw];
    return $out;
}

/**
 * The harness. argv[2] = keyboard-nav.js, argv[3] = net-checkins.js,
 * argv[4] = widget-manager.js, argv[5] = screen-prefs.js.
 *
 * Only the browser objects these files touch are stubbed; the logic under test
 * is production code, loaded from the real file on disk.
 */
$harness = <<<'JS'
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

// ── A DOM small enough to reason about, real enough to run these files ──
function El(tag, attrs) {
    this.tagName = (tag || 'DIV').toUpperCase();
    this._attrs = attrs || {};
    this._classes = {};
    this._listeners = {};
    this.children = [];
    this.parent = null;
    this.disabled = false;
    this.style = {};
    var self = this;
    this.classList = {
        add:      function () { for (var i=0;i<arguments.length;i++) self._classes[arguments[i]] = true; },
        remove:   function () { for (var i=0;i<arguments.length;i++) delete self._classes[arguments[i]]; },
        contains: function (c) { return !!self._classes[c]; }
    };
}
El.prototype.setAttribute = function (k, v) { this._attrs[k] = String(v); };
El.prototype.getAttribute = function (k) { return this._attrs.hasOwnProperty(k) ? this._attrs[k] : null; };
El.prototype.addEventListener = function (t, fn) { (this._listeners[t] = this._listeners[t] || []).push(fn); };
El.prototype.fire = function (t, ev) {
    var hs = this._listeners[t] || [];
    for (var i = 0; i < hs.length; i++) hs[i](ev || {});
};
El.prototype.append = function (c) { c.parent = this; this.children.push(c); return c; };
El.prototype.contains = function (n) { for (var p = n; p; p = p.parent) if (p === this) return true; return false; };
// `closest` is what both handlers use to decide ownership, so it has to be real.
El.prototype.closest = function (sel) {
    var attr = null, cls = null;
    var m = /^\[([a-z-]+)\]$/.exec(sel);
    if (m) attr = m[1]; else if (sel.charAt(0) === '.') cls = sel.slice(1); else return null;
    for (var p = this; p; p = p.parent) {
        if (attr && p.getAttribute(attr) !== null) return p;
        if (cls && p.classList.contains(cls)) return p;
    }
    return null;
};

// ── The page: a dashboard widget, and the floating check-in panel ──
var root  = new El('div');
var respWidget = root.append(new El('div'));
respWidget.classList.add('widget-responders');
var respRow = respWidget.append(new El('tr'));

var panel = root.append(new El('div', { 'data-kb-region': 'net-checkins', tabindex: '-1' }));
panel.classList.add('net-checkin-panel');
var panelRow = panel.append(new El('tr'));

var toolbarBtn = root.append(new El('button', { 'data-widget': 'net_checkins' }));
toolbarBtn.classList.add('widget-toggle', 'active');

var body = new El('body');
var docListeners = {};
global.window = global;
global.document = {
    body: body,
    activeElement: body,
    addEventListener: function (t, fn) { (docListeners[t] = docListeners[t] || []).push(fn); },
    querySelector: function (sel) {
        if (sel.indexOf('widget-toggle') !== -1) return toolbarBtn;
        if (sel.indexOf('.widget-responders') !== -1) return respWidget;
        if (sel.indexOf('csrf') !== -1) return { getAttribute: function () { return 'T'; } };
        return null;
    },
    querySelectorAll: function (sel) { return sel.indexOf('widget-toggle') !== -1 ? [toolbarBtn] : []; },
    getElementById: function () { return null; },
    createElement: function (t) { return new El(t); },
    readyState: 'complete'
};
function fireDoc(type, ev) {
    var hs = docListeners[type] || [];
    for (var i = 0; i < hs.length; i++) hs[i](ev);
}

// ── What the dashboard would do if it acted on the keystroke ──
var dashCalls = [];
var emitted = [];
global.EventBus = {
    on: function () {},
    emit: function (t, d) { emitted.push(t); }
};
// Faithful to app.js: selecting IS what emits the event that app.js's
// onResponderSelected turns into map.setView([lat,lng], 15). If the stub only
// recorded the call, the "no map-focus event" assertion could never go red.
global.DashboardActions = {
    getSelectedType: function () { return 'responder'; },
    getSelectedId:   function () { return 7; },
    selectByOffset:  function (t, n) { dashCalls.push('selectByOffset:' + t + ':' + n);
                                       EventBus.emit(t + ':selected', { id: 1550, lat: 44.9778, lng: -93.27 }); },
    selectFirst:     function (t)    { dashCalls.push('selectFirst:' + t);
                                       EventBus.emit(t + ':selected', { id: 1550, lat: 44.9778, lng: -93.27 }); },
    clearSelection:  function ()     { dashCalls.push('clearSelection'); },
    executeAction:            function (a) { dashCalls.push('incidentAction:' + a); },
    executeResponderAction:   function (a) { dashCalls.push('responderAction:' + a); },
    executeFacilityAction:    function (a) { dashCalls.push('facilityAction:' + a); }
};
global.setTimeout = setTimeout; global.clearTimeout = clearTimeout;
global.fetch = function () { return Promise.resolve({ json: function () { return Promise.resolve({}); } }); };

// ── Load the REAL keyboard-nav.js ──
eval(fs.readFileSync(process.argv[2], 'utf8'));
check('keyboard-nav.js loaded', !!global.window.KeyboardNav);

function keydown(target, key) {
    var prevented = false;
    fireDoc('keydown', {
        key: key, target: target, ctrlKey: false, metaKey: false, altKey: false,
        preventDefault: function () { prevented = true; }
    });
    return prevented;
}

// ── 3. Ownership of the keyboard ──────────────────────────────────────────
// The operator clicked a unit earlier in the shift. That is a legitimate,
// normal thing to do, and it is what made the dashboard handler live.
fireDoc('click', { target: respRow });
check('clicking a unit makes the Responders widget the keyboard target',
      KeyboardNav.getFocusedWidget() === 'responders', KeyboardNav.getFocusedWidget());

// THE DEFECT: the same ArrowDown, with focus inside the check-in panel.
dashCalls.length = 0; emitted.length = 0;
keydown(panelRow, 'ArrowDown');
check('selecting a check-in drives NO dashboard selection (the map stays put)',
      dashCalls.length === 0, dashCalls.join(','));
check('selecting a check-in emits NO map-focus event',
      emitted.length === 0, emitted.join(','));

// The letter hotkeys collided too, and those NAVIGATE — worse than a map pan.
dashCalls.length = 0;
keydown(panelRow, 'd');
keydown(panelRow, 'e');
keydown(panelRow, 'v');
check('check-in letter hotkeys do not fire Responders actions',
      dashCalls.length === 0, dashCalls.join(','));

// …and the dashboard must still work when it IS the one being driven.
dashCalls.length = 0;
keydown(respRow, 'ArrowDown');
check('the Responders widget still arrows normally (nothing was broken to fix this)',
      dashCalls.length === 1 && dashCalls[0] === 'selectByOffset:responder:1', dashCalls.join(','));
dashCalls.length = 0;
keydown(respRow, 'd');
check('the Responders D hotkey still dispatches',
      dashCalls.join(',') === 'responderAction:dispatch', dashCalls.join(','));

// The focusin half of the fix: focus moving into the panel stands the
// dashboard down, so its keyboard-focus ring is honest too.
fireDoc('focusin', { target: panelRow });
check('focus entering the panel releases the dashboard widget',
      KeyboardNav.getFocusedWidget() === null, String(KeyboardNav.getFocusedWidget()));

// ── The panel's own half of the ownership rule ────────────────────────────
// Reload the page objects net-checkins.js needs, then take its exported,
// production panelOwnsKeyboard() through the matrix.
var netSrc = fs.readFileSync(process.argv[3], 'utf8');
global.document.getElementById = function () { return null; };
eval(netSrc);
var NC = global.window.NetCheckins;
check('net-checkins.js loaded', !!NC);

var hidden = new El('div', { 'data-kb-region': 'net-checkins' });
hidden.classList.add('d-none');
check('a hidden panel never claims the keyboard',
      NC.panelOwnsKeyboard(hidden, panelRow, body, null) === false);
check('focus inside the panel claims the keyboard',
      NC.panelOwnsKeyboard(panel, panelRow, body, 'responders') === true);
check('focus on a dashboard widget yields the keyboard to it',
      NC.panelOwnsKeyboard(panel, body, body, 'responders') === false);
check('nothing focused and no widget live: the panel still answers (unchanged behaviour)',
      NC.panelOwnsKeyboard(panel, body, body, null) === true);

// ── 1b. Remembered geometry must stay reachable ───────────────────────────
// A panel dragged to the corner of a 2560-wide monitor, reopened on a laptop.
var far = NC.clampGeometry({ left: 2400, top: 1300, w: 600, h: 234 }, { w: 1280, h: 800 }, { w: 600, h: 234 });
check('a position from a bigger screen is pulled back on screen',
      far.left + 120 <= 1280 && far.top <= 800 - 40,
      JSON.stringify(far));
check('the header stays reachable, so it can be dragged back',
      far.top >= 0, JSON.stringify(far));
var normal = NC.clampGeometry({ left: 300, top: 200, w: 600, h: 234 }, { w: 1600, h: 1000 }, { w: 600, h: 234 });
check('a position that fits is left exactly alone',
      normal.left === 300 && normal.top === 200 && normal.w === 600 && normal.h === 234,
      JSON.stringify(normal));
var unset = NC.clampGeometry({ left: -1, top: -1, w: 0, h: 0 }, { w: 1600, h: 1000 }, { w: 480, h: 144 });
check('an unplaced panel is left unplaced, so the stylesheet centres it',
      unset.left === -1 && unset.top === -1 && unset.w === 0 && unset.h === 0,
      JSON.stringify(unset));

// ── 5b. Which buttons may act ─────────────────────────────────────────────
var waiting = NC.actionState({ id: 1, status: 'pending' }, false);
check('a waiting check-in offers New/Append/Edit/Delete',
      waiting['new'] && waiting.append && waiting.edit && waiting['delete'], JSON.stringify(waiting));
check('Undo is dimmed for a check-in that is still waiting (it would do nothing)',
      waiting.undo === false, JSON.stringify(waiting));
var worked = NC.actionState({ id: 1, status: 'worked' }, false);
check('Undo lights up for a worked check-in', worked.undo === true);
var deleted = NC.actionState({ id: 1, status: 'deleted' }, true);
check('Undo lights up for a deleted check-in', deleted.undo === true);
check('Delete is dimmed for an already-deleted check-in', deleted['delete'] === false);
var none = NC.actionState(null, false);
check('with nothing selected every entry action is dimmed',
      !none['new'] && !none.append && !none.edit && !none['delete'] && !none.undo, JSON.stringify(none));
check('History is always available — it is how you find a deleted entry',
      none.history === true && waiting.history === true);
check('History reports its pressed state for aria-pressed',
      NC.actionState(null, true).historyOn === true && none.historyOn === false);

// ── 4. The widgets toolbar owns open and close ────────────────────────────
var wmSrc = fs.readFileSync(process.argv[4], 'utf8');
global.GridStack = { init: function () { return { on: function () {}, addWidget: function () { return new El('div'); },
    getGridItems: function () { return []; }, removeWidget: function () {}, removeAll: function () {} }; } };
// widget-manager.js declares `var WidgetManager = …` and never touches
// `window`, so publish it explicitly rather than hoping eval's scope leaks.
eval(wmSrc + '\n;global.WidgetManager = WidgetManager;');
var WM = global.WidgetManager;
check('widget-manager.js loaded', !!WM && !!WM.registerFloating);

var shown = 0, hidden2 = 0, open = true;
WM.registerFloating('net_checkins', {
    show:   function () { shown++;   open = true; },
    hide:   function () { hidden2++; open = false; },
    isOpen: function () { return open; }
});
check('the floating widget is registered with the toolbar', WM.isFloating('net_checkins') === true);
check('the toolbar button starts active for an open panel', toolbarBtn.classList.contains('active'));

// try/catch, because a floating widget sent down the GridStack path throws on
// a null grid — which is itself a failure of this contract, not of the harness.
var toggleErr = null;
try { WM.toggleWidget('net_checkins', toolbarBtn); } catch (e1) { toggleErr = String(e1); }
check('the toolbar button closes it', hidden2 === 1 && open === false, 'hide calls: ' + hidden2 + ' ' + (toggleErr || ''));
check('the button shows closed', toolbarBtn.classList.contains('active') === false);

try { WM.toggleWidget('net_checkins', toolbarBtn); } catch (e2) { toggleErr = String(e2); }
check('THE WAY BACK: the same toolbar button re-opens it', shown === 1 && open === true,
      'show calls: ' + shown + ' ' + (toggleErr || ''));
check('the button shows open again', toolbarBtn.classList.contains('active') === true);

// It must not have been dragged through the GridStack path, which would have
// tried to build a grid item from a template that does not exist.
check('a floating widget is not pushed through the GridStack layout path',
      toggleErr === null && WM.getHiddenWidgets().indexOf('net_checkins') === -1,
      toggleErr || JSON.stringify(WM.getHiddenWidgets()));

// ── 2. saveOptions merges rather than clobbers ────────────────────────────
var spSrc = fs.readFileSync(process.argv[5], 'utf8');
var stored = { recent_close_mins: 45, net_panel_left: 933, net_panel_top: 728 };
var lastPost = null;
global.fetch = function (url, opts) {
    if (!opts || opts.method !== 'POST') {
        return Promise.resolve({ json: function () {
            return Promise.resolve({ prefs: { columns: [], sort: { col: '', dir: 'asc' }, options: stored } }); } });
    }
    lastPost = JSON.parse(opts.body);
    return Promise.resolve({ json: function () { return Promise.resolve({ ok: true }); } });
};
global.bootstrap = { Modal: function () { return { show: function () {}, hide: function () {} }; } };
eval(spSrc);
var SP = global.window.ScreenPrefs;
check('screen-prefs.js exposes saveOptions', !!(SP && SP.saveOptions));

SP.saveOptions('dashboard', { net_panel_left: 40, net_panel_top: 50 }).then(function () {
    check('a panel move writes the new position',
          lastPost && lastPost.options.net_panel_left === 40 && lastPost.options.net_panel_top === 50,
          JSON.stringify(lastPost && lastPost.options));
    check('…without wiping the other writer on the same screen',
          lastPost && lastPost.options.recent_close_mins === 45,
          JSON.stringify(lastPost && lastPost.options));
    check('the save carries a CSRF token', lastPost && lastPost.csrf_token === 'T');
    console.log(out.join('\n'));
}).catch(function (e) {
    out.push('FAIL|saveOptions ran||' + e);
    console.log(out.join('\n'));
});
JS;

$srcKeyboardNav  = $base . '/assets/js/keyboard-nav.js';
$srcNetCheckins  = $base . '/assets/js/net-checkins.js';
$srcWidgetMgr    = $base . '/assets/js/widget-manager.js';
$srcScreenPrefs  = $base . '/assets/js/screen-prefs.js';

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
} else {
    $res = njs_run($node, $harness, [$srcKeyboardNav, $srcNetCheckins, $srcWidgetMgr, $srcScreenPrefs]);
    $raw = $res['__raw']['detail'] ?? '';
    unset($res['__raw']);
    if (!$res) {
        bad('node harness ran the widget JS', trim($raw));
    } else {
        foreach ($res as $name => $r) {
            is_true($r['ok'], $name, $r['detail']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    echo "\n-- 6. Negative controls — remove the fix, the tests must go red --\n";
    // ─────────────────────────────────────────────────────────────────────
    //
    // The whole history of this defect is code that LOOKED wired and was not,
    // so each control deletes exactly one line of the fix and requires the
    // assertion it protects to fail.

    // (a) keyboard-nav's data-kb-region guard.
    $kn = file_get_contents($srcKeyboardNav);
    $knBroken = preg_replace(
        "/^\s*if \(e\.target && e\.target\.closest && e\.target\.closest\('\[data-kb-region\]'\)\) return;\s*$/m",
        '', $kn, 1, $cnt
    );
    is_true($cnt === 1, 'the guard line is present in keyboard-nav.js to remove');
    if ($cnt === 1) {
        $tmp = tempnam(sys_get_temp_dir(), 'knb') . '.js';
        // Also disable the focusin half, or it would mask the guard's removal.
        $knBroken = str_replace("document.addEventListener('focusin'", "var _off = (function(){}); (0) && document.addEventListener('focusin'", $knBroken);
        file_put_contents($tmp, $knBroken);
        $neg = njs_run($node, $harness, [$tmp, $srcNetCheckins, $srcWidgetMgr, $srcScreenPrefs]);
        @unlink($tmp);
        $k1 = 'selecting a check-in drives NO dashboard selection (the map stays put)';
        $k2 = 'selecting a check-in emits NO map-focus event';
        is_true(isset($neg[$k1]) && $neg[$k1]['ok'] === false,
            'without the guard, an ArrowDown in the panel DOES drive the dashboard',
            isset($neg[$k1]) ? 'still passed' : 'assertion missing from the negative run');
        is_true(isset($neg[$k2]) && $neg[$k2]['ok'] === false,
            'without the guard, an ArrowDown in the panel DOES emit a map-focus event',
            isset($neg[$k2]) ? 'still passed' : 'assertion missing from the negative run');
    }

    // (b) widget-manager's floating branch — the "way back".
    $wm = file_get_contents($srcWidgetMgr);
    $wmBroken = str_replace('if (floatingWidgets[widgetId]) {', 'if (false) {', $wm, $c2);
    is_true($c2 >= 1, 'the floating branch is present in widget-manager.js to remove');
    if ($c2 >= 1) {
        $tmp2 = tempnam(sys_get_temp_dir(), 'wmb') . '.js';
        file_put_contents($tmp2, $wmBroken);
        $neg2 = njs_run($node, $harness, [$srcKeyboardNav, $srcNetCheckins, $tmp2, $srcScreenPrefs]);
        @unlink($tmp2);
        $k3 = 'THE WAY BACK: the same toolbar button re-opens it';
        is_true(isset($neg2[$k3]) && $neg2[$k3]['ok'] === false,
            'without the floating branch, the toolbar button cannot re-open the panel',
            isset($neg2[$k3]) ? 'still passed' : 'assertion missing from the negative run');
    }

    // (c) saveOptions' merge — the clobber that would silently lose a position.
    $sp = file_get_contents($srcScreenPrefs);
    $spBroken = str_replace(
        'var merged = (prefs && prefs.options) ? prefs.options : {};',
        'var merged = {};', $sp, $c3
    );
    is_true($c3 === 1, 'the merge line is present in screen-prefs.js to remove');
    if ($c3 === 1) {
        $tmp3 = tempnam(sys_get_temp_dir(), 'spb') . '.js';
        file_put_contents($tmp3, $spBroken);
        $neg3 = njs_run($node, $harness, [$srcKeyboardNav, $srcNetCheckins, $srcWidgetMgr, $tmp3]);
        @unlink($tmp3);
        $k4 = '…without wiping the other writer on the same screen';
        is_true(isset($neg3[$k4]) && $neg3[$k4]['ok'] === false,
            'without the merge, saving a position DOES wipe the other writer',
            isset($neg3[$k4]) ? 'still passed' : 'assertion missing from the negative run');
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. The hotkeys are the shared action-bar component --\n";
// ─────────────────────────────────────────────────────────────────────────

$markup = (string) file_get_contents($base . '/inc/net-checkin-widget.php');
$netJs  = (string) file_get_contents($srcNetCheckins);
$barCss = (string) file_get_contents($base . '/assets/css/action-bar.css');
$widgetsCss = (string) file_get_contents($base . '/assets/css/widgets.css');
$wmJs   = (string) file_get_contents($srcWidgetMgr);

// ONE rule styles every bar. Adding a fourth bar by copying the rules is what
// this asserts against — a private copy would satisfy "it looks the same
// today" and drift the moment either is edited.
foreach ([['.btn-xs', 4], ['.btn-xs kbd', 4], ['.action-label', 4]] as [$part, $expect]) {
    $found = 0;
    foreach (['.incident-action-bar', '.responder-action-bar', '.facility-action-bar', '.net-checkin-action-bar'] as $bar) {
        if (strpos($barCss, $bar . ' ' . $part) !== false) $found++;
    }
    eq($expect, $found, "all four action bars share the one '$part' rule");
}
is_true(strpos($widgetsCss, '.responder-action-bar .btn-xs') === false,
    'widgets.css no longer keeps a second copy of the action-bar rules');
is_true(strpos($markup, 'action-bar.css') !== false,
    'the widget include links the shared action-bar stylesheet (situation.php has no widgets.css)');
is_true(strpos((string) file_get_contents($base . '/index.php'), 'action-bar.css') !== false,
    'index.php links the shared action-bar stylesheet');

// Same markup shape as the Responders bar: icon, label, keycap, on a btn-xs.
$refBtn = 'btn btn-xs btn-outline-info responder-action-btn';
is_true(strpos($wmJs, $refBtn) !== false, 'the Responders bar is still the reference shape');

preg_match_all('/<button[^>]*data-net-action="([a-z]+)"[^>]*>(.*?)<\/button>/s', $markup, $m, PREG_SET_ORDER);
eq(6, count($m), 'the panel renders six action buttons');
$actions = [];
foreach ($m as $b) {
    $name = $b[1];
    $actions[] = $name;
    $whole = $b[0];
    is_true(strpos($whole, 'btn-xs') !== false,             "[$name] uses the shared btn-xs size");
    is_true((bool) preg_match('/btn-outline-\w+/', $whole), "[$name] uses a Bootstrap outline variant like the other bars");
    is_true(strpos($whole, 'type="button"') !== false,      "[$name] is type=button (GH #84: a bare button submits the form)");
    is_true((bool) preg_match('/<i class="bi bi-[a-z0-9-]+ me-1">/', $b[2]), "[$name] carries a Bootstrap icon");
    is_true(strpos($b[2], '<span class="action-label">') !== false,          "[$name] carries an action-label");
    is_true((bool) preg_match('/<kbd>.+?<\/kbd>/', $b[2]),                   "[$name] carries a keycap badge");
}
sort($actions);
eq(['append', 'delete', 'edit', 'history', 'new', 'undo'], $actions, 'the six actions are the six hotkeys');

// Keys and buttons must run the SAME code, or one of them rots.
is_true((bool) preg_match("/var byKey = \{[^}]*a: 'append'[^}]*e: 'edit'[^}]*d: 'delete'[^}]*u: 'undo'[^}]*h: 'history'/", $netJs),
    'every letter hotkey maps to a named action');
is_true(substr_count($netJs, 'runAction(') >= 3,
    'both the keys and the buttons dispatch through runAction()');

// Defect 3, in the markup: no dismiss control, and a refresh where every other
// widget puts one.
is_true(strpos($markup, 'netCheckinClose') === false,
    'the dismiss button is gone — closing belongs to the widgets toolbar');
is_true(strpos($markup, 'widget-refresh') !== false && strpos($markup, 'bi-arrow-clockwise') !== false,
    'the top-right control is the same refresh control as every other widget');
is_true(strpos($markup, 'net-checkin-help') === false,
    'the plain-text hotkey legend along the bottom is gone');
is_true(strpos($markup, 'card-header py-1 px-2 d-flex align-items-center') !== false,
    'the header is the dashboard widget card-header shape');

// And the toolbar entry itself, gated by the same permission as the widget.
$indexPhp = (string) file_get_contents($base . '/index.php');
is_true((bool) preg_match('/rbac_can\(\'action\.net_checkin\'\).*?widget-toggle active" data-widget="net_checkins"/s', $indexPhp),
    'the widgets toolbar carries the check-ins toggle, gated on action.net_checkin');

// The caption key is shared with the panel header, per the GH #70 convention.
is_true(strpos($indexPhp, "t('dash.widget.net_checkins'") !== false
     && strpos($markup,   "t('dash.widget.net_checkins'") !== false,
    'toolbar button and panel header share one caption key');
is_true(strpos((string) file_get_contents($base . '/sql/run_phase131_net_checkins.php'), 'dash.widget.net_checkins') !== false,
    'the caption key is seeded, so the Translations UI has a row to edit');

// Defect 1, in the stylesheet: above, movable, resizable, centred by default.
$netCss = (string) file_get_contents($base . '/assets/css/net-checkins.css');
is_true((bool) preg_match('/\.net-checkin-panel\s*\{[^}]*z-index:\s*1045/s', $netCss),
    'the panel floats above the grid widgets');
is_true((bool) preg_match('/\.net-checkin-panel\s*\{[^}]*top:\s*50%[^}]*left:\s*50%[^}]*transform:\s*translate\(-50%, -50%\)/s', $netCss),
    'an unplaced panel is centred');
is_true(strpos($netCss, '.net-panel-placed') !== false && strpos($netCss, 'transform: none') !== false,
    'a remembered position cancels the centring transform');
is_true(strpos($netCss, '.net-checkin-resize-handle') !== false && strpos($netCss, 'nwse-resize') !== false,
    'the panel has a resize grip');
is_true(strpos($markup, 'netCheckinResize') !== false, 'the resize grip is rendered');
is_true(strpos($netCss, '#netCheckinHeader') !== false && strpos($netCss, 'cursor: move') !== false,
    'the header is the drag handle');
// Both themes: every colour comes from a variable. A literal here would be
// unreadable in exactly one of the two themes, and the night shift is the one
// that runs severe-weather nets. (Matching on colour VALUES, not on the word
// "white" — `white-space: nowrap` is not a colour.)
$hardcoded = [];
if (preg_match_all('/#[0-9a-f]{3,8}\b|(?<![-\w])rgba?\(\s*\d/i', $netCss, $mm)) {
    $hardcoded = array_unique($mm[0]);
}
if (preg_match_all('/(?:^|[\s:])(?:color|background(?:-color)?|border(?:-[a-z]+)?-color)\s*:\s*(white|black|red|blue|green|grey|gray)\b/i', $netCss, $m2)) {
    $hardcoded = array_merge($hardcoded, $m2[1]);
}
is_true($hardcoded === [], 'net-checkins.css uses theme variables only, no hardcoded colours',
    implode(',', $hardcoded));

echo "\n";
echo "==========================================================\n";
echo "=== {$pass} passed, {$fail} failed ===\n";
echo "==========================================================\n";
exit($fail > 0 ? 1 : 0);
