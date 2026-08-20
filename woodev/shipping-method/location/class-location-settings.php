<?php
/**
 * Woodev Location Settings
 *
 * The store-level settings handler for the Location Provider layer (Task 3;
 * spec D4: "provider tokens/keys are store settings, held server-side"). Owns
 * exactly three kinds of fields:
 *
 * 1. `active_provider` — a select whose options are every registered provider's
 *    id => name, defaulting to {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::DEFAULT_PROVIDER_ID}.
 * 2. `field_mode_region` / `field_mode_settlement` (Task 13; spec D7; split into
 *    two independent axes by issue #380 — typeahead always offered on both;
 *    related-list/ajax-select2 offered on a provider-capability gate BOTH axes
 *    share, with the settlement axis additionally requiring the region axis to
 *    itself be `related-list` before offering it too (issue #404 — a bulk list
 *    of every settlement in a country does not exist, only a per-region one),
 *    computed by
 *    {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry}; this
 *    handler only ever renders whatever option set it was handed, exactly like
 *    `active_provider`'s own options) — and `address_suggestions` (Task 10;
 *    issue #362), a plain boolean. All three DISPLAY on the «Поля» section
 *    since issue #380 ({@see \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::build_sections()}),
 *    though this handler still registers and owns them (ADR-005: the option
 *    namespace, `woodev_location_*`, does not move with the section).
 * 3. `default_locality_policy` / `default_locality_record` / `default_locality_needs_repick`
 *    (Task 14; spec D11) — the store-level default-locality policy (`off` |
 *    `fixed` | `geoip`, `geoip` OPTIONS-gated by the active provider's `locate`
 *    capability the same way the field-mode axes are gated by `list`), the
 *    merchant-picked FIXED record (JSON, written by {@see Location_Provider_Registry::set_default_locality_record()},
 *    never typed free-hand), and the informational "needs re-picking" flag.
 * 4. EVERY registered provider's own fields, declared via
 *    {@see \Woodev\Framework\Shipping\Location\Location_Provider::get_settings_fields()} —
 *    merged in verbatim, keyed by the provider's own field ids, each already
 *    carrying a `show_if` condition (ADR-008) computed by the caller
 *    ({@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::collect_all_provider_fields()}).
 *    This CHANGED (#375/#377, "dynamic, without saving"): previously only the
 *    ACTIVE provider's fields were merged in at all, so switching the
 *    `active_provider` select only changed the visible key fields after a
 *    save. Now every provider's fields are always registered and the CLIENT
 *    shows/hides them per `show_if` — this handler itself does not know or
 *    care which provider is "active"; it only renders whatever fields (and
 *    conditions) it was handed.
 *
 * This handler is deliberately data-only: it does not know how to collect
 * providers or resolve which one is active — {@see Location_Provider_Registry}
 * computes both and hands them to the constructor, keeping this class a plain,
 * unit-testable `Woodev_Abstract_Settings` definition (the same shape as
 * `Woodev_Test_Settings` in the test fixtures).
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Location_Settings' ) ) :

	/**
	 * Store-level settings handler for the Location Provider layer.
	 *
	 * @since 2.0.2
	 */
	class Location_Settings extends \Woodev_Abstract_Settings {

		/**
		 * Registered provider options for the `active_provider` select, `id => name`.
		 *
		 * @since 2.0.2
		 * @var array<string, string>
		 */
		private array $provider_options;

		/**
		 * Offered `field_mode_region` select options, `id => label` (Task 13;
		 * spec D7), already gated by the active provider's capabilities —
		 * resolved by the caller
		 * ({@see Location_Provider_Registry::offered_field_mode_options()}),
		 * exactly like {@see self::$provider_options} is.
		 *
		 * @since 2.0.2
		 * @var array<string, string>
		 */
		private array $field_mode_region_options;

		/**
		 * Offered `field_mode_settlement` select options, `id => label` (Task
		 * 13; spec D7) — a SEPARATE map from {@see self::$field_mode_region_options}
		 * since issue #404: the settlement axis's `related-list` option is
		 * additionally gated on the region axis's own effective mode, so the
		 * two axes no longer always offer the same values.
		 *
		 * @since 2.0.2
		 * @var array<string, string>
		 */
		private array $field_mode_settlement_options;

		/**
		 * The active provider's declared settings fields (Location_Provider::get_settings_fields()
		 * shape), or `[]` when no provider is active. Set BEFORE the parent
		 * constructor runs, since `register_settings()` (called from it) reads it.
		 *
		 * @since 2.0.2
		 * @var array<string, array<string, mixed>>
		 */
		private array $provider_fields;

		/**
		 * Offered `default_locality_policy` select options, `id => label` (Task 14;
		 * spec D11), already gated by the active provider's `locate` capability —
		 * resolved by the caller
		 * ({@see Location_Provider_Registry::offered_default_locality_policy_options_for()}),
		 * exactly like {@see self::$field_mode_region_options} is.
		 *
		 * @since 2.0.2
		 * @var array<string, string>
		 */
		private array $default_locality_policy_options;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the `$field_mode_options` parameter (Task 13; spec D7).
		 * @since 2.0.2 Added the `$default_locality_policy_options` parameter
		 *              (Task 14; spec D11).
		 * @since 2.0.2 `$field_mode_options` split into `$field_mode_region_options`
		 *              and `$field_mode_settlement_options` (issue #404) — the two
		 *              axes no longer always offer the same values.
		 *
		 * @param string                              $id                              settings id (the option-name namespace).
		 * @param array<string, string>               $provider_options                registered provider `id => name` pairs.
		 * @param array<string, array<string, mixed>> $provider_fields                 EVERY registered provider's declared
		 *                                                                               settings fields, each already carrying
		 *                                                                               a `show_if` condition (#375/#377),
		 *                                                                               resolved by the caller.
		 * @param array<string, string>               $field_mode_region_options       offered `field_mode_region` select
		 *                                                                               options (`id => label`), already
		 *                                                                               gated by the active provider's
		 *                                                                               capabilities.
		 * @param array<string, string>               $field_mode_settlement_options   offered `field_mode_settlement`
		 *                                                                               select options (`id => label`),
		 *                                                                               already gated by the active
		 *                                                                               provider's capabilities AND the
		 *                                                                               region axis's own effective mode
		 *                                                                               (issue #404).
		 * @param array<string, string>               $default_locality_policy_options offered `default_locality_policy`
		 *                                                                               select options (`id => label`),
		 *                                                                               already gated by the active
		 *                                                                               provider's `locate` capability.
		 */
		public function __construct(
			string $id,
			array $provider_options,
			array $provider_fields = [],
			array $field_mode_region_options = [],
			array $field_mode_settlement_options = [],
			array $default_locality_policy_options = []
		) {
			$this->provider_options                = $provider_options;
			$this->provider_fields                 = $provider_fields;
			$this->field_mode_region_options       = $field_mode_region_options;
			$this->field_mode_settlement_options   = $field_mode_settlement_options;
			$this->default_locality_policy_options = $default_locality_policy_options;

			parent::__construct( $id );
		}

		/**
		 * Gets the settings ids this handler owns THAT STILL LIVE ON THE
		 * «Локация» SECTION, in registration order — the active-provider
		 * select, the three default-locality settings (Task 14), then EVERY
		 * registered provider's own fields (#375/#377 — not only the active
		 * one's, see this class's own docblock), in provider-registration
		 * order. Used by {@see Location_Provider_Registry} to build the
		 * «Локация» `Settings_Section` without duplicating this handler's own
		 * field list.
		 *
		 * The two field-mode axes and `address_suggentions` are DELIBERATELY
		 * excluded (issue #380): the operator moved «Тип поля Регион», «Тип
		 * поля НП» and «Подсказки для адреса» to the «Поля» section — this
		 * handler still REGISTERS and OWNS those settings (option name
		 * namespace stays `woodev_location_*`, ADR-005), it just no longer
		 * reports them here. `Shipping_Settings_Tab::build_sections()` lists
		 * them explicitly, interleaved with the `Checkout_Field_Settings`-owned
		 * ids they sit next to, since section membership is a display
		 * concern `Composite_Settings_Handler` already resolves independently
		 * of which child handler owns a setting id.
		 *
		 * `default_locality_needs_repick` is ALSO deliberately excluded now
		 * (issue #376, closing #370 variant 2): it is code-owned bookkeeping,
		 * never a merchant decision, and stays registered/writable — it just
		 * never renders. Its live equivalent already surfaces through the
		 * `default_locality_policy` field's own description, see
		 * {@see Location_Provider_Registry::apply_default_locality_status_note()}.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the three `default_locality_*` settings (Task 14; spec D11).
		 * @since 2.0.2 `default_locality_needs_repick` removed from this list
		 *              (issue #376/#370) — it never had a control and a
		 *              merchant has nothing to see or do with it here.
		 * @since 2.0.2 Added `address_suggestions`, right after `field_mode`
		 *              (Task 10; issue #362; design S3/§3.1).
		 * @since 2.0.2 Field-mode axes and `address_suggestions` moved out of
		 *              this list — they now display in «Поля», not «Локация»
		 *              (issue #380).
		 *
		 * @return string[]
		 */
		public function get_owned_setting_ids(): array {
			return array_merge(
				[
					Location_Provider_Registry::SETTING_ACTIVE_PROVIDER,
					Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_POLICY,
					Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_RECORD,
				],
				array_keys( $this->provider_fields )
			);
		}

		protected function register_settings() {

			$this->register_setting(
				Location_Provider_Registry::SETTING_ACTIVE_PROVIDER,
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Провайдер локаций', 'woodev-plugin-framework' ),
					'options' => $this->provider_options,
					'default' => Location_Provider_Registry::DEFAULT_PROVIDER_ID,
				]
			);
			$this->register_control(
				Location_Provider_Registry::SETTING_ACTIVE_PROVIDER,
				\Woodev_Control::TYPE_SELECT,
				[
					'tooltip' => __( 'От выбранного провайдера зависит, откуда на чекауте берутся подсказки городов и адресов, и какие типы полей ниже вообще доступны — не каждый провайдер умеет отдавать готовый список для локального выбора.', 'woodev-plugin-framework' ),
				]
			);

			// Issue #380: the single `field_mode` setting became two axes — the
			// НП/Регион field's INPUT TYPE is independent per level (the operator
			// found a case one shared value could not express: НП = `ajax-select2`
			// while region stays `typeahead`, or the reverse).
			//
			// Issue #404: the two axes no longer always offer the SAME values —
			// `$this->field_mode_region_options` and
			// `$this->field_mode_settlement_options` are two SEPARATE maps, the
			// caller has already narrowed the settlement one to drop
			// `related-list` when the region axis is not itself `related-list`
			// (a bulk list of every settlement in a country does not exist).
			//
			// `field_mode_region` alone carries a `show_if` on `region_field`
			// (owned by the sibling `Checkout_Field_Settings` handler — resolved
			// across handlers by `Composite_Settings_Handler`): once the region
			// field is removed, this axis has nothing left to control and the
			// control is hidden. The actual issue #369 enforcement is a
			// read-side clamp in `Location_Provider_Registry::get_field_mode_region()`,
			// never this `show_if` alone (design §7 — `show_if` only hides the
			// control; `Composite_Settings_Handler::filter_visible_values()`
			// splits a submission by owning child BEFORE evaluating conditions,
			// so a cross-handler condition has no server-side enforcement power
			// of its own). The settlement axis's issue #404 condition is
			// enforced the SAME way — narrowed OFFERED options plus a read-side
			// clamp in `Location_Provider_Registry::get_field_mode_settlement()`
			// — even though both axes live in THIS handler and a same-handler
			// `show_if` would carry real server-side enforcement here; one
			// mechanism per concern beats two that must agree.
			$this->register_setting(
				Location_Provider_Registry::SETTING_FIELD_MODE_REGION,
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Тип поля Регион', 'woodev-plugin-framework' ),
					'options' => $this->field_mode_region_options,
					'default' => Location_Provider_Registry::MODE_TYPEAHEAD,
					'show_if' => [
						'setting' => 'region_field',
						'value'   => 'show',
					],
				]
			);
			$this->register_control(
				Location_Provider_Registry::SETTING_FIELD_MODE_REGION,
				\Woodev_Control::TYPE_SELECT,
				[
					// Issue #373: a select's tooltip must explain the DIFFERENCE between
					// values, not restate the label — "предустановленный список" and
					// "список с поиском" look similar but one never talks to the server
					// after the initial load and the other queries it on every keystroke.
					'tooltip' => __( 'Как покупатель выбирает регион. «Текст с подсказками» — обычное поле, варианты появляются по мере ввода. «Предустановленный список» — весь список регионов загружается один раз, дальнейший поиск идёт в браузере без обращений к серверу. «Список с поиском» — раскрывающийся список, который запрашивает варианты у провайдера на каждый введённый символ.', 'woodev-plugin-framework' ),
				]
			);

			$this->register_setting(
				Location_Provider_Registry::SETTING_FIELD_MODE_SETTLEMENT,
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Тип поля НП', 'woodev-plugin-framework' ),
					'options' => $this->field_mode_settlement_options,
					'default' => Location_Provider_Registry::MODE_TYPEAHEAD,
				]
			);
			$this->register_control(
				Location_Provider_Registry::SETTING_FIELD_MODE_SETTLEMENT,
				\Woodev_Control::TYPE_SELECT,
				[
					// Issue #404: «Предустановленный список» is offered here only when
					// the region axis is ALSO «Предустановленный список» — a bulk list
					// of every settlement in a country does not exist, only a
					// per-region one. Issue #407: no BLOCKING mechanism exists or is
					// planned — the field stays usable throughout, it just narrows to
					// the chosen region once one is picked.
					'tooltip' => __( 'Как покупатель выбирает населённый пункт — те же три варианта, что и у поля «Регион» (см. подсказку там). «Предустановленный список» доступен здесь, только если для «Региона» тоже выбран «Предустановленный список» — единого списка населённых пунктов по всей стране не существует, только по региону. Пока регион не выбран, список показывает пункты по всей стране; после выбора региона сужается до него — поле при этом остаётся доступным, блокировки нет.', 'woodev-plugin-framework' ),
				]
			);

			/*
			 * `address_suggestions` (Task 10; issue #362; design S3/§3.1/§3.2):
			 * whether the location layer serves the `address` suggest level at
			 * all. No OPTIONS-gating like the two axes above: this is a plain
			 * boolean, and its AVAILABILITY (rather than its offered values) is
			 * what varies — that is expressed as a DISABLED control, applied by
			 * Location_Provider_Registry::register_settings() once this handler
			 * exists (the same "resolved by the caller, this handler only
			 * renders it" discipline this class's own docblock already
			 * describes for `provider_options`/`field_mode_region_options`).
			 */
			$this->register_setting(
				Location_Provider_Registry::SETTING_ADDRESS_SUGGESTIONS,
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name'    => __( 'Подсказки для адреса', 'woodev-plugin-framework' ),
					'default' => true,
				]
			);
			$this->register_control(
				Location_Provider_Registry::SETTING_ADDRESS_SUGGESTIONS,
				\Woodev_Control::TYPE_CHECKBOX,
				[
					'tooltip' => __( 'Показывать подсказки при вводе улицы и дома на чекауте. Работает только пока подключённый провайдер умеет отдавать адреса — если нет, поле становится недоступным, и причина написана под ним.', 'woodev-plugin-framework' ),
				]
			);

			/*
			 * `default_locality_policy`'s own `description` is left EMPTY here on
			 * purpose (issue #373 does not add one) — {@see Location_Provider_Registry::apply_default_locality_status_note()}
			 * writes a LIVE status note directly into this same `Woodev_Setting`'s
			 * description AFTER `register_settings()` finishes, and that write only
			 * has any visible effect while the CONTROL's own snapshot of the
			 * description (taken by `register_control()` below, at THIS point in
			 * time) is itself empty — {@see \Woodev\Framework\Settings\Field_Schema::from_handler()}
			 * prefers a non-empty `$control->get_description()` over the setting's,
			 * so a static description registered here would permanently shadow the
			 * dynamic status note. The value-difference copy therefore lives in the
			 * TOOLTIP instead, which nothing overwrites later.
			 */
			$this->register_setting(
				Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_POLICY,
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Локация по умолчанию', 'woodev-plugin-framework' ),
					'options' => $this->default_locality_policy_options,
					'default' => Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_OFF,
				]
			);
			$this->register_control(
				Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_POLICY,
				\Woodev_Control::TYPE_SELECT,
				[
					'tooltip' => __( 'Что подставляется в поля региона и города до того, как покупатель начал сам вводить адрес. «Отключено» — ничего не подставляется. «Фиксированная локация» — всегда один и тот же город, выбранный вами ниже. «По IP-адресу покупателя» — город определяется по IP каждого покупателя; доступно только пока провайдер умеет геолоцировать.', 'woodev-plugin-framework' ),
				]
			);

			/*
			 * Serialized Location_Record JSON (Task 14), written through
			 * Location_Provider_Registry::set_default_locality_record() — the
			 * admin picker's own selection, never free text the merchant types
			 * by hand.
			 *
			 * Issue #376 (closing #370): a real `TYPE_LOCATION_PICKER` control
			 * now backs this setting (review finding F4's original "no control"
			 * withholding is gone — a picker exists now, see
			 * `location-picker-field.js`) and is shown ONLY while the sibling
			 * `default_locality_policy` field is `fixed` (`show_if`, ADR-008;
			 * same handler, so this condition gets full server-side enforcement
			 * via `filter_visible_values()`, unlike the CROSS-handler conditions
			 * documented on `field_mode_region` above). The control's `country`
			 * arg is resolved ONCE here, at registration time, through
			 * {@see Location_Service::resolve_default_country()} — steps 2+3 of
			 * the store-country cascade (step 1, a live checkout field, does not
			 * exist on an admin screen) — never a second, hand-rolled fallback
			 * on the client.
			 */
			$this->register_setting(
				Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_RECORD,
				\Woodev_Setting::TYPE_STRING,
				[
					'name'    => __( 'Зафиксированная локация', 'woodev-plugin-framework' ),
					'default' => '',
					'show_if' => [
						'setting' => Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_POLICY,
						'value'   => Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED,
					],
				]
			);
			$this->register_control(
				Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_RECORD,
				\Woodev_Control::TYPE_LOCATION_PICKER,
				[
					'country' => ( new Location_Service() )->resolve_default_country(),
					'tooltip' => __( 'Город, который подставляется покупателю по умолчанию при политике «Фиксированная локация». Выбирается через поиск и сохраняется как готовая запись — вписывать его вручную не нужно.', 'woodev-plugin-framework' ),
				]
			);

			/*
			 * Informational flag slot — CODE-OWNED bookkeeping, never a
			 * merchant decision, hence registered WITHOUT a control (review
			 * finding F4): an editable toggle would let a merchant silently
			 * switch it off and mask a genuinely stranded default. Issue
			 * #376/#370 (variant 2) additionally drops it from
			 * {@see self::get_owned_setting_ids()} entirely — it stays
			 * registered and writable through the generic
			 * {@see \Woodev_Abstract_Settings} accessors, it simply never
			 * renders, at any policy. The settings page instead surfaces the
			 * live equivalent of this flag through the
			 * `default_locality_policy` field's own description — see
			 * {@see Location_Provider_Registry::apply_default_locality_status_note()}.
			 * Issue #406 removed the typed `Location_Provider_Registry`
			 * getter/setter pair this slot used to have (dead: their one
			 * historical write site was deliberately excised by review
			 * finding F2, and the live status note above already covers the
			 * same condition unconditionally) — the WP option itself is left
			 * in place rather than migrated away.
			 *
			 * No control means no tooltip either (issue #373's own rule: a
			 * tooltip can only live on a control) — nothing to fill in here.
			 */
			$this->register_setting(
				Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_NEEDS_REPICK,
				\Woodev_Setting::TYPE_BOOLEAN,
				[
					'name'    => __( 'Зафиксированная локация требует повторного выбора', 'woodev-plugin-framework' ),
					'default' => false,
				]
			);

			foreach ( $this->provider_fields as $field_id => $field ) {
				$this->register_provider_field( (string) $field_id, (array) $field );
			}
		}

		/**
		 * Adds one cross-field check on top of {@see \Woodev_Abstract_Settings::validate_values()}'s
		 * own per-field pass (issue #406): the FIXED default-locality record's
		 * own baked-in `provider_id` (set at pick time by the admin picker,
		 * never merchant-typed) must match the ACTIVE provider this SAME
		 * submission resolves to — otherwise saving would silently keep a
		 * record {@see Location_Service::resolve_default()} can never
		 * resolve through the new provider, and the `fixed` policy would
		 * stop working with no signal at all. The operator explicitly
		 * REJECTED both "clear the field on select change" and "clear
		 * server-side + set needs_repick on save" as data loss on a
		 * reversible action; blocking the save instead achieves the same
		 * result — a broken record can never be persisted — without
		 * destroying anything.
		 *
		 * `$values` is this handler's OWN chunk of the tab-wide submission —
		 * both `active_provider` and `default_locality_record` are
		 * registered on THIS handler ({@see self::register_settings()}), so
		 * a provider switch staged in the SAME save is visible in the SAME
		 * map this check reads, never a stale one:
		 *
		 * - switch the provider back to the one the record was picked under
		 *   -> the two ids match again -> no error -> save unblocked;
		 * - switch `default_locality_policy` to `off` in the same save ->
		 *   `default_locality_record` is hidden by its own `show_if` ->
		 *   {@see \Woodev_Abstract_Settings::filter_visible_values()} (run by
		 *   every caller BEFORE `validate_values()`) already strips it from
		 *   `$values` -> the key is simply absent here -> nothing to block.
		 *
		 * Both cases need no special-casing in this method — the rule lifts
		 * itself as a consequence of reading the SAME live map the ordinary
		 * per-field pass already does, not a snapshot taken at page load.
		 *
		 * A malformed/undecodable record degrades to "nothing to check"
		 * rather than a NEW validation error — the same never-throws-on-a-
		 * corrupt-blob discipline {@see Location_Provider_Registry::get_default_locality_record()}
		 * already applies; an empty record likewise has nothing to compare.
		 *
		 * @since 2.0.3
		 *
		 * @param array<string,mixed> $values submitted setting_id => value (this handler's own chunk).
		 *
		 * @return array<string,string> setting_id => error message.
		 */
		public function validate_values( array $values ): array {

			$errors    = parent::validate_values( $values );
			$record_id = Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_RECORD;

			if ( ! array_key_exists( $record_id, $values ) || isset( $errors[ $record_id ] ) ) {
				return $errors;
			}

			$raw = $values[ $record_id ];

			if ( ! is_string( $raw ) || '' === $raw ) {
				return $errors;
			}

			$decoded = json_decode( $raw, true );

			if ( ! is_array( $decoded ) ) {
				return $errors;
			}

			try {
				$record = Location_Record::from_array( $decoded );
			} catch ( \InvalidArgumentException $exception ) {
				return $errors;
			}

			$provider_setting_id = Location_Provider_Registry::SETTING_ACTIVE_PROVIDER;
			$active_provider_id  = array_key_exists( $provider_setting_id, $values )
				? (string) $values[ $provider_setting_id ]
				: (string) $this->get_value( $provider_setting_id );

			if ( $record->provider_id() !== $active_provider_id ) {
				$errors[ $record_id ] = __( 'Зафиксированная локация выбрана для другого провайдера — выберите её заново или верните прежнего провайдера.', 'woodev-plugin-framework' );
			}

			return $errors;
		}

		private function register_provider_field( string $field_id, array $field ): void {
			$type = $field['type'] ?? \Woodev_Setting::TYPE_STRING;
			unset( $field['type'] );

			// Issue #373: a provider declares its OWN field copy (name/description/
			// tooltip) right alongside the field itself — that is where a plugin
			// author would naturally put it. `tooltip` is pulled out here because it
			// is a CONTROL arg (`register_control()`), not a setting arg
			// (`register_setting()` below has no use for it and would silently
			// ignore it if left in `$field`).
			$tooltip = (string) ( $field['tooltip'] ?? '' );
			unset( $field['tooltip'] );

			$this->register_setting( $field_id, $type, $field );

			if ( ! empty( $field['options'] ) ) {
				$control_type = \Woodev_Control::TYPE_SELECT;
			} elseif ( ! empty( $field['sensitive'] ) ) {
				$control_type = \Woodev_Control::TYPE_PASSWORD;
			} else {
				$control_type = \Woodev_Control::TYPE_TEXT;
			}

			$control_args = [];
			if ( '' !== $tooltip ) {
				$control_args['tooltip'] = $tooltip;
			}

			$this->register_control( $field_id, $control_type, $control_args );
		}
	}

endif;
