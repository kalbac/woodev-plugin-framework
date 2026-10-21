# R4 — pochta.ru `postoffices.find-from-rectangle` vs the widget backend `widget.pochta.ru/api/pvz` as a point source (card kalbac/woodev-russian-post#4)

Read-only, anonymous: no account, no credentials, no cookies sent by us. All 146 requests were paced at ≥1.05 s apart; every response was HTTP 200 JSON. Boxes: **Moscow centre** lat 55.72–55.80, lng 37.55–37.70 (the same box as spec/F §2); **Moscow region core** lat 54.95–56.05, lng 36.60–38.40 (a core box, not the whole oblast; F's 3 118-point figure was for a different box).

## Answers

| # | Question | Result | Evidence |
|---|---|---|---|
| 1 | Counts by type, Moscow centre | **Widget: 123** = russian_post 79, postamat 38, additional_pvz 6 (one page, totalEntries 123, same id set on repeat). **G, no filter: 395** = ГОПС 113, ПОЧТОМАТ 119, ППВЗ 69, ТИ 47, ЦЕХ 15, УЧАСТОК 13, ПЖДП 3, УДПП 6, ГСП 2, ОПП 2, ММПО 2, АТП 2, УчастокДПП 1, СТМЦ 1. No СОПС/ПОПС in this box. | S1 `widget_C`, `G_C`; A1 `C` |
| 2 | Counts by type, Moscow region core | **Widget: 1 807** via 37 tile calls, 28 leaves, all ≤200 with len = totalEntries, no incomplete tiles = russian_post 1 022, postamat 584, additional_pvz 201. **G, no filter: 4 707** = ГОПС 956, СОПС 171, ПОПС 1, ПОЧТОМАТ 1 874, ППВЗ 905, plus ~800 non-public types (ТИ 239, УЧАСТОК 297, УДПП 52, ЦЕХ 49, …). **G with the three filters: 2 632** = ГОПС 864, СОПС 159, ПОПС 0, ПОЧТОМАТ 583, ППВЗ 247, all open. | S2 `G_R_nc3`; S1 `widget_R`; A1 `R`; A2 `R_compare` |
| 3 | Partner points (ППВЗ / additional_pvz) | Moscow centre: widget 6, G 69 (62 of them temporarily closed, so 7 open). Region: widget 201, G 905 total, 247 open. Every widget point in the centre box is in G (0 widget-only). In the region, 45 open G ППВЗ points are absent from the widget; the widget set is a subset of G for its own types. | A1 `C`; A2 `R_compare` |
| 4 | Postamats | Moscow centre: widget 38; G 119 total, 81 temporarily closed, so **38 open = exact match**. Region: widget 584; G 583 open after filters (widget has one more). | A1 `C`; A2 `R_compare` |
| 5 | `closed` / `temporaryClosed` in the list; does `NOT_CLOSED` filter work? | **G list:** `isClosed` is `false` in all 5 102 sampled rows (0 true). `isTemporaryClosed` is true for **161/395 (41 %)** in the centre and **2 029/4 707 (43 %)** in the region. **Filters, centre box:** no filter 395; `NOT_CLOSED` alone 395 (no effect, because `isClosed` is never true here, so its effect is unobservable); `NOT_TEMPORARY_CLOSED` alone **234 = 395 − 161** (removes exactly the temp-closed rows); `NOT_PRIVATE` alone 362 (removes 33, meaning not identified); all three 213 (zero temp-closed rows leak, region 2 632 with zero temp-closed). **Widget list:** no closed field at all (keys: address, addressString, deletedAt, deliveryPointIndex, geo, id, type). **Widget detail** `GET /api/pvz/{id}` has `closed` and `temporaryClosed`: 0 of 48 true (12 + 36 centre points). | S1 `G_C`; S2 `G_R_nc3`, `widget_details_36`; A1 `C`; A2 `C_detail_vs_G`; `analyse3.py` output (below) |
| 6 | Do the two backends agree on open/closed? | Centre: 0 of 123 widget points are temp-closed in G. Region: only **4 of 1 807** widget points are temp-closed in G, while **2 004 of 2 100** G public-type points absent from the widget are temp-closed. The widget therefore shows (almost only) open points. The 4 exceptions and the 96 open G points absent from the widget (ППВЗ 46, ГОПС 45, ПОЧТОМАТ 3, СОПС 2) are **not explained** (see UNVERIFIED). | `analyse3.py` output; A2 |
| 7 | Schedules | **G list** carries `workingHours`: 372/395 (centre), 4 253/4 707 (region); `holidays` in 117 / 1 174. **Widget list** has no schedule; it is only in the detail (`workTime`, a key in the detail records, e.g. «пн, открыто: 09:00 - 21:00»). Schedule content was not compared row by row. | S1 `G_C`; S2 `widget_details_36` |
| 8 | Coordinate order | **G:** named fields `latitude` / `longitude` (strings). A request with the values swapped returns `totalCount` 0, so the names are honoured. **Widget:** `[lng, lat]`; `geo.coordinates[0]` is the longitude in 123/123 rows. The `[lat,lng]` trap from F was not re-run. | S1 `G_C.swap_totalCount`; `widget_C.geo_lng_first_ok` |
| 9 | Paging completeness | **G:** centre at `limit` 100 × 4 pages and at `limit` 1 000 × 1 page give the same 395 points (set equal on postalCode / typeCode / lat / lng). Region at `limit` 1 000 × 5 pages: totalCount 4 707, 4 707 distinct, no overlap between pages. **Widget:** centre is complete in one page (123 = totalEntries). Region quad-tree: 28 leaves, each ≤200, 1 807 unique ids = sum of leaf totals (no tile-edge duplicates). Naive offset paging on the widget was not re-run (F §3 found it lossy). | S1 `G_C`; S2 `G_R_nc3`; A1 `R`; S1 `widget_R` |
| 10 | Cookies / challenge | **G** sets `session-cookie` (Max-Age 3600) on 21/21 responses. Our 21 calls sent **no Cookie header** and all returned 200 JSON, so the cookie is **not required**. No challenge, CAPTCHA or 403 was seen. **Widget** sets no cookie on 125/125 responses; all 200 JSON. | `requests.jsonl` (`set_cookie` field) |
| 11 | Rate at ≤1 req/s | 146/146 HTTP 200. No 429 or 403. Slowest G response 4.1 s (4.4 MB page). Pacing was ≥1.05 s. **Not tested** above 1 req/s, nor any burst or daily cap. | `requests.jsonl` |
| 12 | Payload cost | Widget ≈ **645 B/row** (centre: 79 KB for 123 rows). G ≈ **4.4 KB/row** (centre: 1.75 MB for 395 rows, about 22× the widget's bytes for the same viewport; region full: 20 MB in 5 calls; region open-only: 13.8 MB in 3 calls). Widget region tiling: 2.35 MB over 37 calls (this includes the wasted parent-tile pages). | `requests.jsonl` (`bytes`) |
| 13 | Requests used | **146 of ≤150.** Widget 125 = 2 centre pages + 1 id-list page + 48 details (12 + 36) + 74 tile calls (two full 37-call region walks; run 2 repeated run 1 only to save the rows, which run 1 did not keep). G 21. | `requests.jsonl` |

## Verdict

**No, find-from-rectangle is not a better source than the widget backend for release 1. Keep the widget (D2 / D16), and keep G as the named fallback and as a schedule source for later.**

- **For G:** it is the more complete *open* public set (2 632 open points in the region, of which 1 853 are ОПС + postamat + ППВЗ, against the widget's 1 807). It carries `isTemporaryClosed` and `workingHours` in the list, so one filter replaces the closed check and the schedule needs no per-id call. It needs only 3–5 calls for the region, against 37 for the widget tiling.
- **Against G, for release 1:** a city-centre viewport costs about 22× the bytes (1.75 MB against 79 KB). That breaks the spec's slim-record cache budget (§5.4), and a 1 000-row page takes about 4 s. It also sets a session cookie on every response, which is the precursor of bot protection that F flagged. The widget already returns almost only open points (4 temp-closed out of 1 807), so G's closed flag gains little for the point list.
- **Both are unofficial and unlicensed for third-party use**, so neither removes the D2 / D16 risk.
- The widget stays the source behind `Point_Source`. The schedule question is a separate decision: G's list rows would let the per-id `GET /api/pvz/{id}` be dropped for display, at the cost of heavier fetches.

## UNVERIFIED

- Why 96 open G public-type points (ППВЗ 46, ГОПС 45, ПОЧТОМАТ 3, СОПС 2) are absent from the widget in the region box: a widget-side filter, or a box-edge effect of the tiling.
- The 4 widget points that G flags as temporarily closed: their widget `closed` / `temporaryClosed` values were not fetched.
- What `NOT_PRIVATE` removes (33 centre rows; only 3 of the 21 open rows it drops have `eas4` false).
- `NOT_CLOSED`'s own effect: `isClosed` was never true in 5 102 sampled rows.
- Widget closed incidence across the region: only the 48 centre points were checked by detail.
- Whether G and the widget agree on schedule content (not compared).
- Rate above 1 req/s, daily caps, and terms of use for either backend. Behaviour over time: one day only.
- The "Moscow region" box is a core box, so the counts are not the full-oblast counts.

## Not checked

- Naive offset paging on the widget (F already measured it lossy).
- The widget's `[lat,lng]` trap (F measured it; not re-run).
- Any authenticated path. Nothing from the test-account credentials was read or used.

## Files

Base: (kept outside the repo)

- `requests.jsonl`: all 146 calls (tag, URL, body, status, bytes, seconds, Set-Cookie names only, with values masked).
- `raw/*.json.gz`: saved response bodies (public point data, no secrets).
- S1 `summary.json`, S2 `summary2.json`, A1 `analysis.json`, A2 `analysis2.json`.
- Scripts: `measure.py` (part 1), `measure2.py` (part 2), `measure3.py` (filter isolation), `analyse.py`, `analyse2.py`, `analyse3.py` (the `analyse3.py` output is the source of rows 5–6).
- Logging: `Set-Cookie` values are masked in every log. No `Authorization` or other header was sent.

## Related

- [README](README.md) — the consolidated fact base
- [Russian Post v2 design spec](../../specs/2026-10-21-russian-post-v2-plugin-design.md)
