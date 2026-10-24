# Переход на версию 2: что меняется для разработчиков и интеграций (1С, CRM) — Яндекс Доставка для WooCommerce

Статья для тех, кто подключал к магазину обмен с 1С, CRM или писал свой код под плагин «Яндекс Доставка». Настройки магазина при обновлении переносятся сами; здесь только то, что может сломать внешний обмен или собственный код.

**Главное.** Изменились идентификаторы способов доставки (`method_id`), способ «Экспресс» удалён, почти все хуки версии 1 больше не вызываются.

## Идентификаторы способов доставки

| Было (версия 1) | Стало (версия 2) | Правило |
|---|---|---|
| `yandex_delivery_other_day` | `yandex_delivery_courier` | если в способе был тариф «по времени» (`time_interval`) |
| `yandex_delivery_other_day` | `yandex_delivery_pickup` | если в способе был тариф «самовывоз из пункта» (`self_pickup`) |
| `yandex_delivery_express` | — | способ **удалён**, замены нет |

Если тариф в способе не указан или не распознан, способ становится курьерским.

Что делает обновление с зонами доставки: способ в зоне сохраняет номер экземпляра (`instance_id`) и зону, меняется только идентификатор. Настройки копируются под новое имя опции (`woocommerce_yandex_delivery_courier_<instance_id>_settings` или `…_pickup_…`); старая опция остаётся на случай отката. Строки «Экспресс» из зон удаляются; что было удалено, записано в журнале обновления (опция `wc_yandex_upgrade_2_0_0`). В настройках оплаты при получении «Доступно для способов доставки» `yandex_delivery_other_day` заменяется на оба новых идентификатора, `yandex_delivery_other_day:12` — на `yandex_delivery_courier:12` или `yandex_delivery_pickup:12` по номеру экземпляра; записи «Экспресс» убираются (если после этого список стал бы пустым, они остаются, чтобы не открыть оплату для всех способов).

> **Внимание: старые заказы сохраняют старый идентификатор.** Обновление не переписывает строки доставки уже оформленных заказов. У заказов, созданных на версии 1, в строке доставки по-прежнему `method_id = yandex_delivery_other_day` (или `yandex_delivery_express`); у новых — `yandex_delivery_courier` или `yandex_delivery_pickup`. Плагин сам определяет тип старого заказа по его экземпляру способа. Если экземпляр уже удалён, а также для «Экспресс» выгрузка такого заказа в Яндекс откажется: «Способ доставки заказа больше не доступен».

Если сопоставление способов в 1С или CRM сделано по равенству с `yandex_delivery_other_day`, новые заказы не сопоставятся; нужны оба новых значения, а для истории — и старое.

## Мета-данные заказа

| Ключ | Версия 2 |
|---|---|
| `_yandex_delivery_request_id` (номер заявки в Яндексе) | без изменений |
| `_yandex_delivery_state_status` (статус Яндекса, «сырое» значение) | без изменений |
| `_yandex_delivery_sharing_url` (ссылка на отслеживание) | без изменений |
| `_yandex_delivery_destination_station_id`, `_yandex_delivery_destination_interval_from`, `_yandex_delivery_destination_interval_to` | у старых заказов читаются для выгрузки |
| `_yandex_delivery_carrier_price`, `_yandex_delivery_delivery_cost` | новые, пишутся у новых заказов |

Настройки плагина остаются в опции `woocommerce_yandex_delivery_settings`. Таблица складов `wp_wc_yandex_delivery_warehouses` не используется: обновление один раз читает из неё код станции и больше её не трогает.

## Хуки

В версии 2 из хуков плагина сохранился только `woodev_yandex_map_api_key` (фильтр ключа Яндекс.Карт, один аргумент). Фильтр `woodev_yandex_delivery_http_request_args($args, $api)` (параметры HTTP-запросов к API) остаётся с тем же именем: он принадлежит общему клиенту API и берёт имя из идентификатора плагина `yandex_delivery`. <!-- не подтверждено: исходник версии 1 недоступен, имя сохранилось по утверждению карточки #1428 -->

Уведомлений о удалённых хуках плагин **не показывает**: код на удалённых хуках просто перестаёт срабатывать. Проверьте его по таблице.

| Был (версия 1) | Что в версии 2 |
|---|---|
| `wc_yandex_order_status_changed`, `wc_yandex_order_status_changed_to_{статус}`, `wc_yandex_order_status_changed_from_{старый}_to{новый}`, `wc_yandex_order_mark_as_delivered`, `wc_yandex_order_mark_as_cancelled` | замена по смыслу (<!-- не подтверждено: вызов этого действия из опроса статусов Яндекса напрямую не найден, см. facts-yandex.md §4 -->): действие `woodev_shipping_delivery_status_changed($order, $previous_status, $current_status, $provider)`; статусы общие (`pending`, `created`, `in_transit`, `ready_for_pickup`, `delivered`, `returning`, `returned`, `failed`, `cancelled`, `unknown`), событие приходит от всех служб доставки — проверяйте `$provider` |
| `wc_yandex_delivery_rate_cost` | фильтр `woodev_shipping_method_calculated_rate($rate, $package, $method)` |
| `wc_yandex_delivery_{id}_fallback_calculate_shipping` | действие `woodev_shipping_method_rate_calculation_failed($exception, $package, $method)` |
| `wc_yandex_delivery_update_sharing_url`, `wc_yandex_delivery_delete_sharing_url` | нет; ссылка лежит в мета-ключе `_yandex_delivery_sharing_url` |
| `wc_yandex_delivery_prepare_order_data` | нет; перед отправкой заказа срабатывает только действие `woodev_shipping_order_export_requested($order)`, изменить данные им нельзя |
| `wc_yandex_suggestions_plugin_default_params`, `wc_yandex_delivery_checkout_script_params`, `wc_yandex_delivery_after_shipping_rate_outputs`, `wc_yandex_delivery_time_interval_params`, `wc_yandex_delivery_formatted_address`, `wc_yandex_delivery_clear_address_part`, `wc_yandex_delivery_clear_address_part_patterns`, `wc_yandex_delivery_order_statuses`, `wc_yandex_delivery_cancel_reasons`, `wc_yandex_delivery_shipping_method_pricing_calculator_params`, `wc_yandex_delivery_order_places`, `wc_yandex_disabled_statuses_for_export`, `wc_yandex_delivery_integration_form_fields`, `wc_yandex_delivery_abort_cached_response` | удалены без замены |

Для «замен по смыслу» аргументы другие, поэтому старые обработчики придётся переписать. Пример:

```php
add_action( 'woodev_shipping_delivery_status_changed', function ( $order, $previous, $current, $provider ) {
	// Проверьте, что $provider относится к Яндекс Доставке.
	if ( 'delivered' === $current ) {
		// ...
	}
}, 10, 4 );
```

Новые фильтры версии 2, не связанные с версией 1: `woodev_yandex_delivery_cod_payment_methods` (какие способы оплаты считать наложенным платежом).

## Адреса, AJAX и расписание

- Запросы `admin-ajax.php` с действиями `get_yandex_delivery_location_detect`, `get_yandex_delivery_shipment_points`, `set_yandex_delivery_pickup_point`, `set_yandex_delivery_time_interval`, а также `yandex_delivery_export_order`, `yandex_delivery_update_order`, `yandex_delivery_cancel_order`, `yandex_delivery_print_label_order`, `yandex_delivery_print_act` удалены.
- REST-маршруты `yandex-delivery/warehouses` удалены вместе со складами.
- Адрес страницы заказов `wc-yandex-orders` сохранён как совместимый. <!-- не подтверждено: поведение (открытие или перенаправление) не проверялось -->
- Задачи планировщика `wc_yandex_orders_update`, `wc_yandex_update_order`, `wc_yandex_export_order` при обновлении отменяются; статусы теперь обновляет задача `woodev_yandex_delivery_poll_status`. Заказы в статусе `EXPORTING_PROCESS` обновление не меняет, а лишь показывает в отчёте.

## Что проверить в обмене с 1С

1. Принимает ли обмен значения `yandex_delivery_courier` и `yandex_delivery_pickup`, и остаётся ли старое `yandex_delivery_other_day` для истории.
2. Что делает обмен с `yandex_delivery_express`: такие старые заказы остаются, новых не будет.
3. Выгрузка номера заявки: ключ `_yandex_delivery_request_id` на месте.
4. Свой код на `wc_yandex_*`: сверьте с таблицей, прогоните на тестовой копии.
5. Вызовы `admin-ajax.php` и REST из внешних скриптов.
6. Тестовые заказы с курьером и пунктом выдачи: какой `method_id` попал в выгрузку.
