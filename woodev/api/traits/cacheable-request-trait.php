<?php

defined( 'ABSPATH' ) or exit;

if ( ! trait_exists( 'Woodev_Cacheable_Request_Trait' ) ) :

trait Woodev_Cacheable_Request_Trait {

	/** @var int the cache lifetime for the request, in seconds, defaults to 86400 (24 hours) */
	protected $cache_lifetime = 86400;

	/** @var bool whether to force a fresh request regardless if a cached response is available */
	protected $force_refresh = false;

	/** @var bool whether to the current request should be cached or not */
	protected $should_cache = true;


	/**
	 * Sets the cache lifetime for this request.
	 *
	 * @param int $lifetime cache lifetime, in seconds. Set to 0 for unlimited
	 * @return self
	 */
	public function set_cache_lifetime( $lifetime ) {
		$this->cache_lifetime = $lifetime;
		return $this;
	}


	/**
	 * Gets the cache lifetime for this request.
	 *
	 * @return int
	 */
	public function get_cache_lifetime() {
		return $this->cache_lifetime;
	}


	/**
	 * Sets whether a fresh request should be attempted, regardless if a cached response is available.
	 *
	 * @param bool $value whether to force a fresh request, or not
	 * @return self
	 */
	public function set_force_refresh( $value ) {
		$this->force_refresh = $value;
		return $this;
	}


	/**
	 * Determines whether a fresh request should be attempted.
	 *
	 * @return bool
	 */
	public function should_refresh() {
		return $this->force_refresh;
	}


	/**
	 * Sets whether the request's response should be stored in cache.
	 *
	 * @param bool $value whether to cache the request, or not
	 * @return self
	 */
	public function set_should_cache( $value ) {
		$this->should_cache = $value;
		return $this;
	}


	/**
	 * Determines whether the request's response should be stored in cache.
	 *
	 * @return bool
	 */
	public function should_cache() {
		return $this->should_cache;
	}


	/**
	 * Bypasses caching for this request completely.
	 * When called, sets the `force_refresh` flag to true and `should_cache` flag to false
	 *
	 * @return self
	 */
	public function bypass_cache() {

		$this->set_force_refresh( true );
		$this->set_should_cache( false );

		return $this;
	}

}

endif;
