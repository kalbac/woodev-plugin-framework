#!/usr/bin/env python3
"""Dimension and weight limits of Yandex terminals (market_l4g) and 5post points, pricing-calculator only.

Calls ONLY:
  - pickup-points/list   (discover: one Moscow terminal, the 5post point of s174, the market_l4g pickup point)
  - pricing-calculator   (every probe)

Usage (from this folder):
  python3 -I measure_limits.py discover   resolve the points -> raw/ctx.json
  python3 -I measure_limits.py run        weight bracket, length bracket, sum/volume probes -> results.json

Every request/response pair is cached under raw/ (request body, status, response; no headers, no token),
so a rerun reads the cache and calls the API only for missing files.
"""

import json
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
RAW = HERE / "raw"
REPO = HERE.parents[2]
CONFIG = REPO / ".wp-env.override.json"

HOST = "https://b2b-authproxy.taxi.yandex.net"
POINTS_PATH = "/api/b2b/platform/pickup-points/list"
CALC_PATH = "/api/b2b/platform/pricing-calculator"

# s174 drop-off point (Москва Трубная площадь 2, market_l4g), same source as measure_price.py.
SOURCE = "20cfe81c-bb45-4cfa-85e6-31fdade8ed23"
# s174 5post point (Москва Боровское ш 62к3), type pickup_point, prepaid.
FIVE_POST_S174 = "019e0aec42e271dab592470ac89a572c"
# s174 market_l4g pickup_point (Москва Волгоградский 10 стр2), prepaid. Used as the control.
MARKET_PICKUP_S174 = "05212278-441c-4033-9ed8-851ca27facca"
MOSCOW_CENTRE = (55.7558, 37.6173)

GAP_S = 0.5
POINTS_BUDGET = 4
CALC_BUDGET = 60
# Insurance on, 3000 RUB, as the s174 followup did (the shape that produced the 5post refusal).
ASSESSED_KOP = 300000

_last = 0.0
_points_calls = 0
_calc_calls = 0


def token():
    return json.loads(CONFIG.read_text(encoding="utf-8"))["env"]["development"]["config"]["WOODEV_YANDEX_PROD_TOKEN"]


def read_json(raw):
    try:
        return json.loads(raw)
    except ValueError:
        return raw.decode("utf-8", "replace")[:500]


def post(path, body):
    """POST with 0.5 s spacing; on HTTP 429 back off and give up after three backoffs."""
    global _last
    data = json.dumps(body).encode("utf-8")
    for attempt in range(4):
        gap = GAP_S - (time.monotonic() - _last)
        if gap > 0:
            time.sleep(gap)
        req = urllib.request.Request(
            HOST + path,
            data=data,
            method="POST",
            headers={
                "Authorization": "Bearer " + token(),
                "Accept-Language": "ru",
                "Content-Type": "application/json",
            },
        )
        _last = time.monotonic()
        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                return resp.status, read_json(resp.read())
        except urllib.error.HTTPError as err:
            status, parsed = err.code, read_json(err.read())
            if status != 429:
                return status, parsed
            time.sleep(2 ** (attempt + 1))
    raise RuntimeError("HTTP 429 three times: stopping as the brief requires")


def save(file, record):
    file.parent.mkdir(parents=True, exist_ok=True)
    file.write_text(json.dumps(record, ensure_ascii=False, indent=1), encoding="utf-8")


def distance_to_centre(p):
    pos = p.get("position") or {}
    return (pos.get("latitude", 0) - MOSCOW_CENTRE[0]) ** 2 + (pos.get("longitude", 0) - MOSCOW_CENTRE[1]) ** 2


def trim_point(p):
    a = p.get("address") or {}
    return {
        "id": p.get("id"),
        "operator_id": p.get("operator_id"),
        "name": p.get("name"),
        "type": p.get("type"),
        "full_address": a.get("full_address"),
        "payment_methods": p.get("payment_methods") or [],
    }


def points_used():
    """Points calls already on disk (cached files count too), so reruns cannot exceed the budget."""
    return len(list((RAW / "points").glob("*.json"))) if (RAW / "points").exists() else 0


def calc_used():
    return len(list((RAW / "calc").glob("*.json"))) if (RAW / "calc").exists() else 0


def points(geo, ptype, payment=None):
    """pickup-points/list as v1 requests it. Cached under raw/points/; the full response is kept (token-free)."""
    body = {"geo_id": geo, "type": ptype}
    if payment:
        body["payment_method"] = payment
    file = RAW / "points" / f"geo{geo}-{ptype}-{payment or 'nofilter'}.json"
    if file.exists():
        rec = json.loads(file.read_text(encoding="utf-8"))
    else:
        if points_used() >= POINTS_BUDGET:
            raise RuntimeError("points budget exhausted")
        status, parsed = post(POINTS_PATH, body)
        rec = {"request": body, "status": status, "response": parsed}
        save(file, rec)
    if rec["status"] != 200 or not isinstance(rec["response"], dict):
        raise RuntimeError(f"points {ptype} geo {geo}: HTTP {rec['status']}")
    return rec["response"].get("points") or []


def discover():
    # Moscow has no terminal at all (geo measurement: 2276 pickup_point, 0 terminal; raw/points/geo213-terminal-*.json).
    # Samara (geo 51) has one, the first terminal the geo measurement found outside Moscow.
    terminals = points(51, "terminal")
    market_terminals = [p for p in terminals if p.get("operator_id") == "market_l4g"] or terminals
    if not market_terminals:
        raise RuntimeError("no terminal in geo 51")
    terminal = market_terminals[0]

    pickups = points(213, "pickup_point", "already_paid")
    by_id = {p.get("id"): p for p in pickups}
    five_post = by_id.get(FIVE_POST_S174)
    five_post_source = "s174 point still listed"
    if five_post is None:
        five_posts = [p for p in pickups if p.get("operator_id") == "5post"]
        five_post = min(five_posts, key=distance_to_centre)
        five_post_source = "nearest 5post in the list (s174 point not listed)"
    market_pickup = by_id.get(MARKET_PICKUP_S174)

    ctx = {
        "source": SOURCE,
        "terminal": trim_point(terminal),
        "terminal_geo_id": 51,
        "terminal_count_geo51": len(terminals),
        "five_post": trim_point(five_post),
        "five_post_source": five_post_source,
        "market_pickup_control": trim_point(market_pickup) if market_pickup else None,
    }
    save(RAW / "ctx.json", ctx)
    print(json.dumps(ctx, ensure_ascii=False, indent=1))


def load_ctx():
    path = RAW / "ctx.json"
    if not path.exists():
        raise RuntimeError("run `discover` first")
    return json.loads(path.read_text(encoding="utf-8"))


def calc_body(point_id, dims, weight_g):
    """One place, real dx/dy/dz in cm and weight_gross in grams, insurance on: the s174 followup shape."""
    dx, dy, dz = dims
    return {
        "tariff": "self_pickup",
        "total_weight": weight_g,
        "places": [{"physical_dims": {"weight_gross": weight_g, "dx": dx, "dy": dy, "dz": dz}}],
        "source": {"platform_station_id": SOURCE},
        "destination": {"platform_station_id": point_id},
        "payment_method": "already_paid",
        "total_assessed_price": ASSESSED_KOP,
    }


def probe(net, point_id, dims, weight_g, tag):
    """One pricing-calculator call (cached). Returns True when the calculator quotes a price.

    A refusal that names dimensions or weight, or the calculator's «No delivery options» (seen for a 60 cm
    side on a terminal, and for a 240 cm box on a courier in s174), is a measured FAIL, recorded with its
    exact code and text. Any other refusal stops the run so it is read by hand instead of being bisected.
    """
    global _calc_calls
    file = RAW / "calc" / f"{net}-{tag}.json"
    if file.exists():
        rec = json.loads(file.read_text(encoding="utf-8"))
    else:
        if calc_used() >= CALC_BUDGET:
            raise RuntimeError("calc budget exhausted")
        _calc_calls += 1
        body = calc_body(point_id, dims, weight_g)
        status, parsed = post(CALC_PATH, body)
        rec = {"request": body, "status": status, "response": parsed}
        save(file, rec)
    if rec["status"] == 200:
        print(f"{net:9} {tag:32} PASS  {rec['response'].get('pricing_total')}")
        return True
    message = rec["response"].get("message", "") if isinstance(rec["response"], dict) else str(rec["response"])
    code = rec["response"].get("code", "") if isinstance(rec["response"], dict) else ""
    print(f"{net:9} {tag:32} FAIL  HTTP {rec['status']} {code}: {message}")
    text = (code + " " + message).lower()
    if "dimension" in text or "weight" in text or code == "no_delivery_options":
        return False
    raise RuntimeError(f"unexpected refusal, read {file.name} by hand: HTTP {rec['status']} {code} {message}")


def round_to(x, grid):
    return int(round(x / grid)) * grid


def bracket(test, start, grow, floor, cap, grid):
    """Multiply up from start until a fail (or the cap), or down until a pass (or the floor).

    Returns (pass, fail): pass is None when even the floor fails; fail is None when the cap passes.
    """
    if test(start):
        p = start
        while True:
            f = min(round_to(p * grow, grid), cap)
            if f <= p:
                return p, None
            if not test(f):
                return p, f
            p = f
            if f == cap:
                return p, None
    f = start
    while True:
        p = max(round_to(f / grow, grid), floor)
        if test(p):
            return p, f
        if p == floor:
            return None, f
        f = p


def bisect(test, p, f, grid):
    """Narrow a verified (pass p, fail f) pair until f - p <= grid."""
    while f - p > grid:
        m = round_to((p + f) / 2, grid)
        if m <= p:
            m = p + grid
        if m >= f:
            break
        if test(m):
            p = m
        else:
            f = m
    return p, f


def run():
    ctx = load_ctx()
    out = {"terminal": ctx["terminal"]["id"], "five_post": ctx["five_post"]["id"]}
    nets = [("terminal", ctx["terminal"]["id"]), ("5post", ctx["five_post"]["id"])]

    for net, pid in nets:
        print(f"--- {net}: weight bracket (box 10x10x10 cm), weight in g")
        wtest = lambda g: probe(net, pid, (10, 10, 10), g, f"w-10x10x10-{g}g")
        wp, wf = bracket(wtest, 15000, 2, 250, 120000, 250)
        if wp is not None and wf is not None:
            wp, wf = bisect(wtest, wp, wf, 250)
        out[net + "_weight"] = {"pass_g": wp, "fail_g": wf}

        print(f"--- {net}: length bracket (light parcel 500 g, dy=dz=10 cm), longest side in cm")
        ltest = lambda L: probe(net, pid, (L, 10, 10), 500, f"l-{L}x10x10-500g")
        lp, lf = bracket(ltest, 60, 1.5, 10, 150, 1)
        if lp is not None and lf is not None:
            lp, lf = bisect(ltest, lp, lf, 1)
        out[net + "_length"] = {"pass_cm": lp, "fail_cm": lf}

        print(f"--- {net}: sum/volume probes")
        out[net + "_probes"] = {
            "60x40x40_500g": probe(net, pid, (60, 40, 40), 500, "v-60x40x40-500g"),
            "60x40x40_15000g": probe(net, pid, (60, 40, 40), 15000, "v-60x40x40-15000g"),
            "45x45x45_500g": probe(net, pid, (45, 45, 45), 500, "v-45x45x45-500g"),
            "60x20x20_500g": probe(net, pid, (60, 20, 20), 500, "v-60x20x20-500g"),
        }

    # Sum/volume, once the longest-side limits are known (40 cm terminal, 70 cm 5post): stay inside them.
    print("--- follow-up: boxes inside the longest-side limit, to test sum of sides / volume")
    out["terminal_40x40x40_500g"] = probe("terminal", ctx["terminal"]["id"], (40, 40, 40), 500, "v-40x40x40-500g")
    out["5post_70x35x35_500g"] = probe("5post", ctx["five_post"]["id"], (70, 35, 35), 500, "v-70x35x35-500g")
    # Terminal: 40x10x10 (sum 60, vol 4000) passes, 41x10x10 (sum 61, vol 4100) fails, 40x40x40 (sum 120) fails.
    # Sum rule (sum <= 60) vs volume rule (vol <= ~4000-4100): 30x30x2 has sum 62 but vol 1800;
    # 20x20x20 has sum 60 but vol 8000. Sum rule -> 30x30x2 fails, 20x20x20 passes; volume rule -> the reverse.
    out["terminal_30x30x2_500g"] = probe("terminal", ctx["terminal"]["id"], (30, 30, 2), 500, "v-30x30x2-500g")
    out["terminal_20x20x20_500g"] = probe("terminal", ctx["terminal"]["id"], (20, 20, 20), 500, "v-20x20x20-500g")
    # 5post: 70x35x35 (sum 140, vol 85750) fails although 45x45x45 (sum 135, vol 91125) passes: sum threshold in [135, 140).
    out["5post_68x35x35_500g"] = probe("5post", ctx["five_post"]["id"], (68, 35, 35), 500, "v-68x35x35-500g")
    # Terminal: 20x20x20 and 30x30x2 pass, so neither sum <= 60 nor volume <= 4100 is the rule. 40x40x40 fails with
    # the longest side at the limit: a second side of 40 (40x40x10), or sum 80 / volume 16000 (40x20x20), separates them.
    out["terminal_40x40x10_500g"] = probe("terminal", ctx["terminal"]["id"], (40, 40, 10), 500, "v-40x40x10-500g")
    out["terminal_40x20x20_500g"] = probe("terminal", ctx["terminal"]["id"], (40, 20, 20), 500, "v-40x20x20-500g")
    # Terminal family 40x40xc: sum 80+c and volume 1600*c rise together. 40x40x10 passes, 40x40x40 fails: bisect c.
    tid = ctx["terminal"]["id"]
    c_pass, c_fail = bisect(lambda c: probe("terminal", tid, (40, 40, c), 500, f"v-40x40x{c}-500g"), 10, 40, 1)
    out["terminal_40x40xc_bracket"] = {"pass_c": c_pass, "fail_c": c_fail,
                                       "pass_sum": 80 + c_pass, "fail_sum": 80 + c_fail,
                                       "pass_vol": 1600 * c_pass, "fail_vol": 1600 * c_fail}
    # 5post sum of sides: 45x45x45 (sum 135) passes, 68x35x35 (sum 138) fails. Narrow it with sums 136 and 137.
    out["5post_46x45x45_500g"] = probe("5post", ctx["five_post"]["id"], (46, 45, 45), 500, "v-46x45x45-500g")
    out["5post_46x46x45_500g"] = probe("5post", ctx["five_post"]["id"], (46, 46, 45), 500, "v-46x46x45-500g")
    # Exact weight edge: is 15 kg (5post) and 20 kg (terminal) inclusive?
    out["5post_weight_15001g"] = probe("5post", ctx["five_post"]["id"], (10, 10, 10), 15001, "w-10x10x10-15001g")
    out["terminal_weight_20001g"] = probe("terminal", tid, (10, 10, 10), 20001, "w-10x10x10-20001g")
    # Do the axes combine? Near-limit box at the weight limit: 40x40x38 (sum 118) at 20 kg on the terminal,
    # 46x45x45 (sum 136) at 15 kg on 5post. If both pass, weight and size are independent in the measured space.
    out["terminal_40x40x38_20000g"] = probe("terminal", tid, (40, 40, 38), 20000, "v-40x40x38-20000g")
    out["5post_46x45x45_15000g"] = probe("5post", ctx["five_post"]["id"], (46, 45, 45), 15000, "v-46x45x45-15000g")

    if ctx.get("market_pickup_control"):
        print("--- control: market_l4g pickup_point, 60x40x40 cm, 15 kg")
        mid = ctx["market_pickup_control"]["id"]
        out["control_market_pickup_60x40x40_15000g"] = probe(
            "control", mid, (60, 40, 40), 15000, "pickup-60x40x40-15000g")

    out["calc_calls_total"] = calc_used()
    out["points_calls_total"] = points_used()
    save(HERE / "results.json", out)
    print(json.dumps(out, ensure_ascii=False, indent=1))
    print(f"calculator calls on disk: {calc_used()} of {CALC_BUDGET}; points calls on disk: {points_used()} of {POINTS_BUDGET}")


def main(argv):
    if len(argv) != 2 or argv[1] not in ("discover", "run"):
        print(__doc__)
        return 2
    if argv[1] == "discover":
        discover()
    else:
        run()
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
