# Промт для следующей сессии (s21): выбор задачи из бэклога

> Написан в s20 (18.06.2026). Скопируй в новую сессию как стартовый бриф. После выполнения — замени на промт для s22.

---

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`).

## Старт сессии
1. `docs-internal/CURRENT-STATE.md` — lean-состояние (фазы, открытые баги, next actions).
2. `docs-internal/SESSION-LOG.md` — верхняя запись (s20) — что сделано.
3. `docs-internal/GOTCHAS.md` — сканировать релевантные теги под выбранную задачу.

## Контекст: s20 закрыт
- «Woodev → Лицензии» **UI/UX редизайн смержен** (PR #64, `894889b`) и проверен в браузере на риге. Бэкенд тронут только аддитивно (`renewal_url` в `get_state()`); контракты установленных сайтов не менялись.
- **OB-3 завершён** (кроме осознанно отложенного **F6** — backoff, вопрос endpoint-wide-ключа). Ревью-детали: `docs-internal/reviews/ob3-plugin-updater-review-2026-06-14.md`.

## ⭐ Задача s21 — выбрать с оператором из бэклога
Спросить оператора, что брать (AskUserQuestion, рекомендованная опция — ПЕРВОЙ). Кандидаты из `FUTURE-BACKLOG.md` → «Operator backlog dump — s13»:
- **OB-4** — reusable-JS-php-based принцип (PVZ-map и т.п.; фикс-React админ-UI исключение).
- **OB-7** — модернизация страницы Plugins (WP React + аккаунт woodev.ru).
- **OB-5** — изучение godaddy fork (делегировать GPT-research).
- **OB-9** — нюансы доставки.
- **Крупные (operator-scheduled, не solo):** payment-gateway trait extraction (~3 542 строки `class-payment-gateway.php`, autodev-loop); ревью #4 — `array()`→`[]` (~797) + типизация везде + `@since`-свип + включить `Generic.Arrays.DisallowLongArraySyntax`.
- **B-2** — loader-protocol forward-tolerance перед S4/EDD.

## ⚠️ Висит (решения оператора / контекст)
1. **v2.0.1 НЕ выпущен.** Не бампать `VERSION`. Новые символы → `@since 2.0.2`.
2. **Публичные docs/ — НЕ трогаем** до готовности фреймворка (решение s13).
3. **F8 multisite** custom update-row в браузере НЕ проверяли (нужен multisite-стенд; consumer-починка подтверждена на single-site в s19). Низкий приоритет.
4. **s60-аудит доков:** при следующей сессии, кратной 10 — предложить docs audit (последний — n/a; следить за дрейфом).

## Подход и гигиена
- Существенная работа → `brainstorming` → `writing-plans` → атомарные TDD-куски; `composer check` зелёный после каждого. Codex INLINE-бандлом на существенных кусках (фоновый rescue молча умирает на Windows-сэндбоксе → проверять, что вердикт вернулся). Re-critic собственных правок.
- Serena для PHP **если доступна** (в s17–s20 НЕ была загружена → Grep/Read/Edit).
- **Риг (если нужен):** оба стека — issuer `c8ec…` :8090, stand `de59…` :8888. Стенд live-маунтит `woodev-stand/woodev`→`./woodev` (правки в `woodev/` + `npm run build` видны сразу). Логин `admin`/`password`. Драйв состояний: `docker cp` + `wp eval-file` **через PowerShell** (Git-Bash манглит `/tmp/...` пути; кириллица/кавычки ломают inline `wp eval`). НЕ `do_action('admin_init')` в wp-cli. Чистить пробы/тест-юзеров в конце.
- Мердж: `gh pr merge <N> --squash --delete-branch` ТОЛЬКО после подтверждённо зелёного CI; НЕ `--auto`. После merge: `git fetch && git reset --hard origin/main` (squash расходится с локальным main → `git pull --ff-only` упадёт).
