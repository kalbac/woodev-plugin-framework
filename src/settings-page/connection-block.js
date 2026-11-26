/**
 * Self-contained connection block: credential fields + a primary action button
 * (test/connect) + ephemeral result + optional on-load status badge.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { useState } from '@wordpress/element';
import { RawHTML } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import ControlField, { resolveControl } from '../components/control-field';
import { isRequirable } from '../components/validate';
import { testConnection } from './rest';

/**
 * Whether a connection field counts as filled: a wp-config constant, a typed value, or a masked secret that is
 * already saved (`is_set`) all do, even though a saved secret's input is empty.
 *
 * @param {Object} field  the field schema.
 * @param {*}      edited the staged value, `undefined` when the field has no edit.
 * @return {boolean} whether the field is satisfied.
 */
export function isFieldFilled( field, edited ) {
	if ( field.constant_managed ) {
		return true;
	}
	const current = edited ?? field.value;
	if ( current !== '' && current !== null && current !== undefined ) {
		return true;
	}
	return !! ( field.sensitive && field.is_set );
}

/**
 * Labels of the required fields that are still empty, in schema order. Only `required` fields that the
 * server also enforces count (same rule as the required marker: `isRequirable` on the resolved control),
 * so an optional field such as a description never gates the test button.
 *
 * @param {Object} fields schema fields of the section, keyed by setting id.
 * @param {Object} values staged values of the section, keyed by setting id.
 * @return {string[]} labels of the empty required fields.
 */
export function emptyRequiredLabels( fields, values ) {
	return Object.keys( fields )
		.filter( ( settingId ) => {
			const field = fields[ settingId ];
			return !! field.required && isRequirable( resolveControl( field ) ) && ! isFieldFilled( field, values[ settingId ] );
		} )
		.map( ( settingId ) => fields[ settingId ].name );
}

/**
 * Hint under the disabled test button, naming the empty required field(s).
 *
 * @param {string[]} labels labels of the empty required fields, non-empty.
 * @return {string} the hint text.
 */
export function emptyFieldsHint( labels ) {
	if ( 1 === labels.length ) {
		return sprintf( __( 'Заполните поле «%s».', 'woodev-plugin-framework' ), labels[ 0 ] );
	}
	return sprintf( __( 'Заполните поля: %s.', 'woodev-plugin-framework' ), labels.join( ', ' ) );
}

export default function ConnectionBlock( { providerId, section, values, conditionValues, onFieldChange, onFieldRevert, serverErrors } ) {
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

	// An ephemeral test result goes stale the moment a credential changes — drop
	// it so a prior "success" never lingers next to edited fields.
	const handleFieldChange = ( settingId, next ) => {
		setResult( null );
		onFieldChange( settingId, next );
	};

	// Reverting a staged edit (e.g. cancelling a pending secret wipe) is a change
	// too — drop any stale result the same way.
	const handleFieldRevert = ( settingId ) => {
		setResult( null );
		onFieldRevert( settingId );
	};

	// Gate the action button on every required field being satisfied. A handshake block
	// (no fields) is vacuously satisfied → always enabled.
	const emptyLabels = emptyRequiredLabels( section.fields, values );
	const allFieldsFilled = 0 === emptyLabels.length;

	return (
		<div className="woodev-connection">
			{ section.description && (
				<div className="woodev-connection__desc"><RawHTML>{ section.description }</RawHTML></div>
			) }
			{ Object.keys( section.fields ).map( ( settingId ) => (
				<ControlField
					key={ settingId }
					schema={ { ...section.fields[ settingId ], serverError: ( serverErrors || {} )[ settingId ] } }
					conditionValues={ conditionValues || values }
					value={ values[ settingId ] ?? section.fields[ settingId ].value }
					onChange={ ( next ) => handleFieldChange( settingId, next ) }
					hasEdit={ Object.prototype.hasOwnProperty.call( values, settingId ) }
					onRevert={ () => handleFieldRevert( settingId ) }
				/>
			) ) }
			<div className="woodev-connection__action">
				{ section.supports_test && (
					<Button
						variant="secondary"
						isBusy={ busy }
						disabled={ busy || ! allFieldsFilled }
						onClick={ run }
					>
						{ section.action_label || __( 'Проверить подключение', 'woodev-plugin-framework' ) }
					</Button>
				) }
				{ section.supports_test && ! allFieldsFilled && (
					<span className="woodev-connection__hint">{ emptyFieldsHint( emptyLabels ) }</span>
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
