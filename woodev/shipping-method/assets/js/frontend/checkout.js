/**
 * Привязка карты пунктов выдачи (ПВЗ) к оформлению заказа WooCommerce.
 *
 * Specialization из §4.2: связывает радиокнопку метода-ПВЗ с модальным окном →
 * поднимает provider-agnostic ядро карты ({@link WoodevPickupMap}) с активным
 * адаптером и его конфигом → по выбору точки пишет её код в скрытое поле и
 * показывает балун. Запись выбора на сервер (`selectPoint` AJAX) выполняет само
 * ядро карты внутри `handleSelect`; этот скрипт лишь даёт колбэк `onSelect`.
 *
 * Никаких контрактных строк здесь не зашито — id методов, id скрытого поля,
 * имена AJAX-действий и nonce приходят из локализованного конфига, который собрал
 * {@link Pickup_Checkout_Handler}.
 *
 * @file
 */

( function( $ ) {
	'use strict'

	var params = window.woodev_pickup_checkout_params

	if( ! params || ! Array.isArray( params.methodIds ) || ! params.methodIds.length ) {
		return
	}

	// Ядро карты, поднимается лениво при первом открытии модального окна.
	var map = null

	// Последняя выбранная точка — переотрисовывается при обновлении блока методов.
	var chosen = params.selected && params.selected.id ? params.selected : null

	/**
	 * Возвращает выбранное значение метода доставки.
	 *
	 * @returns {string}
	 */
	function selectedShippingMethod() {
		var $radios = $( 'input[name^="shipping_method"]' )
		var $checked = $radios.filter( ':checked' )

		if( $checked.length ) {
			return $checked.val()
		}

		// Единственный доступный метод выводится скрытым input'ом.
		if( $radios.length === 1 ) {
			return $radios.val()
		}

		return ''
	}

	/**
	 * Является ли значение метода ПВЗ-методом.
	 *
	 * Значение WooCommerce имеет форму `method_id:instance_id`, поэтому проверяем и
	 * точное совпадение, и префикс `method_id:`.
	 *
	 * @param {string} value
	 * @returns {boolean}
	 */
	function isPickupMethod( value ) {
		if( ! value ) {
			return false
		}

		return params.methodIds.some( function( id ) {
			return value === id || value.indexOf( id + ':' ) === 0
		} )
	}

	/**
	 * Открывает модальное окно и поднимает карту по требованию.
	 *
	 * @returns {void}
	 */
	function openModal() {
		var $modal = $( '#' + params.modalId )

		if( ! $modal.length ) {
			return
		}

		$modal.addClass( 'is-open' ).attr( 'aria-hidden', 'false' )
		bootMap()
	}

	/**
	 * Закрывает модальное окно.
	 *
	 * @returns {void}
	 */
	function closeModal() {
		$( '#' + params.modalId ).removeClass( 'is-open' ).attr( 'aria-hidden', 'true' )
	}

	/**
	 * Инициализирует ядро карты один раз.
	 *
	 * @returns {void}
	 */
	function bootMap() {
		if( map || typeof window.WoodevPickupMap !== 'function' ) {
			return
		}

		var AdapterCtor = window[ params.adapter ]

		if( typeof AdapterCtor !== 'function' ) {
			return
		}

		map = new window.WoodevPickupMap( '#' + params.mapId, {
			adapter: new AdapterCtor(),
			ajaxUrl: params.ajaxUrl,
			nonce: params.nonce,
			actions: params.actions,
			mapConfig: params.mapConfig || {},
			requestParams: params.requestParams || {},
			onSelect: handlePointSelected
		} )

		map.init().catch( function( error ) {
			// Tear the map down on init() failure so a later open can re-create it. init()
			// initializes the Leaflet container (adapter.init) BEFORE its first fetchPoints,
			// so a transient fetch failure leaves Leaflet initialized; destroy() removes it.
			// Nulling `map` alone would make the retry throw "Map container is already
			// initialized" -- bootMap() early-returns while `map` is set, and a fresh
			// instance on the same container collides. destroy() + reset lets it rebuild.
			if( map && typeof map.destroy === 'function' ) {
				map.destroy()
			}

			map = null

			if( window.console && typeof console.error === 'function' ) {
				console.error( '[woodev-pickup-checkout]', error )
			}
		} )
	}

	/**
	 * Обрабатывает подтверждённый выбор точки.
	 *
	 * @param {Object} point Нормализованная точка выдачи.
	 * @returns {void}
	 */
	function handlePointSelected( point ) {
		if( ! point ) {
			return
		}

		chosen = point

		$( '#' + params.fieldId ).val( point.id ).trigger( 'change' )

		renderBalloon( point )
		closeModal()
	}

	/**
	 * Рендерит балун выбранной точки из шаблона.
	 *
	 * @param {Object} point
	 * @returns {void}
	 */
	function renderBalloon( point ) {
		var $summary = control().find( '.woodev-pickup-checkout__summary' )
		var template = $( '#' + params.balloonTemplateId ).html()

		if( ! template ) {
			$summary.text( point.name || point.address_full || '' )
			return
		}

		var html = template
			.replace( /\{\{\s*name\s*\}\}/g, escapeHtml( point.name || '' ) )
			.replace( /\{\{\s*address\s*\}\}/g, escapeHtml( point.address_full || '' ) )
			.replace( /\{\{\s*description\s*\}\}/g, escapeHtml( describe( point ) ) )

		$summary.html( html )
	}

	/**
	 * Собирает строку описания точки (часы работы или телефон).
	 *
	 * @param {Object} point
	 * @returns {string}
	 */
	function describe( point ) {
		if( Array.isArray( point.work_hours ) && point.work_hours.length ) {
			return point.work_hours.join( ', ' )
		}

		return point.phone || ''
	}

	/**
	 * Экранирует текст для безопасной вставки в шаблон балуна.
	 *
	 * @param {*} value
	 * @returns {string}
	 */
	function escapeHtml( value ) {
		return $( '<div/>' ).text( null === value || undefined === value ? '' : String( value ) ).html()
	}

	/**
	 * Контейнер управления (кнопка + балун); создаётся при отсутствии в DOM.
	 *
	 * Блок методов доставки перерисовывается на `updated_checkout`, поэтому контейнер
	 * пересоздаётся и переподключается при каждом обращении, если был удалён.
	 *
	 * @returns {jQuery}
	 */
	function control() {
		var id = 'woodev-pickup-checkout-' + params.fieldId
		var $control = $( '#' + id )

		if( $control.length ) {
			return $control
		}

		var label = params.i18n && params.i18n.choose ? params.i18n.choose : 'Select a pickup point'

		$control = $(
			'<div id="' + id + '" class="woodev-pickup-checkout" style="display:none;">' +
				'<button type="button" class="button woodev-pickup-checkout__trigger"></button>' +
				'<div class="woodev-pickup-checkout__summary"></div>' +
			'</div>'
		)

		$control.find( '.woodev-pickup-checkout__trigger' ).text( label ).on( 'click', openModal )

		return $control
	}

	/**
	 * Размещает контейнер управления рядом со списком методов доставки.
	 *
	 * @returns {void}
	 */
	function placeControl() {
		var $control = control()
		var $anchor = $( '#shipping_method' ).first()

		if( ! $anchor.length ) {
			$anchor = $( '.woocommerce-shipping-methods' ).first()
		}

		if( $anchor.length ) {
			$anchor.after( $control )
		}
	}

	/**
	 * Показывает/прячет управление по выбранному методу и переотрисовывает балун.
	 *
	 * @returns {void}
	 */
	function refresh() {
		placeControl()

		if( isPickupMethod( selectedShippingMethod() ) ) {
			control().show()

			if( chosen ) {
				renderBalloon( chosen )
			}
		} else {
			control().hide()
		}
	}

	$( function() {

		// Предзаполнение ранее выбранной точкой (из сессии).
		if( chosen ) {
			$( '#' + params.fieldId ).val( chosen.id )
		}

		refresh()

		$( document.body ).on( 'change', 'input[name^="shipping_method"], select[name^="shipping_method"]', refresh )
		$( document.body ).on( 'updated_checkout', refresh )

		// Закрытие модального окна: кнопка/оверлей/Esc.
		$( document ).on( 'click', '#' + params.modalId + ' [data-woodev-pickup-close]', closeModal )
		$( document ).on( 'click', '#' + params.modalId, function( event ) {
			if( event.target === this ) {
				closeModal()
			}
		} )
		$( document ).on( 'keyup', function( event ) {
			if( 27 === event.keyCode ) {
				closeModal()
			}
		} )
	} )

} )( jQuery )
