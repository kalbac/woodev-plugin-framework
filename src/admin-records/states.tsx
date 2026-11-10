/**
 * What the table shows instead of rows: a failed read, an empty result, the first load.
 *
 * WooCommerce's own `emptyMessage` does not exist in the oldest version we support, and its text would not be
 * PHP's, so these are the shell's own blocks ({@see ./wc-runtime}).
 *
 * @package woodev-plugin-framework
 */

import { Button, Spinner } from '@wordpress/components';
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
			{ retryLabel !== '' && (
				<Button __next40pxDefaultSize variant="secondary" onClick={ onRetry }>
					{ retryLabel }
				</Button>
			) }
		</div>
	);
}

export function EmptyState( { text }: { text: string } ) {
	return (
		<div className="woodev-records__state is-empty" style={ STYLES.state } role="status">
			<p>{ text }</p>
		</div>
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
