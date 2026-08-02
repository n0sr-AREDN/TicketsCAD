/**
 * NewUI v4.0 — Net check-in prefill (Phase 131)
 *
 * Loaded on new-incident.php and incident-detail.php. When the operator
 * arrived from the check-in widget (`?net_entry=N`), this fetches that entry
 * and drops it into the form the operator was already going to type into:
 *
 *   new-incident.php     identifier -> #phone (Caller / Contact phone number)
 *                        note       -> #description
 *   incident-detail.php  note       -> #noteText (activity log)
 *
 * IN BOTH CASES the cursor lands immediately AFTER the copied text, because
 * the operator is mid-sentence with a spotter on the radio and the next thing
 * they do is keep typing the report.
 *
 * It deliberately does NOT touch the incident type. The new-incident screen
 * already auto-selects the type by regular expression from the incident-types
 * configuration (in_types.match_pattern, matched on the description field's
 * blur). Filling the description and getting out of the way is the whole
 * integration — there is no type-mapping logic here and there must not be.
 *
 * ES5 only (project rule).
 */
(function () {
    'use strict';

    function param(name) {
        try {
            return new URLSearchParams(window.location.search).get(name);
        } catch (e) {
            var m = new RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search);
            return m ? decodeURIComponent(m[1]) : null;
        }
    }

    /** Put the caret at the end of a field and focus it. */
    function focusAtEnd(el) {
        if (!el) return;
        try {
            el.focus();
            var end = el.value.length;
            if (el.setSelectionRange) el.setSelectionRange(end, end);
        } catch (e) { /* older browser — focus alone is still useful */ }
    }

    function init() {
        var entryId = param('net_entry');
        if (!entryId) return;

        fetch('api/net-checkins.php?action=entry&id=' + encodeURIComponent(entryId),
              { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error || !data.entry) return;
                var entry = data.entry;

                // Remember which check-in this page is working, so the
                // create/save hooks can mark it worked once the work is real.
                window.NET_ENTRY_ID = parseInt(entryId, 10);

                var phone = document.getElementById('phone');
                var desc  = document.getElementById('description');
                var note  = document.getElementById('noteText');

                if (phone && desc) {
                    // new-incident.php
                    phone.value = entry.identifier || '';

                    // A trailing space when there IS a note: the operator is
                    // continuing a sentence ("hail" -> "hail 3/4 inch at ...").
                    desc.value = entry.note ? (entry.note + ' ') : '';
                    focusAtEnd(desc);

                    // Let the existing constituent lookup resolve the
                    // identifier to a name, exactly as if it had been typed.
                    // (See the reference-lookup fix in new-incident.js.)
                    if (phone.value && typeof Event === 'function') {
                        try { phone.dispatchEvent(new Event('blur')); } catch (e) {}
                    }
                } else if (note) {
                    // incident-detail.php — the activity log box
                    note.value = entry.note ? (entry.note + ' ') : '';
                    focusAtEnd(note);
                    // The Add Note button enables on input; tell it we typed.
                    try { note.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
                }
            })
            .catch(function () { /* the operator can still type it by hand */ });
    }

    /**
     * Called by new-incident.js and incident-detail.js once the incident or the
     * note actually exists. Marking "worked" here rather than at keypress time
     * means an abandoned form leaves the check-in still waiting — a spotter
     * nobody called on is the failure this whole feature exists to prevent.
     */
    window.NetPrefill = {
        markWorked: function (ticketId) {
            if (!window.NET_ENTRY_ID) return;
            var entryId = window.NET_ENTRY_ID;
            window.NET_ENTRY_ID = null;    // once only, even if a handler re-fires

            var meta = document.querySelector('meta[name="csrf-token"]');
            var tokenEl = document.getElementById('csrfToken');
            var token = (tokenEl && tokenEl.value)
                ? tokenEl.value
                : (meta ? meta.getAttribute('content') : (window.CSRF_TOKEN || ''));

            fetch('api/net-checkins.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'work',
                    id: entryId,
                    ticket_id: ticketId ? parseInt(ticketId, 10) : 0,
                    csrf_token: token
                })
            }).catch(function () { /* best effort — never block the operator */ });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
