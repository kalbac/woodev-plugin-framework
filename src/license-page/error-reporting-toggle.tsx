/**
 * License Page — «Отправлять отчёты об ошибках» consent checkbox (#130).
 *
 * One site-wide checkbox for every Woodev plugin on the site, OFF until the merchant ticks it.
 * It is only rendered when the site has a report receiver configured (`available`) — a checkbox
 * that would change nothing is not offered. State is replaced wholesale by each REST response.
 *
 * @package woodev-plugin-framework
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Card, CardBody, CheckboxControl, Notice } from '@wordpress/components';

/** The consent state the license page is bootstrapped with and `/woodev/v1/error-reporting` returns. */
export interface ErrorReportingState {
	/** Whether the merchant ticked the checkbox. */
	enabled: boolean;
	/** Whether a report receiver is configured on this site. */
	available: boolean;
}

export interface ErrorReportingToggleProps {
	/** `window.woodevLicenses.errorReporting`; absent on a framework copy that predates the option. */
	initialState?: ErrorReportingState;
}

/**
 * The checkbox card.
 *
 * @param {ErrorReportingToggleProps} props component props.
 * @return {JSX.Element|null} the card, or nothing when reporting is not offered on this site.
 */
export default function ErrorReportingToggle( { initialState }: ErrorReportingToggleProps ) {
	const [ enabled, setEnabled ] = useState< boolean >( Boolean( initialState?.enabled ) );
	const [ busy, setBusy ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );

	if ( ! initialState || ! initialState.available ) {
		return null;
	}

	const handleChange = async ( checked: boolean ) => {
		setBusy( true );
		setError( null );

		try {
			const response = await apiFetch< ErrorReportingState >( {
				path: '/woodev/v1/error-reporting',
				method: 'POST',
				data: { enabled: checked },
			} );
			setEnabled( Boolean( response.enabled ) );
		} catch ( err ) {
			setError( __( 'Не удалось сохранить настройку. Попробуйте ещё раз.', 'woodev-plugin-framework' ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Card className="woodev-error-reporting">
			<CardBody>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<CheckboxControl
					label={ __( 'Отправлять отчёты об ошибках', 'woodev-plugin-framework' ) }
					help={ __(
						'Если в плагине Woodev произойдёт сбой, мы получим версии плагина, WordPress, WooCommerce и PHP, вид ошибки и место в коде плагина: файл, строку и названия функций. Тексты сообщений об ошибках, данные покупателей и адрес сайта не передаются — вместо адреса только обезличенный код.',
						'woodev-plugin-framework'
					) }
					checked={ enabled }
					disabled={ busy }
					onChange={ handleChange }
				/>
			</CardBody>
		</Card>
	);
}
