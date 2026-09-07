/**
 * Shipping orders page root — tabs (aggregate + one per carrier, only when
 * there is more than one), a search box, the framework columns table, and
 * pagination. Rebuilt on the UI-kit (`woodev-tabs`), same shape as the
 * settings page.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, SearchControl, Spinner, TabPanel } from '@wordpress/components';
import { fetchOrders, getProviders } from './rest';
import { formatOrderDate, getStatusTone, hasTrackingNumber } from './columns';

/** @type {number} rows per page — increment 1's REST default. */
const PER_PAGE = 20;

/** @type {number} debounce for the search box, ms. */
const SEARCH_DEBOUNCE_MS = 400;

/** @type {Object<string,string>} `type` => short Russian label. `unknown` omits the prefix. */
const TYPE_LABELS = {
	courier: __( 'Курьер', 'woodev-plugin-framework' ),
	pickup: __( 'Пункт выдачи', 'woodev-plugin-framework' ),
	postal: __( 'Почта', 'woodev-plugin-framework' ),
};

/**
 * Renders the «Статус» cell: canonical label with a tone dot, raw carrier
 * label as the muted secondary line — `unknown` renders visibly as
 * "Неизвестно", never dressed up as a real state (it already carries its own
 * canonical_label from the server, so no special-casing is needed here).
 *
 * @param {Object} deliveryStatus row.delivery_status.
 * @return {JSX.Element} the cell content.
 */
function StatusCell( { deliveryStatus } ) {
	const tone = getStatusTone( deliveryStatus.canonical );

	return (
		<>
			<span className={ `woodev-orders-status woodev-orders-status--${ tone }` }>
				<span className="woodev-orders-status__dot" aria-hidden="true" />
				{ deliveryStatus.canonical_label }
			</span>
			{ deliveryStatus.raw_label && (
				<span className="woodev-orders-cell__meta">{ deliveryStatus.raw_label }</span>
			) }
		</>
	);
}

/**
 * Renders the «Доставка» cell: an optional short type prefix, the resolved
 * destination (M1: pickup point first, else the formatted address), and the
 * method+total as the muted secondary line.
 *
 * @param {Object} row one REST row.
 * @return {JSX.Element} the cell content.
 */
function ShippingCell( { row } ) {
	const typeLabel = TYPE_LABELS[ row.type ];
	const destination = typeLabel
		? `${ typeLabel } · ${ row.shipping.destination_text }`
		: row.shipping.destination_text;

	return (
		<>
			<span>{ destination }</span>
			<span className="woodev-orders-cell__meta">
				{ row.shipping.method_title }: { row.shipping.formatted_total }
			</span>
		</>
	);
}

/**
 * Renders the «Покупатель» cell: name (linked when there is a user account),
 * email, and phone when present.
 *
 * @param {Object} customer row.customer.
 * @return {JSX.Element} the cell content.
 */
function CustomerCell( { customer } ) {
	return (
		<>
			<span>
				{ customer.user_edit_url ? (
					<a href={ customer.user_edit_url }>{ customer.name }</a>
				) : (
					customer.name
				) }
			</span>
			<span className="woodev-orders-cell__meta">{ customer.email }</span>
			{ customer.phone && (
				<span className="woodev-orders-cell__meta">{ customer.phone }</span>
			) }
		</>
	);
}

/**
 * Renders the «Оплата» cell: method + formatted total, with a soft warning
 * badge when the order still needs payment.
 *
 * @param {Object} payment row.payment.
 * @return {JSX.Element} the cell content.
 */
function PaymentCell( { payment } ) {
	return (
		<>
			<span>{ payment.method_title }</span>
			<span className="woodev-orders-cell__meta">{ payment.formatted_total }</span>
			{ payment.needs_payment && (
				<span className="woodev-orders-badge woodev-orders-badge--warn">
					{ __( 'Ожидает оплаты', 'woodev-plugin-framework' ) }
				</span>
			) }
		</>
	);
}

/**
 * Renders the «Трек» cell: a link when the row carries a tracking number,
 * an em dash otherwise — the dash is this component's own display choice,
 * never something the row payload carries.
 *
 * @param {Object} tracking row.tracking.
 * @return {JSX.Element} the cell content.
 */
function TrackingCell( { tracking } ) {
	if ( ! hasTrackingNumber( tracking ) ) {
		return <span className="woodev-orders-cell__meta">—</span>;
	}

	return tracking.url ? (
		<a href={ tracking.url } target="_blank" rel="noopener noreferrer">
			{ tracking.number }
		</a>
	) : (
		<span>{ tracking.number }</span>
	);
}

/**
 * One table row.
 *
 * @param {Object} row REST row.
 * @return {JSX.Element} the rendered `<tr>`.
 */
function OrderRow( { row } ) {
	const date = formatOrderDate( row.date_created );

	return (
		<tr>
			<td className="woodev-orders-table__cb" />
			<td>
				<a href={ row.edit_url }>
					{ sprintf( __( 'Заказ %s', 'woodev-plugin-framework' ), row.order_number ) }
				</a>
			</td>
			<td title={ date.title }>{ date.text }</td>
			<td>
				<StatusCell deliveryStatus={ row.delivery_status } />
			</td>
			<td>
				<CustomerCell customer={ row.customer } />
			</td>
			<td>
				<ShippingCell row={ row } />
			</td>
			<td>
				<PaymentCell payment={ row.payment } />
			</td>
			<td>
				<TrackingCell tracking={ row.tracking } />
			</td>
		</tr>
	);
}

/**
 * Pagination — plain numbered links, matching the layout sketch («‹ 1 2 3 ›»).
 *
 * @param {Object}   props               component props.
 * @param {number}   props.page          current 1-based page.
 * @param {number}   props.totalPages    total page count.
 * @param {Function} props.onPageChange  (page) => void.
 * @return {?JSX.Element} the pagination bar, or null when there is one page or fewer.
 */
function Pagination( { page, totalPages, onPageChange } ) {
	if ( totalPages <= 1 ) {
		return null;
	}

	const pages = [];
	for ( let i = 1; i <= totalPages; i++ ) {
		pages.push( i );
	}

	return (
		<nav className="woodev-orders-pagination">
			<button
				type="button"
				className="woodev-orders-pagination__nav"
				disabled={ page <= 1 }
				onClick={ () => onPageChange( page - 1 ) }
			>
				‹
			</button>
			{ pages.map( ( p ) => (
				<button
					key={ p }
					type="button"
					className={
						'woodev-orders-pagination__page' +
						( p === page ? ' is-active' : '' )
					}
					onClick={ () => onPageChange( p ) }
				>
					{ p }
				</button>
			) ) }
			<button
				type="button"
				className="woodev-orders-pagination__nav"
				disabled={ page >= totalPages }
				onClick={ () => onPageChange( page + 1 ) }
			>
				›
			</button>
		</nav>
	);
}

export default function App() {
	const providers = getProviders();

	const [ carrier, setCarrier ] = useState( 'all' );
	const [ page, setPage ] = useState( 1 );
	const [ orderby, setOrderby ] = useState( 'date' );
	const [ order, setOrder ] = useState( 'DESC' );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ rows, setRows ] = useState( null );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 0 );
	const [ error, setError ] = useState( '' );

	const searchDebounce = useRef( null );

	// Debounce the search box into `search`, which is what actually drives the fetch.
	useEffect( () => {
		if ( searchDebounce.current ) {
			clearTimeout( searchDebounce.current );
		}
		searchDebounce.current = setTimeout( () => {
			setPage( 1 );
			setSearch( searchInput );
		}, SEARCH_DEBOUNCE_MS );

		return () => clearTimeout( searchDebounce.current );
	}, [ searchInput ] );

	useEffect( () => {
		let cancelled = false;

		setError( '' );
		setRows( null );

		fetchOrders( { carrier, page, perPage: PER_PAGE, orderby, order, search } )
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				setRows( ( res && res.rows ) || [] );
				setTotal( ( res && res.total ) || 0 );
				setTotalPages( ( res && res.total_pages ) || 0 );
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}
				setError(
					( err && err.message ) ||
						__( 'Не удалось загрузить заказы.', 'woodev-plugin-framework' )
				);
			} );

		return () => {
			cancelled = true;
		};
	}, [ carrier, page, orderby, order, search ] );

	const onSort = ( column ) => {
		if ( orderby === column ) {
			setOrder( 'ASC' === order ? 'DESC' : 'ASC' );
		} else {
			setOrderby( column );
			setOrder( 'DESC' );
		}
		setPage( 1 );
	};

	const sortIndicator = ( column ) =>
		orderby === column ? ( 'ASC' === order ? ' ▲' : ' ▼' ) : '';

	const renderBody = () => {
		if ( error ) {
			return (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			);
		}

		if ( null === rows ) {
			return (
				<div className="woodev-orders__loading">
					<Spinner />
					<span>{ __( 'Загрузка…', 'woodev-plugin-framework' ) }</span>
				</div>
			);
		}

		if ( 0 === rows.length ) {
			return (
				<Notice status="info" isDismissible={ false }>
					{ __( 'Заказов доставки пока нет.', 'woodev-plugin-framework' ) }
				</Notice>
			);
		}

		return (
			<>
				<table className="wp-list-table widefat fixed striped woodev-orders-table">
					<thead>
						<tr>
							<td className="woodev-orders-table__cb" />
							<th onClick={ () => onSort( 'ID' ) }>
								{ __( 'Заказ', 'woodev-plugin-framework' ) }
								{ sortIndicator( 'ID' ) }
							</th>
							<th onClick={ () => onSort( 'date' ) }>
								{ __( 'Дата', 'woodev-plugin-framework' ) }
								{ sortIndicator( 'date' ) }
							</th>
							<th>{ __( 'Статус', 'woodev-plugin-framework' ) }</th>
							<th>{ __( 'Покупатель', 'woodev-plugin-framework' ) }</th>
							<th>{ __( 'Доставка', 'woodev-plugin-framework' ) }</th>
							<th>{ __( 'Оплата', 'woodev-plugin-framework' ) }</th>
							<th>{ __( 'Трек', 'woodev-plugin-framework' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<OrderRow key={ row.id } row={ row } />
						) ) }
					</tbody>
				</table>
				<Pagination page={ page } totalPages={ totalPages } onPageChange={ setPage } />
			</>
		);
	};

	const renderContent = () => (
		<>
			<div className="woodev-orders__toolbar">
				<SearchControl
					value={ searchInput }
					placeholder={ __( 'Поиск по заказам…', 'woodev-plugin-framework' ) }
					onChange={ setSearchInput }
				/>
				{ null !== rows && ! error && (
					<span className="woodev-orders__count">
						{ sprintf( __( 'Заказов: %d', 'woodev-plugin-framework' ), total ) }
					</span>
				) }
			</div>
			{ renderBody() }
		</>
	);

	if ( providers.length <= 1 ) {
		return <div className="woodev-orders">{ renderContent() }</div>;
	}

	return (
		<div className="woodev-orders">
			<TabPanel
				className="woodev-tabs"
				initialTabName={ carrier }
				tabs={ providers.map( ( p ) => ( {
					name: p.id,
					title: `${ p.label } (${ p.count })`,
				} ) ) }
				onSelect={ ( name ) => {
					setCarrier( name );
					setPage( 1 );
				} }
			>
				{ () => renderContent() }
			</TabPanel>
		</div>
	);
}
