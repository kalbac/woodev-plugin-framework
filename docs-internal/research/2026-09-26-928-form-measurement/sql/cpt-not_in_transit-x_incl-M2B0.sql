SELECT SQL_CALC_FOUND_ROWS  wp_posts.*
					 FROM wp_posts 
					 WHERE 1=1  AND wp_posts.ID IN (1,2,3,4,5,… 750 ids) AND wp_posts.post_type IN ('shop_order', 'shop_order_refund') AND ((wp_posts.post_status = 'wc-pending' OR wp_posts.post_status = 'wc-processing' OR wp_posts.post_status = 'wc-on-hold' OR wp_posts.post_status = 'wc-completed' OR wp_posts.post_status = 'wc-refunded' OR wp_posts.post_status = 'wc-checkout-draft'))
					 
					 ORDER BY wp_posts.post_date DESC
					 LIMIT 0, 20


