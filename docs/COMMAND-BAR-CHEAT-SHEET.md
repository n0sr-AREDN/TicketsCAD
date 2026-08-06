# TicketsCAD Command Bar — Cheat Sheet

Press **`/`** anywhere in the app (as long as you're not typing in a text field) to
open the command bar. Type a command name or a short alias, then **Enter** to run
it. If what you typed matches more than one command, a dropdown appears — use
**↑ / ↓** then **Enter**, click, or **Tab** to complete to the highlighted one.
**Esc** closes the bar without doing anything.

You don't have to type the whole word. `/in` is enough for `/incidents` as
long as nothing else starts with `in`.

## Dispatch & workflow

| Command | Aliases | What it does |
|---|---|---|
| `/new` | — | Create a new incident |
| `/incidents` | `/inc` | Focus the Active Incidents widget |
| `/responders` | `/res`, `/resp` | Focus the Responders widget |
| `/units` | `/uni` | Focus the Responders widget (units view) |
| `/facilities` | `/fac` | Focus the Facilities widget |
| `/log` | `/logs` | Focus the Activity Log widget |
| `/detail` | — | Open the detail page for the selected incident |
| `/zello` | `/zel` | Toggle the Zello radio panel |

## Navigation — jump straight to a page

| Command | Aliases | Opens |
|---|---|---|
| `/dashboard` | `/dash`, `/home`, `/sit`, `/situ`, `/situation` | Dashboard (your main situational view) |
| `/bigscreen` | `/wall`, `/fullscreen`, `/eoc` | Full-screen situation display — for the big monitor at an EOC/command post |
| `/search` | — | Search page |
| `/reports` | — | Reports page |
| `/settings` | — | Settings / configuration page |
| `/sop` | — | SOP viewer |
| `/help` | — | Help page |
| `/roster` | — | Personnel roster |
| `/teams` | `/team` | Teams page |
| `/schedule` | — | Scheduling page |
| `/vehicles` | — | Vehicles page |
| `/equipment` | — | Equipment page |
| `/roles` | — | Roles & permissions admin page |
| `/profile` | — | Your user profile |
| `/contacts` | `/constituents` | Contacts / constituents page |
| `/messages` | `/messaging` | Internal messaging |
| `/links` | — | External links page |
| `/ics` | `/forms` | ICS forms page |

**`/dashboard` vs. `/bigscreen`** — these are two different screens on purpose.
`/dashboard` is the one you live in day to day. `/bigscreen` is the full-screen
version you switch to once, for a large monitor at an event or command post.
Both start differently enough (`/dash`… vs `/wall`/`/eoc`) that neither
accidentally completes to the other.

## Unit status — change a unit without opening a modal

```
/s <handle> <status>
/status <handle> <status>      (same thing — /s and /st also work)
```

**Examples**

| You type | What happens |
|---|---|
| `/s M21 av` | Medic 21 → Available |
| `/status E2 disp` | Engine 2 → Dispatched |
| `/s Engine 2 dispatched` | Multi-word unit names work too |
| `/s M4 out of service` | Three-word statuses work too |

The status keyword is read from the **end** of what you type, so everything
before it is treated as the unit handle. Case doesn't matter.

**Status shortcuts** (case-insensitive):

| Status | Type any of |
|---|---|
| Available | `av`, `avail`, `available` |
| Busy | `busy` |
| Unavailable | `unav`, `unavail`, `unavailable` |
| Dispatched | `disp`, `dispatched`, `dp` |
| Enroute | `en`, `enr`, `enroute` |
| Responding | `resp`, `responding` |
| On Scene | `os`, `onscene`, `on-scene`, `on scene` |
| Transporting | `tx`, `transp`, `transport`, `transporting` |
| At Facility | `af`, `atfacility`, `at-facility`, `at facility` |
| In Quarters | `iq`, `inquarters`, `in-quarters`, `in quarters` |
| Out of Service | `oos`, `out of service` |

**Statuses that need extra info** (like *Transporting* needing a destination
facility, or *Out of Service* needing a reason) can't be set from the command
bar — you'll be told to use the unit's **S** key instead, which opens the full
status modal with the facility picker / note field.

If a status word isn't recognized, the command bar still tries it against
whatever your install's admin has actually configured under *Config → App
Preferences → Unit Statuses* — so a status your agency added yourself will
work even if it's not in the table above.

## Event Net-Control — move a unit between zones

```
/z <team> <zone>
```

Only works when an event is active and selected on the Net Control board —
open Net Control and pick the event first, or you'll get a reminder to.

| You type | What happens |
|---|---|
| `/z alpha 3` | Team Alpha → the zone with code or name "3" |
| `/z echo clear` | Echo's zone assignment is cleared (`clear`, `none`, and `off` all work) |

Team names, zone codes and zone names all match case-insensitively, and a
zone name only needs to be typed far enough to be unambiguous.

## Net-control check-ins — capture a whole round in one line

```
/net <id> <note> / <id> <note> / <id> <note> ...
```

Built for when several stations are checking in back-to-back and you don't
have time to open a form per station. Separate entries with `/`; within each
entry, the first word is the identifier and the rest is the note.

| You type | What happens |
|---|---|
| `/net 1234 tornado / 3344 hail / 6543 hail / 3243 wind damage` | Four check-ins captured in one keystroke |

After Enter, the situational screen opens (or refreshes in place if you're
already on a page with the check-in widget) with the new entries ready to
work.

## Keys once the bar is open

| Key | Does |
|---|---|
| **Enter** | Run the highlighted / typed command |
| **Tab** | Complete to the highlighted (or first) suggestion |
| **↑ / ↓** | Move the highlight in the dropdown |
| **Esc** | Close the bar, do nothing |

---

*Generated from the live command registry in `assets/js/command-bar.js` — if a
command here doesn't match what your install actually does, the code is the
source of truth; please open an issue.*
