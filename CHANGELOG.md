# Changelog

All notable changes to Woodev Plugin Framework are documented here.
## [2.0.22] — 2026-11-01
### Bug Fixes
- **location**: No checkout reload while typing the address

- **location**: Restore the address row on checkout submit


### Documentation
- **s208**: The morning rig-look addendum and 2.0.21



## [2.0.21] — 2026-11-01
### Bug Fixes
- **location**: Offer a DaData house over a CDEK city outside Moscow

- **location**: Keep DaData houses inside a foreign parent's locality

- **location**: Ask DaData by settlement only after an empty city answer


### Documentation
- **s208**: Session record, state and the handoff

- **s208**: The Actions pool reset on 01.11

- **s208**: Keep the handoff under its size gate



## [2.0.20] — 2026-11-01
### Bug Fixes
- **location**: Keep the DaData house address over a CDEK locality

- **location**: Match a nested settlement and refuse another region (#1534 review)

- **location**: Treat a spaced dash as a region-name separator (#1534 review 2)


### Documentation
- **s207**: Session record, state, gotchas and the handoff

- **s207**: Complete the rig-fix section and the handoff

- **s207**: Keep the handoff under its size gate



## [2.0.19] — 2026-10-31
### Bug Fixes
- **shipping**: Coupon in the price cache key, zero-total cost arrays, order-amount limits

- **shipping**: Report an order-amount refusal as a hidden reason


### Documentation
- **s206**: Session record, state, gotchas and the overnight handoff

- **spec**: Ozon 2.0.0 scope after the 31.10.2026 rig review


### Features
- **shipping**: Price settings as FEATURE_* declarations



## [2.0.18] — 2026-10-30
### Bug Fixes
- **shipping**: Protect the customer location record in every session-saving request



## [2.0.17] — 2026-10-30
### Bug Fixes
- **location**: DaData 403 notice no longer claims funds ran out on a 0 ₽ balance (#1505) (#1514)

- **location**: A region the customer did not pick no longer scopes the city search (#1511) (#1515)


### Documentation
- **s202**: T-Bank 2.0.0 and framework 2.0.16 released; handoff (#1510)

- **s203**: CDEK 2.3.0.11, Ozon brainstorm and measurements, overnight handoff (#1513)

- **ozon**: Ozon Delivery v2 plugin design spec (#1512)

- **ozon**: Spec corrections from live measurements (s204) (#1516)

- **s204**: Overnight Ozon build W0–W6, framework #1505 #1511; handoff (#1517)

- **s205**: Session record, handoff, three gotchas, local-ci token flag removed from docs

- **s205**: Make the woodev re-sync command executable and pin-driven

- **s205**: Record that the Ozon 1.0.3 ZIP URL is unreachable from every machine

- **s205**: Ozon 1.0.3 folder name confirmed from the operator's ZIP


### Style
- Enforce WordPress code layout sniffs and reformat



## [2.0.16] — 2026-10-29
### Bug Fixes
- **licensing**: Claim command nonces with INSERT IGNORE (#1485) (#1498)

- **payment-gateway**: Preserve pending hosted orders and stable references (#1480) (#1499)

- **payment-gateway**: Replace deprecated wc_enqueue_js (#1501) (#1506)

- Dormant notice links Woodev → Плагины (#1503); hosted body log redaction (#1504) (#1507)


### Documentation
- **s200**: Ticket wave — framework 2.0.15, CDEK 2.3.0.10, Yandex 2.0.11, Russian Post 2.0.10 to EDD; session docs, handoff (#1495)

- **loop**: The coordinator closes plugin issues once EDD serves the fix (#1496)

- **s200**: Operator decisions (#149 stays open, #1480/#1485 approved), closed plugin cards, Support AI hand-over; handoff → T-Bank (#1497)

- **s201**: Autonomous morning — #1485, #1480 merged; T-Bank W6, W8a, W8c and first rig acceptance; handoff (#1502)



## [2.0.15] — 2026-10-27
### Bug Fixes
- **bootstrap**: Honest notice when a v1 and a v2 Woodev plugin cannot run together (#1389) (#1473)

- **location**: Per-key session merge on WooCommerce < 11.4 + placement guard by the posted address (#1381) (#1481)

- **shipping**: Include pickup selection in rate cache key (#1492)

- Retry city selection and defer wizard steps (#1491)

- **modal**: Resist theme button and overflow styles (#1493)

- **i18n**: Load bundled plugin catalogue as fallback (#1494)


### Documentation
- **s197**: Ticket wave released (2.0.13, 2.0.14, Yandex 2.0.10, CDEK 2.3.0.9, Post 2.0.9); handoff (#1472)

- **s198**: T-Bank v2 research and operator decisions, #1381 race measurement, handoff for overnight s199 (#1477)

- **research**: #1428 drafts verified against released plugins (#1479)

- **spec**: T-Bank «Рассрочки и кредиты» 2.0.0 plugin design (#1483)

- **s199**: T-Bank waves W0–W5 + W7 merged, #1381 merged, spec, session docs, handoff (#1486)


### Build
- **deps**: Bump taiki-e/install-action from 2.87.29 to 2.87.31 (#1488)



## [2.0.14] — 2026-10-25
### Bug Fixes
- **pickup**: Type filter without the map; add a selection seam to location providers (#1445) (#1468)

- **checkout**: Keep WooCommerce's native field label when the descriptor declares none (#1467) (#1470)

- **shipping**: Export from custom order statuses through one filterable list (#1458) (#1469)


### Documentation
- **skills**: A v1 rewrite must declare every dropped hook and publish an integrator article (#1465)

- **website**: Explain method ID changes and map=false in v1 migration (#1466)



## [2.0.13] — 2026-10-25
### Bug Fixes
- **location**: Accept DaData fias_level 3 rows that are a city and their own okrug (#1459) (#1461)

- **licensing**: Keep the stored licence when the server answer carries no licence token (#1460) (#1463)


### Documentation
- **s196**: 2.0.12 + plugin releases in EDD, #1428 research, Support AI loop rules; handoff (#1456)

- **s196**: Handoff starts with #1428; Support AI s47 findings (#1457)


### Tests
- **account**: Skip setAccessible on PHP 8.1+ so PHP 8.5 reports no risky test (#1462)



## [2.0.12] — 2026-10-24
### Bug Fixes
- **pickup**: Open the list when the bulk map fails to load (#1427) (#1446)

- **pickup**: A free-text locality no longer gets another locality's points (#1433) (#1448)

- **account**: «Обновить» with overwrite for a v1 plugin quarantined next to v2 (#1438) (#1450)

- **pickup**: Send the typed city, not a stale record key, when they disagree (#1449) (#1452)

- **account**: Keep the real installer error; show its cause only in debug mode (#1451) (#1453)


### Documentation
- **s195**: Support AI triage, three hotfix waves (2.0.9–2.0.11), plugin repos on main; handoff (#1443)

- **s195**: Хендофф — s196 овернайт, автономно (#1444)


### Tests
- **location**: LocationReadAuthTest drives real routes with a seeded cart and within scope (#1436) (#1447)

- **account**: Make the reflection setup work on PHP < 8.1 (#1451) (#1454)



## [2.0.11] — 2026-10-24
### Bug Fixes
- **pickup**: A failed city geocode centres on the record's coordinates or says so, never a silent Moscow (#1432) (#1440)

- **frontend**: REST query strings join with & on plain permalinks (#1439) (#1441)



## [2.0.10] — 2026-10-23
### Bug Fixes
- **location**: Survive a stale page nonce on a cached checkout (#1426) (#1435)



## [2.0.9] — 2026-10-23
### Bug Fixes
- **location**: Fall back to a configured provider; a provider's key is required only while it is active (#1429)


### Documentation
- **spec**: Russian Post U24 decided — option Б (#44) (#1413)

- **s193**: Framework 2.0.8 and Russian Post 2.0.0 released; handoff (#1414)

- **skill**: Shipping methods must offer the full merchant price-option set (#1416)

- **s193**: Evening — Russian Post #54 merged, 2.0.2 ready, publications drafted; handoff (#1418)

- **s193**: Night — Russian Post 2.0.2 and 2.0.3 released, publications live; handoff (#1419)

- **s194**: Russian Post 2.0.3 and 2.0.4 on EDD, galleries, #1420; handoff (#1422)

- **s194**: Retire staged rollouts; hand s195 the Support AI ticket triage (#1423)


### Features
- **location**: Warn a plugin that half-declares the location layer (#1420) (#1421)



## [2.0.8] — 2026-10-22
### Bug Fixes
- **checkout**: Refresh the classic checkout when the payment method changes under a pickup method (#1390)

- **rest-api**: Mask secret settings in the plugin settings REST controller (#1406) (#1408)


### Documentation
- **release**: Changelog in Keep a Changelog form with repo#PR references, stripped for EDD (#1387)

- **s190**: First v2 EDD publication — framework 2.0.7, CDEK 2.3.0.4, Yandex 2.0.5; handoff (#1388)

- **research**: Russian Post v2 fact base and brainstorm agenda (s191) (#1391)

- **research**: Russian Post passport import measured in PHP (s191, task I) (#1392)

- **research**: Russian Post v2 brainstorm decisions and international measurement (s191) (#1393)

- **research**: Russian Post new cabinet API docs — REST tracking by token (s191, task L) (#1394)

- **specs**: Russian Post v2 plugin design (s191) (#1395)

- **s191**: Session notes and handoff — Russian Post v2 underway (#1398)

- **s191**: Point the pipeline at the plugin repo's docs/orchestration (#1403)

- **s191**: Gotchas and plugin-author skills (#1404)

- **s192**: Session notes, handoff, Russian Post spec amendment, Mockery alias gotcha (#1407)

- **s193**: Session notes, handoff, Russian Post spec amendment, stubbed-WC boot-fatal gotcha (#1411)


### Features
- **pickup**: Tell point sources a confirmation from a card view (#1405) (#1409)


### Tests
- **error-reporting**: Skip the two OOM process tests on PHP builds that die without shutdown (#1399) (#1401)



## [2.0.7] — 2026-10-20
### Bug Fixes
- **shipping**: The order wizard re-prices the delivery after the payment step (#1384)


### Documentation
- **shipping**: Insurance migration notes from #1163 (#1382)

- **s189**: Overnight — framework 2.0.6, CDEK 2.3.0.3, Yandex 2.0.4 released; handoff (#1383)



## [2.0.6] — 2026-10-20
### Bug Fixes
- **api**: Decode JSON responses lazily (#1375)

- **shipping**: Cart calculator writes the delivery address to billing under billing_only (#1368) (#1374)

- **shipping**: Keep pickup list available when map fails (#1373)

- **location**: Keep the customer's locality per site on a multisite network (#1376)

- **checkout-blocks**: Serialize Store API cart mutations so overlapping requests stop undoing the rate and the pickup point (#1357) (#1378)

- **shipping**: Show the map error under viewport instead of an endless spinner (#1379)


### Documentation
- **s188**: Hotfix day — framework 2.0.4/2.0.5, CDEK 2.3.0.1/2.3.0.2, Yandex 2.0.1–2.0.3; overnight pool handoff (#1371)


### Build
- **deps**: Bump taiki-e/install-action from 2.87.27 to 2.87.29 (#1372)



## [2.0.5] — 2026-10-19
### Bug Fixes
- **pickup**: Fill the address line with the point's street address, not the full one (#1360) (#1365)

- **release**: Woodev Framework 2.0.5 — framework translations load early with bundled fallback (#1361) (#1366)



## [2.0.4] — 2026-10-19
### Bug Fixes
- **release**: Woodev Framework 2.0.4 — HPOS compatibility declaration, order meta slashes (#1364)


### Documentation
- **s188**: Releases published — framework v2.0.3, CDEK v2.3.0.0, Yandex v2.0.0 (#1359)



## [2.0.3] — 2026-10-19
### Bug Fixes
- Real bugs and stale docs from #1316 (#1329)

- Keep order-editor coupon discounts on save; cool down the plugins catalogue after a failure (#1330)

- **settings-page**: Keep an edit typed while Save is pending (#1326) (#1333)

- Three audit fixes — wizard location, variation pages, license key lock (#1334 #1335 #1336) (#1337)

- **checkout-blocks**: Restore the remembered pickup point (#1263) and carry out the swallowed Place Order press (#1279) (#1332)

- **shipping-orders**: Offer a bulk action only when a row on the page offers it (#1345) (#1344)

- **settings**: Accept value/label pairs for 0..n-keyed string options (#1353)


### Documentation
- **s182**: Session save — 2.0.2 released, handoff for the repo-order session (#1308)

- S183 documentation audit (#1309)

- **site**: Replace MkDocs with an Astro Starlight site (#1313) ⚠ BREAKING

- **site**: Getting started — five pages (#1315)

- **site**: Core — ten pages (#1317)

- **site**: Settings & admin — eight pages (#1318)

- **site**: Shipping — seven pages (#1319)

- Contributing guide for 2.0.x and a Contributing page (#1320)

- **skills**: Correct override names and dependency traps found while writing the public docs (#1321)

- **site**: Payments (legacy) and the hooks & filters reference (#1322)

- **s183**: Session save — public docs site published, repo cleanup, handoff (#1323)

- **s183**: Handoff carries #1324 (#1325)

- **s184**: Session record, gotchas, skills and handoff (#1338)

- **s184**: Spec §5.4 — COD delivery_cost decision (#109); handoff after the morning merges (#1340)

- **s184**: Handoff — leaked s183 commit needs no GitHub purge (operator, public partner key only) (#1341)

- **s184**: Afternoon — publication plan (#1342), Homelab file-transfer gotcha, autonomous handoff (#1343)

- **s185**: Publication plan stage 1 (#1342), merged cards, handoff (#1346)

- **website**: Generate llms.txt files for AI agents (#1350)

- **s185**: Evening — six shipping articles published (#1342), Yoast/NAS/MCP-permission lessons, overnight handoff (#1351)

- **s185**: Handoff — review all FAQs (docs + product cards) from Support AI cases (#1352)

- **s186**: Overnight — #1342 drafts, four plugin PRs, handoff (#1354)

- **s187**: #1342 publications live, FAQ groups, release handoff (#1355)

- **s188**: Releases prepared up to the button — framework 2.0.3, CDEK 2.3, Yandex 2.0; release gotchas, plugin release skill (#1358)


### Tests
- **contract**: Pin v1 Yandex/CDEK literals as committed fixtures (#1311)

- **e2e**: Classic-checkout specs set their own shipping-destination mode and run under billing_only too (#1306) (#1331)

- **e2e**: Coexistence/06 re-pick closes the restored point's card and picks another point (#1339)



## [2.0.2] — 2026-10-15
### #839
- Закон роста джойнов измерен и закреплён тестом; готчи и документы s138 (#920)


### #919
- Гипотеза отвергнута замером — ошибка квантора, а не цена; карточка переформулируется (#927)


### Bug Fixes
- **license-page**: Frame the page and style the quick-links section (OB-2) (#54)

- **plugin-updater**: OB-3 safe subset — F11 tested-guard, F12 types/visibility, F13 esc_attr (#57)

- **plugin-updater**: OB-3 Step 2 — catch(Throwable) + F7 wiring-failure logging (#58)

- **plugin-updater**: OB-3 F5 — remove unused string action param from api_request() (#59)

- **updater**: OB-3 Step 4 — contract-touching F8/F9/F10 (#62)

- **updater**: OB-3 F1/F3 — normalize sections shape + shared-cache fields (#63)

- **license-page**: Align key form-group heights/border, center compact-card icons, add load skeleton (#65)

- **licensing**: Activation/deactivation bugs from live testing (#66)

- **license-page**: Offer «Изменить ключ» on a revoked/disabled license (#67)

- **license-page**: Never strand the user on an unrecognised license status (#68)

- **extensions**: Extend catalog fetch timeout to 20s (#76)

- **setup**: Address independent-review findings (multi-plugin REST key, error handling) (#81)

- **setup**: Include WP_Error in complete() @return (PHPStan L3) (#82)

- **setup**: Harden settings-api + wizard from Codex critic review (#85)

- **autodev**: Harden loop vs Loop-Engineering review (10 fixes + 2 critic rounds) (#86)

- **admin-menu**: Register top-level Woodev menu under runtime class-map autoloader (#88)

- **ui-kit**: Operator review polish batch (10 fixes) (#91)

- **settings**: Operator review — spacing, toggle alignment, all field types (#92)

- **pickup**: Filter payment_methods/photos instead of strval-coercing them (#186)

- **pickup**: Filter the rig fixture's bulk source by locality (#162) (#189)

- **pickup**: Size the sidebar glyph to the address text, not to an icon number (#204)

- **pickup**: Number co-located tab labels instead of falling back to the full name, and render payment methods as chips (#202)

- **pickup**: Per-type short tab name + strip an upstream stringified boolean (#207, #210) (#211)

- **pickup**: Host the accent variables on the stage, not the panels (#212) (#213)

- **pickup**: Wire the viewport strategy's lazy detail fetch (#219) (#221)

- **pickup**: Filter the sidebar on pan and indicate every background load (#222, #224) (#227)

- **pickup**: Keep the open card's verdict fresh and lock its CTA while it is unknown (#223, #225) (#228)

- **fixture**: Stop caching Pochta listings; cache single points instead (#231)

- **pickup**: Keep an open card's details across a pan instead of re-fetching them (#235)

- **pickup**: Report a tab switch as a card move, and take Pochta's COD rule from the domain (#236)

- **fixture**: Show payment methods as Russian labels, not raw codes (#220) (#240)

- **pickup**: Draw the union of every viewport listing (#234) (#239)

- **pickup**: Drop the sidebar loading overlay on mobile, keep the progress strip (#234 follow-up) (#241)

- **shipping**: Wire #232 cart-change verdict invalidation to a real signal (#238) (#249)

- **assets**: Version woodev-modal.js and woodev-dadata-suggestions.js via get_assets_version() (#253)

- **shipping**: Restore normalizePoint() field parity with Pickup_Point::from_array() (#254)

- **pickup**: Attach the embedded provider's message listener before the frame (#261)

- **pickup**: Move muted label treatment from unchecked to disabled type filter (#255)

- **pickup**: Derive short_address at the boundary, show it first in search results (#266)

- **checkout**: Stop a no-op parent change from destroying the child's value (#272) (#275)

- **pickup**: Drop an applied selection when the locality changes (#271) (#276)

- **pickup**: Под ownsChrome покупателю теперь сообщают об отказе (#265) (#277)

- **api**: Mask every known credential header in the request log (#288) (#301)

- **api**: Mask a credential header by name, not by a truthy value (#288) (#305)

- **pickup**: Don't promise a retry of an action the carrier controls (#297) (#303)

- **api**: Mask secret headers on the response half of the log broadcast (#315)

- **checkout**: Human wording in pickup validation messages, and one message instead of two (#316)

- **pickup**: Draw the checkout trigger in ONE place, never two (#326)

- **location,checkout,pickup**: A guest's locality actually persists, and a button is not a field (#324, #327) (#335)

- **location,pickup**: The layer remembers the customer's CHAIN, not one record (#334, #330) (#338)

- **pickup**: The map addresses itself by the SETTLEMENT, and falls back where the key may not (#336) (#340)

- **pickup**: Announce the point address write so the cascade keeps the record (#344)

- **location**: Блокировать поле адреса, пока не выбран НП (#337) (#345)

- **pickup**: Restore the point remembered for a locality on returning to it (#349) (#351)

- **location**: Keep a foreign-provider address out of the customer chain (#352) (#354)

- **location**: Adopt or report a settlement typed without picking (#350) (#355)

- **location**: Read a stale customer record as absent (#346, #333) (#359)

- **payment-gateway**: Экранировать поля токена в выводе (stored-XSS) (#420)

- **payment-gateway**: Закрыть IDOR в AJAX редактора платёжных токенов (#426)

- **utilities**: Три дефекта фоновых джобов — порча статусов, дыра в AJAX, деление на ноль (#428)

- **settings**: Boolean + is_multi больше не фаталит, и запрещена пара с toggle (#430)

- **payment-gateway**: Четыре дефекта шлюза — fatal на чекауте и две межзаказные утечки в одном запросе (#431)

- **licensing**: Ключ лицензии больше не попадает в debug-лог — редактирование fail-safe по умолчанию (#433)

- **payment-gateway**: Escape token nickname and expiry in the checkout payment form (stored-XSS) (#442)

- **settings**: Живой набор значений НП (#412) и блокировка Сохранить при чужом провайдере (#415) (#422)

- **api**: Redact response body before logging, kill raw-body fallback (#445)

- **settings**: Flat-array options validate by value, not client-side index (#446)

- **payment-gateway**: Пять строк в несуществующем текст-домене не переводились (#443)

- **shipping-method**: Stop the address field from inheriting the settlement axis mode (#454)

- **shipping**: Ajax-select2 submits the location value, not the raw provider key (#455) (#461)

- **shipping-method**: Backwards-fill a select-mode ancestor field by a real option (#462)

- **location-cascade**: Survive WooCommerce's country_to_state_changed rebuild (#464)

- **location-cascade**: Invalidate select2's stale render cache on reuse and clear (#465) (#467)

- **location-select-modes**: Carry data-input-classes and placeholder onto the generated select (#468)

- **checkout-field-classic**: Stop the §8 takeover from reverting location-owned fields (#471)

- **location-related-list**: Derive the settlement select's value like ajax-select2 does (#470)

- **location-cascade**: Attach cascade per woocommerce_ship_to_destination (#472)

- **checkout-config**: Keep the region owner when the related-list states are our own (#484) (#485)

- **location-settings**: The settlement axis has exactly two modes, never related-list (#486) (#487)

- **checkout-fields**: Keep data-input-classes on a field WC would render as state (#469) (#489)

- **location-select**: Really cancel a superseded ajax-select2 search (#449) (#492)

- **location-cascade**: Carry the chain's records across a column swap (#490) (#495)

- **location**: What the customer sees when a popular settlement's key has died (#488 slice 3, D7 client half) (#501)

- **location**: Escape derived-marker native ids; classmap settings-page (#507)

- **location**: Fire #350's onAbandon in ajax-select2 and related-list too (#522)

- **location**: An implicit default locality must not unlock the address field (#509)

- **location**: Source select2's UI strings from wc_country_select_params (#526) (#533)

- **docs**: Keep the handoff drop-check alive now that the file is untracked

- **location**: The checkout settlement field — visible default, immediate feedback, honest search (#536, #538, #539, #540, #541) (#543)

- **location**: Pin the parse/compose round-trip gap for derived keys, name the right entry point in split_key() exceptions (#548)

- **location**: Merge the provider's answer with the locally-narrowed popular list (#539 part 3) (#547)

- **location**: Derive the region ancestor for a default-filled settlement (#552)

- **docs**: Normalise @since to the planned release 2.0.2 (#555)

- **tests**: Name the reset rule's exception, reset the tools registry (#558)

- **location**: Cancel the abandon flush on open, and schedule it only when something can fire (#562)

- **settings-api**: Refuse a numerically-keyed STRING enum at registration, not at save (#565)

- **i18n**: Declare the text domain on all 26 bare gettext calls, and make the scanner say so (#569)

- **checkout**: Guard the §8 updated_checkout loop by ownership, like the takeover loop already is (#571)

- **shipping**: Revive the dead environment set, and stop announcing support on a gateway hook (#575)

- **licensing**: Stop the framework firing its own deprecation notice on the licence AJAX path (#576)

- **handlers**: A guest cannot forge log lines through the script-event endpoint any more (#578)

- **settings**: Match the legacy redirect on query ARGUMENTS, not on a substring of the URI (#579)

- **payment-gateway**: Saving a profile without a token editor no longer deletes the tokens (#580)

- Four follow-ups from the critic sweep over tonight's merged batch (#582)

- **api**: Redact the HTTP reason phrase where it enters, not where it is logged (#584)

- **setup-wizard**: Send per-field errors under the key the client has always read (#581)

- **checkout**: A confirmed pickup point answers the locality prompt (#586)

- **location**: Resolve_geoip_default() refuses a foreign-country geoip hit (#591)

- **logging**: Redact secrets from foreign exception text at three log boundaries (#592)

- **script-handler**: Rate limit the nopriv script-log endpoint (#596)

- **composer**: Map woodev/account and woodev/competitor, and gate the dev autoloader (#607)

- **logging**: Route every caught-exception log sink through redact_secret_log_text (#611)

- **payment-gateway**: Redact the ONE customer-facing exception response (#610) (#616)

- **payment-gateway**: Guard six filter returns on paths a CUSTOMER sees (#613) (#619)

- **tests**: Make the unit suite order-independent, and gate it (#606) (#624)

- **account**: Route five URL filters through one validated helper (#613) (#625)

- **api**: Guard filter returns in the API base, Settings API and job runner (#613) (#626)

- **shipping**: Guard ten filter returns, two of them on the customer's checkout (#613) (#628)

- **location**: Guard the two ?Location_Provider filter returns (#613) (#630)

- **payment-gateway**: Guard the admin and held-order remainder (#613) (#631)

- **tests**: The PHP-matrix check now admits what it could not read (#609) (#636)

- **settings-page**: WCAG AA contrast and a stable selector width (#514 m4, m5) (#640)

- **settings-page**: The tool selector is 320px, not the field-grid 425px (#514 m5) (#641)

- **checkout**: Woodev_location_i18n degrades to the defaults, not to a cast (#627) (#648)

- **location**: The stranded fixed default asks for the record's own country (#353) (#649)

- **location**: Resolve the /suggest provider AFTER the country is known (#655)

- **admin**: Point the delayed-notice reveal at the placeholder that was actually rendered (#662) (#663)

- **location**: Resolve empty country in /location/list, mirroring /suggest (#321) (#668)

- **utilities**: Validate background job batches (#671)

- **api**: Redact cached response credentials (#672)

- **api**: Follow opt-in challenge redirects (#674)

- **shipping**: Fold merged popular-settlement rows on a key collision (#678)

- **test**: Scope bare jest run to tests/js, exclude nested agent worktrees (#681)

- **api**: Scope the challenge-redirect cookie jar by request origin (#685)

- **api**: Challenge-redirect cookie semantics and Location resolver (#688)

- **location**: Shorten four settings labels on the «Доставка» tab (#374) (#699)

- **settings-page**: Scope hasChanges and Save to the open section (#515) (#698)

- **settings-page**: Boolean rows join the field grid; shorten three «Карта» labels (#703)

- **phpcs**: Arm the warning level and enforce the short-array convention (#705)

- **checkout**: Stop enforcing required on a takeover field WC never renders (#717)

- **location**: Derive a region for a settlement that is its own region (#719)

- **shipping**: Derive pickup-method declarations from is_pickup_shipping() (#720)

- **checkout**: Stop the A2 gate blocking on a field nobody rendered (#722)

- **pickup**: Keep focus and scroll position across a card redraw (#732)

- **checkout**: Drop takeover_condition on a location-source field (#739)

- **shipping**: Reconcile pickup declarations per handler, not against the fleet (#740)

- **payment-gateway**: Payment-form enqueue + dead CSS cleanup (#738, #111) (#742)

- **shipping**: Set the pickup list's distance anchor once at open, not only after a search (#743)

- **shipping**: Не сохранять значение ПВЗ-поля, чей метод не выбран (#745) (#748)

- **shipping**: Report a Pickup_Handler built without $plugin, loudly (#746) (#750)

- **shipping**: Key the pickup scope reconciliation gate by method id (#753)

- **bootstrap**: Уведомлять мерчанта, когда v2-плагин не запущен из-за устаревшего плагина (#104) — НУЖНА ПРОВЕРКА ФОРМУЛИРОВОК (#751)

- **framework**: Enforce the admin-notice-handler construction contract (#758) (#760)

- **framework**: Enforce license- and lifecycle-handler construction contracts (#759) (#761)

- **docs**: The handoff was pushed over its gate — trimmed back under 16 KB

- **shipping**: Emit rate meta flat so a migrating plugin keeps its own key (#765)

- **shipping**: Apply the merchant's Title and Description, and stop the rate DTO throwing at checkout (#769)

- **shipping**: Includes() остаётся авторитетным — разрыв в 31 файл закрыт, оба направления под сторожем (#138) (#774)

- **i18n**: Перевести 114 админских строк и завести гейт, чтобы это не накапливалось молча (#771) (#776)

- **licensing**: Refuse a corrupted array license option instead of throwing (#788)

- **utilities**: Guard null WC session, forward URL to ssl-verify filter (#789)

- **setup-wizard**: Give each step its own component identity (#790)

- **payment-gateway**: Bound capture amount to (0, get_order_capture_maximum()] (#787)

- **checkout**: A region lookup that outlives detach() must not overwrite a newer pick (#792)

- **pickup**: Bridge the WC context inside the REST callbacks (#174), and guard the optional string fields (#182) (#796)

- **pickup**: Reject a non-scalar type.code/label instead of casting it to "Array" (#805)

- **tooling**: Refresh Composer's classmap from git hooks; offload AGENTS.md to clear its gate (#806)

- **pickup**: Guard type.code/label with isScalar in the embedded map provider too (#807)

- **pickup**: Make "required" mean non-blank once cast, on both halves of the boundary (#808)

- **i18n**: Витринные msgid'ы на английский, каталог пересобран, гейт находит свой wp-cli (#567, #775, #800) (#810)

- **shipping**: Stop discarding a subclass's own supports[], and make box packing reachable (#812)

- **shipping**: Make add_support() reach the settings screen after construction (#816)

- **shipping**: The orders page's default date range must carry a compare

- **shipping**: Window.wc.currency is the module, not the factory

- **shipping**: The filter row on one line, advanced filters behind the picker

- **shipping**: Страница «Заказы доставки» — фильтрация наконец фильтрует (#837, #835) (#840)

- **shipping**: Offer only the delivery statuses the shop can produce (#837) (#847)

- **shipping**: Блок «Статус данных» по форме WooCommerce + мёртвый селект правила убран (#828, #836) (#848)

- **shipping**: A navigation that changes no filter no longer refetches the table (#850) (#851)

- **shipping**: Flush new-order-counts cache on export (#853) (#858)

- **fixtures**: Seeded orders carry a customer, address and real total (#861) (#864)

- **shipping**: Сумма в колонке «Оплата» не должна переноситься (#866)

- **machine**: Setup-macos.sh работает на ноутбуке — пять дефектов и замеры (#882) (#887)

- **shipping**: Report add_support() called before the id is set (#815) (#903)

- **resolver**: Notice for invalid loader definitions + redirect-safe self-heal notice (#942, #943) (#975)

- **shipping**: Packer store units, is_configured() default, pickup point no longer overwrites the city (#950, #953, #961) (#977)

- **fixtures**: Raising SEED_VERSION backfills the seeder's own orders, empty fields only (#868) (#979)

- **shipping**: Block checkout gets the framework's rates and refuses a pickup order without a point (#949, #966) (#983)

- **shipping**: «Edit» no longer needs a shipment handler (#988) (#989)

- **shipping**: No region-code error under a region the address policy hides (#991) (#997)

- **shipping**: An order another manager is editing refuses carrier actions and loses its checkbox (#1000) (#1002)

- **shipping**: The order wizard never requires the postcode (#999) (#1001)

- **api**: Cap the size of a cached API response so it never bloats wp_options (#952) (#1003)

- **api**: Skip the response cache write when the request's should_cache() is false (#1004) (#1006)

- **orders-page**: Read the advanced-filters «All / Any» select in the dative (#941) (#987)

- **shipping**: Carrier metabox no longer nests a <form> inside the WC order form (#1012) (#1013)

- **shipping**: Migrate v1 auto-export settings only after the v1 option is read (#1015) (#1019)

- **shipping**: Reference calls inside a checkout request get the checkout timeout budget (#1017) (#1023)

- **shipping**: Customer-facing location lookups get the checkout timeout budget (#1025) (#1035)

- **shipping**: Carrier metabox action buttons stack one per line again (#1018) (#1034)

- **settings**: Guard composite carrier tab against section-id clashes and bad owner maps (#1033) (#1038)

- **shipping**: A carrier cancellation reads «Отменено» in the delivery status (#1037) (#1039)

- **shipping**: The «new» scope and the menu badge count only exportable orders (#1024) (#1040)

- **location**: One shared Location_Service per plugin for a render's readers; forget clears the unpersisted default (#1036) (#1041)

- **setup-wizard**: Forward stepper navigation only through visited steps (#110 item 2) (#1044)

- **i18n**: Route the password toggle aria-label through gettext and deliver JS translations (#1032) (#1049)

- **setup**: Catch Throwable around the wizard's on_save and return a generic error (#1048) (#1058)

- **shipping**: Bound the depth of the rate cache's plain-data check (#1062) (#1067)

- **location**: A region the customer cannot change no longer scopes search or popular list (#1069) (#1070)

- **location**: The store's default locality no longer overrides a city already saved for the customer (#1075) (#1077)

- **error-reporting**: Pickup event pluginId comes from the owning plugin (#1093) (#1095)

- **checkout-blocks**: Keep an applied locality choice on remount during rate recalculation (#1096) (#1103)

- **shipping**: Fail loud when a pickup handler has no Selection_Scope (#1100) (#1104)

- **shipping**: SP-11 C-3 — address, rate and payment changes without a stale pickup point (#1090) (#1105)

- **checkout-blocks**: A queued locality select abandoned on unmount no longer erases the applied one (#1102) (#1106)

- **settings**: Carrier box table only for box packing, single cost hint, textarea rows (#1142)

- **checkout**: Custom-settlement guard stands down when the chooser cannot operate (#1148) (#1151)

- **checkout**: Keep the typed shipping postcode and street on classic checkout (#1170) (#1172)

- **e2e**: Pickup walkthrough runs on the realistic fixture (#1173) (#1174)

- **pickup**: First click and a quick close keep the pickup choice on both checkouts (#1171) (#1175)

- **shipping**: Native-field guard is silent for carriers with identical location-level sets (#1188)

- **checkout**: One shared cascade for carriers with overlapping location fields (#1187) (#1198)

- **location**: Admin shows the effective field mode, not the raw stored one (#1216)

- **shipping**: Hide internal shipping-item meta on the admin order screen (#1222)

- **orders**: The order editor rates an existing order with its checkout locality (#1223)

- **pickup**: Keep the map search field white under themes that grey search inputs (#1226)

- **lifecycle**: Keep milestone notices visible without review links (#1232)

- **shipping**: Re-match a city limit's cities by name and region after a provider switch (#1236)

- **shipping**: Hide show_if fields in the zone-method modal, not only in table rows (#1237)

- **api**: User-Agent product token is always woodev-<plugin id> (#1249)

- **api**: Partially mask secret values in logs (#1251)

- **shipping**: Stop auto export after unknown non-reconciling outcome (#1254)

- **api**: Partially mask headers and bodies in the API log broadcast (#1252) (#1255)

- **shipping**: Restore pickup address on method switch (#1264)

- **shipping**: Checkout keeps only the chosen carrier's point data (#1260) (#1267)

- **shipping**: Keep the buyer's chosen carrier method when the rate set changes (#1266) (#1268)

- **api**: Cap memory use when redacting large carrier bodies (#1265)

- **licensing**: Pause the updater after a failed request (#1273) (#1275)

- **pickup**: Hide the classic pickup slot when WooCommerce re-selects a non-pickup method (#1282) (#1283)

- **shipping**: Require explicit retry for unknown exports (#1274)

- **settings-api**: Support numeric string enum keys (#1288)

- **pickup**: A point without a locality or postcode no longer blanks the address fields (#1292)

- **checkout**: One owner for the block-checkout address (#1293) (#1300)


### CI/CD
- Run jest in CI, add timeout-minutes everywhere, fix phpstan OOM (#184)

- Scan for secrets on every pull request (#205)

- Enforce phpcs warnings in CI (#1235) (#1256)


### Documentation
- **s9**: Session save — v2.0.0 released, deactivator specs launched, s10 prompt (#40)

- **s11**: Record remote-deactivation UX findings (B-13/14/15) + re-aim s12 (#43)

- **s12**: Remote-deactivation UX (B-13/14/15) all resolved — session log, state, 2 gotchas, s13 prompt (#45)

- **s13**: Re-aim next session to framework grooming — edostavka pilot deferred (#47)

- **s13**: Session save — capture operator backlog dump (OB-1..9) + finalize state (#49)

- **s13**: Add s14 next-session prompt — grooming, choose from OB-1..9 (#50)

- **s14**: Record OB-3 Woodev_Plugin_Updater review + design decision (#53)

- **s14**: Session save — OB-1/6/3/2 grooming, 2 new gotchas, s15 prompt (#55)

- **s16**: Session save — OB-3 steps 2+3 merged, OB-8 marketplace tab PR #60, s17 prompt

- **s17**: Session save — OB-3 Step 5 updater MOVE merged (PR #61), s18 prompt

- **s18**: Session save — OB-3 Step 4 contract-touching F8/F9/F10 merged (PR #62), s19 prompt

- **s20**: Session save — license-page UI/UX redesign merged + rig-verified (PR #64)

- **s20**: Note license-page polish (form-group, icons, load skeleton — PR #65)

- **s20**: Activation/deactivation bug round (PR #66) + EDD error-vs-license gotcha

- **s20**: Note revoked-key «Изменить ключ» fix (PR #67)

- **s20**: Session save — license page DONE (5 PRs), s21 prompt (OB-7 Plugins page), +esc_url_raw gotcha

- **s21**: Add BLOCKING Item 0 — license-page unknown-status key strands the user (changeKey fix)

- **s21**: Session save — license Item 0 + OB-7 «Плагины» redesign/polish DONE; s22 prompt (woodev.ru-side API + account-connector)

- **s22**: Session save — OB-7 store-side (cross-project) + plugins-page rating (#71)

- **s22**: S23 next-session-prompt — finish «Плагины» (catalog polish + account connection)

- **s23**: Spec — framework-side account-connection client (MVP + #5 installed badges)

- **s23**: Session save — catalog polish OB-8 (PR #72) + account-connection client spec; s24 prompt

- **s24**: Session save — account-connection client shipped (PRs #73/#74) + 2 gotchas + s25 prompt

- **s25**: Session save — #7 «Мои покупки» + «Куплено» badge shipped (PR #75) + 2 gotchas + s26 prompt

- **s26**: Catalog timeout fix shipped (PR #76); #8 install-from-connector queued

- Note rig cleanup tail for #8 session (issuer leftovers)

- **s26**: #8 shipped (PR #77 merged, connector on prod); next-session = pick next

- **backlog**: Account ecosystem ideas — free Updater hub plugin + connected-sites mgmt (defer to post-v2)

- **s27**: Autoloader + type-via-extends shipped (PR #78); B-2 now pre-release blocker; s28 prompt

- **s27**: B-2 forward-tolerance resolved (not a blocker) + write down v2 loader conventions

- Backlog OB-10 — audit + rework Setup Wizard (brainstorm later)

- **s27**: Spec — competitor notification module v2 rework (design approved, impl deferred to s28)

- **s27**: Save session — competitor module spec ready (impl=s28); B-2 resolved; OB-10 setup wizard

- **s28**: Competitor notification module shipped (PR #79); +gotcha phpstan-windows-segfault; s29 prompt

- **s28**: Rig mu-plugin removed from :8090; project wp-env cleaned (stand+stubs gone)

- **s28**: Record docker inventory — never prune wordpress-test (prod-plugins) stack/volume

- **s28**: Save session — rig/docker cleanup logged; s29 prompt = Setup Wizard brainstorm

- **s29**: Record Setup Wizard (OB-10) shipped (PR #80); s30 prompt

- **s29**: Record independent-review fixes (#81/#82) + red-merge incident/lesson

- **s29**: Refresh s30 prompt — review done (#81/#82), UI-verify is the only wizard tail

- **s30**: Wizard UI verify + full-screen + WC-React redesign WIP on branch; s31 = design deep-dive

- **s31**: Gotcha — version wp-scripts CSS by filemtime, not JS bundle hash

- **s31**: Codex critic follow-up (PR #85) — settings-api save-path gotcha + deferred findings (OB-11)

- **s34**: Save session — SP-1 settings page SHIPPED (PR #87); s35 prompt = SP-2

- **s35**: Save session — menu-blocker fix shipped (#88), UI-kit pivot (SP-2 deferred)

- **s35**: UI-kit prompt — study WP/WC component SOURCE (repos + selectWoo), context7 only as pinpoint fallback

- **s36**: UI-kit design spec + UK-0 component research (brainstorm locked)

- **s36**: UK-1 implementation plan (UI-kit foundation + settings rebuild)

- **s36**: Save session — UI-kit UK-1 shipped (PR #90)

- **s36**: Backlog UK-CFR — settings field/section extensibility (deferred cycle)

- **s36**: Save session — UI-kit polish (#91) + settings polish (#92) shipped; s37 prompt = UK-2

- **s37**: SP-2 secrets + auth-contract design spec

- **s37**: SP-2 secrets + auth-contract implementation plan

- **s37**: Save session — UK-2 shipped (#93); SP-2 spec+plan ready; s38 = implement SP-2

- **s38**: Save session — SP-2 SHIPPED (#94); SP-3 validation locked decision #1; 2 gotchas; s39 = SP-3 spec

- **s39**: Save session — SP-3 SHIPPED (#96); +1 gotcha (format-validator-null); s40 = SP-4 DaData seam

- SP-3 polish SHIPPED (#97); next = conditional/dependent fields

- Add Task0 (stale branch cleanup) to next-session prompt

- **s40**: Conditional fields SHIPPED (#98); next = SP-4 DaData seam

- **s41**: UK-3/UK-4 + SP-2-DEF SHIPPED; SP-4 deferred to after the checkout field layer

- **s44**: §8 checkout field layer merged — state, log, gotchas, next-session pointer (#135)

- **sp5**: Pickup points + map — design spec and implementation plan (#137)

- **s51**: Record that both external memory layers were unreachable

- **sp5**: Design spec for the pickup point selection mechanism

- **sp5**: Fold #157 into the selection-mechanism spec, correct the nonce section

- **sp5**: Implementation plan for the pickup selection mechanism

- **s55**: Record the autonomous overnight session (#191)

- **s55**: Record the operator review round and the credential gotcha (#197)

- **s55**: Record the icon cascade, tabs, accent split and gitleaks (#209)

- **s55**: Correct the final suite counts measured on merged main

- **s56**: Record the viewport run, the five defects it found, and the night queue

- **s56**: Fold the measured Pochta widget API contract into the night queue

- **s56**: Restore the Pochta map bundle as a UI reference, with a local copy

- **s57**: Record the overnight run, the one bug behind two cards, and the Pochta queue

- **s57**: Record #226 and the lesson that a contract is not a payload

- **s58**: Record the live-Pochta rig pass and its three defects

- **s59**: Record the point-accumulation session, its two critic passes and three new cards

- **s59**: #237 answered by the operator — public docs stay untouched until v2

- **s59**: Close #242 — truncation self-heals on zoom-in, measured

- **s59**: Final state — #241 merged after operator review, #242 closed on measurement

- **s59**: Add #243 to the handoff queue

- **s60**: Full docs audit — sync gateways, prune state docs, archive shipped-work docs

- **s60**: Serena rule is mandatory — verify at session start, report if missing, propagate into subagent briefs

- **s60**: Session save — SESSION-LOG entry, s61 handoff, wpenv-cwd gotcha

- **s61**: Session save — SESSION-LOG entry, s62 handoff, state update

- **s61**: Add the external-memory section to the s62 handoff

- **s62**: Session save — SESSION-LOG entry, s63 handoff, state update

- **s62**: #243 merged after the operator's rig check; records #252

- **s63**: Session save — SESSION-LOG entry, state update, s64 handoff

- **s63**: Record the operator's rig check of the embedded picker and file #260

- **s63**: Bring the s64 handoff up to the post-verification state

- **s64**: Session save — state, log, two gotchas, overnight handoff for s65

- **s64**: Correct the handoff after #266 merged

- **s65**: #269 merged, and the five findings from the operator's rig pass

- **s65**: Correct the WC address-save claim — the gate is in checkout.js (#272)

- **s65**: Field-layer strategy (#273) and the picker UI batch (#274) in the handoff

- **s65**: Add the morning rig pass and the two corrections to the session log

- **gotchas**: Refresh the session-key recommendation for the rebuilt Pickup_Selection (#143) (#278)

- **s66**: Session log, state and handoff for the autonomous rig-pass session (#280)

- **s66**: Brainstorm brief for the locality/address field layer, and open the next session with it (#281)

- **s67**: Location provider layer — brainstorm-approved design spec + implementation plan (#282)

- **s67**: Session log, state, handoff and the WC address-autocomplete gotcha (#283)

- **s67**: D15 amendment — per-level suggest support with a provider fallback chain (#284)

- **s68**: Session log, state and handoff for the PR-A/PR-B execution session

- **s68**: Record PR-C as coded and awaiting the operator's rig pass

- **s70**: Session log, state and handoff for the rig-verification session

- **s71**: Session log, state, handoff and four gotchas for the overnight batch

- **s71**: Close out the session — plan finished 16/16, two more gotchas

- **s72**: Three gotchas from the adversarial reviews, plus the Codex root cause

- **s72**: Gotcha — Brain Monkey's ->with() is a filter, not a census

- **s72**: Close out the session — three PRs merged, two awaiting the rig

- **s72**: Close the session — all five PRs merged, two new defects found on the rig

- **s72**: Correct the Codex gotcha — do not try to repair the shell

- **s72**: Mark the Codex gotcha's history as unverified, per the operator

- **s73**: Close the session — two PRs merged, ten cards filed, 148 gotchas

- **s74**: Close the session — three PRs merged, one card refuted, 151 gotchas

- **s74**: Fold the operator's #339 answer and the #337 measurement into the handoff

- **s74**: Correct the handoff's entry sha after the addendum commit

- **s74**: Close out the addendum day — operator's #339 answer verified as buildable, #337 measured reachable

- **s74**: Point the handoff at the final sha

- Cut session-start reading from ~334 KB to ~86 KB and gate it

- **s75**: Record the docs cleanup and the #339 measurement

- **s75**: Record the operator's decisions on #337, #325 and #343

- **s75**: Point the handoff at the final sha

- **s76**: Record the three cards, four gotchas and the second live provider

- **s76**: Correct the unit-test count for merged main

- **s77**: Record the operator's rig pass, #349 and the decision on #350

- **s77**: Record the CDEK mixed-chain find and hand off an autonomous session

- **s78**: «заблокирована и всё» is an exception, not a project rule (#360)

- **s78**: Session writeup, state and handoff

- **s78**: Correct the main hash in the handoff and state

- **s78**: Pin the last CODE commit instead of a hash that keeps moving

- **s79**: #362 brainstorm output — approved design spec, 12-task plan, handoff for Opus 5

- **s80**: Gotcha — stacked PRs close (not retarget) on base-branch delete; ci.yml only runs on PRs into main

- **s80**: Session writeup, state and handoff for tasks 10-12

- **s81**: Branch cleanup after a squash-merged stack is a mechanical check

- **s81**: Verify the two remaining field options live, record the operator's review

- **s81**: Handoff for the admin-surface pass on the «Доставка» tab

- Record the accidental-close trap in the handoff traps list

- Sharpen the accidental-close trap — it fired twice, second time on itself

- **s81**: The operator's second pass — DaData keys decided, S2 superseded, colours corrected

- **s81**: "обычное текстовое поле" means typeahead — the #380 question dissolves

- **s83**: Close the session out against the merged state

- **s83**: Record the Orca configuration pass and the @since decision

- **s83**: Install the Orca skills globally instead of hand-rolling a session hook

- **s83**: Hand off with the environment announcing itself

- **gotchas**: Correct the assets-parity cause — it is the node_modules symlink, not the cache (#425)

- **s84**: Record the overnight session, seven merges and six tooling traps (#432)

- **s84**: #395 is open as PR #433 and must not be merged (#434)

- **s84**: Close the session — #395 round 6 has no verdict, and the wave was too big (#435)

- **s84**: Codex is critic-only until 27.08.2026, and cap the wave (#436)

- **s84**: Дизайн поиска НП — крышка 500 это неверный вопрос, а не неверное число (#438)

- **s84**: Granularity becomes a checkbox that self-releases, not a derived mode (#439)

- **s84**: Detection is for the UI, self-release carries correctness (#440)

- **s84**: Sharpen the admin-detection question — reachability first, accuracy second (#441)

- **research**: Select2/selectWoo reference study for ajax-select2 location fields (#452)

- **s85**: Checkout location layer is not shippable — four new defects, two PRs, four gotchas

- **s86**: The checkout location layer is one disease, not five

- Record who owns the checkout address fields as Rule 7

- **s86**: The widget lied while every test asserted the value

- **s86**: A green suite asserted the value while the widget lied

- **s87**: #466 оказался нашим же §8-адаптером, плюс ловушка мерж-поезда (#476)

- **s87**: #472 round 3 broke the integration suite, and the brief never asked for it (#477)

- Rule 7c — один живой каскад, и он следует за активной колонкой (#475) (#479)

- **s87**: Session save — the operator's pass, three of my errors, and Rule 7c (#480)

- Codex is a full worker again; the two caps from that decision are not (operator, 24.08.2026)

- **s88**: Session save — #458 closed on the fourth round, two defects the operator found, and the popular-settlements spec

- **s89**: Session save — three PRs merged overnight, and every card's proposed fix was a hypothesis

- **gotchas**: A dispatch can stall with the brief unsubmitted — a third state, not a retry

- **gotchas**: One /select response narrows every level, including a queued pick

- **s89**: Session save — five PRs merged, and every card's fix was a hypothesis

- **s89**: Handoff — what needs the operator's hands, plus #498

- **plan**: Fix the seams for #488 slice 3 so two workers can build it in parallel

- **plan**: Say what the common /select path must not gain, and what it unavoidably does

- **s90**: Session save — the operator's rig pass beat 1380 tests and five critics

- **s91**: Session save (#519)

- **s91**: Close-out — #508 accepted on the rig, #517 and #518 settled (#521)

- **s92**: Close-out — #517 + #528 and #502 merged, four new gotchas

- **s93**: Correct two stale baselines, record the select2 language gotcha

- **s93**: Close-out — #526 merged, #530 in PR #535 awaiting the operator

- **schema**: Give the handoff a format and a gate that enforces it

- **agents**: The handoff is the one deliberate gitignore exception

- **s93**: #536 verified on the rig, PR #537 open; two new gotchas

- **gotcha**: The untracked-file trap outlived its own project-specific framing

- **s93**: Close-out — #526 merged, two PRs open, #541's real cause found

- **s94**: Session save — both PRs merged, four operator decisions, overnight handoff

- **s95**: Session save — five PRs merged, #405's measurement corrected, one PR held for the rig

- **s96**: Session save — #547 and #552 merged, two gotchas, SESSION-LOG split

- **s97**: Four gotchas and the kilo-as-critic recipe (#557)

- **s97**: Session save — four PRs merged, kilo took the critic seat

- **gotchas**: The local PHP is 8.5, the CI floor is 7.4 — a green local suite is not evidence (#572)

- **location**: Settle the sub-minimumInputLength gap on a measurement, not a guess (#574)

- **s98**: Session save — 16 cards closed, two MAJOR found in merged code, CI blocked on billing

- **s98**: The CI block is a spent quota, not a failed payment — with the cost measured

- The repo is public again — CI unblocked, and the Actions-budget rule is written down

- #582 merged once CI came back — one PR left, and it is waiting on the rig

- **s98**: Session save, part two — the billing arc, the public switch, and local CI measured

- The rig is not where the docs said, and CI was not blocked any more (#588)

- **s99**: Handoff, and the critic model changes on a bill rather than an estimate (#590)

- **s100**: Four merged PRs, and two of my own claims falsified by measurement (#597)

- **s100**: Carry the three cards filed after the docs merge into the handoff (#601)

- **s100**: Stop the handoff header claiming a commit hash it cannot know (#602)

- **agents**: Key the triage rule on whether a card needs the operator, not on who wrote it (#603)

- **s100**: #600 is closed — drop it from the handoff's waiting-on-operator list (#604)

- **s101**: #599 — the filter-return audit, 159 sites with a verdict each (#612)

- **s101**: Three cards shipped, six filed, and two of my own misses recorded (#614)

- **s101**: Fold the operator's two in-session answers into the code, tests and docs (#615)

- **s101**: #610 answered and shipped — record the decision table and the new baseline (#617)

- **s599**: Resolve both UNKNOWN verdicts by measurement — 42 FATAL, not 40 (#618)

- **s101**: #613 tranche 1, and two files trimmed back under their limits (#620)

- **s101**: Session close — handoff rewritten for an autonomous overnight run (#622)

- **s101**: The handoff said there are no worktrees; there is one, and it is not ours (#623)

- **s102**: Session close — #606 and #609 shipped, #613 taken from 6 sites to 47 (#633)

- Codex is back and verified; kilo demoted to fallback critic (#634)

- **s102**: Record the critic round and close out #632 (#637)

- **payment-gateway**: State the real contract of the tokens transient-key hook (#613) (#638)

- **s102**: Session close — #613, #514 and #632 shut, #567 measured and deferred (#642)

- Model selection is a two-step choice, and Codex is a worker again (#643)

- **agents**: The four translatable-string rules, by who reads the string (#567)

- **s103**: #567 rule 1 shipped, #627 and #353 closed, handoff written for an overnight run

- **s103**: Finish the save — the label rewrite and the merge that ate 92 entries

- **s104**: #644 docs+board audit — contradiction map, three stale CURRENT-STATE.md claims, board hygiene (#660)

- **s104**: Five cards closed, three critic findings, and the i18n rule's missing half

- **s104**: #361 merged after the operator's rig pass, baselines re-measured

- **s105**: #410 built and #662 found by building it; both held for the operator's rig pass

- **s105**: Both PRs merged; main re-measured at 3236/7921/1

- **gotchas**: Drop a stray Russian phrase from an English-only internal doc

- **s106**: Both location-layer leftovers closed; two gotchas, SESSION-LOG split

- **orca**: Codex reads an Orca worktree once its gitdir is relative (#510) (#670)

- **testing**: A random-order seed is not a portable reproduction (#675)

- **shipping/checkout**: Resolve card #147's WC-global degradation audit (#677)

- **orca**: Correct the Codex shell claim — the terminal is PowerShell, its tool shell is not (#679)

- **s107**: Eleven cards closed, twelve PRs merged; two operator corrections (#682)

- **s107**: Compile the session's tooling findings into the gotchas that own them (#684)

- **s108**: Correct two gotchas the operator's challenge falsified

- **s108**: Record PR #688, settle #683, and two compiled gotchas (#690)

- **s108**: Brainstorm outcomes, two convention corrections, overnight handoff (#691)

- **s108**: Finish the compilation step and the backlog sweep (#693)

- **s108**: Record the spec drift and the two cards it produced (#696)

- **checkout**: Record that set_label() is inert on a native WC field (#697)

- **gotchas**: The phpcs warning tier is silent, not just line length

- **gotchas**: A mixed-EOL file makes phpcs blame whitespace

- **s109**: Session write-up, board sweep findings and the handoff

- **s109**: The rig session, ADR-011 and the handoff

- **s110**: The #644 tail, the #695 reconciliation and three gotchas

- **s110**: Board audit — #706 filed, nothing left in В работе

- **rig**: The mu-plugin pickup method is now declared everywhere it can be

- **rig**: Correct the §8 demo note — the fields are dark, their validation is not

- **s110**: The rig pass, three checkout defects, and a diagnosis I got backwards

- **116**: Normalise seven inherited @since tags to 1.0.0 (#716)

- **s111**: #694 SP-10 page shape decided, #695 archives SHIPPING-PLANS/PLANS (#718)

- **s111**: Two gotchas, the s111 record, and state after four merges

- **gotcha**: A PR body shuts cards behind the commit-msg hook's back

- **s111**: The handoff

- **s111**: #709 landed — state, session record and handoff updated

- **s111**: #707 closed by his rig pass, #721 found and fixed

- **s111**: Record the operator's acceptance of the #721 fix

- **s111**: Record the e2e suite and what writing it corrected

- **rig**: Restored to standard, and why the default locality gates pickup entirely

- **rig**: Trim CURRENT-STATE back under its reading budget

- **s111**: His decisions, two bookkeeping errors, and the overnight scope

- **s111**: Open-card count is 74, not 75

- **s111**: Fix the stale SESSION-LOG entry and record the worktree-removal trap

- **serena**: Put the tests/ exception in the rule, not only in the gotcha

- **gotchas**: A warning did not prevent the PR-body card closure, so give the positive template

- **s112**: Session file, handoff, state, and the rig point-source gotcha

- **settings**: Correct the help-text slots against the widened rule (#731)

- **s112**: The operator's rig verdicts — #171 accepted, #692's split rejected

- **s112**: Second half — his rig verdicts, the second rig carrier, and #736

- **s112**: Open-card count is 70, not 69

- **gotchas**: Two traps from s113 — a fleet-wide static gate and the modal's locality source

- **s113**: Four cards closed, two settled by measurement against their own proposal

- **s113**: Correct three of this session's own findings; #150 and #741 settled

- **s113**: Record the operator's two rig decisions and their verification

- **s113**: Two defects found answering the operator's DOM and addressing questions

- **s113**: Session saved; handoff rewritten for an autonomous overnight

- **gotchas**: The rig's live point source ignores the requested locality

- **s114**: Autonomous overnight — three PRs merged, one held, five cards closed

- **s114**: Post-report verification — #751 merged, #104/#747 closed

- **s114**: Re-measure the unit gate after #751 landed

- **s114**: Record the @since ceiling gate and the flaky test it uncovered

- **s114**: Close out the queue — #755 and #713 shipped, #714 and #371 deferred with reasons

- **s114**: Record the rig admin fatal and the two mines it uncovered

- **s114**: #759 closed — the subsystem construction contract now covers three subsystems

- **s115**: Priority moves onto the board — field, milestones, pilot decision

- **s115**: Verify the edostavka contract checklist against the live plugin (#762)

- **s115**: The Cyrillic-mangling trap also fires through gh, where it persists

- **s115**: The v2 entry path was never tested, and the pilot found it (#762, #763)

- **s115**: Session close — pilot is live, priority lives on the board

- **s115**: Sync the handoff and session file with the overnight findings

- **s115**: Gap map for the pilot's blocking decision (edostavka#2, #3)

- **s115**: #763 closed — the entry path now has a consumer in the repo

- **s115**: A mutation can land in the DOCBLOCK — sharper form of the s73 gotcha

- **s115**: Note the docs-audit progress in the handoff (#644)

- **s115**: AGENT-RULES read as if Codex were still critic-only (#644 part 1)

- **s115**: The language rule described practice wrongly in 99 files (#644 part 1)

- **s115**: Refresh the Last-updated stamps this session invalidated (#644 part 1)

- **s115**: Handoff carries the audit outcome and the pipe-exit-code trap

- **s115**: Drop the hand-written Last-updated stamps (#644 part 1)

- **s115**: The caркас attempt measured its own scope — and a new gotcha

- **s115**: Reconcile the session file with what the night actually did

- **s115**: Session save — state, handoff, and nothing left waiting on the operator

- **s116**: Пилот на каркасе v2, два дефекта фреймворка и разбитый журнал

- **s116**: Fix the scaffold commit SHA — the commit was amended before push

- **s116**: The Windows/container PHPStan gap, measured with a control

- **s116**: Backlog sweep — two findings that had surfaced but never became cards

- **s116**: Correct the edostavka#3 verdict — the settings page is duck-typed

- **s116**: The pilot is stopped — record that the session went the wrong way

- **s117**: The Title/Description fix, the rate DTO, and the probe's 11/8 correction

- **s117**: Quote the measured doc sizes in the handoff, not rounded ones

- **s117**: Backlog sweep — file #771 and correct the handoff that said no cards were filed

- **s117**: Close the session and write the handoff for an autonomous overnight run

- **s117**: A released worker was still running — read `state`, not `ok`

- **s118**: A raw payload size is not a wire cost — #364 closes at +503 bytes

- **s118**: Close the session — three cards done, and two of my own errors recorded

- **s118**: Rule 8 for where settings live, and why the rig could not show #771

- **s118**: Fold the #771 visual pass into #567, close the session

- **s119**: #644 part 1 — reconcile the gateway docs with the rules they had fallen behind on

- **s119**: #644 part 2 — all 60 open board cards verified against the code

- **s119**: The superseded deprecation policy was in nine more files, including a skill description

- **s119**: Move the rig manual and the layer contracts to the wiki, per #778

- **s119**: Session file, handoff, and the gotcha the docs gate could not have caught

- **s120**: Two gotchas from the capture batch — money as raw floats, and the i18n gate's real contract

- **s120**: Name the two real Codex limits instead of trailing off

- **s120**: The #573 gotcha — a detach that only unbinds still writes

- **s120**: Session file, handoff for the overnight run, and the state it leaves

- Triage the last two Orca capabilities (#779) and record the PHP-driven reusable-JS principle (#107) (#793)

- Audit whether the ten frozen pickup cards need a real carrier plugin (#795)

- **s121**: Two gotchas from the night, and the baselines re-measured

- **s121**: Session file, and s110-s119 archived out of the session index

- **s121**: The critic rounds, and a second trigger for the stale-classmap gotcha

- **pickup**: Say why get_strategy()/fetch_details() are final (#801)

- **s121**: Handoff for the next session, and the classmap incident

- **s121**: E2e re-run on the merged main — 7/7, not the carried-over s120 figure

- **s121**: The operator's rig pass settled #165 and #173, and #371 got its answer

- **s121**: Session-end protocol — final counts, and the classmap friction gets a card

- **orca**: Retier the Codex models around gpt-6-astra, and price it by measurement

- **s122**: Record the non-executable-hook gotcha and correct the unit baseline

- **s122**: Session file, handoff, and the state this session actually leaves

- **s122**: His acceptance on #105/#112, the v2 gate, and an overnight handoff

- **s123**: The i18n pass, the dead feature flag, and a handoff whose numbers are all fresh

- **s123**: Board audit — two cards filed from the #811 investigation, counts corrected

- **s123**: The translation pass is release-gated, not a question for the operator

- **s124**: Both halves of declaring a shipping feature, and two cards that pointed at the wrong fix

- **s124**: #818 filed from the same investigation, counts corrected

- **s124**: The #114 brainstorm, its state on the card, and the boundary gotcha it turned up

- **s124**: Session End now requires closing the session's agent terminals

- **s125**: SP-10 orders page design, and the measurement #694 left open

- **s125**: Correct the SP-10 row-scope design and record the two traps behind it

- **s125**: The SP-10 type column is declared, not inferred

- **s125**: The two traps the orders-page shell cost

- **s125**: The orders page belongs in WooCommerce's menu and WooCommerce's React app

- **s125**: SP-10 session write-up, corrected spec and the handoff

- **s126**: Session file, two new gotchas, and an overnight handoff

- **gotchas**: Declaring wc-settings as a script dependency silently drops the bundle

- **s127**: Session file, two new gotchas, and a handoff that leads with three unmerged PRs

- **s127**: The three SP-10 PRs are merged — re-measure and correct the handoff

- **s127**: Open-card count is 53, and note that gh issue list pages at 30

- **s127**: Recon of the operator's rig pass — five cards, no fixes started

- **s127**: Finish the session record — second half, two gotchas, corrected counts

- **s128**: Save the session and hand off into an autonomous overnight run

- **s128**: Correct the handoff — #836 is started, not waiting, and lives on two branches

- **s129**: Record the #836 merge, three cards closed by measurement, and the rig numbers

- **gotchas**: Wc-admin re-adds the date params a menu link does not carry (s129)

- **s129**: Wave 2 — the data-status panel merged, the is_exported half measured on the rig

- **s129**: Hand off the night — three PRs merged, three cards closed by measurement, three decisions waiting

- **s129**: Close the night — #841 merged, the critic's finding and the rig re-seed consequence

- **s129**: The operator's two answers, both shipped — #837 closed by #847, #838 closed as upstream

- **s129**: Save the session — four PRs merged, one open and waiting on his eyes

- **s130**: A DOM read cannot answer a question about server-rendered markup

- **s130**: Save the session — three PRs merged, the badge held behind a period decision

- **s131**: A bare CONNECTION_CLOSED from Serena is uvx picking the wrong Python

- **s132**: Сохранить сессию — два PR смержены, очередь переставлена оператором

- **s133**: Сохранить сессию — #853 смержена, #829 ждёт визуальной приёмки

- **s133**: Приёмка #829 пройдена — #859 смержена, заведена #862

- **s133**: Сохранить сессию — таблица «Заказы доставки» доведена и принята

- **s134**: Сохранить сессию — таблица «Заказы доставки» закончена и принята

- **s134**: Поправить число открытых карточек — 58, а не 56

- **s134**: Записать уборку веток как обязательство следующей сессии (#878)

- **s135**: Docs audit, GOTCHAS.md topic map, two-machine setup (#883)

- **s135**: Save the session — merged state, first-Mac-session steps, Serena path per clone

- **s136**: Save the session — first Mac session, five script defects, metabox parked (#892)

- **s136**: Name the session-save PR in the handoff, and the real main hash (#893)

- **s136**: Stop writing screenshots to the Desktop — hand over the rig instead (#894)

- **s136**: Close the session — metabox accepted and merged, 17 branches gone (#895)

- **s135**: Desktop rig notes — measured 5-9 s TTFB and its cause, OrbStack on the laptop, desktop rig stopped (#896)

- **s136**: Fix the handoff header — it still described a parked branch (#897)

- **s137**: Close the session — SP-10 done, six tech-debt cards landed (#909)

- **s137**: Record the branch cleanup, the operator's download-id rule and #910 (#911)

- **s137**: Record the backlog sweep card #912 (#914)

- **s138**: Finish the session save — the sodium flag on macOS, and #922 (#923)

- **s139**: Bring CURRENT-STATE to the post-#839 state, and displace what it no longer owns

- **s139**: Record #919's outcome — closed by measurement, re-scoped onto #928

- **s139**: Write the session file and index it

- **s139**: Hand off to the next session

- **s139**: Record the operator's YAGNI ruling on multi-marker orders

- **s140**: Архив s130–s139, лимит строки SESSION-LOG, инварианты фильтра заказов (#931)

- **research**: Замер формы для #928 — стена в маркерной области, а не в отрицании (#933)

- **s140**: Save the session — #928 closed, handoff, gotchas

- **s140**: Record the operator's calls on #926, #922 and #843

- **s141**: Split the agent concurrency cap by machine — RAM on the desktop, rate limit on the Mac

- **research**: #935 measured — neither id-query narrowing nor one GROUP BY is built (#939)

- **s141**: Save the overnight session — #843/#936 and #916 parked for the operator, #935 closed, handoff, Codex readiness gotcha

- **s141**: #937 accepted and merged, #941 filed; Codex readiness fails on --model/--effort, not on the rate limit

- **s141**: #916 merged as an activation guard (PR #944), #942/#943 filed, plugins_loaded gotcha

- **s141**: #710 create/edit order wizard spec from the operator's brainstorm, critic-reviewed; tails decided

- **s141**: Final save — handoff gates after the merges, session log line

- **#962**: #710 I0 measurement — marker writers, checkout hook order, admin zone/rate resolution

- **#962**: Merge #710 I0 measurement note

- **gotchas**: The block checkout is a REST request and fires none of the classic checkout hooks (s142)

- **gotchas**: The block checkout is a REST request and fires none of the classic checkout hooks (s142)

- **gotchas**: Fix slug typo and link the I0 note now on main

- **#962**: Redact WooCommerce cart item keys in the I0 logs

- **s142**: Session save — overnight: tails closed, #710 built I0–I8, five PRs merged, #978 blocked on #981

- **gotchas**: Drop the literal cart-item key from the gitleaks gotcha so the tree scan passes

- **s143**: Session save — #981 closed in four rounds, #710 merged and accepted on the rig, overnight handoff

- **s144**: Session save — #988 merged, the order-wizard stack (#985 #982 #991 #996 #992) parked, overnight handoff

- **s144**: #994 was merged by the dependabot auto-merge, not left open

- **s145**: Session save — wizard stack merged, idempotent export, timeouts and delayed retry, auto-export/auto-cancel, metabox nested form; overnight handoff

- **s146**: Session save — s145 follow-ups merged (#1015 #1016 #1017 #1009 #1026 #1029 #1025), three UI PRs parked; overnight handoff

- **s146**: Session save, part 2 — parked UI PRs accepted and merged, #1033, #1037 (cancellation reads «Отменено»); dispatch-id gotcha

- Codex default model gpt-6-luna, hard tasks gpt-6.1-sol (operator, s146)

- Trim CLAUDE.md under its size gate

- **s146**: #1024 decided and merged (new scope = exportable only)

- **s146**: #1036 merged

- **s146**: Session save — operator-free queue (#181 #110 #1042) and the agenda for his return; card #1042

- **research**: Audit setup wizard for issue 109 (#1046)

- **s147**: Session save — nine PRs merged and accepted on the rig, agenda walked with the operator, rig on Storefront; gotcha on WooCommerce's select2 height

- **s148**: Session save — four PRs merged, #955 reworked to the operator's decisions, overnight handoff; gotcha: DaData has no 402

- **s149**: Overnight session save — #1057 #1056 #1062 merged, #955 and #1060 parked for the operator; gotcha: gh pr merge from a detached HEAD

- **s149**: #955 accepted by the operator and merged (section renamed «Вес и габариты»)

- **s149**: #1060 live-checked on the rig and merged; no open PRs

- **s149**: Checkpoint — #1069 #1071 #331 merged, #332 in progress; gotcha: WooCommerce re-fires a same-value state change

- **s149**: Session save — 9 PRs merged, #1075 awaiting the operator, #332 needs write-on-Save; 3 gotchas

- **s150**: Session save — #1075 #332 #948 #947 #130 merged; base minimum recorded; overnight handoff; 4 gotchas

- **spec**: SP-11 block checkout adapter — draft design (#1078) (#1085)

- **s151**: Session save — #1081 merged; SP-11 draft spec, forks #1086, slices #1087-#1091; 2 gotchas

- **s151**: #1092 merged; handoff gates re-measured on 65749d2c

- **spec**: SP-11 forks decided by the operator (#1086)

- **s151**: Afternoon — #1086 decided, #1088 #1093 #1087 merged, #1089 in progress; handoff

- **s151**: Fix markdown in session log row

- **s151**: Handoff — #1089 critic r1 and fix round in progress

- **s151**: Handoff — #1089 rig probe failed (owner null), final round in progress

- **s151**: Handoff — #1089 ready, parked for acceptance; #1100 filed

- **s151**: #1089 accepted and merged (PR #1099); handoff for SP-11 C-3/C-4

- **s152**: Session log, state and overnight handoff

- **s153**: Session log, state and handoff — #1102 merged, C-4 + #1101 parked in PR #1116

- **s153**: GlitchTip mail and end-to-end check done; temporary server access removed (#1082)

- **s153**: PR #1116 accepted and merged, #1115 settled (A), stale branches removed

- **s153**: #1107 settled by the operator — one locality field, never two

- **s153**: Close the session — #1107 decision, no server reminders

- **s154**: Close the session — SP-11 tails merged (#1120), #1121 next, two gotchas

- **s155**: Close the session — #1121 and SP-11 wave 2 merged, #1108 parked, overnight handoff

- **s156**: Close the overnight session — PR #1131 and #1132 merged, #1119 analysed, two gotchas

- **agents**: Rate review findings by real-shop probability and consequence

- **agents**: Move the real-shop rating rule to AGENT-RULES (AGENTS.md size gate)

- **s156**: Record the operator brainstorm — SP-11 closed, carrier plugin unfrozen, five edge-case cards closed

- **s156**: Session log line covers the morning brainstorm

- **s156**: Keep the session log row under the 600-char gate

- **spec**: CDEK v2 plugin design — inventory, seam map, data contract, stage 1 plan, operator decisions

- **skills**: Plugin-author skills for Framework v2 — core and shipping plugin (#1135)

- **gotcha**: Get_plugin_name() runs before init — a translated name warns; skill trap line

- **spec**: CDEK v2 — operator decisions on developer key, KLADR, carrier-first city codes

- **s157**: Session save — CDEK v2 plugin stages 1-4, framework skills/delivery ids/pool #1140, handoff for a coordinator-only s158

- **s157**: PR #1140 merged, handoff for s158

- **s158**: Session save — CDEK v2 rig review fixes, PR #1141 merged, 4 gotchas, handoff for s159

- **s158**: Rig round 2 — PR #1142 merged, handoff updated

- **s158**: Belarus decision (#1143), handoff for s159

- **s158**: Parity remainder split per operator (#1144-#1147)

- **s159**: Session save — #1143 #1144 #1148 #1149 #1152 (PRs #1150 #1151 #1153), handoff for s160

- **s159**: #1152 verified on the rig and closed

- **s159**: Evening fixes — not-configured notice (#1155, PR #1154), CDEK method names (#1156)

- **s159**: Trim handoff under its size limit

- **s159**: Night wave — PR #1159, CDEK method settings, cards #1157 #1158 #1160

- **s159**: Trim handoff under its size limit

- **s159**: Session save — operator decisions for the overnight s160, handoff

- **s159**: Handoff — address suggestions exist on classic; the open question is the block checkout

- **s159**: Trim handoff under its size limit

- **s159**: Handoff — operator decision on block-checkout address suggestions

- **s160**: Session save — overnight CDEK decisions shipped (PR #1165, plugin v2), gotchas, handoff

- **s160**: Operator decision — functional acceptance is the agent's (#1169), he checks visuals only

- **s161**: Session save — CDEK acceptance gate (#1169), #1170 fix, plugin #7 #8 #9, #1173 parked

- **s162**: Session save — release = two compatible carrier plugins (#1179), Setup Wizard contract (#109), brainstorms #1146 #1147, overnight handoff

- **s162**: Handoff — overnight merge permission granted

- **s162**: Post-save notes — merge permission, coordinator idea (#123), remote dispatch measured

- **s163**: Session save — #109 #1176 #1180 #1188 merged, edostavka#11 done, #1177 skeleton parked, release blocker #1187

- **s163**: Handoff — rig demo order for the courier call, e2e 10/1 after core pickup, #1189

- **gotcha**: A killed inbox loop leaves an orphan check --wait holding the waiter slot

- **s164**: Session save — #1193 #1196 #1197 + edostavka#18-#20 merged, agents work in English, worktree-base gotcha

- **s164**: Handoff — edostavka#22 merged

- **gotchas**: Wp wc shipping_zone_method delete takes the first number as the instance

- **s165**: Session save — #1187 #1145 #1201 + edostavka#21 #24 #26 #27-step-1 merged; overnight handoff

- **s166**: Session save — #27 steps 2+4, #1203, #1205 merged; #1204 and edostavka#30 parked for the operator

- **s166**: Operator approved the parked UI — #1207, edostavka#32 #34 merged; release buttons fix (edostavka#37)

- **s167**: Session save — wizard decisions G1–G6, CDEK services/city limit, all webhooks, migration stand, non-HPOS queries

- **s168**: Session save — wizard merged, migration boundary 2.2.0, real-placement virtual box, overnight handoff

- **s168**: Trim the handoff under its size gate

- **skill**: Reconcile woodev-shipping-plugin with the CDEK v2 lessons (#1221)

- **s169**: Session save — CDEK location provider, real-placement merchant boxes, parked copy PRs, two gotchas

- **s170**: Session save — CDEK operator review, postamat method, tariffs, competitor notices, three gotchas, overnight handoff

- S171 documentation audit (full read, 45 files) (#1234)

- **s171**: Session save — two-round docs audit, #1224, #1217, edostavka#60, Инбокс audit, Codex 0.162.1 launch measurement

- **s172**: CDEK production checks done (#786, plugin #83) — session note, log, state, handoff

- **s172**: Session save — backlog audit and deferred CDEK cards queued for s173

- **s173**: Session save — Бэклог audit, #1185 #1166 #1167 merged, docs-system skill synced, cleanup; Yandex brainstorm queued

- **s174**: Yandex v2 brainstorm — decisions on #1179, three live measurements, design spec

- **research**: Yandex terminal/5post limits (R1) and COD delivery-cost surcharge (R2)

- **gotchas**: S175 — PHPUnit 9 class-level isolation is ignored; two recurrences recorded

- **s175**: Overnight Yandex session — cards, new repo, Y1–Y13, framework UA/masking/export guard; CI billing block on private repos

- **s175**: Y10a #37 approved in round 2

- **s175**: Local-ci becomes the private-repo gate; Y9/Y10a merged; CDEK on local-ci

- **s175**: Session save — billing gotcha superseded for private repos by the local-ci gate; Y9 follow-up card

- **research**: Yandex test contour is silent; sharing_url and PDF shapes from the published docs (Y10b, Y14)

- **s176**: Session save — Y10b/Y14/Y15 merged, coexistence defects fixed, memory 130→85 MB, both plugin pins c241bfba

- **s176**: F1 and F5 merged on the operator's word; plugin pins to bump next

- **s176**: Operator decisions — no live Yandex export check; #1253(b) explicit retry action

- **s176**: Licence gate #1270 is release-blocking — operator decisions recorded

- **s177**: Licence gate #1270 live end to end; Yandex Y16/Y43; pins 8b56ae0a; rig licence mu-plugin

- **spec**: S178 amendments to the Yandex v2 design (#1280)

- **s178**: Session notes, state, four gotchas, s179 handoff (#1281)

- **s179**: Session notes, state and handoff (#1286)

- **s180**: Session notes, state, overnight handoff, consumer_fenced gotcha variant (#1287)

- **s181**: Overnight session notes, state, handoff, two gotchas (#1296)

- **s181**: Operator accepted the Yandex wizard and VAT rate; #1253 closed (#1297)

- **s181**: Order 1444 cancelled; billing_only is the rig norm (operator) (#1298)

- **s181**: Session save — morning outcomes, next actions start with #1293 (#1299)

- **skills**: Plugin-author lessons since 11.10.2026 + standing rule to update skills with the fix (#1304)


### Features
- **licensing**: 2.0.1 — release-blocking WSOD fix, neutral deactivation notice, filterable api_url (#41) ⚠ BREAKING

- **licensing**: S12 remote-deactivation UX — clear notice on reactivation + WC inbox breadcrumb (#44)

- **bootstrap**: Name conflicting v1 plugin in mixed-fleet dormant notice (OB-1) (#51)

- **admin**: OB-8 — add Woodev marketplace tab on plugin-install.php (#60)

- **license-page**: «Woodev → Лицензии» UI/UX redesign (#64)

- **plugins-page**: Redesign «Woodev → Плагины» — React catalog + account scaffold (OB-7 Phase A) (#69)

- **plugins-page**: Modern compact catalog + woodev.ru brand polish (#70)

- **plugins-page**: Show product rating (stars) in the catalog card (#71)

- **plugins-page**: Redirect plugin-install Woodev tab to React catalog + icon placeholder (#72)

- **account**: Woodev.ru account-connection client (MVP + installed badges) (#73)

- **account**: Enable the account-connection UI by default (#74)

- **account**: «Мои покупки» tab + «Куплено» badge (#7) (#75)

- **account**: Install purchased plugin from connector (#8) (#77)

- Framework runtime autoloader + plugin type via extends (PLANS §5) (#78) ⚠ BREAKING

- **competitor**: Competitor notification module (v2 rework) (#79)

- **setup**: Rewrite Setup Wizard (OB-10) — neutral React-driven, opt-in (#80)

- **setup**: Modern WC-onboarding Setup Wizard UI redesign + Settings-API control types (#84)

- **settings-page**: SP-1 — neutral Woodev > Настройки page (registry + REST + React) (#87)

- **ui-kit**: UK-1 — shared UI-kit foundation + settings rebuilt on it (#90)

- **ui-kit**: Migrate Plugins + Licenses pages onto the shared kit (UK-2) (#93)

- **settings**: Secrets masking + connection auth contract (SP-2) (#94)

- **settings**: Field validation — required + email/url/tel/number (SP-3) (#96)

- **settings**: SP-3 polish — placeholders, validate callback, scroll/snackbar on blocked save (#97)

- **settings**: Conditional/dependent fields (show_if) (#98)

- **settings**: Sensitive-secret wipe/clear affordance (SP-2-DEF) (#100)

- **shipping**: Checkout field layer — store, field registry, classic adapter, A2 gating (§8) (#132)

- **shipping**: SP-5 pickup points + map (#149)

- **shipping**: Pickup point selection mechanism (#177)

- **pickup**: Framework-owned square glyphs for the sidebar list and point card (#196)

- **pickup**: Add a point-level icon to the map-marker icon cascade (#198)

- **pickup**: Split the brand accent from the filled-text surface (#203) (#206)

- **pickup**: Disable a zoom button once the camera reaches that limit (#216)

- **fixture**: Live Russian Post viewport point source, measured not inferred (#229)

- **pickup**: Lock the last enabled type-filter checkbox instead of silently reverting it (#246)

- **shipping**: Embedded map-provider adapter seam + rig fixture (#251) (#256)

- **pickup**: Show the dialog's busy overlay while an embedded confirmation runs (#264)

- **pickup**: Persist the chosen pickup point across checkout requests (#176) (#269)

- **location**: Контрактное ядро слоя location-провайдера (PR-A, задачи 1-6) (#286)

- **location**: DaData-провайдер, REST-шов и конфиг чекаута (PR-B, задачи 7-9) (#287)

- **location**: Страны, per-country уровни, гранулярность и стили подсказок (PR-C+) (#291)

- **checkout**: Drop the inline required caption under the field and the pickup trigger (#274) (#279)

- **location**: Режимы поля и арбитраж уровня «регион» — серверная половина (задача 13, #294) (#304)

- **location**: Related-list and ajax-select2 field modes, consume persisted:false (#295) (#306)

- **location**: Политика дефолтного населённого пункта (задача 14, D11) (#307)

- **pickup**: Адрес выбранного пункта рядом с кнопкой и отрисовка триггера в обоих местах (#274) (#308)

- **pickup**: Точки и выбор адресуются ключом локации (задача 15, #159) (#312)

- **location**: An implicitly defaulted locality is marked as not the customer's choice (#317)

- **location**: Country falls back to the store setting, then to a filterable default (#320)

- **checkout**: A plugin can replace the "invalid value" message too, not only the "required" one (#328) (#341)

- **checkout**: Make §8's single-package shape a configuration outcome (#325) (#348)

- **shipping**: «Доставка» tab surface — disabled controls, composite handler, tab registrar (#363)

- **shipping**: «Поля» — настройки, политика через два шва WC, восстановление инвариантов (#367)

- **shipping**: «Карта» — настройки карьерной точки, JS-политика полей на чекауте (#368)

- **shipping**: «Подсказки для адреса», серверная сторона hide_for_pickup, полная верификация #362 (#372)

- **location**: Add CAPABILITY_RESOLVE_KEY provider capability (#488 slice 1) (#493)

- **location**: Popular-settlements table, store and enrolment (#488 slice 2) (#496)

- **location**: Lazy-verify a stale popular-settlement pick at /select (#488 slice 3, D5-D7) (#500)

- **shipping**: Add the «Инструменты» section registry to the «Доставка» tab (#508)

- **location**: Show the popular-settlements list to the customer (#530) (#535)

- **build**: TypeScript by default for src/, migrate-on-touch (#544)

- **location**: Server-side guard for the custom-settlement opt-in (#531) (#545)

- **settings-page**: Add Settings_Provider::create_with_sections() (#570) (#595)

- **tests**: Announce when the running PHP is outside the CI matrix (#609) (#629)

- **location**: A provider must serve the settlement level for every country it lists (#353) (#651)

- **pickup**: Distance labels and unit system move from JS to PHP i18n (#646) (#656)

- **location**: The shopper is told when the search widened past their scope (#361) (#657)

- **location**: Warn on Woodev pages when the fixed default locality is stale (#410) (#661)

- **location**: Providers now self-report how they narrowed a `within` parent (#358) (#664)

- **location**: Forget-path and WP privacy exporter/eraser for customer location (#356 part 1) (#665)

- **location**: The country clear now reaches the server (#356 part 2) (#666)

- **location**: Retention TTL for a stored customer location (#356 part 3) (#667)

- **shipping**: Phone input mask on the classic checkout (#503) (#702)

- **checkout**: Make the place-order block optional and rename the fields section (#727)

- **shipping**: Add COD/insurance/declared-value capability flags + gateway coordination hook (#713) (#757)

- **docs-gate**: Fail on a relative link to a file that does not exist (#644 part 1)

- **docs-gate**: Pin the gotcha count in GOTCHAS.md's own header (#644 part 1)

- **scripts**: Add the signature-compatibility probe for migrating plugins (#770)

- **shipping**: Render the method Description on the CLASSIC order form too (#772)

- **i18n**: Gate the translation catalogue against the sources, not just itself (#791) (#794)

- **pickup**: Add Abstract_Bulk_Point_Source, migrate the four bulk fixtures (#797)

- **shipping**: SP-10 orders page increment 1 — registry, provider descriptor, dual-datastore row scope, REST rows (#821)

- **shipping**: SP-10 increment 2a — row payload and the canonical delivery status (#822)

- **shipping**: SP-10 increment 2b — the orders page as a native WooCommerce admin page (WIP, 3 defects listed) (#823)

- **shipping**: SP-10 #828 foundation — delivery-status sync freshness seam (#831)

- **fixtures**: Register a second Orders_Provider on woodev-test-shipping-method (#830) (#833)

- **shipping**: SP-10 increment 7 — the filter row, client half (#826, #827) (#832)

- **fixtures**: Seed enough demo orders to exercise pagination, and fix the aggregate tracking filter

- **shipping**: Правила «равен / не равен» и фильтр по пункту выдачи на странице заказов (#836) (#844)

- **shipping**: Панель «Статус данных» в ряду фильтров страницы заказов (#828) (#845)

- **shipping**: «новый заказ» как понятие фреймворка — фильтр is_exported (#841) (#846)

- **shipping**: Ссылки «Все / Новые» со счётчиками над таблицей заказов (#841) (#854)

- **shipping**: Свой контрол «Период» со «Всё время» и счётчики перевозчиков по периоду (#855) (#857)

- **shipping**: Счётчик новых заказов в пункте меню + разбивка по перевозчикам (#834) (#852)

- **shipping**: Статусы доставки — бейдж на палитре WooCommerce, слово перевозчика в тултип (#859)

- **shipping**: Status labels roughly equal length (#862 part 1) (#863)

- **shipping**: Колонка «Действие» — построчные выгрузка/обновление/отмена (#824, #860) (#870)

- **orders**: Сторож многомаркерного заказа и явная вселенная гейта (#928, волна 1) (#932)

- **orders**: Маркерная область → запрос id и post__in на всех путях (#928, волна 3) (#934)

- **orders**: «Все / Любое» (#843) + DB-level resolver test on both datastores (#936) (#937)

- **framework**: Refuse to activate a second plugin with a claimed download id (#916) (#944)

- **shipping**: Return an Action_Result from export/cancel/update so the carrier's reason reaches the merchant (#976) ⚠ BREAKING

- **shipping**: Admin pickup-point list and detail routes with an explicit destination (#710 I2b) (#980)

- **shipping**: One order-persistence core for classic, Store API and admin; carrier marker contract; order create/edit service (#710 I1a, I1b, I3) (#978)

- **shipping**: Rate calculator and route for an admin-built package (#710 I2a) (#984)

- **shipping**: The order wizard — create and edit an order from the shipping orders page (#710 I4a–I8) (#986)

- **shipping**: Order wizard step «Address» applies the checkout's field policy (#985) (#993)

- **shipping**: Order wizard edit lock on WooCommerce's _edit_lock + double-click guard (#982) (#995)

- **shipping**: Order wizard fills the delivery address from the chosen pickup point, like the checkout (#992) (#998)

- **shipping**: Export an order to the carrier at most once (#945) (#1005)

- **shipping**: API timeouts by call purpose, delayed export retry with a cap, 429 Retry-After (#954) (#1008)

- **shipping**: Background auto-export by order status, auto-cancel at the carrier, exports-in-progress notice (#1007) (#1010)

- **orders-page**: Show all orders by default, keep the badge a work counter (#1011) (#1020)

- **settings**: One composite woodev-settings tab per carrier plugin (#1014) (#1027)

- **pickup**: Show the point type chip (ПВЗ / Постамат) in search point results (#181) (#1043)

- **pickup**: Structured per-day schedule on the pickup point (#152, variant a) (#1050)

- **pickup**: Local-time info tooltip beside the Opening hours heading (#152) (#1053)

- **pickup**: Accent colour field on the «Карта» settings tab (#379, variant b) (#1051)

- **shipping**: Additional-info block as a card, shown only for the chosen method (#1052) (#1054)

- **location**: Tell the merchant when DaData refuses requests (exhausted balance / daily limit) (#956) (#1059)

- **shipping**: Opt-in cache of successful rate calculations (#958) (#1063)

- **setup**: Wizard stepper a11y and honest complete/skip failures (#1047) (#1061)

- **shipping**: Default dimensions and weight for products without them (#955) (#1064)

- **location**: Name the cause of a DaData 403 via the balance API (#1060) (#1068)

- **location**: Reopening the settlement field after the customer's pick searches by its name (#1071) (#1072)

- **location**: Location suggestions in the classic cart shipping calculator (#331) (#1074)

- **location**: Location suggestions on the My Account address forms (#332) (#1076)

- **shipping**: Carrier order helpers — pack the order at export, reconciled order lines (#948) (#1079)

- **shipping**: Warn in the order metabox when the order changed after export (#947) (#1080)

- **error-reporting**: Opt-in anonymised PHP error reporter for our plugins (#130) (#1083)

- **error-reporting**: Browser errors and woodev_pickup_error via our REST route (#1081) (#1084)

- **shipping**: Validate Store API pickup selections before payment (SP-11 C-2a, #1088) (#1094)

- **location**: Locality chooser in the WooCommerce block checkout (SP-11 C-1, #1087) (#1097)

- **shipping**: Pickup-point button and map modal in the block checkout (SP-11 C-2b, #1089) (#1099)

- **loader**: Shipping plugins are block-checkout compatible by default (#1121) (#1125)

- **location**: DaData delivery ids for carrier adapters — FIAS-keyed, RU-only fallback (#1136) (#1137)

- **shipping**: Carrier documents (#1134), buyer delivery-status emails (#714), merchant-box packing (#1138) (#1140)

- **shipping**: Carrier packaging redesign + carrier settings (delivered status, logging, hide on cart, «Выгрузка заказов») (#1141)

- **shipping**: Fee only for chosen payment methods (#1144) (#1150)

- **location**: Get_record_in_language() — a record in a given language (#1152) (#1153)

- **plugin**: One 'not configured' notice for every Woodev plugin; no nameless shipping methods (#1154)

- **shipping**: Min/max delivery cost for calculated-price carriers; bold plugin name in the not-configured notice (#1159)

- **shipping**: S160 pool — insurance seam, instance-form show_if, clear-address option, block address suggestions (#1165)

- **shipping**: Order actions with input fields + extra metabox/row fields (#1180) (#1183)

- **shipping**: City limit for shipping methods and WooCommerce's own «Самовывоз» (#1176) (#1184)

- **setup**: Setup Wizard v2 engine — step contract, server-side step graph, custom step components (#109) (#1186)

- **shipping**: Order actions UI — metabox documents via REST, one button group, per-action icons, red destructive (#1190) (#1193)

- **shipping**: Carrier documents without a second click + bulk print for selected orders (#1191, #1192) (#1196)

- **shipping**: Carrier seams for a bulk courier call — row flags, toolbar actions with tabbed dialog, field help, confirm text (#1197)

- **shipping**: Carrier-declared additional services in the rate and the export, with a quote snapshot (#1145) (#1200)

- **settings-page**: Groups inside a section + carrier extension of «Дополнительно» (#1201) (#1202)

- **settings**: «Документы для печати» card + «Статус отменённого заказа» (#1203) (#1206)

- **shipping**: Carrier-neutral shipment facts — cost, date, issue, courier (#1205) (#1208)

- **orders**: In-transit cancel — no doomed request, «Оформить отказ» seam, order-screen warning (#1204) (#1207)

- **setup**: Optional short step label for the wizard stepper (#1211)

- **packer**: Retire the single-axis box from the choice; pack leftovers with the virtual box (#1212) (#1213)

- **packer**: Merchant boxes accept a set only when it can really be placed (#1218)

- **pickup**: Admin point requests carry the shipping method and instance (#1227)

- **pickup**: Refuse a parcel locker whose cells cannot hold the order (#1219)

- **competitors**: Carrier competitor notices shared across Woodev plugins (#1230)

- **shipping**: A method class can declare that its price always depends on the payment method (#1239) (#1242)

- **shipping**: Support order tracking URL metadata (#1245)

- **shipping**: Admin-only checkout hint for a hidden shipping method (#1238) (#1246)

- **shipping**: Carrier-declared document types and labels (#1257) (#1258)

- **licensing**: Gate checkout and carrier actions on a server-signed licence (#1270) (#1271)

- **shipping**: Carrier-titled cards in the export settings section (#1277)

- **shipping**: Hidden-method hint — instance titles, one line per reason (#68) (#1276)

- **shipping**: Order-action select that loads its options when the dialog opens (#1285)

- **error-reporting**: One DSN per plugin, events routed to the owner's project (#1302) (#1303)

- **assets**: Dead-asset cleanup, then minified css/js with a SCRIPT_DEBUG suffix helper (#247) (#1305)


### Orders_Provider
- Проверка полноты method_ids под WP_DEBUG (#842) (#904)


### Performance
- **pickup**: Skip the list rebuild when setSelectedId gets an unchanged id (#187)


### Refactoring
- **payment-gateway**: Remove dead Admin_User_Edit_Handler (OB-6) (#52)

- **updater**: OB-3 Step 5 — move plugin-updater under licensing/updater/ (#61)

- **wizard**: Move setup wizard onto the shared UI-kit (UK-3/UK-4) (#99)

- **pickup**: Drop unreachable _searchGeocodeProvider() (#183)

- **location**: Remove superseded address-normalizer seam and dead dadata assets (#311) ⚠ BREAKING

- **location**: Remove the unreachable related-list:settlement renderer (#550)

- **settings**: Name the section KIND at the call site, and validate tools at the door (#563)

- **i18n**: Customer-facing msgids become English, catalogue rebuilt (#567) (#645)

- **i18n**: The location registry's nine admin msgids become English (#567) (#654)

- **shipping**: Align the four class-map outliers with their directories, gate the generator on it (#647) (#658)

- **admin**: Drop the dead license_prompt key from woodev_admin_strings (#669)

- **location**: Drop the /location/list hard cap and fix the settlement tooltip (#437) (#700)

- **shipping**: Remove the warehouse scaffold (#726) ⚠ BREAKING

- **tests**: Share one WP_REST_Request double and restore REST type hints (#730)

- **shipping**: Order-edit metabox dispatches through Order_Actions::perform() (#1016) (#1022)


### SP-10
- Мёртвый CSS убран, зеркало подписей PHP↔TS под гейтом (#869, #867) (#889)


### SP-11
- C-4 Checkout Blocks acceptance + payment-retry pickup restore (#1091, #1101) — PARKED for operator acceptance (#1116)


### Tests
- **licensing**: Stub get_support_url on dispatcher hook-failure test mock (#42)

- **fixture**: Draw 2GIS tiles through the D-8 layers/copyrights seam (#190)

- **fixture**: Close the picker on a confirmed selection (#192)

- **pickup**: Drive the rig from real Yandex.Delivery pickup points (#194)

- **base**: Reverse the #244 handler extraction, keep its tests and correct the tracker (#258)

- **pickup**: Pin the viewport refresh() pool invariant instead of "fixing" it (#262)

- **integration**: Make location and pickup route tests establish their own preconditions (#293) (#302)

- **shipping**: Integration proof for Provider_Selection_Scope write/read agreement (#314)

- **rig**: Add a live CDEK location provider to the rig fixture (#343) (#347)

- **location**: Cover the popular-settlements table against a live MySQL (#513)

- **shipping**: Pin the popular-settlements store default on Shipping_Admin_Order (#516)

- **location**: Pin the measured select2 pick event order (#525) (#549)

- **integration**: Bring the last stray class onto the shared base, and pin that structurally (#564)

- Raise phpunit's memory_limit to 512M so a red run prints its failures (#632) (#635)

- **admin-notices**: Put Woodev_Admin_Notice_Handler under test, then modernise it (#653) (#659)

- **licensing**: Stub command ack option read (#680)

- **e2e**: Pin the classic-checkout walkthrough that 20+ sessions ran by hand (#724)

- **fixtures**: Serve pickup points for more than one locality (#729)

- **fixtures**: A SECOND rig carrier with its own pickup points (#734) (#735)

- **since**: Gate @since tags in woodev/** against a machine-readable ceiling (#754)

- **755**: Pin the clock in LocationControllerTest's real rate-limit test (#756)

- **loader**: Pin the entry-path contract every fixture was teaching wrongly (#763)

- **loader**: Give the v2 entry path a real consumer inside the repo (#763)

- **shipping**: Mount the realistic shipping fixture in the integration environment (#817)

- **shipping**: Pin a single export-retry hook binding across carrier plugins (#1009) (#1021)

- **shipping**: Pin perform(EXPORT) handing the recalled settlement and provider to the handler (#1026) (#1030)

- **js**: Stop the wizard and modal suites waiting out real timers (#1042) (#1045)

- **location-picker**: Fake timers instead of real debounce waits (#1056) (#1066)

- **error-reporting**: Fix clock-dependent absence needles (#1092)

- **admin-orders**: Cover the location check in Order_Payload_Validator (#1224) (#1231)

- **shipping**: Guard setAccessible behind PHP_VERSION_ID < 80100 in ShippingOrderItemMetaTest (#1233)

- **yandex**: Method-id contract as "old names are rewritten into new" (#1241) (#1243)

- **shipping**: Wire the payment-dependent price through add_hooks (#1244) (#1247)

- **e2e**: CDEK + Yandex coexistence specs for the five D9 scenarios (Y15) (#1259)

- **shipping**: Carrier-card export field save round-trip (#1278)

- **e2e**: Two-carrier acceptance matrix (#1179) + locked-order 409 test (#1291)

- **e2e**: Unwrap the fixed matrix defects M1–M3 (#1294)

- **e2e**: Unwrap M3 after the courier region fix (#1295)


### Build
- **hooks**: Gate GitHub closing keywords in commit messages

- **orca**: Make a fresh worktree gate-capable without any install step

- **orca**: Keep agent worktrees inside the project at orca/worktrees

- **orca**: Move agent worktrees to .orca/worktrees, matching the global setting

- **orca**: Copy plugins-reference into every worktree so its gate matches CI (#424)

- **deps**: Bump taiki-e/install-action from 2.86.5 to 2.87.0 (#715)

- **deps**: Generate the vendored IMask bundle from a devDependency (#728)

- **deps**: Bump taiki-e/install-action from 2.87.0 to 2.87.5 (#825)

- **deps**: Bump taiki-e/install-action from 2.87.5 to 2.87.11 (#898)

- **deps**: Bump taiki-e/install-action from 2.87.11 to 2.87.15 (#915)

- **deps**: Bump taiki-e/install-action from 2.87.15 to 2.87.20 (#994)

- **deps**: Bump taiki-e/install-action from 2.87.22 to 2.87.27 (#1284)


### I18n
- **ru**: Drop the double space in the milestone review prompt (#1225)


### Revert
- **licensing**: Drop the WC Admin Notes breadcrumb (s12 Finding B) — operator decision (#46)


### Style
- **imports**: Group same-namespace use statements (#1229)


### Лицензии
- Download id без товара — не коллизия, а команды для него отклоняются (#905) (#908)

- Настоящие download id у фикстур, отказ на неоднозначный id в админском REST (#917)



## [2.0.0] — 2026-06-12
### Bug Fixes
- Use tests-cli container name for wp-env run commands

- Set WP_ENV_HOME at job level so all steps find the environment

- Resolve 2 failing integration tests

- Remove duplicate custom_dir from mkdocs.yml root level

- **dependencies**: Use function_exists instead of extension_loaded in get_missing_php_functions()

- **s3**: PHPStan baseline cleanup — 410 errors → 0, fix 4 code bugs

- **licensing**: Remove wc date helpers from license messages

- **utilities**: Remove wc enqueue helper from job batch handler

- **admin**: Remove wc doing it wrong helper from setup wizard

- **helper**: Guard early doing it wrong fallback

- **helper**: Add phase 5 platform-neutral fallbacks

- **licensing**: Preserve WooCommerce helper contracts

- **payment-gateway**: Restore base methods removed by v2 cleanup

- **payment-gateway**: Use instanceof guards for hosted payment notification dispatch

- **box-packer**: Split Woodev_Box_Packer_Item into base + With_Product contract

- **shipping**: Narrow Shipping_API interface to base framework contracts

- **plugin**: Move get_woocommerce_uploads_path to Woodev_Woocommerce_Plugin with deprecation shim

- **plugin**: Make Woodev_Plugin::\ nullable for pure-WP subclasses

- **payment-gateway**: Add explicit nullable types to 13 legacy method parameters

- **framework-resolver**: H2/H3/H4 — inject notice callbacks, idempotent load, plugin_id dedup

- **bootstrap**: M-2 — delegate is_woocommerce_active() to Woodev_Helper

- **framework**: M-4 — move add_class_form_wrap_* to Woodev_Woocommerce_Plugin

- **plugin**: Make B-2 shim reference the FQCN \\Woodev\\Framework\\Woocommerce_Plugin

- **resolver**: Apply P3 clean-break audit findings (GPT-5.5), verified

- **p4**: Declare HPOS/Blocks compat early via loader metadata (GPT-5.5 audit), verified

- **p6**: Close split-done audit findings — base REST neutrality, plugin-file contract, HPOS (GPT-5.5)

- **autodev**: Robust native-command runner + anti-drift; record bootstrap digest

- **autodev**: Process-driven watchdog, persist worker output, literal locator match

- **autodev**: Scheduler honors depends_on (dependency DAG, not just file-sets)

- **autodev**: Critic robustness + gate-as-lock (worker no longer self-commits)

- **autodev**: Scope gateway_id zone to payment-gateway path; drop generic $this->id= grep that false-tripped shipping value objects

- **s1**: Pickup_Selection is session-only — session key and order-meta prefix are distinct contracts

- **autodev**: Scope gate zone-detection to task file_set + refund attempt on rate-limit

- **s1**: Order-handler spec — plugin supplies full meta-key map, framework hardcodes no suffix

- **autodev**: Refund attempt on critic rate-limit (exit 4) so codex 429s never false-poison a DONE task

- **autodev**: Gate RETRY returns task to pending, not active

- **autodev**: Invoke-critic must not misread benign repo text as a 429

- **autodev**: Expose Pickup_Point id wire alias for shipped pickup-map.js (resolves ajax-base)

- **autodev**: Shipping_API::get_response() is nullable (resolves abstract-api)

- **autodev**: Complete Shipping_Plugin wiring + session->order pickup handoff (resolves R1)

- **autodev**: Loop hardening per the 2026-06-11 tooling review (#30)


### CI/CD
- Phase 1 upgrades — PHP 8.3, security audit, Dependabot

- Add integration tests workflow with wp-env and WooCommerce

- Add GitHub Pages deployment with MkDocs Material theme

- Add WP × WC × PHP version matrix to integration tests

- Auto-merge Dependabot PRs when all checks pass

- Fix security-audit and scope markdown lint to published docs


### Documentation
- Add CI status badges to README

- Overhaul GitHub Pages with custom hero and dark theme

- Auto-inject framework version into docs at build time

- Add Woodev branding, ecosystem links, and auto-release version

- Add plugins showcase section to home page

- Convert plugins grid to infinite auto-scrolling carousel

- Replace carousel with 3-column plugin grid

- Replace WB placeholder with real plugin data from woodev.ru

- Add Licensing & Updates card to feature grid

- Fix code examples, add <?php tags, fix markdown lint

- Verify and fix all code examples against framework source

- **s1**: Establish docs-internal/ structure, create AGENTS.md, refactor CLAUDE.md

- **s2**: Populate gotchas with 10 atomic files from codebase analysis

- **s2**: Update session state after legacy cleanup

- **s3**: ECheck/ACH audit — 14 files, 5-phase removal plan

- **s3**: Add gotcha for missing gateway type methods, update session log for JS/CSS cleanup

- Restructure PLANS.md refactor brainstorm into clear sections

- **platform**: Align v2 strategy planning

- **platform**: Analyze v2 resolver strategy

- **platform**: Add v2 implementation spec

- **platform**: Add next session prompt

- **platform**: Keep next prompt in chat

- **platform**: Record feature compatibility session

- Record Phase 5 admin notice cleanup commit hash

- **platform-v2**: Add reference migration contract draft

- **platform-v2**: Add second reference migration draft

- **platform-v2**: Reconcile roadmap with framework-first strategy

- **audit-2026-06-01**: Record second-model independent audit findings

- **audit-fixes**: Record resolution of B-2, B-3, H1 + add new tests

- **framework**: L-1, L-5, L-6 — sync docblock + comment cleanups

- **audit-fixes**: Record 12/13 deferred audit resolutions + abandoned L-2 5th test

- **platform-v2**: Record direction audit + namespace/clean-break course correction

- **plan**: Platform v2 clean-break plan, base-decomposition sub-plan, execution protocol + program tracker

- **policy**: Reconcile CLAUDE.md/AGENTS.md to clean-break (D-2); add ADR-005

- **review**: P2 pilot external-audit packet; tracker -> P2 ext-audit pending

- **tracker**: P2 gate passed (ext audit applied) -> P3 next

- **tracker**: P3.1 fixtures converted; 3.2-3.5 deletions next

- **review**: P3 clean-break external-audit packet; tracker -> P3 ext-audit pending

- **tracker**: P3 gate closed (audit fixes verified); P4 started — Translation_Handler done

- **autodev**: Add autonomous adversarial dev loop runbook + impl brief

- **p4**: Cron_Handler extracted; record decision to NOT extract action-links/api-logging (polymorphic)

- **state**: Record P6 gate evidence for Woodev_Plugin base size after handler extraction

- **wiki**: Update extension-point article index line after seam removal

- **review**: P4 decomposition external-audit packet; tracker -> P4 ext-audit pending

- **autodev**: Incorporate external review (Planner role, repo-aware critic, mutation recipes, anti-drift depth)

- **tracker**: P4 gate passed (HPOS-timing fix verified); P5 next, orphan cleanup tracked

- **p5**: Verify resolver minimality + responsibility table (ADR-003); no extraction needed

- **review**: P6 split-done external-audit packet; all verification checks green

- **tracker**: P6 fixes applied+verified (195/592); awaiting tag decision

- **tracker**: S0 platform split COMPLETE — tagged platform-v2-split-done; S1 next (autodev loop)

- **cleanbreak-plan**: Mark plan SUPERSEDED + Phase 3 DONE; point to tracker

- **s1**: Planning brief for shipping module spec + autodev decomposition

- **s1**: Resolve 3 pending yandex contracts (EDD 821, warehouse DDL, cron payload)

- **s1**: Correct yandex id-derived contract values to live (operator maksim)

- **autodev**: Gotcha for critic-429 false-poison + record Q3 resolution

- **autodev**: Session log — 2 escalations resolved + critic-429 false-poison fix (2026-06-06)

- **autodev**: Session-save — refresh digest + CURRENT-STATE (escalations closed, Q3 fixed)

- **autodev**: S1 complete — close 6 escalations + unblocked chain; record session

- **autodev**: SESSION-LOG entry for the 2026-06-07 S1-completion session

- **autodev**: Holistic integration review + R1/R2 remediation record

- **autodev**: Session save — PR #20 CI fixed (green), 6 CI/test gotchas

- **session-2**: Dispatcher wiring + warehouse redesign session save + next-session prompt

- Formalize Capability-Gated Feature Seam pattern (wiki + ADR-006)

- Next-session prompt — shipping conformance audit vs capability-gated seam

- **s4**: Next-session prompt (S3 Licensing fork) + reconcile stale program-tracker

- **s5**: Mark S3.1 is_need_license MERGED (#25) + next-session prompt (S3 sub-stage 2/3 fork)

- **s5**: Triage Fable 5 architecture review + autodev model re-tiering

- **s7**: Fold Fable findings B-3/B-4/B-6 into the S3.1 licensing spec (#28)

- **s7**: Session save — CURRENT-STATE/SESSION-LOG/tracker + 2 gotchas (#29)

- **s8**: Session save — S3.2 merged (PR #31) + 2 gotchas (#32)

- **s8**: Next-session prompt — s9 = S3.3 webhooks + section-4 signing (#33)

- **s9**: Tracker + state — PR #35 merged, S3 complete (#36)


### Features
- **platform**: Add resolver facade loader

- **platform**: Namespace resolver slice

- **platform**: Move WooCommerce feature state

- **platform**: Move WooCommerce system status ownership

- **platform**: Move WooCommerce logger ownership

- **platform**: Move WooCommerce template ownership

- **platform**: Move WooCommerce feature compatibility ownership

- **autodev**: Scaffold adversarial dev-loop infrastructure (conductor suite + blackboard)

- **s1**: Commit planner artifacts — shipping spec, queue manifest, 29-task autodev queue

- **shipping**: Add Address_Normalizer interface + Null default

- **autodev**: S1 shipping module complete — clean-break + shipping module via adversarial loop

- **s2**: Dispatcher production wiring + shipping packing seam + warehouses REST redesign (#22)

- **s3**: Weave box-packing into shipping rate-calc (single-seam template) (#23)

- **s3**: Licensing sub-stage 1 — is_need_license safe-scaffold (#25)

- **bootstrap**: B-1 mixed v1/v2 fleet hard-gate (probe + register_plugin tombstone) (#27)

- **s3**: S3.2 modern license-page UI — React card grid + woodev/v1 REST, legacy Settings form removed (#31)

- **s3**: S3.3 built-in webhooks + §4 Ed25519 signing (remote deactivate, signed claims, pull-fallback acks) (#35)

- **s3**: Embed production License_Authority public key (#37)

- **licensing**: Send framework_version on every licensing API request (#38)

- Bump framework version to 2.0.0 (#39) ⚠ BREAKING


### Refactoring
- **v2.0.0**: Remove dead compat code, deprecated methods, and US-specific payment types ⚠ BREAKING

- **v2.0.0**: Remove eCheck from JS, CSS, and dist assets

- **platform**: Remove wc deprecation wrappers from base modules

- **platform**: Decouple settings API helpers from WooCommerce

- **platform-v2**: Remove licensing woocommerce helpers

- **platform-v2**: Decouple lifecycle event sanitization

- **platform-v2**: Decouple beta opt-in helper

- **platform-v2**: Decouple dependency size parser

- **platform-v2**: Decouple admin notice dismiss JavaScript

- **platform-v2**: Decouple settings API error paths

- **helper**: Split Woodev_Woocommerce_Helper off Woodev_Helper (M-1, L-4)

- **licensing**: Split Woodev_Woocommerce_License_Settings off Woodev_License_Settings

- Delete legacy positional registration path ⚠ BREAKING

- Delete internal-API class aliases and deprecated shims ⚠ BREAKING

- **base**: Extract Translation_Handler from Woodev_Plugin

- **base**: Extract Cron_Handler

- **base**: Remove the add_woocommerce_hooks WooCommerce seam from the platform-neutral base

- **p4**: Remove orphaned handle_features_compatibility; migrate WP-neutrality coverage to bootstrap

- **autodev**: Pickup-map.js core (MapAdapter contract) + Leaflet adapter + css

- **autodev**: Map_Provider interface + registry + Leaflet default (PHP descriptor)

- **autodev**: Pickup_Point + Warehouse value objects (pure, immutable)

- **autodev**: Checkout_Fields — custom checkout field definitions (pure)

- **autodev**: Pickup_Point_Source interface + Pickup_Point_Filter (sourcing axis)

- **autodev**: Pickup_Selection — session-only chosen-point WC session persistence

- **autodev**: Checkout_Handler — field injection, posted-data, validation orchestration

- **autodev**: Warehouse_Store interface + abstract table-backed default

- **autodev**: Wire PVZ selection into Shipping_Method_Pickup (extend existing marker)

- **autodev**: Shipping_Order_Handler — HPOS-safe order-meta read/write (plugin-supplied key map)

- **autodev**: Abstract_Tracking_Handler — tracking model + display hooks

- **autodev**: Abstract_Webhook_Handler — inbound receiver scaffolding (spec §4.3)

- **autodev**: Shipping_Method rate-cache pre-filter + get_pickup_point_source() seam

- **autodev**: Abstract_Shipment_Handler — create/cancel/export with retry

- **autodev**: Shipping_Admin — admin suite bootstrap

- **autodev**: Shipping_REST_API — REST extension bootstrap

- **autodev**: Abstract_Pickup_Points_Controller — pickup-search REST controller base

- **autodev**: Wire get_pickup_point_source() override into Shipping_Method_Pickup

- **autodev**: Shipping_Admin_Order — render tracking in metabox, not before redirect (resolves admin-order)

- **autodev**: Status view reads is_configured() from the integration (resolves status-view)

- **autodev**: Warehouse_Admin builds submenu URLs under admin.php (resolves warehouse-admin)

- **autodev**: Pickup checkout handler + modal/balloon + checkout.js (resolves p2-pickup-checkout)

- **autodev**: Register new shipping subsystems in Shipping_Plugin (integration)

- **autodev**: Extract shared pilot-fixture test scaffold (resolver + WP stubs)

- **autodev**: Pickup map JS must honor the AJAX success flag (no false selection)

- **box-packer**: S2 — WC-neutral core + minimal virtual-box algorithm + tests (#21)

- **shipping**: Supports_*() predicate wrappers + host-facing supports() docs (pattern conformance) (#24)

- **licensing**: Cheap-check-first condition + ?? over isset (#34)


### Tests
- Add comprehensive integration tests for WP/WC environment

- Fix integration tests based on real WP/WC environment findings

- **platform**: Cover specialized callback timing

- **platform-v2**: Add realistic payment fixture for runtime validation

- **framework-resolver**: L-2 coverage + docs(wiki): L-3 extension-point pattern

- **pilot**: Edostavka-shaped fixture validates the new load path + data-preservation checklist

- **pilot**: Harden P2 fixture/test per external audit (GPT-5.5)

- **fixtures**: Convert woodev-test-* fixtures to explicit loader definitions

- **autodev**: Write mutation-verified edostavka contract guards (shipping method id + settings option key)

- **autodev**: Mutation-verified yandex contract guards (4 of 5; REST blocked)

- **autodev**: Yandex-shaped pilot fixture + validation gate (resolves fixture-yandex, Task B)

- Bundle framework into integration fixtures for the v2 resolver

- Map framework into integration fixtures via wp-env (fix resolver load)

- Make Unit suite CI-robust (gitignored reference + function pollution)

- SetAccessible for reflection on PHP 7.4/8.0 (version-guarded)


### Style
- **framework**: M-3 + M-5 — docblock annotations + indentation fixes



## [1.4.1] — 2026-03-12
### Bug Fixes
- Enforce WordPress coding standards across framework codebase

- Resolve static analysis and code quality issues

- Resolve all PHPCS errors blocking CI

- Suppress PHPCS warning exit code in CI

- Increase PHPStan memory limit to 1G for WooCommerce stubs

- Disable PHPStan parallel workers to prevent OOM crashes

- Use 2G memory limit for PHPStan with WooCommerce stubs

- Correct PHPStan type error in token editor filter docblock

- Add setAccessible(true) for PHP 7.4/8.0 Reflection compatibility

- Add missing setAccessible(true) in test_singleton_reset_via_reflection


### CI/CD
- Fix failing GitHub Actions workflows


### Documentation
- Expand CLAUDE.md, revise AI agents/skills, and update tooling config


### Features
- **payment-tokens**: Auto-invalidate token cache on WooCommerce token events

- **shipping**: Implement admin notice handlers for countries, debug mode, and plugin configuration


### Refactoring
- Move Woodev_Plugin_Compatibility to compatibility/ directory


### Tests
- Restructure fixtures and improve integration test bootstrap




