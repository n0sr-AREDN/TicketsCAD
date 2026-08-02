<?php
/**
 * settings-secrets.php — classify which `settings` keys hold secrets.
 *
 * Secret values (API tokens, passwords, webhook URLs) must NEVER be sent to
 * the browser. api/config-admin.php's `GET settings` returns a `<key>_set`
 * boolean for these instead of the value; the UI shows "stored / not set" and
 * the save path keeps the stored value when the field is left blank.
 *
 * Kept in its own include (not inline in the endpoint) so it is unit-testable
 * — see tests/test_settings_secret_masking.php.
 */

if (!function_exists('is_secret_setting_key')) {

    // Explicit secret keys (match the data-secret form fields in settings.php).
    // The suffix backstop below masks future secret keys by default, but listing
    // the known ones keeps intent clear and survives renamed suffixes.
    function _secret_setting_keys(): array {
        return [
            'sms_twilio_token', 'sms_bulkvs_api_key', 'sms_bulkvs_secret',
            'sms_pushbullet_token', 'sms_generic_api_key',
            'smtp_pass', 'slack_token', 'slack_webhook',
        ];
    }

    /**
     * True if the given settings key holds a secret and must be masked before
     * being returned to a client.
     */
    /**
     * Names that LOOK like credentials to the suffix backstop but are not.
     *
     * `location_ingest_require_token` and `owntracks_require_token` are
     * booleans — "require a token: yes/no" — and masking them is actively
     * harmful, not merely wrong: the GET withholds the value, the checkbox
     * renders unchecked whatever the real state, and the next save writes
     * that back. A `require_token` switch that was ON silently turns OFF,
     * disabling authentication on the ingest endpoint.
     *
     * Found while fixing openises/TicketsCAD#7 (@rjonesbsink), which reported
     * the opposite failure — a genuine secret being blanked — and correctly
     * predicted the suffix-based classifier deserved an audit.
     */
    function _non_secret_setting_keys(): array {
        return [
            'location_ingest_require_token',
            'owntracks_require_token',
        ];
    }

    function is_secret_setting_key(string $name): bool {
        if (in_array($name, _non_secret_setting_keys(), true)) return false;
        if (in_array($name, _secret_setting_keys(), true)) return true;
        // Suffix backstop: anything that looks like a credential is masked so a
        // newly-added secret setting is safe by default. Non-secret keys (e.g.
        // sms_twilio_sid, push_vapid_public, smtp_host) don't match.
        return (bool) preg_match(
            '/(_token|_secret|_password|_passwd|_pass|_api_key|_apikey|_auth_token|_webhook|_private)$/i',
            $name
        );
    }

    /**
     * True if $value carries no new credential — i.e. it is blank, or it is one
     * of the placeholders the UI renders IN PLACE OF a stored secret.
     *
     * Because `GET settings` returns `<key>_set` rather than the value, the
     * browser never holds a real secret and therefore CANNOT echo one back. So
     * a blank (or placeholder) arriving at the save path always means "I did
     * not retype it" — never "clear it". Writing it through blanks a working
     * credential; see openises/TicketsCAD#7.
     *
     * Consequence worth knowing: a secret cannot be CLEARED by emptying its box
     * (that has never worked — collectSettingsFromForm() has skipped blank
     * data-secret fields since 2026-06-25). Deleting the `settings` row is the
     * deliberate way to unset one.
     */
    function is_masked_secret_value($value): bool {
        if (!is_scalar($value)) return true;          // arrays/null carry nothing
        $v = trim((string) $value);
        if ($v === '') return true;
        // Runs of the glyphs used to mask a stored value: • ● * · ×
        if (preg_match('/^[\x{2022}\x{25CF}\x{00B7}\x{00D7}*]+$/u', $v)) return true;
        // Literal sentinels rendered by the UI (see push_vapid_private_set).
        return in_array(strtolower($v), [
            '(set, hidden)', '(not set)', '(stored)', '(unchanged)', 'stored',
        ], true);
    }
}
