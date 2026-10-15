---
title: Setup wizard
description: Guide a merchant through first-time configuration with a step-by-step wizard.
---

In this page you will learn how to give your plugin a first-run setup wizard: how to turn it on, how to add settings steps and content steps, how to validate and branch, and how to plug in a step of your own.

## What the wizard is

The wizard is a full-screen, step-by-step page that walks a merchant through the settings a plugin needs before it can work. It is **opt-in**: a plugin has no wizard until it returns one.

The framework handles the rest:

- **First install.** The first time the plugin is installed, a user with the wizard's required capability (an administrator, or a shop manager for a WooCommerce wizard) is sent to the wizard once. Bulk activation does not redirect.
- **Until it is finished.** A notice «Завершите настройку My Carrier» (Finish setting up My Carrier) with a «Запустить мастер настройки» (Run the setup wizard) button stays in wp-admin, and the plugin row on the Plugins screen carries a «Настройка» (Setup) link.
- **The merchant can leave.** «Настрою позже» (I'll do it later) marks the wizard as skipped. The notice stays until the merchant reaches the last step.

To ask where a merchant stands, call `$plugin->get_setup_wizard_handler()` and then `is_complete()`, `is_skipped()` or `is_finished()`; `get_setup_url()` returns the address of the wizard.

The wizard reads and saves the same settings as the settings page, so a rule you declare once (required, format, `show_if`) holds in both places. See [Typed settings](../typed-settings/).

## Turn it on

Two pieces: a wizard class, and an override in your main plugin class.

A wizard class extends `\Woodev\Framework\Setup\Woocommerce_Setup_Wizard` for a WooCommerce plugin, or `\Woodev\Framework\Setup\Setup_Wizard` for a plain WordPress plugin. Its one required method is `register_steps()`.

Then your plugin class returns an instance from `build_setup_wizard_handler()`:

```php
<?php
defined( 'ABSPATH' ) || exit;

final class My_Carrier_Plugin extends \Woodev\Framework\Woocommerce_Plugin {

	// ...the constructor, instance(), get_file() and the rest from "Your first plugin"...

	/**
	 * Opts the plugin into the setup wizard.
	 *
	 * @return \Woodev\Framework\Setup\Setup_Wizard
	 */
	protected function build_setup_wizard_handler() {
		return new My_Carrier_Setup_Wizard( $this );
	}
}
```

The default returns `null`, which means no wizard. The wizard wires its own hooks when it is built, so there is nothing else to register.

| Base class | Who can open it | Hooks |
|---|---|---|
| `Setup_Wizard` | users with `manage_options` | wired when the wizard has at least one step |
| `Woocommerce_Setup_Wizard` | users with `manage_woocommerce` | wired when it has a step and WooCommerce is active |

:::caution
The wizard is built early, in the plugin constructor, so `register_steps()` runs before WordPress and WooCommerce are fully ready. Do not read settings or call WooCommerce functions directly inside it. Put that code in the callbacks you pass to a step; they run later, when the wizard page is drawn or a step is saved.
:::

## Settings steps and content steps

There are two kinds of step.

A **settings step** shows fields. It lists the ids of settings from your plugin's settings handler, and the wizard draws the matching controls. `register_step()` takes:

| Argument | Meaning |
|---|---|
| `$id` | A short id: letters, digits, `_` and `-`. It is part of a URL, and `finish` is reserved for the last screen. |
| `$label` | The step title. |
| `$setting_ids` | The setting ids to show, in order. |
| `$on_save` | Optional. A callable that runs **after** the step's settings are saved. |
| `$description` | Optional. One line under the title. |

A **content step** shows text or markup and no fields. `register_content_step()` takes an id, a label, the content (a string, or a callable that returns one) and an optional description. The content is trusted HTML, so escape anything that comes from outside.

Both methods return the `Step`, so you can configure it by chaining.

:::note
A settings step takes its fields from the plugin's `get_settings_handler()`. A plugin without one cannot use settings steps; content steps still work.
:::

### Keep `on_save` repeatable

The merchant can press «Продолжить» (Continue) again after a failure, so `$on_save` may run more than once for the same step. Write it so a second run does no harm. If it throws, the merchant sees a generic error, the details go to the log, and the settings stay saved.

## Configure a step

Each of these methods returns the `Step`, so they chain.

| Method | What it does |
|---|---|
| `set_short_label( $label )` | A shorter title for the progress bar. With many steps the full titles do not fit; the step card keeps the full label. |
| `set_skippable( false )` | Removes the «Пропустить» (Skip) control. Steps are skippable by default. |
| `set_visibility_callback( $callable )` | Shows the step only while the callable returns `true`. It reads **saved** state and takes no arguments, and it is checked again after every save. |
| `set_validation_callback( $callable )` | Checks the values before anything is saved. See below. |
| `add_action( $action )` | Adds a button that runs code on the server. See below. |
| `set_component( $handle, $export )` | Replaces the step body with your own React component. See [A custom step](#a-custom-step). |

A step whose visibility callback throws is hidden and the error is logged, so a bug there never blanks the wizard.

### Validate before saving

`set_validation_callback()` receives `array $values` and the `\WP_REST_Request`. The values are what the step currently shows: the merchant's edits over the stored values, without fields hidden by their `show_if`. Return:

- an array of `field id => message` to refuse the save and show each message on its field;
- `false` to refuse with a generic message;
- `true`, `null` or an empty array to accept.

When the save is refused, nothing is written and `on_save` does not run.

### Run an action

An action is a button that runs code on the server without saving the form, for example «Проверить ключ» (Check the key). Build it with `Step_Action::create( $id, $label, $callback, $destructive, $confirm_message )`. The callback receives the step's unsaved `array $values` and the request, and returns an `Action_Outcome`:

- `Action_Outcome::success( $message )` and `Action_Outcome::error( $message )` give the merchant a result they read. Use `error()` for an expected "no", such as "this key is not valid". Anything the callback throws is logged and shown as a generic error.
- `->discarding_edits()` tells the form to drop what the merchant typed. Use it on an action that resets values; never on a read-only check.
- `$destructive = true` makes the wizard ask the merchant to confirm first. `$confirm_message` is the question.

## Finish the wizard

After the last step, the wizard shows a finish screen. You shape it with two overrides on your wizard class. Both are `protected`.

`get_finish_actions()` returns "what next" cards. Each card is an array with `heading`, `title`, `description`, `actionLabel` and `url`. The default offers a documentation card when the plugin has a documentation URL.

`get_header_image_url()` returns the URL of a logo shown in the header. The default is an empty string, which shows no image.

The finish screen also shows small links to the plugin's settings and to its reviews page, when the plugin provides those URLs.

## A complete example

This wizard has three steps: a welcome page, a connection step that checks the key before it saves and offers a «Проверить ключ» button, and an options step that appears only in live mode. The merchant-facing text is Russian, as it is in the plugin itself.

First the settings that the steps use. A settings handler registers each setting once and says how it is drawn.

`includes/class-my-carrier-settings.php`

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * The My Carrier settings.
 */
final class My_Carrier_Settings extends \Woodev_Abstract_Settings {

	/**
	 * Registers the settings and how they are drawn.
	 *
	 * @return void
	 */
	protected function register_settings() {
		$this->register_setting(
			'mode',
			\Woodev_Setting::TYPE_STRING,
			[
				'name'    => 'Режим',
				'options' => [
					'test' => 'Тестовый',
					'live' => 'Рабочий',
				],
				'default' => 'test',
			]
		);
		$this->register_control( 'mode', \Woodev_Control::TYPE_SELECT );

		$this->register_setting(
			'api_key',
			\Woodev_Setting::TYPE_STRING,
			[
				'name'      => 'Ключ API',
				'default'   => '',
				'required'  => true,
				'sensitive' => true,
			]
		);
		$this->register_control( 'api_key', \Woodev_Control::TYPE_PASSWORD );

		$this->register_setting(
			'notify',
			\Woodev_Setting::TYPE_BOOLEAN,
			[
				'name'    => 'Уведомлять о статусах',
				'default' => true,
			]
		);
		$this->register_control( 'notify', \Woodev_Control::TYPE_TOGGLE );
	}
}
```

Your plugin returns it from `get_settings_handler()`, keeping one instance:

```php
<?php
defined( 'ABSPATH' ) || exit;

final class My_Carrier_Plugin extends \Woodev\Framework\Woocommerce_Plugin {

	// ...the rest of the class...

	/**
	 * The settings handler, built once.
	 *
	 * @var My_Carrier_Settings|null
	 */
	private $settings_handler;

	/**
	 * Gets the settings handler.
	 *
	 * @return My_Carrier_Settings
	 */
	public function get_settings_handler() {
		if ( null === $this->settings_handler ) {
			$this->settings_handler = new My_Carrier_Settings( $this->get_id() );
		}

		return $this->settings_handler;
	}

	/**
	 * Opts the plugin into the setup wizard.
	 *
	 * @return \Woodev\Framework\Setup\Setup_Wizard
	 */
	protected function build_setup_wizard_handler() {
		return new My_Carrier_Setup_Wizard( $this );
	}
}
```

Now the wizard.

`includes/class-my-carrier-setup-wizard.php`

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Setup\Action_Outcome;
use Woodev\Framework\Setup\Step_Action;
use Woodev\Framework\Setup\Woocommerce_Setup_Wizard;

/**
 * The My Carrier first-run wizard.
 */
final class My_Carrier_Setup_Wizard extends Woocommerce_Setup_Wizard {

	/**
	 * Registers the steps.
	 *
	 * @return void
	 */
	protected function register_steps(): void {
		// Resolved inside the callbacks: the wizard is built early in the plugin's boot.
		$plugin = $this->plugin;

		$this->register_content_step(
			'welcome',
			'Добро пожаловать',
			static function (): string {
				return '<p>' . esc_html__( 'Нужно ввести ключ API. Это займёт минуту.', 'my-carrier' ) . '</p>';
			}
		)->set_short_label( 'Начало' );

		$this->register_step(
			'connection',
			'Подключение к перевозчику',
			[ 'mode', 'api_key' ],
			null,
			'Ключ рабочего режима начинается с live_.'
		)->set_short_label( 'Ключ' )->set_validation_callback(
			static function ( array $values ): array {
				$mode = (string) ( $values['mode'] ?? 'test' );
				$key  = (string) ( $values['api_key'] ?? '' );

				if ( 'live' === $mode && 0 !== strpos( $key, 'live_' ) ) {
					return [ 'api_key' => 'Ключ рабочего режима начинается с live_.' ];
				}

				return [];
			}
		)->add_action(
			Step_Action::create(
				'check-key',
				'Проверить ключ',
				static function ( array $values ): Action_Outcome {
					$key = (string) ( $values['api_key'] ?? '' );

					// Call the carrier here. This sketch only checks the shape.
					return '' !== $key
						? Action_Outcome::success( 'Ключ принят.' )
						: Action_Outcome::error( 'Введите ключ.' );
				}
			)
		);

		$this->register_step(
			'options',
			'Уведомления',
			[ 'notify' ],
			static function (): void {
				// Runs after the step is saved, and may run again: keep it repeatable.
				delete_transient( 'my_carrier_status_cache' );
			}
		)->set_visibility_callback(
			static function () use ( $plugin ): bool {
				return 'live' === $plugin->get_settings_handler()->get_value( 'mode' );
			}
		);
	}

	/**
	 * Adds a "what next" card to the finish screen.
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function get_finish_actions(): array {
		return [
			[
				'heading'     => 'Дальше',
				'title'       => 'Добавьте способ доставки',
				'description' => 'Включите доставку My Carrier в зоне доставки.',
				'actionLabel' => 'Открыть зоны доставки',
				'url'         => admin_url( 'admin.php?page=wc-settings&tab=shipping' ),
			],
		];
	}

	/**
	 * Gets the logo shown in the wizard header.
	 *
	 * @return string
	 */
	protected function get_header_image_url(): string {
		return $this->plugin->get_plugin_url() . '/assets/logo.svg';
	}
}
```

Notice that the `options` step is hidden while the saved mode is `test`. Visibility reads the saved value, so it changes after the merchant saves the `connection` step, not while they type.

## Change steps from outside

Another plugin, or your own code, can add, reorder or remove steps with the `woodev_{id}_setup_wizard_steps` filter. `{id}` is the plugin id, so for `my-carrier` the hook is `woodev_my-carrier_setup_wizard_steps`. The filter receives the registered steps as an array keyed by step id, and the wizard object. Return an array of `\Woodev\Framework\Setup\Step` objects; a value that is not an array is ignored and entries that are not steps are dropped.

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Removes the welcome step from the My Carrier wizard.
 *
 * @param array<string,\Woodev\Framework\Setup\Step> $steps The registered steps, keyed by id.
 * @return array<string,\Woodev\Framework\Setup\Step>
 */
function my_carrier_trim_wizard_steps( $steps ) {
	unset( $steps['welcome'] );

	return $steps;
}
add_filter( 'woodev_my-carrier_setup_wizard_steps', 'my_carrier_trim_wizard_steps' );
```

## A custom step

When a step needs more than fields or text, you can ship a small React component and let the wizard draw it inside the standard frame: title, description, error banner and the «Назад» (Back), «Пропустить» (Skip) and «Продолжить» (Continue) buttons.

1. Register a script with `wp_register_script()` **before** the wizard page is drawn. Register it on `admin_init` at priority 9 or lower. Give it `wp-element` and `wp-components` as dependencies.
2. Declare the step with `set_component( $handle, 'ExportName' )`.
3. In the script, publish the component under that handle and name.

`includes/class-my-carrier-setup-wizard.php`, the step and the script registration:

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Setup\Action_Outcome;
use Woodev\Framework\Setup\Step_Action;
use Woodev\Framework\Setup\Woocommerce_Setup_Wizard;

/**
 * A wizard with a custom review step.
 */
final class My_Carrier_Review_Wizard extends Woocommerce_Setup_Wizard {

	/**
	 * Registers the steps.
	 *
	 * @return void
	 */
	protected function register_steps(): void {
		$this->register_content_step( 'review', 'Проверка', '', 'Проверьте связь с перевозчиком.' )
			->set_skippable( false )
			->set_component( 'my-carrier-wizard-review', 'ReviewStep' )
			->add_action(
				Step_Action::create(
					'ping',
					'Проверить связь',
					static function (): Action_Outcome {
						return Action_Outcome::success( 'Связь с перевозчиком в порядке.' );
					}
				)
			);

		add_action( 'admin_init', [ $this, 'register_component_script' ], 5 );
	}

	/**
	 * Registers the script that publishes the step component.
	 *
	 * @return void
	 */
	public function register_component_script(): void {
		wp_register_script(
			'my-carrier-wizard-review',
			$this->plugin->get_plugin_url() . '/assets/wizard-review-step.js',
			[ 'wp-element', 'wp-components' ],
			$this->plugin->get_version(),
			true
		);
	}
}
```

`assets/wizard-review-step.js` needs no build step:

```js
( function ( wp, window ) {
	var el = wp.element.createElement;
	var useState = wp.element.useState;

	function ReviewStep( props ) {
		var state = useState( '' );
		var message = state[ 0 ];
		var setMessage = state[ 1 ];

		function ping() {
			props.runAction( 'ping' ).then( function ( answer ) {
				setMessage( answer.message );
			} );
		}

		return el(
			'div',
			null,
			el( 'p', null, 'Нажмите кнопку, чтобы проверить связь.' ),
			el(
				wp.components.Button,
				{ variant: 'secondary', onClick: ping, disabled: props.busy },
				'Проверить связь'
			),
			message ? el( 'p', { role: 'status' }, message ) : null
		);
	}

	window.woodevSetupWizardComponents = window.woodevSetupWizardComponents || {};
	window.woodevSetupWizardComponents[ 'my-carrier-wizard-review' ] = { ReviewStep: ReviewStep };
}( window.wp, window ) );
```

The component receives these props:

| Prop | What it is |
|---|---|
| `step` | The step descriptor: label, fields with their saved values, actions. |
| `values` | What the merchant changed on this step, as `field id => value`. Only edited fields are listed. |
| `onChange( values )` | Replaces the step's edits. Pass the whole map. |
| `errors`, `showErrors` | Per-field errors from the last refused save, and whether to show client-side errors. |
| `busy` | A save or action is running. |
| `save()` | Validates and saves the edits without leaving the step. Resolves `true` when accepted. |
| `next()` | The standard «Продолжить»: save if needed, then go to the next visible step. |
| `back()` / `skip()` | Go to the previous step, or skip this one. `skip()` does nothing on a step that is not skippable. |
| `runAction( id )` | Runs one of the step's actions. Resolves with `{ status, message, data }`; `status` is `success`, `error` or `cancelled` (the merchant declined the confirmation). |

If the script handle is not registered, or the script does not publish the export, the step shows an error instead of a blank screen, and the cause is written to the PHP error log. If the component throws while drawing, the step shows an error and the rest of the wizard keeps working.

## Where to go next

- [Typed settings](../typed-settings/) shows how to declare the settings that settings steps use.
- [Tabs, sections & cards](../tabs-sections-and-cards/) covers the settings page that shares those settings.
- [Admin notices & messages](../admin-notices-and-messages/) covers the notices that tell a merchant about setup.
