/**
 * Адаптер карты на Leaflet — реализация по умолчанию контракта {@link MapAdapter}.
 *
 * Реализует ровно пять методов контракта (`init`, `setPoints`, `on`, `filter`,
 * `destroy`) поверх глобального объекта Leaflet (`window.L`). Маркеры + балун
 * (popup) с кнопкой подтверждения выбора. Аналогичный адаптер для Yandex.Maps
 * поставляется в плагине Yandex и реализует тот же контракт.
 *
 * @file
 * @implements {MapAdapter}
 */

( function( $ ) {
	'use strict'

	/**
	 * Leaflet-реализация контракта MapAdapter.
	 *
	 * @class
	 * @implements {MapAdapter}
	 */
	class WoodevPickupMapLeafletAdapter {

		/**
		 * Настройки карты по умолчанию.
		 *
		 * @static
		 * @type {Object}
		 */
		static defaultConfig = {
			center: [ 55.751244, 37.618423 ],
			zoom: 10,
			tileUrl: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
			tileOptions: {
				maxZoom: 19,
				attribution: '© OpenStreetMap'
			},
			fitToPoints: true,
			selectButtonLabel: 'Выбрать'
		}

		constructor() {
			this.map = null
			this.config = {}
			this.markerLayer = null
			this.markers = []
			this.listeners = {}
		}

		/**
		 * Создаёт карту Leaflet внутри контейнера.
		 *
		 * @param {HTMLElement} containerEl
		 * @param {Object} config
		 * @returns {Promise<void>}
		 */
		init( containerEl, config ) {
			if( typeof window.L === 'undefined' ) {
				return Promise.reject( new Error( 'WoodevPickupMapLeafletAdapter: Leaflet (window.L) is not loaded' ) )
			}

			this.config = $.extend( true, {}, WoodevPickupMapLeafletAdapter.defaultConfig, config || {} )

			this.map = window.L.map( containerEl ).setView( this.config.center, this.config.zoom )
			window.L.tileLayer( this.config.tileUrl, this.config.tileOptions ).addTo( this.map )
			this.markerLayer = window.L.layerGroup().addTo( this.map )

			// Leaflet нередко инициализируется в скрытом контейнере (модальное окно).
			// invalidateSize пересчитывает размеры после показа.
			return Promise.resolve().then( () => {
				this.map.invalidateSize()
			} )
		}

		/**
		 * Отрисовывает набор точек: создаёт маркеры с балунами.
		 *
		 * @param {Array<PickupPoint>} points
		 * @returns {void}
		 */
		setPoints( points ) {
			this.clearMarkers()

			const list = Array.isArray( points ) ? points : []
			list.forEach( ( point ) => {
				if( ! this.hasCoordinates( point ) ) return

				const marker = window.L.marker( [ point.lat, point.lng ] )
				marker.bindPopup( this.buildPopup( point ) )
				marker.woodevPoint = point
				marker.on( 'popupopen', ( event ) => this.bindSelectButton( event, point ) )
				marker.addTo( this.markerLayer )
				this.markers.push( marker )
			} )

			if( this.config.fitToPoints && this.markers.length ) {
				this.fitToMarkers()
			}
		}

		/**
		 * Подписка на события адаптера. Поддерживается событие `'select'`.
		 *
		 * @param {string} event
		 * @param {function} callback
		 * @returns {void}
		 */
		on( event, callback ) {
			if( typeof callback !== 'function' ) return
			if( ! this.listeners[ event ] ) {
				this.listeners[ event ] = []
			}
			this.listeners[ event ].push( callback )
		}

		/**
		 * Скрывает маркеры, не удовлетворяющие предикату; остальные показывает.
		 *
		 * @param {function(PickupPoint): boolean} predicate
		 * @returns {void}
		 */
		filter( predicate ) {
			if( typeof predicate !== 'function' ) return

			this.markers.forEach( ( marker ) => {
				if( predicate( marker.woodevPoint ) ) {
					if( ! this.markerLayer.hasLayer( marker ) ) {
						this.markerLayer.addLayer( marker )
					}
				} else if( this.markerLayer.hasLayer( marker ) ) {
					this.markerLayer.removeLayer( marker )
				}
			} )

			const visible = this.markers.filter( ( marker ) => this.markerLayer.hasLayer( marker ) )
			if( this.config.fitToPoints && visible.length ) {
				this.fitToMarkers( visible )
			}
		}

		/**
		 * Уничтожает карту и очищает слушатели.
		 *
		 * @returns {void}
		 */
		destroy() {
			this.clearMarkers()
			if( this.map ) {
				this.map.remove()
				this.map = null
			}
			this.markerLayer = null
			this.listeners = {}
		}

		/**
		 * Вызывает подписчиков события.
		 *
		 * @param {string} event
		 * @param {*} payload
		 * @returns {void}
		 */
		emit( event, payload ) {
			( this.listeners[ event ] || [] ).forEach( ( callback ) => callback( payload ) )
		}

		/**
		 * Собирает HTML балуна для точки.
		 *
		 * @param {PickupPoint} point
		 * @returns {HTMLElement}
		 */
		buildPopup( point ) {
			const wrapper = document.createElement( 'div' )
			wrapper.className = 'woodev-pickup-popup'

			const title = document.createElement( 'div' )
			title.className = 'woodev-pickup-popup__title'
			title.textContent = point.name || String( point.id )
			wrapper.appendChild( title )

			if( point.address ) {
				const address = document.createElement( 'div' )
				address.className = 'woodev-pickup-popup__address'
				address.textContent = point.address
				wrapper.appendChild( address )
			}

			if( point.description ) {
				const description = document.createElement( 'div' )
				description.className = 'woodev-pickup-popup__description'
				description.textContent = point.description
				wrapper.appendChild( description )
			}

			const button = document.createElement( 'button' )
			button.type = 'button'
			button.className = 'woodev-pickup-popup__select button'
			button.textContent = this.config.selectButtonLabel
			wrapper.appendChild( button )

			return wrapper
		}

		/**
		 * Вешает обработчик на кнопку «Выбрать» внутри открытого балуна.
		 *
		 * @param {Object} event Событие popupopen Leaflet.
		 * @param {PickupPoint} point
		 * @returns {void}
		 */
		bindSelectButton( event, point ) {
			const node = event.popup.getElement()
			if( ! node ) return
			const button = node.querySelector( '.woodev-pickup-popup__select' )
			if( ! button ) return
			button.addEventListener( 'click', () => {
				this.emit( 'select', point )
			}, { once: true } )
		}

		/**
		 * Подгоняет видимую область под маркеры.
		 *
		 * @param {Array} [markers] Маркеры для подгонки (по умолчанию — все).
		 * @returns {void}
		 */
		fitToMarkers( markers ) {
			const list = markers || this.markers
			if( ! list.length ) return
			const bounds = window.L.latLngBounds( list.map( ( marker ) => marker.getLatLng() ) )
			this.map.fitBounds( bounds, { padding: [ 24, 24 ] } )
		}

		/**
		 * Удаляет все маркеры со слоя.
		 *
		 * @returns {void}
		 */
		clearMarkers() {
			if( this.markerLayer ) {
				this.markerLayer.clearLayers()
			}
			this.markers = []
		}

		/**
		 * Проверяет наличие валидных координат у точки.
		 *
		 * @param {PickupPoint} point
		 * @returns {boolean}
		 */
		hasCoordinates( point ) {
			return point && isFinite( point.lat ) && isFinite( point.lng )
		}
	}

	window.WoodevPickupMapLeafletAdapter = WoodevPickupMapLeafletAdapter

} )( jQuery )
