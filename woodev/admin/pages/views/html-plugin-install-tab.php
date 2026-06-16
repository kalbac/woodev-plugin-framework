<?php

defined( 'ABSPATH' ) || exit;

/**
 * Variables passed from Woodev_Plugin_Install_Tab::render():
 *
 * @var array<int, object> $addons   Product objects from the Woodev store API.
 * @var string             $base_url Base URL for tab navigation (plugin-install.php?tab=woodev).
 * @var string             $search   Current search query.
 * @var string             $section  Current section/category slug.
 * @var array<int, object> $sections Category objects from the Woodev store API.
 */
?>
<div class="woodev-extension-wrap">
	<div class="woodev-extension-header">
		<h1 class="woodev-extension-header__title"><?php esc_html_e( 'Woodev Plugins', 'woodev-plugin-framework' ); ?></h1>
		<p class="woodev-extension-header__description"><?php esc_html_e( 'Improve your WordPress website with our extensions.', 'woodev-plugin-framework' ); ?></p>
		<form method="GET" class="woodev-extension-header__search-form" action="<?php echo esc_url( admin_url( 'plugin-install.php' ) ); ?>">
			<input
				type="text"
				name="search"
				value="<?php echo esc_attr( $search ); ?>"
				placeholder="<?php esc_attr_e( 'Search for extensions', 'woodev-plugin-framework' ); ?>"
			/>
			<button type="submit">
				<span class="dashicons dashicons-search"></span>
			</button>
			<input type="hidden" name="tab" value="woodev">
		</form>
	</div>

	<div class="wrap">
		<div class="woodev-extension-content-wrapper">

			<div class="woodev-extension-content-categories">
				<ul>
					<li <?php echo ( 'all' === $section ) ? 'class="current"' : ''; ?>>
						<a href="<?php echo esc_url( $base_url ); ?>">
							<?php esc_html_e( 'All', 'woodev-plugin-framework' ); ?>
						</a>
					</li>
					<?php foreach ( (array) $sections as $category ) : ?>
						<li <?php echo ( $category->slug === $section ) ? 'class="current"' : ''; ?>>
							<a href="<?php echo esc_url( add_query_arg( 'section', $category->slug, $base_url ) ); ?>">
								<?php echo esc_html( $category->label ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="woodev-extension-content-list">
				<?php foreach ( (array) $addons as $addon ) : ?>
					<div class="woodev-extension-content-item">
						<div class="details">
							<div class="picture">
								<a href="<?php echo esc_url( Woodev_Admin_Plugins::generate_utm_url( $addon->info->permalink ?: $addon->info->link, $addon->info->slug ) ); ?>" target="_blank" rel="noopener noreferrer">
									<img
										src="<?php echo esc_url( isset( $addon->info->thumbnails, $addon->info->thumbnails->small ) ? $addon->info->thumbnails->small : $addon->info->thumbnail ); ?>"
										alt="<?php echo esc_attr( $addon->info->title ); ?>"
									/>
								</a>
							</div>
							<div class="description">
								<h3 class="name">
									<a href="<?php echo esc_url( Woodev_Admin_Plugins::generate_utm_url( $addon->info->permalink ?: $addon->info->link, $addon->info->slug ) ); ?>" target="_blank" rel="noopener noreferrer">
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
								<?php
								if ( isset( $addon->rating ) ) {
									wp_star_rating(
										[
											'rating' => $addon->rating,
											'type'   => 'percent',
										]
									);
								}
								?>
							</div>
							<div class="action-button">
								<?php
								$amount      = isset( $addon->pricing->amount ) ? intval( $addon->pricing->amount ) : 0;
								$button_text = $amount > 0
									? sprintf( __( 'Buy by %d RUB', 'woodev-plugin-framework' ), intval( $addon->pricing->amount ) )
									: __( 'Free download', 'woodev-plugin-framework' );
								?>
								<a href="<?php echo esc_url( Woodev_Admin_Plugins::generate_utm_url( $addon->info->permalink ?: $addon->info->link, $addon->info->slug ) ); ?>" class="button">
									<?php echo esc_html( $button_text ); ?>
								</a>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

		</div>
	</div>
</div>
