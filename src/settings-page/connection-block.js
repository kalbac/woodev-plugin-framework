/**
 * Self-contained connection block: credential fields + a primary action button
 * (test/connect) + ephemeral result + optional on-load status badge.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import ControlField from '../components/control-field';
import { testConnection } from './rest';

export default function ConnectionBlock( { providerId, section, values, onFieldChange } ) {
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( section.status || null );

	const run = () => {
		setBusy( true );
		setResult( null );
		testConnection( providerId, section.id, values )
			.then( ( res ) => setResult( res ) )
			.catch( ( err ) =>
				setResult( {
					success: false,
					message: ( err && err.message ) || __( 'Ошибка проверки.', 'woodev-plugin-framework' ),
				} )
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="woodev-connection">
			{ section.description && (
				<p className="woodev-connection__desc">{ section.description }</p>
			) }
			{ Object.keys( section.fields ).map( ( settingId ) => (
				<ControlField
					key={ settingId }
					schema={ section.fields[ settingId ] }
					value={ values[ settingId ] ?? section.fields[ settingId ].value }
					onChange={ ( next ) => onFieldChange( settingId, next ) }
				/>
			) ) }
			<div className="woodev-connection__action">
				{ section.supports_test && (
					<Button variant="secondary" isBusy={ busy } disabled={ busy } onClick={ run }>
						{ section.action_label || __( 'Проверить подключение', 'woodev-plugin-framework' ) }
					</Button>
				) }
				{ result && (
					<span
						className={ `woodev-connection__result is-${ result.success ? 'ok' : 'error' }` }
					>
						{ result.message }
					</span>
				) }
			</div>
		</div>
	);
}
