<?php

defined( 'ABSPATH' ) or exit;

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
			if ( ! $this->items || sizeof( $this->items ) === 0 ) {
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
			$max_length = 0;
			$max_width  = 0;
			$max_height = 0;

			foreach ( $items as $item ) {
				if ( $item->get_length() > $max_length ) {
					$max_length = $item->get_length();
				}
				if ( $item->get_width() > $max_width ) {
					$max_width = $item->get_width();
				}
				if ( $item->get_height() > $max_height ) {
					$max_height = $item->get_height();
				}
			}

			return [
				'length' => $max_length,
				'width'  => $max_width,
				'height' => $max_height,
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
			usort( $items, function ( Woodev_Box_Packer_Item $a, Woodev_Box_Packer_Item $b ) {
				return $b->get_volume() <=> $a->get_volume();
			} );

			return $items;
		}
	}

endif;