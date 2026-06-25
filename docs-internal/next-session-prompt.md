# Промт для следующей сессии (s33): Shipping Module → SP-1 «Страница настроек» (спека → план → реализация)

> Написан в конце s32 (2026-06-26). **s32 был брейншторм-декомпозиция: все 14 пробелов `SHIPPING-PLANS.md` ЗАКРЫТЫ, программа разбита на под-проекты. Кода не писали.** Дальше — реализация **по одному под-проекту**: каждый SP проходит свой цикл `(брейншторм при необходимости) → спека → план → реализация → CI/ревью → мёрж`. Стартовый — **SP-1 «Страница настроек» (§15)**.

## Возобновление (ОБЯЗАТЕЛЬНО)

1. Прочитать `docs-internal/CURRENT-STATE.md` + `docs-internal/GOTCHAS.md` (индекс) + этот файл.
2. Прочитать **`docs-internal/specs/2026-06-25-shipping-module-decisions.md`** — авторитетные решения по всему модулю доставки + декомпозиция на SP-1…SP-11 + пилот-миграция + кросс-катинг-констрейнты + gap-анализ.
3. (Справочно, по необходимости) операторская `SHIPPING-PLANS.md` (корень репо, untracked) — нарратив + «📎 Из ревью кода»; все 14 пробелов помечены ✅ РЕШЕНО, §20 = таблица решений.
4. `git switch main && git pull`. Версию НЕ бампать (`@since 2.0.2`, VERSION=2.0.1 in-dev).
5. **Риг:** проектный wp-env dev `:8888` (если погашен — `npx wp-env start` из PowerShell; прод `:8080`/issuer `:8090` НЕ трогать). admin/password.

## 🎯 Задача s33 — SP-1 «Страница настроек» (§15), свой цикл спека→план→реализация

> Это **блокирующий фундамент** всего модуля: §16 и §8/§11 зависят от него. Прямо ложится на отгруженный в s31 Settings-API + визард (та же схема контролов, тот же React-паттерн `woodev/v1`).

**Что строим (из §15 decisions):**
- Меню **`Woodev > Настройки`** (в хабе Woodev рядом с Лицензии/Плагины), появляется при ≥1 плагине с настройками.
- Структура **страница → вкладки → (опц.) подсекции**; правило **1 вкладка = 1 провайдер** (перевозчик ИЛИ фреймворк-сервис типа DaData; мульти-карьер плагин = несколько вкладок).
- Рендер — **один нейтральный React-слот поверх Settings-API через `woodev/v1`** (паттерн визарда/License-page). Реестр (`Settings_Page_Registry`, имя обсуждаемо) собирает вкладки от плагинов + фреймворк-сервисов.
- Settings-API схема получает **2 уровня группировки (вкладка/секция)** — переиспользуем примитив, что группирует `setting_ids` в шаги визарда.
- **Миграция/§19:** плагин декларирует легаси option-ключ (`woocommerce_{id}_settings`), Settings-API пишет в него; легаси-URL старой страницы → admin-редирект на новую вкладку.
- В этот же SP закладываем **расширяемый shell реестра общих сервисов §9** (DaData-вкладка живёт здесь) + **§4 declaration-spine** (механизм support-флагов/config-контрактов).
- **НЕ в этом SP:** инстанс-настройки метода (остаются в модалке WC Shipping Zones).

**Начать с брейншторма SP-1** (skill `brainstorming`) — уточнить детали поверхности (точная модель реестра вкладок, как плагин декларирует вкладку/секции, capability страницы, slug/redirect-механика), затем спека `docs-internal/specs/`, план `docs-internal/plans/`, потом код.

## Что НЕ забыть (кросс-катинг-констрейнты — из decisions-doc)

- **HPOS-safe:** любой доступ к order meta через `Woodev_Order_Compatibility`, никогда `get_post_meta()` (gotcha `hpos-order-meta-safety`). (Для SP-1 настроек — это про опции, не meta, но держим в уме на будущие SP.)
- **i18n:** без `_n()` (русский — source; gotcha `russian-source-i18n-plural-n`).
- **Нет Composer в прод-плагинах:** после нового класса фреймворка — `php bin/generate-class-map.php` в **той же** задаче (иначе `ClassMapCompletenessTest` краснеет).
- **Секреты §5:** `sensitive`-маска в любом новом log/REST/export; механизм `constant_name` (wp-config override) — часть SP-1/SP-2.

## Гигиена (project rules)

- `@since 2.0.2`, версию НЕ бампать.
- Новый код — namespaces (PSR-4 `Woodev\Framework\*`) + короткие массивы `[]`, type declarations, docblocks. OOP-only.
- React — `@wordpress/scripts`, classic JSX runtime (`createElement`/`Fragment`, без JSX-синтаксиса), LF в `assets/build`. Собранные ассеты коммитить (assets-parity CI).
- PHPStan локально на Windows падает (segfault, environmental — gotcha `phpstan-windows-parallel-worker-segfault`); гейт — **Linux CI «Run PHPStan»**. Локально гонять `composer phpcs` + `composer test:unit`.
- Независимое ревью: Codex shell сломан (gotcha `codex-shell-sandbox-broken-windows`) — запускать критика **inline-bundle** методом, либо `/code-review ultra` / pr-review-toolkit субагенты.
- **Мёрж:** ветка → PR → проверить **КАЖДЫЙ** CI-job = pass + state CLEAN (main НЕ защищён обязательным чеком) → `gh pr merge --squash --delete-branch` (никогда `--auto`).

## Процесс программы (напоминание)

- **По одному SP за раз**, не «оптом». Декомпозиция уже сделана (s32). Порядок — по зависимостям (Фазы A–E в decisions-doc). Поздние SP правим по мере того, что узнали в ранних. Пилот-миграция Яндекса в конце валидирует весь стек.
- SP-1 (настройки) → SP-2 (auth+секреты) → SP-3 (слой полей, классика) → SP-4 (DaData) → SP-5 (карта/ПВЗ) → SP-6 (тариф+упаковка) → SP-7 (экспорт+документы) → SP-8 (трекинг+статусы) → SP-9 (письма) → SP-10 (страница заказов) → SP-11 (адаптер блоков) → пилот-миграция.

## Прочее (по приоритету ниже доставки)

- payment-gateway trait extraction (`class-payment-gateway.php` ~3,542 строк) — autodev-loop кандидат.
- review #4: `array()`→`[]` (~797 мест) + type declarations + `@since` sweep + включить `Generic.Arrays.DisallowLongArraySyntax`.
- s-кратно-10 (s40) → docs audit.
