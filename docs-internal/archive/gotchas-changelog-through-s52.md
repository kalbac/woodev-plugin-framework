# GOTCHAS.md changelog archive — entries through s52

Moved out of the live index header in the s60 docs audit; newest entries stay in GOTCHAS.md.

> Prior: 2026-08-06 (session 52, rig defect on the restore path: +1 file, existing namespace
> `[shipping/pickup]` — `ymaps-draw-then-move-parks-the-overlay` (`setBounds()` also ISSUES its camera
> command ~40 ms late, so a `setCenter()` sent in between is overwritten by the fit; and a camera move
> made across the ObjectManager's FIRST layout parks the marker's overlay at ymaps' off-screen sentinel
> `-32760px` — move the camera BEFORE drawing, and measure a marker's rect, not just its attributes)).
> Prior: 2026-08-06 (session 52, SP-5 Task 12: +1 file, existing namespace `[testing/js]` —
> `jest-toequal-empty-array-ignores-undefined` (Jest's `toEqual` ignores `undefined` array items, so
> `expect( someCallsArray ).toEqual( [] )` against one of this repo's plain-array call recorders PASSES
> even when a call happened with an `undefined` argument — found by deliberately removing a "point not
> found" guard in `restoreSelection()` and watching a "was never called" assertion stay green anyway.
> Use `toHaveLength( 0 )` instead; only a deliberate mutation reveals the gap, a normal run never does).
> Prior: 2026-08-06 (session 52, SP-5 Task 11: +1 file, new namespace `[tooling/git-checkout]` —
> `git-checkout-destroys-uncommitted-mutation-revert` (reverting a deliberate-regression mutation with
> `git checkout <file>` restores from HEAD, not from "before the mutation" — with the implementation
> still uncommitted that deletes the whole task's work in that file, unrecoverably, and the resulting
> wall of failures in tests unrelated to the mutated line reads as "the mutation broke everything".
> Commit the green implementation first, then mutate). Recovered from a `cp` backup taken minutes
> earlier, by luck.
> Prior: 2026-08-06 (session 52: +1 file, new namespace `[testing/js]` —
> `npx-jest-bypasses-wp-scripts-jsdom` (this project has NO jest config of its own; JS tests run
> through `wp-scripts test-unit-js`, which is what supplies the jsdom environment. `npx jest`
> bypasses it and falls back to jest's node default, reporting **194 failed / 472 total** where
> the truth is **631 passed / 631 total** — and the dropped TOTAL is the tell, because suites
> that fail to load never contribute their tests. The failure names your own files and shows
> plausible diffs, so it reads as "I broke the JS layer"; it had been written into all seven JS
> tasks of the s52 plan before being caught).
> Prior: 2026-08-05 (session 51, operator manual-test pass on the pickup map: +4 files —
> `ymaps-html-icon-layout-anchors-at-its-top-left` (a custom HTML icon layout draws with its top-left
> corner AT the geo anchor while `iconShape` is measured CENTRED on it, so the drawn artwork and the
> clickable rectangle overlap only in one quadrant — the direct sequel to s50's `iconShape` fix, which
> made markers clickable but not, it turns out, over most of their own artwork), `ymaps-suggest-not-
> geocode-for-address-lists` (the address search used `geocode()`, which ranks POIs and returned a
> metro station for an exact street+house query; needs `ymaps.suggest()` plus its `value` field, not
> `displayName`, which reads right but is reversed and still carries the country),
> `bounding-the-address-resolve-breaks-the-normal-case` (self-inflicted: bounding the SUGGEST/GEOCODE
> calls to the pickup-point area is correct, applying the same bound to `resolveAddress()` is not — a
> customer's own address is routinely outside the point coverage, that's why they're searching, and the
> bounded resolve silently returned nothing for a real address 14km from the fixture's points), and
> `ymaps-copyright-pane-is-trapped-in-a-stacking-context` (Yandex's ToS-mandated copyright strip
> ignores `margin.addArea()` entirely — even after fixing that call's zero-width bug — and sits inside
> an ymaps-owned container whose z-index, not the pane's own 5002, is what actually competes with the
> sidebar; shipped fix leaves a 32px gap, the cosmetically-preferred full-height panel was tracked as
> #168 — since closed in s54: floating sidebar card + mobile pass). Also extended `hostile-theme-button-display-none-needs-important` with the s51
> finding that its own `!important` guard silently beats a later plain `[hidden]` rule on the same
> property — the clear-search button stayed visible on an empty field until `display: none` was
> promoted to `!important` too.
> Prior: 2026-08-05 (live-review round 2, T1: +1 file —
> `ymaps-margin-area-needs-explicit-width` (`setMargin()` put the sidebar panel's pixel width into
> `right`, an OFFSET, and declared no `width` key at all — the area it reserved was ZERO pixels
> wide, so every `useMapMargin: true` camera move "worked" against a reservation that never
> existed. Copied faithfully from a design spec that specified the same wrong shape. Rig-measured:
> the focused point centred on the FULL map instead of the margin-adjusted half, and ymaps' own
> copyright strip sat entirely under the sidebar panel — invisible, against Yandex's ToS. No test
> existed for `setMargin()` at all before this fix)).
> Prior: 2026-08-04 (session 50, hostile-theme pass: +1 file —
> `hostile-theme-button-display-none-needs-important` (a theme's `!important` button-hiding rule
> beat our deliberately-`!important`-free reset, hiding every modal button including close; `display`
> alone is now promoted to `!important`, confirmed by re-testing that everything else in the reset
> still wins on ordinary specificity)).
> Prior: 2026-08-04 (session 50, mobile pass: +1 file —
> `mobile-inline-min-width-and-floating-control-stacking` (two defects only reachable by actually
> resizing a live page: an inline `minWidth` beating the mobile media query, and a z-index ordering
> that only mattered once the panels went full-width and started sharing screen space with the
> zoom control)).
> Prior: 2026-08-04 (session 50, rig verification continued: +1 file —
> `setanchor-resorts-but-never-shows-the-sidebar` (picking an address re-sorted the list correctly
> but never opened it, so a card left open from an earlier click stayed on screen over it — a new
> `openList()` makes visibility deterministic instead of riding along on a content update)).
> Prior: 2026-08-04 (session 50, rig verification continued: +1 file —
> `ymaps-objectmanager-setfilter-single-argument` (the filter callback takes one argument, not
> `(objectId, object)`; reading the second left `typeCode` always undefined, so picking any specific
> point type hid every marker on the map — the unit test that should have caught it called the stored
> function with the same wrong shape the code expected)).
> Prior: 2026-08-04 (session 50, rig verification: +1 file — `focusgroup-only-moved-for-clustered-points`
> (a camera-move helper built to escape a cluster was reused as the general click-parity primitive
> without re-checking its guard, so a plain marker click opened the card but never moved the map —
> found by clicking a real marker, since every test's fixture happened to make the bug look correct)).
> Prior: 2026-08-04 (session 50 continued: +1 file — `css-hidden-attribute-needs-explicit-override`
> (an element toggled via the `hidden` DOM property alone stayed visible forever, because its own
> `display: flex` rule tied the UA `[hidden]` rule at equal specificity and won by author precedence —
> found reviewing Task 17, fixed same session, issue #160 closed)).
> Prior: 2026-08-03 (session 50: +2 files — `ymaps-control-options-must-be-nested` (control
> options passed at the root of the constructor argument are silently dropped, leaving ymaps' default
> chrome AND its default worldwide geocoder) and `ymaps-html-icon-layout-needs-iconshape` (a custom
> HTML icon layout has no hit area without `iconShape`, so clicks fall through into the Yandex POI
> layer). Both are the same family as s49's find: a shape the library does not read, reported by
> nothing).
> Prior: 2026-08-02 (session 49: +1 file — `ymaps-objectmanager-properties-are-plain`
> (an ObjectManager layout receives feature `properties` as PLAIN JSON while a Placemark layout
> receives a data manager; calling `.get()` threw inside ymaps' cross-origin script, which the
> browser masks as a bare `Script error.` — every marker rendered empty and dragging span forever.
> The test helper modelled the Placemark shape, so 393 green tests saw nothing)).
> Prior: 2026-08-01 (session 48: +1 file — `modal-backdrop-opacity-dims-the-whole-dialog`
> (CSS `opacity` on a backdrop that is the dialog's ANCESTOR dims the whole dialog; three
> presentation defects on this branch were invisible to 391 green jest tests and visible in the
> first rig screenshot)).
> Prior: 2026-08-01 (session 47: +1 file — `ymaps-locale-region-drives-units` (the ymaps `lang`
> parameter's REGION half selects kilometres vs miles, so an `en_US` fallback silently switches the map
> to miles while our own sidebar keeps computing kilometres); and `ymaps-camera-moves-are-async` gained
> the degenerate case its own fix cannot solve — points on IDENTICAL coordinates cluster at every zoom,
> so collapsing the bounds never un-clusters them and `balloon.open()` throws forever).
> Prior: 2026-07-31 (session 46: +2 files — `ymaps-camera-moves-are-async` (a dropped `setBounds()` promise made the map be queried at its OLD viewport, and a clustered placemark has no balloon so opening it throws; a placeholder API key hides both because ymaps refuses geocoding while still serving tiles) and `fixture-classes-must-live-inside-plugin-init`; prior note: +1 file — `fixture-classes-must-live-inside-plugin-init`, from wiring the SP-5 fixture: a class that `implements` a framework interface at plugin-file top level fatals, because that code runs before the bootstrap registers the autoloader).
> Prior: 2026-07-31 (session 45: +5 files from SP-5 — `mutation-sweep-branch-only-false-confidence`, `phpcs-does-not-enforce-line-length`, `file-deletion-tail-includes-classmap-fixtures`, `js-store-instance-registry-cross-module`, `php-stdlib-traps-that-survive-tests`. Two of them, the mutation-sweep and phpcs ones, are about **what a green run does not prove** — read them before quoting a passing suite as evidence).
