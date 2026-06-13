# Промт для следующей сессии (s13): причёсывание фреймворка (grooming) — пилот edostavka ОТЛОЖЕН

> Написан в s12 (13.06.2026). Скопируй в новую сессию как стартовый бриф. После выполнения — удали этот файл.

---

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`).

## Что сделано в s12
Все 3 UX-пробела remote-деактивации (B-13/14/15 из `docs-internal/reviews/remote-deactivation-ux-findings-2026-06-13.md`) **закрыты и проверены на rig против реального WC 10.8.1**:
- **A** — `Woodev_Lifecycle::handle_activation()` чистит запись опции `woodev_license_remote_deactivation_notices` + WC-заметку при реактивации (новый `Woodev_License_Command_Deactivate_Plugin::clear_remote_deactivation_artifacts()`).
- **B** — пробовали **WC Admin Notes**, но оператор **откатил** (PR #46): на сайте с единственным v2-плагином баннера НЕ будет — и это ОК (опция нацелена на нарушителей, ничего не теряем), а два уведомления на мульти-плагинном сайте избыточны. Итог: только обычный баннер `admin_notices`, который рисуется лишь когда активен ещё ≥1 v2-плагин (= «более одного v2-плагина»). `Woodev_Notes_Helper::add_note` и breadcrumb-код удалены. **Finding A оставлен** (чистка протухшего баннера при реактивации на мульти-v2 сайте — реальный фикс).
- **C** (woodev_theme deactivator) — wording «Отменить»(queued) / «Снять с доставки»(delivered) по состоянию доставки; re-деактивация-после-terminal проверена. «Застряло на Отменить» — это pull-only rig-артефакт.

Framework PR #44 смерджен (`21bb436`), затем PR #46 откатил Finding B. Deactivator — локальный коммит `28af8b9` (woodev_theme, без remote). Гоча: [[is-enhanced-admin-available-always-true]].

## ⚠️ Висит (решение оператора)
1. **Релиз v2.0.1 — так и не вышел.** main = код 2.0.1 + правки s12 (`@since 2.0.2`, VERSION оставлен 2.0.1). Оператор: **НЕ бампать версию на каждое изменение** (иначе доедем до 3.0, пока всё стабилизируем). Номер релиза — будущее решение оператора; пока не релизим.

_(Finding B решён в s12: WC-заметка откачена, остаётся только баннер — см. выше.)_

## Задача s13 — причёсывание фреймворка (НЕ пилот!)
**Решение оператора (s12):** пилот edostavka ОТЛОЖЕН — «ещё рано». Сначала максимально привести **фреймворк** к рабочему/чистому состоянию; пилот — потом, и баги фреймворка, найденные по ходу пилота, будем править тогда. Работы немало.

**ПЕРВЫМ ДЕЛОМ в s13:** запросить у оператора приоритетный список того, что «причёсываем» (scope он задаёт). Кандидаты из тех-долга (CLAUDE.md «Known Technical Debt» + `FUTURE-BACKLOG.md` + `platform-v2-cleanbreak-plan.md`):
- Удаление internal-API back-compat лесов (clean-break Phase 3): 2 `class_alias`-файла, ~10 `_deprecated_function`-шимов, legacy positional registration — см. `docs-internal/platform-v2-cleanbreak-plan.md` Phase 3.
- 50+ PHPStan baseline-игноров (`phpstan-baseline.neon`) — разобрать.
- `class-payment-gateway.php` (~2378 строк) — извлечение трейтов.
- `@since`-свип vs git-история + `array()`→`[]` + включение `Generic.Arrays.DisallowLongArraySyntax` в phpcs (бэклог s9).
- Прочие пункты `FUTURE-BACKLOG.md`.

Подход: каждый кусок — отдельная атомная задача (по возможности TDD), `composer check` зелёный после каждого, Codex-критика на существенных изменениях (inline-бандл), PR на зелёном CI. **НЕ бампать VERSION** на каждое изменение (правило оператора).

**Edostavka-пилот (когда дойдём, НЕ в s13):** audit-first — запросить список нюансов оператора → аудит `woodev/shipping-method/` + box-packer + warehouse/rate/checkout (в `docs-internal/reviews/`) → спека миграции + data-preservation контракт-тест (B-11), см. `docs-internal/migration/edostavka-data-preservation-checklist.md`.

## Локальный rig (поднят в s11, переиспользован в s12 — оба стека UP)
- **Issuer** (woodev_theme = локальный woodev.ru + EDD SL + деактиватор): `wp-env` в `d:\projects\woodev_theme`, `http://localhost:8090`. Контейнер cli: `c8ec47a5...-cli-1`. Authority-pubkey `QSisoK0CDOmIOqGHvilMe+4mB/LMRFHf9hi6BxatfMk=`. Локальный SSRF-bypass в `Push_Delivery::is_safe_target()` (env==='local' → true). Очередь команд: таблица `wp_woodev_pd_commands` (после s12 стук-row id=3 отменён → чисто).
- **Stand/consumer** (фреймворк wp-env): `http://localhost:8888`, контейнер cli `de59f74e...-cli-1`. WP 6.9 / WC 10.8.1. Gitignored `.wp-env-stand/woodev-stand.php` (id `cdek-stand`, download 21) + `.wp-env.override.json`. Канал — PULL. Tools → Woodev Stand (Шаг 0 setkey / 1 capture / 2 apply). Логин `admin`/`password`.
- **Драйв:** `docker exec <cli> wp eval-file /var/www/html/<f>.php` (docker cp файл внутрь; cyrillic/quoting в `wp eval` инлайн ломается — всегда eval-file). НЕ запускать `do_action('admin_init')` в wp-cli — WC OrderAttributionController фаталит (нужен реальный admin-screen); вызывай методы напрямую.
- Старт/стоп: `wp-env start|stop` в каждой папке (woodev_theme первый старт ~10 мин). Все грабли rig: gotcha [[wp-safe-remote-request-local-rig]].

## Гигиена / контекст
- Serena для PHP фреймворка; `tests/`, `.wp-env-stand/`, и весь woodev_theme — Serena игнорирует → Grep/Read/Edit.
- Codex shell-sandbox сломан (Windows) — критика только INLINE-бандлом: `$prompt | codex exec` со встроенным diff в промпте (работает; MCP-шум "forbidden" игнорируй). Gotcha `codex-shell-sandbox-broken-windows`.
- woodev_theme — локальный репо без remote; пофайловые коммиты (`git -C D:\projects\woodev_theme add <file>`; дерево замусорено npm-кэшем).
- `composer check` зелёный (607 unit после отката B, 41 integration baseline) — держать.
- **Мердж PR-ов:** НЕ `gh pr merge --auto`; мерджить `gh pr merge <N> --squash --delete-branch` ТОЛЬКО после подтверждённо зелёного CI. Рекомендованная опция в AskUserQuestion — ПЕРВОЙ; инфраструктуру объяснять до создания.
