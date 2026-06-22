# Промт для следующей сессии (s31): Setup Wizard — детальная проработка ДИЗАЙНА

> Написан в конце s30 (2026-06-23). OB-10 backend влит в `main` (PR #80/#81/#82). **UI визарда — в работе на ветке, дизайн НЕ финал.** s31 = плотная дизайн-сессия, ведёт оператор (интерактивно). НЕ уходить в большой код до согласования внешнего вида.

## Возобновление (ОБЯЗАТЕЛЬНО)

- **Ветка:** `git switch feat/setup-wizard-fullscreen` (`17c132a`, запушена). На ней: full-screen рендер + WC-React редизайн + **риг-демо** на тест-фикстуре. `main` функционально не трогали.
- **Риг:** проектный wp-env dev **`:8888`** (если погашен — `npx wp-env start` из PowerShell; прод `:8080`/`:8090` НЕ трогать). Активны WooCommerce + `woodev-test-plugin`. Логин admin/password.
- **URL визарда:** `http://localhost:8888/wp-admin/admin.php?page=woodev-woodev-test-plugin-setup`. Драйв браузера — Playwright MCP (есть). Скриншоты s30 в корне репо: `wizard-v2-clean.png` / `-connection.png` / `-delivery.png` / `-finish2.png`.
- ⚠️ **Риг-демо — НЕ для мёржа.** Обогащение `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php` (`Woodev_Test_Settings` + многошаговый визард + копирайт) — это scaffolding для просмотра дизайна. **Вернуть перед финальным PR** (в PR — только framework React/SCSS/PHP).
- ⚠️ PHPStan локально (Windows) падает — гейт Linux CI. Локально `composer phpcs` + `composer test:unit`. Мёрж: ветка → PR → **проверить КАЖДЫЙ job = pass + state CLEAN** (main не защищён обязательным чеком — в s29 был мёрж на красном PHPStan) → `--squash --delete-branch` (не `--auto`).

## Что уже есть (s30, на ветке)

- **Full-screen рендер:** `Setup_Wizard::maybe_render_full_screen()` на `admin_init` → свой HTML-документ (own `<head>/<body class="woodev-setup-wizard">` + mount `#woodev-setup-wizard-root` + `wp_print_*`) → `exit` до admin-chrome. emoji-стили на странице убраны. Пайплайн ассетов на standalone-странице работает (wp-element/components/api-fetch + наш css/js, 0 ошибок).
- **React-shell (`src/setup-wizard/`):** `index.js` (createRoot), `app.js` (state-машина: Назад/Пропустить/Продолжить·Завершить, finish-экран), `stepper.js` (нумерованный степпер), `step-view.js`, `control-field.js` (text/select/toggle), `icons.js`, `style.scss` (токены, Woo-purple `#7f54b3`, адаптив, focus). Classic JSX runtime (createElement, без JSX-синтаксиса).
- **Bootstrap (PHP → JS):** `get_bootstrap_data()` отдаёт `steps[]` (id/label/type/fields/content) + pluginName + headerLogoUrl + restRoot + nonce + state + finishActions. `get_field_schema()` строит схему из `Woodev_Setting` (type/name/options/value).

## 🎯 Задачи s31 (требования оператора — обсудить детально, дизайн ведёт он)

> Общий вердикт оператора: «стало лучше, но далеко не то; легаси-визард выглядит современнее». Планка ВЫШЕ — целимся в по-настоящему современный WP/WC-React онбординг. Сначала согласовать визуальное направление (можно мокапы/варианты), потом строить.

1. **Обязательный `finish`-шаг.** Сейчас визард = `welcome` + 2 настроечных шага, **обязательного `finish` нет** (финиш-экран показывается только после «Завершить» последнего шага). Решить: фреймворк авто-добавляет завершающий шаг в степпер? отдельный тип шага `finish`? — обсудить модель.
2. **Бренд-изображение** (header logo / «бренд изображение»). `headerLogoUrl` уже прокинут в bootstrap, но в шапке сейчас только текст-название. Прорисовать лого; добавить в риг-демо.
3. **Описания разделов (шагов).** Добавить `description` к шагу (`register_step`/`register_content_step` → доп. параметр) и рендерить под заголовком шага.
4. **Описания опций + tooltip к полям.** `Woodev_Setting` уже имеет `description` — рендерить как help под контролом. Плюс **tooltip** (инфо-иконка рядом с лейблом, `@wordpress/components` `Tooltip`).
5. **Все типы контролов.** Сейчас `control-field.js` маппит ТОЛЬКО по типу настройки (boolean→toggle, options→select, иначе text) и **игнорирует control-type**. Нужно:
   - **Аудит** `Woodev_Abstract_Settings::get_control_types()` / `get_setting_control_types()` / `Woodev_Control` — какие control-типы реально есть.
   - Рендерить по **control-type**, поддержать: `richtext`, `radio`, `number`, **`number-with-range` (ползунок в виде прогресс-бара — `RangeControl`)**, плюс существующие text/select/toggle. Возможно, добавить недостающие control-типы в Settings-API (PHP) + прокинуть в `get_field_schema()` (control type, min/max/step для range, options для radio).
6. **Максимально засеять моковые данные** — демо-визард должен показывать ВСЕ типы полей + описания шагов + описания опций + tooltips + бренд-лого + finish. Production-look.
7. **Дизайн-полиш** — отступы/типографика/иерархия; возможно боковая панель/иллюстрация, прогресс «Шаг X из Y», ссылка «Выйти» — на усмотрение после обсуждения с оператором.

## Гигиена

`@since 2.0.2`, версия не бампнута. Новый код — namespaces + `[]`. React — `@wordpress/scripts`, classic JSX runtime, импорт `createElement`/`Fragment`, LF в `assets/build`. После нового класса фреймворка — `php bin/generate-class-map.php` в той же задаче. Публичные `docs/` не трогаем. Перед PR — вернуть риг-демо фикстуры (`git checkout tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php`), прогнать `composer test:unit` (после возврата демо ожидается 782) + `composer phpcs`, обновить JS-bundle, добавить/поправить unit-тесты под новые control-типы где применимо.

## Прочее (после визарда)

- **Shipping module** — большое далее; начинать с брейншторма-декомпозиции по `SHIPPING-PLANS.md` (программа на ~десяток под-проектов; блокирующая развилка — §15 страница настроек, стыкуется с визардом). См. s29-обсуждение.
- payment-gateway trait extraction · review #4 (`array()`→`[]`) · OB-6 dead-file sweep · s-кратно-10 → docs audit.
