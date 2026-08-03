<?php
/**
 * NewUI v4.0 — Net-Control Check-In floating widget (Phase 131)
 *
 * Included by index.php (the dashboard — Eric's primary situational screen,
 * per the note in assets/js/command-bar.js) and by situation.php (the
 * full-screen EOC display), so `/net` lands somewhere useful from either.
 *
 * Renders nothing at all for a user without action.net_checkin, so the markup
 * is not a hint about a feature they cannot use.
 *
 * IT MUST LOOK AND BEHAVE LIKE THE OTHER WIDGETS (owner review, 2026-08-01).
 * Three conventions are borrowed here rather than approximated:
 *
 *   1. The header is the dashboard widget card header — a `card-header py-1
 *      px-2 d-flex align-items-center` with an icon + `small fw-semibold`
 *      title on the left and, on the right, a `d-flex align-items-center
 *      gap-1` group ending in the REFRESH control. That is exactly the
 *      shape assets/js/widget-manager.js builds for every grid widget.
 *   2. The hotkeys are an action bar — `btn btn-xs btn-outline-*` +
 *      `<i class="bi …">` + `<span class="action-label">` + `<kbd>`, the
 *      Responders widget's treatment, styled by the SHARED rules in
 *      assets/css/action-bar.css. Buttons that cannot act right now carry
 *      `disabled`, so they dim exactly like the other bars.
 *   3. There is NO dismiss control. Closing belongs to the widgets toolbar,
 *      like every other widget — a panel with an X and no toolbar entry is a
 *      one-way door, which is what the owner hit.
 *
 * NOTE ON BUTTONS: every <button> here carries an explicit type="button".
 * A <button> without one inside a <form> defaults to type=submit and reloads
 * the page — a documented past bug in this codebase (GH #84, the unit-edit
 * OwnTracks button that "immediately refreshes the page").
 */

if (!function_exists('rbac_can')) {
    require_once __DIR__ . '/rbac.php';
}
if (!rbac_can('action.net_checkin')) {
    return;
}

$__netAssetV = function (string $p): string {
    return function_exists('asset_v') ? asset_v($p) : newui_version();
};
// The caption key is shared with the widgets-toolbar button, so a per-install
// rename reaches both at once (the GH #70 convention for dash.widget.* keys).
$__netTitle = t('dash.widget.net_checkins', 'Check-Ins');
?>
<!-- action-bar.css is the shared definition of the widget action-bar styling.
     index.php also links it; situation.php does not, and this include is the
     only thing that puts the bar on that screen. A duplicate <link> to an
     identical URL costs one cache hit and nothing else. -->
<link rel="stylesheet" href="assets/css/action-bar.css?v=<?php echo $__netAssetV('assets/css/action-bar.css'); ?>">
<link rel="stylesheet" href="assets/css/net-checkins.css?v=<?php echo $__netAssetV('assets/css/net-checkins.css'); ?>">

<!-- Floating check-in list.
     tabindex="-1" so the panel itself can hold focus: the hotkeys work with no
     field focused and no mouse.
     data-kb-region tells assets/js/keyboard-nav.js that this panel owns the
     keyboard while focus is inside it. Without that the dashboard's own
     arrow/letter handler ran on the SAME keystroke: one ArrowDown moved the
     check-in selection AND panned the map to an unrelated unit, and `d`
     (delete a check-in) also fired the Responders "dispatch" action and
     navigated the operator off the dashboard mid-net. -->
<div class="net-checkin-panel card shadow d-none" id="netCheckinPanel" tabindex="-1"
     role="region" aria-label="Net control check-ins" data-kb-region="net-checkins">
    <div class="card-header py-1 px-2" id="netCheckinHeader">
        <!-- TITLE ROW. The refresh control must sit in the top-right corner at
             EVERY panel width, because that is where this application puts it on
             every other widget and an operator reaches for it without looking.
             It previously shared one flex line with the six action buttons, and
             at the default 30rem the line overflowed a panel that is
             `overflow:hidden` — so the history-count box was sliced off and the
             refresh control was not on screen at all until the panel was
             resized. The action bar therefore gets its own row below. -->
        <div class="net-checkin-titlerow d-flex align-items-center justify-content-between gap-1">
            <span class="small fw-semibold net-checkin-title">
                <i class="bi bi-broadcast-pin me-1"></i><?php echo e($__netTitle); ?>
                <span class="badge bg-primary rounded-pill ms-1" id="netCheckinCount">0</span>
            </span>

            <span class="d-flex align-items-center gap-1 flex-shrink-0">
                <!-- How many history rows. Same inline-numeric-in-the-header
                     shape as the Incidents widget's "keep closed N min". -->
                <input type="number" class="form-control form-control-sm net-history-count"
                       id="netHistoryCount" min="0" max="200" value="10"
                       title="How many historical check-ins to show"
                       aria-label="Number of historical check-ins to show">

                <!-- The top-right control on every widget in this application. -->
                <span class="widget-refresh text-body-secondary" id="netCheckinRefresh"
                      data-widget="net_checkins" style="cursor:pointer" role="button" tabindex="0"
                      title="Refresh" aria-label="Refresh the check-in list">
                    <i class="bi bi-arrow-clockwise"></i>
                </span>
            </span>
        </div>

        <!-- ACTION ROW. Same component as the Responders widget's V/E/D/S/N bar;
             wraps within itself on a narrow panel instead of overflowing. -->
        <div class="d-flex align-items-center gap-1 flex-wrap mt-1">
            <span class="net-checkin-action-bar d-flex align-items-center gap-1 flex-wrap" id="netCheckinActionBar">
                <button class="btn btn-xs btn-outline-primary net-action-btn" type="button"
                        data-net-action="new" title="New incident from the selected check-in">
                    <i class="bi bi-plus-circle me-1"></i><span class="action-label">New</span><kbd>&crarr;</kbd>
                </button>
                <button class="btn btn-xs btn-outline-success net-action-btn" type="button"
                        data-net-action="append" title="Append the note to an existing incident">
                    <i class="bi bi-box-arrow-in-down me-1"></i><span class="action-label">Append</span><kbd>A</kbd>
                </button>
                <button class="btn btn-xs btn-outline-secondary net-action-btn" type="button"
                        data-net-action="edit" title="Correct a misheard identifier or note">
                    <i class="bi bi-pencil me-1"></i><span class="action-label">Edit</span><kbd>E</kbd>
                </button>
                <button class="btn btn-xs btn-outline-danger net-action-btn" type="button"
                        data-net-action="delete" title="Delete the selected check-in">
                    <i class="bi bi-trash me-1"></i><span class="action-label">Delete</span><kbd>D</kbd>
                </button>
                <button class="btn btn-xs btn-outline-warning net-action-btn" type="button"
                        data-net-action="undo" title="Put a worked or deleted check-in back">
                    <i class="bi bi-arrow-counterclockwise me-1"></i><span class="action-label">Undo</span><kbd>U</kbd>
                </button>
                <button class="btn btn-xs btn-outline-info net-action-btn" type="button"
                        data-net-action="history" aria-pressed="false"
                        title="Show worked and deleted check-ins">
                    <i class="bi bi-clock-history me-1"></i><span class="action-label">History</span><kbd>H</kbd>
                </button>
            </span>
        </div>
    </div>

    <div class="net-checkin-body" id="netCheckinBody"></div>

    <!-- [a] append: incident chooser, arrow + Enter, no mouse required -->
    <div class="net-checkin-picker d-none" id="netCheckinPicker"></div>

    <!-- Drag/resize are conveniences only: every action above has a hotkey and
         the panel is fully usable from the keyboard without ever moving it. -->
    <div class="net-checkin-resize-handle" id="netCheckinResize" aria-hidden="true"></div>
</div>

<script src="assets/js/net-checkins.js?v=<?php echo $__netAssetV('assets/js/net-checkins.js'); ?>"></script>
