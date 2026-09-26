SELECT SQL_CALC_FOUND_ROWS  wp_posts.*
					 FROM wp_posts 
					 WHERE 1=1  AND wp_posts.post_type IN ('shop_order', 'shop_order_refund') AND ((wp_posts.post_status = 'wc-pending' OR wp_posts.post_status = 'wc-processing' OR wp_posts.post_status = 'wc-on-hold' OR wp_posts.post_status = 'wc-completed' OR wp_posts.post_status = 'wc-refunded' OR wp_posts.post_status = 'wc-checkout-draft')) AND EXISTS (SELECT 1 FROM wp_postmeta k WHERE k.post_id = wp_posts.ID AND k.meta_key IN ('_m1_marker','_m2_marker')) AND NOT EXISTS (SELECT 1 FROM wp_postmeta s WHERE s.post_id = wp_posts.ID AND ((s.meta_key = '_m1_status' AND s.meta_value IN ('M1_GO','M1_DONE')) OR (s.meta_key = '_m2_status' AND s.meta_value IN ('M2_GO','M2_DONE'))))
					 
					 ORDER BY wp_posts.post_date DESC
					 LIMIT 0, 20


