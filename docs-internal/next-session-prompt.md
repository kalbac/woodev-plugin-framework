# Промт для следующей сессии (s32): Shipping Module — брейншторм + декомпозиция (ведёт оператор)

> Написан в конце s31 (2026-06-25). **OB-10 Setup Wizard ЗАВЕРШЁН и влит в `main`** (UI-редизайн одобрен оператором на риге, demo-фикстура возвращена, PR squash-merged). Следующее большое — **модуль доставки** по операторской `SHIPPING-PLANS.md`. Это программа на ~десяток под-проектов, ведёт оператор интерактивно. НЕ уходить в большой код до согласования декомпозиции.

## Возобновление (ОБЯЗАТЕЛЬНО)

1. Прочитать `docs-internal/CURRENT-STATE.md` + `docs-internal/GOTCHAS.md` (индекс) + этот файл.
2. Прочитать операторскую **`SHIPPING-PLANS.md`** (в корне репо, untracked, 434 строки, 20 разделов) — это первоисточник требований к модулю доставки.
3. `git switch main && git pull` — визард уже здесь; ветки `feat/setup-wizard-fullscreen` больше нет (удалена при squash-merge).
4. **Риг:** проектный wp-env dev **`:8888`** (если погашен — `npx wp-env start` из PowerShell; прод `:8080`/issuer `:8090` НЕ трогать). admin/password.

## Что сделано в s31 (фундамент для §15/§9 модуля доставки)

- **Setup Wizard UI** — современный WC-онбординг на `@wordpress/components`: бренд-шапка, кликабельный progress-line+dot степпер, обязательный терминальный finish, описания шагов, анатомия поля (label + tooltip-bubble + description).
- **Settings-API расширен** (это ключ к §9 «общие переиспользуемые поля» и §15 «страница настроек»):
  - `Woodev_Control`: новые типы `toggle` / `richtext` / `multiselect` + свойства `min` / `max` / `step` / `tooltip`.
  - `Woodev_Setting::update_value()` теперь принимает enum-**ключи** (а не только значения) и корректно обрабатывает `is_multi` поэлементно.
  - `register_setting()` порядок: `set_is_multi` **до** `set_default` (иначе массивные дефолты обнулялись).
  - Визард рендерит форму **автоматически** из схемы Settings-API — плагин лишь группирует `setting_ids` по шагам. Та же схема обслужит и страницу настроек.
- **Модель шагов визарда** (ответ на вопрос оператора): welcome/finish — редактируемый контент в плагине (`register_content_step` + overridable `get_finish_actions()`/`get_finish_secondary_actions()`); промежуточные шаги — `register_step(id, label, [setting_ids], …)`, форму строит Settings-API. UI плагин не пишет.

## 🎯 Задачи s32 — Shipping Module (декомпозиция, ведёт оператор)

> Подход: брейншторм-декомпозиция по `SHIPPING-PLANS.md`, затем по одному под-проекту через spec → plan → реализация. НЕ начинать код до согласования карты работ.

- **Стартовая развилка (блокирующая, §15 + §9):** страница настроек модуля доставки. Прямо стыкуется с только что отгруженным Settings-API + визардом (та же схема контролов). Логичная первая цель — спроектировать **архитектуру страницы настроек** (вкладки/секции, переиспользуемые поля §9, Dadata-интеграция) поверх готового Settings-API.
- **Аудит «существующего vs идеала» (s27 находки):** skeleton модуля богатый, но **ни разу не валидирован реальным плагином**; конкретные дыры — `admin/views/html-admin-shipping-method-status.php` ~30% заглушка; нет setup-wizard у shipping-плагина (теперь есть фреймворковый — переиспользовать); **нет абстракции label/export** (§12 накладная/ШК); JS/CSS ассеты не проверены; webhook-handler не валидирован на yandex (§13 трекинг).
- **План программы:** конформанс-аудит против 3 референс-плагинов → закрыть дыры → пилот-миграция yandex (ПВЗ как референс §7). Хороший кандидат на autodev-loop.
- **Release-blocking контракт данных (§19):** meta-ключи доставки — сохранять байт-в-байт (clean-break: внутренние API ломать можно, данные установленных сайтов — нет). Свериться с §19 при любой работе с meta.

## Гигиена (project rules)

- `@since 2.0.2`, версию НЕ бампать (вся линия v2 in-dev помечена 2.0.2; VERSION=2.0.1 бампится отдельным релизным действием; CI лишь детектит смену константы).
- Новый код — namespaces (PSR-4 `Woodev\Framework\*`) + короткие массивы `[]`, type declarations, docblocks. OOP-only.
- После нового класса фреймворка — `php bin/generate-class-map.php` в **той же** задаче (иначе `ClassMapCompletenessTest` краснеет).
- React (если будет) — `@wordpress/scripts`, classic JSX runtime (`createElement`/`Fragment`, без JSX-синтаксиса), LF в `assets/build`. Собранные ассеты коммитить (assets-parity CI).
- PHPStan локально на Windows падает (segfault `-1073741819`, environmental — gotcha `phpstan-windows-parallel-worker-segfault`); гейт — **Linux CI «Run PHPStan»**. Локально гонять `composer phpcs` + `composer test:unit`.
- Codex shell на этом Windows-боксе сломан (gotcha `codex-shell-sandbox-broken-windows`) — для независимого ревью использовать `/code-review ultra` или pr-review-toolkit субагентов.
- **Мёрж:** ветка → PR → проверить **КАЖДЫЙ** CI-job = pass + state CLEAN (main НЕ защищён обязательным чеком — в s29 был мёрж на красном PHPStan) → `gh pr merge --squash --delete-branch` (никогда `--auto`).

## Прочее (по приоритету ниже доставки)

- payment-gateway trait extraction (`class-payment-gateway.php` ~3,542 строк) — autodev-loop кандидат.
- review #4: `array()`→`[]` (~797 мест) + type declarations + `@since` sweep + включить `Generic.Arrays.DisallowLongArraySyntax`.
- s-кратно-10 (s40) → docs audit.

## Если понадобится риг-демо визарда снова

Demo-фикстура (`Woodev_Test_Settings` + многошаговый визард со всеми типами контролов) была возвращена к минимуму перед мёржем. Полный demo-снимок — в истории смёрженного PR (closed PR на GitHub) либо восстановить из reflog коммита `cdc5dca`. Для нового рига проще написать заново поверх готового Settings-API.
