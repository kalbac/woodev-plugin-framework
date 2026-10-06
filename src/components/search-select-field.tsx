/** Scalar async selection using the UI kit's select anatomy and WP popover controls. */
import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Dropdown, SearchControl, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { ChevronIcon, CheckFilledIcon } from './icons';

type Option = { value: number | string; label: string };
type Props = {
	value: number | string;
	valueLabel?: string;
	savedValue?: number | string;
	searchUrl: string;
	nonce?: string;
	disabled?: boolean;
	onChange: ( value: number | string ) => void;
};

export default function SearchSelectField( { value, valueLabel = '', savedValue, searchUrl, nonce, disabled = false, onChange }: Props ) {
	const [ term, setTerm ] = useState( '' );
	const [ open, setOpen ] = useState( false );
	const [ options, setOptions ] = useState<Option[]>( [] );
	const [ pending, setPending ] = useState( false );
	const [ failed, setFailed ] = useState( false );
	const [ picked, setPicked ] = useState<Option | null>( null );
	const trigger = useRef<HTMLButtonElement>( null );
	const query = term.trim();

	useEffect( () => {
		setOptions( [] );
		setFailed( false );
		setPending( false );
		if ( ! open || disabled || Array.from( query ).length < 2 ) {
			return;
		}
		let active = true;
		setPending( true );
		const timer = setTimeout( () => {
			const url = new URL( searchUrl, window.location.href );
			url.searchParams.set( 'term', query );
			apiFetch<{ options: Option[] }>( { url: url.toString(), headers: { 'X-WP-Nonce': nonce || '' } } )
				.then( ( response ) => { if ( active ) { setOptions( response.options || [] ); } } )
				.catch( () => { if ( active ) { setFailed( true ); } } )
				.finally( () => { if ( active ) { setPending( false ); } } );
		}, 300 );
		return () => { active = false; clearTimeout( timer ); };
	}, [ query, open, disabled, searchUrl, nonce ] );

	const label = String( value ) === String( savedValue ) ? valueLabel
		: ( picked && String( picked.value ) === String( value ) ? picked.label : '' );
	const placeholder = __( 'Выберите…', 'woodev-plugin-framework' );
	return (
		<Dropdown
			className="woodev-select"
			contentClassName="woodev-select__popover"
			popoverProps={ { placement: 'bottom-start', offset: 4 } }
			onToggle={ ( next ) => { setOpen( next ); if ( ! next ) { setTerm( '' ); } } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<button type="button" ref={ trigger } className={ 'woodev-select__trigger' + ( isOpen ? ' is-open' : '' ) }
					disabled={ disabled } aria-expanded={ isOpen } aria-haspopup="listbox" onClick={ onToggle }>
					<span className={ 'woodev-select__value' + ( ! label ? ' is-placeholder' : '' ) }>{ label || placeholder }</span>
					<span className="woodev-select__chevron"><ChevronIcon /></span>
				</button>
			) }
			renderContent={ ( { onClose } ) => (
				<div className="woodev-select__menu" style={ { minWidth: trigger.current?.offsetWidth } }>
					<SearchControl __nextHasNoMarginBottom __next40pxDefaultSize value={ term } onChange={ setTerm }
						placeholder={ __( 'Поиск…', 'woodev-plugin-framework' ) } disabled={ disabled } />
					<div className="woodev-select__list" role="listbox">
						{ Array.from( query ).length < 2 && <div className="woodev-select__empty">{ __( 'Введите не менее двух символов', 'woodev-plugin-framework' ) }</div> }
						{ pending && <div className="woodev-select__empty" role="status"><Spinner /></div> }
						{ failed && <div className="woodev-field__error" role="alert">{ __( 'Не удалось загрузить список.', 'woodev-plugin-framework' ) }</div> }
						{ ! pending && ! failed && Array.from( query ).length >= 2 && ! options.length && <div className="woodev-select__empty">{ __( 'Ничего не найдено', 'woodev-plugin-framework' ) }</div> }
						{ options.map( ( option ) => (
							<button key={ String( option.value ) } type="button" role="option" disabled={ disabled }
								aria-selected={ String( option.value ) === String( value ) } className="woodev-select__option"
								onClick={ () => { setPicked( option ); onChange( option.value ); onClose(); } }>
								<span className="woodev-select__check">{ String( option.value ) === String( value ) && <CheckFilledIcon /> }</span>
								<span className="woodev-select__option-label">{ option.label }</span>
							</button>
						) ) }
					</div>
				</div>
			) }
		/>
	);
}
