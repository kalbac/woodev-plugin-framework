/**
 * The «Диагностика» page: the operational state, the diagnostic sections and the support report.
 *
 * The menu lists the page only while «Логирование» is on, so the page has no switch of its own. One white wrapper card holds an
 * intro sentence and a card per section (the heading inside, the lines as `dl.woodev-facts`). A page left open after the
 * merchant switched «Логирование» off says so and keeps only the operational sections.
 *
 * @package woodev-plugin-framework
 */

import { Fragment, useEffect, useMemo, useState } from '@wordpress/element';
import { Button, Card, CardBody, Notice, Spinner } from '@wordpress/components';
import { ProblemsLink } from '../../../components/help-link';
import type { PageViewProps } from '../../types';
import { errorLines } from '../mappings/api';
import { createDiagnosticsApi } from './api';
import { copyOutcome } from './report';
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
			<Card className="woodev-settings__card">
				<CardBody>
					<p className="woodev-settings__section-desc">{ messages.intro }</p>
					{ notice && (
						<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
							{ notice.lines.join( ' ' ) }
							<ProblemsLink status={ notice.status } />
						</Notice>
					) }
					{ ! enabled && (
						<Notice status="warning" isDismissible={ false }>
							{ messages.loggingOff }
						</Notice>
					) }
					<Sections sections={ state.operational } />
					{ enabled && report && (
						<>
							<Sections sections={ report.diagnostic } />
							<div className="woodev-group">
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
							</div>
						</>
					) }
				</CardBody>
			</Card>
		</div>
	);
}

export function Sections( { sections }: { sections: DiagnosticsSection[] } ) {
	return (
		<>
			{ sections.map( ( section ) => (
				<div key={ section.title } className="woodev-group">
					<h3 className="woodev-group__title">{ section.title }</h3>
					<dl className="woodev-facts">
						{ section.lines.map( ( line ) => (
							<Fragment key={ line.label }>
								<dt>{ line.label }</dt>
								<dd>
									{ line.monospace ? (
										<code className="woodev-facts__code">
											{ line.value }
										</code>
									) : (
										line.value
									) }
									{ /* The link is a line of its own: beside the address it wrapped into it. */ }
									{ line.link && (
										<div className="woodev-facts__link">
											<a href={ line.link.url }>
												{ line.link.text }
											</a>
										</div>
									) }
								</dd>
							</Fragment>
						) ) }
					</dl>
				</div>
			) ) }
		</>
	);
}
