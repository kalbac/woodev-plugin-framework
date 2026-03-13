# Admin Module

The Admin module provides infrastructure for creating admin pages, displaying notices, and managing messages in the WordPress admin area.

## Overview

The Admin module handles:

- Top-level Woodev admin menu
- Dismissible admin notices
- Flash messages for one-off notifications
- Setup wizard for onboarding

## Key Classes

| Class | File | Purpose |
| --- | --- | --- |
| `Woodev_Admin_Pages` | `admin/class-admin-pages.php` | Woodev admin menu |
| `Woodev_Admin_Notice_Handler` | `class-admin-notice-handler.php` | Dismissible notices |
| `Woodev_Admin_Message_Handler` | `class-admin-message-handler.php` | Flash messages |
| `Woodev_Plugin_Setup_Wizard` | `admin/abstract-plugin-admin-setup-wizard.php` | Setup wizard |

## Admin Pages

### Woodev Menu

The framework automatically creates a **Woodev** top-level menu with:

- **Licenses** — License management for all plugins
- **Plugins** — List of installed Woodev plugins

### Creating Custom Admin Pages

```php
<?php
class My_Plugin extends Woodev_Plugin {

    public function init_admin() {
        parent::init_admin();

        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'My Plugin', 'my-plugin' ),
            __( 'My Plugin', 'my-plugin' ),
            'manage_woocommerce',
            'my-plugin',
            [ $this, 'render_settings_page' ]
        );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $this->get_plugin_name() ); ?></h1>
            <p><?php esc_html_e( 'Configure your plugin settings.', 'my-plugin' ); ?></p>
        </div>
        <?php
    }
}
```

## Admin Notices

### Woodev_Admin_Notice_Handler

Displays dismissible notices that are stored per user.

### Displaying a Notice

```php
<?php
$plugin = My_Plugin::instance();

$plugin->get_admin_notice_handler()->add_admin_notice(
    '<p>' . __( 'Thank you for installing My Plugin!', 'my-plugin' ) . '</p>',
    'my-notice-id',
    [
        'dismissible'  => true,
        'notice_class' => 'notice-info',
    ]
);
```

### Notice Options

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `dismissible` | `bool` | `true` | Show dismiss button |
| `notice_class` | `string` | `'updated'` | CSS class for styling |
| `always_show_on_settings` | `bool` | `true` | Always show on settings pages |

### Notice Classes

- `notice-info` — Blue information notice
- `notice-warning` — Yellow warning notice
- `notice-error` — Red error notice
- `notice-success` — Green success notice

### Checking if Notice is Dismissed

```php
<?php
$handler = $plugin->get_admin_notice_handler();

if ( $handler->is_notice_dismissed( 'my-notice-id' ) ) {
    echo 'Notice was dismissed';
} else {
    echo 'Notice is visible';
}
```

### Dismissing Notices Programmatically

```php
<?php
// Dismiss a notice for current user
$handler->dismiss_notice( 'my-notice-id' );

// Undismiss a notice
$handler->undismiss_notice( 'my-notice-id' );

// Get all dismissed notices
$dismissed = $handler->get_dismissed_notices();
```

### Complete Notice Example

```php
<?php
class Notice_Manager {

    private Woodev_Plugin $plugin;

    public function __construct( Woodev_Plugin $plugin ) {
        $this->plugin = $plugin;
        $this->init();
    }

    private function init() {
        add_action( 'admin_init', [ $this, 'check_api_key' ] );
        add_action( 'admin_init', [ $this, 'check_license' ] );
    }

    public function check_api_key() {
        $settings = $this->plugin->get_settings_handler();
        $api_key = $settings->get_value( 'api_key' );

        if ( empty( $api_key ) ) {
            $this->show_api_key_notice();
        }
    }

    private function show_api_key_notice() {
        $this->plugin->get_admin_notice_handler()->add_admin_notice(
            sprintf(
                '<p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p>',
                __( 'API Key Required', 'my-plugin' ),
                __( 'Please enter your API key to use this plugin.', 'my-plugin' ),
                $this->plugin->get_settings_url(),
                __( 'Go to Settings', 'my-plugin' )
            ),
            'my-plugin-api-key-missing',
            [
                'dismissible'  => false,
                'notice_class' => 'notice-error',
            ]
        );
    }

    public function check_license() {
        $license = $this->plugin->get_license_instance();

        if ( $license && ! $license->is_active() ) {
            $this->show_license_notice();
        }
    }

    private function show_license_notice() {
        $this->plugin->get_admin_notice_handler()->add_admin_notice(
            sprintf(
                '<p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p>',
                __( 'License Expired', 'my-plugin' ),
                __( 'Please renew your license to continue receiving updates.', 'my-plugin' ),
                admin_url( 'admin.php?page=woodev-licenses' ),
                __( 'Renew License', 'my-plugin' )
            ),
            'my-plugin-license-expired',
            [
                'dismissible'  => true,
                'notice_class' => 'notice-warning',
            ]
        );
    }
}
```

## Flash Messages

### Woodev_Admin_Message_Handler

Used for one-off messages that appear after redirects.

### Adding Messages

```php
<?php
$plugin = My_Plugin::instance();

// Add success message
$plugin->get_message_handler()->add_message(
    __( 'Settings saved successfully!', 'my-plugin' )
);

// Add error message
$plugin->get_message_handler()->add_error(
    __( 'Failed to save settings.', 'my-plugin' )
);

// Add warning
$plugin->get_message_handler()->add_warning(
    __( 'Some settings were not saved.', 'my-plugin' )
);

// Add info
$plugin->get_message_handler()->add_info(
    __( 'Please review your settings.', 'my-plugin' )
);
```

### Message Methods

```php
<?php
$handler = $plugin->get_message_handler();

// Count messages
echo $handler->message_count();
echo $handler->error_count();
echo $handler->warning_count();
echo $handler->info_count();

// Get messages
$errors = $handler->get_errors();
$messages = $handler->get_messages();
$warnings = $handler->get_warnings();
$infos = $handler->get_infos();

// Clear all messages
$handler->clear_messages();
```

### Redirect with Message

```php
<?php
function save_settings() {
    $plugin = My_Plugin::instance();

    // Save settings
    $success = $this->do_save();

    if ( $success ) {
        $plugin->get_message_handler()->add_message(
            __( 'Settings saved!', 'my-plugin' )
        );
    } else {
        $plugin->get_message_handler()->add_error(
            __( 'Save failed!', 'my-plugin' )
        );
    }

    // Redirect - message will show on next page
    wp_safe_redirect( admin_url( 'admin.php?page=my-plugin' ) );
    exit;
}
```

### Displaying Messages

```php
<?php
function render_settings_page() {
    $plugin = My_Plugin::instance();

    // Messages are automatically displayed
    $plugin->get_message_handler()->show_messages();

    // Or manually:
    ?>
    <div class="wrap">
        <h1>Settings</h1>

        <?php $plugin->get_message_handler()->show_messages(); ?>

        <!-- Settings form -->
    </div>
    <?php
}
```

## Setup Wizard

### Creating a Setup Wizard

```php
<?php
class My_Setup_Wizard extends Woodev_Plugin_Setup_Wizard {

    /**
     * Register wizard steps
     */
    protected function register_steps(): void {
        $this->register_step(
            'introduction',
            __( 'Introduction', 'my-plugin' ),
            [ $this, 'step_introduction' ]
        );

        $this->register_step(
            'api_key',
            __( 'API Key', 'my-plugin' ),
            [ $this, 'step_api_key' ],
            [ $this, 'step_api_key_save' ]
        );

        $this->register_step(
            'settings',
            __( 'Settings', 'my-plugin' ),
            [ $this, 'step_settings' ],
            [ $this, 'step_settings_save' ]
        );

        $this->register_step(
            'complete',
            __( 'Complete', 'my-plugin' ),
            [ $this, 'step_complete' ]
        );
    }

    /**
     * Introduction step
     */
    public function step_introduction(): void {
        ?>
        <h1><?php esc_html_e( 'Welcome to My Plugin!', 'my-plugin' ); ?></h1>
        <p><?php esc_html_e( 'This wizard will help you configure the plugin.', 'my-plugin' ); ?></p>
        <?php
    }

    /**
     * API Key step view
     */
    public function step_api_key(): void {
        ?>
        <h1><?php esc_html_e( 'API Key', 'my-plugin' ); ?></h1>
        <label>
            <?php esc_html_e( 'Enter your API key:', 'my-plugin' ); ?>
            <input type="text" name="api_key" class="regular-text" />
        </label>
        <?php
    }

    /**
     * API Key step handler
     */
    public function step_api_key_save(): void {
        $api_key = isset( $_POST['api_key'] )
            ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) )
            : '';

        update_option( 'my_plugin_api_key', $api_key );
    }

    /**
     * Settings step view
     */
    public function step_settings(): void {
        ?>
        <h1><?php esc_html_e( 'Settings', 'my-plugin' ); ?></h1>
        <label>
            <input type="checkbox" name="debug_mode" value="yes" />
            <?php esc_html_e( 'Enable debug mode', 'my-plugin' ); ?>
        </label>
        <?php
    }

    /**
     * Settings step handler
     */
    public function step_settings_save(): void {
        $debug_mode = isset( $_POST['debug_mode'] ) ? 'yes' : 'no';
        update_option( 'my_plugin_debug_mode', $debug_mode );
    }

    /**
     * Complete step
     */
    public function step_complete(): void {
        ?>
        <h1><?php esc_html_e( 'Setup Complete!', 'my-plugin' ); ?></h1>
        <p><?php esc_html_e( 'Your plugin is now configured.', 'my-plugin' ); ?></p>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=my-plugin' ) ); ?>">
            <?php esc_html_e( 'Go to Settings', 'my-plugin' ); ?>
        </a>
        <?php
    }
}
```

### Connecting Wizard to Plugin

```php
<?php
class My_Plugin extends Woodev_Plugin {

    protected function init_setup_wizard_handler() {
        parent::init_setup_wizard_handler();

        $this->setup_wizard_handler = new My_Setup_Wizard( $this );
    }
}
```

### Wizard URL

The wizard is accessible at:
`wp-admin/index.php?page=woodev-{plugin-id-dasherized}-setup`

## Admin Assets

### Enqueueing Scripts and Styles

```php
<?php
class My_Plugin extends Woodev_Plugin {

    public function init_admin() {
        parent::init_admin();

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    public function enqueue_admin_assets( string $hook_suffix ): void {
        // Only on plugin pages
        if ( ! $this->is_plugin_settings() ) {
            return;
        }

        // Enqueue styles
        wp_enqueue_style(
            'my-plugin-admin',
            $this->get_plugin_url() . '/assets/css/admin.css',
            [],
            $this->get_version()
        );

        // Enqueue scripts
        wp_enqueue_script(
            'my-plugin-admin',
            $this->get_plugin_url() . '/assets/js/admin.js',
            [ 'jquery' ],
            $this->get_version(),
            true
        );

        // Localize script
        wp_localize_script( 'my-plugin-admin', 'myPlugin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'my-plugin-admin' ),
            'i18n'    => [
                'saving'  => __( 'Saving...', 'my-plugin' ),
                'saved'   => __( 'Saved!', 'my-plugin' ),
                'error'   => __( 'Error!', 'my-plugin' ),
            ],
        ] );
    }
}
```

### Getting Admin Strings

```php
<?php
class My_Plugin extends Woodev_Plugin {

    public function get_admin_js_strings(): array {
        return [
            'confirmDelete' => __( 'Are you sure?', 'my-plugin' ),
            'loading'       => __( 'Loading...', 'my-plugin' ),
            'error'         => __( 'An error occurred', 'my-plugin' ),
        ];
    }
}
```

## Practical Examples

### Example 1: Settings Page with Notices

```php
<?php
class Settings_Page {

    private Woodev_Plugin $plugin;

    public function __construct( Woodev_Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    public function init() {
        add_action( 'admin_menu', [ $this, 'add_page' ] );
        add_action( 'admin_init', [ $this, 'handle_save' ] );
    }

    public function add_page() {
        add_submenu_page(
            'woocommerce',
            'My Plugin',
            'My Plugin',
            'manage_woocommerce',
            'my-plugin',
            [ $this, 'render' ]
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['save_settings'] ) ) {
            return;
        }

        check_admin_referer( 'my-plugin-save', 'nonce' );

        $api_key = sanitize_text_field( $_POST['api_key'] ?? '' );

        if ( empty( $api_key ) ) {
            $this->plugin->get_message_handler()->add_error(
                __( 'API Key is required.', 'my-plugin' )
            );
            return;
        }

        update_option( 'my_plugin_api_key', $api_key );

        $this->plugin->get_message_handler()->add_message(
            __( 'Settings saved!', 'my-plugin' )
        );
    }

    public function render() {
        $api_key = get_option( 'my_plugin_api_key', '' );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $this->plugin->get_plugin_name() ); ?></h1>

            <?php $this->plugin->get_message_handler()->show_messages(); ?>

            <?php $this->plugin->get_admin_notice_handler()->render_admin_notices(); ?>

            <form method="post">
                <?php wp_nonce_field( 'my-plugin-save', 'nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th>
                            <label for="api_key"><?php esc_html_e( 'API Key', 'my-plugin' ); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="api_key"
                                   name="api_key"
                                   class="regular-text"
                                   value="<?php echo esc_attr( $api_key ); ?>" />
                            <p class="description">
                                <?php esc_html_e( 'Enter your API key.', 'my-plugin' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save Settings', 'my-plugin' ), 'primary', 'save_settings' ); ?>
            </form>
        </div>
        <?php
    }
}
```

### Example 2: Conditional Notices

```php
<?php
class Notice_Handler {

    private Woodev_Plugin $plugin;

    public function __construct( Woodev_Plugin $plugin ) {
        $this->plugin = $plugin;
        add_action( 'admin_init', [ $this, 'check_requirements' ] );
    }

    public function check_requirements() {
        // Check SSL
        if ( ! wp_http_supports( [ 'ssl' ] ) ) {
            $this->show_ssl_notice();
        }

        // Check API key
        $api_key = get_option( 'my_plugin_api_key' );
        if ( empty( $api_key ) ) {
            $this->show_api_key_notice();
        }

        // Check for updates
        $this->check_for_updates();
    }

    private function show_ssl_notice() {
        $this->plugin->get_admin_notice_handler()->add_admin_notice(
            sprintf(
                '<p><strong>%1$s</strong> %2$s</p>',
                __( 'SSL Required', 'my-plugin' ),
                __( 'Your server must support SSL to use this plugin.', 'my-plugin' )
            ),
            'my-plugin-ssl-required',
            [
                'dismissible'  => false,
                'notice_class' => 'notice-error',
            ]
        );
    }

    private function show_api_key_notice() {
        $this->plugin->get_admin_notice_handler()->add_admin_notice(
            sprintf(
                '<p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p>',
                __( 'API Key Required', 'my-plugin' ),
                __( 'Please enter your API key.', 'my-plugin' ),
                $this->plugin->get_settings_url(),
                __( 'Settings', 'my-plugin' )
            ),
            'my-plugin-api-key',
            [
                'dismissible'  => true,
                'notice_class' => 'notice-warning',
            ]
        );
    }

    private function check_for_updates() {
        $last_check = get_option( 'my_plugin_last_update_check', 0 );

        if ( time() - $last_check > WEEK_IN_SECONDS ) {
            $this->show_update_notice();
        }
    }

    private function show_update_notice() {
        $this->plugin->get_admin_notice_handler()->add_admin_notice(
            sprintf(
                '<p>%1$s <a href="%2$s">%3$s</a></p>',
                __( 'A new version is available!', 'my-plugin' ),
                admin_url( 'update-core.php' ),
                __( 'Update Now', 'my-plugin' )
            ),
            'my-plugin-update-check',
            [
                'dismissible'  => true,
                'notice_class' => 'notice-info',
            ]
        );
    }
}
```

## Best Practices

### 1. Use Message Handler for Redirects

```php
<?php
// Save and redirect
function save_and_redirect() {
    $this->save_data();

    $plugin->get_message_handler()->add_message(
        __( 'Data saved!', 'my-plugin' )
    );

    wp_redirect( $url );
    exit;
}
```

### 2. Make Notices Dismissible

```php
<?php
$handler->add_admin_notice(
    '<p>Important information</p>',
    'my-notice',
    [ 'dismissible' => true ]
);
```

### 3. Use Appropriate Notice Classes

```php
<?php
// Errors
'notice_class' => 'notice-error'

// Warnings
'notice_class' => 'notice-warning'

// Info
'notice_class' => 'notice-info'

// Success
'notice_class' => 'notice-success'
```

### 4. Escape All Output

```php
<?php
echo esc_html( $text );
echo esc_url( $url );
echo esc_attr( $attribute );
```

### 5. Check Permissions

```php
<?php
if ( ! current_user_can( 'manage_woocommerce' ) ) {
    return;
}
```

## Related Documentation

- [Core Framework](core-framework.md) — Plugin base class
- [Settings API](settings-api.md) — Settings handling
- [Helpers](helpers.md) — Utility functions

---

*For more information, see [README.md](README.md).*
