# Промт для следующей сессии (s20): редизайн страницы «Woodev → Лицензии» (UI/UX)

> Написан в s19 (18.06.2026). Скопируй в новую сессию как стартовый бриф. После выполнения — замени на промт для s21.

---

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`).

## Старт сессии
1. `docs-internal/CURRENT-STATE.md` — lean-состояние (фазы, открытые баги, next actions).
2. `docs-internal/SESSION-LOG.md` — верхняя запись (s19) — что сделано.
3. `docs-internal/GOTCHAS.md` — сканировать релевантные теги (**52**). Особо для этой задачи: `license-page-css-bundle-only` (страница грузит ТОЛЬКО `style-index.css` + `wp-components`; стили серверных секций — в `src/license-page/style.scss`; `license_page()` оборачивает вывод в `.wrap`+`<h1>`), `wp-scripts-jsx-runtime-wp66` (классический JSX-рантайм, импортить `createElement`/`Fragment` в каждом JSX), `build-artifacts-eol-lf-windows-parity` (коммитить и `src/`, и `woodev/assets/build/`; `.gitattributes` пинит LF), `russian-source-i18n-plural-n` (русский — исходный язык; избегать `_n()`), `wp-safe-remote-request-local-rig` + `wc-blocks-subscriber-wp-admin-403-test` (ловушки рига).

## ⭐ Задача s20 — редизит «Woodev → Лицензии» (дизайн УЖЕ УТВЕРЖДЁН)

**Спека (авторитетная, утверждена оператором в s19):** `docs-internal/specs/2026-06-18-license-page-ui-ux-redesign.md`. Прочитай её целиком — там вся раскладка, анатомия карточки, form-group ключа, машина состояний на 7 групп (на реальных EDD-статусах), решённые вопросы и список файлов.

**Порядок:**
1. Дать оператору бегло освежить спеку (он уже согласовал; если правок нет — двигаемся).
2. **`writing-plans`** — составить план реализации по спеке.
3. Реализация TDD-циклами, `composer check` зелёный после каждого куска.
4. `npm run build`; коммитить и `src/`, и `woodev/assets/build/**` (build-parity CI).
5. **Браузер-верификация на двухстековом риге** — оба стека UP (issuer `c8ec…` :8090, stand `de59…` :8888). Stand уже фильтрует `woodev_license_base_url` → `host.docker.internal:8090`. Логин `admin`/`password`. Прогнать разные состояния карточки (активна / истекает / истекла / неверный ключ / лимит) — драйвить через активацию разных issuer-загрузок и правку их сроков. Серверные сообщения нужно ЛОКАЛИЗОВАТЬ на русский (`Woodev_License_Messages` сейчас на английском).
6. **Codex INLINE-бандлом** на существенных кусках; PR; мердж `gh pr merge <N> --squash --delete-branch` ТОЛЬКО после подтверждённо зелёного CI; НЕ `--auto`. Рекомендованная опция в AskUserQuestion — ПЕРВОЙ.

**Ключевые точки из спеки (напоминание):**
- Карточки лицензий: flex/grid **3/2/1**. Карточки ссылок (`.woodev-settings-documentation`): `.card.card-compact`-стиль (иконка слева, справа заголовок/текст/CTA), равная высота, **4/2/1**. Intro — info-notice на всю ширину.
- Form-group ключа: `[ input ][ 👁 ][ Проверить ]`. Ключ не сохранён → editable+плейсхолдер, 👁/Проверить disabled. Ключ сохранён → маска (4+4) read-only, 👁/Проверить enabled. «Проверить» = re-validate через `/verify` с сохранённым ключом.
- Машина состояний: editable только когда подозрителен сам ключ (группы **A** «нет ключа», **E** «неверный ключ»); маска+read-only для valid/expiring/expired/site_inactive/no_activations_left/disabled/revoked.
- Бета: прижат вправо, лейбл «Бета» + tooltip; по умолчанию выкл.
- Бэкенд: **аддитивно** добавить `renewal_url` в `Woodev_Plugins_License::get_state()` (тест!) для кнопки «Продлить». REST-роуты/`activate`/`deactivate`/кэш/ключи опций — НЕ трогать.

## ⚠️ Висит (решения оператора / контекст)
1. **v2.0.1 НЕ выпущен.** Не бампать `VERSION`. Новые символы → `@since 2.0.2`.
2. **Публичные docs/ — НЕ трогаем** до готовности фреймворка.
3. **OB-3 завершён** (кроме осознанно отложенного **F6** — backoff, вопрос endpoint-wide-ключа). Ревью-детали: `docs-internal/reviews/ob3-plugin-updater-review-2026-06-14.md`.
4. **F8 multisite** custom update-row в браузере НЕ проверяли (нужен multisite-стенд; consumer-починка подтверждена на single-site в s19). Низкий приоритет.

## Бэклог (если редизайн закроется быстро) — `FUTURE-BACKLOG.md` «Operator backlog dump — s13»
- OB-4 (reusable-JS-php-based) · OB-5 (godaddy fork, GPT-research) · OB-7 (модернизация Plugins page) · OB-9 (нюансы доставки).
- Крупные: payment-gateway trait extraction (~3 542 строки); ревью #4 (`array()`→`[]` ~797 + типизация + `@since`-свип + `Generic.Arrays.DisallowLongArraySyntax`).

## Подход и гигиена
- Брейншторм уже проведён → сразу `writing-plans` (НЕ повторять брейншторм). Реализация — атомарные TDD-куски.
- Serena для PHP **если доступна** (в s17–s19 НЕ была загружена → Grep/Read/Edit). `src/`, `tests/`, `.wp-env-stand/`, весь woodev_theme — Serena игнорит.
- Рижные пробы: `docker cp` файл в контейнер + `wp eval-file` (НЕ inline `wp eval` — кириллица/кавычки ломаются). НЕ `do_action('admin_init')` в wp-cli (WC OrderAttributionController фаталит). Чистить пробы/временные файлы/тест-юзеров в конце.
- Фоновый `codex:codex-rescue` молча умирает на Windows-сэндбоксе → INLINE-бандл + проверять, что вердикт реально вернулся.
