# Routing Engine — Reference

**Audience:** admins configuring message routing rules, developers extending the engine.
**Implementation:** [`inc/router.php`](../inc/router.php).
**UI:** Settings → Communications & Integrations → Message Routing.

The routing engine forwards messages between [channels](GLOSSARY.md#channel) using user-configured rules. Voice transcripts from DMR, mesh chat, SMS, Slack, internal chat, and HTTP webhooks all flow through the same [broker](GLOSSARY.md#broker), so a single routing rule can bridge any combination.

---

## Mental model

```
                ┌────────────────────────────────────────────┐
                │       broker_send(channel, message)         │
                │       broker_receive(channel, message)      │
                └─────────────────┬──────────────────────────┘
                                  │
                                  ▼
                ┌──────────────────────────────────┐
                │   router_evaluate(channel,        │
                │       direction, message)         │
                │                                   │
                │   1. Discard untrusted metadata   │
                │   2. Load enabled routes for ch.  │
                │   3. For each route:              │
                │        - Match filters            │
                │        - If matched: forward      │
                │   4. Log every decision           │
                └──────────────────┬───────────────┘
                                   │
                       (each matching route)
                                   ▼
                ┌──────────────────────────────────┐
                │   router_forward(route, message)  │
                │   - Apply transform              │
                │   - Set _is_routed_forward = 1   │
                │   - broker_send(dest, forwarded) │
                └──────────────────────────────────┘
```

**Key idea:** "all matches fire". A single inbound message can trigger zero, one, or many routes — they don't compete; they're independent rules. If you want exclusive routing, use the priority field to order them and make filters mutually-exclusive.

---

## Route schema

Each row in the **`message_routes`** table:

| Column | Type | Purpose |
|---|---|---|
| `id` | int unsigned | PK |
| `enabled` | tinyint | 0 = rule is ignored |
| `priority` | int | Lower = evaluated first (within all-matches-fire semantics, this just controls log order) |
| `name` | varchar(100) | Human-readable label for the admin UI |
| `description` | varchar(255) | Longer note shown in the admin UI |
| `source_channel` | varchar(64) | The channel the message arrived on (`meshtastic`, `dmr`, `local_chat`, `slack`, `sms`, `audit_event`, etc.). `*` matches any channel. |
| `direction` | enum | `inbound`, `outbound`, or `both` |
| `dest_channel` | varchar(64) | The channel the forwarded copy gets sent to |
| `filters_json` | JSON | See [Filters](#filters) below |
| `transform_json` | JSON | See [Transforms](#transforms) below |
| `recipient_predicate_json` | JSON | Who the forwarded copy goes to. See [Recipient predicates](#recipient-predicates) below. |
| `dest_subaddress_json` | JSON | Destination sub-address (e.g. a specific talkgroup or chat sub-channel) for adapters that accept one |
| `attach_action` | varchar(16) | Optional incident attachment on forward — currently `add_note` |
| `attach_ticket_id` | int | Fixed ticket id for `attach_action`, when not derived from the message |
| `created_at` / `updated_at` | datetime | Audit |
| `created_by` | int unsigned | User id of the admin who created it |

> The table is `message_routes`. There is no table called `routes` — earlier
> revisions of this page said there was, and every SQL example on it failed with
> `Base table or view not found`.

### Example row

```sql
INSERT INTO message_routes
  (enabled, priority, name, description, source_channel, direction,
   dest_channel, filters_json, transform_json)
VALUES
  (1, 100, 'DMR urgent → dispatch chat', 'Bridge urgent radio traffic to dispatch',
   'dmr', 'inbound',
   'local_chat',
   '{"priority_in":["urgent"],"exclude_keywords":["test","drill"]}',
   '{"prefix":"[DMR] "}');
```

When an urgent message arrives on the DMR channel and does not mention "test" or
"drill", this rule synthesises a chat message with body `"[DMR] " + transcript`
and posts it to `local_chat`.

**Two preconditions that are not on this row**, and that account for most routes
which appear correct and never deliver:

1. `dest_channel` must also be listed in the **`broker_enabled_channels`**
   setting. If it is not, the route logs `skipped` with
   `Destination channel 'X' is not enabled` (`inc/router.php:360`). Two
   otherwise-identical routes will behave differently if one destination is in
   that list and the other is not, and nothing in the routing UI shows it.
2. For the shipped seed routes, `recipient_predicate_json` must match at least
   one user — see below.

---

## Filters

`filters_json` is a JSON object. Every present key is ANDed. Empty / missing keys are ignored.

### Supported filter keys

These are the keys `_router_match_filters()` reads. **An unrecognised key is
ignored, not rejected** — a rule carrying one is saved happily by the UI and
matches as though that condition were absent, so a misspelt key makes a route
broader than intended rather than failing loudly.

| Key | Type | Matches when |
|---|---|---|
| `incident_type_ids` | int[] | `message.in_types_id` (or `message.incident_type_id`) is in the list. These are `in_types.id` values, not type names. |
| `severity_min` | int | `message.severity` **≥** this value |
| `priority_in` | string[] | `message.priority` in list (`urgent`, `high`, `normal`, `low`) |
| `sender_roles` | int[] | `message.sender_role` in list. RBAC **role ids** (`roles.id`), not role names, and not the retired `user.level`. |
| `keywords` | string[] | Any keyword appears in `message.body` (case-insensitive substring) |
| `exclude_keywords` | string[] | If any of these appears in `message.body`, the route is SKIPPED |
| `incident_id` | int | `message.ticket_id` (or `message.incident_id`) equals this value — pins a route to one incident |

There is no talkgroup, chat-sub-channel, direction or time-window filter. The
route's own `direction` column is what enforces direction.

### Filter examples

**Only urgent + high-priority messages:**
```json
{"priority_in": ["urgent", "high"]}
```

**Only traffic that doesn't mention "test" or "drill":**
```json
{
  "exclude_keywords": ["test", "drill"]
}
```

**Only severity 3+ messages from Dispatchers (role id 3) on selected incident types:**
```json
{
  "incident_type_ids": [12, 14],
  "sender_roles": [3],
  "severity_min": 3
}
```

---

## Transforms

`transform_json` reshapes the forwarded copy. Like filters, every present key applies; missing keys leave the field unchanged.

These are the keys `_router_transform()` reads. As with filters, an unrecognised
key is silently ignored.

| Key | Effect |
|---|---|
| `prefix` | Prepended to `body`. Supports `{source}` substitution → the source-channel code. |
| `override_priority` | Replace the message's priority. |
| `override_type` | Replace the message's type. |

Note the spelling: `override_priority`, not `priority_override`. There is no
suffix, body template, truncate, recipient-override or channel-override
transform — the body cannot be templated, so an incident number or address
cannot be injected into a forwarded message from a route.

### Transform examples

**Tag forwarded traffic with its source channel:**
```json
{
  "prefix": "[{source}] "
}
```

**Prefix and escalate:**
```json
{
  "prefix": "[DMR] ",
  "override_priority": "high"
}
```

---

## Recipient predicates

`recipient_predicate_json` decides **who** a forwarded copy reaches. It is
separate from Filters: filters decide whether the route fires at all,
predicates decide the audience. Both routes seeded by the installer rely on it,
so this is not a corner feature.

```json
{"predicate": "assigned_to_incident", "params": {"ticket_id": "$payload.ticket_id"}}
```

A `$payload.<key>` string is substituted from the message payload at evaluation
time.

| Predicate | Selects |
|---|---|
| `assigned_to_incident` | Users assigned to the given incident |
| `responder_status_in` | Users whose responder status is in a list |
| `member_of_team` | Members of a team |
| `user_id_in` | An explicit list of user ids |
| `org_member` | Members of an organisation |
| `rbac_can` | Users holding a given permission code |

Compose them with `any_of`, `all_of` or `none_of`:

```json
{"type": "any_of", "predicates": [
  {"predicate": "rbac_can", "params": {"permission_code": "screen.situation"}},
  {"predicate": "rbac_can", "params": {"permission_code": "widget.incidents"}}
]}
```

**A predicate that matches nobody logs `skipped`, not `failed`** —
`recipient predicate matched zero users` (`inc/router.php:416`). That is
deliberate: an empty audience is not an error. But it means a route can be
enabled, correct, and reach no one, while the log line reads like a normal
outcome. When a route is not delivering, check the predicate before the adapter.

---

## Loop prevention

Routing forwards a message → the forwarded copy travels through the broker → the broker calls `router_evaluate` on it → which could match another route → which forwards again → infinite loop.

Two defences (both hardened in Phase 73u):

### 1. `_is_routed_forward` trust flag

`router_forward()` sets `_is_routed_forward = 1` on every forwarded copy. `router_evaluate()` and `router_forward()` BOTH only honour caller-supplied `_routed` and `_route_depth` when this flag is set.

Without this guard, a caller of `broker_send` could preset `_routed = [all_route_ids]` or `_route_depth = 99` to silently bypass routing. The Phase 73u fix discards untrusted metadata; routing starts fresh on any non-forwarded input.

### 2. `_routed` set + `_route_depth` counter

For trusted forwards:

- `_routed` is the list of route IDs already applied to this message. A route that's in this list is skipped.
- `_route_depth` is the count of forward hops. When it reaches `ROUTER_MAX_DEPTH` (5), no further forwarding fires.

So a chain `chat → DMR → mesh → SMS` (4 hops) works; a longer chain or any loop is cut off.

---

## All-matches-fire semantics

If three rules all match the same inbound message, all three forward independently. They don't compete; they don't share state.

### When this is what you want

```
DMR TG9990 inbound
  ├─ Route A: → local chat (for dispatcher visibility)
  ├─ Route B: → SMS to on-call duty officer
  └─ Route C: → audit-log entry tagged "radio traffic"
```

Three forwards, three independent results.

### When this isn't what you want

If you want exclusive routing — "if it matches Route A, don't also fire B" — use mutually-exclusive filters.

Example: route urgent messages one way, non-urgent another.

```
Route A (priority 10): filters={"priority_in":["urgent"]}, dest=on-call SMS
Route B (priority 20): filters={"priority_in":["normal","low"]}, dest=chat
```

Because the priority field is a "high" or a "low" per message (never both), exactly one rule matches.

---

## Channel adapters

The `dest_channel` of a route must correspond to a [broker](GLOSSARY.md#broker) channel adapter. Current adapters:

| Channel code | File | Status |
|---|---|---|
| `local_chat` | [`inc/channels/local_chat.php`](../inc/channels/local_chat.php) | Production |
| `smtp` | [`inc/channels/smtp.php`](../inc/channels/smtp.php) | Production (configure SMTP credentials) |
| `email` | [`inc/channels/email.php`](../inc/channels/email.php) | Alias for `smtp` |
| `sms` | [`inc/channels/sms.php`](../inc/channels/sms.php) | Production (Twilio / BulkVS / Pushbullet) |
| `slack` | [`inc/channels/slack.php`](../inc/channels/slack.php) | Production (Slack incoming webhook) |
| `telegram` | [`inc/channels/telegram.php`](../inc/channels/telegram.php) | Production (Bot API `sendMessage`; outbound only) |
| `meshtastic` | [`inc/channels/meshtastic.php`](../inc/channels/meshtastic.php) | Production (mesh bridge VM running) |
| `dmr` | [`inc/channels/dmr.php`](../inc/channels/dmr.php) | Stub — basic shape, real implementation via DVSwitch bridge |
| `zello` | `inc/channel_registry.php` (stub) | Stub — registered, awaiting Zello Work API impl |

Routes to a stub channel will log `failed` in the routing log with reason `not_implemented`. Watch the log when you enable a stub channel.

---

## Dry-run testing

Before enabling a rule, test it against synthesised inputs:

```php
// Settings → Communications & Integrations → Message Routing → row → Test
// Or via API:
POST /api/routing.php
{
  "action": "router_test",
  "route_id": 42,
  "test_message": {
    "channel": "dmr",
    "direction": "inbound",
    "body": "all clear at scene",
    "priority": "urgent"
  }
}
```

Response:

```json
{
  "matched": true,
  "filter_results": {
    "priority_in": "matched (urgent in [urgent])",
    "exclude_keywords": "matched (no exclusion hit)"
  },
  "would_forward_to": "local_chat",
  "transformed_body": "[DMR] all clear at scene",
  "would_actually_send": false
}
```

The `would_actually_send: false` tells you the dry-run didn't actually call `broker_send` — perfect for verifying a rule without spamming dispatch.

---

## Routing log

Every evaluation writes a row to `routing_log`:

| Column | Purpose |
|---|---|
| `id`, `created_at` | PK + timestamp |
| `route_id` | Which route was evaluated |
| `source_channel` | Where the message arrived |
| `dest_channel` | Where the forwarded copy was sent (NULL on no-match) |
| `source_message_id` | FK to the `messages` table for the source |
| `dest_message_id` | FK for the forwarded copy (NULL on no-match) |
| `status` | `forwarded`, `failed`, `skipped`, `loop_blocked` — those four are the column's ENUM; there is no `not_implemented` status (a stub adapter logs `failed` with that text in `error`) |
| `error` | Free-text reason on failure |
| `summary` | Short human-readable description |

View it in **Settings → Communications & Integrations → Message Routing → Activity Log**.

Useful queries:

```sql
-- Why did this message NOT get forwarded?
SELECT * FROM routing_log
 WHERE source_message_id = 12345
 ORDER BY created_at;

-- Top 10 failure modes in the last week
SELECT error, COUNT(*) c
  FROM routing_log
 WHERE status = 'failed'
   AND created_at > NOW() - INTERVAL 7 DAY
 GROUP BY error
 ORDER BY c DESC LIMIT 10;

-- Routes that have never matched anything
SELECT r.id, r.name
  FROM message_routes r
  LEFT JOIN routing_log l ON l.route_id = r.id
 WHERE l.id IS NULL
   AND r.enabled = 1;
```

---

## Common patterns

### Pattern: radio chatter → chat for dispatcher visibility

```
DMR (inbound) → local_chat (channel=dispatch)
Mesh (inbound) → local_chat (channel=dispatch)
```

Transforms add a `[DMR TGxxx]` or `[Mesh @nodename]` prefix so dispatchers can tell sources apart.

### Pattern: urgent messages → SMS to on-call

```
* (inbound) → sms (recipient = on_call_number)
filters: {"priority_in":["urgent"]}
```

The `*` source matches any channel. Combine with `exclude_keywords:["test","drill"]` so test traffic doesn't page anyone.

### Pattern: cross-bridge translation (mesh ↔ DMR)

```
Mesh (inbound) → DMR
DMR  (inbound) → Mesh
```

The loop-prevention `_routed` set keeps these from chaining infinitely.

There is no talkgroup *filter*, so a route cannot be restricted to traffic from
one talkgroup. A specific destination talkgroup is set on the route's
`dest_subaddress_json`, not in `filters_json`.

### Pattern: routing-engine "off" switch

Disable all routes via:

```bash
sudo mariadb newui -e "UPDATE message_routes SET enabled = 0;"
```

Or per-route via the admin UI's toggle. The routing engine continues to log decisions but takes no forwarding action.

---

## Performance notes

- Each `router_evaluate` is one cached query against `message_routes` for that source channel; the cache is per-request, so a high-throughput inbound (thousands of msgs/sec) won't hit the DB once-per-message.
- The actual `broker_send` to the destination channel is what costs time. Slack and SMS adapters block on the external API; for high-volume bridging, configure adapter timeouts (`broker.timeout_ms` setting) so a slow adapter doesn't stall the source channel.
- For very high throughput, consider moving forwarding to a queue (planned for a future phase; not yet implemented).

---

## When to write custom channel adapters

The broker channel registry ([`inc/broker.php`](../inc/broker.php)) accepts any PHP file in `inc/channels/` that calls:

```php
broker_register('my_channel', [
    'name'    => 'My channel',
    'send'    => '_my_channel_send',     // PHP function
    'receive' => '_my_channel_receive',  // PHP function (optional)
    'status'  => '_my_channel_status',   // PHP function (optional)
]);
```

`_my_channel_send($message)` must return `['success' => bool, 'error' => ?string]`. The broker calls it; you handle the actual outbound delivery (HTTP POST, MQTT publish, hardware write, etc.).

Once registered, routes can target `my_channel` as their `dest_channel` and the engine doesn't know or care about the implementation.

For inbound traffic, your channel adapter should call `broker_receive('my_channel')` when a message arrives. The broker logs each message and then calls `router_evaluate($channel, 'inbound', …)` to fan out routes.

**`broker_receive()` currently has no caller in the shipped tree**, so
`direction: inbound` routes cannot fire on their own. Push-style channels work
because they receive an HTTP post and re-inject via `broker_send()`, which fires
**outbound** rules — `api/dmr-ingest.php` and `api/atak-ingest.php` do this.
A poll-based channel (Slack, Telegram, SMS) needs something to call
`broker_receive()` on a schedule, and nothing does. Tracked as
[#23](https://github.com/openises/TicketsCAD/issues/23); until it is resolved,
treat inbound routing as unavailable rather than as configuration you got wrong.

---

## Security considerations

1. **`_is_routed_forward` enforcement** (Phase 73u) — without this, any caller that can reach `broker_send` could bypass routing entirely. Don't disable.
2. **Channel access control** — there's currently no per-channel ACL beyond the global `action.send_chat` / `action.manage_routing` permissions. If you need "Dispatcher A can send to channel X but not channel Y", file an issue; this is queued for a future phase.
3. **Audit trail** — every forwarded message hits `routing_log`. Don't disable; it's the only way to investigate "why did this go where it went".
4. **Loop prevention** — `ROUTER_MAX_DEPTH = 5` is hard-coded. Don't change without understanding the consequences (mesh networks with cross-bridges can legitimately want 6+ hops).

---

## Where the code lives

| What | Path |
|---|---|
| Routing engine | [`inc/router.php`](../inc/router.php) |
| Broker | [`inc/broker.php`](../inc/broker.php) |
| Channel adapters | [`inc/channels/*.php`](../inc/channels/) |
| Admin API | [`api/routing.php`](../api/routing.php) |
| Admin UI | Settings → Communications & Integrations → Message Routing in [`settings.php`](../settings.php) |
| Schema migration | [`sql/run_routing.php`](../sql/run_routing.php) |
| Tests | [`tests/test_routing.php`](../tests/test_routing.php) (41 tests) |

---

This reference is maintained alongside the code. The 41 routing tests in `tests/test_routing.php` are the executable spec; if the engine ever does something other than what's documented here, that's a bug.
