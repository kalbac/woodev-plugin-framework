<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Licencing_API_Response' ) ) :

	/**
	 * @property stdClass[] $response_data
	 */
	class Woodev_Licencing_API_Response extends Woodev_API_JSON_Response {

		public function get_response_data() {
			return $this->response_data;
		}
	}

endif;