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
			config: config || {}
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
	 * Запускает каскад для одного потомка: чистит значение, запрашивает
	 * источник, перезаполняет `<select>` из данных, восстанавливает значение.
	 *
	 * @param {Object} entry    Запись { store, config }.
	 * @param {string} childId  Id поля-потомка.
	 * @param {string} parentId Id поля-родителя.
	 * @returns {void}
	 */
	function cascadeChild( entry, childId, parentId ) {
		var store    = entry.store
		var $child   = $( '#' + childId )
		var previous = store.getValue( childId )

		// Значение потомка теперь неактуально: чистим в сторе и в DOM.
		store.setValue( childId, '' )

		if( $child.length ) {
			$child.val( '' )
		}

		var url = sourceUrl( entry.config, childId )

		if( ! url || ! $child.length ) {
			refreshGate()
			return
		}

		$.ajax( {
			url:      url,
			method:   'GET',
			dataType: 'json',
			data:     {
				parent:  store.getValue( parentId ) !== undefined ? store.getValue( parentId ) : '',
				country: currentCountry()
			},
			beforeSend: function( xhr ) {
				if( entry.config.nonce ) {
					xhr.setRequestHeader( 'X-WP-Nonce', entry.config.nonce )
				}
			}
		} ).done( function( response ) {
			var options  = response && response.options ? response.options : []
			var restored = fillSelect( $child, options, previous, placeholderText( entry.config ) )

			// Значение, введённое пользователем, не теряем: держим прежнее в сторе
			// независимо от того, вернулась ли опция (спека: не «ронять» значение).
			store.setValue( childId, previous )

			if( ! restored ) {
				// В DOM опции нет — очищаем видимый выбор, но храним в сторе.
				$child.val( '' )
			}

			maybeInitSelect2( entry, childId )
			refreshGate()
		} ).fail( function( xhr, status, error ) {
			logError( error || status || 'field-source request failed' )
			refreshGate()
		} )
	}

	/**
	 * Прогоняет каскад для всех потомков родителя.
	 *
	 * @param {Object} entry    Запись { store, config }.
	 * @param {string} parentId Id изменившегося родителя.
	 * @returns {void}
	 */
	function runCascade( entry, parentId ) {
		entry.store.childrenOf( parentId ).forEach( function( childId ) {
			cascadeChild( entry, childId, parentId )
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
		// native select remains; remote search is skipped.
		if( ! method || ! $select.length || ! url || ! $select.is( 'select' ) ) {
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

	function applyTakeover( entry, fieldId, country ) {
		var $field = $( '#' + fieldId )

		// Гейт: применяем только когда наше поле присутствует/активно.
		if( ! $field.length || ! entry.store.takeoverFor( fieldId, country ) ) {
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
		// не-обслуживаемой страны) и вешаем typeahead.
		if( field && field.source_kind === 'suggest' ) {
			ensureSelect( $field )
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
	 * Гарантирует наличие стабильного якоря `data-woodev-pickup-slot` рядом с
	 * методами доставки и показывает/прячет его по требуемости поля.
	 *
	 * §8 не рендерит сюда карту/кнопку — SP-5 монтируется в этот якорь.
	 * Паттерн размещения повторяет `placeControl` из checkout.js.
	 *
	 * @param {Object} entry   Запись { store, config }.
	 * @param {string} fieldId Id pickup-поля (is_pickup_slot).
	 * @returns {void}
	 */
	function placeSlot( entry, fieldId ) {
		var id     = 'woodev-pickup-slot-' + fieldId
		var $slot  = $( '#' + id )

		if( ! $slot.length ) {
			$slot = $( '<div id="' + id + '" data-woodev-pickup-slot="' + escapeHtml( fieldId ) + '" style="display:none;"></div>' )
		}

		var $anchor = $( '#shipping_method' ).first()

		if( ! $anchor.length ) {
			$anchor = $( '.woocommerce-shipping-methods' ).first()
		}

		if( $anchor.length ) {
			$anchor.after( $slot )
		} else {
			// Цель размещения отсутствует — пропускаем без ошибки.
			return
		}

		// Показываем якорь, только когда pickup-метод выбран (best-effort).
		if( entry.store.evaluateRequired( fieldId ) ) {
			$slot.show()
		} else {
			$slot.hide()
		}
	}

	/**
	 * Переразмещает якоря всех pickup-полей стора.
	 *
	 * @param {Object} entry Запись { store, config }.
	 * @returns {void}
	 */
	function placeSlots( entry ) {
		var fields = entry.store.allFields()

		Object.keys( fields ).forEach( function( fieldId ) {
			if( fields[ fieldId ] && fields[ fieldId ].is_pickup_slot ) {
				placeSlot( entry, fieldId )
			}
		} )
	}

	// ---------------------------------------------------------------------
	// A2-гейт (клиентский UX, сервер остаётся авторитетом).
	// ---------------------------------------------------------------------

	/**
	 * Показывает/убирает inline-ошибку рядом с полем (или его pickup-якорем).
	 *
	 * @param {Object}  entry   Запись { store, config }.
	 * @param {string}  fieldId Id поля.
	 * @param {boolean} invalid Требуется ли показать ошибку.
	 * @returns {void}
	 */
	function toggleFieldError( entry, fieldId, invalid ) {
		var field    = entry.store.getField( fieldId )
		var errorId  = 'woodev-checkout-field-error-' + fieldId
		var $error   = $( '#' + errorId )
		var $anchor  = field && field.is_pickup_slot
			? $( '[data-woodev-pickup-slot="' + fieldId + '"]' ).first()
			: $( '#' + fieldId ).first()

		if( ! invalid ) {
			$error.remove()
			return
		}

		if( ! $anchor.length ) {
			return
		}

		if( ! $error.length ) {
			$error = $( '<span id="' + errorId + '" class="woodev-checkout-field-error" role="alert"></span>' )
			$anchor.after( $error )
		}

		var i18nRequired = ( entry.config && entry.config.i18n && entry.config.i18n.required )
			? entry.config.i18n.required
			: 'Заполните обязательное поле.'
		$error.text( i18nRequired )
	}

	/**
	 * Пересчитывает A2-гейт по ВСЕМ сторам: если хоть одно требуемое поле пусто —
	 * блокирует «Оформить заказ» и показывает inline-ошибку; иначе разблокирует.
	 *
	 * Сервер остаётся авторитетом — это только UX.
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

				toggleFieldError( entry, fieldId, invalid )

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
	function cascadeFromParent( parentId ) {
		stores.forEach( function( entry ) {
			if( entry.store.childrenOf( parentId ).length ) {
				runCascade( entry, parentId )
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

			if( entry ) {
				entry.store.setValue( id, $( event.target ).val() )
				refreshGate()
			}

			// Изменение поля-родителя (в т.ч. нативного) → каскад потомков.
			if( id ) {
				cascadeFromParent( id )
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
					var stored = store.getValue( fieldId )

					if( $field.length && stored !== undefined && stored !== null ) {
						$field.val( stored )
					}

					maybeInitSelect2( entry, fieldId )
				} )

				placeSlots( entry )
			} )

			refreshGate()
		} )
	} )

} )( jQuery )
