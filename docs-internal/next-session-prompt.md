# Next-session prompt — S3 Licensing sub-stage 2 (UI) or 3 (webhooks) after `is_need_license` landed

> Copy the block below as the starting prompt for the next session.
> Written 2026-06-10 at the end of session 5 (S3.1 `is_need_license` safe-scaffold merged as PR #25 `61006c3`).

---

## PROMPT (paste this)

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`). Ветка `main`, всё смержено. Общайся со мной по-русски.

### СТАРТ СЕССИИ
1. Прочитай `docs-internal/CURRENT-STATE.md` (верхний digest — session 5, 2026-06-10) и
   `docs-internal/GOTCHAS.md` (индекс — 33 гочи; новая — `licensing/two-layer`).
2. Прочитай `docs-internal/platform-v2-program-tracker.md` (S3 in progress) и обе спеки S3:
   `docs-internal/platform-v2-s3-licensing-need-license-spec.md` (фреймворк, §4 — отложенная подпись)
   и серверную `D:\Projects\woodev_theme\docs\superpowers\specs\2026-06-10-woodev-core-license-authority-signing-spec.md`.
3. Для PHP — Serena MCP (`find_symbol`, `get_symbols_overview`, `find_referencing_symbols`),
   не `Read` на `.php`. Активируй проект `woodev_framework` через Serena в начале.

### ГДЕ МЫ ПО ПЛАНУ (PLANS.md §3.4)
- **S0/S1/S2 ✅**, всё в `main`.
- **S3 Licensing — in progress, разбито на 3 подстадии:**
  - **Sub-stage 1 ✅ MERGED (PR #25 `61006c3`):** `is_need_license()` (L1 презентация) + `is_license_required()`
    (L2 авторитет, default-true seam) + outage-grace. Полная Ed25519-подпись **отложена** (safe-scaffold).
    Серверная часть (woodev-core) уже реализована + закоммичена локально в woodev_theme (без remote).
  - **Sub-stage 2 ⚪ — современный UI страницы лицензий** (PLANS §3.4 «UI страницы лицензий»).
  - **Sub-stage 3 ⚪ — встроенные вебхуки** (PLANS §3.4.1: kill-switch по просроченной лицензии + диагностика
    для `woodev_support`; переиспользует Ed25519-примитив подписи из S3.1 спеки §4).

### РАЗВИЛКА — спроси оператора в начале
Кандидаты на эту сессию:

**A. Sub-stage 2 — современный UI страницы лицензий (PLANS §3.4).** Сейчас — обычная Settings API
   форма (`Woodev_Admin_Pages::license_page()` + `Woodev_Woocommerce_License_Settings::do_license_fields()`,
   уже есть AJAX-путь `verify_license($license, $ajax=true)`). Сделать интерактивной/современной.
   ⚠️ PLANS §6 хочет **встроенный React WordPress/WooCommerce** (`@wordpress/element`, `@wordpress/components`),
   НЕ отдельный ReactJS. Обратная совместимость в плагинах обязательна; release-blocking контракты лицензий
   (settings group `woodev_license_fields_group`, slug `woodev-licenses`, nonce, option-ключи) — byte-for-byte.
   → Начни с `superpowers:brainstorming` + **предложи визуальный компаньон** (это визуальная задача).

**B. Sub-stage 3 — встроенные вебхуки (PLANS §3.4.1).** Реализовать клиентскую Ed25519-верификацию
   (спека §4) как общий примитив, затем два вебхук-сценария. Больше security-дизайна; начни с брейншторма.
   Зависит от того, что woodev-core уже подписывает claim'ы — но публичный ключ prod ещё не извлечён/вшит.

**C. Завершить S3.1 signing (cross-repo).** Вшить prod Ed25519-публичный ключ woodev-core в фреймворк
   и реализовать верификацию подписанного claim (спека §4) + fixture-тест на published test vector.
   ⚠️ Требует prod-deploy woodev-core и `wp eval` для ключа (`get_public_key_base64()`). Уточни у оператора,
   задеплоен ли woodev-core на prod.

**D. Мелкий safety-фикс (warm-up).** `Abstract_Warehouse_Store::save()` не проверяет возврат `wpdb` —
   упавший UPDATE возвращает 200 со старыми данными. Висит с session 2; адверсариальный критик обязателен.

### КАК РАБОТАЕМ (жёсткие правила)
- **ВСЁ существенное — через autodev-pattern, НЕ самостоятельно.** Atomic-задачи в `.autodev/queue/pending/`
  (формат — `.autodev/queue/done/s5-p1-*.md`); worker-субагент пишет дифф, адверсариальный критик
  (general-purpose / silent-failure-hunter как GPT-5.5 стенд-ин) ревьюит КАЖДЫЙ контракт-смежный дифф
  И твои собственные in-place фиксы перед коммитом (no self-certify — в s5-p2 критик поймал реальный
  `wp_die(403)`). После доводки — холистический проход критика по всей фиче.
- **Брейншторм объёма с оператором до кода** (PLANS §6 — формат дискуссии, агент оспаривает/предлагает),
  если развилка существенная (новые публичные контракты, React-стек, формат вебхука).
- **НЕ ломать installed-site контракты byte-for-byte** (см. `.autodev/GUARDS.md`, CLAUDE.md). Внутренний
  код на v2 — свободно (ADR-005). Massivы — short syntax `[]`. Строки/комменты — English. Type decls +
  докблоки `@since 2.0.0`.
- **Anti-pirate инвариант (S3):** `is_license_valid()`/`is_active()` НИКОГДА не зависят от локального
  `is_need_license()` — только от серверного авторитета. Гоча `licensing/two-layer`.
- **CI/PR гигиена:** новый branch от свежего `main`. `gh` авторизован (kalbac). Перед доверием CI —
  `gh pr view <n> --json mergeable,mergeStateStatus` (DIRTY → CI не бежит). Локально PHP 8.5 → помни
  `reflection-setaccessible-version-guard` и `brain-monkey-function-pollution`. Conventional Commits.
  Мердж PR ТОЛЬКО после зелёных GH Actions И решения оператора (squash + delete-branch).
- **woodev_theme не имеет git remote** — туда только локальные коммиты, ни push, ни PR.

### НАЧАЛО
Спроси оператора про развилку A/B/C/D. После выбора A/B — `superpowers:brainstorming`
(для A — предложи визуальный компаньон). После реализации — удали этот файл (`docs-internal/next-session-prompt.md`).
