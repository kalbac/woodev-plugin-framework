# Next-session prompt — S3.2 implementation: writing-plans → autodev-loop (modern license-page UI)

> Copy the block below as the starting prompt for the next session.
> Written 2026-06-10 at the end of session 6. S3.2 design+spec done & committed (`a4433f2` on `feat/s3-licensing-ui`); implementation deferred to next session by operator.

---

## PROMPT (paste this)

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`). Общайся со мной по-русски.

**Ветка `feat/s3-licensing-ui` уже существует** (отведена от свежего `main`), на ней закоммичена approved-спека S3.2 (`a4433f2`). Продолжай на ней — не создавай новую.

### ЗАДАЧА СЕССИИ
Реализовать **S3 sub-stage 2 — современный UI страницы лицензий**. Дизайн уже полностью согласован с оператором в прошлой сессии (брейншторм + визуальный компаньон) и зафиксирован в спеке. **Сначала** прогони `superpowers:writing-plans` по спеке → создай план реализации, **затем** веди реализацию через **autodev-pattern** (worker-субагент пишет дифф → адверсариальный критик ревьюит → commit), задача за задачей.

### СТАРТ СЕССИИ
1. Прочитай `docs-internal/CURRENT-STATE.md` (верхний digest — session 6) и `docs-internal/GOTCHAS.md` (индекс гочей; критичная — `licensing/two-layer`).
2. Прочитай `docs-internal/platform-v2-program-tracker.md` (S3.2 = next action) и **главное — спеку `docs-internal/platform-v2-s3-licensing-ui-spec.md`** (это источник истины; §9 = 5 atomic-задач). Полезный контекст — sub-stage-1 спека `platform-v2-s3-licensing-need-license-spec.md` (модель L1/L2, флаг `is_need_license`).
3. Для PHP — Serena MCP (`find_symbol`, `get_symbols_overview`, `find_referencing_symbols`, `search_for_pattern`), НЕ `Read` на `.php`. Активируй проект `woodev_framework` через Serena в начале.

### ЧТО УЖЕ РЕШЕНО (НЕ переоткрывать — оператор утвердил)
- **Стек:** `@wordpress/scripts` + JSX, нативные `@wordpress/components` (по максимуму, не выдумывать дизайн). Раскладка — **сетка карточек** (вариант A). Это ПЕРВАЯ React-поверхность фреймворка → задаёт паттерн для S5.
- **Данные:** REST, namespace **`woodev/v1`**, на core `rest_api_init` (НЕ WC-gated) + `wp.apiFetch` (`X-WP-Nonce`).
- **Clean-break:** старая Settings-форма / `<noscript>` / `options.php`-обработчики (`activate_license()`/`deactivate_license()` на `admin_init`, sanitize-callback, регистрации группы/секции/поля) — **УДАЛЯЮТСЯ** (внутренняя плумбинг-механика, на v2 ломать свободно — ADR-005).
- **Контракт хранения — byte-for-byte, БЕЗ миграции:** REST читает/пишет ТЕ ЖЕ опции `woodev_{id}_license` / `_license_key` / `beta_version`. Сохраняем: option-ключи, EDD `edd_action`-контракт + endpoint, хуки `woodev_license_saved`/`_deleted`/`woodev_enable_license_logging`, slug `woodev-licenses`, transient `woodev_extensions`, константа `WOODEV_LICENSE_DEBUG`.
- **Ключ:** `get_state()` отдаёт полный ключ (admin-only контекст), React рендерит маскированным + иконка-глаз для показа (toggle на клиенте).
- **Анти-пиратский инвариант:** `is_license_valid()`/`is_active()` НИКОГДА не зависят от `is_need_license()` — только от `is_license_required()` (серверный авторитет). Гоча `licensing/two-layer`.

### 5 ATOMIC-ЗАДАЧ (спека §9) — каждая worker → адверсариальный критик → commit
1. **`s6-p1`** — `Woodev_Plugins_License`: извлечь чистые операции `activate()`/`deactivate()`/`set_beta_enabled()`/`get_state()` (+ маппинг `message_variant`); **удалить** `admin_init`-обработчики, sanitize-callback, WC-рендерер полей; тесты на byte-for-byte parity записи опций + анти-пиратский + license-free. *(контракт-смежная → обязателен адверсариальный критик на запись опций.)*
2. **`s6-p2`** — REST-контроллер `Woodev_REST_API_License` (`woodev/licensing/api/`) + регистрация `woodev/v1` на `rest_api_init`; тесты permission/schema/no-silent-failure. *(silent-failure-hunter критик.)*
3. **`s6-p3`** — скаффолд `@wordpress/scripts`: `package.json`, `src/license-page/`, build-pipeline, коммит `woodev/assets/build/` артефактов, `.gitignore` `node_modules/`, ADR «React-admin стек фреймворка».
4. **`s6-p4`** — React-приложение (сетка карточек, `@wordpress/components`, apiFetch-экшены, маскировка+глаз, license-free карточка) + enqueue + `license_page()` mount; render-тест.
5. **`s6-p5`** — CI `assets` build-parity job в `ci.yml` (пересборка + `git diff --exit-code woodev/assets/build/`); холистический критик-проход по всей фиче.

### КАК РАБОТАЕМ (жёсткие правила)
- **ВСЁ существенное — через autodev-pattern, НЕ самостоятельно.** Atomic-задачи в `.autodev/queue/pending/` (формат — `.autodev/queue/done/s5-p1-*.md`); worker-субагент пишет дифф, адверсариальный критик (general-purpose / silent-failure-hunter как GPT-5.5 стенд-ин) ревьюит КАЖДЫЙ контракт-смежный дифф И твои собственные in-place фиксы перед коммитом (no self-certify). После доводки — холистический проход критика по всей фиче.
- **Дизайн НЕ переоткрывать** — он утверждён (см. «ЧТО УЖЕ РЕШЕНО»). Если всплывёт реальная развилка реализации, которой нет в спеке — спроси оператора коротко, не уходи в самостоятельные решения по новым публичным контрактам.
- **НЕ ломать installed-site контракты byte-for-byte** (см. `.autodev/GUARDS.md`, CLAUDE.md §Backward Compatibility, спека §8). Внутренний код на v2 — свободно (ADR-005). Массивы — short syntax `[]`. Строки/комменты — English. Type decls + докблоки `@since 2.0.0`. Yoda, `??`.
- **CI/PR гигиена:** `gh` авторизован (kalbac). Перед доверием CI — `gh pr view <n> --json mergeable,mergeStateStatus` (DIRTY → CI не бежит). Локально PHP 8.5 → помни `reflection-setaccessible-version-guard` и `brain-monkey-function-pollution`. `composer check` зелёный перед каждым коммитом (PHPCS + PHPStan 0 + PHPUnit). Conventional Commits. Мердж PR ТОЛЬКО после зелёных GH Actions И решения оператора (squash + delete-branch).
- **Новое для этой сессии — node-тулчейн:** добавляется `@wordpress/scripts` (первая node-сборка в repo). `node_modules/` в gitignore; `woodev/assets/build/` — КОММИТИМ (вендорные потребители берут готовый бандл). Убедись, что `npm run build` детерминирован и закоммиченный бандл совпадает с `src/` (это и проверяет CI-job из s6-p5).

### НАЧАЛО
1. Активируй Serena (`woodev_framework`). 2. Прочитай спеку `platform-v2-s3-licensing-ui-spec.md` целиком. 3. `superpowers:writing-plans` → план реализации по спеке. 4. Разложи 5 задач в `.autodev/queue/pending/` и веди autodev-loop (worker → адверсариальный критик → commit), начиная с `s6-p1`. 5. После реализации — **удали этот файл** (`docs-internal/next-session-prompt.md`).
