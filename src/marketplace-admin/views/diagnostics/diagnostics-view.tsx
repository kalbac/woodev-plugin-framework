/**
 * The «Диагностика» page: the operational state always, and the diagnostic sections and the support report while
 * «Логирование» is on.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import type { PageViewProps } from '../../types';
import { errorLines } from '../mappings/api';
import { createDiagnosticsApi } from './api';
import { copyOutcome, loggingSwitchLabel } from './report';
import type { DiagnosticsReport, DiagnosticsSection, DiagnosticsState } from './types';

interface NoticeState {
	status: 'success' | 'error';
	lines: string[];
}

export function DiagnosticsView( { menu }: PageViewProps ) {
	const api = useMemo( () => createDiagnosticsApi( menu.pluginId ), [ menu.pluginId ] );
	const [ state, setState ] = useState< DiagnosticsState | null >( null );
	const [ report, setReport ] = useState< DiagnosticsReport | null >( null );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );

	const load = () => {
		api.state()
			.then( setState )
			.catch( ( error ) => setNotice( { status: 'error', lines: errorLines( error, '' ) } ) );
	};

	useEffect( load, [ api ] );

	const enabled = state?.logging.enabled === true;

	useEffect( () => {
		if ( ! enabled ) {
			setReport( null );
			return;
		}

		api.report()
			.then( setReport )
			.catch( ( error ) => setNotice( { status: 'error', lines: errorLines( error, state?.messages.reportFailed ?? '' ) } ) );
	}, [ api, enabled ] );

	if ( ! state ) {
		return <Spinner />;
	}

	const { messages } = state;

	const toggle = () => {
		api.setLogging( ! enabled )
			.then( () => {
				setNotice( null );
				load();
			} )
			.catch( ( error ) => setNotice( { status: 'error', lines: errorLines( error, messages.switchFailed ) } ) );
	};

	const copy = () => {
		// `navigator.clipboard` is undefined on a plain-http admin: say so and point at the text below instead of throwing.
		if ( ! navigator.clipboard ) {
			setNotice( { status: 'error', lines: [ copyOutcome( false, messages ) ] } );
			return;
		}

		navigator.clipboard
			.writeText( report?.report ?? '' )
			.then( () => setNotice( { status: 'success', lines: [ copyOutcome( true, messages ) ] } ) )
			.catch( () => setNotice( { status: 'error', lines: [ copyOutcome( false, messages ) ] } ) );
	};

	return (
		<div className="woodev-diagnostics-view" style={ { paddingRight: 20 } }>
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.lines.join( ' ' ) }
				</Notice>
			) }
			<p>{ enabled ? messages.loggingOn : messages.loggingOff }</p>
			<Button variant={ enabled ? 'secondary' : 'primary' } onClick={ toggle }>
				{ loggingSwitchLabel( enabled, messages ) }
			</Button>
			<Sections sections={ state.operational } />
			{ enabled && report && (
				<>
					<Sections sections={ report.diagnostic } />
					<h2>{ messages.reportHeading }</h2>
					<p>{ messages.reportHint }</p>
					<Button variant="primary" onClick={ copy }>
						{ messages.copyReport }
					</Button>
					<textarea
						className="large-text code"
						rows={ 18 }
						readOnly
						value={ report.report }
					/>
				</>
			) }
		</div>
	);
}

function Sections( { sections }: { sections: DiagnosticsSection[] } ) {
	return (
		<>
			{ sections.map( ( section ) => (
				<section key={ section.title }>
					<h2>{ section.title }</h2>
					<table className="widefat striped" style={ { maxWidth: 960 } }>
						<tbody>
							{ section.lines.map( ( line ) => (
								<tr key={ line.label }>
									<th scope="row" style={ { width: '30%' } }>
										{ line.label }
									</th>
									<td>{ line.value }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</section>
			) ) }
		</>
	);
}
