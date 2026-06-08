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
			// Items are pre-normalised: length >= width >= height.
			// Stack items along one axis: that axis gets sum(), the other two get max().
			// Try all three axis assignments; return the combination with minimum volume.

			$lengths = array_map( fn( Woodev_Box_Packer_Item $i ) => $i->get_length(), $items );
			$widths  = array_map( fn( Woodev_Box_Packer_Item $i ) => $i->get_width(), $items );
			$heights = array_map( fn( Woodev_Box_Packer_Item $i ) => $i->get_height(), $items );

			$candidates = [
				// Option A: stack along height (smallest dim) - common case for flat items.
				[ max( $lengths ), max( $widths ), array_sum( $heights ) ],
				// Option B: stack along width (middle dim).
				[ max( $lengths ), array_sum( $widths ), max( $heights ) ],
				// Option C: stack along length (largest dim) - common case for long thin items.
				[ array_sum( $lengths ), max( $widths ), max( $heights ) ],
			];

			// Initialise to first candidate so $best is never null (even if all volumes overflow to INF).
			$best        = $candidates[0];
			$best_volume = PHP_FLOAT_MAX;

			foreach ( $candidates as $dims ) {
				$volume = $dims[0] * $dims[1] * $dims[2];
				if ( $volume < $best_volume ) {
					$best_volume = $volume;
					$best        = $dims;
				}
			}

			// Each candidate already guarantees box_axis >= max(item_axis) by construction.
			// Do NOT rsort: that would destroy axis-name alignment for non-normalised items.
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
