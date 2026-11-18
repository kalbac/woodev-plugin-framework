/**
 * The «Диагностика» page: the operational state always, and the diagnostic sections and the support report while
 * «Логирование» is on.
 *
 * Every section is a card of the settings page's look: the heading inside, the lines as `dl.woodev-facts`.
 *
 * @package woodev-plugin-framework
 */

import { Fragment, useEffect, useMemo, useState } from '@wordpress/element';
import { Button, Card, CardBody, Notice, Spinner } from '@wordpress/components';
import { ProblemsLink } from '../../../components/help-link';
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
		// A state that cannot be read is said, not left as a spinner (the tools view does the same).
		return notice?.status === 'error' ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.lines.join( ' ' ) }
				<ProblemsLink status="error" />
			</Notice>
		) : (
			<Spinner />
		);
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
		<div className="woodev-settings woodev-settings--marketplace woodev-diagnostics-view">
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.lines.join( ' ' ) }
					<ProblemsLink status={ notice.status } />
				</Notice>
			) }
			<Card className="woodev-settings__card">
				<CardBody>
					<h3 className="woodev-group__title">{ messages.loggingHeading }</h3>
					<p className="woodev-group__desc">{ enabled ? messages.loggingOn : messages.loggingOff }</p>
					<Button variant={ enabled ? 'secondary' : 'primary' } onClick={ toggle }>
						{ loggingSwitchLabel( enabled, messages ) }
					</Button>
				</CardBody>
			</Card>
			<Sections sections={ state.operational } />
			{ enabled && report && (
				<>
					<Sections sections={ report.diagnostic } />
					<Card className="woodev-settings__card">
						<CardBody>
							<h3 className="woodev-group__title">{ messages.reportHeading }</h3>
							<p className="woodev-group__desc">{ messages.reportHint }</p>
							<Button variant="primary" onClick={ copy }>
								{ messages.copyReport }
							</Button>
							<textarea
								className="large-text code woodev-diagnostics-view__report"
								rows={ 18 }
								readOnly
								value={ report.report }
							/>
						</CardBody>
					</Card>
				</>
			) }
		</div>
	);
}

function Sections( { sections }: { sections: DiagnosticsSection[] } ) {
	return (
		<>
			{ sections.map( ( section ) => (
				<Card key={ section.title } className="woodev-settings__card">
					<CardBody>
						<h3 className="woodev-group__title">{ section.title }</h3>
						<dl className="woodev-facts">
							{ section.lines.map( ( line ) => (
								<Fragment key={ line.label }>
									<dt>{ line.label }</dt>
									<dd>{ line.value }</dd>
								</Fragment>
							) ) }
						</dl>
					</CardBody>
				</Card>
			) ) }
		</>
	);
}
