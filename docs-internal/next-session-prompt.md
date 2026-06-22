# Промт для следующей сессии (s30)

> Написан в конце s29 (2026-06-22, автономный овернайт). OB-10 Setup Wizard влит в `main` (`ce04700`, PR #80).

## Старт сессии

- framework: `docs-internal/CURRENT-STATE.md`, `docs-internal/GOTCHAS.md`, этот промт.
- `main` синхронизирован (`ce04700`), **780 unit**, phpcs чисто, полный CI зелёный (вкл. PHPStan + Integration WP 6.4/6.6/latest).
- ⚠️ **PHPStan локально на Windows падает** (нативный segfault `-1073741819`, гоча `phpstan-windows-parallel-worker-segfault`) — авторитетный гейт Linux CI. Локально: `composer phpcs` + `composer test:unit`.
- ⚠️ **Docker:** не трогать `wordpress-test` стек/volume (боевой инстанс оператора) — см. CURRENT-STATE «Docker inventory». Проектный wp-env `de59f74e…` (:8888/:8889), issuer `c8ec47a5…` (:8090) — KEEP.
- Серена есть (PHP-навигация); правки существующих PHP — встроенный `Edit` (не Serena `replace_content`, ломает EOL).
- **Овернайт-наблюдение s29:** Anthropic API временами отдавал `529 Overloaded` — спавн субагентов и Bash-классификатор периодически блокировались. Если повторится — переключаться на инлайн-реализацию (Bash работал) и не долбить в стену.

## 🎯 Два хвоста по Setup Wizard (s29), сделать в начале s30

1. **Браузер/риг-верификация визарда (НЕ сделана автономно).** Бэкенд+REST+нейтральность доказаны CI, но **React-UI визарда в браузере не проверялся**. Нужно: на риге включить плагин, объявивший визард (через `build_setup_wizard_handler()` → подкласс `Setup_Wizard`/`Woocommerce_Setup_Wizard`), проверить: редирект при первой установке (один раз), навигацию шагов, сохранение значений (REST `woodev/v1/{id}/setup/steps/*`), завершение, notice-fallback (вкл. bulk-activate), skip, рендер content-шагов. Тест-плагин-фикстура (`tests/_fixtures/woodev-test-plugin`) уже объявляет минимальный one-step визард для интеграции — можно от неё оттолкнуться.
2. **Отложенное независимое ревью.** В s29 финальный субагент-ревью всей ветки и Codex-проход не запустились (529). T1–T6 прошли двухстадийное субагент-ревью; T7–T11 + фикс `rest.js` проверены инлайн. Рекомендуется **`/code-review ultra`** по диапазону s29 (или Codex-ревью) как догоняющий независимый гейт. Архитектурно-чувствительные места: opt-in seam в `Woodev_Plugin`, REST save_step (валидация/исключения), `dangerouslySetInnerHTML` в `step-view.js` (контент — авторский серверный, не пользовательский ввод — но подтвердить).

## 🚧 БОЛЬШОЕ ДАЛЕЕ — Shipping module (operator-led)

PLANS §3.2 «идеальный и максимально универсальный». Скелет богатый, но **никогда не валидирован реальным плагином**. Оператор пишет черновик `SHIPPING-PLANS.md` (в корне репо) — там же он хотел решить **архитектуру основной страницы настроек** (наследование `WC_Integration` vs своя React-страница на уже существующем нейтральном `Woodev_Abstract_Settings` + `Woodev_REST_API_Settings`; визард s29 уже опирается на этот контракт и НЕ предрешает решение — см. спеку s29 §3/D3). Подход: **брейншторм** по `SHIPPING-PLANS.md` → аудит против 3 референсов (edostavka/cdek/yandex) → закрыть дыры (s27 audit: `html-admin-shipping-method-status.php` 30%-стаб; нет label/export-абстракции; webhook не yandex-validated; JS/CSS не проверены) → пилот yandex (ПВЗ). Хороший кандидат под autodev-loop.

## Прочие кандидаты

- payment-gateway trait extraction (`class-payment-gateway.php` ~3,542 строк; autodev-loop).
- review #4: `array()`→`[]` (~797) + типизация везде + `@since` sweep + включить `Generic.Arrays.DisallowLongArraySyntax`.
- OB-6 dead-file sweep · box-packer non-WC wrapper (ближе к готовому, чем думали).
- s30 кратно 10 → можно предложить **docs audit** (последний был s39-методология; см. global CLAUDE.md auto-trigger).

## Гигиена

Версия НЕ бампнута (2.0.1 unreleased, `@since 2.0.2`). Публичные `docs/` не трогаем (решение оператора s13; `docs/admin-module.md` всё ещё ссылается на удалённый `Woodev_Plugin_Setup_Wizard` — обновить только при общем рерайте public docs). Новый код — PSR-4 namespaces + `[]` (правило усилено в AGENTS.md/CLAUDE.md s29). React — `@wordpress/scripts`, classic JSX runtime, LF. **После добавления класса фреймворка — `php bin/generate-class-map.php` В ТОЙ ЖЕ задаче** (ClassMapCompletenessTest краснеет сразу). Мерж: ветка → PR → зелёный CI (unit-матрица **и** «Run PHPStan» **и** Integration) → `--squash --delete-branch` (не `--auto`).
