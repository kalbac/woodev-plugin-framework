#!/usr/bin/env python3
"""Why does the Yandex checkout price differ from the price in the Yandex cabinet?

v1 quotes checkout with POST /pricing-calculator and exports with POST /offers/create.
This script builds both request bodies EXACTLY as the v1 plugin builds them, measures
the prices, and then isolates which single parameter moves the price.

Calls ONLY:
  - pickup-points/list   (source and destination points, as v1 requests them)
  - pricing-calculator   (checkout price)
  - offers/create        (cabinet offers)

Never calls offers/confirm, request/create or anything that turns an offer into a request.
Offers expire on their own. The token is read at runtime from the repo's
.wp-env.override.json and is never printed, logged or written to any file.

Usage:
  python3 measure_price.py discover        resolve source/destination points -> raw/ctx.json
  python3 measure_price.py stage A|B       run the grid (A: Moscow, Samara, Kaliningrad; B: the rest)
  python3 measure_price.py isolate         one-parameter isolation on the ISO_CASES
  python3 measure_price.py followup        real dimensions in offers/create (5 variants) and total_assessed_price 0 vs absent
  python3 measure_price.py confirm         model check on every measured case -> confirm.json
  python3 measure_price.py probe_dims      volume vs longest edge: three box shapes, same weight
  python3 measure_price.py summarize       write results.json and tables.md from raw/

Every request/response pair is cached under raw/ (request body + status + response, no headers),
so a rerun reads the cache and calls the API only for missing files.
"""

import functools
import json
import math
import subprocess
import sys
import time
import urllib.error
import urllib.request
from collections import Counter
from pathlib import Path

HERE = Path(__file__).resolve().parent
RAW = HERE / "raw"
REPO = HERE.parents[2]
CONFIG = REPO / ".wp-env.override.json"
PACKER = REPO / "woodev" / "box-packer"

HOST = "https://b2b-authproxy.taxi.yandex.net"
POINTS_PATH = "/api/b2b/platform/pickup-points/list"
CALC_PATH = "/api/b2b/platform/pricing-calculator"
OFFERS_PATH = "/api/b2b/platform/offers/create"

GAP_S = 0.3
BUDGET = 1500
MOSCOW_CENTRE = (55.7558, 37.6173)

GEOS = {
    213: "Moscow",
    51: "Samara",
    22: "Kaliningrad",
    2: "Saint Petersburg",
    239: "Sochi",
    21141: "Timashevsk",
    20721: "Volzhsk",
    121363: "Drozhzhino MO",
}
STAGE_GEOS = {"A": [213, 51, 22], "B": [2, 239, 21141, 20721, 121363]}
STAGE_PROFILES = {
    "A": ["small", "medium", "large", "medium_x3", "two_items"],
    "B": ["medium", "two_items"],
}

PAYMENT_METHOD = {"prepaid": "already_paid", "cod": "card_on_receipt"}
DESTS = ["pickup_market", "pickup_5post", "courier"]

# Cart subtotal is 3000 RUB in every profile (COD client_price and the insurance value).
SUBTOTAL_RUB = 3000

# One entry = one product line. Dimensions in cm as length/width/height, weight in grams per unit,
# price in RUB per unit. v1 maps length->dx, height->dy, width->dz for the calculator.
PROFILES = {
    "small": [dict(sku="SMALL", qty=1, weight=500, length=20, width=15, height=10, price=3000)],
    "medium": [dict(sku="MEDIUM", qty=1, weight=3000, length=40, width=30, height=20, price=3000)],
    "large": [dict(sku="LARGE", qty=1, weight=15000, length=60, width=40, height=40, price=3000)],
    "medium_x3": [dict(sku="MEDIUM", qty=3, weight=3000, length=40, width=30, height=20, price=1000)],
    "two_items": [
        dict(sku="MEDIUM", qty=1, weight=3000, length=40, width=30, height=20, price=2000),
        dict(sku="SMALL", qty=1, weight=500, length=20, width=15, height=10, price=1000),
    ],
}

# Courier destinations. Yandex geocodes these (probe in raw/probe/courier-addresses.json).
# None: no address we tried geocodes ("can't restore coordinates from address"), so no courier case.
COURIER_ADDRESS = {
    213: "Россия, Москва, Тверская улица, 13",
    51: "Россия, Самарская область, Самара, улица Куйбышева, 90",
    22: None,
    2: "Россия, Санкт-Петербург, Невский проспект, 28",
    239: "Россия, Краснодарский край, Сочи, Курортный проспект, 1",
    21141: "Россия, Краснодарский край, Тимашевск, улица Ленина, 1",
    20721: None,
    121363: None,
}

# Isolation: each entry is (geo, dest, profile, package, payment, insurance). Chosen after stage A.
ISO_CASES = [
    (213, "courier", "large", "sku_boxes", "prepaid", "off"),
    (213, "courier", "medium", "sku_boxes", "prepaid", "off"),
    (213, "courier", "small", "sku_boxes", "prepaid", "off"),
    (213, "pickup_market", "medium_x3", "sku_boxes", "cod", "off"),
    (22, "pickup_5post", "medium", "sku_boxes", "prepaid", "off"),
]

# Calculator variants: each moves ONE calculator parameter towards the offer body.
CALC_VARIANTS = [
    "weight_x1000",         # every weight x1000 (int grams): does the calculator price weight at all?
    "dims_one_cm",          # every place dx/dy/dz = 1 cm: does the box size move the calculator price?
    "assessed_flip",        # total_assessed_price toggled
    "no_client_price",      # drop client_price (COD only)
    "cod_delivery_cost",    # billing_info.delivery_cost = shipping total in kopecks (COD only)
    "package_flip",         # sku_boxes <-> common_box
    "no_total_weight",      # drop the top-level total_weight (only the places carry weight)
    "dims_one_cm+assessed_flip",  # combination: the two drivers together should close the gap
]
# Offer variants: each moves ONE offer parameter towards the calculator body.
OFFER_VARIANTS = [
    "weight_x1000",         # every weight x1000 (int grams) in items and places
    "volume_one_cm3",       # every predefined_volume = 1 cm3: does the offer price volume?
    "assessed_zero",        # assessed_unit_price = 0 on every item
    "delivery_cost_zero",   # billing_info.delivery_cost = 0 (COD only)
    "places_per_unit",      # one place per physical unit instead of per SKU line
    "package_flip",         # sku_boxes <-> common_box
]

# Packer: the same PHP classes v1 uses for common_box (woodev/box-packer), run under PHP CLI.
PACKER_PHP = """<?php
define('ABSPATH', '/');
$base = '__BASE__';
foreach (['interfaces/interface-packer.php', 'interfaces/interface-packer-item.php',
          'interfaces/interface-packer-box.php', 'interfaces/interface-packer-item-with-product.php',
          'abstract-class-packer.php', 'class-item-implementation.php', 'class-box-implementation.php',
          'class-packed-box.php', 'class-packer-single-box.php'] as $file) {
    require_once $base . '/' . $file;
}
$items = json_decode('__ITEMS__', true);
$packer = new Woodev_Packer_Single_Box('single_place');
foreach ($items as $item) {
    $packer->add_item(new Woodev_Packer_Item_Implementation($item[0], $item[1], $item[2], $item[3]));
}
$packer->pack();
foreach ($packer->get_packages() as $package) {
    $box = $package->get_box();
    echo json_encode([$box->get_length(), $box->get_width(), $box->get_height(), $box->get_volume()]);
}
"""

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
    """POST to Yandex: 300 ms spacing, backoff on 429, hard call budget. Returns (status, parsed)."""
    global _last, _calls
    if _calls >= BUDGET:
        raise RuntimeError("call budget exhausted")
    data = json.dumps(body).encode("utf-8")
    status, parsed = 0, None
    for attempt in range(5):
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
            time.sleep(2 ** (attempt + 1))
    return status, parsed


def cached(path, body, file):
    """Return the cached {request, status, response} record, calling the API only when missing."""
    if file.exists():
        return json.loads(file.read_text(encoding="utf-8"))
    status, parsed = post(path, body)
    record = {"request": body, "status": status, "response": parsed}
    file.parent.mkdir(parents=True, exist_ok=True)
    file.write_text(json.dumps(record, ensure_ascii=False, indent=1), encoding="utf-8")
    return record


def points(geo, ptype, payment=None, dropoff=False):
    """pickup-points/list exactly as v1 requests it. Not cached: full lists are too big for raw/."""
    body = {"geo_id": geo, "type": ptype}
    if payment:
        body["payment_method"] = payment
    if dropoff:
        body["available_for_dropoff"] = True
    status, parsed = post(POINTS_PATH, body)
    if status != 200 or not isinstance(parsed, dict):
        raise RuntimeError(f"points geo={geo} type={ptype}: HTTP {status}")
    return parsed.get("points") or []


def trim_point(p):
    a = p.get("address") or {}
    return {
        "id": p.get("id"),
        "operator_id": p.get("operator_id"),
        "name": p.get("name"),
        "type": p.get("type"),
        "full_address": a.get("full_address"),
        "region": a.get("region"),
        "locality": a.get("locality"),
        "street": a.get("street"),
        "house": a.get("house"),
        "payment_methods": p.get("payment_methods") or [],
    }


def distance_to_centre(p):
    pos = p.get("position") or {}
    return (pos.get("latitude", 0) - MOSCOW_CENTRE[0]) ** 2 + (pos.get("longitude", 0) - MOSCOW_CENTRE[1]) ** 2


def courier_address(point):
    """Courier destination string from a real point's address (Yandex naming, v1-style order)."""
    street_house = f"{point['street']}, {point['house']}" if point.get("street") else point["full_address"]
    parts = ["Россия", point.get("region"), point.get("locality"), street_house]
    out = []
    for part in parts:
        if part and part not in out:
            out.append(part)
    return ", ".join(out)


def discover():
    """Source = nearest market_l4g drop-off point to central Moscow; per geo, the first point v1 would pick
    (pickup_point list filtered by payment method) plus the first market_l4g and first 5post point."""
    drop = points(213, "pickup_point", dropoff=True)
    markets = sorted([p for p in drop if p.get("operator_id") == "market_l4g"], key=distance_to_centre)
    source = markets[0] if markets else drop[0]
    ctx = {"source": trim_point(source), "geos": {}}
    print(f"source: {ctx['source']['operator_id']} {ctx['source']['id']} ({ctx['source']['full_address']})")

    for geo, name in GEOS.items():
        entry = {"name": name, "points": {}}
        first_point = None
        for pm_key, pm in PAYMENT_METHOD.items():
            pickup_list = points(geo, "pickup_point", payment=pm)
            terminal_list = points(geo, "terminal", payment=pm)
            usable = [p for p in pickup_list + terminal_list if pm in (p.get("payment_methods") or [])]
            v1_first = next((p for p in pickup_list if pm in (p.get("payment_methods") or [])), None)
            picks = {"v1_first": trim_point(v1_first) if v1_first else None}
            for op in ("market_l4g", "5post"):
                hit = next((p for p in usable if p.get("operator_id") == op), None)
                picks[op] = trim_point(hit) if hit else None
            entry["points"][pm] = picks
            entry[f"operators_{pm_key}"] = dict(Counter(p.get("operator_id") for p in pickup_list + terminal_list))
            if first_point is None:
                first_point = pickup_list[0] if pickup_list else None
        if first_point is None:
            raise RuntimeError(f"no pickup point for geo {geo}; courier address cannot be built")
        entry["courier_address_from_point"] = courier_address(trim_point(first_point))
        entry["courier_address"] = COURIER_ADDRESS[geo]
        ctx["geos"][str(geo)] = entry
        seen = {pm: {k: (v["id"] if v else None) for k, v in entry["points"][pm].items()} for pm in PAYMENT_METHOD.values()}
        print(f"geo {geo} {name}: operators {entry['operators_prepaid']} | prepaid {seen['already_paid']} | cod {seen['card_on_receipt']}")

    RAW.mkdir(parents=True, exist_ok=True)
    (RAW / "ctx.json").write_text(json.dumps(ctx, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"wrote {RAW / 'ctx.json'}; calls so far: {_calls}")


def load_ctx():
    path = RAW / "ctx.json"
    if not path.exists():
        raise RuntimeError("run `discover` first")
    return json.loads(path.read_text(encoding="utf-8"))


def units(profile):
    """One entry per physical unit, the way v1 emits calculator places (one per quantity)."""
    return [sku for sku in PROFILES[profile] for _ in range(sku["qty"])]


def total_weight(profile):
    return sum(sku["weight"] * sku["qty"] for sku in PROFILES[profile])


@functools.lru_cache(maxsize=None)
def common_box(profile):
    """Box dims and volume from v1's Woodev_Packer_Single_Box. Items as v1: Item(dx, dz, dy, weight)."""
    items = [[u["length"], u["width"], u["height"], u["weight"]] for u in units(profile)]
    code = PACKER_PHP.replace("__BASE__", str(PACKER)).replace("__ITEMS__", json.dumps(items))
    out = subprocess.run(["php"], input=code, capture_output=True, text=True, check=True).stdout
    length, width, height, volume = json.loads(out)
    # v1 maps the box back as dx=length, dy=height, dz=width.
    return {"dx": length, "dy": height, "dz": width, "volume": volume}


def build_case(stage, geo, dest, profile, package, payment, insurance):
    cid = f"g{geo}-{dest}-{profile}-{package}-{payment}-{insurance}"
    return dict(id=cid, stage=stage, geo=geo, dest=dest, profile=profile, package=package,
                payment=payment, insurance=insurance)


def resolve(case, ctx):
    """Destination for a case, or None when that point kind does not exist for the geo."""
    geo = ctx["geos"][str(case["geo"])]
    if case["dest"] == "courier":
        if geo["courier_address"] is None:
            return None
        return {"pickup": False, "address": geo["courier_address"]}
    op = "market_l4g" if case["dest"] == "pickup_market" else "5post"
    point = geo["points"][PAYMENT_METHOD[case["payment"]]].get(op)
    if point is None:
        return None
    return {"pickup": True, "point": point["id"]}


def calc_body(case, ctx, dest, variants=()):
    """pricing-calculator body built as v1 class-shipping-method-other-day.php get_delivery_cost() does."""
    v = {name for variant in variants for name in variant.split("+")}
    cod = case["payment"] == "cod"
    profile = case["profile"]
    factor = 1000 if "weight_x1000" in v else 1
    source_id = ctx["source"]["id"]
    shipping_kop = None

    places_default = case["package"] == "sku_boxes"
    if "package_flip" in v:
        places_default = not places_default
    if places_default:
        dims = (lambda u: (1, 1, 1)) if "dims_one_cm" in v else (lambda u: (u["length"], u["height"], u["width"]))
        places = [
            {"physical_dims": {"weight_gross": u["weight"] * factor,
                               "dx": dims(u)[0], "dy": dims(u)[1], "dz": dims(u)[2]}}
            for u in units(profile)
        ]
    else:
        box = common_box(profile)
        if "dims_one_cm" in v:
            box = {"dx": 1, "dy": 1, "dz": 1, "volume": 1}
        places = [{"physical_dims": {
            "weight_gross": total_weight(profile) * factor,
            "predefined_volume": box["volume"],
            "dx": box["dx"], "dy": box["dy"], "dz": box["dz"],
        }}]
    if "places_no_dims" in v:
        places = [{"physical_dims": {"weight_gross": u["weight"] * factor}} for u in units(profile)]
    if "places_export_shape" in v:
        places = [{"physical_dims": {
            "weight_gross": s["weight"] * s["qty"] * factor,
            "predefined_volume": s["length"] * s["width"] * s["height"] * s["qty"],
        }} for s in PROFILES[profile]]

    body = {
        "tariff": "self_pickup" if dest["pickup"] else "time_interval",
        "total_weight": total_weight(profile) * factor,
        "places": places,
    }
    if "no_total_weight" in v:
        del body["total_weight"]
    body["source"] = ({"platform_station": {"platform_id": source_id}} if "source_export_shape" in v
                      else {"platform_station_id": source_id})

    if dest["pickup"]:
        pid = dest["point"]
        body["destination"] = ({"platform_station": {"platform_id": pid}} if "dest_export_shape" in v
                               else {"platform_station_id": pid})
    else:
        address = dest["address"]
        body["destination"] = ({"custom_location": {"details": {"full_address": address}}} if "dest_export_shape" in v
                               else {"address": address})

    assessed = case["insurance"] == "on"
    if "assessed_flip" in v:
        assessed = not assessed
    if "assessed_on" in v:
        assessed = True
    if cod:
        body["payment_method"] = "card_on_receipt"
        if "no_client_price" not in v:
            body["client_price"] = SUBTOTAL_RUB * 100
        if "cod_delivery_cost" in v:
            body["billing_info"] = {"payment_method": "card_on_receipt", "delivery_cost": shipping_kop_of(case, ctx)}
    else:
        body["payment_method"] = "already_paid"
    if assessed:
        body["total_assessed_price"] = SUBTOTAL_RUB * 100
    return body


def shipping_kop_of(case, ctx):
    """Kopecks of the shipping total the customer was charged (COD delivery_cost), from the baseline calculator."""
    rec = load_calc(case)
    return calc_price(rec) * 100


def offers_body(case, ctx, dest, shipping_kop, variants=()):
    """offers/create body built as v1 class-order.php prepare_order_data() + create_offers() does."""
    v = {name for variant in variants for name in variant.split("+")}
    cod = case["payment"] == "cod"
    profile = case["profile"]
    source_id = ctx["source"]["id"]
    package_common = case["package"] == "common_box"
    if "package_flip" in v:
        package_common = not package_common

    factor = 1000 if "weight_x1000" in v else 1

    def unit_volume(sku):
        return 1 if "volume_one_cm3" in v else sku["length"] * sku["width"] * sku["height"]

    items, places = [], []
    for sku in PROFILES[profile]:
        unit_kop = sku["price"] * 100
        volume = unit_volume(sku)
        weight = sku["weight"] * factor
        items.append({
            "uin": sku["sku"],
            "article": sku["sku"],
            "count": sku["qty"],
            "name": f"Measure {sku['sku']}",
            "place_barcode": case["id"] if package_common else sku["sku"],
            "billing_details": {
                "assessed_unit_price": 0 if "assessed_zero" in v else unit_kop,
                "unit_price": unit_kop,
                "nds": -1,
            },
            "physical_dims": {"predefined_volume": volume, "weight_gross": weight},
        })
        if package_common:
            continue
        if "places_per_unit" in v:
            for _ in range(sku["qty"]):
                places.append({"barcode": sku["sku"], "description": f"Measure {sku['sku']}",
                               "physical_dims": {"predefined_volume": volume, "weight_gross": weight}})
        else:
            places.append({"barcode": sku["sku"], "description": f"Measure {sku['sku']}",
                           "physical_dims": {"predefined_volume": volume * sku["qty"],
                                             "weight_gross": weight * sku["qty"]}})
    if package_common:
        places = [{
            "barcode": case["id"],
            "description": f"Measure order {case['id']}",
            "physical_dims": {
                "predefined_volume": sum(unit_volume(s) * s["qty"] for s in PROFILES[profile]),
                "weight_gross": total_weight(profile) * factor,
            },
        }]

    if dest["pickup"]:
        pid = dest["point"]
        destination = ({"platform_station_id": pid} if "dest_calc_shape" in v
                       else {"type": "platform_station", "platform_station": {"platform_id": pid}})
    else:
        address = dest["address"]
        destination = ({"address": address} if "dest_calc_shape" in v
                       else {"type": "custom_location", "custom_location": {"details": {"full_address": address, "room": ""}}})

    delivery_cost = shipping_kop if cod else 0
    if "delivery_cost_zero" in v:
        delivery_cost = 0

    return {
        "billing_info": {"payment_method": "card_on_receipt" if cod else "already_paid", "delivery_cost": delivery_cost},
        "destination": destination,
        "info": {"operator_request_id": case["id"], "referral_source": "woodev"},
        "recipient_info": {"first_name": "Measure", "last_name": "Test", "phone": "+79000000000",
                           "email": "measure@example.com"},
        "items": items,
        "places": places,
        "source": ({"platform_station_id": source_id} if "source_calc_shape" in v
                   else {"platform_station": {"platform_id": source_id}}),
        "last_mile_policy": "self_pickup" if dest["pickup"] else "time_interval",
    }


def calc_price(rec):
    """v1: list($price) = explode(' ', pricing_total); return ceil($price)."""
    return math.ceil(float(rec["response"]["pricing_total"].split()[0]))


def offer_rub(pricing_total):
    """v1 admin list: intval($price) from 'NNN.NN RUB'. Truncates the kopecks like intval does."""
    return int(float(pricing_total.split()[0]))


def load_calc(case):
    return json.loads(stage_path(case, "calc").read_text(encoding="utf-8"))


def stage_path(case, kind, variant=None):
    if variant is None:
        return RAW / case["stage"] / f"{case['id']}.{kind}.json"
    return RAW / "iso" / case["id"] / f"{kind}-{variant}.json"


def run_case(case, ctx):
    dest = resolve(case, ctx)
    if dest is None:
        print(f"{case['id']}: absent (no point of this kind for this payment)")
        return
    calc_file = stage_path(case, "calc")
    calc_rec = cached(CALC_PATH, calc_body(case, ctx, dest), calc_file)
    if calc_rec["status"] != 200:
        print(f"{case['id']}: calc HTTP {calc_rec['status']}")
        return
    price = calc_price(calc_rec)
    shipping_kop = price * 100
    offers_file = stage_path(case, "offers")
    offers_rec = cached(OFFERS_PATH, offers_body(case, ctx, dest, shipping_kop), offers_file)
    if offers_rec["status"] != 200:
        print(f"{case['id']}: calc={price} offers HTTP {offers_rec['status']}")
        return
    offer_prices = [offer_rub(o["offer_details"]["pricing_total"]) for o in offers_rec["response"].get("offers", [])]
    print(f"{case['id']}: calc={price} offers={offer_prices}")


def stage(name):
    ctx = load_ctx()
    for geo in STAGE_GEOS[name]:
        for dest in DESTS:
            for profile in STAGE_PROFILES[name]:
                packages = ["sku_boxes", "common_box"] if profile == "two_items" and name == "A" else ["sku_boxes"]
                for package in packages:
                    for payment in ("prepaid", "cod"):
                        for insurance in ("off", "on"):
                            run_case(build_case(name, geo, dest, profile, package, payment, insurance), ctx)
    print(f"stage {name} done; calls so far: {_calls}")


def isolate():
    ctx = load_ctx()
    for geo, dest, profile, package, payment, insurance in ISO_CASES:
        stage_name = "A" if geo in STAGE_GEOS["A"] else "B"
        case = build_case(stage_name, geo, dest, profile, package, payment, insurance)
        d = resolve(case, ctx)
        if d is None or not stage_path(case, "calc").exists() or not stage_path(case, "offers").exists():
            print(f"{case['id']}: no baseline, skipped")
            continue
        base_calc = calc_price(load_calc(case))
        base_offers = json.loads(stage_path(case, "offers").read_text(encoding="utf-8"))["response"].get("offers", [])
        base_cheapest = min(offer_rub(o["offer_details"]["pricing_total"]) for o in base_offers)
        shipping_kop = base_calc * 100
        print(f"{case['id']}: baseline calc={base_calc} cheapest offer={base_cheapest}")
        for variant in CALC_VARIANTS:
            if variant == "cod_delivery_cost" and payment != "cod":
                continue
            if variant == "no_client_price" and payment != "cod":
                continue
            file = stage_path(case, "calc", variant)
            rec = cached(CALC_PATH, calc_body(case, ctx, d, [variant]), file)
            print(f"  calc {variant}: status={rec['status']} price={calc_price(rec) if rec['status'] == 200 else '-'}")
        for variant in OFFER_VARIANTS:
            if variant in ("delivery_cost_zero",) and payment != "cod":
                continue
            file = stage_path(case, "offers", variant)
            rec = cached(OFFERS_PATH, offers_body(case, ctx, d, shipping_kop, [variant]), file)
            prices = ([offer_rub(o["offer_details"]["pricing_total"]) for o in rec["response"].get("offers", [])]
                      if rec["status"] == 200 else "-")
            print(f"  offer {variant}: status={rec['status']} prices={prices}")
    print(f"isolation done; calls so far: {_calls}")


def parse_offers(rec):
    out = []
    for o in (rec.get("response") or {}).get("offers", []) if rec["status"] == 200 else []:
        det = o.get("offer_details") or {}
        out.append({
            "offer_id": o.get("offer_id"),
            "pricing_total": det.get("pricing_total"),
            "price_rub": offer_rub(det["pricing_total"]) if det.get("pricing_total") else None,
            "station_id": o.get("station_id"),
            "policy": (det.get("delivery_interval") or {}).get("policy"),
            "delivery_min": (det.get("delivery_interval") or {}).get("min"),
            "delivery_max": (det.get("delivery_interval") or {}).get("max"),
        })
    return out


def probe_dims():
    """Which box property moves the calculator: volume (and where the threshold is) or the longest edge?
    Medium courier, prepaid, no insurance.
    Same weight (3000 g) and one place; only dx/dy/dz change. Saved under raw/probe/."""
    ctx = load_ctx()
    case = build_case("A", 213, "courier", "medium", "sku_boxes", "prepaid", "off")
    dest = resolve(case, ctx)
    shapes = {
        "long_thin_40x1x1": (40, 1, 1),
        "mid_1x30x20": (1, 30, 20),
        "tall_10x10x240": (10, 10, 240),
        # volumetric divisor check: 15000 cm3 = 3 kg at /5000, 15500 cm3 = 3.1 kg
        "vol15000_25x20x30": (25, 20, 30),
        "vol15500_25x20x31": (25, 20, 31),
        # same volume (24000 cm3), different shape: volume-based or edge-based?
        "vol24000_20x20x60": (20, 20, 60),
        "vol24300_30x30x27": (30, 30, 27),
    }
    out = {}
    for name, (dx, dy, dz) in shapes.items():
        body = calc_body(case, ctx, dest)
        body["places"] = [{"physical_dims": {"weight_gross": 3000, "dx": dx, "dy": dy, "dz": dz}}]
        rec = cached(CALC_PATH, body, RAW / "probe" / f"dims-{name}.json")
        price = calc_price(rec) if rec["status"] == 200 else rec["response"]
        out[name] = price
        print(f"{name} (volume {dx*dy*dz} cm3): {price}")
    print(f"calls so far: {_calls}")


def confirm():
    """Model check on every measured case. Model calculator = volumetric size removed (1 cm places)
    and insurance always on, i.e. the two things the offer body carries and the calculator does not
    (or only with the option). Residual = cheapest offer - model. For COD also the offer with
    billing_info.delivery_cost = 0, the one term the calculator never sees."""
    ctx = load_ctx()
    recs = json.loads((HERE / "results.json").read_text(encoding="utf-8"))
    rows = []
    for r in recs:
        geo, dest, profile, package, payment, insurance = r["id"].split("-")
        case = build_case(r["stage"], int(geo[1:]), dest, profile, package, payment, insurance)
        dest_info = resolve(case, ctx)
        if dest_info is None:
            continue
        shipping_kop = r["calc_price"] * 100
        model = cached(CALC_PATH, calc_body(case, ctx, dest_info, ["dims_one_cm+assessed_on"]),
                       stage_path(case, "calc", "model"))
        if model["status"] != 200:
            print(f"{case['id']}: model calc HTTP {model['status']}")
            continue
        model_price = calc_price(model)
        row = {"id": case["id"], "stage": r["stage"], "calc_v1": r["calc_price"], "model": model_price,
               "offer_cheapest": r["cheapest_offer_price"], "residual": r["cheapest_offer_price"] - model_price,
               "offer_no_delivery_cost": None, "residual_no_delivery_cost": None}
        if payment == "cod":
            nodc = cached(OFFERS_PATH, offers_body(case, ctx, dest_info, shipping_kop, ["delivery_cost_zero"]),
                          stage_path(case, "offers", "delivery_cost_zero"))
            if nodc["status"] == 200:
                cheapest_nodc = min(offer_rub(o["offer_details"]["pricing_total"]) for o in nodc["response"]["offers"])
                row["offer_no_delivery_cost"] = cheapest_nodc
                row["residual_no_delivery_cost"] = cheapest_nodc - model_price
        rows.append(row)
        print(f"{case['id']}: v1 calc={row['calc_v1']} model={model_price} offer={row['offer_cheapest']} "
              f"residual={row['residual']} nodc_residual={row['residual_no_delivery_cost']}")
    (HERE / "confirm.json").write_text(json.dumps(rows, ensure_ascii=False, indent=1), encoding="utf-8")
    prepaid = [r["residual"] for r in rows if "cod" not in r["id"]]
    cod = [r["residual_no_delivery_cost"] for r in rows if r["residual_no_delivery_cost"] is not None]
    print(f"prepaid: {len(prepaid)} cases, residual range {min(prepaid)}..{max(prepaid)}, |r|<=1: "
          f"{sum(abs(x) <= 1 for x in prepaid)}")
    print(f"cod: {len(cod)} cases without delivery_cost, residual range {min(cod)}..{max(cod)}, |r|<=1: "
          f"{sum(abs(x) <= 1 for x in cod)}")
    print(f"calls so far: {_calls}")


# Follow-up: do real dimensions in offers/create make the offer charge the volumetric step?
FOLLOWUP_CASES = [
    (213, "courier", "medium"), (213, "courier", "large"),
    (51, "courier", "medium"), (51, "courier", "large"),
    (213, "pickup_5post", "medium"), (213, "pickup_5post", "large"),
]
FOLLOWUP_VARIANTS = ["v1_shape", "places_dims", "items_dims", "both_dims", "volume_real"]


def with_real_dims(body, profile, variant):
    """Offer body variants: real dx/dy/dz on places and/or items; predefined_volume set from real L*W*H."""
    by_sku = {sku["sku"]: sku for sku in PROFILES[profile]}
    if variant in ("places_dims", "both_dims"):
        for place in body["places"]:
            sku = by_sku[place["barcode"]]
            place["physical_dims"].update(dx=sku["length"], dy=sku["height"], dz=sku["width"])
    if variant in ("items_dims", "both_dims"):
        for item in body["items"]:
            sku = by_sku[item["article"]]
            item["physical_dims"].update(dx=sku["length"], dy=sku["height"], dz=sku["width"])
    if variant == "volume_real":
        for place in body["places"]:
            sku = by_sku[place["barcode"]]
            place["physical_dims"]["predefined_volume"] = sku["length"] * sku["width"] * sku["height"]
        for item in body["items"]:
            sku = by_sku[item["article"]]
            item["physical_dims"]["predefined_volume"] = sku["length"] * sku["width"] * sku["height"]
    return body


def followup():
    ctx = load_ctx()
    folder = RAW / "followup"
    lines = ["| case | calc, real dims, insurance on (v1) | (1) v1 shape | (2) places dx/dy/dz | (3) items dx/dy/dz | (4) both | (5) predefined_volume = real L*W*H |",
             "|---|---|---|---|---|---|---|"]
    for geo, dest, profile in FOLLOWUP_CASES:
        case = build_case("followup", geo, dest, profile, "sku_boxes", "prepaid", "on")
        d = resolve(case, ctx)
        if d is None:
            lines.append(f"| {case['id']} | no point | - | - | - | - | - |")
            continue
        calc = cached(CALC_PATH, calc_body(case, ctx, d), folder / f"{case['id']}.calc.json")
        calc_cell = str(calc_price(calc)) if calc["status"] == 200 else f"HTTP {calc['status']}: {calc['response'].get('message', '')}"
        cells = []
        for variant in FOLLOWUP_VARIANTS:
            body = with_real_dims(offers_body(case, ctx, d, shipping_kop=0), profile, variant)
            rec = cached(OFFERS_PATH, body, folder / f"{case['id']}.offers-{variant}.json")
            if rec["status"] == 200:
                cells.append(str(min(offer_rub(o["offer_details"]["pricing_total"]) for o in rec["response"]["offers"])))
            else:
                cells.append(f"HTTP {rec['status']}: {rec['response'].get('message', '')}")
        lines.append(f"| {case['id']} | {calc_cell} | " + " | ".join(cells) + " |")
        print(lines[-1])
    # total_assessed_price: 0 vs absent, calculator, Moscow courier medium, prepaid, insurance off
    case = build_case("followup", 213, "courier", "medium", "sku_boxes", "prepaid", "off")
    d = resolve(case, ctx)
    absent = cached(CALC_PATH, calc_body(case, ctx, d), folder / "zero-absent.json")
    zero_body = calc_body(case, ctx, d)
    zero_body["total_assessed_price"] = 0
    zero = cached(CALC_PATH, zero_body, folder / "zero-explicit.json")
    zero_line = (f"absent: {calc_price(absent)} RUB; explicit 0: "
                 + (f"{calc_price(zero)} RUB" if zero["status"] == 200 else f"HTTP {zero['status']}: {zero['response'].get('message', '')}"))
    (HERE / "followup.md").write_text("\n".join(lines) + "\n\n" + zero_line + "\n", encoding="utf-8")
    print(zero_line)
    print(f"wrote followup.md; calls so far: {_calls}")


def summarize():
    ctx = load_ctx()
    records = []
    for stage_name in ("A", "B"):
        for file in sorted((RAW / stage_name).glob("*.calc.json")):
            cid = file.name[: -len(".calc.json")]
            case = {"id": cid, "stage": stage_name}
            calc_rec = json.loads(file.read_text(encoding="utf-8"))
            offers_file = RAW / stage_name / f"{cid}.offers.json"
            if calc_rec["status"] != 200 or not offers_file.exists():
                continue
            offers_rec = json.loads(offers_file.read_text(encoding="utf-8"))
            offers = parse_offers(offers_rec)
            if not offers:
                continue
            price = calc_price(calc_rec)
            cheapest = min(offers, key=lambda o: o["price_rub"])
            records.append({
                "id": cid, "stage": stage_name,
                "calc_raw": calc_rec["response"]["pricing_total"], "calc_price": price,
                "calc_commission": calc_rec["response"].get("pricing_commission_on_delivery_payment"),
                "calc_commission_amount": calc_rec["response"].get("pricing_commission_on_delivery_payment_amount"),
                "offers": offers,
                "first_offer_price": offers[0]["price_rub"],
                "cheapest_offer_price": cheapest["price_rub"],
                "delta_rub": cheapest["price_rub"] - price,
                "delta_pct": round((cheapest["price_rub"] - price) / price * 100, 2) if price else None,
            })
    (HERE / "results.json").write_text(json.dumps(records, ensure_ascii=False, indent=1), encoding="utf-8")
    lines = ["| case | calc (ceil) | cheapest offer | first offer (v1 list order) | delta RUB | delta % | offers |",
             "|---|---|---|---|---|---|---|"]
    for r in records:
        offers_txt = ", ".join(f"{o['price_rub']} ({o['policy']})" for o in r["offers"])
        lines.append(f"| {r['id']} | {r['calc_price']} | {r['cheapest_offer_price']} | {r['first_offer_price']} | "
                     f"{r['delta_rub']} | {r['delta_pct']} | {offers_txt} |")
    lines += ["", "## Isolation", "",
              "| case | probe | calc | calc vs baseline | gap after (offer - calc) |",
              "|---|---|---|---|---|"]
    iso_lines = []
    for geo, dest, profile, package, payment, insurance in ISO_CASES:
        stage_name = "A" if geo in STAGE_GEOS["A"] else "B"
        case = build_case(stage_name, geo, dest, profile, package, payment, insurance)
        base_file = stage_path(case, "calc")
        off_file = stage_path(case, "offers")
        if not base_file.exists() or not off_file.exists():
            continue
        base_calc = json.loads(base_file.read_text(encoding="utf-8"))
        base_offers = json.loads(off_file.read_text(encoding="utf-8"))
        base_price = calc_price(base_calc)
        base_cheapest = min(offer_rub(o["offer_details"]["pricing_total"]) for o in base_offers["response"]["offers"])
        iso_lines.append(f"| {case['id']} | baseline | {base_price} ({base_calc['response']['pricing_total']}) | - | "
                         f"{base_cheapest - base_price} (offer {base_cheapest}) |")
        for variant in CALC_VARIANTS:
            f = stage_path(case, "calc", variant)
            if not f.exists():
                continue
            rec = json.loads(f.read_text(encoding="utf-8"))
            if rec["status"] != 200:
                iso_lines.append(f"| {case['id']} | calc {variant} | HTTP {rec['status']}: {rec['response'].get('message', '')} | - | - |")
                continue
            price = calc_price(rec)
            iso_lines.append(f"| {case['id']} | calc {variant} | {price} ({rec['response']['pricing_total']}) | "
                             f"{price - base_price:+d} | {base_cheapest - price:+d} |")
        for variant in OFFER_VARIANTS:
            f = stage_path(case, "offers", variant)
            if not f.exists():
                continue
            rec = json.loads(f.read_text(encoding="utf-8"))
            if rec["status"] != 200:
                iso_lines.append(f"| {case['id']} | offer {variant} | HTTP {rec['status']}: {rec['response'].get('message', '')} | - | - |")
                continue
            cheapest = min(offer_rub(o["offer_details"]["pricing_total"]) for o in rec["response"]["offers"])
            iso_lines.append(f"| {case['id']} | offer {variant} | offer {cheapest} | {cheapest - base_cheapest:+d} | "
                             f"{cheapest - base_price:+d} |")
    lines += iso_lines
    (HERE / "tables.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"wrote results.json ({len(records)} cases) and tables.md")


def main(argv):
    if len(argv) < 2:
        print(__doc__)
        return 2
    cmd = argv[1]
    if cmd == "discover":
        discover()
    elif cmd == "stage":
        stage(argv[2])
    elif cmd == "isolate":
        isolate()
    elif cmd == "followup":
        followup()
    elif cmd == "confirm":
        confirm()
    elif cmd == "probe_dims":
        probe_dims()
    elif cmd == "summarize":
        summarize()
    else:
        print(__doc__)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
