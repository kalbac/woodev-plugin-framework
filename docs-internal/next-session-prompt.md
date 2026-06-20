# Промт для следующей сессии (s27): #8 ЗАШИПЛЕН — выбрать следующее

> Написан в s26 part-2 (2026-06-21). **#8 install-from-connector ПОЛНОСТЬЮ ЗАВЕРШЁН и зашиплен** (PR #77 `086585c` смержен в main; коннектор задеплоен на прод woodev.ru оператором; e2e пройден). Версия НЕ бампнута (2.0.1 unreleased). Следующая задача — на выбор оператора (+ у него есть новая идея, которую он расскажет).

## Старт сессии

- framework: `docs-internal/CURRENT-STATE.md`, `docs-internal/GOTCHAS.md`, этот промт.
- `main` синхронизирован на `086585c`. **725 unit**, всё зелёное.

## Что зашиплено в #8 (контекст)

- **Framework:** `POST woodev/v1/account/install` (cap `install_plugins`+nonce) → `Woodev_Account_Installer` (SSRF host-pin + `Plugin_Upgrader`, ставит НЕактивным) + React-кнопка «Установить» с full-card спиннер-оверлеем; фильтруемый таймаут запроса (install `/download`=30с, фильтр `woodev_account_request_timeout`).
- **Connector (woodev.ru, на проде):** `GET /download/{id}` (HMAC+владение) → EDD purchase-URL; signed `woodev_install` маркер обходит per-file лимит; account-scoped atomic rate-limit.
- Готча: `edd-sl-package-download-domain-bound`.

## 🟡 Сначала — мелкая уборка рига (если будешь на стенде)

- Удалить риговый mu-plugin `zz-rig-host-rewrite.php` на issuer'е :8090 (он только для локального e2e; в контейнере + локальная копия в woodev_theme root). Стабы (.rig-stubs/*.zip + продукты 36/23/26) и пересиженное подключение можно оставить для будущих тестов.

## 🎯 Кандидаты на следующую задачу (выбор оператора)

1. **Новая идея оператора** — он озвучит (приоритет, если расскажет).
2. **Cross-project (woodev_theme): account push-«Обновить»** — в ЛК «Доступные обновления» кнопка «Обновить» пушит апдейт в сайт пользователя. v2-фундамент готов (см. woodev_theme `docs/FUTURE-BACKLOG.md`, новая запись 2026-06-21). Решить pull-vs-push; pull переиспользует #8 почти целиком.
3. **Cross-project (woodev_theme): инфо-блок** «ставьте плагины прямо из админки» (снижение обращений в поддержку). woodev_theme `docs/FUTURE-BACKLOG.md`.
4. **Framework backlog** (`FUTURE-BACKLOG.md` / CURRENT-STATE «Big ones»): payment-gateway trait extraction (autodev-loop); review #4 — `array()`→`[]` (~797) + type declarations + `@since` sweep + enforce `Generic.Arrays.DisallowLongArraySyntax`; B-2 loader-protocol forward-tolerance перед S4/EDD; OB-9 shipping nuances.

## Гигиена

`@since 2.0.2`. Публичные `docs/` не трогаем (s13). Серена есть; для существующих PHP — встроенный `Edit` (Serena `replace_content` ломает EOL на Windows). JS/SCSS не пиннятся в `.gitattributes` к LF → `sed -i 's/\r$//'` после Edit. Мерж: ветка → PR → зелёный CI → `--squash --delete-branch` (НЕ `--auto`). Codex-ревью на security-чувствительное; находки не автофиксить — спрашивать.
