# Промт следующей сессии (s40): SP-4 (DaData seam) — brainstorm → spec → plan → impl

> Написан в конце s39 (2026-06-30). **s39 итог:** **SP-3 SHIPPED** (PR #96 `9ad9b5d`) — валидация полей (`required` + email/url/tel/number(min/max)), two-tier (клиент = UX-гейт блокирует Save/«Продолжить», сервер = авторитетная атомарная карта ошибок), обе поверхности (settings + wizard). Гнали 13-задачным subagent-driven + two-stage review + Codex-критик + **моя браузерная e2e на `:8888` (Playwright) перед мерджем**. 910 unit / 61 integration / 0 risky, каждый CI-job CLEAN. main = `9ad9b5d`.

## ⚠️ s40 кратно 10 — аудит доков

Последний полный аудит доков был давно. **В начале s40 предложить оператору `Сделай аудит документов`** (методология в глобальном CLAUDE.md). Не блокер — если оператор хочет сразу SP-4, аудит можно отложить, но напомнить.

## Возобновление (ОБЯЗАТЕЛЬНО)

1. `docs-internal/CURRENT-STATE.md` (раздел «Last session context» — s39) + `docs-internal/GOTCHAS.md` (индекс; **71 готча**; s39 добавил `format-validator-null-strlen-deprecation` [`[settings-api/validation]`]).
2. Карта программы: `docs-internal/specs/2026-06-25-shipping-module-decisions.md`. SP-1/SP-2/SP-3 спеки+планы — для стиля.
3. **Риг:** проектный wp-env dev `:8888` (`npx wp-env start`). admin/password. Вкладка «Карьер» теперь содержит SP-3 демо-поля (manager_email/support_phone required, tracking_url=url, max_weight number 1..1000) — на риге уже сохранены валидные тестовые значения (manager@woodev.ru, +7 (999) 123-45-67). Прод `:8080`/issuer `:8090` НЕ трогать.
4. Версию НЕ бампать (`@since 2.0.2`).

## 🎯 Задача s40 — SP-4 (DaData seam)

**Что это (из §9 карты программы):** сервисный seam для адресной автоподсказки/нормализации (DaData как первый провайдер). Framework = mechanism+contract+hooks; конкретика DaData/тарифы — в плагине (YAGNI, `feedback_framework_mechanism_not_domain`). Уточнить с оператором scope на brainstorm: что именно фреймворк предоставляет (интерфейс провайдера подсказок? REST-прокси? JS-виджет PHP-driven per `feedback_php_based_reusable_js`?) vs что остаётся плагину.

**Способ:** как SP-1/2/3 — `brainstorming` (skill, заземляясь на РЕАЛЬНОМ коде — settings/connection-контракт SP-2, REST `woodev/v1`, account-client) → `writing-plans` → **subagent-driven impl** (fresh agent на задачу, two-stage review каждая) → Codex GPT-5.5 критик → **обязательная моя браузерная e2e на `:8888` перед мерджем** (правило `feedback_self_e2e_verify_before_merge`).

## Процесс/уроки s39 (применить)

- **Codex-критик:** inline-bundle ≤~12KB строго (11.8KB бандл завис на 10 мин — у порога). Дробить по файлам, слать только security/логику-критичные source-диффы. `node <codex-plugin>/scripts/codex-companion.mjs task "$(cat bundle)" --json`. Re-critic свои фиксы перед мерджем.
- **Браузер:** Claude-in-Chrome расширение НЕ подключено в этой среде → гонять **Playwright MCP** (`browser_navigate`/`browser_snapshot`/`browser_type`/`browser_click`/`browser_network_requests`). Логин admin/password. REST идёт через `?rest_route=%2Fwoodev%2Fv1...` (URL-encoded — фильтровать сеть по `woodev`, не по `/woodev/v1`).
- **subagent-driven:** работает отлично, когда план содержит реальный код пошагово; two-stage review (spec → code-quality) ловит реальные баги (в s39 ~10 + 2 от Codex). Имплементеру: built-in `Edit` (не Serena — CRLF), не бампать VERSION, гонять unit+phpcs+build.
- **Мерж:** локальные docs-коммиты на main НЕ пушить отдельно (создаёт divergence после squash — готча `git-squash-onto-stale-origin-main-diverge`; лечится containment-check + `git reset --hard origin/main`). Лучше: коммитить спеку/план уже на фиче-ветке ИЛИ пушить main сразу.
- **Валидаторы:** любой формат-валидатор guard'ить `is_string()` (готча `format-validator-null-strlen-deprecation`).

## Кросс-катинг (как в SP-1/2/3)

- Новые классы → `php bin/generate-class-map.php` (no Composer в проде). i18n без `_n()` (русский — source). Built-in `Edit` для source. Build: `npm run build`, коммитить ассеты (LF, assets-parity CI). PHPStan локально на Windows падает (segfault) — гейт Linux CI. Интеграция foreground: `MSYS_NO_PATHCONV=1 npx wp-env run tests-cli env TEST_SUITE=integration php /var/www/html/woodev-framework/vendor/bin/phpunit --configuration /var/www/html/woodev-framework/phpunit.xml --testsuite=Integration [--filter X]`.

## Прочее / бэклог

- **SP-2-DEF:** wipe-secret/disconnect affordance (FUTURE-BACKLOG) — можно в SP-4 или на пилоте.
- **UK-3/UK-4 wizard** — последняя не-kit поверхность (можно вставить между SP).
- **SP-программа дальше:** SP-4 (DaData) … SP-11 → пилот-миграция (Яндекс→СДЭК→Почта).
- **MCP:** Supermemory/Obsidian статус не проверялся в s39 — проверить в начале s40, обновить `sessions/latest-context.md` если доступны.
