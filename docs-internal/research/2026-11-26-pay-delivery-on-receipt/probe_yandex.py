#!/usr/bin/env python3
"""Card #1475: can Yandex Delivery take «goods prepaid, recipient pays only delivery»?

Calls ONLY POST /api/b2b/platform/offers/create (an offer expires on its own; nothing is confirmed, no request is
created). The token is read at runtime from the repo's .wp-env.override.json and is never printed or written.

Usage (from this folder): python3 -I probe_yandex.py   -> raw/yandex-<case>.json (request, status, response summary)
"""

import json
import time
import urllib.error
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
RAW = HERE / "raw"
CONFIG = HERE.parents[2] / ".wp-env.override.json"
URL = "https://b2b-authproxy.taxi.yandex.net/api/b2b/platform/offers/create"
SOURCE = "20cfe81c-bb45-4cfa-85e6-31fdade8ed23"  # Moscow drop-off, as in the s174 measurements
POINT = "05212278-441c-4033-9ed8-851ca27facca"  # Moscow market_l4g, accepts card_on_receipt

# case: (payment_method, unit_price kop, delivery_cost kop)
CASES = {
    "y1-prepaid-with-fee": ("already_paid", 300000, 30000),
    "y2-postpay-goods-zero-with-fee": ("card_on_receipt", 0, 30000),
    "y3-postpay-goods-zero-no-fee": ("card_on_receipt", 0, 0),
    "y4-postpay-full-cod-control": ("card_on_receipt", 300000, 30000),
}


def body(tag, payment, unit, delivery):
    dims = {"predefined_volume": 24000, "weight_gross": 3000}
    return {
        "info": {"operator_request_id": f"r1475-{tag}", "referral_source": "woodev"},
        "source": {"platform_station": {"platform_id": SOURCE}},
        "destination": {"type": "platform_station", "platform_station": {"platform_id": POINT}},
        "last_mile_policy": "self_pickup",
        "billing_info": {"payment_method": payment, "delivery_cost": delivery},
        "recipient_info": {"first_name": "Measure", "last_name": "Test", "phone": "+79000000000",
                           "email": "measure@example.com"},
        "places": [{"barcode": f"r1475-{tag}", "physical_dims": dims}],
        "items": [{"article": "r1475", "count": 1, "name": "Measurement item", "place_barcode": f"r1475-{tag}",
                   "billing_details": {"assessed_unit_price": 300000, "unit_price": unit, "nds": -1}}],
    }


def main():
    token = json.loads(CONFIG.read_text(encoding="utf-8"))["env"]["development"]["config"]["WOODEV_YANDEX_PROD_TOKEN"]
    RAW.mkdir(exist_ok=True)
    for tag, case in CASES.items():
        request = body(tag, *case)
        req = urllib.request.Request(URL, data=json.dumps(request).encode(), method="POST", headers={
            "Authorization": "Bearer " + token, "Accept-Language": "ru", "Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                status, parsed = resp.status, json.loads(resp.read())
        except urllib.error.HTTPError as err:
            status, parsed = err.code, json.loads(err.read() or b"null")
        offers = parsed.get("offers") if isinstance(parsed, dict) else None
        if offers:
            details = offers[0].get("offer_details", {})
            summary = {"offers": len(offers), "first_offer_pricing": {k: v for k, v in details.items() if "pric" in k}}
        else:
            summary = parsed
        record = {"date": time.strftime("%Y-%m-%d"), "request": request, "status": status, "response": summary}
        (RAW / f"yandex-{tag}.json").write_text(json.dumps(record, ensure_ascii=False, indent=1), encoding="utf-8")
        print(tag, status, json.dumps(summary, ensure_ascii=False)[:600])
        time.sleep(0.6)


if __name__ == "__main__":
    main()
