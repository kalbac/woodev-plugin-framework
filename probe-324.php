<?php
// Temporary diagnostic probe for issue #324 follow-up. Deleted after use.
echo "REST_NONCE=" . wp_create_nonce( 'wp_rest' ) . "\n";

$products = get_posts(
	[
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 3,
	]
);

foreach ( $products as $product ) {
	echo "PRODUCT=" . $product->ID . " " . $product->post_title . "\n";
}
