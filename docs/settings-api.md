# Settings API

The Settings API module provides a typed, self-describing settings layer for Woodev-based plugins. It manages getting, setting, validating, and persisting option values with a clean object-oriented interface.

## Overview

The Settings API consists of:

- **`Woodev_Abstract_Settings`** — Base class for settings handlers
- **`Woodev_Setting`** — Single setting descriptor
- **`Woodev_Control`** — UI control descriptor
- **`Woodev_Register_Settings`** — WordPress Settings API registration
- **`Woodev_Register_Settings_Fields`** — Field registration

Settings values are stored in `wp_options` under the pattern:
`woodev_{plugin_id}_{setting_id}`

## Key Classes

| Class | File | Purpose |
| --- | --- | --- |
| `Woodev_Abstract_Settings` | `settings-api/abstract-class-settings.php` | Settings container and manager |
| `Woodev_Setting` | `settings-api/class-setting.php` | Single setting descriptor |
| `Woodev_Control` | `settings-api/class-control.php` | UI control descriptor |
| `Woodev_Register_Settings` | `settings-api/class-register-settings.php` | WP Settings API registration |
| `Woodev_Register_Settings_Fields` | `settings-api/class-register-settings-fields.php` | Field registration |

## Setting Types

| Type | PHP Type | Storage | Notes |
| --- | --- | --- | --- |
| `string` | `string` | Text | Default type |
| `url` | `string` | Text | Validated as URL |
| `email` | `string` | Text | Validated as email |
| `integer` | `int` | Text | Cast on read |
| `float` | `float` | Text | Cast on read |
| `boolean` | `bool` | `yes`/`no` | `'yes'` = true |
| `object` | `mixed` | JSON | JSON-encoded |

## Control Types

Available control types:

- `text` — Text input
- `textarea` — Textarea
- `number` — Number input
- `email` — Email input
- `password` — Password input
- `date` — Date picker
- `checkbox` — Checkbox
- `radio` — Radio buttons
- `select` — Dropdown
- `file` — File upload
- `color` — Color picker
- `range` — Range slider

## Creating a Settings Class

### Basic Example

```php
<?php
class My_Settings extends Woodev_Abstract_Settings {

    /**
     * @var self|null
     */
    private static $instance;

    /**
     * Get settings instance
     */
    public static function instance( Woodev_Plugin $plugin ): self {
        if ( null === self::$instance ) {
            self::$instance = new self( $plugin );
        }
        return self::$instance;
    }

    /**
     * Register settings
     */
    protected function register_settings(): void {
        // API Key setting
        $this->register_setting(
            'api_key',
            'string',
            [
                'name'        => __( 'API Key', 'my-plugin' ),
                'description' => __( 'Your API key from the provider.', 'my-plugin' ),
                'default'     => '',
                'control'     => new Woodev_Control( 'password' ),
            ]
        );

        // Debug Mode setting
        $this->register_setting(
            'debug_mode',
            'boolean',
            [
                'name'        => __( 'Debug Mode', 'my-plugin' ),
                'description' => __( 'Enable debug logging.', 'my-plugin' ),
                'default'     => false,
                'control'     => new Woodev_Control( 'checkbox' ),
            ]
        );

        // Max Weight setting
        $this->register_setting(
            'max_weight',
            'float',
            [
                'name'        => __( 'Maximum Weight (kg)', 'my-plugin' ),
                'description' => __( 'Maximum package weight.', 'my-plugin' ),
                'default'     => 30.0,
                'control'     => new Woodev_Control( 'number', [
                    'min'  => 0,
                    'step' => 0.5,
                ] ),
            ]
        );

        // Environment setting
        $this->register_setting(
            'environment',
            'string',
            [
                'name'        => __( 'Environment', 'my-plugin' ),
                'description' => __( 'Select environment.', 'my-plugin' ),
                'default'     => 'production',
                'control'     => new Woodev_Control( 'select', [
                    'options' => [
                        'production' => __( 'Production', 'my-plugin' ),
                        'sandbox'    => __( 'Sandbox', 'my-plugin' ),
                    ],
                ] ),
            ]
        );
    }
}
```

### Connecting to Plugin

```php
<?php
class My_Plugin extends Woodev_Plugin {

    public function get_settings_handler(): ?Woodev_Abstract_Settings {
        return My_Settings::instance( $this );
    }
}
```

## Reading Settings

### Get Setting Object

```php
<?php
$settings = $plugin->get_settings_handler();

// Get a Woodev_Setting object
$setting = $settings->get_setting( 'api_key' );

// Get the value
$api_key = $setting->get_value();
```

### Get Value Directly

```php
<?php
$settings = $plugin->get_settings_handler();

// Shorthand to get value
$api_key = $settings->get_value( 'api_key' );
$debug   = $settings->get_value( 'debug_mode' );
$weight  = $settings->get_value( 'max_weight' );
```

### Check Default Values

```php
<?php
$setting = $settings->get_setting( 'max_weight' );

// Get default value
$default = $setting->get_default(); // 30.0

// Check if current value equals default
$is_default = $setting->is_default();
```

### Get All Settings

```php
<?php
foreach ( $settings->get_settings() as $id => $setting ) {
    printf(
        "%-20s = %s\n",
        $id,
        var_export( $setting->get_value(), true )
    );
}
```

## Writing Settings

### Update Single Value

```php
<?php
$settings = $plugin->get_settings_handler();

// Update value (persists immediately)
$settings->update_value( 'api_key', 'new-secret-key' );

// Boolean values
$settings->update_value( 'debug_mode', true );
```

### Update Multiple Values

```php
<?php
$settings->update_value( 'api_key', 'key123' );
$settings->update_value( 'debug_mode', true );
$settings->update_value( 'max_weight', 50.0 );
```

### Delete Value

```php
<?php
// Delete setting value (resets to default)
$settings->delete_value( 'api_key' );

// Delete all settings
foreach ( $settings->get_settings() as $id => $setting ) {
    $settings->delete_value( $id );
}
```

### Save Settings

```php
<?php
// Save all pending changes
$settings->save();
```

## Control Configuration

### Text Input

```php
<?php
new Woodev_Control( 'text', [
    'placeholder' => 'Enter value',
    'class'       => 'regular-text',
] );
```

### Number Input

```php
<?php
new Woodev_Control( 'number', [
    'min'       => 0,
    'max'       => 100,
    'step'      => 1,
    'placeholder' => '0',
] );
```

### Select Dropdown

```php
<?php
new Woodev_Control( 'select', [
    'options' => [
        'value1' => __( 'Label 1', 'my-plugin' ),
        'value2' => __( 'Label 2', 'my-plugin' ),
        'value3' => __( 'Label 3', 'my-plugin' ),
    ],
    'class' => 'regular-text',
] );
```

### Radio Buttons

```php
<?php
new Woodev_Control( 'radio', [
    'options' => [
        'option1' => __( 'Option 1', 'my-plugin' ),
        'option2' => __( 'Option 2', 'my-plugin' ),
    ],
] );
```

### Checkbox

```php
<?php
new Woodev_Control( 'checkbox', [
    'label' => __( 'Enable feature', 'my-plugin' ),
] );
```

### Textarea

```php
<?php
new Woodev_Control( 'textarea', [
    'rows'        => 5,
    'cols'        => 50,
    'placeholder' => 'Enter description',
] );
```

### Color Picker

```php
<?php
new Woodev_Control( 'color', [
    'default' => '#ffffff',
] );
```

### Range Slider

```php
<?php
new Woodev_Control( 'range', [
    'min'   => 0,
    'max'   => 100,
    'step'  => 1,
] );
```

### File Upload

```php
<?php
new Woodev_Control( 'file', [
    'button_text' => __( 'Upload File', 'my-plugin' ),
] );
```

### Date Picker

```php
<?php
new Woodev_Control( 'date', [
    'min' => date( 'Y-m-d' ),
] );
```

### Email Input

```php
<?php
new Woodev_Control( 'email', [
    'placeholder' => 'email@example.com',
] );
```

### Password Input

```php
<?php
new Woodev_Control( 'password', [
    'placeholder' => 'Enter password',
] );
```

## Complete Settings Page Example

### Settings Class

```php
<?php
class My_Settings extends Woodev_Abstract_Settings {

    private static $instance;

    public static function instance( Woodev_Plugin $plugin ): self {
        if ( null === self::$instance ) {
            self::$instance = new self( $plugin );
        }
        return self::$instance;
    }

    protected function register_settings(): void {
        // General Section
        $this->register_setting(
            'api_key',
            'string',
            [
                'name'        => __( 'API Key', 'my-plugin' ),
                'description' => __( 'Your API key.', 'my-plugin' ),
                'default'     => '',
                'control'     => new Woodev_Control( 'password', [
                    'placeholder' => 'sk_live_...',
                ] ),
            ]
        );

        $this->register_setting(
            'api_secret',
            'string',
            [
                'name'        => __( 'API Secret', 'my-plugin' ),
                'description' => __( 'Your API secret.', 'my-plugin' ),
                'default'     => '',
                'control'     => new Woodev_Control( 'password' ),
            ]
        );

        // Advanced Section
        $this->register_setting(
            'debug_mode',
            'boolean',
            [
                'name'        => __( 'Debug Mode', 'my-plugin' ),
                'description' => __( 'Enable debug logging.', 'my-plugin' ),
                'default'     => false,
                'control'     => new Woodev_Control( 'checkbox' ),
            ]
        );

        $this->register_setting(
            'log_retention_days',
            'integer',
            [
                'name'        => __( 'Log Retention (days)', 'my-plugin' ),
                'description' => __( 'Days to keep logs.', 'my-plugin' ),
                'default'     => 30,
                'control'     => new Woodev_Control( 'number', [
                    'min'  => 1,
                    'max'  => 365,
                    'step' => 1,
                ] ),
            ]
        );

        // Limits Section
        $this->register_setting(
            'max_requests_per_minute',
            'integer',
            [
                'name'        => __( 'Max Requests/Minute', 'my-plugin' ),
                'description' => __( 'Rate limit.', 'my-plugin' ),
                'default'     => 60,
                'control'     => new Woodev_Control( 'number', [
                    'min'  => 1,
                    'max'  => 1000,
                ] ),
            ]
        );

        $this->register_setting(
            'timeout',
            'float',
            [
                'name'        => __( 'Timeout (seconds)', 'my-plugin' ),
                'description' => __( 'Request timeout.', 'my-plugin' ),
                'default'     => 30.0,
                'control'     => new Woodev_Control( 'range', [
                    'min'  => 1,
                    'max'  => 120,
                    'step' => 0.5,
                ] ),
            ]
        );
    }
}
```

### Plugin Integration

```php
<?php
class My_Plugin extends Woodev_Plugin {

    public function get_settings_handler(): ?Woodev_Abstract_Settings {
        return My_Settings::instance( $this );
    }

    public function init_admin() {
        parent::init_admin();

        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings_section' ] );
    }

    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            __( 'My Plugin Settings', 'my-plugin' ),
            __( 'My Plugin', 'my-plugin' ),
            'manage_woocommerce',
            'my-plugin',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings_section() {
        $settings = $this->get_settings_handler();

        register_setting(
            'my_plugin_settings',
            'my_plugin_settings_group'
        );

        add_settings_section(
            'my_plugin_general',
            __( 'General', 'my-plugin' ),
            [ $this, 'render_general_section' ],
            'my-plugin'
        );

        // Add settings fields
        foreach ( $settings->get_settings() as $id => $setting ) {
            add_settings_field(
                $id,
                $setting->get_name(),
                [ $this, 'render_field' ],
                'my-plugin',
                'my_plugin_general',
                [
                    'setting' => $setting,
                    'option_name' => "woodev_{$this->get_id()}_{$id}",
                ]
            );
        }
    }

    public function render_general_section() {
        echo '<p>' . __( 'Configure your plugin settings.', 'my-plugin' ) . '</p>';
    }

    public function render_field( array $args ) {
        $setting = $args['setting'];
        $control = $setting->get_control();
        $value = $setting->get_value();
        $option_name = $args['option_name'];

        echo '<label>';

        switch ( $control->get_type() ) {
            case 'text':
            case 'email':
            case 'password':
            case 'number':
            case 'date':
                printf(
                    '<input type="%s" name="%s" value="%s" class="regular-text" />',
                    esc_attr( $control->get_type() ),
                    esc_attr( $option_name ),
                    esc_attr( $value )
                );
                break;

            case 'checkbox':
                printf(
                    '<input type="checkbox" name="%s" value="yes" %s />',
                    esc_attr( $option_name ),
                    checked( $value, 'yes', false )
                );
                break;

            case 'select':
                echo '<select class="regular-text">';
                foreach ( $control->get_options() as $opt_value => $opt_label ) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr( $opt_value ),
                        selected( $value, $opt_value, false ),
                        esc_html( $opt_label )
                    );
                }
                echo '</select>';
                break;

            case 'textarea':
                printf(
                    '<textarea name="%s" class="large-text" rows="5">%s</textarea>',
                    esc_attr( $option_name ),
                    esc_textarea( $value )
                );
                break;
        }

        echo '</label>';

        if ( $setting->get_description() ) {
            echo '<p class="description">' . esc_html( $setting->get_description() ) . '</p>';
        }
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $this->get_plugin_name() ); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'my_plugin_settings' ); ?>
                <?php do_settings_sections( 'my-plugin' ); ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
```

## Validation

### Custom Validation

```php
<?php
class My_Settings extends Woodev_Abstract_Settings {

    protected function register_settings(): void {
        $this->register_setting(
            'api_key',
            'string',
            [
                'name'        => __( 'API Key', 'my-plugin' ),
                'default'     => '',
                'control'     => new Woodev_Control( 'text' ),
                'validate_callback' => [ $this, 'validate_api_key' ],
            ]
        );
    }

    public function validate_api_key( $value ) {
        if ( empty( $value ) ) {
            add_settings_error(
                'my_plugin_api_key',
                'required',
                __( 'API Key is required.', 'my-plugin' )
            );
            return '';
        }

        if ( ! str_starts_with( $value, 'sk_live_' ) ) {
            add_settings_error(
                'my_plugin_api_key',
                'invalid_format',
                __( 'API Key must start with sk_live_.', 'my-plugin' )
            );
            return '';
        }

        return sanitize_text_field( $value );
    }
}
```

## Sanitization

### Built-in Sanitization

Settings are automatically sanitized based on type:

- `string` — `sanitize_text_field()`
- `url` — `esc_url_raw()`
- `email` — `sanitize_email()`
- `integer` — `absint()`
- `float` — `floatval()`
- `boolean` — `wc_string_to_bool()`
- `object` — `wp_json_encode()`

### Custom Sanitization

```php
<?php
$this->register_setting(
    'custom_field',
    'string',
    [
        'name' => __( 'Custom Field', 'my-plugin' ),
        'sanitize_callback' => function( $value ) {
            return strtoupper( sanitize_text_field( $value ) );
        },
    ]
);
```

## REST API Integration

Settings are automatically available via REST API when connected to plugin:

```php
<?php
// GET /wp-json/wc/v3/my-plugin/settings
// Returns all settings

// PUT /wp-json/wc/v3/my-plugin/settings
// Update settings
```

## System Status Integration

Settings are automatically included in WooCommerce System Status:

```text
WooCommerce > Status > My Plugin
- API Key: sk_live_...
- Debug Mode: Yes
- Max Weight: 30.0
```

## Best Practices

### 1. Use Singleton Pattern

```php
<?php
class My_Settings extends Woodev_Abstract_Settings {

    private static $instance;

    public static function instance( Woodev_Plugin $plugin ): self {
        if ( null === self::$instance ) {
            self::$instance = new self( $plugin );
        }
        return self::$instance;
    }
}
```

### 2. Provide Sensible Defaults

```php
<?php
$this->register_setting(
    'timeout',
    'float',
    [
        'name'    => __( 'Timeout', 'my-plugin' ),
        'default' => 30.0, // Sensible default
    ]
);
```

### 3. Use Descriptive Names

```php
<?php
// ✅ Good
'name' => __( 'Maximum Weight (kg)', 'my-plugin' ),

// ❌ Bad
'name' => __( 'Max Weight', 'my-plugin' ),
```

### 4. Add Help Text

```php
<?php
$this->register_setting(
    'api_key',
    'string',
    [
        'name'        => __( 'API Key', 'my-plugin' ),
        'description' => __( 'Get your API key from the dashboard.', 'my-plugin' ),
    ]
);
```

### 5. Validate Input

```php
<?php
'validate_callback' => function( $value ) {
    if ( empty( $value ) ) {
        add_settings_error( 'api_key', 'required', 'Required' );
    }
    return $value;
},
```

## Related Documentation

- [Core Framework](core-framework.md) — Plugin base class
- [REST API](rest-api.md) — REST endpoints
- [Admin Module](admin-module.md) — Admin pages

---

*For more information, see [README.md](README.md).*
