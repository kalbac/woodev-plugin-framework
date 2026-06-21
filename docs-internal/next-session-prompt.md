# Промт для следующей сессии (s29): Setup Wizard (OB-10) — БРЕЙНШТОРМ перед реализацией

> Написан в конце s28 (2026-06-21). Задача согласована с оператором. **Первый шаг — `brainstorming` (НЕ сразу код).** Версия НЕ бампнута (2.0.1 unreleased, `@since 2.0.2`).

## Старт сессии

- framework: `docs-internal/CURRENT-STATE.md`, `docs-internal/GOTCHAS.md`, этот промт.
- `main` синхронизирован, **760 unit**, phpcs чисто.
- ⚠️ **PHPStan локально на Windows падает** (`-1073741819`, нативный segfault, НЕ ошибка кода — гоча `phpstan-windows-parallel-worker-segfault`). Авторитетный гейт — **Linux CI** («Run PHPStan» в job Lint). Локально гоняй `composer phpcs` + `composer test:unit`.
- ⚠️ **Docker:** `wordpress-test` стек (+`wordpress-test_db_data`) — БОЕВОЙ инстанс оператора со всеми плагинами; НЕ удалять, НЕ запускать `docker volume prune`/`system prune --volumes` (см. CURRENT-STATE «Docker inventory»). Проектный wp-env: `de59f74e…` (dev `:8888` + tests `:8889`), фикстуры чистые.
- **Серена есть** — для PHP-навигации; для правок существующих PHP — встроенный `Edit` (Serena `replace_content` ломает EOL).

## 🎯 Задача s29 — OB-10 Setup Wizard (рерайт). Сначала БРЕЙНШТОРМ.

Текущий визард устарел; нужен рерайт. **Сессия: `brainstorming` → согласовать спеку с оператором → (возможно) `writing-plans` → TDD.** Оператор параллельно пишет черновик `SHIPPING-PLANS.md` (Shipping — следующая большая задача после SW).

### Требования оператора (зафиксированы s28, уточнить в брейншторме)

1. **0 зависимостей от WC по умолчанию.** Чистый WP базовый класс — напр. `Woodev_Setup_Wizard` (или namespaced `Woodev\Framework\Setup\Setup_Wizard` — обсудить именование/неймспейс).
2. **WC-обёртка отдельным наследником** — `Woodev_Woocommerce_Setup_Wizard extends Woodev_Setup_Wizard` (WC-специфика только в наследнике; ровно паттерн competitor-модуля s28: нейтральный + WC-слой).
3. **Инициализация при ПЕРВОЙ установке плагина — сразу**, и **только если плагин объявил override-класс** абстрактного визарда (opt-in; null по умолчанию — как `get_competitor_notification_handler()` / `get_setup_wizard_handler()`). Триггер «первой установки» — через `Woodev_Lifecycle` install-событие (проверить как сейчас).
4. **UI на React** (текущий UI сильно устарел). Посмотреть, **как WooCommerce делает свой setup/onboarding wizard** (вроде React) — взять за референс UX/паттерны.

### С чего начать брейншторм (grounding — обязательно)

- **Аудит текущего визарда** (что есть, как инициализируется, какой UI): `woodev/admin/abstract-plugin-admin-setup-wizard.php` (require'ится в `Woodev_Plugin::init_setup_wizard_handler()`), класс `Woodev_Plugin_Setup_Wizard`, property `$setup_wizard_handler` + `get_setup_wizard_handler()`. Тест `tests/unit/PlatformNeutralSetupWizardTest.php`.
- **Изучить WC onboarding wizard** (React-реализация) — context7/web; и **как фреймворк уже строит React-админку**: `src/` (license-page, plugins-page) + `@wordpress/scripts`. Гочи сборки: `wp-scripts-jsx-runtime-wp66` (classic runtime для WP 6.3+, импортировать `createElement`/`Fragment`), `license-page-css-bundle-only`, `build-artifacts-eol-lf-windows-parity`, `esc-url-raw-for-js-consumed-urls`.
- **Прецедент архитектуры:** competitor-модуль s28 (`woodev/competitor/`) — нейтральный движок + WC-рендерер + opt-in `get_*_handler()` (null default). SW ложится на тот же паттерн.

### Открытые вопросы для брейншторма

- Именование/неймспейс (legacy `Woodev_Setup_Wizard` vs PSR-4 `Woodev\Framework\Setup\*`)? Новый код — PSR-4.
- Чистый-WP базовый класс с React UI — какой транспорт данных (REST `woodev/v1` как у license/extensions, или admin-ajax)? Шаги визарда — декларативные (как competitor-правила) или императивные?
- Что именно WC-обёртка добавляет (WC-онбординг-шаги: платежи/доставка/налоги)?
- Точка «первой установки» (`Woodev_Lifecycle` install event) + редирект на визард; уважение «уже пройден/пропущен».
- Сохранять ли back-compat со старым `Woodev_Plugin_Setup_Wizard` (по clean-break v2 — внутренние API свободно ломаем; данные-контракты беречь — admin page slug визарда, опции «wizard_complete» если есть).

## Гигиена

`@since 2.0.2`. Публичные `docs/` не трогаем. **Composer только в dev/тестах, не в плагинах.** После добавления класса фреймворка — `php bin/generate-class-map.php` + коммит карты. Новый код PSR-4. React UI — `@wordpress/scripts`, classic JSX runtime, LF EOL. Мерж: ветка → PR → зелёный CI (проверь, что unit-матрица **и** «Run PHPStan» реально прошли) → `--squash --delete-branch` (НЕ `--auto`). Codex-ревью на архитектурно-чувствительное; находки в неавтономном режиме не автофиксить — спрашивать.

## Прочие кандидаты (после SW)

- **Shipping module** (большой, оператор пишет `SHIPPING-PLANS.md`) — аудит против 3 референсов → закрыть дыры → пилот yandex.
- payment-gateway trait extraction (autodev-loop) · review #4 (`array()`→`[]` + typing) · OB-6 dead-file sweep.
