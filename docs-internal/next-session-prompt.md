> 🎯 **СЛЕДУЮЩАЯ СЕССИЯ = ДОДЕЛАТЬ SP-5: задачи T13–T19.**
> Ветка `feat/pickup-map` готова на 12/19, 15 коммитов поверх `main` (14 кода + docs), дерево чистое,
> **1240 unit / 87 jest / phpcs clean**. Ничего не смерджено.
>
> - Спека: `docs-internal/specs/2026-07-30-sp5-pickup-map-design.md` — **обязательно §10**, там семь
>   решений, изменённых по ходу реализации.
> - План: `docs-internal/plans/2026-07-30-sp5-pickup-map-plan.md` — **у него шапка с перечнем мест,
>   где он разошёлся с кодом. Прочитать её до того, как следовать любой задаче.**
> - Карточка на доске: **#145**, статус «В работе».

# Хендофф в T13–T19

## Возобновление (~5 мин)

1. `docs-internal/CURRENT-STATE.md` — «Last session context» (s45).
2. Спека §10 — что изменилось и почему. Без этого легко откатить решение назад.
3. Шапка плана — четыре места, где он врёт, включая Task 7 step 3 с `to_array()`.
4. Готчи: `mutation-sweep-branch-only-false-confidence`, `phpcs-does-not-enforce-line-length`,
   `js-store-instance-registry-cross-module`, `playwright-mcp-does-not-fire-wc-checkout-ajax`.

## Что сделано

| | |
|---|---|
| T1 | Удалён дошниковый слой ПВЗ + S1-овский кластер, снята вся разводка |
| T2–T6 | `Pickup_Point` (`to_array()` канон / `to_browser_array()` экранированный), `Point_Query` (кап 10° на сторону), `Point_Source` (bulk/viewport), `Constraint_Checker` (вес перед COD), `Address_Target` |
| T7 | REST `woodev/v1/shipping/pickup/{plugin}/points[/{id}]` + общий трейт рейт-лимита |
| T8 | `Pickup_Handler` — JS-конфиг, серверный бэкстоп на `woocommerce_checkout_process`, персист через `Shipping_Order_Handler` |
| T9 | Шов `Map_Provider` перенацелен на «откуда карта»; ymaps + embedded; ключ карт — обязанность плагина |
| T10–T12 | Оболочка модалки, `dataSource`, монтирование в якорь §8 + реестр экземпляров стора |

## Осталось

- **T13 ymaps-провайдер** — самый большой кусок. Эталон:
  `plugins-reference/woocommerce-yandex-delivery/assets/js/frontend/wc-yandex-delivery-widget-map.js`
  (620 строк). Обязательно воспроизвести две вещи: **список синхронизирован с вьюпортом**
  (`boundschange` → `geoQuery`) и **балун знает про способ оплаты** (COD не принимается → кнопка
  выбора заблокирована с объяснением). Копировать нельзя: эталон тянет точки из своего AJAX-слоя,
  наш провайдер получает `dataSource` в `init()`.
- **T14 embedded** — виджет/iframe карьера в той же оболочке, `postMessage` с проверкой origin.
- **T15 стили** — оболочка, drawer, балун, мобильный брейкпоинт 782px. Префикс `woodev-pickup-`.
- **T16/T17** — поле ключа карт (форма **Woodev settings-API**, не WC `form_fields`) и тумблер
  замены адреса (`replaceAddress` уже отдаёт `{ enabled, billingOnly }` — **не** добавлять `target`).
- **T18 фикстура** — два источника, bulk и viewport, переключатель `WOODEV_TEST_PICKUP_STRATEGY`.
  **Плюс написать `tests/integration/Shipping/PickupRouteTest.php` — Task 7 его не писал**, план
  ошибочно считает, что он уже есть.
- **T19 верификация** — весь сьют, интеграционные локально, риговый e2e по обеим стратегиям,
  проверка серверной авторитетности, Codex-ревью, PR.

## Контракты, которые уже зафиксированы (не переоткрывать)

- Провайдер: `init( container, config, dataSource )`, `on( 'select' | 'error', cb )`, `destroy()`.
  **Повторная попытка = `destroy()` + новый экземпляр.** Re-`init()` на живом объекте контрактом
  не определён.
- Модалка: `open/close/getContainer/showError/showEmpty/showNotice/destroy`. `showNotice( msg, onRetry )` —
  баннер **рядом** с картой, для случая «точки уже отрисованы»; `showError`/`showEmpty` заменяют тело
  и годятся только до первой отрисовки (спека §4.9).
- `dataSource`: `fetchPoints`/`fetchDetails`, debounce 300 мс, де-дуп по id, вытесненные запросы
  усыновляются последним, отказ — `{ status, code, message }`, пустой результат — **не** ошибка.
- Регистр стора §8: `getStoreForField( fieldId )`. Второй стор из того же конфига создавать нельзя.
- Каждая точка несёт `selectable: { allowed, reason }`, посчитанный **на сервере**. Провайдер только
  рендерит вердикт, правила не дублирует.

## Как работать (это окупилось на каждой задаче)

1. Subagent-driven: свежий исполнитель на задачу (Sonnet 5), потом ревью, потом правки.
2. **Требовать мутационное тестирование по значениям и содержимому, а не только по ветвлениям.**
   Трижды за сессию отчёт «все мутанты убиты» оказывался правдой и при этом вводил в заблуждение.
3. Длину строк мерить руками (`phpcs` её не проверяет), тесты `tests/` phpcs не видит вовсе.
4. Полный сьют, а не точечный прогон: PHPUnit молча берёт **только первый** путь из нескольких.
5. Браузер — **chrome-devtools MCP**, не Playwright MCP.
6. Находки — сразу карточкой на доску #6, не в текст сессии.

## Риг

Порты **8973** (dev) / **8974** (tests), прописаны в gitignore'нутом `.wp-env.override.json`.
Товар #12, зоны Russia + rest-of-world, метод `woodev_test_shipping`, COD, `/classic-checkout/`,
pretty permalinks, `woocommerce_coming_soon = no`. Интеграционные тесты гонять локально:

```
MSYS_NO_PATHCONV=1 npx wp-env run tests-cli env TEST_SUITE=integration php \
  /var/www/html/woodev-framework/vendor/bin/phpunit \
  --configuration /var/www/html/woodev-framework/phpunit.xml --testsuite=Integration --no-coverage
```

## Открыто на оператора

- **URL инструкции по получению ключа Яндекс.Карт** — в `Yandex_Map_Provider` это параметр
  `$key_docs_url` с `TODO`; ссылка не выдумана намеренно. Без неё примечание к полю рендерится без
  ссылки, что корректно, но менее полезно.
- **Полный аудит доски #6** — оператор просил проверить все карточки «когда всё завершишь». Две уже
  закрыты (#133, #128). Остальные 28 — при мердже SP-5.

## После SP-5

`SP-4` DaData → `SP-6` расчёт + упаковка → `SP-7` экспорт → `SP-8` трекинг → `SP-9` письма →
`SP-10` админ-страница заказов → `SP-11` blocks-адаптер → **пилот: Яндекс → СДЭК → Почта**.
Цель оператора: доделать фреймворк, перевести на v2 минимум СДЭК и Яндекс.Доставку, затем взяться
за новый плагин **OZON Логистика**.
