/**
 * The card footer's pager: the page count with its two arrows and the jump-to-page field — drawn only when the result has more
 * than one page — and the page-size select, always drawn, like the footer of «Заказы доставки».
 *
 * WooCommerce's own `Pagination` draws nothing for one page and offers the page size only above 25 rows, and it cannot be told
 * otherwise (its arrows and its page-size picker are `null` for `pageCount <= 1` in 7.0 and in trunk). So the shell draws the footer
 * itself under WooCommerce's own class names: the stylesheet of the vendor card, which the delivery orders page shows, styles it, and the
 * vendor pagination is hidden ({@see ./style.scss}). Every sentence is PHP's.
 *
 * @package woodev-plugin-framework
 */

import { Button, SelectControl } from '@wordpress/components';
import { MESSAGE_KEYS, fillMessage, message } from './messages';
import type { Messages } from './types';

export interface PagerProps {
	/** The page on screen, from 1. */
	page: number;
	perPage: number;
	/** Rows in the whole result. */
	total: number;
	/** PHP's page sizes. */
	sizes: number[];
	/** The page-size control's label («Товаров на странице»). */
	sizeLabel: string;
	messages: Messages;
	onPage: ( page: number ) => void;
	onPerPage: ( size: string ) => void;
}

/** WooCommerce's chevrons (`@wordpress/icons` `chevronLeft` and `chevronRight`), inlined: the package is only a transitive dependency. */
const CHEVRON_PATHS = {
	previous: 'M14.6 7l-1.2-1L8 12l5.4 6 1.2-1-4.6-5z',
	next: 'M10.6 6L9.4 7l4.6 5-4.6 5 1.2 1L16 12z',
} as const;

function Chevron( { direction }: { direction: keyof typeof CHEVRON_PATHS } ) {
	return (
		<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
			<path d={ CHEVRON_PATHS[ direction ] } />
		</svg>
	);
}

interface ArrowsProps {
	page: number;
	pages: number;
	messages: Messages;
	onPage: ( page: number ) => void;
}

/** «Страница 1 из 3» and the two arrows. */
function PageArrows( { page, pages, messages, onPage }: ArrowsProps ) {
	const status = fillMessage( message( messages, MESSAGE_KEYS.pageStatus ), { page: String( page ), pages: String( pages ) } );
	const hasPrevious = page > 1;
	const hasNext = page < pages;

	return (
		<div className="woocommerce-pagination__page-arrows">
			<span className="woocommerce-pagination__page-arrows-label" role="status" aria-live="polite">
				{ status === '' ? `${ page } / ${ pages }` : status }
			</span>
			<div className="woocommerce-pagination__page-arrows-buttons">
				<Button
					className={ `woocommerce-pagination__link${ hasPrevious ? ' is-active' : '' }` }
					icon={ <Chevron direction="previous" /> }
					label={ message( messages, MESSAGE_KEYS.pagePrev ) }
					disabled={ ! hasPrevious }
					onClick={ () => onPage( page - 1 ) }
				/>
				<Button
					className={ `woocommerce-pagination__link${ hasNext ? ' is-active' : '' }` }
					icon={ <Chevron direction="next" /> }
					label={ message( messages, MESSAGE_KEYS.pageNext ) }
					disabled={ ! hasNext }
					onClick={ () => onPage( page + 1 ) }
				/>
			</div>
		</div>
	);
}

/** The jump-to-page field: a number between 1 and the last page, taken on Enter or when the field is left. */
function PagePicker( { page, pages, messages, onPage }: ArrowsProps ) {
	const jump = ( input: HTMLInputElement ) => {
		const target = parseInt( input.value, 10 );

		if ( Number.isFinite( target ) && target !== page && target >= 1 && target <= pages ) {
			onPage( target );
			return;
		}

		input.value = String( page );
	};

	return (
		<div className="woocommerce-pagination__page-picker">
			<label className="woocommerce-pagination__page-picker-label">
				{ message( messages, MESSAGE_KEYS.pageGoto ) }
				<input
					// A new page is a new field: it shows that page, and a half-typed number does not outlive a jump.
					key={ page }
					className="woocommerce-pagination__page-picker-input"
					type="number"
					min={ 1 }
					max={ pages }
					defaultValue={ page }
					onBlur={ ( event ) => jump( event.currentTarget ) }
					onKeyDown={ ( event ) => event.key === 'Enter' && jump( event.currentTarget ) }
				/>
			</label>
		</div>
	);
}

interface PageSizeProps {
	label: string;
	perPage: number;
	sizes: number[];
	onChange: ( size: string ) => void;
}

/** PHP's own page sizes in place of WooCommerce's fixed 25/50/75/100 picker. */
function PageSize( { label, perPage, sizes, onChange }: PageSizeProps ) {
	const options = ( sizes.includes( perPage ) ? sizes : [ ...sizes, perPage ] ).map( ( size ) => ( {
		value: String( size ),
		label: String( size ),
	} ) );

	return (
		<div className="woocommerce-pagination__per-page-picker woodev-records__page-size">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				labelPosition="side"
				value={ String( perPage ) }
				options={ options }
				onChange={ onChange }
			/>
		</div>
	);
}

export function Pager( { page, perPage, total, sizes, sizeLabel, messages, onPage, onPerPage }: PagerProps ) {
	const pages = Math.max( 1, Math.ceil( total / perPage ) );
	const hasPages = pages > 1;
	const hasSizes = sizes.length > 1;

	if ( ! hasPages && ! hasSizes ) {
		return null;
	}

	return (
		<div className="woocommerce-pagination woodev-records__pager">
			{ hasPages && <PageArrows page={ page } pages={ pages } messages={ messages } onPage={ onPage } /> }
			{ hasPages && <PagePicker page={ page } pages={ pages } messages={ messages } onPage={ onPage } /> }
			{ hasSizes && <PageSize label={ sizeLabel } perPage={ perPage } sizes={ sizes } onChange={ onPerPage } /> }
		</div>
	);
}
