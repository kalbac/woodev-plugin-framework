/**
 * «Инструменты» section: one card per registered tool — optional provider
 * selector, an action button, and (below it) the run result.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import SelectField from '../components/select-field';
import { runTool } from './rest';

/**
 * The selector's starting value (its `default`, else empty).
 *
 * @param {?{ default?: string }|undefined} selector `Shipping_Tool::to_array()['selector']`.
 * @return {string} initial value.
 */
export function selectorDefault( selector ) {
	return selector ? ( selector.default ?? '' ) : '';
}

/**
 * The args one run sends: the selector's named value, or nothing for a tool without a selector.
 * Shared by the tool card and the group card so both send the same payload.
 *
 * @param {?{ name: string }|undefined} selector the tool's selector.
 * @param {string}                      value    the selected value.
 * @return {Object<string, string>} args for `runTool()`.
 */
export function toolArgs( selector, value ) {
	return selector ? { [ selector.name ]: value } : {};
}

/**
 * A tool's provider selector: optional label above a select. Shared by the tool card and the group card.
 *
 * @param {Object}   props
 * @param {*}        props.selector `Shipping_Tool::to_array()['selector']`.
 * @param {string}   props.value    selected value.
 * @param {Function} props.onChange receives the next value.
 * @param {boolean}  props.disabled whether the select is disabled.
 */
export function ToolSelector( { selector, value, onChange, disabled } ) {
	return (
		<div className="woodev-tool__selector">
			{ selector.description && (
				<span className="woodev-tool__selector-label">{ selector.description }</span>
			) }
			<SelectField
				value={ value }
				options={ selector.options }
				onChange={ onChange }
				placeholder={ selector.placeholder }
				disabled={ disabled }
			/>
		</div>
	);
}

function ToolCard( { providerId, tool } ) {
	const selector = tool.selector || null;
	const [ value, setValue ] = useState( selectorDefault( selector ) );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );

	const run = () => {
		// Busy + result-clear happen synchronously, before the request even
		// starts — a sweep over a live provider takes seconds, and an
		// un-indicated wait reads as a dead button.
		setBusy( true );
		setResult( null );

		runTool( providerId, tool.id, toolArgs( selector, value ) )
			.then( ( res ) => setResult( res ) )
			.catch( ( err ) =>
				setResult( {
					success: false,
					message: ( err && err.message ) || __( 'Ошибка выполнения.', 'woodev-plugin-framework' ),
				} )
			)
			.finally( () => setBusy( false ) );
	};

	// The selector is the load-bearing input — it names which provider's list
	// a run reports on. A stale result under a changed selector would read as
	// a claim about the provider now selected, so drop it the same way
	// ConnectionBlock drops a stale result on a field change.
	const handleSelectorChange = ( next ) => {
		setResult( null );
		setValue( next );
	};

	return (
		<div className="woodev-tool">
			<h4 className="woodev-tool__name">{ tool.name }</h4>
			{ tool.desc && <p className="woodev-tool__desc">{ tool.desc }</p> }

			{ selector && (
				<ToolSelector
					selector={ selector }
					value={ value }
					onChange={ handleSelectorChange }
					disabled={ tool.disabled || busy }
				/>
			) }

			<div className="woodev-tool__action">
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy || tool.disabled }
					onClick={ run }
				>
					{ tool.button }
				</Button>
			</div>

			{ tool.disabled && tool.status_text && (
				<p className="woodev-tool__status">{ tool.status_text }</p>
			) }

			{ result && (
				<div className={ `woodev-tool__result is-${ result.success ? 'ok' : 'error' }` }>
					{ result.message }
				</div>
			) }
		</div>
	);
}

/**
 * The tools as ONE row of buttons: the first is primary, the rest secondary, and the result line of the
 * last clicked one sits under the row. A tool's `desc` is the button's accessible description. Used for a
 * section whose actions are one account's operations (`Settings_Section::ACTIONS_ROW`); the default layout
 * stays one card per tool ({@see ToolsBlock}).
 *
 * @param {Object}   props
 * @param {string}   props.providerId the settings tab id.
 * @param {Object[]} props.tools      `Shipping_Tool::to_array()` rows.
 */
export function ToolsRow( { providerId, tools } ) {
	const [ busyId, setBusyId ] = useState( '' );
	const [ result, setResult ] = useState( null );
	// Each selector-backed tool keeps its own selected value, like a ToolCard does.
	const [ selected, setSelected ] = useState( {} );
	const selectedOf = ( tool ) => ( tool.id in selected ? selected[ tool.id ] : selectorDefault( tool.selector ) );
	const notes = tools
		.filter( ( tool ) => tool.disabled && tool.status_text )
		.map( ( tool ) => tool.status_text )
		.filter( ( line, index, all ) => all.indexOf( line ) === index );

	const run = ( tool ) => {
		// Busy + result-clear before the request starts, like ToolCard — a live call takes seconds.
		setBusyId( tool.id );
		setResult( null );

		runTool( providerId, tool.id, toolArgs( tool.selector || null, selectedOf( tool ) ) )
			.then( ( res ) => setResult( res ) )
			.catch( ( err ) =>
				setResult( {
					success: false,
					message: ( err && err.message ) || __( 'Ошибка выполнения.', 'woodev-plugin-framework' ),
				} )
			)
			.finally( () => setBusyId( '' ) );
	};

	return (
		<div className="woodev-tools-row">
			{ tools.filter( ( tool ) => tool.selector ).map( ( tool ) => (
				<ToolSelector
					key={ `selector:${ tool.id }` }
					selector={ tool.selector }
					value={ selectedOf( tool ) }
					onChange={ ( next ) => {
						setResult( null );
						setSelected( ( prev ) => ( { ...prev, [ tool.id ]: next } ) );
					} }
					disabled={ tool.disabled || '' !== busyId }
				/>
			) ) }
			<div className="woodev-tools-row__buttons">
				{ tools.map( ( tool, index ) => (
					<Button
						key={ tool.id }
						variant={ 0 === index ? 'primary' : 'secondary' }
						isBusy={ busyId === tool.id }
						disabled={ '' !== busyId || tool.disabled }
						describedBy={ tool.desc || undefined }
						onClick={ () => run( tool ) }
					>
						{ tool.button }
					</Button>
				) ) }
			</div>
			{ notes.map( ( line ) => (
				<p key={ line } className="woodev-tools-row__note">{ line }</p>
			) ) }
			{ result && (
				<div className={ `woodev-tools-row__result is-${ result.success ? 'ok' : 'error' }` }>
					{ result.message }
				</div>
			) }
		</div>
	);
}

export default function ToolsBlock( { providerId, section } ) {
	return (
		<div className="woodev-tools">
			{ section.description && (
				<p className="woodev-tools__desc">{ section.description }</p>
			) }
			{ ( section.tools || [] ).map( ( tool ) => (
				<ToolCard key={ tool.id } providerId={ providerId } tool={ tool } />
			) ) }
		</div>
	);
}
