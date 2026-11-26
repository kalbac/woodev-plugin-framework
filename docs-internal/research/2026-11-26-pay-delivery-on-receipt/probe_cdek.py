#!/usr/bin/env python3
"""Card #1475: does CDEK accept «goods prepaid, recipient pays only delivery» (`delivery_recipient_cost`)?

Creates a few office-to-office orders on the operator's test-only production account, reads what CDEK answered and
DELETEs every one of them (status «Создан»: nothing is shipped, no courier, no intake). Credentials are read at runtime
from the repo's .wp-env.override.json; they, the token and the account's own data are never printed or written.

Usage (from this folder): python3 -I probe_cdek.py [case ...]   -> raw/cdek-<case>.json (request and a whitelisted summary)
"""

import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
RAW = HERE / "raw"
CONFIG = HERE.parents[2] / ".wp-env.override.json"
BASE = "https://api.cdek.ru/v2"
TARIFF = 136  # parcel, office to office

# case: (declared cost of the item, payment.value of the item, delivery_recipient_cost, cod_point)
CASES = {
    "c1-prepaid-with-fee": (1000, 0, 300, True),
    "c2-prepaid-no-fee-control": (1000, 0, 0, True),
    "c3-zero-declared-with-fee": (0, 0, 300, True),
    "c4-fee-above-declared": (100, 0, 300, True),
    "c5-fee-to-point-without-cod": (1000, 0, 300, False),
    "c6-no-fee-to-point-without-cod-control": (1000, 0, 0, False),
}


def call(method, path, token=None, data=None, form=None):
    headers = {"Content-Type": "application/x-www-form-urlencoded" if form else "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    body = urllib.parse.urlencode(form).encode() if form else (json.dumps(data).encode() if data else None)
    req = urllib.request.Request(BASE + path, data=body, method=method, headers=headers)
    time.sleep(0.3)
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            return resp.status, json.loads(resp.read() or b"null")
    except urllib.error.HTTPError as err:
        return err.code, json.loads(err.read() or b"null")


def summary(reply):
    """Only fields that say nothing about the account."""
    entity = reply.get("entity", {}) if isinstance(reply, dict) else {}
    return {
        "requests": [{k: r.get(k) for k in ("type", "state", "errors", "warnings") if r.get(k)}
                     for r in (reply.get("requests", []) if isinstance(reply, dict) else [])],
        "statuses": [s.get("code") for s in entity.get("statuses", [])],
        "delivery_recipient_cost": entity.get("delivery_recipient_cost"),
        "items": [{"cost": i.get("cost"), "payment": i.get("payment")}
                  for p in entity.get("packages", []) for i in p.get("items", [])],
        "services": [s.get("code") for s in entity.get("services", [])],
        "delivery_detail": {k: v for k, v in entity.get("delivery_detail", {}).items()
                            if k in ("delivery_sum", "total_sum", "payment_sum", "payment_info")},
    }


def main():
    env = json.loads(CONFIG.read_text(encoding="utf-8"))["env"]["development"]["config"]
    status, auth = call("POST", "/oauth/token", form={
        "grant_type": "client_credentials", "client_id": env["WOODEV_CDEK_PROD_CLIENT_ID"],
        "client_secret": env["WOODEV_CDEK_PROD_CLIENT_SECRET"]})
    if status != 200:
        raise SystemExit(f"auth failed: {status}")
    token = auth["access_token"]

    def points(city, **extra):
        query = urllib.parse.urlencode({"city_code": city, "type": "PVZ", **extra})
        return call("GET", "/deliverypoints?" + query, token)[1]

    origin = points(44, is_reception="true")[0]["code"]
    spb = points(137, is_handout="true")
    cod = next(p for p in spb if p.get("allowed_cod"))["code"]
    no_cod = next((p for p in spb if p.get("allowed_cod") is False), None)
    RAW.mkdir(exist_ok=True)
    created = []

    try:
        for tag, (cost, payment, fee, cod_point) in CASES.items():
            if sys.argv[1:] and tag not in sys.argv[1:]:
                continue
            if not cod_point and no_cod is None:
                print(tag, "skipped: no office without allowed_cod in the city")
                continue
            request = {
                "type": 1, "number": f"R1475-{tag[:2]}-{int(time.time())}", "tariff_code": TARIFF,
                "shipment_point": origin, "delivery_point": cod if cod_point else no_cod["code"],
                "recipient": {"name": "Тест Проверка", "phones": [{"number": "+79000000000"}]},
                "delivery_recipient_cost": {"value": fee},
                "packages": [{"number": "1", "weight": 1000, "length": 20, "width": 15, "height": 10, "items": [{
                    "name": "Измерительный товар", "ware_key": "r1475", "amount": 1, "weight": 1000,
                    "cost": cost, "payment": {"value": payment}}]}],
            }
            status, reply = call("POST", "/orders", token, data=request)
            uuid = (reply.get("entity") or {}).get("uuid")
            record = {"date": time.strftime("%Y-%m-%d"), "cod_point": cod_point, "request": request,
                      "post_status": status, "post": summary(reply)}
            if uuid:
                created.append(uuid)
                for _ in range(12):
                    time.sleep(5)
                    got = call("GET", f"/orders/{uuid}", token)[1]
                    states = [r.get("state") for r in got.get("requests", []) if r.get("type") == "CREATE"]
                    if states and states[0] in ("SUCCESSFUL", "INVALID"):
                        break
                time.sleep(10)
                record["get"] = summary(call("GET", f"/orders/{uuid}", token)[1])
            (RAW / f"cdek-{tag}.json").write_text(json.dumps(record, ensure_ascii=False, indent=1), encoding="utf-8")
            print(tag, status, json.dumps(record.get("get", record["post"]), ensure_ascii=False)[:900])
    finally:
        for uuid in created:
            print("DELETE", uuid, call("DELETE", f"/orders/{uuid}", token)[0])
        time.sleep(25)
        for uuid in created:
            status, reply = call("GET", f"/orders/{uuid}", token)
            info = summary(reply)
            codes = [e.get("code") for r in info["requests"] for e in r.get("errors") or []]
            print("verify", uuid, status, codes, info["statuses"][:3])


if __name__ == "__main__":
    main()
