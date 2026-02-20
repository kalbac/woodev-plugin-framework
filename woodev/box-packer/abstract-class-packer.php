<?php

abstract class Woodev_Packer implements Woodev_Packer_Interface {

	/** @var Woodev_Box_Packer_Box[] */
	protected $boxes;
	/** @var Woodev_Box_Packer_Item[] */
	protected $items;
	/** @var Woodev_Box_Packer_Item[] */
	protected $items_cannot_pack;
	/** @var Woodev_Box_Packer_Packed_Box[] */
	protected $packages;

	/**
	 * @param Woodev_Box_Packer_Item $item
	 */
	public function add_item( Woodev_Box_Packer_Item $item ): void {
		$this->items[] = $item;
	}

	/**
	 * @param Woodev_Box_Packer_Box $box
	 */
	public function add_box( Woodev_Box_Packer_Box $box ): void {
		$this->boxes[] = $box;
	}

	/**
	 * @return Woodev_Box_Packer_Packed_Box[]
	 */
	public function get_packages(): array {
		return $this->packages ?: array();
	}

	/**
	 * @return Woodev_Box_Packer_Item[]
	 */
	public function get_items_cannot_pack(): array {
		return $this->items_cannot_pack ?: array();
	}

	/**
	 * Pack items to boxes creating packages.
	 */
	abstract public function pack();
}
