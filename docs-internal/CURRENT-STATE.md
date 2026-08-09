# Current State — Woodev Plugin Framework

> Lean state doc: phase status, open bugs, next actions. **Full session history → `SESSION-LOG.md`** (newest on top). Program history snapshot → `platform-v2-program-tracker.md` (rewritten s60); active program map → `specs/2026-06-25-shipping-module-decisions.md`.
> Last updated: 2026-08-09 (s59 — **#234 НАКОПЛЕНИЕ ТОЧЕК СМЕРДЖЕНО, РЕШЕНИЕ ПО КРЫШКЕ ПРИНЯТО ЗАМЕРОМ.** **1520 unit / 800 jest / phpcs чисто / PHPStan чисто.** `main` = `0cd51c4`, **дерево чистое, открытых PR и веток нет**. Смерджены #239 (#234), #240 (#220) и #241 (мобильный оверлей — **оператор отревьюил лично: «багов не обнаружил, всё работает чётко»**). Закрыты #220, #230, #233 (обе половины), #234, #237, #242. **Постановка #234 была неверна дважды и это выяснилось до кода:** докблок `setPoints()` уже отдавал межзапросную дедупликацию вызывающему (правка целиком в `pickup-mount.js`, контракт `Map_Provider` не тронут), а карта с сайдбаром разойтись не могут (сайдбар = пул ∩ кадр). **Крышка не поставлена — по замеру:** пул 20 000 точек это `setPoints()` 334 мс и 8.8 МБ против листинга в 6–13 СЕКУНД, суперлинейный `setTypeFilter` (1017 мс) на viewport-путь не попадает вовсе; реально накапливается 1290 точек за тур по Москве и области, 4664 — за четыре области на `MIN_ZOOM`. Выведен фильтр `woodev_pickup_max_accumulated_points` (0 = без ограничения), потому что плотность точек — знание домена. **Критик Codex отработал дважды (по дизайну и по коду) плюс повторное ревью моих правок:** по дизайну — ложное «листинг побеждает» и отсутствующий генерационный барьер; по коду — пять находок, включая вытеснение по `Object.keys()` (это НЕ порядок вставки для числовых id Почты — выбрасывалась самая новая точка) и `(int)` на возврате фильтра, превращавший массив в `1`. **Возражение исполнителя вскрыло #238:** `getSession()` не вызывается из продакшена, значит `forgetPointDetails()` из #232 там не работал никогда; радиус мал — сессия пересоздаётся на каждое открытие модалки. **Риг:** зум 14 → сервер отдал 31 точку, на карте 350, в сайдбаре 25; зум +1/−1 → 348/348 сразу, до ответа сервера. **Мобильный:** оверлей сайдбара закрывал 94% экрана, снят — полоска прогресса уже лежит на верхней кромке сайдбара. **#230 закрыт замером** (0 срабатываний ловушки на 671 строку лога под увеличенной нагрузкой), ловушка снята из контейнера. **#214 НЕ сделан осознанно** — вариант 2 не покрывает собственные примеры карточки, нужен замер разброса. Заведены #237, #242. **#242 закрыт по решению оператора («ничего не делаем») ПОСЛЕ проверки его довода замером:** усечение самозалечивается при зуме внутрь — зум в район даёт точки, которых не было в урезанных 2000 (Бутово +1, Митино +3, Некрасовка +4 при листингах 16/24/29), то есть недостижимых точек нет; плюс 2000 точек на широком кадре рисуются 24 DOM-объектами. **🎯 NEXT = #238** (мёртвый `refresh()`), **#214 ждёт ответа оператора**. s60 делится на 10 — предложить аудит документов.)
> Older per-session headers (s42–s58) → `SESSION-LOG.md` (they were verbatim duplicates).

## Last session context (≤3 lines)

- **s59 (09.08.2026) — ЗАМЕР ВМЕСТО МНЕНИЯ, И ДВАЖДЫ ОШИБЛАСЬ САМА ПОСТАНОВКА ЗАДАЧИ.** Урок сессии: **опасение, записанное в карточке, снималось чтением докблока до конца предложения** — «накопление меняет семантику фреймворка» было неправдой, `setPoints()` уже отдавал дедупликацию вызывающему. Второй: **крышку пула решил замер, а не довод** — 334 мс на 20 000 точек против листинга в 6–13 секунд, и суперлинейная операция оказалась вообще не на этом пути. Третий: **`Object.keys()` — не порядок вставки**, и у Почты id числовые, поэтому вытеснение выбрасывало самую новую точку при верном итоговом счёте; тесты на `'A'`/`'B'`/`'C'` этого не видели — фикстура беднее продакшена ровно в том измерении, которое решало. Четвёртый: **экспорт — не вызывающий** (#238): `getSession()` виден снаружи и не зовётся никем, поэтому вся инвалидация вердикта из #232 в проде не работала. Пятый, про критика: **три его HIGH были артефактом моего бандла** — дал текущий код плюс дизайн, он прочёл их как один итоговый; формулировать бандл надо так, чтобы «этого ещё нет» было видно.

## Program status (high level)

| Stage | Status | Notes |
|---|---|---|
| S0 Platform Split | ✅ DONE | tag `platform-v2-split-done`; base platform-neutral, resolver minimal, clean-break Phase 3 shims deleted |
| S1 Shipping | ✅ DONE | PR #20; PSR-4 module; rate/packing seam + conformance audit (s2–s4) |
| S2 Box-packer | ✅ DONE | PR #21/#22; woven into rate-calc single-seam template |
| S3 Licensing | ✅ DONE | need-license (PR #25) → React UI (PR #31) → webhooks + Ed25519 signing (PR #35) |
| Remote-deactivation UX | ✅ DONE | s10–s12; command cycle proven live (push prod + pull rig); B-13/14/15 resolved |
| Checkout field layer (§8) | ✅ DONE | merged s44 (PR #132 → `957c039`) |
| Shipping SP-track | 🚧 IN PROGRESS | SP-1…SP-5 done (настройки, auth+секреты, валидация, show_if, карта/ПВЗ — incl. pickup selection + viewport accumulation); SP-6…SP-11 pending; map = `specs/2026-06-25-shipping-module-decisions.md` |
| S4 EDD / S5 React admin / S6 ecosystem | ⚪ deferred | post-v2.0 |

`composer check` green at s59: **1520 unit / 800 jest**, phpcs + PHPStan clean, integration green. Keep green after each change. Note: PHPStan crashes locally on Windows (`-1073741819`, environmental — gotcha `phpstan-windows-parallel-worker-segfault`); Linux CI "Run PHPStan" is the authoritative gate.

## Phase Status (subsystems)

| Phase | Code | Browser-verified | Notes |
|-------|------|------------------|-------|
| Framework Core | ✅ | ✅ | Bootstrap, Plugin base, Lifecycle — stable |
| Payment Gateway | ✅ | ✅ | `class-payment-gateway.php`: **~3,542 lines** (whole tree ~13.8k); trait-extraction candidate |
| Shipping Method | ✅ | ✅ | PSR-4 namespaced |
| Licensing | ✅ | ✅ | EDD store integration; React license page on core `woodev/v1` REST |
| Settings API | ✅ | ✅ | Typed settings framework |
| Settings React page (SP-1) | ✅ | ✅ | `Woodev > Настройки`: registry + `woodev/v1/settings` REST + React surface on the UI-kit |
| Setup wizard (UK-3/4) | ✅ | ✅ | React wizard on the shared UI-kit (s41, PR #99) |
| Box Packer | ✅ | ✅ | Shipping box-packing algorithm |
| REST API | ✅ | ✅ | Plugin REST routes |
| PHPStan | ✅ | — | Level 3, **no baseline** (`phpstan-baseline.neon` removed; do not reintroduce) |
| Documentation | ✅ | — | Two-tier: `docs/` (GH Pages) + `docs-internal/` (AI agents) |

## P6 gate evidence — base is platform-neutral & not a god-object (reference)

- **Platform neutrality:** base `Woodev_Plugin` declares **zero** WooCommerce/HPOS-named methods; the last HPOS seam (`is_hpos_compatible()`) was removed. Late-safe WC hooks live in `Woodev\Framework\Woocommerce_Plugin::register_woocommerce_hooks()`; early `before_woocommerce_init` feature declarations are wired by the bootstrap from loader `supported_features` metadata. Enforced by `PlatformNeutralBaseHasNoWcMethodTest`, `PlatformNeutralRestApiTest`, `BootstrapRegistrationTest`.
- **Base size (2026-06-04):** `woodev/class-plugin.php` ~1,274 lines / 74 methods (56 public) after the P6 split-done follow-up.
- **Construction shape:** `__construct()` is an ordered list of `init_*_handler()`/`load_*` calls ending with `add_hooks()`; `add_hooks()` wires only base-owned hooks.

## Known Bugs / Open debt

- [⚠️] `class-payment-gateway.php` ~3,542 lines — trait-extraction candidate (grooming, s13; → `FUTURE-BACKLOG` (frozen — см. board №6)).
- **B-2 loader-protocol forward-tolerance — standing behavior (s27):** the resolver loads framework classes from the **highest registered copy for the whole fleet** regardless of the bootstrap rendezvous winner; the `backwards_compatible` min-version guard deactivates-with-notice any plugin below the loaded copy's min. Standing rules (every loader definition MUST set `version` + `backwards_compatible`; registration contract additive-only from v2.0.0) → `AGENT-RULES.md` Rule 3.
- [ℹ️ OB-7 follow-up] «Плагины» still shows discontinued/coming-soon items (Беру.ру/GOODS) — `edd-api/v2` exposes no `_coming_soon`/`_product_icon`/rating; needs a woodev.ru-side API extension (s22 task #1). Framework normalizer already consumes them forward-compatibly.
- **All earlier release-blocker findings RESOLVED** (2026-06-01 audit, PHPStan masks, base-class leaks, eCheck/ACH removal, payment-gateway base-method regression, etc.) — see `SESSION-LOG.md` + git history. Not repeated here.

### Public-docs API staleness — DEFERRED (operator decision, s13)

- `docs/` (GH Pages) registration examples still teach the **v1 `register_plugin( '1.4.0', ... )` positional API**, which in v2 is a **tombstone** (quarantines the caller, never registers). The live API is `register_loader_definition([...])`. Examples also hardcode `'1.4.0'`/`VERSION='1.4.1'` instead of the `%%FRAMEWORK_VERSION%%` placeholder / `2.0.1`. Affected: `getting-started.md`, `core-framework.md`, `payment-gateway.md`, `shipping-method.md`, `README.md`.
- **Operator decision (s13): do NOT touch public docs yet** — he is currently the only consumer of the framework; the public docs get rewritten once everything is fully ready. Recorded so it is not mistaken for an oversight.

## Next Actions

1. **#243** — последнюю включённую галку фильтра типов делать `disabled` (решение принято, можно брать).
2. **#238** — мёртвая проводка `getSession()`/`refresh()` (инвалидация вердикта из #232 в проде не работает). Дизайн нужен: дебаунс + обновлять только открытые сессии.
3. **#214** — ждёт ответа оператора (Инбокс). Замер разблокирован: токен Яндекса читается из wp-config (s60).
4. Новые карточки аудита s60: **#244** (недоделанные P4-извлечения) и **#245** (плейсхолдер прод-паблик-ключа — **release-blocking перед v2-релизом**). Оба в Бэклоге.

Deferred (всё остальное — board №6): UK-CFR (settings extensibility) и прочие отложенные карточки живут на доске; `FUTURE-BACKLOG.md` заморожен.

## 🔔 Cross-Project Reminder — Ecosystem Orchestration (dormant)

- **Trigger:** v2.0.0 shipped AND stable in production for several weeks. When it fires, surface it in the session-opening summary; do **NOT** auto-start; point the operator to the spec and read its "Prompt for the Future Agent" section first.
- **Spec:** `D:\Projects\woodev_theme\docs\superpowers\specs\2026-05-13-woodev-ecosystem-orchestration-spec.md`. Cross-ref: `FUTURE-BACKLOG.md` → "Cross-Project Initiatives" #7 (frozen — см. board №6).

## Local rig

- **Project rig: dev `:8973` / tests `:8974`, branch `main`** (chrome-devtools MCP driver). Ports live in the gitignored `.wp-env.override.json`; the `WOODEV_TEST_*` constants live in the **container's wp-config** (`wp config set` — survives restarts) and are mirrored in `.wp-env.override.json`. **State as of s60: LIVE YANDEX bulk ON** — `WOODEV_TEST_PICKUP_LIVE_YANDEX=1` wins over `WOODEV_TEST_PICKUP_LIVE_POCHTA=false` and `WOODEV_TEST_PICKUP_STRATEGY=viewport`; the rig serves 812 live Yandex points (Moscow). Fixture is active only when both live flags are false.
- **Issuer `:8090` — KEPT, do NOT touch.** It is effectively a copy of prod (woodev_theme = local woodev.ru + EDD SL + deactivator, with test data); operator uses it independently. Container `c8ec47a5...-wordpress-1`. Authority pubkey `QSisoK0CDOmIOqGHvilMe+4mB/LMRFHf9hi6BxatfMk=`. The rig-only `zz-rig-host-rewrite.php` mu-plugin was **removed in s28** (from the container's `wp-content/mu-plugins/` and the `woodev_theme/` source), so :8090 is now a clean prod copy. NB: `docker exec ... rm /var/www/...` needs `MSYS_NO_PATHCONV=1` on Git-Bash or the path is mangled and the rm silently no-ops (gotcha `wpenv-windows-gitbash-path-mangling`).
- Drive via `docker exec <cli> wp eval-file ...` (cyrillic/quoting breaks inline `wp eval` — always eval-file). Do NOT run `do_action('admin_init')` in wp-cli (WC OrderAttributionController fatals). All rig traps: gotcha `wp-safe-remote-request-local-rig`.

### Docker inventory — DO NOT blindly prune (s28)

- **`wordpress-test` stack** (`wordpress-test` + `wp-mysql` + `wp-phpmyadmin`, volume `wordpress-test_db_data`, ~`:8080`) is the operator's **production-plugins test instance — ALL real plugins in one env** (intentional single instance, to test plugin↔plugin compatibility). **NEVER delete it or its volume, even when its containers are `Exited`.**
- Because that volume is unattached while the stack is down, **never run `docker volume prune` / `docker system prune --volumes` here** — it would wipe `wordpress-test_db_data`. Clean docker only surgically: `docker builder prune`, `docker image prune` (dangling), and explicitly-identified orphans.
- Project wp-env = `de59f74e…` (dev `:8973` + tests `:8974`); issuer = `c8ec47a5…` (`:8090`). Both KEEP.

## Infrastructure Reference

- **Version:** `Woodev_Plugin::VERSION` (in `woodev/class-plugin.php`) = 2.0.1 (unreleased).
- **PHP target:** 8.1 · **WP min:** 6.6 · **WC min:** 7.0
- **Tests:** Brain Monkey (unit) + WP Test Library (integration). `composer check` = phpcs + phpstan L3 + unit.
- **CI:** GitHub Actions. **Merge PRs:** `gh pr merge <N> --squash --delete-branch` only after confirmed-green CI; never `gh pr merge --auto`.
