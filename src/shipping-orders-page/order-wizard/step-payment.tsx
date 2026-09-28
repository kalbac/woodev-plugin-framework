/**
 * Step ⑤ «Оплата» (#710 D1, O7 / O8 / O9, increment I5b of card #971): what the manager is about
 * to create — a read-only summary of steps ①–④ with a link back to each — the payment method, the
 * order status, the totals, and the button that sends the order.
 *
 * **No money moves (O7).** The payment method and the status are labels WooCommerce acts on: the
 * status transition is what sends its emails and takes stock (`Order_Editor::apply_status()`), so
 * the step says so next to the select instead of offering a separate «send the email» switch.
 *
 * **The point is re-checked here (D3).** A pickup point is picked in ④, before the payment method
 * exists; a carrier may refuse cash-on-delivery at some points. Once a method is chosen the step
 * asks the admin point-detail route with it, and a point that no longer suits stops the order with
 * a way back to ④. An unknown answer never blocks — the checker is permissive by omission.
 *
 * **The button (StepProps.submit).** It reads «Создать заказ» / «Сохранить». A 422 comes back
 * routed by the shell to the step and field that own the problem; the ones that belong here
 * (`payment_method`, `status`, anything unmapped) are shown on this step.
 *
 * Edit-mode extras — the «было X, стало Y» warning for a paid order (O14) — are I6's; the
 * immediate-export checkbox (O9, D6) waits for #872.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';
import type { ReactNode } from 'react';
import { getWizardContext } from '../rest';
import { checkPickupPoint } from './api';
import type { PointVerdict } from './api';
import { decodeEntities, pickedPointId } from './delivery-state';
import { FieldErrorList, SelectField, errorsFor } from './fields';
import { addressLine, orderTotals, paymentOptions, personName, shownStatus, statusOptions } from './payment-state';
import type { StepProps } from './step-props';
import type { WizardStepId } from './types';
import { errorsOfStep } from './validation';
import { formatMoney } from './wizard-data';

/** Field paths this step draws under a control of its own; any other error of the step goes to the general list. */
const OWN_FIELDS = [ 'payment_method', 'status' ];

interface SummaryRowProps {
	title: string;
	step: WizardStepId;
	goToStep: ( step: WizardStepId ) => void;
	disabled: boolean;
	children: ReactNode;
}

function SummaryRow( { title, step, goToStep, disabled, children }: SummaryRowProps ) {
	return (
		<div className="woodev-order-wizard__summary-row">
			<dt>{ title }</dt>
			<dd>{ children }</dd>
			<Button variant="link" disabled={ disabled } onClick={ () => goToStep( step ) }>
				{ __( 'Изменить', 'woodev-plugin-framework' ) }
			</Button>
		</div>
	);
}

export default function StepPayment( { data, setData, errors, mode, submit, busy, goToStep }: StepProps ) {
	const { wizard } = getWizardContext();
	const symbol = wizard.currency?.symbol || '';
	const countries = wizard.countries || {};
	const states = wizard.states || {};
	const { rest } = data;
	const totals = orderTotals( data );
	const editing = 'edit' === mode;

	const pointId = pickedPointId( rest );
	const check = rest.pickup_check;
	const pointsRoot = check ? ( wizard.pickup?.[ check.provider ]?.restRoot as string | undefined ) : undefined;
	const [ verdict, setVerdict ] = useState<PointVerdict | null>( null );

	// D3: the point was picked before the payment method existed — ask again with it.
	useEffect( () => {
		setVerdict( null );

		if ( ! rest.rate_is_pickup || '' === pointId || '' === rest.payment_method || ! check || ! pointsRoot ) {
			return undefined;
		}

		let cancelled = false;

		checkPickupPoint( pointsRoot, pointId, check.weight, rest.payment_method ).then( ( answer ) => {
			if ( ! cancelled ) {
				setVerdict( answer );
			}
		} );

		return () => {
			cancelled = true;
		};
		// The route and weight are what the check is made of; the object identity is not.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ rest.rate_is_pickup, pointId, rest.payment_method, check?.provider, check?.weight, pointsRoot ] );

	const pointRefused = null !== verdict && ! verdict.allowed;
	const line = rest.shipping_line;
	const strayErrors = Object.entries( errorsOfStep( errors, 'payment' ) )
		.filter( ( [ field ] ) => ! OWN_FIELDS.includes( field ) )
		.flatMap( ( [ , messages ] ) => messages );

	const noneLabel = __( 'Не указан', 'woodev-plugin-framework' );
	const paymentTitle = ( paymentOptions( wizard.paymentMethods, rest.payment_method, noneLabel ).find( ( o ) => o.value === rest.payment_method ) || { label: noneLabel } ).label;
	const statusValue = shownStatus( data, mode );

	return (
		<div className="woodev-order-wizard__step">
			<h3 className="woodev-order-wizard__step-title">{ __( 'Оплата и итоги', 'woodev-plugin-framework' ) }</h3>

			<dl className="woodev-order-wizard__summary">
				<SummaryRow title={ __( 'Покупатель', 'woodev-plugin-framework' ) } step="customer" goToStep={ goToStep } disabled={ busy }>
					{ [ personName( data.billing ), data.billing.email, data.billing.phone ].filter( Boolean ).join( ' · ' ) ||
						__( 'Имя не указано', 'woodev-plugin-framework' ) }
					<span className="woodev-order-wizard__muted">
						{ data.customer.id > 0
							? sprintf(
									/* translators: %s: the registered customer's name / email. */
									__( 'Зарегистрированный покупатель: %s', 'woodev-plugin-framework' ),
									data.customer.label
							  )
							: data.customer.create_account
								? __( 'Гость; при создании заказа заведём аккаунт', 'woodev-plugin-framework' )
								: __( 'Гость', 'woodev-plugin-framework' ) }
					</span>
				</SummaryRow>

				<SummaryRow title={ __( 'Адрес', 'woodev-plugin-framework' ) } step="address" goToStep={ goToStep } disabled={ busy }>
					{ addressLine( data.shipping, countries, states ) || __( 'Не указан', 'woodev-plugin-framework' ) }
				</SummaryRow>

				<SummaryRow title={ __( 'Товары', 'woodev-plugin-framework' ) } step="items" goToStep={ goToStep } disabled={ busy }>
					<ul className="woodev-order-wizard__summary-list">
						{ data.items.map( ( item ) => (
							<li key={ item.key }>
								{ sprintf(
									/* translators: 1: product name, 2: quantity, 3: unit price. */
									__( '%1$s — %2$s шт. × %3$s', 'woodev-plugin-framework' ),
									item.name,
									item.quantity,
									formatMoney( Number( item.price ) || 0, symbol )
								) }
							</li>
						) ) }
					</ul>
				</SummaryRow>

				<SummaryRow title={ __( 'Доставка', 'woodev-plugin-framework' ) } step="delivery" goToStep={ goToStep } disabled={ busy }>
					{ line ? (
						<>
							{ `${ String( line.label ?? '' ) } — ${ formatMoney( totals.delivery, symbol ) }` }
							{ rest.rate_is_pickup && '' !== pointId && (
								<span className="woodev-order-wizard__muted">
									{ [ 'string' === typeof rest.pickup_point?.name ? decodeEntities( rest.pickup_point.name ) : '', 'string' === typeof rest.pickup_point?.address ? decodeEntities( rest.pickup_point.address ) : '' ]
										.filter( Boolean )
										.join( ' — ' ) || pointId }
								</span>
							) }
						</>
					) : (
						__( 'Не выбрана', 'woodev-plugin-framework' )
					) }
				</SummaryRow>
			</dl>

			<div className="woodev-order-wizard__grid">
				<SelectField
					label={ __( 'Способ оплаты', 'woodev-plugin-framework' ) }
					value={ rest.payment_method }
					options={ paymentOptions( wizard.paymentMethods, rest.payment_method, noneLabel ) }
					errors={ errorsFor( errors, 'payment_method' ) }
					disabled={ busy }
					onChange={ ( payment_method ) => setData( ( d ) => ( { ...d, rest: { ...d.rest, payment_method } } ) ) }
				/>
				<SelectField
					label={ __( 'Статус заказа', 'woodev-plugin-framework' ) }
					value={ statusValue }
					options={ statusOptions( wizard.orderStatuses, wizard.finalStatuses, mode, statusValue ) }
					errors={ errorsFor( errors, 'status' ) }
					disabled={ busy }
					onChange={ ( status ) => setData( ( d ) => ( { ...d, rest: { ...d.rest, status } } ) ) }
				/>
			</div>
			<p className="woodev-order-wizard__hint">
				{ __( 'Деньги не списываются. От статуса зависит, какие письма получит покупатель и спишутся ли остатки — это делает сам WooCommerce.', 'woodev-plugin-framework' ) }
			</p>

			{ pointRefused && (
				<Notice status="error" isDismissible={ false }>
					{ sprintf(
						/* translators: 1: the payment method's name, 2: the carrier's own reason (may be empty). */
						__( 'Выбранный пункт выдачи не подходит для способа оплаты «%1$s». %2$s', 'woodev-plugin-framework' ),
						paymentTitle,
						decodeEntities( verdict?.reason || '' )
					).trim() }{ ' ' }
					<Button variant="link" disabled={ busy } onClick={ () => goToStep( 'delivery' ) }>
						{ __( 'Выбрать другой пункт', 'woodev-plugin-framework' ) }
					</Button>
				</Notice>
			) }

			<FieldErrorList messages={ strayErrors } />

			<dl className="woodev-order-wizard__totals">
				<div>
					<dt>{ __( 'Товары', 'woodev-plugin-framework' ) }</dt>
					<dd>{ formatMoney( totals.items, symbol ) }</dd>
				</div>
				<div>
					<dt>{ __( 'Доставка', 'woodev-plugin-framework' ) }</dt>
					<dd>{ formatMoney( totals.delivery, symbol ) }</dd>
				</div>
				<div className="woodev-order-wizard__totals-sum">
					<dt>{ __( 'Итого', 'woodev-plugin-framework' ) }</dt>
					<dd>{ formatMoney( totals.total, symbol ) }</dd>
				</div>
			</dl>
			{ wizard.taxesEnabled && (
				<p className="woodev-order-wizard__hint">
					{ __( 'Налоги WooCommerce добавит сам при сохранении заказа — итог в заказе может быть больше.', 'woodev-plugin-framework' ) }
				</p>
			) }

			<div className="woodev-order-wizard__actions">
				<Button variant="primary" isBusy={ busy } disabled={ busy || pointRefused } onClick={ () => void submit() }>
					{ editing ? __( 'Сохранить', 'woodev-plugin-framework' ) : __( 'Создать заказ', 'woodev-plugin-framework' ) }
				</Button>
			</div>
		</div>
	);
}
