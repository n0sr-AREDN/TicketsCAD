/**
 * NewUI v4.0 — Net-Control Check-In widget (Phase 131)
 *
 * A floating panel that sits above the other widgets on the situational
 * display and holds the list of stations that have checked in, so net control
 * can read the list back on the air and then call on each in turn.
 *
 * KEYBOARD-FIRST. This runs while people are talking on the radio; the mouse
 * is not a supported input path for any of it.
 *
 *   Arrow Up/Down   move the selection
 *   Enter           new incident from the selected entry
 *   a               append the note to an existing incident
 *   e               edit the entry (misheard callsign, note needs fixing)
 *   d               delete the entry
 *   u               undo — restore a worked or deleted entry
 *   h               toggle the history view
 *   Esc             close picker -> cancel edit -> release focus, one level
 *                   at a time, never destroying work
 *
 * Every one of those also has a labelled button with a keycap badge in the
 * header — the Responders widget's action bar, same markup, same shared CSS,
 * same dimmed-when-unavailable behaviour. The keys are the fast path; the
 * buttons are how anyone discovers the keys exist.
 *
 * The parser is NOT here. The command bar sends the raw string the operator
 * typed and the server owns the parse (inc/net-checkins.php), so there is one
 * definition of what `/net` means rather than two that drift.
 *
 * WHO OWNS THE KEYBOARD. Both this file and assets/js/keyboard-nav.js listen
 * for keydown on `document`, and preventDefault() does not stop the other one.
 * Before 2026-08-01 they both acted on the same keystroke whenever a dashboard
 * widget had been clicked earlier in the shift: one ArrowDown moved the
 * check-in selection AND panned the map to an unrelated unit, `d` deleted a
 * check-in AND opened the Responders dispatch screen. The rule now is that
 * exactly one of them is live at a time, decided by focus — see
 * panelOwnsKeyboard() below and the data-kb-region handling in keyboard-nav.js.
 *
 * WHERE THE PANEL SITS. Position, size and open/closed are per-user server-side
 * state, stored in the Phase 17 screen-prefs 'dashboard' options block (see
 * inc/screen-prefs.php for why that store and not the other two).
 *
 * ES5 only — var, no arrow functions, no template literals (project rule).
 */
(function () {
    'use strict';

    var panelEl   = null;
    var bodyEl    = null;
    var pickerEl  = null;
    var headerEl  = null;
    var resizeEl  = null;
    var entries   = [];        // everything currently rendered, in visible order
    var selected  = 0;         // index into the visible list
    var config    = { history_count: 10, autofocus: true, order: 'arrival' };
    var showHistory   = false;
    var historyCount  = 10;
    var editingId = null;      // entry being edited inline
    var pickerOpen = false;
    var pickerIncidents = [];
    var pickerIdx = 0;
    var loaded = false;

    var PREFS_SCREEN = 'dashboard';
    var GEOM_SAVE_MS = 600;
    var geom      = { left: -1, top: -1, w: 0, h: 0 };  // -1/0 = "not set"
    var isOpen    = true;      // has the operator dismissed it from the toolbar?
    var geomTimer = null;
    var dragState   = { active: false, startX: 0, startY: 0, origLeft: 0, origTop: 0 };
    var resizeState = { active: false, startX: 0, startY: 0, origW: 0, origH: 0 };

    // ── Pure helpers (exported; exercised under node by tests/test_net_checkins.php) ──

    /**
     * Decide whether the widget should act on a keystroke.
     *
     * FALSE whenever the operator is typing into a field — stealing 'd' or 'a'
     * from someone filling in an address would be worse than having no hotkeys
     * at all. The widget's own inline edit box is the single exception, and
     * only for Enter (save) and Escape (cancel), which it handles itself.
     *
     * @param {object} active  the focused element (or a {tagName,...} stand-in)
     * @param {string} key     the key being pressed
     */
    function shouldHandleKey(active, key) {
        if (!active) return true;                       // nothing focused -> ours
        if (active.isContentEditable) return false;

        var tag = (active.tagName || '').toUpperCase();
        var isField = (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT');
        if (!isField) return true;

        // Our own edit box: only the two keys that mean something there.
        if (active.getAttribute && active.getAttribute('data-net-edit') === '1') {
            return (key === 'Enter' || key === 'Escape');
        }
        return false;
    }

    /**
     * Build the visible list: everything still waiting, then up to `count`
     * history rows. Waiting entries order by the configured mode; history is
     * always most-recently-touched first, because it is a lookback and not a
     * work queue.
     *
     * Worked and deleted entries are inactive — they appear ONLY when history
     * is being shown.
     */
    function computeVisible(all, opts) {
        opts = opts || {};
        var order = opts.order === 'priority' ? 'priority' : 'arrival';
        var count = typeof opts.historyCount === 'number' ? opts.historyCount : 0;
        var withHistory = !!opts.showHistory;

        var active = [];
        var past = [];
        var i;
        for (i = 0; i < (all || []).length; i++) {
            if (all[i].status === 'pending') active.push(all[i]);
            else past.push(all[i]);
        }

        active.sort(function (a, b) {
            if (order === 'priority' && a.priority !== b.priority) {
                return b.priority - a.priority;      // higher priority first
            }
            if (a.seq !== b.seq) return a.seq - b.seq;
            return a.id - b.id;
        });

        if (!withHistory || count <= 0) return active;

        past.sort(function (a, b) {
            var au = String(a.updated_at || ''), bu = String(b.updated_at || '');
            if (au !== bu) return au < bu ? 1 : -1;    // most recent first
            return b.id - a.id;
        });

        return active.concat(past.slice(0, count));
    }

    /** Row styling by state — worked/deleted must be obvious at a glance. */
    function rowStateClass(entry) {
        if (!entry) return '';
        if (entry.status === 'worked')  return 'net-row-worked';
        if (entry.status === 'deleted') return 'net-row-deleted';
        return 'net-row-pending';
    }

    /** "14:19" from a MySQL datetime — NCS logs and reads back clock time. */
    function shortTime(dt) {
        if (!dt) return '';
        var m = String(dt).match(/(\d{2}):(\d{2})/);
        return m ? (m[1] + ':' + m[2]) : String(dt);
    }

    /**
     * Which action-bar buttons can act right now.
     *
     * The Responders bar shows all of its buttons all of the time and lets
     * Bootstrap dim the ones that cannot fire; this returns that same
     * enabled/disabled picture so the bar never offers an action that would
     * silently do nothing.
     *
     * `undo` is the interesting one: restoring a check-in that is already
     * waiting is a no-op, so Undo is live only for a worked or deleted entry.
     *
     * @param {object|null} entry     the selected check-in, or null
     * @param {boolean}     history   is the history view on?
     */
    function actionState(entry, history) {
        var has = !!entry;
        return {
            'new':    has,
            append:   has,
            edit:     has,
            'delete': has && entry.status !== 'deleted',
            undo:     has && entry.status !== 'pending',
            history:  true,
            historyOn: !!history
        };
    }

    /**
     * Keep a remembered position on screen.
     *
     * A panel dragged to the corner of a 2560-wide desk monitor and reopened on
     * a laptop would otherwise come back entirely off-screen, with no way to
     * reach it — the same one-way door the dismiss button was. Clamping on
     * restore is what makes "remembered" safe to promise.
     *
     * Pure, so tests can drive it directly.
     *
     * @param {object} g   {left, top, w, h} — left/top -1 and w/h 0 mean unset
     * @param {object} vp  {w, h} viewport
     * @param {object} def {w, h} the panel's current/default size
     */
    function clampGeometry(g, vp, def) {
        var out = { left: g.left, top: g.top, w: g.w, h: g.h };
        var MIN_VISIBLE = 120;      // px of panel that must remain reachable
        var TOP_MIN = 0;

        if (out.w > 0) out.w = Math.max(240, Math.min(out.w, vp.w));
        if (out.h > 0) out.h = Math.max(120, Math.min(out.h, vp.h));

        var w = out.w > 0 ? out.w : (def && def.w ? def.w : 480);
        var h = out.h > 0 ? out.h : (def && def.h ? def.h : 320);

        if (out.left !== -1) {
            out.left = Math.max(MIN_VISIBLE - w, Math.min(out.left, vp.w - MIN_VISIBLE));
            if (out.left < 0 && vp.w >= w) out.left = 0;
        }
        if (out.top !== -1) {
            // The header is the only way to drag it back, so the top edge must
            // never go above the viewport.
            out.top = Math.max(TOP_MIN, Math.min(out.top, vp.h - 40));
            if (out.top + h > vp.h && vp.h >= h) out.top = Math.max(TOP_MIN, vp.h - h);
        }
        return out;
    }

    /**
     * Does this panel own the keyboard right now?
     *
     * TRUE when focus is inside the panel — the operator put it there, via
     * `/net`, a click on a row, or Tab. Also TRUE when nothing at all is
     * focused AND the dashboard's keyboard-nav has no widget of its own, which
     * preserves the "press d with nothing focused" behaviour the panel shipped
     * with while still guaranteeing the two never both act on one keystroke.
     *
     * @param {object} panel   the panel element (or a stand-in)
     * @param {object} active  document.activeElement
     * @param {object} body    document.body
     * @param {string|null} kbWidget  KeyboardNav.getFocusedWidget()
     */
    function panelOwnsKeyboard(panel, active, body, kbWidget) {
        if (!panel) return false;
        if (panel.classList && panel.classList.contains('d-none')) return false;
        if (active && panel.contains && panel.contains(active)) return true;
        if (!active || active === body) return !kbWidget;
        return false;
    }

    // ── DOM ───────────────────────────────────────────────────────────────

    function csrf() {
        var el = document.getElementById('csrfToken');
        if (el && el.value) return el.value;
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : (window.CSRF_TOKEN || '');
    }

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function api(body) {
        body.csrf_token = csrf();
        return fetch('api/net-checkins.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function init() {
        panelEl = document.getElementById('netCheckinPanel');
        if (!panelEl) return;
        bodyEl   = document.getElementById('netCheckinBody');
        pickerEl = document.getElementById('netCheckinPicker');
        headerEl = document.getElementById('netCheckinHeader');
        resizeEl = document.getElementById('netCheckinResize');

        var histCount  = document.getElementById('netHistoryCount');
        var refreshBtn = document.getElementById('netCheckinRefresh');

        if (histCount) {
            histCount.addEventListener('change', function () {
                historyCount = Math.max(0, parseInt(histCount.value, 10) || 0);
                load();
            });
        }

        // The top-right control on every widget in this application.
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () { load().then(focusPanel); });
            refreshBtn.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    load().then(focusPanel);
                }
            });
        }

        bindActionBar();
        bindDragResize();
        restoreGeometry();

        document.addEventListener('keydown', onKeyDown);

        load();
    }

    // ── Action bar ────────────────────────────────────────────────────────

    /** One entry point for a key press and for its button — never two paths. */
    function runAction(name) {
        var list = visible();
        var entry = list[selected] || null;
        var state = actionState(entry, showHistory);
        if (!state[name]) return;

        switch (name) {
            case 'new':     newIncidentFrom(entry); return;
            case 'append':  openPicker(entry); return;
            case 'edit':    editingId = entry.id; render(); return;
            case 'delete':  api({ action: 'delete',  id: entry.id }).then(load).then(focusPanel); return;
            case 'undo':    api({ action: 'restore', id: entry.id }).then(load).then(focusPanel); return;
            case 'history':
                showHistory = !showHistory;
                render();
                focusPanel();
                return;
        }
    }

    function bindActionBar() {
        var bar = document.getElementById('netCheckinActionBar');
        if (!bar) return;
        var btns = bar.querySelectorAll('[data-net-action]');
        for (var i = 0; i < btns.length; i++) {
            btns[i].addEventListener('click', function () {
                runAction(this.getAttribute('data-net-action'));
            });
        }
    }

    /** Reflect the current selection in the bar — dimmed when it cannot act. */
    function renderActionBar(entry) {
        var bar = document.getElementById('netCheckinActionBar');
        if (!bar) return;
        var state = actionState(entry, showHistory);
        var btns = bar.querySelectorAll('[data-net-action]');
        for (var i = 0; i < btns.length; i++) {
            var name = btns[i].getAttribute('data-net-action');
            btns[i].disabled = !state[name];
            if (name === 'history') {
                btns[i].setAttribute('aria-pressed', state.historyOn ? 'true' : 'false');
                if (state.historyOn) btns[i].classList.add('active');
                else                 btns[i].classList.remove('active');
            }
        }
    }

    // ── Float: position, size, and remembering both ───────────────────────

    function viewport() {
        return { w: window.innerWidth || 1024, h: window.innerHeight || 768 };
    }

    /** Paint `geom` onto the panel. -1/0 leave the stylesheet in charge. */
    function applyGeometry() {
        if (!panelEl) return;
        var rect = panelEl.getBoundingClientRect();
        var g = clampGeometry(geom, viewport(), { w: rect.width, h: rect.height });
        if (g.w > 0) panelEl.style.width  = g.w + 'px';
        if (g.h > 0) panelEl.style.height = g.h + 'px';
        if (g.left !== -1 && g.top !== -1) {
            panelEl.style.left = g.left + 'px';
            panelEl.style.top  = g.top + 'px';
            panelEl.style.right = 'auto';
            panelEl.classList.add('net-panel-placed');
        }
        geom = g;
    }

    /**
     * Read this user's stored panel state. Open/closed is read here too so a
     * panel the operator closed from the widgets toolbar yesterday does not
     * reappear today just because check-ins are waiting.
     */
    function restoreGeometry() {
        if (!window.ScreenPrefs || !window.ScreenPrefs.load) return;
        window.ScreenPrefs.load(PREFS_SCREEN).then(function (p) {
            var o = (p && p.options) ? p.options : {};
            geom = {
                left: intOr(o.net_panel_left, -1),
                top:  intOr(o.net_panel_top, -1),
                w:    intOr(o.net_panel_w, 0),
                h:    intOr(o.net_panel_h, 0)
            };
            if (o.net_panel_open !== undefined && o.net_panel_open !== null) {
                isOpen = String(o.net_panel_open) !== '0';
            }
            applyGeometry();
            syncToolbar();
        }).catch(function () { /* prefs unreachable — CSS default, panel open */ });
    }

    function intOr(v, dflt) {
        if (v === undefined || v === null || v === '') return dflt;
        var n = parseInt(v, 10);
        return isNaN(n) ? dflt : n;
    }

    function saveGeometry() {
        if (!window.ScreenPrefs || !window.ScreenPrefs.saveOptions) return;
        if (geomTimer) clearTimeout(geomTimer);
        geomTimer = setTimeout(function () {
            geomTimer = null;
            // Fire and forget. The panel is already where the operator put it;
            // a failed preference write must never interrupt a running net.
            window.ScreenPrefs.saveOptions(PREFS_SCREEN, {
                net_panel_left: geom.left,
                net_panel_top:  geom.top,
                net_panel_w:    geom.w,
                net_panel_h:    geom.h,
                net_panel_open: isOpen ? 1 : 0
            }).catch(function () {});
        }, GEOM_SAVE_MS);
    }

    /** Record where the panel actually is, in px, and remember it. */
    function captureGeometry() {
        if (!panelEl) return;
        var r = panelEl.getBoundingClientRect();
        geom = {
            left: Math.round(r.left),
            top:  Math.round(r.top),
            w:    Math.round(r.width),
            h:    Math.round(r.height)
        };
        saveGeometry();
    }

    /**
     * Drag by the header, resize by the corner grip — the same interaction the
     * chat and Zello panels already use (assets/js/chat-widget.js). Neither is
     * ever required: every action has a hotkey and the panel opens centred.
     */
    function bindDragResize() {
        if (headerEl) {
            headerEl.addEventListener('mousedown', function (e) {
                // Let the controls in the header behave like controls.
                if (e.target.closest && e.target.closest('button, input, select, a, .widget-refresh')) return;
                if (e.button !== 0) return;
                var r = panelEl.getBoundingClientRect();
                dragState.active = true;
                dragState.startX = e.clientX;
                dragState.startY = e.clientY;
                dragState.origLeft = r.left;
                dragState.origTop  = r.top;
                // Switch from the centring transform to explicit pixels at the
                // panel's CURRENT place, so it does not jump on the first move.
                panelEl.style.left  = r.left + 'px';
                panelEl.style.top   = r.top + 'px';
                panelEl.style.right = 'auto';
                panelEl.classList.add('net-panel-placed', 'net-panel-dragging');
                e.preventDefault();
            });
        }

        if (resizeEl) {
            resizeEl.addEventListener('mousedown', function (e) {
                if (e.button !== 0) return;
                var r = panelEl.getBoundingClientRect();
                resizeState.active = true;
                resizeState.startX = e.clientX;
                resizeState.startY = e.clientY;
                resizeState.origW = r.width;
                resizeState.origH = r.height;
                panelEl.style.left  = r.left + 'px';
                panelEl.style.top   = r.top + 'px';
                panelEl.style.right = 'auto';
                panelEl.classList.add('net-panel-placed', 'net-panel-dragging');
                e.preventDefault();
                e.stopPropagation();
            });
        }

        document.addEventListener('mousemove', function (e) {
            if (dragState.active) {
                panelEl.style.left = (dragState.origLeft + (e.clientX - dragState.startX)) + 'px';
                panelEl.style.top  = (dragState.origTop  + (e.clientY - dragState.startY)) + 'px';
            } else if (resizeState.active) {
                panelEl.style.width  = Math.max(320, resizeState.origW + (e.clientX - resizeState.startX)) + 'px';
                panelEl.style.height = Math.max(140, resizeState.origH + (e.clientY - resizeState.startY)) + 'px';
            }
        });

        document.addEventListener('mouseup', function () {
            if (!dragState.active && !resizeState.active) return;
            dragState.active = false;
            resizeState.active = false;
            panelEl.classList.remove('net-panel-dragging');
            captureGeometry();
            applyGeometry();      // clamp, in case it was dropped off-screen
            focusPanel();
        });

        // A stored position from a bigger screen must not strand the panel.
        window.addEventListener('resize', function () {
            if (geom.left !== -1) applyGeometry();
        });
    }

    /** Keep the widgets-toolbar button in step with the panel's state. */
    function syncToolbar() {
        if (window.WidgetManager && window.WidgetManager.syncFloatingToggle) {
            window.WidgetManager.syncFloatingToggle('net_checkins', isOpen);
        }
    }

    function load() {
        return fetch('api/net-checkins.php?action=list&history=' + historyCount,
                     { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) return;
                entries = data.entries || [];
                if (data.config) {
                    config = data.config;
                    if (!loaded) {
                        historyCount = (typeof config.history_count === 'number')
                            ? config.history_count : historyCount;
                        var hc = document.getElementById('netHistoryCount');
                        if (hc) hc.value = historyCount;
                    }
                }

                // FR-3: appear and take focus automatically whenever the
                // situational screen loads and there are un-worked entries.
                // Admin-overridable — a shared EOC wall display may not want
                // focus stolen from whoever is driving it.
                // Still admin-overridable, and now also respects the operator:
                // a panel closed from the widgets toolbar stays closed.
                if (!loaded) {
                    loaded = true;
                    if ((data.pending_count || 0) > 0 && isOpen) {
                        show(!!config.autofocus);
                    }
                }
                render();
            })
            .catch(function () { /* offline / auth — widget just stays quiet */ });
    }

    /**
     * Open the panel. Every caller — the widgets toolbar, `/net`, the
     * auto-appear — means "the operator wants this on screen", so opening is
     * recorded the same way closing is.
     */
    function show(takeFocus) {
        if (!panelEl) return;
        panelEl.classList.remove('d-none');
        applyGeometry();
        if (!isOpen) { isOpen = true; saveGeometry(); }
        syncToolbar();
        if (takeFocus) {
            selected = 0;
            // The panel itself is the focus target (tabindex="-1") so arrows
            // and hotkeys work with no mouse and without a field being active.
            try { panelEl.focus(); } catch (e) {}
        }
        render();
    }

    /**
     * Closing is a toolbar decision, and it is remembered. There is deliberately
     * no dismiss control on the panel itself: the way back has to be the same
     * place as for every other widget, or it is a one-way door.
     */
    function hide() {
        if (!panelEl) return;
        panelEl.classList.add('d-none');
        closePicker();
        if (isOpen) { isOpen = false; saveGeometry(); }
        syncToolbar();
    }

    function visible() {
        return computeVisible(entries, {
            order: config.order,
            historyCount: historyCount,
            showHistory: showHistory
        });
    }

    function render() {
        if (!bodyEl) return;
        var list = visible();
        if (selected >= list.length) selected = Math.max(0, list.length - 1);

        var countEl = document.getElementById('netCheckinCount');
        if (countEl) {
            var waiting = 0;
            for (var w = 0; w < entries.length; w++) {
                if (entries[w].status === 'pending') waiting++;
            }
            countEl.textContent = String(waiting);
        }

        renderActionBar(list[selected] || null);

        if (!list.length) {
            bodyEl.innerHTML = '<div class="text-body-secondary small px-2 py-3 text-center">'
                + 'No check-ins. Type <code>/net 1234 tornado / 3344 hail</code> to capture a round.'
                + '</div>';
            return;
        }

        var html = '<table class="table table-sm net-checkin-table mb-0"><tbody>';
        for (var i = 0; i < list.length; i++) {
            var e = list[i];
            var active = (i === selected) ? ' net-row-selected' : '';
            html += '<tr class="' + rowStateClass(e) + active + '" data-id="' + e.id + '" data-idx="' + i + '">';

            if (editingId === e.id) {
                // Inline edit — data-net-edit marks it as OUR field, so
                // shouldHandleKey lets Enter/Escape through and blocks the rest.
                html += '<td colspan="3" class="p-1">'
                     +  '<div class="input-group input-group-sm">'
                     +  '<input type="text" class="form-control form-control-sm" data-net-edit="1"'
                     +  ' id="netEditIdent" value="' + escHtml(e.identifier) + '" style="max-width:7rem"'
                     +  ' aria-label="Identifier">'
                     +  '<input type="text" class="form-control form-control-sm" data-net-edit="1"'
                     +  ' id="netEditNote" value="' + escHtml(e.note) + '" aria-label="Note">'
                     +  '<button class="btn btn-outline-success" type="button" id="netEditSave">'
                     +  '<i class="bi bi-check-lg"></i></button>'
                     +  '<button class="btn btn-outline-secondary" type="button" id="netEditCancel">'
                     +  '<i class="bi bi-x-lg"></i></button>'
                     +  '</div></td>';
            } else {
                html += '<td class="net-col-ident fw-semibold">' + escHtml(e.identifier) + '</td>'
                     +  '<td class="net-col-note">' + escHtml(e.note) + '</td>'
                     +  '<td class="net-col-time text-body-secondary small text-nowrap">'
                     +  escHtml(shortTime(e.created_at))
                     +  (e.status !== 'pending'
                            ? ' <span class="net-state-tag">' + escHtml(e.status) + '</span>'
                            : '')
                     +  '</td>';
            }
            html += '</tr>';
        }
        html += '</tbody></table>';
        bodyEl.innerHTML = html;

        // Mouse is optional, but clicking a row should still select it.
        var rows = bodyEl.querySelectorAll('tr[data-idx]');
        for (var r = 0; r < rows.length; r++) {
            rows[r].addEventListener('mousedown', function (ev) {
                var idx = parseInt(this.getAttribute('data-idx'), 10);
                if (!isNaN(idx)) { selected = idx; render(); }
                if (ev.target && ev.target.tagName === 'INPUT') return;
                ev.preventDefault();
                try { panelEl.focus(); } catch (e2) {}
            });
        }

        if (editingId !== null) bindEditRow();
        var selRow = bodyEl.querySelector('.net-row-selected');
        if (selRow && selRow.scrollIntoView) selRow.scrollIntoView({ block: 'nearest' });
    }

    function bindEditRow() {
        var save   = document.getElementById('netEditSave');
        var cancel = document.getElementById('netEditCancel');
        var ident  = document.getElementById('netEditIdent');
        if (save)   save.addEventListener('click', commitEdit);
        if (cancel) cancel.addEventListener('click', function () { editingId = null; render(); focusPanel(); });
        if (ident) { ident.focus(); ident.select(); }
    }

    function focusPanel() {
        try { panelEl.focus(); } catch (e) {}
    }

    function commitEdit() {
        var identEl = document.getElementById('netEditIdent');
        var noteEl  = document.getElementById('netEditNote');
        if (!identEl || editingId === null) return;
        var id = editingId;
        api({ action: 'update', id: id,
              identifier: identEl.value, note: noteEl ? noteEl.value : '' })
            .then(function () { editingId = null; return load(); })
            .then(function () { focusPanel(); });
    }

    // ── Hotkeys ───────────────────────────────────────────────────────────

    function onKeyDown(e) {
        if (!panelEl || panelEl.classList.contains('d-none')) return;
        if (e.ctrlKey || e.metaKey || e.altKey) return;

        var active = document.activeElement;

        // Exactly one keyboard owner at a time. Without this the dashboard's
        // keyboard-nav handler ran on the same keystroke and panned the map to
        // a unit nobody selected — a check-in has no location, so there was
        // nothing for the map to show and every reason for it to stay put.
        if (!panelOwnsKeyboard(panelEl, active, document.body,
                               window.KeyboardNav ? window.KeyboardNav.getFocusedWidget() : null)) {
            return;
        }

        // The inline edit box handles its own two keys first.
        if (active && active.getAttribute && active.getAttribute('data-net-edit') === '1') {
            if (e.key === 'Enter')  { e.preventDefault(); commitEdit(); return; }
            if (e.key === 'Escape') { e.preventDefault(); editingId = null; render(); focusPanel(); return; }
            return;
        }

        if (!shouldHandleKey(active, e.key)) return;

        // The picker owns the arrows and Enter while it is open.
        if (pickerOpen) {
            if (e.key === 'ArrowDown') { e.preventDefault(); movePicker(1);  return; }
            if (e.key === 'ArrowUp')   { e.preventDefault(); movePicker(-1); return; }
            if (e.key === 'Enter')     { e.preventDefault(); choosePickerIncident(); return; }
            if (e.key === 'Escape')    { e.preventDefault(); closePicker(); focusPanel(); return; }
            return;
        }

        var list = visible();
        var entry = list[selected] || null;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                if (list.length) { selected = (selected + 1) % list.length; render(); }
                return;
            case 'ArrowUp':
                e.preventDefault();
                if (list.length) { selected = (selected - 1 + list.length) % list.length; render(); }
                return;
            case 'Enter':
                if (!entry) return;
                e.preventDefault();
                runAction('new');
                return;
            case 'Escape':
                // Predictable and non-destructive: one level per press.
                e.preventDefault();
                if (editingId !== null) { editingId = null; render(); focusPanel(); return; }
                if (document.activeElement === panelEl) { panelEl.blur(); }
                return;
        }

        // Every letter hotkey goes through the SAME runAction() the buttons
        // call, so a key and its button can never drift apart — and a key
        // whose button is dimmed does nothing, which is what dimmed means.
        // Undo is the one that matters: a deleted check-in is a spotter nobody
        // called on, so getting one back has to be as cheap as losing it.
        var byKey = { a: 'append', e: 'edit', d: 'delete', u: 'undo', h: 'history' };
        var name = byKey[String(e.key).toLowerCase()];
        if (name) {
            e.preventDefault();
            runAction(name);
        }
    }

    // ── [Enter] — new incident ────────────────────────────────────────────

    /**
     * Hand off to the STANDARD new-incident screen carrying only the entry id.
     *
     * The identifier and note are fetched there rather than passed in the URL:
     * a callsign identifies a person, and personal data does not belong in a
     * query string. It also keeps one source of truth for the entry.
     *
     * The entry is NOT marked worked here. It is marked worked when the
     * incident is actually created (net-prefill.js -> onWorked), so abandoning
     * the form leaves the check-in correctly waiting.
     */
    function newIncidentFrom(entry) {
        window.location.href = 'new-incident.php?net_entry=' + encodeURIComponent(entry.id);
    }

    // ── [a] — append to an existing incident ──────────────────────────────

    function openPicker(entry) {
        if (!pickerEl) return;
        pickerOpen = true;
        pickerIdx = 0;
        pickerEl.classList.remove('d-none');
        pickerEl.innerHTML = '<div class="px-2 py-2 small text-body-secondary">Loading incidents…</div>';
        pickerEl.setAttribute('data-entry', String(entry.id));

        fetch('api/net-checkins.php?action=incidents&limit=25', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                pickerIncidents = (data && data.incidents) ? data.incidents : [];
                renderPicker();
            })
            .catch(function () {
                pickerEl.innerHTML = '<div class="px-2 py-2 small text-danger">Could not load incidents.</div>';
            });
    }

    function renderPicker() {
        if (!pickerEl) return;
        if (!pickerIncidents.length) {
            pickerEl.innerHTML = '<div class="px-2 py-2 small text-body-secondary">'
                + 'No open incidents. Press Esc, then Enter to create one.</div>';
            return;
        }
        var html = '<div class="net-picker-title px-2 py-1 small fw-semibold">'
                 + 'Append to which incident? (↑↓ then Enter, Esc to cancel)</div>'
                 + '<ul class="list-group list-group-flush net-picker-list">';
        for (var i = 0; i < pickerIncidents.length; i++) {
            var inc = pickerIncidents[i];
            var act = (i === pickerIdx) ? ' active' : '';
            var where = [inc.street, inc.city].filter(function (v) { return v; }).join(', ');
            html += '<li class="list-group-item list-group-item-action py-1' + act + '" data-pidx="' + i + '">'
                 +  '<span class="fw-semibold">#' + escHtml(inc.id) + '</span> '
                 +  escHtml(inc.scope || inc.type_name || '(no description)')
                 +  (where ? ' <span class="text-body-secondary small">' + escHtml(where) + '</span>' : '')
                 +  '</li>';
        }
        html += '</ul>';
        pickerEl.innerHTML = html;

        var items = pickerEl.querySelectorAll('[data-pidx]');
        for (var j = 0; j < items.length; j++) {
            items[j].addEventListener('mousedown', function (ev) {
                ev.preventDefault();
                pickerIdx = parseInt(this.getAttribute('data-pidx'), 10) || 0;
                choosePickerIncident();
            });
        }
        var sel = pickerEl.querySelector('.active');
        if (sel && sel.scrollIntoView) sel.scrollIntoView({ block: 'nearest' });
    }

    function movePicker(delta) {
        if (!pickerIncidents.length) return;
        pickerIdx = (pickerIdx + delta + pickerIncidents.length) % pickerIncidents.length;
        renderPicker();
    }

    function choosePickerIncident() {
        var inc = pickerIncidents[pickerIdx];
        if (!inc || !pickerEl) return;
        var entryId = pickerEl.getAttribute('data-entry');
        closePicker();
        // Same reasoning as newIncidentFrom(): pass the id, fetch the note there.
        window.location.href = 'incident-detail.php?id=' + encodeURIComponent(inc.id)
                             + '&net_entry=' + encodeURIComponent(entryId);
    }

    function closePicker() {
        pickerOpen = false;
        pickerIncidents = [];
        if (pickerEl) { pickerEl.classList.add('d-none'); pickerEl.innerHTML = ''; }
    }

    // ── Public surface ────────────────────────────────────────────────────

    window.NetCheckins = {
        // Called by new-incident.js / incident-detail.js once the incident or
        // the note actually exists. "Worked" then means real work happened,
        // not that a key was pressed.
        onWorked: function (entryId, ticketId) {
            if (!entryId) return Promise.resolve();
            return api({ action: 'work', id: parseInt(entryId, 10),
                         ticket_id: ticketId ? parseInt(ticketId, 10) : 0 });
        },
        reload: load,
        show: show,
        hide: hide,
        isOpen: function () { return isOpen; },
        // Exported for tests — driven under node so the real logic is what runs.
        computeVisible: computeVisible,
        shouldHandleKey: shouldHandleKey,
        panelOwnsKeyboard: panelOwnsKeyboard,
        actionState: actionState,
        clampGeometry: clampGeometry,
        rowStateClass: rowStateClass,
        shortTime: shortTime,
        _geometry: function () { return geom; }
    };

    // Register with the widgets toolbar. This runs at script-parse time —
    // widget-manager.js is loaded before this include and the toolbar buttons
    // are already in the DOM — so the toggle is live from the first paint,
    // which is the whole point: there must be a way back.
    if (window.WidgetManager && window.WidgetManager.registerFloating) {
        window.WidgetManager.registerFloating('net_checkins', {
            show:   function () { show(true); },
            hide:   hide,
            isOpen: function () { return isOpen; }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
