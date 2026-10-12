#!/usr/bin/env python3
"""COD surcharge on the delivery cost (R2): how offers/create prices billing_info.delivery_cost.

Calls ONLY POST /api/b2b/platform/offers/create. Never calls offers/confirm, request/* or
anything that turns an offer into a request. Offers expire on their own. The token is read at
runtime from the repo's .wp-env.override.json and is never printed, logged or written to any file.

Usage (from this folder):
  python3 -I measure_cod.py run        run every case below (cached: raw/ is read before any call)
  python3 -I measure_cod.py summarize  write results.json from raw/
  python3 -I measure_cod.py fit        compare the COD commission with the models -> fit.json

Every request/response pair is cached under raw/ (request body + status + response, no headers),
so a rerun reads the cache and calls the API only for missing files.
"""

import json
import sys
import time
import urllib.error
import urllib.request
from decimal import Decimal
from pathlib import Path

HERE = Path(__file__).resolve().parent
RAW = HERE / "raw"
REPO = HERE.parents[2]
CONFIG = REPO / ".wp-env.override.json"

HOST = "https://b2b-authproxy.taxi.yandex.net"
OFFERS_PATH = "/api/b2b/platform/offers/create"

GAP_S = 0.5
BUDGET = 60            # offers/create requests for the whole task (brief: <= 60)
RETRIES_429 = 3

SOURCE = "20cfe81c-bb45-4cfa-85e6-31fdade8ed23"       # Moscow market_l4g drop-off, Трубная площадь 2
MOSCOW_MARKET = "05212278-441c-4033-9ed8-851ca27facca"  # Moscow market_l4g, Волгоградский пр. 10 стр2 (accepts COD)
SAMARA_MARKET = "01a0ece738f473b7be140baf6bde5a51"      # Samara market_l4g, Ново-Садовая 106 к155 (accepts COD)
MOSCOW_5POST = "019e0aec42e271dab592470ac89a572c"       # Moscow 5post, Боровское ш 62к3 (refuses COD per ctx.json)

RUB = 100  # kopecks per RUB; the API takes kopecks

_last = 0.0
_calls = 0


def token():
    return json.loads(CONFIG.read_text(encoding="utf-8"))["env"]["development"]["config"]["WOODEV_YANDEX_PROD_TOKEN"]


def read_json(raw):
    try:
        return json.loads(raw)
    except ValueError:
        return raw.decode("utf-8", "replace")[:500]


def post(path, body):
    """POST to Yandex: 500 ms spacing, back-off on 429 (3 retries, then stop), hard call budget."""
    global _last, _calls
    if _calls >= BUDGET:
        raise RuntimeError("call budget exhausted")
    data = json.dumps(body).encode("utf-8")
    status, parsed = 0, None
    for attempt in range(RETRIES_429 + 1):
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
        _calls += 1
        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                return resp.status, read_json(resp.read())
        except urllib.error.HTTPError as err:
            status, parsed = err.code, read_json(err.read())
            if status != 429:
                return status, parsed
            if attempt == RETRIES_429:
                raise RuntimeError("HTTP 429 after 3 back-offs: stopping")
            time.sleep(2 ** (attempt + 1))
    return status, parsed


def cached(body, name):
    """Return the cached {request, status, response} record, calling the API only when missing."""
    file = RAW / f"{name}.json"
    if file.exists():
        return json.loads(file.read_text(encoding="utf-8"))
    status, parsed = post(OFFERS_PATH, body)
    record = {"request": body, "status": status, "response": parsed}
    file.parent.mkdir(parents=True, exist_ok=True)
    file.write_text(json.dumps(record, ensure_ascii=False, indent=1), encoding="utf-8")
    return record


def body(tag, station, payment, delivery_kop, client_rub=3000, assessed_rub=None):
    """One MEDIUM parcel (40x30x20 cm, 3 kg, one unit at client_rub), as the s174 v1-shape offers body.

    assessed_rub defaults to client_rub; 0 sends an explicit zero assessed value.
    """
    unit = client_rub * RUB
    assessed = unit if assessed_rub is None else assessed_rub * RUB
    dims = {"predefined_volume": 24000, "weight_gross": 3000}
    return {
        "billing_info": {"payment_method": payment, "delivery_cost": delivery_kop},
        "destination": {"type": "platform_station", "platform_station": {"platform_id": station}},
        "info": {"operator_request_id": f"r2-{tag}", "referral_source": "woodev"},
        "recipient_info": {"first_name": "Measure", "last_name": "Test", "phone": "+79000000000",
                           "email": "measure@example.com"},
        "items": [{
            "uin": "MEDIUM", "article": "MEDIUM", "count": 1, "name": "Measure MEDIUM",
            "place_barcode": "MEDIUM",
            "billing_details": {"assessed_unit_price": assessed, "unit_price": unit, "nds": -1},
            "physical_dims": dict(dims),
        }],
        "places": [{"barcode": "MEDIUM", "description": "Measure MEDIUM", "physical_dims": dict(dims)}],
        "source": {"platform_station": {"platform_id": SOURCE}},
        "last_mile_policy": "self_pickup",
    }


COD = "card_on_receipt"
PREPAID = "already_paid"

# (name, station, payment, delivery_cost in kopecks, client RUB, assessed RUB or None)
# Series 1 - Moscow market_l4g, COD, client 3000 RUB: delivery_cost grid + odd values + repeats.
CASES = []
for rub in [0, 1, 100, 300, 500, 1000, 2000, 5000, 20000]:
    CASES.append((f"s1-moscow-cod-d{rub}rub", MOSCOW_MARKET, COD, rub * RUB, 3000, None))
for kop in [1, 50, 99, 12345, 77777]:  # odd values: rounding and non-round kopecks
    CASES.append((f"s1-moscow-cod-d{kop}kop", MOSCOW_MARKET, COD, kop, 3000, None))
for rub in [300, 1000]:                 # repeats: stability
    CASES.append((f"s1-moscow-cod-d{rub}rub-rep", MOSCOW_MARKET, COD, rub * RUB, 3000, None))

# Series 2 - client price and assessed value at fixed delivery_cost 1000 RUB.
for client in [1000, 5000, 10000]:
    CASES.append((f"s2-moscow-cod-d1000rub-client{client}", MOSCOW_MARKET, COD, 1000 * RUB, client, None))
CASES.append(("s2-moscow-cod-d0-assessed0", MOSCOW_MARKET, COD, 0, 3000, 0))
CASES.append(("s2-moscow-cod-d1000rub-assessed0", MOSCOW_MARKET, COD, 1000 * RUB, 3000, 0))

# Series 3 - Samara market_l4g (another city), COD.
for rub in [0, 1000, 5000]:
    CASES.append((f"s3-samara-cod-d{rub}rub", SAMARA_MARKET, COD, rub * RUB, 3000, None))

# Series 4 - 5post in Moscow (ctx.json: no 5post point accepts card_on_receipt). Probe with COD anyway.
for rub in [0, 1000, 5000]:
    CASES.append((f"s4-moscow5post-cod-d{rub}rub", MOSCOW_5POST, COD, rub * RUB, 3000, None))

# Series 5 - already_paid control: a non-zero delivery_cost must not change anything.
CASES.append(("s5-moscow-prepaid-d0", MOSCOW_MARKET, PREPAID, 0, 3000, None))
CASES.append(("s5-moscow-prepaid-d1000rub", MOSCOW_MARKET, PREPAID, 1000 * RUB, 3000, None))


def run():
    for name, station, payment, kop, client, assessed in CASES:
        if name.startswith("s4-") and name != "s4-moscow5post-cod-d0rub":
            # Stop the 5post series if the first COD probe was refused.
            first = RAW / "s4-moscow5post-cod-d0rub.json"
            if not first.exists() or json.loads(first.read_text(encoding="utf-8"))["status"] != 200:
                print(f"{name}: skipped (5post COD probe not accepted)")
                continue
        rec = cached(body(name, station, payment, kop, client, assessed), name)
        if rec["status"] == 200:
            totals = [o["offer_details"]["pricing_total"] for o in rec["response"].get("offers", [])]
            print(f"{name}: HTTP 200, {len(totals)} offers, first {totals[:1]}")
        else:
            print(f"{name}: HTTP {rec['status']} {json.dumps(rec['response'], ensure_ascii=False)[:200]}")
    print(f"offers/create calls this run: {_calls}")


def summarize():
    rows = []
    for name, station, payment, kop, client, assessed in CASES:
        file = RAW / f"{name}.json"
        if not file.exists():
            rows.append({"case": name, "missing": True})
            continue
        rec = json.loads(file.read_text(encoding="utf-8"))
        row = {"case": name, "station": station, "payment": payment, "delivery_cost_kop": kop,
               "client_rub": client, "assessed_rub": client if assessed is None else assessed,
               "status": rec["status"]}
        if rec["status"] == 200:
            offers = rec["response"].get("offers", [])
            details = [o["offer_details"] for o in offers]
            row["offers"] = len(offers)
            row["pricing_total"] = sorted({d["pricing_total"] for d in details})
            row["pricing"] = sorted({d.get("pricing") for d in details})
            row["commission_pct"] = sorted({d.get("pricing_commission_on_delivery_payment") for d in details})
            row["commission_amount"] = sorted({d.get("pricing_commission_on_delivery_payment_amount") for d in details})
            row["policy"] = sorted({(d.get("delivery_interval") or {}).get("policy") for d in details})
            row["features"] = sorted({json.dumps(d.get("features", {}), sort_keys=True) for d in details})
        else:
            row["error"] = rec["response"] if isinstance(rec["response"], str) else json.dumps(rec["response"], ensure_ascii=False)[:300]
        rows.append(row)
    (HERE / "results.json").write_text(json.dumps(rows, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"wrote results.json: {len(rows)} cases")


K = Decimal("0.0305")  # measured: 3.05 % of (goods + delivery_cost), see README


def floor2(value):
    """Truncate to kopecks, the rounding the API shows (checked on 50 and 12345 kop)."""
    return value.quantize(Decimal("0.01"), rounding="ROUND_FLOOR")


def fit():
    """Compare every measured COD commission with the two candidate models; write fit.json."""
    rows = []
    for name, station, payment, kop, client, assessed in CASES:
        if payment != COD:
            continue
        file = RAW / f"{name}.json"
        if not file.exists() or json.loads(file.read_text(encoding="utf-8"))["status"] != 200:
            continue
        offers = json.loads(file.read_text(encoding="utf-8"))["response"]["offers"]
        det = offers[0]["offer_details"]
        observed = Decimal(det["pricing_commission_on_delivery_payment_amount"].split()[0])
        pricing = Decimal(det["pricing"].split()[0])
        total = Decimal(det["pricing_total"].split()[0])
        goods = Decimal(client)
        d_rub = Decimal(kop) / 100
        linear = K * (goods + d_rub)
        floored = floor2(linear)
        rows.append({
            "case": name,
            "goods_rub": str(goods),
            "delivery_cost_rub": str(d_rub),
            "assessed_rub": str(client if assessed is None else assessed),
            "observed_commission": str(observed),
            "model_linear": str(linear.quantize(Decimal("0.00001"))),
            "model_floor2": str(floored),
            "residual_floor2": str(observed - floored),
            "pricing": str(pricing),
            "pricing_total": str(total),
            "total_check": str(pricing + observed - total),
        })
    (HERE / "fit.json").write_text(json.dumps(rows, ensure_ascii=False, indent=1), encoding="utf-8")
    worst = max(abs(Decimal(r["residual_floor2"])) for r in rows)
    print(f"fit: {len(rows)} COD cases; max |observed - floor2(K*(goods+D))| = {worst} RUB")
    for r in rows:
        print(f"{r['case']}: D={r['delivery_cost_rub']} goods={r['goods_rub']} obs={r['observed_commission']} "
              f"linear={r['model_linear']} floor2={r['model_floor2']} res={r['residual_floor2']} "
              f"total_check={r['total_check']}")
    return 0


def main(argv):
    if len(argv) < 2 or argv[1] not in ("run", "summarize", "fit"):
        print(__doc__)
        return 2
    if argv[1] == "run":
        run()
    elif argv[1] == "summarize":
        summarize()
    else:
        return fit()
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
