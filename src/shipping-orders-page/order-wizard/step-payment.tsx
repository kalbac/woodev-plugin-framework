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
 * a way back to ④. An unknown answer never blocks — the checker is permissive by omission — but
 * the question being asked does: the button waits (and refuses to send) until the answer is in,
 * because the server does not re-run this check on save.
 *
 * **The button (StepProps.submit).** It reads «Создать заказ» / «Сохранить». A 422 comes back
 * routed by the shell to the step and field that own the problem; the ones that belong here
 * (`payment_method`, `status`, anything unmapped) are shown on this step.
 *
 * Edit mode (#972) adds the «было X, стало Y» warning for a PAID order whose total the edit
 * changes (O14): a warning only — the button stays, and the refund or extra payment is done with
 * WooCommerce's own tools. The private order note that goes with it is written by the server on
 * save (`Order_Editor::write()`).
 *
 * **«Сразу выгрузить перевозчику» (O9, D6, #974).** Create only — an edit never exports (O4), so the
 * box is not drawn there. Ticked, the order is sent to the carrier right after it is saved; the
 * outcome, including the carrier's own refusal text, comes back with the create answer and is
 * reported by the page. The order exists either way. The export is offered only in some statuses
 * (`exportableStatuses`), so the box is disabled — and cleared — under any other; the server
 * refuses with its own reason regardless.
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
import { CheckboxField, FieldErrorList, SelectField, errorsFor } from './fields';
import type { PaidTotalChange } from './payment-state';
import { addressLine, exportOffered, orderTotals, paidTotalChange, paymentOptions, personName, shownStatus, statusOptions } from './payment-state';
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

/** The first sentence of O14's warning; with taxes on, the new total is only an estimate (WooCommerce adds the tax on save). */
function paidWarning( change: PaidTotalChange, symbol: string, approximate: boolean ): string {
	const was = formatMoney( change.was, symbol );
	const now = formatMoney( change.now, symbol );

	return approximate
		? sprintf(
				/* translators: 1: the order total now, 2: the approximate total after the edit. */
				__( 'Заказ уже оплачен, а его сумма меняется: было %1$s, станет примерно %2$s.', 'woodev-plugin-framework' ),
				was,
				now
		  )
		: sprintf(
				/* translators: 1: the order total now, 2: the total after the edit. */
				__( 'Заказ уже оплачен, а его сумма меняется: было %1$s, стало %2$s.', 'woodev-plugin-framework' ),
				was,
				now
		  );
}

export default function StepPayment( { data, setData, errors, mode, order, baselineTotal, submit, busy, goToStep }: StepProps ) {
	const { wizard } = getWizardContext();
	const symbol = wizard.currency?.symbol || '';
	const countries = wizard.countries || {};
	const states = wizard.states || {};
	const { rest } = data;
	const totals = orderTotals( data );
	const editing = 'edit' === mode;
	const paidChange = editing ? paidTotalChange( order, baselineTotal, data ) : null;

	const pointId = pickedPointId( rest );
	const check = rest.pickup_check;
	const pointsRoot = check ? ( wizard.pickup?.[ check.provider ]?.restRoot as string | undefined ) : undefined;
	// What the check is made of. `null` = there is nothing to ask (a courier tariff, no method yet, a carrier without a picker).
	const checkKey =
		rest.rate_is_pickup && '' !== pointId && '' !== rest.payment_method && check && pointsRoot
			? JSON.stringify( [ pointsRoot, pointId, check.weight, rest.payment_method, data.settlementRecord ] )
			: null;
	// The answer that came back, tagged with the question it answers — an older question's answer is no answer.
	// A `null` verdict is a check that FAILED or said nothing: it is over, and it never blocks.
	const [ answer, setAnswer ] = useState<{ key: string; verdict: PointVerdict | null } | null>( null );
	const answered = null !== checkKey && answer?.key === checkKey;
	const verdict = answered && answer ? answer.verdict : null;
	// M1: derived, not set from the effect — the render that changes the question is already "checking",
	// so the button is never live for the frame between the change and the request.
	const checking = null !== checkKey && ! answered;

	// D3: the point was picked before the payment method existed — ask again with it.
	useEffect( () => {
		if ( null === checkKey || ! check || ! pointsRoot ) {
			return undefined;
		}

		let cancelled = false;

		checkPickupPoint( pointsRoot, pointId, check.weight, rest.payment_method, data.settlementRecord ).then( ( result ) => {
			if ( ! cancelled ) {
				setAnswer( { key: checkKey, verdict: result } );
			}
		} );

		return () => {
			cancelled = true;
		};
		// `checkKey` is the route, point, weight, method and destination; the object identities are not the question.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ checkKey ] );

	const pointRefused = null !== verdict && ! verdict.allowed;
	const line = rest.shipping_line;
	const strayErrors = Object.entries( errorsOfStep( errors, 'payment' ) )
		.filter( ( [ field ] ) => ! OWN_FIELDS.includes( field ) )
		.flatMap( ( [ , messages ] ) => messages );

	const noneLabel = __( 'Не указан', 'woodev-plugin-framework' );
	const paymentTitle = ( paymentOptions( wizard.paymentMethods, rest.payment_method, noneLabel ).find( ( o ) => o.value === rest.payment_method ) || { label: noneLabel } ).label;
	const statusValue = shownStatus( data, mode );
	const exportable = wizard.exportableStatuses;
	const canExport = exportOffered( statusValue, exportable );
	const exportHelp = canExport
		? __( 'Заказ создастся в любом случае. Если перевозчик его не примет, вы увидите причину, а выгрузить заказ можно будет позже из списка.', 'woodev-plugin-framework' )
		: sprintf(
				/* translators: %s: comma-separated names of the order statuses the export works in. */
				__( 'Выгрузить можно заказ в статусах: %s.', 'woodev-plugin-framework' ),
				( exportable || [] ).map( ( slug ) => wizard.orderStatuses?.[ slug ] || slug ).join( ', ' )
		  );

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
					onChange={ ( status ) =>
						setData( ( d ) => ( {
							...d,
							// A status the export is not offered in takes the tick back — the box is disabled under it.
							rest: { ...d.rest, status, export_now: d.rest.export_now && exportOffered( status, exportable ) },
						} ) )
					}
				/>
			</div>
			<p className="woodev-order-wizard__hint">
				{ __( 'Деньги не списываются. От статуса зависит, какие письма получит покупатель и спишутся ли остатки — это делает сам WooCommerce.', 'woodev-plugin-framework' ) }
			</p>

			{ ! editing && (
				<CheckboxField
					label={ __( 'Сразу выгрузить перевозчику', 'woodev-plugin-framework' ) }
					checked={ rest.export_now && canExport }
					help={ exportHelp }
					disabled={ busy || ! canExport }
					onChange={ ( export_now ) => setData( ( d ) => ( { ...d, rest: { ...d.rest, export_now } } ) ) }
				/>
			) }

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

			{ paidChange && (
				<Notice status="warning" isDismissible={ false } className="woodev-order-wizard__paid-warning">
					{ paidWarning( paidChange, symbol, !! wizard.taxesEnabled ) }{ ' ' }
					{ __( 'Возврат или доплату сделайте средствами WooCommerce; в заказ добавится заметка об изменении.', 'woodev-plugin-framework' ) }
				</Notice>
			) }

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
				<Button
					variant="primary"
					__next40pxDefaultSize
					isBusy={ busy || checking }
					disabled={ busy || checking || pointRefused }
					onClick={ () => ( checking ? undefined : void submit() ) }
				>
					{ editing
						? __( 'Сохранить', 'woodev-plugin-framework' )
						: rest.export_now && canExport
							? __( 'Создать и выгрузить', 'woodev-plugin-framework' )
							: __( 'Создать заказ', 'woodev-plugin-framework' ) }
				</Button>
			</div>
		</div>
	);
}
