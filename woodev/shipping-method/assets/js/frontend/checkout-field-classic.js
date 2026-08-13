/**
 * Классический адаптер слоя чекаут-полей (`[woocommerce_checkout]`).
 *
 * §8 §3.2 / §5: тонкая jQuery-обвязка поверх фреймворк-агностичного стора
 * ({@link WoodevCheckoutFieldStore.createStore}, Task 10). Читает
 * локализованный конфиг, поднимает стор и усиливает нативные поля чекаута
 * WooCommerce через делегирование событий на `document.body` — переживает
 * `updated_checkout` и ре-рендер `country-select.js`, никогда не привязывается
 * повторно к конкретным элементам.
 *
 * Контрактных строк здесь не зашито: id полей, endpoint, nonce и id методов
 * доставки приходят исключительно из конфига. §8 НЕ рендерит карту/кнопку —
 * лишь готовит якорь `data-woodev-pickup-slot`, в который монтируется SP-5.
 *
 * Несколько конфигов (по одному на shipping-плагин, глобал
 * `woodev_checkout_field_config_<prefix>`) сосуществуют независимо; A2-гейт
 * учитывает поля из ВСЕХ сторов.
 *
 * @file
 * @since 2.0.2
 */

( function( $ ) {
	'use strict'

	var PREFIX = 'woodev_checkout_field_config_'
	var LOG    = '[woodev-checkout-field]'

	var factory = window.WoodevCheckoutFieldStore

	if( ! factory || typeof factory.createStore !== 'function' ) {
		return
	}

	// Каждая запись: { store, config }. Собирается по всем совпадающим глобалам.
	var stores = Object.keys( window ).filter( function( key ) {
		return key.indexOf( PREFIX ) === 0
	} ).map( function( key ) {
		var config = window[ key ]

		return {
			store:  factory.createStore( config ),
			config: config || {},
			// Per-child record of the PARENT value the child's own value is CONSISTENT
			// with. A cascade is destructive (it drops the child's value), so the drop
			// only happens when the parent actually CHANGED — see cascadeChild().
			resolved: {},
			// Per-child record of the PARENT value the child's OPTION SET was fetched
			// against. Separate from `resolved` on purpose: at page load the value is
			// already consistent with the rendered parent (nothing to drop) while the
			// options have never been fetched (a dependent select still needs them).
			fetched: {}
		}
	} ).filter( function( entry ) {
		return entry.config && entry.config.fields
	} )

	if( ! stores.length ) {
		return
	}

	// ---------------------------------------------------------------------
	// Общие помощники (портированы из checkout.js — тот же дом-стиль).
	// ---------------------------------------------------------------------

	/**
	 * Возвращает выбранное значение метода доставки.
	 *
	 * Reused from checkout.js: отмеченный радио либо единственный скрытый input.
	 *
	 * @returns {string}
	 */
	function selectedShippingMethod() {
		var $radios  = $( 'input[name^="shipping_method"]' )
		var $checked = $radios.filter( ':checked' )

		if( $checked.length ) {
			return $checked.val()
		}

		if( $radios.length === 1 ) {
			return $radios.val()
		}

		return ''
	}

	/**
	 * Возвращает текущую страну оформления.
	 *
	 * @returns {string}
	 */
	function currentCountry() {
		var $country = $( '#billing_country' )

		return $country.length ? ( $country.val() || '' ) : ''
	}

	/**
	 * Экранирует текст для безопасной вставки (защита от XSS-меток).
	 *
	 * @param {*} value
	 * @returns {string}
	 */
	function escapeHtml( value ) {
		return $( '<div/>' ).text( null === value || undefined === value ? '' : String( value ) ).html()
	}

	/**
	 * Логирует ошибку с префиксом, не выбрасывая исключение.
	 *
	 * @param {*} error
	 * @returns {void}
	 */
	function logError( error ) {
		if( window.console && typeof console.error === 'function' ) {
			console.error( LOG, error )
		}
	}

	/**
	 * Определяет select2/selectWoo, если WooCommerce его подгрузил.
	 *
	 * @returns {string} 'selectWoo' | 'select2' | ''
	 */
	function select2Method() {
		if( $.fn && typeof $.fn.selectWoo === 'function' ) {
			return 'selectWoo'
		}

		if( $.fn && typeof $.fn.select2 === 'function' ) {
			return 'select2'
		}

		return ''
	}

	// ---------------------------------------------------------------------
	// Работа с REST-источником полей.
	// ---------------------------------------------------------------------

	/**
	 * Собирает URL источника для поля: `<endpoint>/<fieldId>`.
	 *
	 * @param {Object} config
	 * @param {string} fieldId
	 * @returns {string}
	 */
	function sourceUrl( config, fieldId ) {
		var endpoint = config.endpoint || ''

		if( ! endpoint ) {
			return ''
		}

		return endpoint.replace( /\/+$/, '' ) + '/' + encodeURIComponent( fieldId )
	}

	/**
	 * Строит `<option>`-элементы из данных источника и вставляет их в select,
	 * восстанавливая прежнее значение, если оно ещё присутствует.
	 *
	 * Обработчики НЕ перевязываются — меняется только содержимое `<select>`.
	 *
	 * @param {jQuery}   $select
	 * @param {Object[]} options  Массив `{ value, label }`.
	 * @param {string}   previous Прежнее значение для восстановления.
	 * @returns {boolean} Было ли восстановлено прежнее значение.
	 */
	function placeholderText( config ) {
		return config && config.i18n && config.i18n.placeholder ? config.i18n.placeholder : 'Выберите…'
	}

	function fillSelect( $select, options, previous, placeholder ) {
		var ph      = placeholder !== undefined && placeholder !== null ? placeholder : ''
		var html    = '<option value="">' + escapeHtml( ph ) + '</option>'
		var matched = false
		var list    = options || []

		list.forEach( function( option ) {
			var value = option && option.value !== undefined ? String( option.value ) : ''
			var label = option && option.label !== undefined ? option.label : value

			if( previous && value === String( previous ) ) {
				matched = true
			}

			html += '<option value="' + escapeHtml( value ) + '">' + escapeHtml( label ) + '</option>'
		} )

		$select.html( html )

		if( matched ) {
			$select.val( String( previous ) )
		}

		return matched
	}

	// ---------------------------------------------------------------------
	// Каскад «родитель → потомок».
	// ---------------------------------------------------------------------

	/**
	 * Нормализует значение родителя к строке для сравнения «сменился ли он».
	 *
	 * `.val()` отдаёт `undefined` для отсутствующего элемента и `null` для
	 * `<select>` без опций, а с сервера то же значение приходит строкой —
	 * сравнение без нормализации объявляло бы смену там, где её нет.
	 *
	 * @param {*} value
	 * @returns {string}
	 */
	function cascadeKey( value ) {
		return value === undefined || value === null ? '' : String( value )
	}

	/**
	 * Запускает каскад для одного потомка: запрашивает источник, перезаполняет
	 * `<select>` из данных, восстанавливает значение.
	 *
	 * Каскад ДЕСТРУКТИВЕН — он роняет значение потомка, — поэтому чистка идёт
	 * только когда родитель РЕАЛЬНО сменился. WooCommerce на инициализации
	 * чекаута сам стреляет программным `change` по адресным полям (см. гейт
	 * `meaningful` ниже: непустое значение проходит его законно), и такой
	 * «холостой» каскад раньше синхронно обнулял и DOM, и стор. Стартовый
	 * `update_checkout` самого WooCommerce сериализовал форму спустя ~50 мс —
	 * то есть ЗАДОЛГО до ответа источника — и уносил пустое значение в
	 * `WC()->customer`, после чего сервер отдавал пустое поле уже на всех
	 * последующих загрузках. Ни одного `change` по самому потомку при этом не
	 * происходит: значение убивает не событие, а окно между чисткой и ответом.
	 *
	 * @param {Object} entry       Запись { store, config, resolved }.
	 * @param {string} childId     Id поля-потомка.
	 * @param {string} parentId    Id поля-родителя.
	 * @param {string} parentValue Текущее значение родителя.
	 * @returns {void}
	 */
	function cascadeChild( entry, childId, parentId, parentValue ) {
		var store    = entry.store
		var $child   = $( '#' + childId )
		var previous = store.getValue( childId )
		var changed  = entry.resolved[ childId ] !== parentValue

		if( changed ) {
			// Родитель сменился → значение потомка неактуально: чистим в сторе и в DOM.
			store.setValue( childId, '' )

			if( $child.length ) {
				$child.val( '' )
			}

			// После чистки состояние потомка согласовано с новым родителем.
			entry.resolved[ childId ] = parentValue
		}

		var url = sourceUrl( entry.config, childId )

		if( ! url || ! $child.length ) {
			refreshGate()
			return
		}

		// Набор опций для этого значения родителя уже загружен — повторный
		// «холостой» change не должен слать запрос ещё раз.
		if( entry.fetched[ childId ] === parentValue ) {
			refreshGate()
			return
		}

		entry.fetched[ childId ] = parentValue

		$.ajax( {
			url:      url,
			method:   'GET',
			dataType: 'json',
			data:     {
				parent:  parentValue !== undefined && parentValue !== null ? parentValue : '',
				country: currentCountry()
			},
			beforeSend: function( xhr ) {
				if( entry.config.nonce ) {
					xhr.setRequestHeader( 'X-WP-Nonce', entry.config.nonce )
				}
			}
		} ).done( function( response ) {
			// Перечитываем узел: takeover мог заменить <input> на <select>
			// (ensureSelect) пока запрос был в полёте, и захваченная ссылка
			// указывала бы на открепленный от документа элемент — запись в него
			// не видна ни покупателю, ни сериализации формы.
			$child = $( '#' + childId )

			if( ! $child.length ) {
				refreshGate()
				return
			}

			var options  = response && response.options ? response.options : []
			var restored = fillSelect( $child, options, previous, placeholderText( entry.config ) )

			// Значение, введённое пользователем, не теряем: держим прежнее в сторе
			// независимо от того, вернулась ли опция (спека: не «ронять» значение).
			store.setValue( childId, previous )

			if( ! restored && previous !== undefined && previous !== null && previous !== '' ) {
				if( changed ) {
					// Родитель сменился, и в новом наборе значения нет — оно
					// действительно устарело: убираем видимый выбор.
					$child.val( '' )
				} else {
					// Родитель ТОТ ЖЕ, значит значение законно. Источник мог просто
					// не вернуть его (усечённый/поисковый набор) — возвращаем как
					// выбранную опцию, а не выбрасываем.
					$child.append( new Option( previous, previous, true, true ) )
					$child.val( previous )
				}
			}

			maybeInitSelect2( entry, childId )
			refreshGate()
		} ).fail( function( xhr, status, error ) {
			// Набор опций не получен — значение родителя НЕ считается загруженным,
			// иначе повтор того же change больше никогда не догрузит потомка.
			delete entry.fetched[ childId ]
			logError( error || status || 'field-source request failed' )
			refreshGate()
		} )
	}

	/**
	 * Прогоняет каскад для всех потомков родителя.
	 *
	 * @param {Object} entry       Запись { store, config, resolved }.
	 * @param {string} parentId    Id изменившегося родителя.
	 * @param {string} parentValue Текущее значение родителя.
	 * @returns {void}
	 */
	function runCascade( entry, parentId, parentValue ) {
		entry.store.childrenOf( parentId ).forEach( function( childId ) {
			cascadeChild( entry, childId, parentId, parentValue )
		} )
	}

	// ---------------------------------------------------------------------
	// suggest-поля (select2/selectWoo typeahead) и takeover.
	// ---------------------------------------------------------------------

	/**
	 * Инициализирует select2/selectWoo для suggest-поля с remote-источником.
	 *
	 * Метки рендерятся через `.text()` (не `.html()`) — метка приходит уже
	 * `esc_html`'нутой с сервера, но клиент не должен ре-инъектить как HTML.
	 *
	 * @param {Object} entry   Запись { store, config }.
	 * @param {string} fieldId Id поля.
	 * @returns {void}
	 */
	function initSuggest( entry, fieldId ) {
		var method  = select2Method()
		var $select = $( '#' + fieldId )
		var url     = sourceUrl( entry.config, fieldId )

		// Only enhance an actual <select> (a text input stays native — a suggest field
		// left un-enhanced for a country the carrier does not serve). Without select2 the
		// native select remains; remote search is skipped. Skip a field that is ALREADY
		// select2-enhanced: re-initialising it on `updated_checkout` clears the current value.
		if( ! method || ! $select.length || ! url || ! $select.is( 'select' )
			|| $select.hasClass( 'select2-hidden-accessible' ) ) {
			return
		}

		var store  = entry.store
		var config = entry.config

		$select[ method ]( {
			minimumInputLength: 2,
			placeholder:        placeholderText( config ),
			// No custom templateResult/templateSelection: select2's default rendering
			// escapes the option text (escapeMarkup) and displays it correctly. A custom
			// template that returned a jQuery object rendered as "[object Object]" in the
			// selection box on the bundled selectWoo build. Labels are also esc_html'd by
			// the REST controller server-side.
			ajax: {
				url:      url,
				dataType: 'json',
				delay:    250,
				data: function( query ) {
					var field    = store.getField( fieldId )
					var parentId = field && field.depends_on ? field.depends_on : ''
					var parent   = parentId ? store.getValue( parentId ) : ''

					return {
						q:       query.term || '',
						country: currentCountry(),
						parent:  parent !== undefined && parent !== null ? parent : ''
					}
				},
				beforeSend: function( xhr ) {
					if( config.nonce ) {
						xhr.setRequestHeader( 'X-WP-Nonce', config.nonce )
					}
				},
				processResults: function( response ) {
					var options = response && response.options ? response.options : []

					return {
						results: options.map( function( option ) {
							return {
								id:   option && option.value !== undefined ? option.value : '',
								text: option && option.label !== undefined ? option.label : ''
							}
						} )
					}
				}
			}
		} )
	}

	/**
	 * Инициализирует select2 на поле, если оно suggest или активный takeover.
	 *
	 * @param {Object} entry   Запись { store, config }.
	 * @param {string} fieldId Id поля.
	 * @returns {void}
	 */
	function maybeInitSelect2( entry, fieldId ) {
		var field = entry.store.getField( fieldId )

		if( field && field.source_kind === 'suggest' ) {
			initSuggest( entry, fieldId )
		}
	}

	/**
	 * Применяет takeover для одного поля при заданной стране.
	 *
	 * `true` → превращаем поле в наш source-backed select (запрашиваем options,
	 * заполняем, инициализируем select2, восстанавливаем прежнее значение);
	 * `false` → оставляем нативное поле WC нетронутым. Гейтируется наличием
	 * поля в DOM.
	 *
	 * @param {Object} entry   Запись { store, config }.
	 * @param {string} fieldId Id поля.
	 * @param {string} country ISO-2 код страны.
	 * @returns {void}
	 */
	function ensureSelect( $field ) {
		if( $field.is( 'select' ) ) {
			return $field
		}

		// WooCommerce renders `billing_state` as a text <input> for countries with no WC
		// states (RU/BY/KZ/UZ). Replace it with a <select> — preserving id/name/class — so
		// our region options can populate it. (country-select.js keeps rewriting it back on
		// country change, which is why takeover re-runs on every country_to_state_changed.)
		var $sel = $( '<select><option value=""></option></select>' )
			.attr( 'id', $field.attr( 'id' ) || '' )
			.attr( 'name', $field.attr( 'name' ) || '' )
			.attr( 'class', $field.attr( 'class' ) || '' )

		$field.replaceWith( $sel )

		return $sel
	}

	function isWcManagedField( fieldId ) {
		// WooCommerce re-renders its own state fields on country change; leave those to WC
		// (reverting billing_state to a bare text input would destroy WC's US state <select>).
		return /(^|_)state$/.test( fieldId )
	}

	function ensureText( entry, fieldId ) {
		var $field = $( '#' + fieldId )

		if( ! $field.length || ! $field.is( 'select' ) ) {
			return
		}

		var method = select2Method()

		if( method && $field.hasClass( 'select2-hidden-accessible' ) ) {
			try { $field[ method ]( 'destroy' ) } catch( e ) {}
		}

		var value  = entry.store.getValue( fieldId )
		var $input = $( '<input type="text" />' )
			.attr( 'id', $field.attr( 'id' ) || '' )
			.attr( 'name', $field.attr( 'name' ) || '' )
			.attr( 'class', ( $field.attr( 'class' ) || '' ).replace( /select2\S*/g, '' ).replace( /\s+/g, ' ' ).trim() )
			.val( value !== undefined && value !== null ? value : '' )

		$field.replaceWith( $input )
	}

	function applyTakeover( entry, fieldId, country ) {
		// State fields are handled NATIVELY by WooCommerce — regions are injected as WC states
		// via the `woocommerce_states` server filter, so WC renders the <select> and persists
		// the value in its session (surviving update_checkout). Never DOM-convert them here.
		if( isWcManagedField( fieldId ) ) {
			return
		}

		var $field = $( '#' + fieldId )

		if( ! $field.length ) {
			return
		}

		// Takeover no longer applies for this country: revert a field WE converted back to a
		// native text input (e.g. the city autocomplete for the US).
		if( ! entry.store.takeoverFor( fieldId, country ) ) {
			ensureText( entry, fieldId )
			return
		}

		var store    = entry.store
		var previous = store.getValue( fieldId )
		var field    = store.getField( fieldId )
		var url      = sourceUrl( entry.config, fieldId )

		if( ! url ) {
			return
		}

		// suggest-takeover: гарантируем <select> (WC/сервер оставили text-input для
		// не-обслуживаемой страны), сохраняем ранее выбранное значение и вешаем typeahead.
		if( field && field.source_kind === 'suggest' ) {
			var current = $field.val() || previous
			var $sel    = ensureSelect( $field )

			if( current ) {
				// Takeover re-runs on every `country_to_state_changed`. When the field is ALREADY
				// a <select> (switching between two takeover countries) ensureSelect() returns it
				// untouched, so appending unconditionally accumulates a duplicate option per pass.
				var hasOption = $sel.find( 'option' ).filter( function() {
					return this.value === String( current )
				} ).length > 0

				if( hasOption ) {
					$sel.val( current )
				} else {
					$sel.append( new Option( current, current, true, true ) )
				}

				store.setValue( fieldId, current )
			}

			initSuggest( entry, fieldId )
			return
		}

		// options-takeover: гарантируем <select> (WC мог отрисовать text-input) и заливаем.
		$field = ensureSelect( $field )

		$.ajax( {
			url:      url,
			method:   'GET',
			dataType: 'json',
			data:     { country: country, parent: '' },
			beforeSend: function( xhr ) {
				if( entry.config.nonce ) {
					xhr.setRequestHeader( 'X-WP-Nonce', entry.config.nonce )
				}
			}
		} ).done( function( response ) {
			var options  = response && response.options ? response.options : []
			var restored = fillSelect( $field, options, previous, placeholderText( entry.config ) )
			var initSel2 = select2Method()

			if( restored ) {
				store.setValue( fieldId, previous )
			}

			if( initSel2 ) {
				$field[ initSel2 ]()
			}
		} ).fail( function( xhr, status, error ) {
			logError( error || status || 'takeover request failed' )
		} )
	}

	/**
	 * Прогоняет takeover по всем takeover-полям стора для страны.
	 *
	 * @param {Object} entry   Запись { store, config }.
	 * @param {string} country ISO-2 код страны.
	 * @returns {void}
	 */
	function runTakeover( entry, country ) {
		var fields = entry.store.allFields()

		Object.keys( fields ).forEach( function( fieldId ) {
			applyTakeover( entry, fieldId, country )
		} )
	}

	// ---------------------------------------------------------------------
	// Якорь слота ПВЗ.
	// ---------------------------------------------------------------------

	/**
	 * Резолвит DOM-якорь для одного места размещения ПВЗ-триггера (#274 п.3).
	 *
	 * `'review'` — после списка методов доставки. Это ЕДИНСТВЕННОЕ место, что было в
	 * фреймворке ДО #274, и замер разметки классического чекаута показал: оно уже
	 * совпадает с тем, где WooCommerce рендерит собственный хук
	 * `woocommerce_review_order_after_shipping` (список `#shipping_method` лежит
	 * ВНУТРИ `tr.shipping td`, а этот якорь вставляется СРАЗУ ПОСЛЕ списка — то есть
	 * внутри той же ячейки, ниже списка).
	 *
	 * `'rate'` — внутри `<li>` ВЫБРАННОГО тарифа, под его подписью — аналог хука
	 * `woocommerce_after_shipping_rate`, которого фреймворку не хватало. Ищет `<li>`
	 * отмеченного радио `input[name^="shipping_method"]`; если методов доставки на
	 * странице всего один, WooCommerce иногда рендерит его единственным радио без
	 * явного `checked` — тот же fallback, что уже применяет `selectedShippingMethod()`
	 * этого файла.
	 *
	 * Возвращает пустой jQuery-набор, а не бросает исключение, когда якорь ещё не в
	 * DOM — `placeSlot()` уже трактует пустой набор как «место размещения
	 * отсутствует, пропускаем без ошибки» для 'review'; то же самое правило теперь
	 * действует и для 'rate'.
	 *
	 * @param {string} placement `'review'` или `'rate'`.
	 * @returns {jQuery}
	 */
	function resolvePlacementAnchor( placement ) {
		if( placement === 'rate' ) {
			var $radios = $( 'input[name^="shipping_method"]' )
			var $target = $radios.filter( ':checked' )

			if( ! $target.length && $radios.length === 1 ) {
				$target = $radios
			}

			return $target.length ? $target.closest( 'li' ) : $()
		}

		var $anchor = $( '#shipping_method' ).first()

		if( ! $anchor.length ) {
			$anchor = $( '.woocommerce-shipping-methods' ).first()
		}

		return $anchor
	}

	/**
	 * Гарантирует наличие стабильного якоря `data-woodev-pickup-slot` для ОДНОГО места
	 * размещения (#274 п.3: поле может занимать несколько мест одновременно — см.
	 * {@see resolvePlacementAnchor}) и показывает/прячет его по требуемости поля.
	 *
	 * §8 не рендерит сюда карту/кнопку — SP-5 монтируется в этот якорь, по одному
	 * триггеру на каждый смонтированный слот (`pickup-mount.js`'s `mountOne()`).
	 * Паттерн размещения повторяет `placeControl` из checkout.js.
	 *
	 * @param {Object} entry     Запись { store, config }.
	 * @param {string} fieldId   Id pickup-поля (is_pickup_slot).
	 * @param {string} placement `'review'` или `'rate'` — см. {@see resolvePlacementAnchor}.
	 * @returns {void}
	 */
	function placeSlot( entry, fieldId, placement ) {
		var id    = 'woodev-pickup-slot-' + fieldId + '-' + placement
		var $slot = $( '#' + id )

		if( ! $slot.length ) {
			$slot = $(
				'<div id="' + id + '" data-woodev-pickup-slot="' + escapeHtml( fieldId ) + '"' +
				' data-woodev-pickup-placement="' + escapeHtml( placement ) + '" style="display:none;"></div>'
			)
		}

		var $anchor = resolvePlacementAnchor( placement )

		if( ! $anchor.length ) {
			// Цель размещения отсутствует — пропускаем без ошибки.
			return
		}

		if( placement === 'rate' ) {
			// Внутрь <li>, под подписью тарифа — не после него, там уже нет сиблингов.
			$anchor.append( $slot )
		} else {
			$anchor.after( $slot )
		}

		// Показываем якорь, только когда pickup-метод выбран (best-effort).
		if( entry.store.evaluateRequired( fieldId ) ) {
			$slot.show()
		} else {
			$slot.hide()
		}
	}

	/**
	 * Переразмещает якоря всех pickup-полей стора — по одному вызову
	 * {@see placeSlot} на КАЖДОЕ место из `field.pickup_slot_placements`
	 * (#274 п.3). Отсутствующий/не-массив список (`null`/`undefined` — PHP-сторона
	 * различает их с #308 п.2: {@see Checkout_Config::resolve_pickup_slot_placements()})
	 * деградирует к `[ 'review' ]` — поведению фреймворка ДО #274 — а не к пустому
	 * списку: разнородный флот, где это поле пришло от плагина на СТАРОЙ версии
	 * фреймворка (без ключа `pickup_slot_placements` в конфиге вовсе), не должен
	 * молча остаться совсем без триггера.
	 *
	 * ЯВНЫЙ пустой массив (`[]`) — это НЕ то же самое, что «список отсутствует»
	 * (#308 п.2, adversarial review для #274 п.3): плагин, чей фильтр
	 * `woodev_pickup_slot_placements` намеренно вернул `[]`, рисует свой собственный
	 * триггер и просит фреймворк не монтировать ни один якорь вообще. До фикса оба
	 * случая — «список отсутствует» и «список явно пуст» — схлопывались в один и тот
	 * же `[ 'review' ]`, так что такой плагин молча получал лишнюю кнопку фреймворка
	 * рядом со своей собственной. `Array.isArray()` — единственная проверка ниже,
	 * БЕЗ `.length`: PHP теперь гарантирует, что немассив (`null`) и явный пустой
	 * массив (`[]`) — разные значения на границе, так что здесь достаточно различать
	 * «массив» от «не массив», не заглядывая внутрь.
	 *
	 * @param {Object} entry Запись { store, config }.
	 * @returns {void}
	 */
	function placeSlots( entry ) {
		var fields = entry.store.allFields()

		Object.keys( fields ).forEach( function( fieldId ) {
			var field = fields[ fieldId ]

			if( ! field || ! field.is_pickup_slot ) {
				return
			}

			var placements = Array.isArray( field.pickup_slot_placements )
				? field.pickup_slot_placements
				: [ 'review' ]

			placements.forEach( function( placement ) {
				placeSlot( entry, fieldId, placement )
			} )
		} )
	}

	// ---------------------------------------------------------------------
	// A2-гейт (клиентский UX, сервер остаётся авторитетом).
	// ---------------------------------------------------------------------

	/**
	 * Пересчитывает A2-гейт по ВСЕМ сторам: если хоть одно требуемое поле пусто —
	 * блокирует «Оформить заказ»; иначе разблокирует.
	 *
	 * Сервер остаётся авторитетом — это только UX.
	 *
	 * НИКАКОГО inline-текста под полем (#274). Раньше здесь рисовалась подпись
	 * «Заполните обязательное поле.» под «Населённым пунктом» и под кнопкой выбора
	 * ПВЗ; ни СДЭК, ни Яндекс, ни Почта так не делают, и правило оператора то же:
	 * заблокированный контрол не поясняем — заблокирован и всё. Отключённая кнопка
	 * «Оформить заказ» и есть сигнал, а WooCommerce всё равно скажет своё при
	 * отправке формы. Вместе с текстом ушла и строка `i18n.required` из
	 * `Checkout_Handler::enqueue_*` — у неё не осталось потребителя.
	 *
	 * @returns {void}
	 */
	function refreshGate() {
		var blocked = false

		stores.forEach( function( entry ) {
			var fields = entry.store.allFields()

			Object.keys( fields ).forEach( function( fieldId ) {
				var required = entry.store.evaluateRequired( fieldId )
				var value    = entry.store.getValue( fieldId )
				var invalid  = required && ( value === undefined || value === null || String( value ) === '' )

				if( invalid ) {
					blocked = true
				}
			} )
		} )

		var $button = $( '#place_order' )

		if( $button.length ) {
			$button.prop( 'disabled', blocked )
		}
	}

	// ---------------------------------------------------------------------
	// Boot / prefill + делегированная привязка.
	// ---------------------------------------------------------------------

	/**
	 * Предзаполняет стор текущими значениями DOM, методом и страной.
	 *
	 * @param {Object} entry Запись { store, config }.
	 * @returns {void}
	 */
	function prefill( entry ) {
		var store  = entry.store
		var fields = store.allFields()

		Object.keys( fields ).forEach( function( fieldId ) {
			var $field = $( '#' + fieldId )

			if( $field.length ) {
				store.setValue( fieldId, $field.val() )
			}
		} )

		// Фиксируем, против какого значения родителя КАЖДЫЙ потомок уже разрешён на
		// момент загрузки: сервер отрендерил их согласованной парой. Без этого
		// стартовый программный `change` от WooCommerce читается как смена родителя
		// и «холостой» каскад роняет только что отданное сервером значение.
		Object.keys( fields ).forEach( function( fieldId ) {
			var parentId = fields[ fieldId ] && fields[ fieldId ].depends_on
				? fields[ fieldId ].depends_on
				: ''

			if( ! parentId ) {
				return
			}

			var $parent = $( '#' + parentId )

			if( $parent.length ) {
				entry.resolved[ fieldId ] = cascadeKey( $parent.val() )
			}
		} )

		store.setChosenMethod( selectedShippingMethod() )
		store.setCountry( currentCountry() )
	}

	/**
	 * Возвращает запись стора, которому принадлежит поле с данным id.
	 *
	 * @param {string} fieldId
	 * @returns {Object|null}
	 */
	function entryForField( fieldId ) {
		for( var i = 0; i < stores.length; i++ ) {
			if( stores[ i ].store.getField( fieldId ) ) {
				return stores[ i ]
			}
		}

		return null
	}

	/**
	 * Прогоняет каскад по всем сторам, где данное поле — родитель потомка.
	 *
	 * Учитывает и нативных родителей (`billing_country`/`billing_state`),
	 * которыми фреймворк не владеет, но на которые ссылается `depends_on`.
	 *
	 * @param {string} parentId
	 * @returns {void}
	 */
	function cascadeFromParent( parentId, parentValue ) {
		stores.forEach( function( entry ) {
			if( entry.store.childrenOf( parentId ).length ) {
				runCascade( entry, parentId, cascadeKey( parentValue ) )
			}
		} )
	}

	$( function() {

		// 1. Boot / prefill + первичный гейт + слоты + suggest.
		stores.forEach( function( entry ) {
			prefill( entry )
			placeSlots( entry )

			var fields = entry.store.allFields()

			Object.keys( fields ).forEach( function( fieldId ) {
				maybeInitSelect2( entry, fieldId )
			} )
		} )

		refreshGate()

		// Re-assert takeover for the current country AFTER WooCommerce's country-select.js
		// has done its initial state-field render (it rewrites billing_state to a text input
		// for stateless countries). Deferred a tick so it runs after WC's ready handlers.
		window.setTimeout( function() {
			stores.forEach( function( entry ) {
				runTakeover( entry, currentCountry() )
			} )
			refreshGate()
		}, 0 )

		// 2. Делегированное отслеживание изменений управляемых полей.
		$( document.body ).on( 'change', function( event ) {
			var id    = event.target && event.target.id ? event.target.id : ''
			var entry = id ? entryForField( id ) : null
			var value = id ? $( event.target ).val() : ''

			// WooCommerce re-renders address fields on `update_checkout` and fires PROGRAMMATIC
			// changes on them (jQuery .trigger, so no originalEvent): an empty value, or the
			// state wildcard "*" for a `RU:*`-style base country. Such spurious changes must NOT
			// wipe the external store nor trigger a cascade. `*` is WooCommerce's "any state"
			// wildcard and is never a real user selection, so it is always ignored; an empty
			// value is honoured only from a real user event (a deliberate clear).
			var meaningful = '*' !== value
				&& ( !! event.originalEvent || ( value !== '' && value !== null && value !== undefined ) )

			if( entry ) {
				if( meaningful ) {
					entry.store.setValue( id, value )
				}

				refreshGate()
			}

			// Изменение поля-родителя (в т.ч. нативного) → каскад потомков.
			if( id && meaningful ) {
				cascadeFromParent( id, value )
			}
		} )

		// 2b. Смена метода доставки → chosenMethod + гейт + видимость слотов.
		$( document.body ).on(
			'change',
			'input[name^="shipping_method"], select[name^="shipping_method"]',
			function() {
				var method = selectedShippingMethod()

				stores.forEach( function( entry ) {
					entry.store.setChosenMethod( method )
					placeSlots( entry )
				} )

				refreshGate()
			}
		)

		// 5. Takeover — детерминированное событие WC (после ре-рендера billing_state).
		$( document.body ).on( 'country_to_state_changed', function( event, country ) {
			var value = country || currentCountry()

			stores.forEach( function( entry ) {
				entry.store.setCountry( value )
				runTakeover( entry, value )
			} )

			refreshGate()
		} )

		// 4. updated_checkout: восстановление значений + re-init select2 + слоты.
		$( document.body ).on( 'updated_checkout', function() {
			stores.forEach( function( entry ) {
				var store  = entry.store
				var fields = store.allFields()

				Object.keys( fields ).forEach( function( fieldId ) {
					var $field = $( '#' + fieldId )

					// Restore is a SAFETY NET, not an overwrite: only put the stored value back
					// when WooCommerce actually cleared the field (DOM empty) but the store still
					// holds it. Overwriting unconditionally clobbered a value the field still had
					// (WC does not re-render non-state fields on update_checkout).
					//
					// WC-managed state fields are EXCLUDED: WooCommerce owns them (regions are
					// injected server-side via `woocommerce_states` and the choice lives in WC's
					// session). After a country change WC legitimately renders an empty state
					// field, and restoring the previous country's region would resurrect a stale
					// value whenever it happens to exist in the new country's option set.
					if( ! isWcManagedField( fieldId ) && $field.length && ! $field.val() ) {
						var stored = store.getValue( fieldId )

						if( stored !== undefined && stored !== null && stored !== '' ) {
							$field.val( stored )

							if( $field.hasClass( 'select2-hidden-accessible' ) ) {
								$field.trigger( 'change.select2' )
							}
						}
					}

					maybeInitSelect2( entry, fieldId )
				} )

				placeSlots( entry )
			} )

			refreshGate()
		} )
	} )

} )( jQuery )
