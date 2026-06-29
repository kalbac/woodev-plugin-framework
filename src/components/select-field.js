/**
 * Woodev UI-kit — WooCommerce-style select with search.
 *
 * Closed: a trigger button showing the selected label(s) + chevron. Open: a
 * popover with a search box and the (filtered) option list — single-select picks
 * and closes; multi-select toggles options live. Built on wp `Dropdown`
 * (Popover) + `SearchControl`, so it is WP-only (no WC dependency) and the list
 * floats above the page instead of pushing it.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { useState, useMemo, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Dropdown, SearchControl } from '@wordpress/components';
import { ChevronIcon, CheckFilledIcon } from './icons';

/**
 * @param {Object}    props             component props.
 * @param {*}         props.value       current value (string for single, array for multi).
 * @param {Array}     props.options     [{ value, label }] options.
 * @param {boolean}   [props.multi]     allow multiple selection.
 * @param {Function}  props.onChange    change handler (string for single, array for multi).
 * @param {string}    [props.placeholder] trigger placeholder when nothing is selected.
 * @return {JSX.Element} the select.
 */
export default function SelectField( { value, options = [], multi = false, onChange, placeholder } ) {
	const [ search, setSearch ] = useState( '' );
	const triggerRef = useRef( null );
	const ph = placeholder || __( 'Выберите…', 'woodev-plugin-framework' );

	const selected = multi
		? ( Array.isArray( value ) ? value.map( String ) : [] )
		: ( null === value || undefined === value ? '' : String( value ) );

	const labelByValue = {};
	options.forEach( ( o ) => {
		labelByValue[ String( o.value ) ] = o.label;
	} );

	const isPlaceholder = multi ? 0 === selected.length : '' === selected;
	const triggerLabel = multi
		? ( selected.length ? selected.map( ( v ) => labelByValue[ v ] ?? v ).join( ', ' ) : ph )
		: ( '' !== selected ? ( labelByValue[ selected ] ?? selected ) : ph );

	const filtered = useMemo( () => {
		const q = search.trim().toLowerCase();
		return q ? options.filter( ( o ) => String( o.label ).toLowerCase().includes( q ) ) : options;
	}, [ search, options ] );

	const isSelected = ( v ) => ( multi ? selected.includes( String( v ) ) : selected === String( v ) );

	const choose = ( v, close ) => {
		const sv = String( v );
		if ( multi ) {
			onChange( selected.includes( sv ) ? selected.filter( ( x ) => x !== sv ) : [ ...selected, sv ] );
		} else {
			onChange( sv );
			close();
		}
	};

	return (
		<Dropdown
			className="woodev-select"
			contentClassName="woodev-select__popover"
			popoverProps={ { placement: 'bottom-start', offset: 4 } }
			onToggle={ ( open ) => {
				if ( ! open ) {
					setSearch( '' );
				}
			} }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<button
					type="button"
					ref={ triggerRef }
					className={ 'woodev-select__trigger' + ( isOpen ? ' is-open' : '' ) }
					onClick={ onToggle }
					aria-expanded={ isOpen }
					aria-haspopup="listbox"
				>
					<span className={ 'woodev-select__value' + ( isPlaceholder ? ' is-placeholder' : '' ) }>
						{ triggerLabel }
					</span>
					<span className="woodev-select__chevron"><ChevronIcon /></span>
				</button>
			) }
			renderContent={ ( { onClose } ) => (
				<div
					className="woodev-select__menu"
					style={ { minWidth: triggerRef.current ? triggerRef.current.offsetWidth + 'px' : undefined } }
				>
					<SearchControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						value={ search }
						onChange={ setSearch }
						placeholder={ __( 'Поиск…', 'woodev-plugin-framework' ) }
					/>
					<div className="woodev-select__list" role="listbox">
						{ 0 === filtered.length && (
							<div className="woodev-select__empty">
								{ __( 'Ничего не найдено', 'woodev-plugin-framework' ) }
							</div>
						) }
						{ filtered.map( ( o ) => (
							<button
								key={ String( o.value ) }
								type="button"
								role="option"
								aria-selected={ isSelected( o.value ) }
								className={ 'woodev-select__option' + ( isSelected( o.value ) ? ' is-selected' : '' ) }
								onClick={ () => choose( o.value, onClose ) }
							>
								<span className="woodev-select__check">
									{ isSelected( o.value ) && <CheckFilledIcon /> }
								</span>
								<span className="woodev-select__option-label">{ o.label }</span>
							</button>
						) ) }
					</div>
				</div>
			) }
		/>
	);
}
