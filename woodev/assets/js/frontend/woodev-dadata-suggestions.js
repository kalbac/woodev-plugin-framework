/**
 * jQuery-плагин для интеграции DaData с полями формы WooCommerce
 * @class
 */
class WoodevDadataSuggestions {
	/**
	 * Карта типов полей и их настроек
	 * @static
	 * @type {Object}
	 */
	static fieldTypeMap = {
		billing_city: { type: 'ADDRESS', bounds: 'city-settlement' },
		billing_state: { type: 'ADDRESS', bounds: 'region' },
		billing_address_1: { type: 'ADDRESS', bounds: 'street-house' },
		billing_address_2: { type: 'ADDRESS', bounds: 'flat' },
		billing_postcode: { type: 'ADDRESS', bounds: 'postal-code' },
		shipping_city: { type: 'ADDRESS', bounds: 'city-settlement' },
		shipping_state: { type: 'ADDRESS', bounds: 'region' },
		shipping_address_1: { type: 'ADDRESS', bounds: 'street-house' },
		shipping_address_2: { type: 'ADDRESS', bounds: 'flat' },
		shipping_postcode: { type: 'ADDRESS', bounds: 'postal-code' },
		billing_first_name: { type: 'NAME', bounds: 'given-name' },
		billing_last_name: { type: 'NAME', bounds: 'surname' },
		billing_email: { type: 'EMAIL' },
		shipping_first_name: { type: 'NAME', bounds: 'given-name' },
		shipping_last_name: { type: 'NAME', bounds: 'surname' }
	}

	/**
	 * Карта зависимостей полей
	 * @static
	 * @type {Object}
	 */
	static fieldDependencyMap = {
		billing_state: { dependsOn: 'billing_country' },
		billing_city: { dependsOn: 'billing_state' },
		billing_postcode: { dependsOn: 'billing_city' },
		billing_address_1: { dependsOn: 'billing_city' },
		billing_address_2: { dependsOn: 'billing_address_1' },
		shipping_state: { dependsOn: 'shipping_country' },
		shipping_city: { dependsOn: 'shipping_state' },
		shipping_postcode: { dependsOn: 'shipping_city' },
		shipping_address_1: { dependsOn: 'shipping_city' },
		shipping_address_2: { dependsOn: 'shipping_address_1' },
		billing_first_name: null,
		billing_last_name: null,
		billing_email: null,
		shipping_first_name: null,
		shipping_last_name: null
	}

	/**
	 * Настройки по умолчанию
	 * @static
	 * @type {Object}
	 */
	static defaultOptions = {
		allowedCountries: null,
		type: null,
		bounds: null,
		constraints: null,
		defaultCountry: 'RU',
		enableGeolocation: false,
		updateCountryOnGeolocation: true,
		overrideExisting: false,
		debug: false,
		preventCheckoutUpdate: true,
		cleanCityPrefixes: true,
		excludePlanningStructures: true,
		refineCitySuggestions: true,
		onSelect: null,
		formatResult: null,
		additionalFields: null,
		additionalDataMapping: null
	}

	/**
	 * Конструктор плагина
	 * @param {jQuery} element - Элемент поля формы
	 * @param {Object} options - Пользовательские настройки
	 */
	constructor( element, options ) {
		this.element = $( element )
		this.settings = $.extend( {}, WoodevDadataSuggestions.defaultOptions, options )
		this.suggestions = this.element.suggestions()
		this.isWatching = false
		this.isSuggestionsActive = false
		this.constraintAttempts = 0
		this.maxConstraintAttempts = 100
		this.suggestionCache = new Map()
	}

	/**
	 * Инициализация плагина
	 * @returns {void}
	 */
	init() {
		if( ! this.isAddressFieldPresent() ) {
			this.logWarning( `No address fields with active suggestions for ${ this.element.attr( 'id' ) }` )
			return
		}
		if( ! this.isInputField() ) {
			this.logWarning( `Field ${ this.element.attr( 'id' ) } is not an input` )
			this.suggestions.disable()
			return
		}
		if( this.isCountryRestricted() && ! this.isCountryAllowed() ) {
			this.logWarning( `Country not allowed for ${ this.element.attr( 'id' ) }` )
			this.suggestions.disable()
			return
		}
		this.bindSuggestionsEvents()
		if( this.hasDependencies() ) {
			this.setupConstraints()
			this.watchCityField()
		}
		this.suggestions.enable()
		if( this.settings.enableGeolocation ) {
			this.detectGeolocation()
		}
	}

	/**
	 * Проверяет допустимость страны
	 * @returns {boolean}
	 */
	isCountryAllowed() {
		if( ! this.settings.allowedCountries ) return true
		const countryCode = this.getCountryCode()
		const allowed = Array.isArray( this.settings.allowedCountries )
			? this.settings.allowedCountries
			: [ this.settings.allowedCountries ]
		return allowed.includes( countryCode )
	}

	/**
	 * Возвращает тип и границы поля
	 * @returns {Object|null}
	 */
	getFieldType() {
		const fieldId = this.element.attr( 'id' ) || this.element.attr( 'name' )
		return WoodevDadataSuggestions.fieldTypeMap[ fieldId ] || null
	}

	/**
	 * Возвращает группу полей (billing или shipping)
	 * @returns {string}
	 */
	getFieldGroup() {
		const fieldId = this.element.attr( 'id' ) || this.element.attr( 'name' )
		return fieldId.startsWith( 'shipping_' ) ? 'shipping' : 'billing'
	}

	/**
	 * Находит родительскую форму или body
	 * @returns {jQuery}
	 */
	getParentForm() {
		const form = this.element.closest( 'form' )
		return form.length ? form : $( 'body' )
	}

	/**
	 * Настраивает зависимости для подсказок
	 * @returns {void}
	 */
	setupConstraints() {
		const fieldId = this.element.attr( 'id' ) || this.element.attr( 'name' )
		const dependency = WoodevDadataSuggestions.fieldDependencyMap[ fieldId ]
		const group = this.getFieldGroup()
		const countryField = $( `#${ group }_country` )

		if( ! dependency || ! this.hasActiveDependency() ) {
			if( this.isCityFieldSelect() ) {
				const cityField = $( `#${ group }_city` )
				if( cityField.length && this.isCityFieldSelect2() ) {
					const cityData = this.getCityFieldValue()
					if( cityData ) {
						this.constraintAttempts = 0
						this.updateConstraints( cityData )
						return
					}
					if( this.constraintAttempts < this.maxConstraintAttempts ) {
						this.constraintAttempts++
						setTimeout( () => this.setupConstraints(), 100 )
						return
					}
					if( this.settings.debug ) {
						this.logWarning( `Max attempts reached for ${ this.element.attr( 'id' ) }, resetting constraints` )
					}
				}
			}
			this.constraintAttempts = 0
			this.resetConstraints()
			return
		}

		this.constraintAttempts = 0
		const parentField = $( `#${ dependency.dependsOn }` )
		const parentValue = parentField.val().trim()
		this.suggestions.setOptions( {
			constraints: parentValue ? parentField : countryField
		} )
	}

	/**
	 * Отслеживает изменения формы через MutationObserver
	 * @returns {void}
	 */
	watchForm() {
		if( this.isWatching ) return
		const form = this.getParentForm()[ 0 ]
		const observer = new MutationObserver( () => this.setupConstraints() )
		observer.observe( form, { childList: true, subtree: true } )
		this.isWatching = true
	}

	/**
	 * Отслеживает изменения поля города
	 * @returns {void}
	 */
	watchCityField() {
		const group = this.getFieldGroup()
		const cityField = $( `#${ group }_city` )
		if( cityField.length && this.isCityFieldSelect() ) {
			cityField.on( 'change select2:select', () => {
				const cityData = this.getCityFieldValue()
				this.updateConstraints( cityData )
			} )
		}
	}

	/**
	 * Обновляет зависимости для подсказок
	 * @param {Object|null} cityData - Данные города
	 * @returns {void}
	 */
	updateConstraints( cityData ) {
		if( ! cityData ) {
			this.resetConstraints()
			return
		}

		const constraints = { locations: {} }
		if( cityData.hasAdditionalData ) {
			if( cityData.fias_guid ) {
				constraints.locations.fias_id = cityData.fias_guid
			} else if( cityData.latitude && cityData.longitude ) {
				constraints.locations.lat = cityData.latitude
				constraints.locations.lon = cityData.longitude
				constraints.radius = 1000
			} else if( cityData.region && cityData.district ) {
				constraints.locations.region = cityData.region
				constraints.locations.district = cityData.district
				constraints.locations.city = cityData.value
				constraints.locations.settlement = cityData.value
			} else if( cityData.region ) {
				constraints.locations.region = cityData.region
				constraints.locations.city = cityData.value
				constraints.locations.settlement = cityData.value
			} else if( cityData.value ) {
				constraints.locations.city = cityData.value
				constraints.locations.settlement = cityData.value
			}
		} else if( cityData.value ) {
			constraints.locations.city = cityData.value
			constraints.locations.settlement = cityData.value
		} else {
			this.resetConstraints()
			return
		}

		this.suggestions.setOptions( { constraints } )
	}

	/**
	 * Сбрасывает зависимости на страну
	 * @returns {void}
	 */
	resetConstraints() {
		const group = this.getFieldGroup()
		const countryField = $( `#${ group }_country` )
		this.suggestions.setOptions( {
			constraints: countryField.length
				? countryField
				: { locations: { country_iso_code: this.settings.defaultCountry } }
		} )
	}

	/**
	 * Запрашивает геолокацию
	 * @returns {void}
	 */
	detectGeolocation() {
		if( ! this.isGeolocationSupported() || ! this.isLowestInHierarchy() ) return
		if( navigator.geolocation ) {
			navigator.geolocation.getCurrentPosition(
				( position ) => this.handleGeolocation( position ),
				() => {}
			)
		}
	}

	/**
	 * Обрабатывает данные геолокации
	 * @param {GeolocationPosition} position - Данные геолокации
	 * @returns {void}
	 */
	handleGeolocation( position ) {
		const group = this.getFieldGroup()
		const countryField = $( `#${ group }_country` )
		const query = {
			lat: position.coords.latitude,
			lon: position.coords.longitude,
			count: 1
		}
		this.suggestions.getSuggestions( query ).then( ( suggestions ) => {
			if( suggestions.length ) {
				const suggestion = suggestions[ 0 ]
				if( this.settings.updateCountryOnGeolocation && countryField.length ) {
					countryField.val( suggestion.data.country_iso_code ).trigger( 'change' )
				}
				this.suggestions.setSuggestion( suggestion )
			}
		} )
	}

	/**
	 * Проверяет, является ли поле текстовым
	 * @returns {boolean}
	 */
	isInputField() {
		return this.element.is( 'input[type="text"]' )
	}

	/**
	 * Проверяет наличие зависимостей
	 * @returns {boolean}
	 */
	hasDependencies() {
		const fieldId = this.element.attr( 'id' ) || this.element.attr( 'name' )
		return !!WoodevDadataSuggestions.fieldDependencyMap[ fieldId ]
	}

	/**
	 * Проверяет активность зависимого поля
	 * @returns {boolean}
	 */
	hasActiveDependency() {
		const fieldId = this.element.attr( 'id' ) || this.element.attr( 'name' )
		const dependency = WoodevDadataSuggestions.fieldDependencyMap[ fieldId ]
		if( ! dependency ) return false
		const parentField = $( `#${ dependency.dependsOn }` )
		return parentField.length && parentField.val().trim()
	}

	/**
	 * Возвращает код страны
	 * @returns {string}
	 */
	getCountryCode() {
		const group = this.getFieldGroup()
		const countryField = $( `#${ group }_country` )
		return countryField.length ? countryField.val() : this.settings.defaultCountry
	}

	/**
	 * Проверяет наличие активных полей адреса
	 * @returns {boolean}
	 */
	isAddressFieldPresent() {
		const fieldId = this.element.attr( 'id' ) || this.element.attr( 'name' )
		const group = this.getFieldGroup()
		const fields = [
			`#${ group }_country`,
			`#${ group }_state`,
			`#${ group }_city`,
			`#${ group }_address_1`,
			`#${ group }_address_2`,
			`#${ group }_postcode`
		]
		return fields.some( ( selector ) => $( selector ).length && selector !== `#${ fieldId }` )
	}

	/**
	 * Проверяет, ограничены ли страны
	 * @returns {boolean}
	 */
	isCountryRestricted() {
		return !!this.settings.allowedCountries
	}

	/**
	 * Проверяет поддержку геолокации
	 * @returns {boolean}
	 */
	isGeolocationSupported() {
		const fieldType = this.getFieldType()
		return fieldType?.type === 'ADDRESS' && [ 'region', 'city-settlement' ].includes( fieldType.bounds )
	}

	/**
	 * Возвращает иерархию поля
	 * @returns {number}
	 */
	getFieldHierarchy() {
		const fieldId = this.element.attr( 'id' ) || this.element.attr( 'name' )
		const hierarchy = {
			'_country': 1,
			'_state': 2,
			'_city': 3,
			'_postcode': 4,
			'_address_1': 5,
			'_address_2': 6
		}
		return Object.keys( hierarchy ).find( ( key ) => fieldId.includes( key ) )
			? hierarchy[ fieldId.match( /_country|_state|_city|_postcode|_address_1|_address_2/ )[ 0 ] ]
			: 999
	}

	/**
	 * Проверяет, является ли поле самым низким в иерархии
	 * @returns {boolean}
	 */
	isLowestInHierarchy() {
		const group = this.getFieldGroup()
		const fields = [
			`#${ group }_country`,
			`#${ group }_state`,
			`#${ group }_city`,
			`#${ group }_postcode`,
			`#${ group }_address_1`,
			`#${ group }_address_2`
		]
		const hierarchy = this.getFieldHierarchy()
		return ! fields.some( ( selector ) => {
			const field = $( selector )
			return field.length && field[ 0 ] !== this.element[ 0 ] && this.getFieldHierarchy( field ) > hierarchy
		} )
	}

	/**
	 * Проверяет, является ли поле города select
	 * @returns {boolean}
	 */
	isCityFieldSelect() {
		const group = this.getFieldGroup()
		const cityField = $( `#${ group }_city` )
		return cityField.length && cityField.is( 'select' )
	}

	/**
	 * Проверяет инициализацию select2
	 * @returns {boolean}
	 */
	isCityFieldSelect2() {
		const group = this.getFieldGroup()
		const cityField = $( `#${ group }_city` )
		return cityField.length && cityField.data( 'select2' ) !== undefined
	}

	/**
	 * Проверяет наличие дополнительных данных для constraints
	 * @param {Object} select2Data - Данные select2
	 * @returns {boolean}
	 */
	hasAdditionalDataForConstraints( select2Data ) {
		return select2Data && (
			select2Data.fias_guid ||
			select2Data.fias_id ||
			select2Data.latitude ||
			select2Data.longitude ||
			select2Data.region ||
			select2Data.region_fias_id ||
			select2Data.district ||
			select2Data.kladr_id
		)
	}

	/**
	 * Извлекает данные поля города
	 * @returns {Object|null}
	 */
	getCityFieldValue() {
		const group = this.getFieldGroup()
		const cityField = $( `#${ group }_city` )
		if( ! cityField.length ) return null
		if( this.isCityFieldSelect2() ) {
			const select2Data = cityField.select2( 'data' )[ 0 ]
			if( select2Data ) {
				const cityData = {
					value: select2Data.text,
					fias_guid: select2Data.fias_guid || select2Data.fias_id,
					latitude: select2Data.latitude,
					longitude: select2Data.longitude,
					region: select2Data.region,
					district: select2Data.district,
					region_fias_id: select2Data.region_fias_id,
					kladr_id: select2Data.kladr_id
				}
				cityData.hasAdditionalData = this.hasAdditionalDataForConstraints( select2Data )
				if( ! cityData.value && ! cityData.hasAdditionalData ) {
					if( this.settings.debug ) {
						this.logWarning( `Empty text and no additional data for ${ cityField.attr( 'id' ) }` )
					}
					return null
				}
				return cityData
			}
			if( this.settings.debug ) {
				this.logWarning( `Select2 data not ready for ${ cityField.attr( 'id' ) }` )
			}
			return null
		}
		return { value: cityField.val() }
	}

	/**
	 * Привязывает события для подсказок
	 * @returns {void}
	 */
	bindSuggestionsEvents() {
		this.element.on( 'focus', () => {
			this.isSuggestionsActive = true
		} )
		this.element.on( 'blur', () => {
			this.isSuggestionsActive = false
		} )
		this.element.on( 'suggestions-select', () => {
			this.isSuggestionsActive = false
		} )
		if( this.settings.preventCheckoutUpdate ) {
			this.element.on( 'input', ( event ) => this.handleInput( event ) )
		}
		if( this.settings.excludePlanningStructures ) {
			this.element.on( 'suggestions-fetch', ( event, { suggestions } ) => {
				event.suggestions = this.filterSuggestions( suggestions )
			} )
		}
	}

	/**
	 * Перехватывает событие input для предотвращения update_checkout
	 * @param {Event} event - Событие input
	 * @returns {void}
	 */
	handleInput( event ) {
		if( this.isSuggestionsActive ) {
			event.stopPropagation()
		}
	}

	/**
	 * Удаляет префиксы из названия города
	 * @param {string} value - Название города
	 * @returns {string}
	 */
	cleanCityValue( value ) {
		if( ! this.settings.cleanCityPrefixes || ! value ) return value
		const prefixes = Array.isArray( this.settings.cleanCityPrefixes )
			? this.settings.cleanCityPrefixes
			: [ 'г', 'г.', 'пос', 'пос.', 'с', 'с.', 'д', 'д.', 'п', 'п.', 'рп', 'рп.', 'кп', 'нп', 'тер', 'мкр', 'ст' ]
		const regex = new RegExp( `^(${ prefixes.join( '|' ) })\\s*`, 'i' )
		return value.replace( regex, '' ).trim()
	}

	/**
	 * Фильтрует подсказки, исключая планировочную структуру
	 * @param {Array} suggestions - Подсказки от DaData
	 * @returns {Array}
	 */
	filterSuggestions( suggestions ) {
		if( ! this.settings.excludePlanningStructures ) return suggestions
		return suggestions.filter( ( suggestion ) => suggestion.data?.fias_level !== '65' )
	}

	/**
	 * Уточняет подсказку для поля города
	 * @param {Object} suggestion - Подсказка
	 * @returns {Promise<Object>}
	 */
	refineCitySuggestion( suggestion ) {
		if( ! this.settings.refineCitySuggestions || ! this.element.attr( 'id' ).includes( '_city' ) ) {
			return Promise.resolve( suggestion )
		}
		const cacheKey = `${ suggestion.value }|${ suggestion.data?.city_fias_id || '' }`
		if( this.suggestionCache.has( cacheKey ) ) {
			return Promise.resolve( this.suggestionCache.get( cacheKey ) )
		}
		if( ! this.suggestions.isQueryRequestable( suggestion.value ) ) {
			return Promise.resolve( suggestion )
		}
		const query = {
			query: suggestion.value,
			type: 'ADDRESS',
			bounds: 'city-settlement',
			count: 2
		}
		if( suggestion.data?.region ) {
			query.locations = { region: suggestion.data.region }
		}
		return this.suggestions.getSuggestions( query ).then( ( suggestions ) => {
			const refined = suggestions.find( ( s ) => {
				const fiasLevel = parseInt( s.data?.fias_level, 10 )
				return fiasLevel >= 4 || s.data?.city_fias_id === suggestion.data?.city_fias_id
			} ) || suggestion
			this.suggestionCache.set( cacheKey, refined )
			return refined
		} )
	}

	/**
	 * Обрабатывает выбор подсказки
	 * @param {Object} suggestion - Выбранная подсказка
	 * @returns {void}
	 */
	onSelect( suggestion ) {
		if( this.settings.additionalFields ) {
			Object.entries( this.settings.additionalFields ).forEach( ( [ fieldId, dataPath ] ) => {
				const value = dataPath.split( '.' ).reduce( ( obj, key ) => obj?.[ key ], suggestion )
				if( value ) $( fieldId ).val( value ).trigger( 'change' )
			} )
		}
		if( this.settings.additionalDataMapping && suggestion.data.fias_id ) {
			const mappedValue = this.settings.additionalDataMapping[ suggestion.data.fias_id ]
			if( mappedValue ) $( `#${ this.getFieldGroup() }_external_id` ).val( mappedValue ).trigger( 'change' )
		}
		if( this.element.attr( 'id' ).includes( '_city' ) ) {
			suggestion.value = this.cleanCityValue( suggestion.value )
		}
		this.refineCitySuggestion( suggestion ).then( ( refinedSuggestion ) => {
			if( this.settings.onSelect ) {
				this.settings.onSelect( refinedSuggestion )
			}
			this.suggestions.setSuggestion( refinedSuggestion )
			this.suggestions.fixData()
		} )
	}

	/**
	 * Форматирует выбранную подсказку
	 * @param {Object} suggestion - Подсказка
	 * @returns {string}
	 */
	formatSelected( suggestion ) {
		if( this.element.attr( 'id' ).includes( '_city' ) ) {
			return this.cleanCityValue( suggestion.value )
		}
		return suggestion.value
	}

	/**
	 * Логирует предупреждения
	 * @param {string} message - Сообщение
	 * @returns {void}
	 */
	logWarning( message ) {
		if( this.settings.debug ) {
			console.warn( `[WoodevDadataSuggestions] ${ message }` )
		}
	}
}

/**
 * jQuery-плагин для инициализации WoodevDadataSuggestions
 * @param {Object|string} options - Настройки или метод
 * @returns {jQuery}
 */
$.fn.woodevDadataSuggestions = function( options ) {
	return this.each( function() {
		const $this = $( this )
		let instance = $this.data( 'woodevDadataSuggestions' )

		if( ! instance ) {
			if( typeof options === 'object' || ! options ) {
				instance = new WoodevDadataSuggestions( this, options )
				$this.data( 'woodevDadataSuggestions', instance )
				instance.init()
			}
		} else if( typeof options === 'string' ) {
			if( instance[ options ] ) {
				instance[ options ].apply( instance, Array.prototype.slice.call( arguments, 1 ) )
			}
		}
	} )
}