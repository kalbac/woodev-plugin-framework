/**
 * UI-kit gallery — showcases every kit component and field type in isolation,
 * so the design system can be reviewed before rollout across the real surfaces.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { useState } from '@wordpress/element';
import { Card, CardBody, Tooltip, Button } from '@wordpress/components';
import ControlField from '../components/control-field';
import TabsNav from '../components/tabs-nav';

const FIELD_SPECS = [
	{ id: 'text', name: 'Текстовое поле', controlType: 'text', description: 'Обычный однострочный ввод.', tooltip: 'Это длинная подсказка, которая раньше обрезалась за краем экрана; теперь она в портале и переносится корректно.', value: 'Карьер №1' },
	{ id: 'select', name: 'Выпадающий список (поиск)', controlType: 'select', description: 'WC-стиль: кнопка + поиск в выпадающем списке.', options: { msk: 'Москва', spb: 'Санкт-Петербург', nsk: 'Новосибирск', ekb: 'Екатеринбург', kzn: 'Казань' }, value: 'spb' },
	{ id: 'multi', name: 'Мультивыбор', controlType: 'multiselect', description: 'Как выпадающий список, но можно выбрать несколько.', options: { a: 'Самовывоз', b: 'Курьер', c: 'Почта', d: 'Постамат' }, value: [ 'a', 'b' ] },
	{ id: 'range', name: 'Наценка', controlType: 'range', description: 'Слайдер с суффиксом.', min: 0, max: 100, step: 5, suffix: '%', value: 25 },
	{ id: 'textarea', name: 'Многострочное', controlType: 'textarea', value: 'Несколько\nстрок текста.' },
	{ id: 'richtext', name: 'Форматированный текст', controlType: 'richtext', description: 'contentEditable с тулбаром.', value: '<b>Жирный</b> и <i>курсив</i>.' },
	{ id: 'number', name: 'Число', controlType: 'number', suffix: 'кг', value: '12' },
	{ id: 'color', name: 'Цвет', controlType: 'color', value: '#06aedd' },
	{ id: 'email', name: 'E-mail', controlType: 'email', value: 'shop@example.ru' },
	{ id: 'password', name: 'Пароль / токен', controlType: 'password', value: 'secret-token' },
	{ id: 'date', name: 'Дата', controlType: 'date', value: '2026-06-27' },
];

const TOGGLE_SPECS = [
	{ id: 't1', name: 'Включить интеграцию', controlType: 'toggle', description: 'WC-pill переключатель.', value: true },
	{ id: 't2', name: 'Тестовый режим', controlType: 'toggle', description: 'Запросы идут на песочницу.', value: false },
];

const RADIO_SPEC = { id: 'r1', name: 'Тип расчёта', controlType: 'radio', description: 'Сгруппированные опции.', options: { fixed: 'Фиксированная ставка', dynamic: 'По тарифу перевозчика' }, value: 'dynamic' };

const DEMO_TABS = [
	{ id: 'quarry', label: 'Карьер', sections: [ { id: 'auth', label: 'Авторизация' }, { id: 'order', label: 'Форма заказа' } ] },
	{ id: 'cdek', label: 'СДЭК', sections: [ { id: 'auth', label: 'Авторизация' } ] },
	{ id: 'post', label: 'Почта России', sections: [ { id: 'auth', label: 'Авторизация' }, { id: 'pack', label: 'Упаковка' } ] },
];

function Field( { spec, values, setValues } ) {
	return (
		<ControlField
			schema={ spec }
			value={ values[ spec.id ] ?? spec.value }
			onChange={ ( v ) => setValues( ( prev ) => ( { ...prev, [ spec.id ]: v } ) ) }
		/>
	);
}

function Section( { title, children } ) {
	return (
		<Card className="woodev-gallery__card">
			<CardBody>
				<h2 className="woodev-gallery__heading">{ title }</h2>
				{ children }
			</CardBody>
		</Card>
	);
}

export default function App() {
	const [ values, setValues ] = useState( {} );

	return (
		<div className="woodev-gallery woodev-settings">
			<Section title="Навигация — папочные табы + под-табы + deep-link">
				<TabsNav
					tabs={ DEMO_TABS }
					renderSection={ ( tab, sectionId ) => (
						<p className="woodev-gallery__note">
							Провайдер: <strong>{ tab.label }</strong>, секция: <strong>{ sectionId }</strong>.
							URL обновляется (?tab=&section=).
						</p>
					) }
				/>
			</Section>

			<Section title="Поля — все типы контролов">
				{ FIELD_SPECS.map( ( spec ) => (
					<Field key={ spec.id } spec={ spec } values={ values } setValues={ setValues } />
				) ) }
			</Section>

			<Section title="Сгруппированные поля — переключатели и радио">
				<div className="woodev-field__option-group woodev-field__option-group--standalone">
					{ TOGGLE_SPECS.map( ( spec ) => (
						<Field key={ spec.id } spec={ spec } values={ values } setValues={ setValues } />
					) ) }
				</div>
				<Field spec={ RADIO_SPEC } values={ values } setValues={ setValues } />
			</Section>

			<Section title="Оверлеи и действия">
				<p>
					<Tooltip text="Тултип в портале — не обрезается даже у самого края окна, текст переносится на несколько строк по ширине." placement="right">
						<span className="woodev-field__tip" tabIndex={ 0 }>?</span>
					</Tooltip>
					<span style={ { marginLeft: 8 } }>наведи на знак вопроса (тултип у края).</span>
				</p>
				<div style={ { display: 'flex', gap: 8, marginTop: 12 } }>
					<Button variant="primary">Основная</Button>
					<Button variant="secondary">Вторичная</Button>
					<Button variant="tertiary">Третичная</Button>
				</div>
			</Section>
		</div>
	);
}
