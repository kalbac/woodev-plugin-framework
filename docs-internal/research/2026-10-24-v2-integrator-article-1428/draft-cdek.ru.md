# Переход на версию 2: что меняется для разработчиков и интеграций (1С, CRM) — СДЭК для WooCommerce

Статья для тех, кто подключал к магазину обмен с 1С, CRM или писал свой код под плагин СДЭК. Если вы только настраиваете доставку, вам сюда не нужно: настройки магазина при обновлении переносятся сами.

**Главное.** В версии 2 у способов доставки изменились идентификаторы (`method_id`), а часть хуков плагина больше не вызывается. Всё, что в вашем обмене или коде завязано на `edostavka`, нужно проверить.

## Идентификаторы способов доставки

В актуальной версии 1 был один способ `edostavka` на все тарифы. В версиях до 2.2 встречались также строки способов `edostavka-<тариф>`; миграция v2 их не преобразует, такие строки нужно проверить отдельно. В версии 2 способов три, по типу доставки:

| Было (версия 1) | Стало (версия 2) | Когда |
|---|---|---|
| `edostavka` | `edostavka_courier` | курьерские тарифы |
| `edostavka` | `edostavka_pickup` | тарифы «до пункта выдачи» (режимы 2 и 4) |
| `edostavka` | `edostavka_postamat` | тарифы «до постамата» (режимы 6 и 7) |

Что делает обновление с зонами доставки: способ в зоне остаётся на месте, с тем же номером экземпляра (`instance_id`), но получает новый идентификатор по тарифу, выбранному в этом способе. Настройки способа копируются под новое имя опции, например `woocommerce_edostavka_courier_<instance_id>_settings` вместо `woocommerce_edostavka_<instance_id>_settings`; старая опция остаётся на случай отката. В настройках оплаты при получении («Доступно для способов доставки») `edostavka` заменяется на три новых идентификатора, а `edostavka:12` — на `edostavka_courier:12` (или `_pickup`, `_postamat`) по тому же номеру.

> **Внимание: старые заказы сохраняют старый идентификатор.** Обновление не переписывает строки доставки уже оформленных заказов. У заказов, созданных на версии 1, в строке доставки по-прежнему `method_id = edostavka`; у новых заказов — `edostavka_courier`, `edostavka_pickup` или `edostavka_postamat`. Сам плагин понимает оба варианта. Ваш обмен должен понимать их тоже.

Если в 1С или CRM сопоставление способов доставки сделано по `method_id` строго по равенству с `edostavka`, новые заказы перестанут сопоставляться. Так и случилось в обращении, из-за которого написана эта статья.

Также учтите: стандартный фильтр WooCommerce `woocommerce_shipping_edostavka_is_available` строится из идентификатора способа. После обновления он относится к старому имени; для новых способов нужны `woocommerce_shipping_edostavka_courier_is_available` и аналоги.

## Мета-данные заказа

Ключи, которые читают обмены, сохранились:

| Ключ | Что хранит | Версия 2 |
|---|---|---|
| `_wc_edostavka_cdek_order_id` | UUID заказа в СДЭК | без изменений |
| `_wc_edostavka_tracking_code` | трек-номер СДЭК | без изменений |
| `_wc_edostavka_status` | статус заказа в СДЭК | без изменений |
| `_wc_edostavka_shipping` | отметка «заказ доставляет СДЭК» | без изменений |
| `_wc_edostavka_chosen_delivery_point` | выбранный пункт выдачи | у старых заказов читается; **новые заказы пишут** `_wc_edostavka_pickup_point_data` |
| `_wc_edostavka_history_statuses`, `_wc_edostavka_latest_order_update_time` | история статусов | новая версия их больше не пишет и не читает |
| `_wc_edostavka_can_courier_call`, `_wc_edostavka_courier_already_called` | служебные пометки вызова курьера | не переносятся |

Настройки плагина остаются в опции `woocommerce_edostavka_settings` под прежними именами (логин и пароль API, данные отправителя, префикс номера заказа).

## Хуки: что осталось, что заменено, что удалено

Если в вашей теме или плагине есть `add_action`/`add_filter` на хуки `wc_edostavka_*`, проверьте их по таблицам. При включённом `WP_DEBUG` плагин показывает уведомление о каждом удалённом хуке из первой таблицы (список составлен по тем хукам, которыми, скорее всего, пользовались). Остальные удалённые хуки молча перестают вызываться.

**Сохранились без изменений**

| Хук | Тип |
|---|---|
| `wc_edostavka_yandex_map_apikey` | фильтр |
| `woodev_yandex_map_api_key` | фильтр |

**Заменены (имя и аргументы другие, старые обработчики не вызываются)**

| Был | Стал |
|---|---|
| фильтр `wc_edostavka_delivery_rates_item($rate_atts, $rate, $package, $method)` и `wc_edostavka_delivery_rates_item_total_cost($total, $rate, $package)` | фильтр `woodev_shipping_method_calculated_rate($rate, $package, $method)` |
| действие `wc_edostavka_delivery_rate_calculate_shipping($rate, $package, $method)` | действие `woodev_shipping_method_after_calculate_shipping($package, $rate, $method)` |
| `wc_edostavka_order_status_changed($old, $new, $order_id)`, `wc_edostavka_order_mark_as_delivered($order_id, $status)` | действие `woodev_shipping_delivery_status_changed($order, $previous_status, $current_status, $provider)` |
| `wc_edostavka_before_order_update`, `wc_edostavka_after_order_update` | `woodev_shipping_order_exported($order, $carrier_order_id)` (другое событие: выгрузка заказа, а не опрос статуса) |
| `wc_edostavka_export_order_api_params` | `woodev_shipping_order_export_requested($order)` — действие перед отправкой, изменить данные запроса им нельзя |
| `wc_edostavka_order_statuses` | `woodev_shipping_delivery_status_resolved($canonical, $raw, $status_map)` |
| `wc_edostavka_method_instruction_text`, `wc_edostavka_method_additional_info_strings` | `woodev_shipping_rate_additional_info($blocks, $rate, $method, $index, $plugin)` |
| `wc_edostavka_shop_tariffs_data`, `wc_edostavka_delivery_tariffs_data`, `wc_edostavka_china_tariffs_data` | `woodev_edostavka_tariff_choices($options, $allowed_modes)` |
| `wc_edostavka_customer_location_data`, `wc_edostavka_set_customer_location` | `woodev_customer_location_saved($record, $implicit, $user_id)` |

Пример: подписка на изменение цены доставки.

```php
// Было
add_filter( 'wc_edostavka_delivery_rates_item', function ( $rate_atts, $cdek_rate, $package, $method ) {
	$rate_atts['cost'] += 50;
	return $rate_atts;
}, 10, 4 );

// Стало: приходит объект тарифа, вернуть можно его же или null (способ не показывать)
add_filter( 'woodev_shipping_method_calculated_rate', function ( $rate, $package, $method ) {
	return $rate;
}, 10, 3 );
```

Пример: реакция на смену статуса. Статусы теперь общие, не коды СДЭК: `pending`, `created`, `in_transit`, `ready_for_pickup`, `delivered`, `returning`, `returned`, `failed`, `cancelled`, `unknown`. Событие приходит от всех служб доставки, поэтому проверяйте `$provider`.

```php
add_action( 'woodev_shipping_delivery_status_changed', function ( $order, $previous, $current, $provider ) {
	if ( 'edostavka' === $provider && 'delivered' === $current ) {
		// ...
	}
}, 10, 4 );
```

**Удалены без замены.** `wc_edostavka_checkout_params`, `wc_edostavka_widget_map_params`, `wc_edostavka_native_widget_map_params`, `wc_edostavka_suggestions_plugin_default_params`, `wc_edostavka_is_admin`, `wc_edostavka_checkout_hidden_or_remove_state_field`, `wc_edostavka_checkout_hidden_or_remove_postcode_field`, `wc_cdek_shipping_rates_calc_params`, `wc_edostavka_shipping_method_delivery_points_request_params`, `wc_edostavka_disabled_statuses_for_export`, `wc_edostavka_order_package`, `wc_edostavka_order_package_item`, `wc_edostavka_update_tracking_code`, `wc_edostavka_delete_tracking_code`, `woocommerce_edostavka_email_tracking_core_url`, `wc_edostavka_after_send_email_*`, `wc_edostavka_extra_services_list`, `wc_edostavka_carton_boxes`, `wc_edostavka_customer_default_city_code`, `wc_cdek_shipping_integration_form_fields`.

Без уведомления перестали вызываться и другие хуки версии 1, которых нет в карте `get_deprecated_hooks()`: `edostavka_update_order_review_address_args`, `edostavka_update_order_review_postcode_args`, `edostavka_update_order_review_state_args`, `wc_edostavka_after_details_order_table`, `wc_edostavka_after_order_preview_table`, `wc_edostavka_ajax_rate_limit`, `wc_edostavka_allowed_countries_for_state`, `wc_edostavka_api_base_url`, `wc_edostavka_before_order_preview_table`, `wc_edostavka_before_order_update_save_meta`, `wc_edostavka_cod_payment_methods`, `wc_edostavka_create_order_barcode_params`, `wc_edostavka_create_order_waybill_params`, `wc_edostavka_currencies`, `wc_edostavka_customer_allow_geo_location`, `wc_edostavka_customer_location_fields`, `wc_edostavka_customer_set_default_destination`, `wc_edostavka_delivery_photo_ready`, `wc_edostavka_enable_admin_help_tab`, `wc_edostavka_excluded_location_region_ids`, `wc_edostavka_generate_delivery_point_button_args`, `wc_edostavka_integration_params`, `wc_edostavka_locale`, `wc_edostavka_location_cities_sort_results`, `wc_edostavka_location_regions_list`, `wc_edostavka_order_is_delivered`, `wc_edostavka_order_is_editable`, `wc_edostavka_order_need_create_notice`, `wc_edostavka_order_preview_details`, `wc_edostavka_order_status_changed_from_{старый}_to_{новый}`, `wc_edostavka_order_status_changed_to_{статус}`, `wc_edostavka_order_statuses_to_create_notice`, `wc_edostavka_order_{статус}_notification`, `wc_edostavka_preloaded_data_locations`, `wc_edostavka_print_form_ready`, `wc_edostavka_status_keys_for_show`, `woocommerce_edostavka_cart_delivery_points_template`, `woocommerce_edostavka_disallow_city_ids`, `woocommerce_edostavka_order_number`, `woocommerce_edostavka_shipping_id`. Для `wc_edostavka_print_form_ready` в версии 2 есть `woodev_edostavka_document_ready($type)`, но у него другие аргументы.

## Адреса, AJAX и расписание

- Запросы `admin-ajax.php` с действиями `edostavka_get_location_cities`, `edostavka_get_deliverypoints`, `edostavka_set_delivery_point`, `edostavka_order_action`, `edostavka_create_courier_call` и остальными из версии 1 удалены.
- Адреса уведомлений `?wc-api=wc_edostavka_order_status` и `?wc-api=wc_edostavka_orders` принимаются совместимым обработчиком версии 2. Адреса `wc_edostavka_print_form` и `wc_edostavka_download_photo` в релизе не найдены; проверьте, если они вам нужны.
- Регулярный опрос статусов теперь идёт через планировщик действий WooCommerce каждые 15 минут; настройка интервала убрана.

## Что проверить в обмене с 1С

1. Сопоставление способов доставки: принимает ли обмен значения `edostavka_courier`, `edostavka_pickup`, `edostavka_postamat` **и** `edostavka` для старых заказов.
2. Не ищет ли обмен пункт выдачи по `_wc_edostavka_chosen_delivery_point`: у новых заказов он в `_wc_edostavka_pickup_point_data`.
3. Выгрузка трек-номера: ключ `_wc_edostavka_tracking_code` на месте.
4. Свой код на хуках `wc_edostavka_*`: сверьте со списками выше, включите `WP_DEBUG` на тестовой копии.
5. Правила «способ доставки → склад / служба» в 1С для обоих наборов идентификаторов.
6. Выполните тестовый заказ с каждым из трёх способов и посмотрите, какой `method_id` попал в выгрузку.
