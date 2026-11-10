/**
 * «Check the products of this category»: runs the batches and prints the server's sentences.
 *
 * @package woodev-plugin-framework
 */

import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { errorLines, type MappingsApi } from './api';
import type { Messages } from './types';

interface Props {
	api: MappingsApi;
	messages: Messages;
	mappingId: number;
}

interface Report {
	text: string;
	groups: Array< { text: string; count: number } >;
}

export function CheckReport( { api, messages, mappingId }: Props ) {
	const [ running, setRunning ] = useState( false );
	const [ report, setReport ] = useState< Report | null >( null );

	const run = async () => {
		const id = Date.now().toString( 36 );
		let offset = 0;

		setRunning( true );
		setReport( { text: messages.checking, groups: [] } );

		try {
			for ( ;; ) {
				const step = await api.check( mappingId, offset, id );

				setReport( { text: step.text, groups: step.groups } );

				if ( step.done ) {
					break;
				}

				offset = step.next_offset;
			}
		} catch ( error ) {
			setReport( { text: errorLines( error, messages.requestFailed ).join( ' ' ), groups: [] } );
		}

		setRunning( false );
	};

	return (
		<div>
			<Button variant="secondary" onClick={ run } disabled={ running } isBusy={ running }>
				{ running ? messages.checking : messages.check }
			</Button>
			<p className="description">{ messages.checkHelp }</p>
			{ report && (
				<div role="status">
					<p>{ report.text }</p>
					<ul>
						{ report.groups.map( ( group ) => (
							<li key={ group.text }>{ group.text }</li>
						) ) }
					</ul>
				</div>
			) }
		</div>
	);
}
