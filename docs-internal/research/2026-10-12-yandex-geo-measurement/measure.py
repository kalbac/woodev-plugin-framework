#!/usr/bin/env python3
"""Read-only measurement of how Yandex Delivery resolves Russian settlement strings.

Calls ONLY:
  - DaData  suggest/address        ground truth (name, type, region, area, FIAS, coordinates)
  - Yandex  location/detect        free string -> up to 4 geo_id variants
  - Yandex  pickup-points/list     points by geo_id, and by a bounding box around DaData coordinates

Tokens are read at runtime from the repo's .wp-env.override.json (key env.development.config).
They are never printed, logged or written to any file in this directory.

Usage:
  python3 measure.py discover "<query>" ["<region>"]   DaData candidates only (no Yandex calls)
  python3 measure.py run [<key> ...]                   measure all settlements, or only the given keys
  python3 measure.py summarize                         write results.json and tables.md from the cache
  python3 measure.py rules                            score candidate matching rules offline (rules.md)

Every response is cached under raw/; a rerun reads the cache and calls the API only for missing files.
"""

import json
import math
import re
import statistics
import sys
import time
import urllib.error
import urllib.request
from collections import Counter
from pathlib import Path

HERE = Path(__file__).resolve().parent
RAW = HERE / "raw"
CONFIG = HERE.parents[2] / ".wp-env.override.json"  # repo root

YANDEX_HOST = "https://b2b-authproxy.taxi.yandex.net"
DETECT_PATH = "/api/b2b/platform/location/detect"
POINTS_PATH = "/api/b2b/platform/pickup-points/list"
DADATA_URL = "https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address"

YANDEX_GAP_S = 0.3
YANDEX_BUDGET = 1500
BBOX_HALF_SIZES = (0.05, 0.1, 0.2)
POINT_TYPES = ("pickup_point", "terminal")
CANDIDATES_TO_CHECK = 3

_cfg = None
_last_yandex = 0.0
yandex_calls = 0


def config():
    global _cfg
    if _cfg is None:
        _cfg = json.loads(CONFIG.read_text(encoding="utf-8"))["env"]["development"]["config"]
    return _cfg


def read_body(raw):
    try:
        return json.loads(raw)
    except ValueError:
        return raw.decode("utf-8", "replace")[:500]


def yandex_post(path, body):
    """POST to Yandex with 300 ms spacing and exponential backoff on 429. Returns (status, parsed)."""
    global _last_yandex, yandex_calls
    if yandex_calls >= YANDEX_BUDGET:
        raise RuntimeError("Yandex call budget exhausted")
    payload = json.dumps(body).encode("utf-8")
    status, parsed = 0, None
    for attempt in range(5):
        gap = YANDEX_GAP_S - (time.monotonic() - _last_yandex)
        if gap > 0:
            time.sleep(gap)
        req = urllib.request.Request(
            YANDEX_HOST + path,
            data=payload,
            method="POST",
            headers={
                "Authorization": "Bearer " + config()["WOODEV_YANDEX_PROD_TOKEN"],
                "Accept-Language": "ru",
                "Content-Type": "application/json",
            },
        )
        _last_yandex = time.monotonic()
        yandex_calls += 1
        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                status, parsed = resp.status, read_body(resp.read())
                break
        except urllib.error.HTTPError as err:
            status, parsed = err.code, read_body(err.read())
            if status != 429:
                break
            time.sleep(2 ** (attempt + 1))
    return status, parsed


def dadata_suggest(query, region=None):
    body = {
        "query": query,
        "count": 20,
        "from_bound": {"value": "city"},
        "to_bound": {"value": "settlement"},
    }
    if region:
        body["locations"] = [{"region": region}]
    req = urllib.request.Request(
        DADATA_URL,
        data=json.dumps(body).encode("utf-8"),
        method="POST",
        headers={
            "Authorization": "Token " + config()["WOODEV_TEST_DADATA_TOKEN"],
            "Accept": "application/json",
            "Content-Type": "application/json",
        },
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        suggestions = json.loads(resp.read())["suggestions"]
    time.sleep(0.2)
    return {"request": body, "suggestions": suggestions}


def cached(path, make):
    if path.exists():
        return json.loads(path.read_text(encoding="utf-8"))
    obj = make()
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(obj, ensure_ascii=False, indent=1), encoding="utf-8")
    return obj


def yandex_record(path, route, body):
    def make():
        status, parsed = yandex_post(route, body)
        return {"request": body, "status": status, "response": parsed}

    return cached(path, make)


def join(sep, *parts):
    return sep.join(p for p in parts if p)


def norm(text):
    """DaData writes 'поселок', Yandex 'посёлок': compare with ё folded to е."""
    return (text or "").replace("ё", "е").replace("Ё", "Е")


def mentions(text, word):
    """Cyrillic-aware whole-word match: the word must not be glued to a letter on either side."""
    if not word:
        return False
    # A hyphen glues words together ("Самара-Лада" is not "Самара"), so it is not a boundary.
    return re.search(r"(?<![^\W\d_-])" + re.escape(norm(word)) + r"(?![^\W\d_-])", norm(text), re.IGNORECASE) is not None


def typed_mention(text, p):
    """The name written with its settlement type in front: 'г Самара', 'деревня Пушкино'."""
    prefixes = [x for x in (p["type_short"], p["type_full"]) if x]
    if not prefixes:
        return False
    pattern = r"(?<![^\W\d_])(?:" + "|".join(re.escape(norm(x)) for x in prefixes) + r")\.?\s+" + re.escape(norm(p["name"])) + r"(?![^\W\d_])"
    return re.search(pattern, norm(text), re.IGNORECASE) is not None


def match_weight(addr, p):
    """0 if the address does not name this place; otherwise 1 for the name, +1 for the region,
    +3 for the typed name ('г Самара'), +2 for the parent city."""
    if not mentions(addr, p["name"]):
        return 0
    weight = 2 if mentions(addr, p["region_key"]) else 1
    if typed_mention(addr, p):
        weight += 3
    if p["parent"] and mentions(addr, p["parent"]):
        weight += 2
    return weight


def leads_with_place(addr, p):
    """The address's first segment that is not the region is the place itself ('Тольятти, Самарская
    область'), not a sub-object of it ('СНТ Луч, Тольятти, ...')."""
    forms = {norm(p["name"]).lower()} | {norm(f"{x} {p['name']}").lower() for x in (p["type_short"], p["type_full"]) if x}
    skip_region = p["region_key"] != p["name"]
    for seg in (s.strip() for s in addr.split(",")):
        if not seg or (skip_region and mentions(seg, p["region_key"])):
            continue
        return norm(seg).lower() in forms
    return False


def small_place_eligible(addr, p):
    """A village-level place needs its own type or parent city, plus its region, in the address
    (a bare 'Пушкино, Московская область' is the city, not the village)."""
    has_own_or_parent = typed_mention(addr, p) or bool(p["parent"] and mentions(addr, p["parent"]))
    return has_own_or_parent and mentions(addr, p["region_key"])


def haversine_km(lat1, lon1, lat2, lon2):
    r = 6371.0
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dp, dl = p2 - p1, math.radians(lon2 - lon1)
    a = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * r * math.asin(math.sqrt(a))


def variants_of(record):
    resp = record.get("response")
    if isinstance(resp, dict):
        return resp.get("variants") or []
    return []


def points_of(record):
    resp = record.get("response")
    if isinstance(resp, dict):
        return resp.get("points") or []
    return []


# ---------------------------------------------------------------- discover

def cmd_discover(query, region=None):
    result = cached(
        RAW / "discover" / (re.sub(r"[^\w]+", "_", f"{query}_{region or ''}").strip("_") + ".json"),
        lambda: dadata_suggest(query, region),
    )
    for s in result["suggestions"]:
        d = s.get("data") or {}
        print(" | ".join(str(x) for x in [
            s.get("value"), d.get("fias_id"), d.get("geo_lat"), d.get("geo_lon"),
            d.get("region_with_type"), d.get("area_with_type"),
            d.get("city_type"), d.get("city"), d.get("settlement_type"), d.get("settlement"),
        ]))


# ---------------------------------------------------------------- ground truth

def pick_ground_truth(spec, suggestions):
    for s in suggestions:
        d = s.get("data") or {}
        if spec.get("fias_id"):
            if d.get("fias_id") == spec["fias_id"]:
                return s
            continue
        name = d.get("settlement") or d.get("city")
        if (
            name == spec["name"]
            and spec["region"] in (d.get("region") or "")
            and (not spec.get("area") or spec["area"] == d.get("area"))
        ):
            return s
    return None


def place_of(gt):
    d = gt["data"]
    is_settlement = bool(d.get("settlement"))
    name = d["settlement"] if is_settlement else d["city"]
    region = d.get("region") or ""
    rtype = d.get("region_type_full")
    rwt = d.get("region_with_type") or region
    if region == name:  # city-region (Moscow, Saint Petersburg)
        region_full = region_short = None
    else:
        if rtype == "республика":
            region_full = f"Республика {region}"
        else:
            region_full = f"{region} {rtype}" if rtype else region
        region_short = rwt if rwt.endswith(".") else rwt + "."
    return {
        "name": name,
        "type_short": d.get("settlement_type") if is_settlement else d.get("city_type"),
        "type_full": d.get("settlement_type_full") if is_settlement else d.get("city_type_full"),
        "parent": d.get("city") if is_settlement else None,
        "region": region,
        "region_key": region.split()[0] if region else "",
        "region_full": region_full,
        "region_short": region_short,
        "area": d.get("area_with_type"),
        "area_key": (d.get("area") or "").split()[0] if d.get("area") else "",
        "size_class": "small place" if is_settlement else "city",
        "fias_id": d.get("fias_id"),
        "lat": float(d["geo_lat"]) if d.get("geo_lat") else None,
        "lon": float(d["geo_lon"]) if d.get("geo_lon") else None,
    }


def build_forms(p, value, unrestricted):
    n, t, rf, rs, area = p["name"], p["type_short"], p["region_full"], p["region_short"], p["area"]
    typed = f"{t} {n}" if t else n
    return [
        ("A", "country-region-city", join(", ", "Россия", rf, n)),
        ("A0", "country-city-region", join(", ", "Россия", n, rf)),
        ("B", "country-no-commas", join(" ", "Россия", n, rf)),
        ("C", "country-regionshort-city", join(", ", "Россия", rs, n)),
        ("D", "city-only", n),
        ("E", "regionshort-city", join(" ", rs, n)),
        ("F", "country-region-area-typed", join(", ", "Россия", rf, area, typed)),
        ("G", "dadata-value", value),
        ("G2", "dadata-unrestricted", unrestricted),
        ("H", "city-region", join(", ", n, rf)),
    ]


# ---------------------------------------------------------------- per-settlement measurement

def measure_settlement(spec):
    key = spec["key"]
    base = RAW / key
    gt_rec = cached(
        base / "dadata.json",
        lambda: dadata_suggest(spec["query"], spec["region"]),
    )
    gt = pick_ground_truth(spec, gt_rec["suggestions"])
    if gt is None:
        return {"key": key, "error": "ground truth not found in DaData suggestions"}
    p = place_of(gt)
    p["group"] = spec["group"]
    p["note"] = spec.get("note", "")

    forms = build_forms(p, gt.get("value"), gt.get("unrestricted_value"))
    detect = {}  # form id -> record
    by_text = {}
    for form_id, form_name, text in forms:
        if text in by_text:
            detect[form_id] = dict(by_text[text], same_text_as=by_text[text]["_form"])
            continue
        rec = yandex_record(base / "detect" / f"{form_id}.json", DETECT_PATH, {"location": text})
        rec["_form"] = form_id
        by_text[text] = rec
        detect[form_id] = rec

    # Which geo_id is the right one. Step 1: rank every geo_id whose address names the settlement
    # (weighted by address evidence). Step 2: fetch the points of the top candidates; the right
    # geo_id is the one whose points actually lie in this settlement (name share), then by weight.
    score = Counter()
    area_hit = Counter()
    lead_hit = Counter()
    for form_id, _, text in forms:
        for v in variants_of(detect[form_id]):
            addr = v.get("address", "")
            weight = match_weight(addr, p)
            if not weight:
                continue
            if p["size_class"] == "small place" and not small_place_eligible(addr, p):
                continue
            score[v["geo_id"]] += weight
            if p["area_key"] and mentions(addr, p["area_key"]):
                area_hit[v["geo_id"]] += 1
            if leads_with_place(addr, p):
                lead_hit[v["geo_id"]] += 1
    top = sorted(score, key=lambda g: (lead_hit[g] > 0, score[g], area_hit[g]), reverse=True)[:CANDIDATES_TO_CHECK]
    checked = {}
    for g in top:
        pts = {}
        for t in POINT_TYPES:
            rec = yandex_record(base / "points" / f"geo-{g}-{t}.json", POINTS_PATH, {"geo_id": g, "type": t})
            for pt in points_of(rec):
                pts[pt["id"]] = pt
        checked[g] = pts
    correct = None
    if top:
        correct = max(top, key=lambda g: (
            lead_hit[g] > 0,
            share(list(checked[g].values()), p["name"]) if checked[g] else -1,
            score[g],
            area_hit[g],
        ))
    candidates = [
        {"geo_id": g, "weight": score[g], "lead_hits": lead_hit[g], "points_n": len(checked[g]),
         "points_name_share": share(list(checked[g].values()), p["name"])}
        for g in top
    ]

    # A small place with a parent city is accepted only if the candidate's address names that parent
    # or its own points confirm the name (>= 50% of points). Otherwise it is a different place with the
    # same name, and the place is recorded as none_found with the rejected candidate kept for the report.
    rejected = None
    if correct is not None and p["parent"] and p["size_class"] == "small place":
        pts = list(checked[correct].values())
        addr_ok = any(
            mentions(v.get("address", ""), p["parent"])
            for f in forms for v in variants_of(detect[f[0]]) if v["geo_id"] == correct
        )
        confirmed = bool(pts) and share(pts, p["name"]) >= 0.5
        if not addr_ok and not confirmed:
            rejected, correct = correct, None

    form_rows = []
    for form_id, form_name, text in forms:
        rec = detect[form_id]
        variants = variants_of(rec)
        geo_list = [v["geo_id"] for v in variants]
        pos = geo_list.index(correct) if correct in geo_list else None
        has_region = mentions(text, p["region_key"])
        if not variants:
            old_pick = None
        elif has_region:
            old_pick = variants[0]["geo_id"]
        else:
            old_pick = min(geo_list)
        form_rows.append({
            "form": form_id,
            "name": form_name,
            "text": text,
            "status": rec.get("status"),
            "same_text_as": rec.get("same_text_as"),
            "has_region": has_region,
            "variants": [{"geo_id": v["geo_id"], "address": v.get("address")} for v in variants],
            "correct_position": pos,
            "found": pos is not None,
            "at_position_0": pos == 0,
            "old_plugin_pick": old_pick,
            "old_plugin_correct": correct is not None and old_pick == correct,
        })

    # Point lists by geo_id (A) and by bounding boxes around DaData coordinates (B).
    a_points = checked[correct] if correct is not None else {}

    b_sets = {}
    if p["lat"] is not None:
        for half in BBOX_HALF_SIZES:
            dlon = half / math.cos(math.radians(p["lat"]))
            pts = {}
            for t in POINT_TYPES:
                body = {
                    "latitude": {"from": round(p["lat"] - half, 6), "to": round(p["lat"] + half, 6)},
                    "longitude": {"from": round(p["lon"] - dlon, 6), "to": round(p["lon"] + dlon, 6)},
                    "type": t,
                }
                rec = yandex_record(base / "points" / f"B{half}-{t}.json", POINTS_PATH, body)
                for pt in points_of(rec):
                    pts[pt["id"]] = pt
            b_sets[str(half)] = pts

    correct_addresses = [v.get("address", "") for f in forms for v in variants_of(detect[f[0]])
                         if correct is not None and v["geo_id"] == correct]
    analysis = {
        "key": key,
        **{k: v for k, v in p.items() if k not in ("region_key", "area_key")},
        "correct_address": correct_addresses[0] if correct_addresses else None,
        "parent_in_correct_address": (mentions(correct_addresses[0], p["parent"])
                                      if correct_addresses and p["parent"] else None),
        "gt_value": gt.get("value"),
        "gt_region_with_type": gt["data"].get("region_with_type"),
        "correct_geo_id": correct,
        "correct_geo_weight": score.get(correct, 0) if correct is not None else 0,
        "candidates": candidates,
        "rejected_candidate": rejected,
        "forms": form_rows,
        "A": summarise_points(a_points, p, p["lat"], p["lon"]),
        "B": {},
    }
    a_ids = set(a_points)
    for half, pts in b_sets.items():
        b_ids = set(pts)
        inter = a_ids & b_ids
        b_minus_a = [pts[i] for i in b_ids - a_ids]
        a_minus_b = [a_points[i] for i in a_ids - b_ids]
        analysis["B"][half] = {
            "n": len(b_ids),
            "intersection_with_A": len(inter),
            "B_minus_A": len(b_minus_a),
            "B_minus_A_name_share": share(b_minus_a, p["name"]),
            "A_minus_B": len(a_minus_b),
            "A_minus_B_median_km": round(statistics.median(dist(pt, p) for pt in a_minus_b), 2) if a_minus_b else None,
            "A_minus_B_max_km": round(max(dist(pt, p) for pt in a_minus_b), 2) if a_minus_b else None,
            "A_name_share_of_intersection": share([a_points[i] for i in inter], p["name"]),
        }
    return analysis


def dist(pt, p):
    pos = pt.get("position") or {}
    return haversine_km(p["lat"], p["lon"], pos["latitude"], pos["longitude"])


def share(points, name):
    if not points:
        return None
    hits = sum(1 for pt in points if mentions((pt.get("address") or {}).get("full_address", ""), name))
    return round(hits / len(points), 3)


def summarise_points(points, p, lat, lon):
    pts = list(points.values())
    return {
        "n": len(pts),
        "by_operator": dict(Counter(str(pt.get("operator_id")) for pt in pts)),
        "by_type": dict(Counter(str(pt.get("type")) for pt in pts)),
        "yandex_branded": sum(1 for pt in pts if pt.get("is_yandex_branded")),
        "market_partner": sum(1 for pt in pts if pt.get("is_market_partner")),
        "name_share": share(pts, p["name"]),
    }


def cmd_run(keys):
    specs = json.loads((HERE / "settlements.json").read_text(encoding="utf-8"))
    for spec in specs:
        if keys and spec["key"] not in keys:
            continue
        result = measure_settlement(spec)
        out = RAW / spec["key"] / "analysis.json"
        out.write_text(json.dumps(result, ensure_ascii=False, indent=1), encoding="utf-8")
        status = result.get("error") or f"correct={result['correct_geo_id']} A={result['A']['n']}"
        print(f"{spec['key']}: {status} (yandex calls so far: {yandex_calls})", flush=True)


# ---------------------------------------------------------------- summarize

def cmd_summarize():
    analyses = []
    for path in sorted(RAW.glob("*/analysis.json")):
        analyses.append(json.loads(path.read_text(encoding="utf-8")))
    (HERE / "results.json").write_text(json.dumps(analyses, ensure_ascii=False, indent=1), encoding="utf-8")
    lines = []
    lines.append("## Per settlement\n")
    lines.append("| key | group | class | correct geo_id | forms found (position) | old-plugin pick correct (forms) |")
    lines.append("|---|---|---|---|---|---|")
    for a in analyses:
        found = ", ".join(
            f"{f['form']}:{f['correct_position'] if f['found'] else '-'}" for f in a["forms"]
        )
        old = sum(1 for f in a["forms"] if f["old_plugin_correct"])
        lines.append(f"| {a['key']} | {a['group']} | {a['size_class']} | {a['correct_geo_id']} | {found} | {old}/{len(a['forms'])} |")
    lines.append(f"\n## Form success rates (denominator = all {len(analyses)} settlements; a none_found place counts as not found)\n")
    lines.append("| form | string | found: all | at pos 0: all | found: city | found: small | pos 0: city | pos 0: small | old-plugin correct: all |")
    lines.append("|---|---|---|---|---|---|---|---|---|")
    form_ids = []
    for a in analyses:
        for f in a["forms"]:
            if f["form"] not in form_ids:
                form_ids.append(f["form"])
    names = {f["form"]: f["name"] for a in analyses for f in a["forms"]}
    for fid in form_ids:
        rows = [(a, f) for a in analyses for f in a["forms"] if f["form"] == fid]
        city = [r for r in rows if r[0]["size_class"] == "city"]
        small = [r for r in rows if r[0]["size_class"] == "small place"]
        def cnt(rs, k):
            return sum(1 for _, f in rs if f[k])
        def frac(rs, k):
            return f"{cnt(rs, k)}/{len(rs)}"
        lines.append(
            f"| {fid} | {names[fid]} | {frac(rows,'found')} | {frac(rows,'at_position_0')} | "
            f"{frac(city,'found')} | {frac(small,'found')} | {frac(city,'at_position_0')} | {frac(small,'at_position_0')} | "
            f"{frac(rows,'old_plugin_correct')} |"
        )
    lines.append("\n## Point lists: geo_id (A) vs bounding boxes (B), counts by point id\n")
    lines.append("| key | |A| | bbox | |B| | |A∩B| | B\\A | B\\A name share | A\\B | A\\B median km | A\\B max km |")
    lines.append("|---|---|---|---|---|---|---|---|---|---|")
    for a in analyses:
        for half, b in a.get("B", {}).items():
            lines.append(
                f"| {a['key']} | {a['A']['n']} | ±{half}° | {b['n']} | {b['intersection_with_A']} | {b['B_minus_A']} | "
                f"{b['B_minus_A_name_share']} | {b['A_minus_B']} | {b['A_minus_B_median_km']} | {b['A_minus_B_max_km']} |"
            )
    lines.append("\n## Networks inside A (geo_id list)\n")
    lines.append("| key | |A| | by operator_id | by type | yandex_branded | market_partner | name share |")
    lines.append("|---|---|---|---|---|---|---|")
    for a in analyses:
        A = a["A"]
        lines.append(
            f"| {a['key']} | {A['n']} | {A['by_operator']} | {A['by_type']} | {A['yandex_branded']} | "
            f"{A['market_partner']} | {A['name_share']} |"
        )
    lines.append("\n## DaData ground truth\n")
    lines.append("| key | group | DaData value | fias_id | kladr_id | geo_lat, geo_lon | region_with_type | area | type | parent city |")
    lines.append("|---|---|---|---|---|---|---|---|---|---|")
    for a in analyses:
        gt = json.loads((RAW / a["key"] / "dadata.json").read_text(encoding="utf-8"))
        s = next((x for x in gt["suggestions"] if (x.get("data") or {}).get("fias_id") == a["fias_id"]), None)
        d = (s or {}).get("data") or {}
        lines.append(
            f"| {a['key']} | {a['group']} | {a['gt_value']} | {a['fias_id']} | {d.get('kladr_id')} | "
            f"{d.get('geo_lat')}, {d.get('geo_lon')} | {a['gt_region_with_type']} | {a['area'] or '-'} | "
            f"{a['type_short'] or '-'} / {a['type_full'] or '-'} | {a['parent'] or '-'} |"
        )
    (HERE / "tables.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"wrote results.json ({len(analyses)} settlements) and tables.md")


# ---------------------------------------------------------------- rules (offline, no API calls)

def place_from_analysis(a):
    return {
        "name": a["name"], "type_short": a["type_short"], "type_full": a["type_full"],
        "parent": a["parent"], "size_class": a["size_class"],
        "region_key": (a["region"] or "").split()[0] if a["region"] else "",
    }


def rule_picks(a):
    """Candidate matching rules, each returning a geo_id or None, computed from the stored variants only."""
    p = place_from_analysis(a)
    by_form = {f["form"]: f["variants"] for f in a["forms"]}
    order = ["A0", "A", "B", "C", "E", "F", "H", "G", "G2", "D"]

    def old_rule():
        v = by_form["A0"]
        return v[0]["geo_id"] if v else None

    def city_only_min():
        v = by_form["D"]
        return min(x["geo_id"] for x in v) if v else None

    def first_leading_variant():
        for fid in order:
            for v in by_form[fid]:
                addr = v["address"]
                if not leads_with_place(addr, p) or not mentions(addr, p["region_key"]):
                    continue
                if p["size_class"] == "small place" and not small_place_eligible(addr, p):
                    continue
                return v["geo_id"]
        return None

    def first_leading_any_region():
        for fid in order:
            for v in by_form[fid]:
                addr = v["address"]
                if not leads_with_place(addr, p):
                    continue
                if p["size_class"] == "small place" and not small_place_eligible(addr, p):
                    continue
                return v["geo_id"]
        return None

    def most_leading_hits():
        hits = Counter()
        for fid in order:
            for v in by_form[fid]:
                if leads_with_place(v["address"], p) and mentions(v["address"], p["region_key"]):
                    hits[v["geo_id"]] += 1
        return hits.most_common(1)[0][0] if hits else None

    return {
        "R1 old plugin: variant[0] of A0": old_rule(),
        "R2 city-only: min geo_id of D": city_only_min(),
        "R3 first form-order variant that leads with place + region": first_leading_variant(),
        "R4 most leading-place hits over all forms": most_leading_hits(),
        "R5 first form-order variant that leads with the place (region optional)": first_leading_any_region(),
    }


def cmd_rules():
    analyses = [json.loads(p.read_text(encoding="utf-8")) for p in sorted(RAW.glob("*/analysis.json"))]
    names = list(rule_picks(analyses[0]).keys())
    n_city = sum(1 for a in analyses if a["size_class"] == "city")
    n_small = len(analyses) - n_city
    lines = [f"| rule | agrees with measured geo_id: all {len(analyses)} | big cities ({n_city}) | small places ({n_small}) | picks a geo where none_found |",
             "|---|---|---|---|---|"]
    for name in names:
        ok_all = ok_city = ok_small = wrong_on_none = 0
        for a in analyses:
            pick = rule_picks(a)[name]
            agree = pick == a["correct_geo_id"]
            ok_all += agree
            if a["size_class"] == "city":
                ok_city += agree
            else:
                ok_small += agree
            if a["correct_geo_id"] is None and pick is not None:
                wrong_on_none += 1
        lines.append(f"| {name} | {ok_all} | {ok_city} | {ok_small} | {wrong_on_none} |")
    (HERE / "rules.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    print("\n".join(lines))


def main(argv):
    if not argv:
        print(__doc__)
        return 1
    cmd, rest = argv[0], argv[1:]
    if cmd == "discover":
        cmd_discover(*rest[:2])
    elif cmd == "run":
        cmd_run(rest)
    elif cmd == "summarize":
        cmd_summarize()
    elif cmd == "rules":
        cmd_rules()
    else:
        print(__doc__)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
