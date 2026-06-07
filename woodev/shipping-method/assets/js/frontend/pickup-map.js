/**
 * Provider-agnostic ядро карты пунктов выдачи (ПВЗ).
 *
 * Решение §6a: JS-сторона — это и есть граница провайдера. Ядро ничего не знает
 * о Leaflet или Yandex.Maps: оно лишь оркеструет поток
 * «загрузить точки (AJAX) → отдать активному адаптеру → получить выбор → записать
 * выбор обратно (AJAX)». Конкретный провайдер карты подключается через объект,
 * реализующий контракт {@link MapAdapter}.
 *
 * Названия AJAX-действий и nonce НЕ зашиты в код — они приходят из конфигурации,
 * которую формирует конкретный плагин доставки. Так ядро остаётся
 * provider-agnostic и не касается ни одной installed-site-контрактной зоны.
 *
 * @file
 */

/**
 * Контракт адаптера карты (MapAdapter).
 *
 * Любой провайдер карты (Leaflet — по умолчанию, Yandex — в одноимённом плагине)
 * обязан реализовать ровно этот интерфейс. Ядро взаимодействует с картой
 * исключительно через эти пять методов и ничего не знает о деталях провайдера.
 *
 * @interface MapAdapter
 *
 * @property {function(HTMLElement, Object): Promise<void>} init
 *           Создаёт карту внутри контейнера. Принимает DOM-элемент контейнера и
 *           объект конфигурации (центр, зум, тайлы/ключ API). Возвращает Promise,
 *           который резолвится, когда карта готова принимать точки.
 *
 * @property {function(Array<PickupPoint>): void} setPoints
 *           Отрисовывает переданный массив точек (маркеры + балун). Полностью
 *           заменяет ранее показанный набор.
 *
 * @property {function(string, function): void} on
 *           Подписка на событие адаптера. Обязательное событие — `'select'`:
 *           колбэк получает выбранный {@link PickupPoint}, когда пользователь
 *           подтверждает выбор точки на карте.
 *
 * @property {function(function(PickupPoint): boolean): void} filter
 *           Показывает только те точки, для которых предикат вернул `true`.
 *           Набор точек не перезагружается — фильтр применяется к уже загруженным.
 *
 * @property {function(): void} destroy
 *           Полностью уничтожает карту и освобождает ресурсы/слушатели.
 */

/**
 * Точка выдачи в provider-agnostic форме.
 *
 * Ядро и адаптеры обмениваются точками в этом нормализованном виде. Конкретный
 * плагин доставки отвечает за приведение ответа своего API к этой форме на сервере.
 *
 * @typedef {Object} PickupPoint
 * @property {string|number} id      Уникальный идентификатор точки у провайдера.
 * @property {number}        lat      Широта.
 * @property {number}        lng      Долгота.
 * @property {string}        name     Название/код точки.
 * @property {string}        [address] Почтовый адрес для балуна.
 * @property {string}        [description] Доп. описание (часы работы, оплата и т.п.).
 * @property {Object}        [meta]   Произвольные данные провайдера (прокидываются обратно).
 */

( function( $ ) {
	'use strict'

	/**
	 * Ядро карты ПВЗ.
	 *
	 * @class
	 */
	class WoodevPickupMap {

		/**
		 * Настройки по умолчанию.
		 *
		 * @static
		 * @type {Object}
		 */
		static defaultConfig = {
			adapter: null,
			ajaxUrl: null,
			nonce: null,
			actions: {
				fetchPoints: null,
				selectPoint: null
			},
			mapConfig: {},
			requestParams: {},
			onSelect: null,
			onError: null,
			debug: false
		}

		/**
		 * @param {HTMLElement|jQuery|string} container Контейнер для карты.
		 * @param {Object} config Конфигурация (см. {@link WoodevPickupMap.defaultConfig}).
		 */
		constructor( container, config ) {
			this.container = $( container ).get( 0 )
			this.config = $.extend( true, {}, WoodevPickupMap.defaultConfig, config )
			this.adapter = this.config.adapter
			this.points = []
			this.selectHandlers = []
			this.ready = false
		}

		/**
		 * Инициализирует адаптер, загружает точки и связывает событие выбора.
		 *
		 * @returns {Promise<WoodevPickupMap>}
		 */
		init() {
			if( ! this.adapter ) {
				return Promise.reject( new Error( 'WoodevPickupMap: adapter is not provided' ) )
			}
			if( ! this.container ) {
				return Promise.reject( new Error( 'WoodevPickupMap: container element not found' ) )
			}

			return this.adapter.init( this.container, this.config.mapConfig ).then( () => {
				this.ready = true
				this.adapter.on( 'select', ( point ) => this.handleSelect( point ) )
				return this.fetchPoints( this.config.requestParams )
			} ).then( () => this )
		}

		/**
		 * Запрашивает точки у сервера и передаёт их адаптеру.
		 *
		 * @param {Object} [params] Доп. параметры запроса (город, вес и т.п.).
		 * @returns {Promise<Array<PickupPoint>>}
		 */
		fetchPoints( params ) {
			const action = this.config.actions.fetchPoints
			if( ! action || ! this.config.ajaxUrl ) {
				return Promise.reject( new Error( 'WoodevPickupMap: fetchPoints action or ajaxUrl is not configured' ) )
			}

			const data = $.extend( {
				action: action,
				nonce: this.config.nonce,
				security: this.config.nonce
			}, params || {} )

			return Promise.resolve( $.post( this.config.ajaxUrl, data ) ).then( ( response ) => {
				if( this.isErrorResponse( response ) ) {
					// wp_send_json_error() отдаёт HTTP 200 — это ошибка, а не пустой набор точек.
					throw this.toError( response )
				}
				const points = this.normalizeResponse( response )
				this.setPoints( points )
				return points
			} ).catch( ( error ) => {
				this.handleError( error )
				throw error
			} )
		}

		/**
		 * Сохраняет точки и отрисовывает их через адаптер.
		 *
		 * @param {Array<PickupPoint>} points
		 * @returns {void}
		 */
		setPoints( points ) {
			this.points = Array.isArray( points ) ? points : []
			if( this.ready ) {
				this.adapter.setPoints( this.points )
			}
		}

		/**
		 * Показывает только точки, удовлетворяющие предикату.
		 *
		 * @param {function(PickupPoint): boolean} predicate
		 * @returns {void}
		 */
		filter( predicate ) {
			if( typeof predicate !== 'function' ) return
			this.adapter.filter( predicate )
		}

		/**
		 * Подписка на подтверждённый выбор точки.
		 *
		 * @param {function(PickupPoint): void} callback
		 * @returns {WoodevPickupMap}
		 */
		onSelect( callback ) {
			if( typeof callback === 'function' ) {
				this.selectHandlers.push( callback )
			}
			return this
		}

		/**
		 * Обрабатывает выбор точки: пишет выбор на сервер, затем уведомляет подписчиков.
		 *
		 * @param {PickupPoint} point
		 * @returns {Promise<PickupPoint>}
		 */
		handleSelect( point ) {
			return this.persistSelection( point ).then( ( response ) => {
				if( this.isErrorResponse( response ) ) {
					// Сервер отклонил выбор (wp_send_json_error, HTTP 200) — НЕ коммитим:
					// не уведомляем подписчиков/onSelect, состояние выбора не меняем.
					throw this.toError( response )
				}
				this.selectHandlers.forEach( ( handler ) => handler( point, response ) )
				if( typeof this.config.onSelect === 'function' ) {
					this.config.onSelect( point, response )
				}
				return point
			} ).catch( ( error ) => {
				this.handleError( error )
				throw error
			} )
		}

		/**
		 * Отправляет выбранную точку на сервер (selectPoint AJAX).
		 *
		 * @param {PickupPoint} point
		 * @returns {Promise<*>}
		 */
		persistSelection( point ) {
			const action = this.config.actions.selectPoint
			if( ! action || ! this.config.ajaxUrl ) {
				// Сохранение выбора не настроено — отдаём точку как есть.
				return Promise.resolve( point )
			}

			const data = $.extend( {
				action: action,
				nonce: this.config.nonce,
				security: this.config.nonce,
				point_id: point.id
			}, point.meta || {} )

			return Promise.resolve( $.post( this.config.ajaxUrl, data ) )
		}

		/**
		 * Приводит ответ сервера к массиву точек.
		 *
		 * Поддерживает как «голый» массив, так и обёртку wp_send_json_success
		 * (`{ success: true, data: [...] }` или `{ data: { points: [...] } }`).
		 *
		 * @param {*} response
		 * @returns {Array<PickupPoint>}
		 */
		normalizeResponse( response ) {
			if( Array.isArray( response ) ) return response
			if( ! response || typeof response !== 'object' ) return []
			const data = 'data' in response ? response.data : response
			if( Array.isArray( data ) ) return data
			if( data && Array.isArray( data.points ) ) return data.points
			return []
		}

		/**
		 * Является ли ответ WP-обёрткой с признаком ошибки (`wp_send_json_error`).
		 *
		 * `wp_send_json_success()` и `wp_send_json_error()` обе отдают HTTP 200, поэтому
		 * `$.post()` резолвится в обоих случаях; отличить отказ от успеха можно только по
		 * полю `success`. Ответ без этого поля (например, «голый» массив точек или иной
		 * формат, уже обрабатываемый {@link WoodevPickupMap#normalizeResponse}) ошибкой
		 * НЕ считается — сохраняется поведение по умолчанию.
		 *
		 * @param {*} response
		 * @returns {boolean}
		 */
		isErrorResponse( response ) {
			return !! response && 'object' === typeof response && 'success' in response && false === response.success
		}

		/**
		 * Строит объект ошибки из обёртки wp_send_json_error.
		 *
		 * @param {*} response
		 * @returns {Error}
		 */
		toError( response ) {
			const data = response && 'object' === typeof response ? response.data : null
			let message = 'WoodevPickupMap: the server rejected the request'

			if( 'string' === typeof data ) {
				message = data
			} else if( data && 'string' === typeof data.message ) {
				message = data.message
			}

			return new Error( message )
		}

		/**
		 * Централизованная обработка ошибок.
		 *
		 * @param {Error} error
		 * @returns {void}
		 */
		handleError( error ) {
			if( typeof this.config.onError === 'function' ) {
				this.config.onError( error )
			}
			if( this.config.debug ) {
				console.error( '[WoodevPickupMap]', error )
			}
		}

		/**
		 * Уничтожает карту и сбрасывает состояние.
		 *
		 * @returns {void}
		 */
		destroy() {
			if( this.adapter && typeof this.adapter.destroy === 'function' ) {
				this.adapter.destroy()
			}
			this.points = []
			this.selectHandlers = []
			this.ready = false
		}
	}

	window.WoodevPickupMap = WoodevPickupMap

} )( jQuery )
