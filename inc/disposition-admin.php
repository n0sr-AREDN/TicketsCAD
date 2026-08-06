<?php
/**
 * Phase 132 (2026-08-03) — Structured incident disposition, Step 3:
 * Settings-panel admin CRUD (list / save / retire / reactivate /
 * set_enforcement). See
 * specs/phase-132-incident-disposition/{spec.md,plan.md,tasks.md}.
 * Steps 1 (sql/run_phase132_disposition.php) and 2
 * (inc/incident-write.php's incident_set_disposition_internal(),
 * api/incident-update.php's set_disposition action) are untouched and
 * separate.
 *
 * Business logic lives HERE, not in api/dispositions.php, for the same
 * reason Step 2 put incident_set_disposition_internal() in
 * inc/incident-write.php rather than the endpoint: so
 * tests/test_phase132_settings_panel.php can drive the REAL writer
 * directly (CLAUDE.md "reproduce bugs through the REAL creation path")
 * without faking an HTTP request, and so any future caller (a CLI
 * seeding script, a bulk import) goes through the same validation
 * instead of growing its own copy. api/dispositions.php owns only
 * auth/RBAC/CSRF and JSON response shaping.
 *
 * RETIREMENT, NEVER DELETION (plan.md §2): `active` flips to 0.
 * Nothing in this file ever DELETEs a ticket_disposition row. An
 * incident may reference one via ticket.disposition_id — there is no FK
 * constraint enforcing that — so deleting the row would silently orphan
 * the reference and break every read that resolves the label. Retiring
 * only affects NEW assignment (enforced in
 * incident_set_disposition_internal()); an incident that already
 * carries a since-retired disposition keeps reading it unchanged.
 *
 * `code` IS IMMUTABLE ONCE CREATED (plan.md §1: "stable export/
 * integration key, distinct from the label"). disposition_save_internal()
 * accepts `code` from the input only when creating (id <= 0). On update
 * the input's `code` is deliberately IGNORED, not validated-and-rejected
 * — the Settings panel resubmits the (disabled/read-only) code field
 * verbatim on every edit, and treating an unchanged resubmission as an
 * error would be a false alarm for the normal case. The stored value is
 * simply never touched by the UPDATE statement.
 *
 * UNIQUENESS is enforced at the APPLICATION level, matching Step 1's
 * migration — there is no DB-level UNIQUE key on (code, org_id) because
 * org_id is nullable and MySQL/MariaDB treat every NULL in a UNIQUE
 * index as a DISTINCT value (Phase 129's uk_user_role_org lesson).
 * disposition_code_exists() does the same NULL-safe existence check
 * sql/run_phase132_disposition.php uses for its own seed idempotency.
 *
 * Step 4 (2026-08-04, GH #16) added disposition_options_for_ticket_
 * internal() at the bottom of this file — the OFFERED-list builder for
 * api/dispositions-picker.php (the incident-detail dropdown + close-flow
 * dropdown). Business logic, same split as everything above: so
 * tests/test_phase132_incident_detail.php can drive the real filtering/
 * fallback logic directly rather than faking an HTTP request.
 */

/**
 * All dispositions — active AND retired (the admin panel shows both,
 * with a visual badge distinguishing them) — plus the current value of
 * the disposition_required_on_close enforcement setting, in one
 * response so the panel's toggle can initialize from a single fetch.
 *
 * Read with get_variable() — NEVER get_setting() (CLAUDE.md "TWO
 * settings stores", GH #79): the Settings UI / disposition_set_
 * enforcement_internal() below write to the `settings` table, which
 * get_variable() reads and get_setting() does not.
 */
function disposition_list_internal(): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $rows = db_fetch_all(
        "SELECT `id`, `status_val`, `description`, `code`, `discipline`, `org_id`,
                `sort_order`, `requires_comment`, `active`
           FROM `{$prefix}ticket_disposition`
          ORDER BY `sort_order`, `id`"
    );
    foreach ($rows as &$row) {
        $row['id']               = (int) $row['id'];
        $row['org_id']           = ($row['org_id'] !== null) ? (int) $row['org_id'] : null;
        $row['sort_order']       = (int) $row['sort_order'];
        $row['requires_comment'] = (int) $row['requires_comment'];
        $row['active']           = (int) $row['active'];
    }
    unset($row);

    $enforcement = function_exists('get_variable') ? get_variable('disposition_required_on_close') : false;
    $enforcement = ($enforcement === '1' || $enforcement === 1) ? '1' : '0';

    return ['dispositions' => $rows, 'disposition_required_on_close' => $enforcement];
}

/**
 * NULL-safe (code, org_id) existence check — see file docblock
 * "UNIQUENESS". $excludeId lets an update check "does anything ELSE
 * already have this code" (not currently used — code is immutable on
 * update — but kept for a future caller that might need it).
 */
function disposition_code_exists(string $code, ?int $orgId, ?int $excludeId = null): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($orgId === null) {
        $sql    = "SELECT `id` FROM `{$prefix}ticket_disposition` WHERE `code` = ? AND `org_id` IS NULL";
        $params = [$code];
    } else {
        $sql    = "SELECT `id` FROM `{$prefix}ticket_disposition` WHERE `code` = ? AND `org_id` = ?";
        $params = [$code, $orgId];
    }
    if ($excludeId !== null) {
        $sql      .= " AND `id` != ?";
        $params[]  = $excludeId;
    }
    $sql .= " LIMIT 1";

    $row = db_fetch_value($sql, $params);
    return $row !== null && $row !== false;
}

/**
 * Wrapped so an audit-log failure never breaks the underlying write
 * (project standing audit rule; plan.md §4 says the same for the
 * disposition-on-incident writer).
 */
function _disposition_audit(string $activity, int $id, ?array $before, array $after, int $userId): void {
    if (!function_exists('audit_log') && is_file(__DIR__ . '/audit.php')) {
        require_once __DIR__ . '/audit.php';
    }
    if (!function_exists('audit_log')) return;

    try {
        $label = $after['status_val'] ?? ($before['status_val'] ?? ('#' . $id));
        audit_log('config', $activity, 'ticket_disposition', $id,
            ucfirst($activity) . "d incident disposition '{$label}'",
            ['before' => $before, 'after' => $after, 'user_id' => $userId]);
    } catch (Throwable $e) {
        error_log('[disposition-admin] audit_log failed for ' . $activity
            . ' on ticket_disposition ' . $id . ': ' . $e->getMessage());
    }
}

/**
 * Create (id <= 0 / omitted) or update (id > 0) a disposition.
 *
 * @param array $input  status_val, description, code (create only),
 *                       discipline, org_id (null/0/''=every org),
 *                       sort_order, requires_comment
 * @return array{success:bool, id?:int, error?:string}
 */
function disposition_save_internal(array $input, int $userId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $id              = (int) ($input['id'] ?? 0);
    $statusVal       = mb_substr(trim((string) ($input['status_val'] ?? '')), 0, 64);
    $description     = trim((string) ($input['description'] ?? ''));
    $discipline      = mb_substr(trim((string) ($input['discipline'] ?? '')), 0, 32);
    $sortOrder       = (int) ($input['sort_order'] ?? 0);
    $requiresComment = !empty($input['requires_comment']) ? 1 : 0;

    $orgIdRaw = $input['org_id'] ?? null;
    $orgId    = ($orgIdRaw !== null && $orgIdRaw !== '' && (int) $orgIdRaw > 0) ? (int) $orgIdRaw : null;

    if ($statusVal === '') {
        return ['success' => false, 'error' => 'Label is required.'];
    }

    if ($id > 0) {
        $existing = db_fetch_one("SELECT * FROM `{$prefix}ticket_disposition` WHERE `id` = ?", [$id]);
        if (!$existing) {
            return ['success' => false, 'error' => 'Disposition not found.'];
        }

        // `code` is NOT in this UPDATE's column list — see file docblock
        // "code IS IMMUTABLE ONCE CREATED". Whatever the request sent is
        // silently ignored; the stored value is untouched.
        try {
            db_query(
                "UPDATE `{$prefix}ticket_disposition`
                    SET `status_val` = ?, `description` = ?, `discipline` = ?, `org_id` = ?,
                        `sort_order` = ?, `requires_comment` = ?
                  WHERE `id` = ?",
                [$statusVal, $description, $discipline, $orgId, $sortOrder, $requiresComment, $id]
            );
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Save failed: ' . $e->getMessage()];
        }

        _disposition_audit('update', $id, $existing, [
            'status_val'       => $statusVal,
            'code'             => $existing['code'],
            'description'      => $description,
            'discipline'       => $discipline,
            'org_id'           => $orgId,
            'sort_order'       => $sortOrder,
            'requires_comment' => $requiresComment,
        ], $userId);

        return ['success' => true, 'id' => $id];
    }

    // Create — code required, and application-level-unique per (code, org_id).
    $code = mb_substr(trim((string) ($input['code'] ?? '')), 0, 64);
    if ($code === '') {
        return ['success' => false, 'error' => 'Code is required.'];
    }
    if (disposition_code_exists($code, $orgId)) {
        return ['success' => false,
            'error' => 'A disposition with this code already exists for this org scope.'];
    }

    try {
        db_query(
            "INSERT INTO `{$prefix}ticket_disposition`
                (`status_val`, `description`, `code`, `discipline`, `org_id`, `sort_order`,
                 `requires_comment`, `active`)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
            [$statusVal, $description, $code, $discipline, $orgId, $sortOrder, $requiresComment]
        );
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Save failed: ' . $e->getMessage()];
    }

    $id = (int) db_insert_id();
    _disposition_audit('create', $id, null, [
        'status_val'       => $statusVal,
        'code'             => $code,
        'description'      => $description,
        'discipline'       => $discipline,
        'org_id'           => $orgId,
        'sort_order'       => $sortOrder,
        'requires_comment' => $requiresComment,
    ], $userId);

    return ['success' => true, 'id' => $id];
}

/**
 * Retire (active=0) or reactivate (active=1) — NEVER a DELETE. See file
 * docblock "RETIREMENT, NEVER DELETION".
 *
 * @return array{success:bool, id?:int, active?:int, error?:string}
 */
function disposition_set_active_internal(int $id, bool $active, int $userId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $existing = db_fetch_one("SELECT * FROM `{$prefix}ticket_disposition` WHERE `id` = ?", [$id]);
    if (!$existing) {
        return ['success' => false, 'error' => 'Disposition not found.'];
    }

    $newActive = $active ? 1 : 0;
    try {
        db_query("UPDATE `{$prefix}ticket_disposition` SET `active` = ? WHERE `id` = ?", [$newActive, $id]);
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Update failed: ' . $e->getMessage()];
    }

    _disposition_audit($active ? 'reactivate' : 'retire', $id, $existing,
        ['status_val' => $existing['status_val'], 'code' => $existing['code'], 'active' => $newActive],
        $userId);

    return ['success' => true, 'id' => $id, 'active' => $newActive];
}

/**
 * Write disposition_required_on_close through the SAME store the
 * close-enforcement gate reads (`settings` table / get_variable() —
 * CLAUDE.md "TWO settings stores", GH #79 — NOT the separate `config`
 * table read by get_setting()). settings.name carries a UNIQUE key
 * (Phase 24, sql/run_phase24_settings_unique_name.php), so
 * INSERT ... ON DUPLICATE KEY UPDATE genuinely updates in place here.
 *
 * @return array{success:bool, value?:string, error?:string}
 */
function disposition_set_enforcement_internal(string $value, int $userId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $v = ($value === '1' || $value === 1) ? '1' : '0';

    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('disposition_required_on_close', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$v]
        );
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Save failed: ' . $e->getMessage()];
    }

    if (!function_exists('audit_log') && is_file(__DIR__ . '/audit.php')) {
        require_once __DIR__ . '/audit.php';
    }
    if (function_exists('audit_log')) {
        try {
            audit_log('config', 'update', 'settings', null,
                "Updated disposition_required_on_close = {$v}",
                ['disposition_required_on_close' => $v, 'user_id' => $userId]);
        } catch (Throwable $e) {
            error_log('[disposition-admin] audit_log failed for set_enforcement: ' . $e->getMessage());
        }
    }

    return ['success' => true, 'value' => $v];
}

/**
 * Phase 132 Step 4 (2026-08-04, GH #16) — the OFFERED-dispositions list
 * for a specific incident: ACTIVE dispositions scoped to the ticket's org
 * (`org_id IS NULL` = every org — same `org_id` precedent as
 * `member_types`/`teams`, spec.md "In scope"), further filtered by
 * discipline (the incident type's `in_types.group` against each
 * disposition's `discipline`; `discipline = ''` is always offered,
 * plan.md §1).
 *
 * HARD INVARIANT (plan.md §1, tasks.md Step 4 item 4): NEVER returns a
 * truncated/empty list when active (org-scoped) dispositions exist. If
 * the incident's type has no discipline tag, OR its tag matches NO
 * active disposition's discipline, this falls back to the FULL
 * (org-scoped) active list rather than an empty/narrow one. A long
 * dropdown is a far better failure than one missing the entry a
 * dispatcher needs.
 *
 * The filtering here is presentation ONLY (spec.md "Filtering is
 * presentation, not validation") — incident_set_disposition_internal()
 * (Step 2, unchanged by this function) validates only existence + active,
 * never discipline or org, so any active disposition remains storable via
 * the API even when this list would not have offered it.
 * tests/test_phase132_incident_detail.php proves that directly by calling
 * the writer with a discipline-mismatched id.
 *
 * Also always surfaces the incident's CURRENTLY-SET disposition via
 * current_id/current_label/current_retired, even when it would have been
 * excluded from `dispositions` by discipline, org, or retirement — an
 * incident must always be able to display (and keep selected) its own
 * recorded value. Mirrors the "stays readable after retirement" guarantee
 * Step 2 already gives the write path; here the SAME guarantee applies to
 * the read/offer side.
 *
 * Read-only, used by api/dispositions-picker.php — NOT gated on
 * action.manage_dispositions (plan.md §8: selecting a disposition needs
 * no permission, only ordinary incident access, which the endpoint
 * checks via user_can_access_entity()/org_can_see_ticket() before calling
 * this).
 *
 * @return array{
 *   dispositions: array   list of {id, status_val, description, code,
 *                          discipline, org_id, requires_comment} — the
 *                          offered set (active, org+discipline filtered,
 *                          with the hard fallback), PLUS the incident's
 *                          current value appended if it was excluded by
 *                          that filter (retired and/or org/discipline
 *                          mismatch)
 *   current_id: int|null
 *   current_label: string|null   resolved even when current_id was
 *                                 excluded from `dispositions`
 *   current_retired: bool        true only when the current value's
 *                                 `active` = 0 (not merely excluded by
 *                                 org/discipline)
 *   disposition_required_on_close: '0'|'1'
 * }
 */
function disposition_options_for_ticket_internal(int $ticketId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $enforcement = function_exists('get_variable') ? get_variable('disposition_required_on_close') : false;
    $enforcement = ($enforcement === '1' || $enforcement === 1) ? '1' : '0';

    $empty = [
        'dispositions' => [],
        'current_id' => null,
        'current_label' => null,
        'current_retired' => false,
        'disposition_required_on_close' => $enforcement,
    ];

    if ($ticketId <= 0) return $empty;

    // Resolve the ticket's type discipline (in_types.group), org_id, and
    // its currently-set disposition_id in one query — defensively
    // guarded: an install mid-upgrade (Step 1's ticket.disposition_id
    // column not yet migrated) degrades to "no tag, no org, no current
    // value" rather than a 500 (CLAUDE.md schema-resilience pattern).
    $typeGroup   = '';
    $ticketOrgId = null;
    $currentId   = null;
    try {
        $t = db_fetch_one(
            "SELECT `t`.`disposition_id`, `t`.`org_id`, `it`.`group` AS `type_group`
               FROM `{$prefix}ticket` `t`
               LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
              WHERE `t`.`id` = ?",
            [$ticketId]
        );
        if ($t !== null) {
            $typeGroup   = trim((string) ($t['type_group'] ?? ''));
            $ticketOrgId = ($t['org_id'] !== null && $t['org_id'] !== '') ? (int) $t['org_id'] : null;
            $currentId   = ($t['disposition_id'] !== null && (int) $t['disposition_id'] > 0)
                ? (int) $t['disposition_id'] : null;
        }
    } catch (Exception $e) {
        // Pre-migration install or missing ticket — fall through with
        // defaults (empty discipline, no org, no current value). The
        // active-list query below still runs on its own guard.
    }

    // Active dispositions scoped to this ticket's org. NULL org_id on
    // ticket_disposition = "every org"; when $ticketOrgId is itself null,
    // ordinary SQL NULL semantics mean only NULL-org rows match — no
    // special-casing needed.
    $rows = [];
    try {
        $rows = db_fetch_all(
            "SELECT `id`, `status_val`, `description`, `code`, `discipline`, `org_id`, `requires_comment`
               FROM `{$prefix}ticket_disposition`
              WHERE `active` = 1
                AND (`org_id` IS NULL OR `org_id` = ?)
              ORDER BY `sort_order`, `id`",
            [$ticketOrgId]
        );
    } catch (Exception $e) {
        return $empty; // ticket_disposition table itself missing (pre-migration)
    }
    foreach ($rows as &$r) {
        $r['id']               = (int) $r['id'];
        $r['org_id']           = $r['org_id'] !== null ? (int) $r['org_id'] : null;
        $r['requires_comment'] = (int) $r['requires_comment'];
    }
    unset($r);

    // Discipline filter, with the hard invariant's fallback.
    $hasMatch = false;
    if ($typeGroup !== '') {
        foreach ($rows as $r) {
            if ($r['discipline'] === $typeGroup) { $hasMatch = true; break; }
        }
    }
    if ($typeGroup !== '' && $hasMatch) {
        $filtered = array_values(array_filter($rows, function ($r) use ($typeGroup) {
            return $r['discipline'] === '' || $r['discipline'] === $typeGroup;
        }));
    } else {
        // HARD INVARIANT — an untagged type, or a tag matching nothing
        // active, must never narrow the list. Show everything instead.
        $filtered = $rows;
    }

    // Always surface the incident's current value, even if excluded above.
    $currentLabel   = null;
    $currentRetired = false;
    if ($currentId !== null) {
        $present = false;
        foreach ($filtered as $r) {
            if ($r['id'] === $currentId) { $present = true; $currentLabel = $r['status_val']; break; }
        }
        if (!$present) {
            try {
                $cur = db_fetch_one(
                    "SELECT `id`, `status_val`, `description`, `code`, `discipline`, `org_id`,
                            `requires_comment`, `active`
                       FROM `{$prefix}ticket_disposition` WHERE `id` = ?",
                    [$currentId]
                );
            } catch (Exception $e) {
                $cur = null;
            }
            if ($cur !== null) {
                $currentLabel   = $cur['status_val'];
                $currentRetired = ((int) $cur['active']) !== 1;
                $filtered[] = [
                    'id'               => (int) $cur['id'],
                    'status_val'       => $cur['status_val'],
                    'description'      => $cur['description'],
                    'code'             => $cur['code'],
                    'discipline'       => $cur['discipline'],
                    'org_id'           => $cur['org_id'] !== null ? (int) $cur['org_id'] : null,
                    'requires_comment' => (int) $cur['requires_comment'],
                ];
            }
        }
    }

    return [
        'dispositions' => $filtered,
        'current_id' => $currentId,
        'current_label' => $currentLabel,
        'current_retired' => $currentRetired,
        'disposition_required_on_close' => $enforcement,
    ];
}
