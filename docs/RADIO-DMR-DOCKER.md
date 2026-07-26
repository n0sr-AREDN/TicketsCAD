# Running the DMR bridge with Docker

The DMR bridge connects TicketsCAD to a DMR network (HBLink3, BrandMeister, or
any HomeBrew-Protocol master). [RADIO-DMR-INSTALL.md](RADIO-DMR-INSTALL.md)
covers the bare-metal install with systemd. **This page is the Docker path** —
for a CAD running in Docker/Coolify/Portainer, where `systemctl` and
`/etc/ticketscad/*.env` don't exist.

> **Licence:** the bridge transmits on amateur radio frequencies under *your*
> callsign and DMR ID. You are the control operator for everything it sends.

## How the pieces fit

The bridge is **not** part of the CAD container, and it is really two programs:

| Piece | What it is |
|---|---|
| **hbp_client.py** | The bridge. Speaks HomeBrew Protocol (UDP) to your master, builds the DMR framing, and serves an HTTP control surface on **18091** for the CAD. |
| **md380-emu** | The AMBE+2 **vocoder** — MD-380 radio firmware in emulation, listening on **UDP 2470**. The bridge hands it 20 ms audio frames. Without it you get a connected bridge and *silence*. |
| **dmr-proxy** (optional) | A WebSocket relay in the CAD's own compose (`--profile voice`) that connects the browser radio widget to the bridge. Not the bridge itself. |

Both bridge pieces run **in one container**, because the Python client talks to
the vocoder on `127.0.0.1:2470` — they must share a network namespace.

The CAD reaches the bridge over HTTP using the channel's **bridge host / port /
bearer token**, so the bridge can live on the CAD's host, another VM, or beside
your master — wherever it is reachable.

## What you need first

- Your **DMR ID** and **callsign** (radioid.net registration).
- Your **master**: hostname/IP, port, and passphrase (HBLink3 commonly 54000;
  BrandMeister 62031).
- A **DMR channel** created in TicketsCAD (Settings → Communications → DMR).
  Note the **bearer token** it generates — the bridge needs the same value.

## Install

The bridge ships its own compose file:

```bash
cd services/dvswitch/docker
cp .env.example .env      # fill in DMR_ID, callsign, master, bearer token
docker compose up -d --build
```

The first build takes a few minutes. It fetches `md380-emu` from the **DVSwitch
project's apt repository** at build time — we don't redistribute the vocoder
(it's emulated radio firmware), so it's pulled onto your machine, by you.

Then in TicketsCAD, edit the DMR channel and set:

- **Bridge host** — where the bridge is reachable from the CAD container
  (a hostname/IP; `127.0.0.1` only works if they share a network namespace)
- **Bridge port** — `18091`
- **Bearer token** — the same string as `DMR_BEARER_TOKEN` in your `.env`

If you want browser push-to-talk, also start the CAD's relay:
`docker compose --profile voice up -d` (in the CAD's compose project).

## Verify it, in order

**1. The vocoder** (no radio needed — proves the AMBE codec works):

```bash
docker run --rm ticketscad-dmr-bridge:local selftest
```

You want `md380-emu is answering on UDP 2470` and a non-silent round-trip.
(The reported peak amplitude is small — 40-60 is normal. AMBE is model-based, so
one isolated frame decodes quiet. A *working* production bridge measures ~55.)

**2. The bridge starts and logs in:**

```bash
docker compose logs -f dmr-bridge
```

Healthy startup looks like:

```
[entrypoint] md380-emu is answering on UDP 2470
[hbp] config loaded — DMR ID=… callsign=… master=…
[hbp] bound local UDP 62032
[hbp] sent RPTL for DMR ID …
```

`login stalled in state 1, retrying` means the master isn't answering — check
the address/port, that your passphrase matches, and that your master allows this
DMR ID. The bridge retries on its own.

**3. The CAD can reach it:** in Settings → Communications → DMR the channel
should report connected. From the bridge host:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" http://127.0.0.1:18091/health
```

## Configuration

Set these in `.env` (see `.env.example`). The container renders an
`MMDVM_Bridge.ini` from them at startup, so you don't hand-write one — although
you still can: mount yours at `/etc/ticketscad/MMDVM_Bridge.ini` and it wins.

| Variable | Meaning |
|---|---|
| `DMR_ID`, `DMR_CALLSIGN` | Your registered identity |
| `DMR_MASTER_HOST/PORT/PASSWORD` | Your master |
| `DMR_COLOR_CODE` | Colour code (default 1) |
| `DMR_DEFAULT_TG` | Talkgroup used when the CAD doesn't name one |
| `DMR_BEARER_TOKEN` | Must match the CAD channel's token |
| `DMR_BIND_ADDR` | `127.0.0.1` (same host as CAD) or `0.0.0.0` (remote CAD) |
| `DMR_LOG_LEVEL` | `INFO`, or `DEBUG` when chasing a problem |

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `FATAL: md380-emu did not answer on UDP 2470` | The vocoder didn't start; the container stops rather than run silently. Re-run the `selftest` above and open an issue with the output. |
| Connected, but **no audio** either direction | Almost always the vocoder. Run the `selftest`. |
| `login stalled in state 1, retrying` | The master isn't responding: wrong host/port, wrong passphrase, DMR ID not authorised, or UDP blocked. |
| CAD shows the channel offline | Bridge host/port/token mismatch. The token must match `DMR_BEARER_TOKEN` exactly. Check the port is published where the CAD can reach it. |
| Bridge fine, browser PTT silent | That's the WebSocket relay, not the bridge — start the CAD's `voice` profile. |

## Security

The HTTP control port is protected **only** by the bearer token. Publish it on
`127.0.0.1` when the CAD is on the same host, or firewall it to the CAD's
address. Don't expose 18091 to the internet. Keep `.env` out of git — it holds
your master passphrase and the token.

## Related

- [RADIO-DMR-INSTALL.md](RADIO-DMR-INSTALL.md) — bare-metal/systemd install
- [DVSWITCH-ADMIN-GUIDE.md](DVSWITCH-ADMIN-GUIDE.md) — operating the DMR features
- [DOCKER.md](DOCKER.md) — the CAD's own Docker deployment
