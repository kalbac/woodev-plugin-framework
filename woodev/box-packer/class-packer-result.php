<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Packer_Result' ) ) :

	/**
	 * Standardised result returned by Woodev_Packer_Dispatcher::pack().
	 *
	 * Always has the same array structure regardless of the chosen algorithm,
	 * making it safe to use in plugin business logic without switch/case on algorithm.
	 *
	 * @since 1.4.1
	 */
	final class Woodev_Packer_Result {

		/** @var string */
		private $algorithm;
		/** @var Woodev_Packer_Package_Result[] */
		private $packages;

		/**
		 * @since  1.4.1
		 * @param  string                         $algorithm Algorithm ID that produced this result.
		 * @param  Woodev_Packer_Package_Result[] $packages  Packed packages.
		 */
		public function __construct( string $algorithm, array $packages ) {
			$this->algorithm = $algorithm;
			$this->packages  = $packages;
		}

		/**
		 * The algorithm ID that was used.
		 *
		 * @since  1.4.1
		 * @return string
		 */
		public function get_algorithm(): string {
			return $this->algorithm;
		}

		/**
		 * @since  1.4.1
		 * @return Woodev_Packer_Package_Result[]
		 */
		public function get_packages(): array {
			return $this->packages;
		}

		/**
		 * @since  1.4.1
		 * @return int
		 */
		public function get_package_count(): int {
			return count( $this->packages );
		}

		/**
		 * Sum of all package weights.
		 *
		 * @since  1.4.1
		 * @return float
		 */
		public function get_total_weight(): float {
			return (float) array_sum(
				array_map( fn( Woodev_Packer_Package_Result $p ) => $p->get_weight(), $this->packages )
			);
		}

		/**
		 * Sum of all package volumes.
		 *
		 * @since  1.4.1
		 * @return float
		 */
		public function get_total_volume(): float {
			return (float) array_sum(
				array_map( fn( Woodev_Packer_Package_Result $p ) => $p->get_volume(), $this->packages )
			);
		}

		/**
		 * Returns the full result as a plain array, suitable for JSON serialisation
		 * or storage in shipping-rate meta.
		 *
		 * @since  1.4.1
		 * @return array{algorithm: string, package_count: int, total_weight: float, total_volume: float, packages: array}
		 */
		public function to_array(): array {
			return [
				'algorithm'     => $this->algorithm,
				'package_count' => $this->get_package_count(),
				'total_weight'  => $this->get_total_weight(),
				'total_volume'  => $this->get_total_volume(),
				'packages'      => array_map(
					fn( Woodev_Packer_Package_Result $p ) => $p->to_array(),
					$this->packages
				),
			];
		}
	}

endif;
