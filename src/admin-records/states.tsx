/**
 * What the table shows instead of rows: a failed read, an empty result, the first load.
 *
 * WooCommerce's own `emptyMessage` does not exist in the oldest version we support, and its text would not be
 * PHP's, so these are the shell's own blocks ({@see ./wc-runtime}).
 *
 * @package woodev-plugin-framework
 */

import type { ReactNode } from 'react';
import { Button, Card, CardBody, CardHeader, Spinner } from '@wordpress/components';
import { ProblemsLink } from '../components/help-link';
import { STYLES } from './styles';

export interface ErrorStateProps {
	/** The server's sentence, or PHP's generic one. */
	text: string;
	/** Label of the retry button; without it there is no button. */
	retryLabel: string;
	onRetry: () => void;
}

export function ErrorState( { text, retryLabel, onRetry }: ErrorStateProps ) {
	return (
		<div className="woodev-records__state is-error" style={ STYLES.state } role="alert">
			<p>{ text }</p>
			<ProblemsLink status="error" />
			{ retryLabel !== '' && (
				<Button __next40pxDefaultSize variant="secondary" onClick={ onRetry }>
					{ retryLabel }
				</Button>
			) }
		</div>
	);
}

export interface EmptyCardProps {
	/** The card title: the one the table would have. */
	title: string;
	/** The table header's own controls (bulk group, search), so a search that found nothing can still be edited or cleared. */
	actions: ReactNode;
	/** PHP's sentence for an empty (or empty filtered) table. */
	text: string;
	/** An action offered right in the empty table («Обновить список»), if PHP named one. */
	button?: ReactNode;
}

/**
 * The empty result in the frame of the table it stands for: `TableCard` has no `emptyMessage` in the oldest WooCommerce
 * we support, so the card is built from what `TableCard` itself is made of. Never a bare box instead of the card.
 */
export function EmptyCard( { title, actions, text, button }: EmptyCardProps ) {
	return (
		<Card className="woodev-records__empty-card">
			<CardHeader className="woodev-records__empty-header">
				<h2 className="woodev-records__empty-title">{ title }</h2>
				{ actions }
			</CardHeader>
			<CardBody>
				<div className="woodev-records__state is-empty" role="status">
					<p>{ text }</p>
					{ button }
				</div>
			</CardBody>
		</Card>
	);
}

/** Before the first definition arrives there is no PHP string to show, only that something is happening. */
export function LoadingState() {
	return (
		<div className="woodev-records__state is-loading" style={ STYLES.state } aria-busy="true">
			<Spinner />
		</div>
	);
}
