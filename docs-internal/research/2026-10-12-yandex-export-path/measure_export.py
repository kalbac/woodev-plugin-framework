#!/usr/bin/env python3
"""Which export path should the v2 Yandex plugin use? Live measurement on the production (test) account.

Calls:
  - offers/info          the intervals checkout can show for a route (creates nothing)
  - offers/create        offers with price, delivery_interval, pickup_interval (creates nothing; offers expire)
  - offers/confirm       books one offer -> creates a request (P3)
  - request/create       books on the nearest slot, or with destination.interval_utc (P1, P2)
  - request/info         what the request was assigned (GET)
  - request/cancel       every request created here is cancelled and then verified via request/info
  - pricing-calculator   only for the COD shipping total (creates nothing)

Every request_id created is appended to ledger.json BEFORE the next call, and cancelled in a finally block.
At most MAX_CREATED requests are ever created. The token is read at runtime from the repo's
.wp-env.override.json (through measure_price.py) and is never printed, logged or written to any file.
Request bodies and responses are saved under raw/ without headers.

Usage:
  python3 measure_export.py offers [CASE_TAG]   offers/info + offers/create per case (no request is created)
  python3 measure_export.py paths  [CASE_TAG]   P3 + P1 + P2 (3rd and latest interval) per case; cancel + verify each
  python3 measure_export.py cancel              cancel and verify every ledger entry not yet CANCELLED (retries)
"""

import importlib.util
import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

HERE = Path(__file__).resolve().parent
RAW = HERE / "raw"
LEDGER = HERE / "ledger.json"
MP_PATH = HERE.parent / "2026-10-12-yandex-price-measurement" / "measure_price.py"

# Reuse the body builders that already mirror v1 (offers/create, pricing-calculator, pickup points).
_spec = importlib.util.spec_from_file_location("measure_price", MP_PATH)
mp = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(mp)

INFO_PATH = "/api/b2b/platform/offers/info"
CONFIRM_PATH = "/api/b2b/platform/offers/confirm"
REQ_CREATE_PATH = "/api/b2b/platform/request/create"
REQ_INFO_PATH = "/api/b2b/platform/request/info"
REQ_CANCEL_PATH = "/api/b2b/platform/request/cancel"

MAX_CREATED = 20
MAX_CALLS = 300
RUN_TAG = datetime.now(timezone.utc).strftime("%H%M%S")

# Cases: Moscow and Samara sources are the same drop-off point (mp.ctx source). Parcel: medium, one place.
CASES = [
    dict(tag="C1-moscow-pickup-prepaid", geo=213, dest="pickup_market", payment="prepaid"),
    dict(tag="C2-samara-pickup-prepaid", geo=51, dest="pickup_market", payment="prepaid"),
    dict(tag="C3-samara-courier-prepaid", geo=51, dest="courier", payment="prepaid"),
    dict(tag="C4-moscow-pickup-cod", geo=213, dest="pickup_market", payment="cod"),
]

_last = 0.0
_calls = 0


def call(method, path, body=None, params=None):
    """One Yandex call with the measure_price.py throttle and 429 backoff. Returns (status, parsed, latency_ms)."""
    global _last, _calls
    if _calls >= MAX_CALLS:
        raise RuntimeError("call cap reached")
    url = mp.HOST + path + ("?" + urllib.parse.urlencode(params) if params else "")
    data = json.dumps(body).encode("utf-8") if body is not None else None
    headers = {"Authorization": "Bearer " + mp.token(), "Accept-Language": "ru"}
    if body is not None:
        headers["Content-Type"] = "application/json"
    status, parsed, ms = 0, None, 0
    for attempt in range(5):
        gap = mp.GAP_S - (time.monotonic() - _last)
        if gap > 0:
            time.sleep(gap)
        req = urllib.request.Request(url, data=data, method=method, headers=headers)
        _last = time.monotonic()
        _calls += 1
        t0 = time.monotonic()
        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                status, parsed = resp.status, mp.read_json(resp.read())
        except urllib.error.HTTPError as err:
            status, parsed = err.code, mp.read_json(err.read())
        ms = round((time.monotonic() - t0) * 1000)
        if status != 429:
            return status, parsed, ms
        time.sleep(2 ** (attempt + 1))
    return status, parsed, ms


def save(name, method, path, request, status, parsed, ms):
    """Raw record: request body (or query params), status, latency and response. No headers."""
    record = {"method": method, "path": path, "request": request, "status": status,
              "latency_ms": ms, "response": parsed}
    file = RAW / f"{name}.json"
    file.parent.mkdir(parents=True, exist_ok=True)
    file.write_text(json.dumps(record, ensure_ascii=False, indent=1), encoding="utf-8")
    return record


def load_ledger():
    return json.loads(LEDGER.read_text(encoding="utf-8")) if LEDGER.exists() else []


def save_ledger(entries):
    LEDGER.write_text(json.dumps(entries, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")


def ledger_update(request_id, **fields):
    entries = load_ledger()
    for entry in entries:
        if entry["request_id"] == request_id:
            entry.update(fields)
    save_ledger(entries)


def load_ctx():
    return json.loads((mp.RAW / "ctx.json").read_text(encoding="utf-8"))


def case_of(tag):
    spec = next(c for c in CASES if c["tag"] == tag)
    case = mp.build_case("A", spec["geo"], spec["dest"], "medium", "sku_boxes", spec["payment"], "on")
    case["tag"] = spec["tag"]
    return case


def route(case, ctx):
    dest = mp.resolve(case, ctx)
    if dest is None:
        raise RuntimeError(f"{case['tag']}: no destination of this kind in the discovered context")
    return dest


def info_body(dest, ctx):
    """offers/info body as v1 checkout builds it (flat source and destination) plus real dims on places."""
    body = {
        "source": {"platform_station_id": ctx["source"]["id"]},
        "destination": ({"platform_station_id": dest["point"]} if dest["pickup"] else {"address": dest["address"]}),
        "places": [{"physical_dims": {"weight_gross": 3000, "dx": 40, "dy": 20, "dz": 30}}],
    }
    return body


def shipping_kop(case, ctx, dest):
    """COD only: the shipping total the checkout would have charged (v1 calculator), in kopecks."""
    if case["payment"] != "cod":
        return 0
    body = mp.calc_body(case, ctx, dest)
    status, parsed, ms = call("POST", mp.CALC_PATH, body)
    rec = save(f"{case['tag']}/calc", "POST", mp.CALC_PATH, body, status, parsed, ms)
    if status != 200:
        raise RuntimeError(f"{case['tag']}: pricing-calculator HTTP {status}")
    return mp.calc_price(rec) * 100


def offers_body(case, ctx, dest, kop, label):
    """offers/create and request/create body: v1 shape with places carrying real dx/dy/dz (v2 rule)."""
    body = mp.offers_body(case, ctx, dest, kop)
    body = mp.with_real_dims(body, "medium", "places_dims")
    body["info"]["operator_request_id"] = f"{case['tag']}-{label}-{RUN_TAG}"
    return body


def info_intervals(parsed):
    """(from, to) pairs in response order from offers/info."""
    out = []
    for item in (parsed or {}).get("offers", []) if isinstance(parsed, dict) else []:
        src = item if "from" in item else item.get("interval") or item.get("delivery_interval") or {}
        if src.get("from") and src.get("to"):
            out.append({"from": src["from"], "to": src["to"], "raw_keys": sorted(item.keys())})
    return out


def create_offers_list(parsed):
    return (parsed or {}).get("offers", []) if isinstance(parsed, dict) else []


def do_offers(case, ctx, dest):
    tag = case["tag"]
    kop = shipping_kop(case, ctx, dest)
    body = info_body(dest, ctx)
    status, parsed, ms = call("POST", INFO_PATH, body)
    save(f"{tag}/offers-info", "POST", INFO_PATH, body, status, parsed, ms)
    print(f"{tag}: offers/info HTTP {status} {ms} ms")
    ob = offers_body(case, ctx, dest, kop, "offers")
    status2, parsed2, ms2 = call("POST", mp.OFFERS_PATH, ob)
    save(f"{tag}/offers-create", "POST", mp.OFFERS_PATH, ob, status2, parsed2, ms2)
    print(f"{tag}: offers/create HTTP {status2} {ms2} ms, offers={len(create_offers_list(parsed2))}")
    return status, parsed, status2, parsed2


def finish(request_id, tag, label):
    """Cancel the request, then verify with request/info. Retries cancel up to 3 times. Ledger is updated each step."""
    cancel_status = None
    for attempt in range(1, 4):
        body = {"request_id": request_id}
        status, parsed, ms = call("POST", REQ_CANCEL_PATH, body)
        save(f"{tag}/{label}-cancel-try{attempt}", "POST", REQ_CANCEL_PATH, body, status, parsed, ms)
        cancel_status = status
        ledger_update(request_id, cancel_attempts=attempt, cancel_http=status,
                      cancel_response_status=(parsed or {}).get("status") if isinstance(parsed, dict) else None)
        status_i, parsed_i, ms_i = call("GET", REQ_INFO_PATH, params={"request_id": request_id})
        save(f"{tag}/{label}-info-after-cancel-try{attempt}", "GET", REQ_INFO_PATH,
             {"request_id": request_id}, status_i, parsed_i, ms_i)
        state = parsed_i.get("state", {}).get("status") if status_i == 200 and isinstance(parsed_i, dict) else None
        ledger_update(request_id, final_state=state, verified_cancelled=bool(state and "CANCEL" in state.upper()))
        if state and "CANCEL" in state.upper():
            return True
    return False


def book(case, ctx, dest, label, interval=None, offer_id=None):
    """Create one request (request/create or offers/confirm), record it in the ledger, then cancel + verify it."""
    tag = case["tag"]
    entries = load_ledger()
    if len(entries) >= MAX_CREATED:
        raise RuntimeError("request cap reached; no more requests are created")
    kop = shipping_kop(case, ctx, dest)
    if offer_id is not None:
        body = {"offer_id": offer_id}
        status, parsed, ms = call("POST", CONFIRM_PATH, body)
        path = CONFIRM_PATH
    else:
        body = offers_body(case, ctx, dest, kop, label)
        if interval is not None:
            body["destination"]["interval_utc"] = {"from": interval["from"], "to": interval["to"]}
        status, parsed, ms = call("POST", REQ_CREATE_PATH, body)
        path = REQ_CREATE_PATH
    save(f"{tag}/{label}-create", "POST", path, body, status, parsed, ms)
    request_id = (parsed or {}).get("request_id") if status == 200 and isinstance(parsed, dict) else None
    print(f"{tag} {label}: HTTP {status} {ms} ms request_id={'yes' if request_id else 'none'}")
    if request_id is None:
        return None
    entries = load_ledger()
    entries.append({"request_id": request_id, "case": tag, "path": label, "endpoint": path,
                    "operator_request_id": body.get("info", {}).get("operator_request_id"),
                    "requested_interval": interval, "offer_id": offer_id,
                    "create_http": status, "create_latency_ms": ms, "created_utc": datetime.now(timezone.utc).isoformat(),
                    "cancel_attempts": 0, "final_state": None, "verified_cancelled": False})
    save_ledger(entries)
    try:
        s, p, m = call("GET", REQ_INFO_PATH, params={"request_id": request_id})
        save(f"{tag}/{label}-info", "GET", REQ_INFO_PATH, {"request_id": request_id}, s, p, m)
        print(f"{tag} {label}: request/info HTTP {s} {m} ms state={(p or {}).get('state', {}).get('status') if isinstance(p, dict) else None}")
    finally:
        ok = finish(request_id, tag, label)
        print(f"{tag} {label}: cancelled and verified={ok}")
    return request_id


def do_paths(case, ctx, dest):
    tag = case["tag"]
    status, parsed, status2, parsed2 = do_offers(case, ctx, dest)
    intervals = info_intervals(parsed) if status == 200 else []
    offers = create_offers_list(parsed2) if status2 == 200 else []
    if len(intervals) < 3 or not offers:
        raise RuntimeError(f"{tag}: need >=3 offers/info intervals and offers/create offers, got {len(intervals)}/{len(offers)}")
    third, latest = intervals[2], intervals[-1]
    # P3 first, so the offer is still fresh: offers/create -> offers/confirm of the 3rd offer in list order.
    book(case, ctx, dest, "P3-confirm-3rd-offer", offer_id=offers[2]["offer_id"])
    book(case, ctx, dest, "P1-no-interval")
    book(case, ctx, dest, "P2-interval-3rd", interval=third)
    book(case, ctx, dest, "P2-interval-latest", interval=latest)


def cancel_pending():
    for entry in load_ledger():
        if entry.get("verified_cancelled"):
            continue
        ok = finish(entry["request_id"], entry["case"], "recancel-" + entry["path"])
        print(f"{entry['case']} {entry['path']}: verified_cancelled={ok}")


def main(argv):
    if len(argv) < 2:
        print(__doc__)
        return 2
    cmd = argv[1]
    only = argv[2] if len(argv) > 2 else None
    if cmd in ("offers", "paths"):
        ctx = load_ctx()
        for spec in CASES:
            if only and spec["tag"] != only:
                continue
            case = case_of(spec["tag"])
            dest = route(case, ctx)
            if cmd == "offers":
                do_offers(case, ctx, dest)
            else:
                do_paths(case, ctx, dest)
    elif cmd == "cancel":
        cancel_pending()
    else:
        print(__doc__)
        return 2
    print(f"calls={_calls} ledger_entries={len(load_ledger())}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
