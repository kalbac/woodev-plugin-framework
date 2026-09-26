SELECT SQL_CALC_FOUND_ROWS  wp_posts.*
					 FROM wp_posts  LEFT JOIN wp_postmeta ON ( wp_posts.ID = wp_postmeta.post_id )  LEFT JOIN wp_postmeta AS mt1 ON ( wp_posts.ID = mt1.post_id AND mt1.meta_key = '_m1_status' )  LEFT JOIN wp_postmeta AS mt2 ON ( wp_posts.ID = mt2.post_id )  LEFT JOIN wp_postmeta AS mt3 ON ( wp_posts.ID = mt3.post_id )  LEFT JOIN wp_postmeta AS mt4 ON ( wp_posts.ID = mt4.post_id AND mt4.meta_key = '_m2_status' )  LEFT JOIN wp_postmeta AS mt5 ON ( wp_posts.ID = mt5.post_id )
					 WHERE 1=1  AND ( 
  ( 
    wp_postmeta.meta_key = '_m1_marker' 
    AND 
    ( 
      mt1.post_id IS NULL 
      OR 
      ( mt2.meta_key = '_m1_status' AND mt2.meta_value NOT IN ('M1_GO','M1_DONE') )
    )
  ) 
  OR 
  ( 
    mt3.meta_key = '_m2_marker' 
    AND 
    ( 
      mt4.post_id IS NULL 
      OR 
      ( mt5.meta_key = '_m2_status' AND mt5.meta_value NOT IN ('M2_GO','M2_DONE') )
    )
  )
) AND wp_posts.post_type IN ('shop_order', 'shop_order_refund') AND ((wp_posts.post_status = 'wc-pending' OR wp_posts.post_status = 'wc-processing' OR wp_posts.post_status = 'wc-on-hold' OR wp_posts.post_status = 'wc-completed' OR wp_posts.post_status = 'wc-refunded' OR wp_posts.post_status = 'wc-checkout-draft'))
					 GROUP BY wp_posts.ID
					 ORDER BY wp_posts.post_date DESC
					 LIMIT 0, 20


