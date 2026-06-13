# Промт для следующей сессии (s13): пилот edostavka — audit-first

> Написан в s12 (13.06.2026). Скопируй в новую сессию как стартовый бриф. После выполнения — удали этот файл.

---

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`).

## Что сделано в s12
Все 3 UX-пробела remote-деактивации (B-13/14/15 из `docs-internal/reviews/remote-deactivation-ux-findings-2026-06-13.md`) **закрыты и проверены на rig против реального WC 10.8.1**:
- **A** — `Woodev_Lifecycle::handle_activation()` чистит запись опции `woodev_license_remote_deactivation_notices` + WC-заметку при реактивации (новый `Woodev_License_Command_Deactivate_Plugin::clear_remote_deactivation_artifacts()`).
- **B** — оператор выбрал **WC Admin Notes**: команда деактивации пишет inbox-заметку (`Woodev_Notes_Helper::add_note`) ПОСЛЕ `deactivate_plugins()`/`handle_deactivation` (иначе bulk-delete по source её сотрёт). WC рисует её независимо от состояния плагина → решает кейс единственного v2-плагина. Заметка **аддитивна** к баннеру (решение «единственный канал?» отложено — см. ниже).
- **C** (woodev_theme deactivator) — wording «Отменить»(queued) / «Снять с доставки»(delivered) по состоянию доставки; re-деактивация-после-terminal проверена. «Застряло на Отменить» — это pull-only rig-артефакт.

Framework PR #44 смерджен (`21bb436`, 608 unit, CI зелёный, Codex-критика пройдена). Deactivator — локальный коммит `28af8b9` (woodev_theme, без remote). Гочи: [[is-enhanced-admin-available-always-true]], [[wc-note-breadcrumb-survives-deactivation]].

## ⚠️ Висит (решения оператора, НЕ начинать без него)
1. **Релиз v2.0.1 всё ещё НЕ выпущен.** main теперь = код 2.0.1 + правки s12 (`@since 2.0.2`, но VERSION = 2.0.1). Варианты: тег s12-мерджа как **2.0.2** (бамп VERSION на зелёном main → авто-релиз) ИЛИ сначала ручной 2.0.1. Решает оператор.
2. **B — аддитивность WC-заметки.** На сайте с НЕСКОЛЬКИМИ активными v2-плагинами сработают ОБА канала (баннер сверху + inbox-заметка). Если это лишнее — сделать заметку единственным каналом (убрать/ограничить баннер). Спросить оператора.

## Задача s13 — пилот edostavka, AUDIT-FIRST (НЕ начинать с миграции)
Оператор по ручному ревью считает shipping-модуль не полностью готовым. **ПЕРВЫМ ДЕЛОМ** запросить у него список нюансов → провести аудит `woodev/shipping-method/` + box-packer + warehouse/rate/checkout (зафиксировать в `docs-internal/reviews/`). Только ПОСЛЕ аудита — спека миграции edostavka + data-preservation контракт-тест (B-11), см. `docs-internal/migration/edostavka-data-preservation-checklist.md`.

## Локальный rig (поднят в s11, переиспользован в s12 — оба стека UP)
- **Issuer** (woodev_theme = локальный woodev.ru + EDD SL + деактиватор): `wp-env` в `d:\projects\woodev_theme`, `http://localhost:8090`. Контейнер cli: `c8ec47a5...-cli-1`. Authority-pubkey `QSisoK0CDOmIOqGHvilMe+4mB/LMRFHf9hi6BxatfMk=`. Локальный SSRF-bypass в `Push_Delivery::is_safe_target()` (env==='local' → true). Очередь команд: таблица `wp_woodev_pd_commands` (после s12 стук-row id=3 отменён → чисто).
- **Stand/consumer** (фреймворк wp-env): `http://localhost:8888`, контейнер cli `de59f74e...-cli-1`. WP 6.9 / WC 10.8.1. Gitignored `.wp-env-stand/woodev-stand.php` (id `cdek-stand`, download 21) + `.wp-env.override.json`. Канал — PULL. Tools → Woodev Stand (Шаг 0 setkey / 1 capture / 2 apply). Логин `admin`/`password`.
- **Драйв:** `docker exec <cli> wp eval-file /var/www/html/<f>.php` (docker cp файл внутрь; cyrillic/quoting в `wp eval` инлайн ломается — всегда eval-file). НЕ запускать `do_action('admin_init')` в wp-cli — WC OrderAttributionController фаталит (нужен реальный admin-screen); вызывай методы напрямую.
- Старт/стоп: `wp-env start|stop` в каждой папке (woodev_theme первый старт ~10 мин). Все грабли rig: gotcha [[wp-safe-remote-request-local-rig]].

## Гигиена / контекст
- Serena для PHP фреймворка; `tests/`, `.wp-env-stand/`, и весь woodev_theme — Serena игнорирует → Grep/Read/Edit.
- Codex shell-sandbox сломан (Windows) — критика только INLINE-бандлом: `$prompt | codex exec` со встроенным diff в промпте (работает; MCP-шум "forbidden" игнорируй). Gotcha `codex-shell-sandbox-broken-windows`.
- woodev_theme — локальный репо без remote; пофайловые коммиты (`git -C D:\projects\woodev_theme add <file>`; дерево замусорено npm-кэшем).
- `composer check` зелёный (608 unit, 41 integration baseline) — держать.
- **Мердж PR-ов:** НЕ `gh pr merge --auto`; мерджить `gh pr merge <N> --squash --delete-branch` ТОЛЬКО после подтверждённо зелёного CI. Рекомендованная опция в AskUserQuestion — ПЕРВОЙ; инфраструктуру объяснять до создания.
