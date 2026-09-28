/**
 * Placeholders for steps ④ «Доставка» and ⑤ «Оплата» (#710 D1).
 *
 * Both belong to later increments — I5a (rates, pickup points, delivery price) and I5b
 * (payment, status, totals, submit). They are drawn so the wizard's five-step shape can be
 * walked end to end now, and are replaced through {@link StepRenderers} (`order-wizard.tsx`
 * takes them as a prop), never edited into the shell.
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

interface StepPlaceholderProps {
	title: string;
}

export default function StepPlaceholder( { title }: StepPlaceholderProps ) {
	return (
		<div className="woodev-order-wizard__step woodev-order-wizard__placeholder">
			<h3 className="woodev-order-wizard__step-title">{ title }</h3>
			<Notice status="info" isDismissible={ false }>
				{ __( 'Этот шаг ещё в разработке — он появится в следующем обновлении.', 'woodev-plugin-framework' ) }
			</Notice>
		</div>
	);
}
