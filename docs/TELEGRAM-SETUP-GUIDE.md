# Telegram Setup Guide — TicketsCAD

**Audience:** Administrators wiring up the Telegram channel for the first time.
**Version:** NewUI v4.0. Reflects the Telegram integration as shipped
(outbound text only — incident, PAR and system alerts posted to a Telegram
group as a bot).

Telegram is one of the simplest channels to set up — two values pasted into
Settings — but two of the steps fail in ways that point you at the wrong
problem. Both are called out below where they bite.

---

## Table of contents

1. [What Telegram adds, and when to use it](#1-what-telegram-adds-and-when-to-use-it)
2. [Create the bot with BotFather](#2-create-the-bot-with-botfather)
3. [Create the group and add the bot](#3-create-the-group-and-add-the-bot)
4. [Find the chat ID (the gotcha)](#4-find-the-chat-id-the-gotcha)
5. [Configure TicketsCAD](#5-configure-ticketscad)
6. [Route messages to Telegram](#6-route-messages-to-telegram)
7. [Troubleshooting](#7-troubleshooting)
8. [Quick reference](#8-quick-reference)

---

## 1. What Telegram adds, and when to use it

Telegram lets TicketsCAD post text into a group chat that your people already
have on their phones. It is **outbound only** — TicketsCAD posts to the group
and does not read replies, so it suits announcements rather than conversation:

- Incident alerts to an on-call or duty group.
- PAR and system notifications.
- Anything you would otherwise send by group SMS, without per-message cost.

**When to reach for something else.** If you need two-way traffic, voice, or
per-unit direct messages, Zello is the channel for that
([`ZELLO-SETUP-GUIDE.md`](ZELLO-SETUP-GUIDE.md)). Telegram is the low-effort
option for one-way notification to a group of people with smartphones.

There is no server-side daemon to run: sends go out over HTTPS from the web
process, so nothing needs installing beyond the two settings below.

---

## 2. Create the bot with BotFather

1. In Telegram, search for **@BotFather** (the one with the blue verified
   check) and start a chat.
2. Send `/newbot`.
3. Give it a display name (anything — e.g. `Dispatch Alerts`).
4. Give it a username ending in `bot` — e.g. `YourOrgCADbot`. It must be
   globally unique, so expect a couple of tries.
5. BotFather replies with a token that looks like:

   ```
   123456789:AAHt-abcdefGHIJKLmnopQRSTuvwx-yz12345
   ```

   Copy it. You will paste it into Settings in step 5.

> **Treat the token like a password.** Anyone holding it can post as your bot.
> If it leaks, send `/revoke` to BotFather and paste the new one into Settings.

---

## 3. Create the group and add the bot

1. Create a Telegram group (or use an existing one) containing the people who
   should receive alerts.
2. **Add your bot to the group** as a member — search for the `…bot` username
   you chose in step 2.
3. If the group is a channel-style broadcast group, give the bot **Admin**
   rights so it is permitted to post.

> **Do not skip this before step 4.** Until the bot is actually a member of the
> group, the group will not appear in the lookup below at all — which reads as
> "the lookup is broken" rather than "the bot isn't in the group yet".

---

## 4. Find the chat ID (the gotcha)

1. Send any message **in the group** — not a direct message to the bot. Just
   "test" is fine.
2. In a browser, open (replacing `<TOKEN>` with the token from step 2):

   ```
   https://api.telegram.org/bot<TOKEN>/getUpdates
   ```

3. Look through the JSON for a `chat` object and take its `id`:

   ```json
   "chat": { "id": -1001234567890, "title": "Dispatch Alerts", "type": "supergroup" }
   ```

### This is where people get stuck

**The group's chat ID is negative.** Group and supergroup IDs start with `-`,
usually `-100…`. Copy the minus sign along with the digits.

`getUpdates` will happily also show you the chat ID of your **private** chat
with the bot — a *positive* number — if you messaged the bot directly at any
point. Using that positive ID looks correct and fails with:

```
Forbidden: bot can't initiate conversation with a user
```

which reads like a permissions problem with the bot and is actually the wrong
chat ID. If you see that error, you almost certainly have a DM id where you
want the group's.

Check the `"type"` field to be sure: you want `"group"` or `"supergroup"`, not
`"private"`.

> If `getUpdates` returns an empty `result` array, the bot is not in the group
> (step 3), or no message has been sent in the group since it joined (step 4.1).

---

## 5. Configure TicketsCAD

Go to **Settings → Telegram Bot** and fill in the two fields:

| Field | Value |
|---|---|
| **Bot Token** | The `123456789:AA…` token from step 2 |
| **Chat ID** | The **negative** group ID from step 4, e.g. `-1001234567890` |

Click **Save**, then **Send Test**. A confirmation message should arrive in the
group within a second or two.

Both values are format-checked on save, so a mistyped or half-pasted token is
reported as malformed rather than failing later with an opaque error from
Telegram.

---

## 6. Route messages to Telegram

Configuring the channel does not by itself send anything. Traffic reaches
Telegram through the routing engine, the same as any other channel:

**Settings → Communications & Integrations → Message Routing** → add a route with
`dest_channel = telegram`.

See [`MESSAGE-ROUTING-GUIDE.md`](MESSAGE-ROUTING-GUIDE.md) for filters and
transforms, and [`ROUTING-ENGINE-REFERENCE.md`](ROUTING-ENGINE-REFERENCE.md)
for the full route schema.

The destination chat is taken from the **Chat ID** setting for every message —
it is deliberately not settable per message or per route. One bot credential
posts to one configured group.

---

## 7. Troubleshooting

### `Forbidden: bot can't initiate conversation with a user`

The chat ID is a private/DM id (positive) rather than the group's (negative).
Redo [step 4](#4-find-the-chat-id-the-gotcha) and check the `"type"` field is
`group` or `supergroup`.

### `Bad Request: chat not found`

The chat ID is wrong, or the bot has been removed from the group. Confirm the
bot is still a member, send a fresh message in the group, and re-check
`getUpdates`.

### `getUpdates` shows an empty `result`

The bot is not in the group, or nothing has been said in the group since it
joined. Both are [step 3](#3-create-the-group-and-add-the-bot).

### "Telegram bot token is malformed"

The token does not look like `<digits>:<secret>` — usually a partial paste, a
stray space, or the bot *username* pasted instead of the token. Re-copy it from
BotFather, or `/revoke` and use the replacement.

### "Telegram chat ID is malformed"

The chat ID is not an integer — usually the group *title* or an `@username`
pasted instead of the numeric id, or the minus sign dropped.

### "Telegram not configured"

One of the two fields is empty. Note that the Bot Token field intentionally
shows as blank once saved (it is stored as a secret); leave it blank to keep
the existing token, or type a new one to replace it.

### Nothing arrives, but Send Test reports success

The bot posted to a different group than you are watching — a stale chat ID
from an earlier group. Re-run [step 4](#4-find-the-chat-id-the-gotcha) against
the group you actually want.

### The bot was working and stopped

Check whether the bot is still a member of the group, and whether the token was
revoked. Telegram silently drops a bot from a group when it is removed by an
admin; sends then fail with `chat not found`.

---

## 8. Quick reference

**Outside TicketsCAD (Telegram side):**

| What | Where | Notes |
|---|---|---|
| Create the bot | **@BotFather** → `/newbot` | Username must end in `bot` |
| Bot token | BotFather's reply | `123456789:AA…` — treat as a password |
| Add bot to group | Telegram group → add member | Required before the chat ID lookup works |
| Chat ID | `https://api.telegram.org/bot<TOKEN>/getUpdates` | **Negative** number, `type` = `group`/`supergroup` |
| Revoke a leaked token | **@BotFather** → `/revoke` | Then paste the new one into Settings |

**Inside TicketsCAD:**

| Field (Settings → Telegram Bot) | Value |
|---|---|
| Bot Token | `123456789:AA…` from BotFather |
| Chat ID | Negative group ID, e.g. `-1001234567890` |
| Send Test | Posts a confirmation message to the group |

**Where the pieces live:**

| Piece | Location |
|---|---|
| Channel adapter | [`inc/channels/telegram.php`](../inc/channels/telegram.php) |
| Settings panel | Settings → Telegram Bot |
| Routing | Settings → Communications & Integrations → Message Routing, `dest_channel = telegram` |

---

*The single most common failure is a positive (DM) chat ID where the group's
negative one belongs — it fails with a message about bot permissions that gives
no hint the ID is wrong. Re-check [step 4](#4-find-the-chat-id-the-gotcha)
first.*
