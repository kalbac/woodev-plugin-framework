/**
 * Shapes of what the mappings REST routes return (`Mappings_Controller`). The view holds no copy: every sentence is in
 * `messages`, every option list arrives from PHP.
 *
 * @package woodev-plugin-framework
 */

export interface Option {
	value: string;
	label: string;
}

/** The sentences of the view by key (`View_Config::messages()`). */
export type Messages = Record< string, string >;

export interface ShopCategory {
	id: number;
	label: string;
	/** The mapping that already holds this category, if any. */
	mapping_id: number | null;
	mapping_name: string;
}

export interface MappingSummary {
	id: number;
	name: string;
	terms: number[];
	category_label: string;
	profile_id: number | null;
	products: number;
}

export interface ProfileSummary {
	id: number;
	name: string;
	mappings: number;
}

export interface SourceSetting {
	setting: string;
	title: string;
	kinds: Option[];
	default: string;
	/** The kind that names a product meta key. */
	custom: string;
}

export interface ProfileForm {
	sources: SourceSetting[];
	vat_rates: Option[];
	weight_units: Option[];
	dimension_units: Option[];
	/** Label of the marketplace's own currency rule; null when it has none. */
	currency_field: string | null;
	price: Record< 'base' | 'markup_kind' | 'rounding' | 'old_price_rule', Option[] >;
}

export interface MappingsState {
	messages: Messages;
	mappings: MappingSummary[];
	profiles: ProfileSummary[];
	shop_categories: ShopCategory[];
	product_fields: Option[];
	profile_form: ProfileForm;
	categories_loaded: boolean;
}

export interface CategoryChoice {
	key: string;
	label: string;
}

export interface MappingCategory extends CategoryChoice {
	/** False when the marketplace no longer offers the saved category. */
	available: boolean;
}

export interface AttributeSource {
	source: string;
	value: string;
	key: string;
	dictionary_value_id: string;
}

export interface AttributeRow {
	id: string;
	name: string;
	required: boolean;
	type: string;
	dictionary: boolean;
	multi: boolean;
	unit: string | null;
	source: AttributeSource;
}

export interface MappingDetail {
	id: number;
	name: string;
	terms: number[];
	profile_id: number | null;
	category: MappingCategory | null;
	attributes: AttributeRow[];
}

export type ProfileValues = Record< string, string | boolean >;

export interface ProfileDetail {
	id: number;
	name: string;
	values: ProfileValues;
}

export interface DictionaryValue {
	id: number;
	text: string;
	name: string;
}

export interface CheckStep {
	done: boolean;
	next_offset: number;
	groups: Array< { text: string; count: number } >;
	text: string;
}

export interface SaveResult {
	id: number;
	notices: string[];
}
