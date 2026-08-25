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

function ToolCard( { providerId, tool } ) {
	const selector = tool.selector || null;
	const [ value, setValue ] = useState( selector ? ( selector.default ?? '' ) : '' );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );

	const run = () => {
		// Busy + result-clear happen synchronously, before the request even
		// starts — a sweep over a live provider takes seconds, and an
		// un-indicated wait reads as a dead button.
		setBusy( true );
		setResult( null );

		const args = selector ? { [ selector.name ]: value } : {};

		runTool( providerId, tool.id, args )
			.then( ( res ) => setResult( res ) )
			.catch( ( err ) =>
				setResult( {
					success: false,
					message: ( err && err.message ) || __( 'Ошибка выполнения.', 'woodev-plugin-framework' ),
				} )
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="woodev-tool">
			<h4 className="woodev-tool__name">{ tool.name }</h4>
			{ tool.desc && <p className="woodev-tool__desc">{ tool.desc }</p> }

			{ selector && (
				<div className="woodev-tool__selector">
					{ selector.description && (
						<span className="woodev-tool__selector-label">{ selector.description }</span>
					) }
					<SelectField
						value={ value }
						options={ selector.options }
						onChange={ setValue }
						placeholder={ selector.placeholder }
						disabled={ tool.disabled || busy }
					/>
				</div>
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
