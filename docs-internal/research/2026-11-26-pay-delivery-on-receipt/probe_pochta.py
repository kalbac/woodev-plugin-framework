#!/usr/bin/env python3
"""Card #1475: can Russian Post collect only the delivery fee on a prepaid parcel («Отправка» API)?

Creates a few orders in «Новые» of the operator's TEST cabinet, records the answer and DELETEs every created order
(no batch, no hand-over). Credentials are read at runtime from the repo's .wp-env.override.json and are never printed or
written; account data (sender, settings) is not stored.

Usage (from this folder): python3 -I probe_pochta.py   -> raw/pochta-<case>.json
"""

import base64
import json
import time
import urllib.error
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
RAW = HERE / "raw"
CONFIG = HERE.parents[2] / ".wp-env.override.json"
BASE = "https://otpravka-api.pochta.ru"
COD = "WITH_DECLARED_VALUE_AND_CASH_ON_DELIVERY"
COMPULSORY = "WITH_COMPULSORY_PAYMENT"
COMPULSORY_DECLARED = "WITH_DECLARED_VALUE_AND_COMPULSORY_PAYMENT"

# case: (mail-type, mail-category, money fields in kopecks)
CASES = {
    "p1-cod-fee-only-below-declared": ("ONLINE_PARCEL", COD, {"insr-value": 500000, "payment": 30000}),
    "p2-cod-fee-above-declared": ("ONLINE_PARCEL", COD, {"insr-value": 10000, "payment": 30000}),
    "p3-cod-fee-zero-declared": ("ONLINE_PARCEL", COD, {"insr-value": 0, "payment": 30000}),
}


def main():
    env = json.loads(CONFIG.read_text(encoding="utf-8"))["env"]["development"]["config"]
    basic = base64.b64encode(
        (env["WOODEV_POCHTA_PROD_LOGIN"] + ":" + env["WOODEV_POCHTA_PROD_PASSWORD"]).encode()).decode()
    headers = {"Authorization": "AccessToken " + env["WOODEV_POCHTA_PROD_ACCESS_TOKEN"],
               "X-User-Authorization": "Basic " + basic,
               "Content-Type": "application/json;charset=UTF-8", "Accept": "application/json;charset=UTF-8"}

    def call(method, path, data=None):
        req = urllib.request.Request(BASE + path, data=json.dumps(data).encode() if data is not None else None,
                                     method=method, headers=headers)
        time.sleep(0.8)
        try:
            with urllib.request.urlopen(req, timeout=90) as resp:
                return resp.status, json.loads(resp.read() or b"null")
        except urllib.error.HTTPError as err:
            text = err.read().decode("utf-8", "replace")
            try:
                return err.code, json.loads(text)
            except ValueError:
                return err.code, text[:300]

    points = call("GET", "/1.0/user-shipping-points")[1]
    point = next((p for p in points if str(p.get("operator-postcode")) == "109012"), points[0])
    products = point.get("user-available-products") or []
    compulsory = sorted({(p.get("mail-type"), p.get("mail-category")) for p in products
                         if "COMPULSORY" in str(p.get("mail-category"))})
    print("compulsory-payment products at this intake office:", compulsory)

    cases = dict(CASES)
    for mail_type, category in compulsory:
        money = {"compulsory-payment": 30000}
        if category == COMPULSORY_DECLARED:
            money["insr-value"] = 500000
        cases[f"p4-{mail_type.lower()}-{category.lower()}"] = (mail_type, category, money)
    # Also the compulsory categories on the parcel types the plugin ships, whatever the account lists.
    cases.setdefault("p5-online-parcel-compulsory", ("ONLINE_PARCEL", COMPULSORY, {"compulsory-payment": 30000}))
    cases.setdefault("p6-postal-parcel-compulsory", ("POSTAL_PARCEL", COMPULSORY, {"compulsory-payment": 30000}))

    RAW.mkdir(exist_ok=True)
    (RAW / "pochta-products-compulsory.json").write_text(
        json.dumps({"date": time.strftime("%Y-%m-%d"), "products": compulsory}, indent=1), encoding="utf-8")
    created = []
    try:
        for tag, (mail_type, category, money) in cases.items():
            order = {"address-type-to": "DEFAULT", "mail-type": mail_type, "mail-category": category,
                     "mail-direct": 643, "mass": 1000, "index-to": 190000, "region-to": "г Санкт-Петербург",
                     "place-to": "г Санкт-Петербург", "street-to": "Невский пр-кт", "house-to": "1",
                     "given-name": "Тест", "surname": "TestovR1475", "tel-address": 79000000000,
                     "order-num": f"R1475-{tag[:2]}-{int(time.time())}",
                     "postoffice-code": str(point.get("operator-postcode")), **money}
            status, reply = call("PUT", "/2.0/user/backlog", [order])
            record = {"date": time.strftime("%Y-%m-%d"),
                      "request": {k: v for k, v in order.items() if k != "postoffice-code"},
                      "status": status, "response": reply}
            for row in (reply.get("orders") or []) if isinstance(reply, dict) else []:
                created.append(row["result-id"])
                got = call("GET", f"/1.0/backlog/{row['result-id']}")[1]
                record["stored"] = {k: got.get(k) for k in (
                    "mail-type", "mail-category", "insr-value", "payment", "compulsory-payment", "payment-method")}
            (RAW / f"pochta-{tag}.json").write_text(json.dumps(record, ensure_ascii=False, indent=1), encoding="utf-8")
            errors = reply.get("errors") if isinstance(reply, dict) else reply
            print(tag, status, json.dumps(record.get("stored") or errors, ensure_ascii=False)[:600])
    finally:
        if created:
            status, reply = call("DELETE", "/1.0/backlog", created)
            print("DELETE", status, reply)
            for number in created:
                print("verify", number, call("GET", f"/1.0/backlog/{number}")[0])


if __name__ == "__main__":
    main()
