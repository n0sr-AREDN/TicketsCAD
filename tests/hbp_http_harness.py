#!/usr/bin/env python3
"""Bring the REAL hbp_client HTTP control surface up on loopback, for tests.

Used by tests/test_dmr_bridge_http.php. Deliberately runs the production
`serve_http()` + `ControlHandler` — not a copy — so the test proves what the
bridge actually does:

  * the surface comes up with NO text-to-speech configured (openises/tickets#10:
    it used to be gated on DMR_PIPER_BIN + DMR_PIPER_VOICE, which nothing under
    services/dvswitch/docker/ ever sets, so port 18091 never opened on Docker);
  * the bearer the CAD stores is the bearer the bridge accepts.

Usage:  python tests/hbp_http_harness.py <bearer>
Prints  "PORT <n>" on stdout once listening, then serves until stdin closes.

Never touches a radio: the stub client below reports STATE_RUNNING = false, so
every transmit path answers 503 before reaching the vocoder.
"""
import os
import sys
import threading

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                "..", "services", "dvswitch"))
import hbp_client  # noqa: E402


class _StubClient:
    """Just enough of HBPClient for /health. state != STATE_RUNNING on
    purpose — it is what makes every TX path refuse before it can key."""
    state = 0
    running = False
    rx_dmrd_count = 0
    rx_keepalive_count = 0


def main() -> int:
    bearer = sys.argv[1] if len(sys.argv) > 1 else "test-bearer"
    server = hbp_client.serve_http(
        _StubClient(), 0, bearer,
        operator_id=1234567, default_tg=91,
        piper_bin="", piper_voice="",      # <- the whole point: no TTS
        bind_addr="127.0.0.1",
    )
    sys.stdout.write("PORT %d\n" % server.server_address[1])
    sys.stdout.flush()
    # Serve until the parent closes our stdin (or kills us).
    try:
        sys.stdin.read()
    except Exception:
        pass
    server.shutdown()
    return 0


if __name__ == "__main__":
    threading.current_thread().name = "harness"
    sys.exit(main())
