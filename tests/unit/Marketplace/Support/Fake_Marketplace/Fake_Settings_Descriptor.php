<?php
/**
 * Fake settings descriptor and push admin for marketplace unit tests.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Settings\Credential_Field_Spec;
use Woodev\Framework\Marketplace\Settings\Push_Admin;
use Woodev\Framework\Marketplace\Settings\Settings_Descriptor;

/**
 * In-memory {@see Settings_Descriptor} used by {@see \Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter}.
 */
final class Fake_Settings_Descriptor implements Settings_Descriptor {
	/**
	 * Credential fields of the owning adapter.
	 *
	 * @var Credential_Fields
	 */
	private $fields;

	/**
	 * Marketplace display name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Custom field specs keyed by field name.
	 *
	 * @var array<string,Credential_Field_Spec>
	 */
	private $specs;

	/**
	 * Optional push admin factory (`function ( int $account_id ): ?Push_Admin`).
	 *
	 * @var callable|null
	 */
	private $push_factory;

	/**
	 * Default IP allowlist.
	 *
	 * @var string
	 */
	private $allowlist;

	/**
	 * Creates the fake descriptor.
	 *
	 * @param Credential_Fields                   $fields       Credential fields.
	 * @param string                              $name         Marketplace display name.
	 * @param array<string,Credential_Field_Spec> $specs        Optional explicit field specs.
	 * @param callable|null                       $push_factory Optional `function ( int $account_id ): ?Push_Admin`.
	 * @param string                              $allowlist    Default IP allowlist.
	 */
	public function __construct(
		Credential_Fields $fields,
		string $name = 'Fake Seller',
		array $specs = [],
		?callable $push_factory = null,
		string $allowlist = ''
	) {
		$this->fields       = $fields;
		$this->name         = $name;
		$this->specs        = $specs;
		$this->push_factory = $push_factory;
		$this->allowlist    = $allowlist;
	}

	/** @inheritDoc */
	public function marketplace_name(): string {
		return $this->name;
	}

	/** @inheritDoc */
	public function field_spec( string $field_key ): Credential_Field_Spec {
		if ( isset( $this->specs[ $field_key ] ) ) {
			return $this->specs[ $field_key ];
		}

		$is_id = $field_key === $this->fields->identifier();

		return new Credential_Field_Spec(
			$is_id ? 'ID кабинета (' . $field_key . ')' : 'Секрет (' . $field_key . ')',
			$is_id ? 'Идентификатор продавца' : 'Секретный ключ доступа'
		);
	}

	/** @inheritDoc */
	public function credentials_help(): string {
		return 'Учётные данные создаются в настройках личного кабинета маркетплейса.';
	}

	/** @inheritDoc */
	public function key_renewal_advice(): string {
		return 'Выпустите новый ключ в личном кабинете маркетплейса и сохраните его в настройках.';
	}

	/** @inheritDoc */
	public function unreadable_secrets_message(): string {
		return 'Сохранённый ключ не удалось расшифровать после смены ключей безопасности WordPress. Введите ключ заново.';
	}

	/** @inheritDoc */
	public function health_hint( string $status ): string {
		return 'Проверьте ключ и права доступа в личном кабинете (' . $status . ').';
	}

	/** @inheritDoc */
	public function default_ip_allowlist(): string {
		return $this->allowlist;
	}

	/** @inheritDoc */
	public function push_admin( int $account_id ): ?Push_Admin {
		if ( null === $this->push_factory ) {
			return null;
		}

		$admin = call_user_func( $this->push_factory, $account_id );

		return $admin instanceof Push_Admin ? $admin : null;
	}
}
