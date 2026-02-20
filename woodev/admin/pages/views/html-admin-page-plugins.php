<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @var array  $addons
 * @var string $section
 * @var string $search
 * @var array  $sections
 */
?>

    <div class="woodev-extension-wrap">
        <div class="woodev-extension-header">
            <h1 class="woodev-extension-header__title"><?php esc_html_e( 'Woodev Plugins', 'woodev-plugin-framework' ); ?></h1>
            <p class="woodev-extension-header__description"><?php esc_html_e( 'Improve your Wordpress website with our extensions.', 'woodev-plugin-framework' ); ?></p>
            <form method="GET" class="woodev-extension-header__search-form">
                <input
                        type="text"
                        name="search"
                        value="<?php echo esc_attr( ! empty( $search ) ? sanitize_text_field( wp_unslash( $search ) ) : '' ); ?>"
                        placeholder="<?php esc_attr_e( 'Search for extensions', 'woodev-plugin-framework' ); ?>"
                />
                <button type="submit">
                    <span class="dashicons dashicons-search"></span>
                </button>
                <input type="hidden" name="page" value="woodev-extensions">
                <input type="hidden" name="section" value="all">
            </form>
        </div>

        <div class="wrap">
            <div class="woodev-extension-content-wrapper">

                <div class="woodev-extension-content-categories">
                    <ul>
                        <li <?php if ( 'all' === $section ) echo 'class="current"'; ?>>
                            <a href="<?php menu_page_url( 'woodev-extensions' ); ?>">
                                <?php _e( 'All', 'woodev-plugin-framework' ); ?>
                            </a>
                        </li>
                        <?php foreach ( ( array ) $sections as $category ) : ?>
                            <li <?php if ( $category->slug === $section ) echo 'class="current"'; ?>>
                                <a href="<?php echo esc_url( add_query_arg( array( 'section' => $category->slug ), menu_page_url( 'woodev-extensions', false ) ) );?>"><?php echo esc_html( $category->label );?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="woodev-extension-content-list">

					<?php foreach ( ( array ) $addons as $addon ) : ?>

                        <div class="woodev-extension-content-item">
                            <div class="details">
                                <div class="picture">
                                    <a href="<?php echo esc_attr( Woodev_Admin_Plugins::generate_utm_url( $addon->info->permalink ?: $addon->info->link, $addon->info->slug ) ); ?>"
                                       target="_blank">
                                        <img src="<?php echo esc_url( isset( $addon->info->thumbnails, $addon->info->thumbnails->small ) ? $addon->info->thumbnails->small : $addon->info->thumbnail ); ?>"
                                             alt="<?php echo esc_html( $addon->info->title ); ?>"/>
                                    </a>
                                </div>
                                <div class="description">
                                    <h3 class="name">
                                        <a href="<?php echo esc_attr( Woodev_Admin_Plugins::generate_utm_url( $addon->info->permalink ?: $addon->info->link, $addon->info->slug ) ); ?>"
                                           target="_blank">
											<?php echo esc_html( $addon->info->title ); ?>
                                        </a>
                                    </h3>
                                    <div class="excerpt">
										<?php echo wp_kses_post( $addon->info->excerpt ); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="footer">
                                <div class="info">
									<?php if ( isset( $addon->rating ) ) {
										wp_star_rating( array( 'rating' => $addon->rating, 'type' => 'percent' ) );
									} ?>
                                </div>
                                <div class="action-button">
									<?php
									$amount      = isset( $addon->pricing->amount ) ? intval( $addon->pricing->amount ) : 0;
									$button_text = $amount > 0 ? sprintf( __( 'Buy by %d RUB', 'woodev-plugin-framework' ), intval( $addon->pricing->amount ) ) : __( 'Free download', 'woodev-plugin-framework' );
									?>
                                    <a href="<?php echo esc_attr( Woodev_Admin_Plugins::generate_utm_url( $addon->info->permalink ?: $addon->info->link, $addon->info->slug ) ); ?>" class="button"><?php echo esc_html( $button_text ); ?></a>
                                </div>
                            </div>
                        </div>

					<?php endforeach; ?>
                </div>

            </div>
        </div>
    </div>