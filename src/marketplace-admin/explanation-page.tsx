/**
 * The body of a page the menu leaves out: one card with the sentence PHP wrote and a link to where the switch is.
 *
 * A bookmark to «Диагностика» while «Логирование» is off lands here instead of on wc-admin's English «not allowed» screen.
 *
 * @package woodev-plugin-framework
 */

import { Card, CardBody } from '@wordpress/components';
import type { PageExplanation } from './types';

export function ExplanationPage( { explanation }: { explanation: PageExplanation } ) {
	return (
		<div className="woodev-settings woodev-settings--marketplace woodev-marketplace-explanation">
			<Card className="woodev-settings__card">
				<CardBody>
					<p className="woodev-settings__section-desc">{ explanation.message }</p>
					{ explanation.settings && <a href={ explanation.settings.url }>{ explanation.settings.label }</a> }
				</CardBody>
			</Card>
		</div>
	);
}
