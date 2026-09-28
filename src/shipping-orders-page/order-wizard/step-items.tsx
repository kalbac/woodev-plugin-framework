/**
 * Step ③ «Товары» (#710 D1, O8): search a product or variation, set the quantity, override the
 * price, remove a line; the subtotal follows.
 *
 * Search goes through WooCommerce's own product REST (`api.ts`); a variable product is chosen
 * variation by variation, because the parent is not orderable. Picking a product already in
 * the list adds one to its quantity instead of a duplicate line. The price is the unit price
 * before tax, prefilled from the product and free to change — the server takes exactly what is
 * sent (`Order_Payload_Validator::check_items()`).
 *
 * No coupons in v1 (O8 default).
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { searchProducts } from './api';
import type { PickedProduct } from './api';
import { FieldErrorList, TextField, errorsFor } from './fields';
import RemoteSearch from './remote-search';
import type { StepProps } from './step-props';
import { formatMoney, itemsSubtotal, newItem } from './wizard-data';
import { getWizardContext } from '../rest';

export default function StepItems( { data, setData, errors }: StepProps ) {
	const symbol = getWizardContext().wizard.currency?.symbol || '';
	const { items } = data;

	const addProduct = ( picked: PickedProduct ) =>
		setData( ( d ) => {
			const same = d.items.findIndex(
				( line ) => line.product_id === picked.product_id && line.variation_id === picked.variation_id
			);

			if ( same >= 0 ) {
				return {
					...d,
					items: d.items.map( ( line, i ) =>
						i === same ? { ...line, quantity: String( ( Number( line.quantity ) || 0 ) + 1 ) } : line
					),
				};
			}

			return { ...d, items: [ ...d.items, newItem( picked ) ] };
		} );

	const patchLine = ( key: string, patch: { quantity?: string; price?: string } ) =>
		setData( ( d ) => ( { ...d, items: d.items.map( ( line ) => ( line.key === key ? { ...line, ...patch } : line ) ) } ) );

	const removeLine = ( key: string ) =>
		setData( ( d ) => ( { ...d, items: d.items.filter( ( line ) => line.key !== key ) } ) );

	return (
		<div className="woodev-order-wizard__step">
			<h3 className="woodev-order-wizard__step-title">{ __( 'Что в заказе', 'woodev-plugin-framework' ) }</h3>

			<RemoteSearch
				label={ __( 'Добавить товар', 'woodev-plugin-framework' ) }
				placeholder={ __( 'Название или артикул', 'woodev-plugin-framework' ) }
				search={ searchProducts }
				onPick={ ( picked ) => addProduct( picked as PickedProduct ) }
			/>
			<FieldErrorList messages={ errorsFor( errors, 'items' ) } />

			{ items.length > 0 && (
				<ul className="woodev-order-wizard__lines">
					{ items.map( ( line, index ) => {
						const total = ( Number( line.quantity ) || 0 ) * ( Number( line.price ) || 0 );

						return (
							<li key={ line.key } className="woodev-order-wizard__line">
								<div className="woodev-order-wizard__line-name">
									<span>{ line.name }</span>
									<FieldErrorList messages={ errorsFor( errors, `items.${ index }.product_id` ) } />
								</div>
								<TextField
									label={ __( 'Кол-во', 'woodev-plugin-framework' ) }
									type="number"
									min="1"
									step="1"
									className="woodev-order-wizard__line-qty"
									value={ line.quantity }
									errors={ errorsFor( errors, `items.${ index }.quantity` ) }
									onChange={ ( quantity ) => patchLine( line.key, { quantity } ) }
								/>
								<TextField
									label={ __( 'Цена за шт.', 'woodev-plugin-framework' ) }
									type="number"
									min="0"
									step="0.01"
									className="woodev-order-wizard__line-price"
									value={ line.price }
									errors={ errorsFor( errors, `items.${ index }.price` ) }
									onChange={ ( price ) => patchLine( line.key, { price } ) }
								/>
								<div className="woodev-order-wizard__line-total">{ formatMoney( total, symbol ) }</div>
								<Button
									variant="tertiary"
									isDestructive
									className="woodev-order-wizard__line-remove"
									label={ `${ __( 'Убрать', 'woodev-plugin-framework' ) }: ${ line.name }` }
									onClick={ () => removeLine( line.key ) }
								>
									{ __( 'Убрать', 'woodev-plugin-framework' ) }
								</Button>
							</li>
						);
					} ) }
				</ul>
			) }

			<p className="woodev-order-wizard__subtotal">
				{ __( 'Товары:', 'woodev-plugin-framework' ) } <strong>{ formatMoney( itemsSubtotal( items ), symbol ) }</strong>
			</p>
		</div>
	);
}
