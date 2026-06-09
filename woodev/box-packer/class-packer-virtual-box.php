<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Packer_Virtual_Box' ) ) :

	/**
	 * Класс для упаковки товаров в виртуальную коробку с минимальным свободным пространством.
	 *
	 * Этот класс формирует виртуальную коробку на основе габаритов товаров и упаковывает их так,
	 * чтобы свободное пространство было минимальным.
	 */
	class Woodev_Packer_Virtual_Box extends Woodev_Packer {

		/**
		 * Упаковывает товары в виртуальную коробку.
		 *
		 * @throws Woodev_Packer_Exception Если товары отсутствуют.
		 */
		public function pack() {
			if ( ! $this->items || count( $this->items ) === 0 ) {
				throw new Woodev_Packer_Exception( __( 'Нет товаров для упаковки!' ) );
			}

			$this->packages = [];
			$this->items    = $this->order_items_by_volume_desc( $this->items );

			// Рассчитываем размеры виртуальной коробки
			$virtual_box_dimensions = $this->calculate_virtual_box_dimensions( $this->items );

			// Создаем виртуальную коробку
			$virtual_box = new Woodev_Packer_Box_Implementation(
				$virtual_box_dimensions['length'],
				$virtual_box_dimensions['width'],
				$virtual_box_dimensions['height'],
				0, // Вес коробки
				null, // Максимальный вес
				'virtual_box', // Уникальный идентификатор
				'Виртуальная коробка' // Название
			);

			// Упаковываем товары в виртуальную коробку
			$packed_box = new Woodev_Box_Packer_Packed_Box( $virtual_box, $this->items );

			// Все товары гарантированно помещаются в коробку
			$this->packages[] = $packed_box;
			$this->items      = [];
		}

		/**
		 * Рассчитывает размеры виртуальной коробки на основе габаритов товаров.
		 *
		 * @param Woodev_Box_Packer_Item[] $items Массив товаров.
		 *
		 * @return array Возвращает массив с размерами коробки: длина, ширина, высота.
		 */
		private function calculate_virtual_box_dimensions( array $items ): array {
			$n = count( $items );

			// Sort each dimension independently so the largest values are assigned
			// to whichever grid axis expands the least.
			$lengths = array_map( fn( Woodev_Box_Packer_Item $i ) => $i->get_length(), $items );
			$widths  = array_map( fn( Woodev_Box_Packer_Item $i ) => $i->get_width(), $items );
			$heights = array_map( fn( Woodev_Box_Packer_Item $i ) => $i->get_height(), $items );

			rsort( $lengths );
			rsort( $widths );
			rsort( $heights );

			// Prefix sums for O(1) slice-sum inside the double loop.
			$sum_l = array_fill( 0, $n + 1, 0.0 );
			$sum_w = array_fill( 0, $n + 1, 0.0 );
			$sum_h = array_fill( 0, $n + 1, 0.0 );
			for ( $i = 0; $i < $n; $i++ ) {
				$sum_l[ $i + 1 ] = $sum_l[ $i ] + $lengths[ $i ];
				$sum_w[ $i + 1 ] = $sum_w[ $i ] + $widths[ $i ];
				$sum_h[ $i + 1 ] = $sum_h[ $i ] + $heights[ $i ];
			}

			$best_max_dim = PHP_FLOAT_MAX;
			$best_volume  = PHP_FLOAT_MAX;
			$best         = [ $sum_l[1], $sum_w[1], $sum_h[ $n ] ]; // (1,1,N) as initial fallback

			// Enumerate every 3-D grid arrangement (a × b × c):
			// a items placed side-by-side along L, b along W, c stacked along H.
			// c is set to ceil(N / (a×b)) — the minimum layers needed to hold all items.
			for ( $a = 1; $a <= $n; $a++ ) {
				for ( $b = 1; $b <= (int) ceil( $n / $a ); $b++ ) {
					$c = (int) ceil( $n / ( $a * $b ) );

					$l = $sum_l[ $a ];
					$w = $sum_w[ $b ];
					$h = $sum_h[ $c ];

					$max_dim = max( $l, $w, $h );
					$volume  = $l * $w * $h;

					// Primary: minimise max dimension (avoids sausage shapes and carrier
					// oversized-parcel surcharges). Secondary: minimise volume.
					if (
						$max_dim < $best_max_dim - 1e-9 ||
						( $max_dim < $best_max_dim + 1e-9 && $volume < $best_volume )
					) {
						$best_max_dim = $max_dim;
						$best_volume  = $volume;
						$best         = [ $l, $w, $h ];
					}
				}
			}

			// sum_l[$a] >= $lengths[0] = max(lengths), so each axis is automatically >=
			// the per-item maximum for that axis. Do NOT re-sort the result — that would
			// destroy L/W/H axis names for non-normalised custom item implementations.
			return [
				'length' => $best[0],
				'width'  => $best[1],
				'height' => $best[2],
			];
		}

		/**
		 * Сортирует товары по объему в порядке убывания.
		 *
		 * @param Woodev_Box_Packer_Item[] $items Массив товаров.
		 *
		 * @return Woodev_Box_Packer_Item[] Отсортированный массив товаров.
		 */
		private function order_items_by_volume_desc( array $items ): array {
			usort(
				$items,
				function ( Woodev_Box_Packer_Item $a, Woodev_Box_Packer_Item $b ) {
					return $b->get_volume() <=> $a->get_volume();
				}
			);

			return $items;
		}
	}

endif;
