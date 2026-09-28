/**
 * Step ① «Покупатель» (#710 D1, O11).
 *
 * Search an existing customer — their name, email, phone and last address fill the wizard —
 * or type a new person: name, phone, email, and a «создать аккаунт» box. A new person is a
 * guest by default (O11); the box makes WooCommerce create the user (and send its own email)
 * when the order is saved.
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { searchCustomers } from './api';
import { CheckboxField, FieldErrorList, TextField, errorsFor } from './fields';
import RemoteSearch from './remote-search';
import type { StepProps } from './step-props';
import { applyCustomer, clearCustomer } from './wizard-data';
import type { WcCustomerRecord } from './wizard-data';

export default function StepCustomer( { data, setData, errors }: StepProps ) {
	const { customer, billing } = data;
	const setBilling = ( patch: Partial<typeof billing> ) => setData( ( d ) => ( { ...d, billing: { ...d.billing, ...patch } } ) );

	return (
		<div className="woodev-order-wizard__step">
			<h3 className="woodev-order-wizard__step-title">{ __( 'Кто покупатель', 'woodev-plugin-framework' ) }</h3>

			{ customer.id > 0 ? (
				<div className="woodev-order-wizard__picked">
					<span>
						{ __( 'Покупатель из магазина:', 'woodev-plugin-framework' ) } <strong>{ customer.label || `#${ customer.id }` }</strong>
					</span>
					<Button variant="link" onClick={ () => setData( clearCustomer ) }>
						{ __( 'Выбрать другого или указать нового', 'woodev-plugin-framework' ) }
					</Button>
				</div>
			) : (
				<RemoteSearch
					label={ __( 'Найти покупателя', 'woodev-plugin-framework' ) }
					placeholder={ __( 'Имя, email или телефон', 'woodev-plugin-framework' ) }
					search={ searchCustomers }
					onPick={ ( record ) => setData( ( d ) => applyCustomer( d, record as WcCustomerRecord ) ) }
				/>
			) }
			<FieldErrorList messages={ errorsFor( errors, 'customer.id' ) } />

			<div className="woodev-order-wizard__grid">
				<TextField
					label={ __( 'Имя', 'woodev-plugin-framework' ) }
					value={ billing.first_name }
					errors={ errorsFor( errors, 'billing.first_name' ) }
					autoComplete="off"
					onChange={ ( first_name ) => setBilling( { first_name } ) }
				/>
				<TextField
					label={ __( 'Фамилия', 'woodev-plugin-framework' ) }
					value={ billing.last_name }
					errors={ errorsFor( errors, 'billing.last_name' ) }
					autoComplete="off"
					onChange={ ( last_name ) => setBilling( { last_name } ) }
				/>
				<TextField
					label={ __( 'Телефон', 'woodev-plugin-framework' ) }
					type="tel"
					value={ billing.phone }
					errors={ errorsFor( errors, 'billing.phone' ) }
					autoComplete="off"
					onChange={ ( phone ) => setBilling( { phone } ) }
				/>
				<TextField
					label={ __( 'Электронная почта', 'woodev-plugin-framework' ) }
					type="email"
					value={ billing.email }
					errors={ errorsFor( errors, 'billing.email' ) }
					autoComplete="off"
					onChange={ ( email ) => setBilling( { email } ) }
				/>
			</div>

			{ 0 === customer.id && (
				<CheckboxField
					label={ __( 'Создать аккаунт покупателя', 'woodev-plugin-framework' ) }
					help={ __( 'Без галочки заказ оформляется как гостевой. Покупатель получит письмо со ссылкой для входа.', 'woodev-plugin-framework' ) }
					checked={ customer.create_account }
					errors={ [] }
					onChange={ ( create_account ) =>
						setData( ( d ) => ( { ...d, customer: { ...d.customer, create_account } } ) )
					}
				/>
			) }
		</div>
	);
}
