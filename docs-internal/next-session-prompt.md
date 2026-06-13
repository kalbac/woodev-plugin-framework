# Промт для следующей сессии (s12): хардениг UX remote-деактивации (уведомление + кнопка)

> Написан в s11 (13.06.2026). Скопируй в новую сессию как стартовый бриф. После выполнения — удали этот файл.

---

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`).

## Контекст (s11)
Живой цикл remote-деактивации доказан на реальном коде (push прод + pull локальный rig + ack + replay). Фреймворк **2.0.1** смерджен в main (PR #41 + фикс теста PR #42, `dd63e5f`, main CI зелёный). НО при ручном прогоне оператор нашёл **3 реальных пробела в UX** — это и есть задача s12. Полный разбор: `docs-internal/reviews/remote-deactivation-ux-findings-2026-06-13.md`.

## ⚠️ Висит отдельно: релиз v2.0.1 НЕ выпущен
Auto-release не сработал (version-change был в #41 с красными тестами → release-job заблокирован; #42 починил тесты, но без version-change → release-job пропущен). main зелёный с кодом 2.0.1, но **тега/релиза v2.0.1 нет**. Оператор решил «оставить на потом». Когда дойдёт: либо ручной тег+release v2.0.1 на `dd63e5f` (реплика pipeline: тег → git-cliff CHANGELOG → ZIP → gh release), либо бамп до 2.0.2 через pipeline (version-change на зелёном main → авто-релиз; минус — пропуск номера 2.0.1).

## Задача s12 — починить 3 бага UX remote-деактивации
Подробности и предлагаемые фиксы — в findings-доке. Кратко:

1. **Framework — уведомление не очищается при (ре)активации плагина** (Finding A). Висит «вас отключили» после того как админ снова включил плагин. Фикс: хук активации → удалить запись плагина из опции `woodev_license_remote_deactivation_notices`.
2. **Framework — уведомление не может отрисоваться на сайте с ЕДИНСТВЕННЫМ v2-плагином** (Finding B, дизайн). Деактивированный плагин неактивен → сам себя не нарисует; рисуют только соседние активные v2-плагины. На сайте с одним v2-плагином уведомление не видно никогда. **Нужно дизайн-решение оператора:** рисовать из бутстрапа/любой загруженной копии независимо от состояния плагина, ИЛИ core-breadcrumb, ИЛИ оставить «рисуют соседи». 
3. **Deactivator (woodev_theme) — кнопка застревает на «Отменить», нельзя деактивировать повторно** (Finding C). Частично rig-артефакт (ack на rig не доходит — деактивированный плагин не шлёт ack сам; в проде ack синхронный в push). Реальное: семантика «Отменить» для уже доставленной команды + надо гарантировать повторную деактивацию после завершённого цикла и реактивации клиента.

**Подход:** воспроизводить и проверять каждый фикс на локальном rig (см. ниже). Framework-часть — TDD + `composer check`; deactivator-часть — в `woodev_theme` (локальный репо, пофайловые коммиты). Это правки v2-механизма; на прод не влияют (ни один прод-плагин ещё не на v2).

## Локальный rig (поднят в s11 — переиспользовать)
- **Issuer** (woodev_theme = локальный woodev.ru + EDD SL + деактиватор): `wp-env` в `d:\projects\woodev_theme`, `http://localhost:8090`. Локальные лицензии в `wp_edd_licenses` (использовали download_id=21 `19bcfa9e0a9c9a656ce194e4fab6f2ee`, status=expired). Authority-pubkey: `wp eval '(new Woodev\EDD\Plugin\EDD\License_Authority())->get_public_key_base64();'` → `QSisoK0CDOmIOqGHvilMe+4mB/LMRFHf9hi6BxatfMk=`. Деактиватор добавлен в `.wp-env.json`; локальный SSRF-bypass в `Push_Delivery::is_safe_target()` (`env==='local' → return true`).
- **Stand/consumer** (фреймворк wp-env): `http://localhost:8888`, gitignored `.wp-env-stand/woodev-stand.php` (id `cdek-stand`, download 21) + `.wp-env.override.json` (core 6.9, php 8.1). Настройки для локального issuer: `define WOODEV_LICENSE_AUTHORITY_PUBKEY`, фильтр `woodev_licensing_api_url`→`host.docker.internal:8090`, `http_request_host_is_external`+`http_allowed_safe_ports`(8090). Канал — PULL. Админ-страница Tools → Woodev Stand (Шаг 0 setkey / Шаг 1 capture / Шаг 2 apply).
- **Драйв цикла (wp-cli, eval-file):** issuer `Command_Queue::issue(3,21,'http://localhost:8888',1)`; stand `validate_license` через Tools или eval. Браузер: Chrome DevTools MCP, логин стенда `admin`/`password` (отдельная MCP-сессия).
- Старт/стоп: `wp-env start|stop` в каждой папке (woodev_theme первый старт ~10 мин).
- Все грабли rig: gotcha [[wp-safe-remote-request-local-rig]].

## После s12 (НЕ начинать здесь) — пилот edostavka, audit-first
Оператор по ручному ревью считает shipping-модуль не полностью готовым. ПЕРВЫМ ДЕЛОМ запросить его список нюансов → аудит `woodev/shipping-method/` + box-packer + warehouse/rate/checkout (зафиксировать в `docs-internal/reviews/`). Только после — спека миграции edostavka + data-preservation контракт-тест (B-11), см. `docs-internal/migration/edostavka-data-preservation-checklist.md`.

## Гигиена / контекст
- Serena для PHP; `tests/` и `.wp-env-stand/` Serena игнорирует → Grep/Read/Edit.
- Codex shell-sandbox сломан (Windows) — критика только INLINE-бандлом (gotcha `codex-shell-sandbox-broken-windows`).
- woodev_theme — локальный репо без remote; пофайловые коммиты (дерево замусорено).
- `composer check` зелёный (603 unit, 41 integration baseline) — держать.
- **Мердж PR-ов:** НЕ использовать `gh pr merge --auto` (в s11 оно переключило на main и дало рассинхрон head/CI). Мерджить `gh pr merge <N> --squash --delete-branch` ТОЛЬКО после подтверждённо зелёного CI. Память: рекомендованная опция в AskUserQuestion — ПЕРВОЙ; инфраструктуру объяснять до создания.
