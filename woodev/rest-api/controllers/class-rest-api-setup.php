<?php
/**
 * Setup wizard REST controller (woodev/v1).
 *
 * @package Woodev\Framework\REST
 */

use Woodev\Framework\Setup\Action_Outcome;
use Woodev\Framework\Setup\Callback_Failure;
use Woodev\Framework\Setup\Step;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_REST_API_Setup' ) ) :

	/**
	 * Serves the wizard bootstrap, persists per-step values, runs step actions and finalizes setup.
	 *
	 * Registered through Woodev_REST_V1_Registrar (neutral woodev/v1 namespace).
	 *
	 * @since 2.0.2
	 */
	class Woodev_REST_API_Setup {

		/**
		 * Wizard handler.
		 *
		 * @since 2.0.2
		 *
		 * @var \Woodev\Framework\Setup\Setup_Wizard
		 */
		private $wizard;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev\Framework\Setup\Setup_Wizard $wizard wizard handler.
		 */
		public function __construct( $wizard ) {
			$this->wizard = $wizard;
		}

		/**
		 * Registers the wizard routes.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_routes(): void {
			$id   = $this->wizard->get_id();
			$base = Woodev_REST_V1_Registrar::ROUTE_NAMESPACE;

			register_rest_route(
				$base,
				"/{$id}/setup/steps/(?P<step_id>[\\w-]+)",
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'save_step' ],
					'permission_callback' => [ $this, 'permissions_check' ],
				]
			);

			// A step action: a server-side operation bound to a step (D2). A new route next to
			// the shipped ones — the save and complete routes above and below are installed-site contracts.
			register_rest_route(
				$base,
				"/{$id}/setup/steps/(?P<step_id>[\\w-]+)/actions/(?P<action_id>[\\w-]+)",
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'run_action' ],
					'permission_callback' => [ $this, 'permissions_check' ],
				]
			);

			register_rest_route(
				$base,
				"/{$id}/setup/complete",
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'complete' ],
					'permission_callback' => [ $this, 'permissions_check' ],
				]
			);
		}

		/**
		 * Capability gate mirroring the wizard page.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function permissions_check(): bool {
			return current_user_can( $this->wizard->get_required_capability() );
		}

		/**
		 * Resolves the step a request addresses.
		 *
		 * The single choke point for "may this request touch this step": today a step must
		 * be registered and visible at wizard build time; the server-side step-graph
		 * recompute (D3) refuses a step hidden in the CURRENT graph here, for the save and
		 * the action route alike.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return Step|\WP_Error
		 */
		private function resolve_step( $request ) {
			$step_id = (string) $request->get_param( 'step_id' );
			$step    = $this->wizard->get_steps()[ $step_id ] ?? null;

			if ( null === $step ) {
				return new WP_Error(
					'woodev_setup_unknown_step',
					__( 'Неизвестный шаг.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			return $step;
		}

		/**
		 * The values of one step as the server must see them.
		 *
		 * - `submitted`: what the client sent (the fields the merchant changed), minus fields
		 *   hidden by their show_if conditions and minus anything not declared on the step. This
		 *   is what gets PERSISTED (dirty-only: an untouched field is never rewritten).
		 * - `effective`: the same, overlaid on the STORED (else default) value of every other
		 *   declared field — what the merchant actually sees on the step. This is what a
		 *   validation callback and an action work on, so a stored API key the merchant did not
		 *   retype still counts and a cross-field rule sees both fields. Hidden fields are
		 *   dropped from it against the effective controlling values (the same rule the client
		 *   applies).
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @param Step             $step    the addressed step.
		 * @return array{submitted: array<string,mixed>, effective: array<string,mixed>}
		 */
		private function get_step_values( $request, Step $step ): array {
			$declared  = array_flip( $step->get_setting_ids() );
			$submitted = array_intersect_key( (array) $request->get_param( 'values' ), $declared );
			$handler   = $this->wizard->get_plugin()->get_settings_handler();

			if ( ! $handler ) {
				return [
					'submitted' => $submitted,
					'effective' => $submitted,
				];
			}

			$stored = [];
			foreach ( array_keys( $declared ) as $sid ) {
				if ( ! array_key_exists( $sid, $submitted ) ) {
					try {
						$stored[ $sid ] = $handler->get_value( $sid );
					} catch ( \Woodev_Plugin_Exception $e ) {
						// A declared id the handler does not know: nothing stored to overlay.
						continue;
					}
				}
			}

			// Drop fields hidden by their show_if conditions — never validated, never persisted.
			$merged = [];
			foreach ( array_keys( $declared ) as $sid ) {
				if ( array_key_exists( $sid, $submitted ) ) {
					$merged[ $sid ] = $submitted[ $sid ];
				} elseif ( array_key_exists( $sid, $stored ) ) {
					$merged[ $sid ] = $stored[ $sid ];
				}
			}

			$effective = $handler->filter_visible_values( $merged );

			return [
				'submitted' => array_intersect_key( $effective, $submitted ),
				'effective' => $effective,
			];
		}

		/**
		 * The generic 500 the browser gets for any unexpected failure.
		 *
		 * @since 2.0.2
		 *
		 * @param string $code error code.
		 * @return \WP_Error
		 */
		private function server_error( string $code ): \WP_Error {
			return new WP_Error(
				$code,
				__( 'Внутренняя ошибка сервера. Попробуйте ещё раз.', 'woodev-plugin-framework' ),
				[ 'status' => 500 ]
			);
		}

		/**
		 * Normalises what a step validation callback returned.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $result callback return.
		 * @return array<string,string>|null null when the values are valid; otherwise the
		 *                                   (possibly empty) field id => message map of a refusal.
		 */
		private function normalise_validation_result( $result ): ?array {
			if ( false === $result ) {
				return [];
			}

			if ( ! is_array( $result ) || [] === $result ) {
				return null;
			}

			$errors = [];
			foreach ( $result as $field => $message ) {
				if ( is_scalar( $message ) && '' !== (string) $message ) {
					$errors[ (string) $field ] = (string) $message;
				}
			}

			return $errors;
		}

		/**
		 * Validates + persists one step's values, then runs the optional on_save.
		 *
		 * Order: the step's validation callback (if any) runs FIRST, before anything is
		 * persisted, on the step's EFFECTIVE values (the edits over the stored values — see
		 * get_step_values()); only the edited fields are then persisted. When it refuses, nothing is saved and on_save does not run; the answer is
		 * a WP_Error `woodev_setup_invalid` (HTTP 400) whose data carries `errors`, a map of
		 * field id => message. Then each setting is persisted as it passes the settings
		 * handler's validation: if setting N fails, settings 0..N-1 are already saved. This is
		 * intentional and idempotent — re-submitting the step overwrites any already-saved
		 * values. Settings are persisted BEFORE on_save; a thrown on_save reports an error
		 * while settings are already saved (on_save must therefore be idempotent too).
		 *
		 * Failure contract (#1048, audit #6). Anything the plugin's callbacks throw — an
		 * Exception or an Error, any \Throwable — is caught, logged through error_log() with
		 * secrets masked by Woodev_API_Base::redact_secret_log_text(), and answered with a
		 * generic translated HTTP 500 WP_Error; the throwable's own message never reaches the
		 * browser. A throwing validation callback persists nothing. After a throwing on_save:
		 *
		 * - every setting of the step that was submitted and valid IS persisted
		 *   (the step's values are written before on_save runs, and are not rolled back);
		 * - on_save may have done part of its own work — the framework cannot know
		 *   how far it got and does not undo it;
		 * - a retry is safe exactly when on_save is idempotent, which register_step()
		 *   already requires of it: the retry re-writes the same settings and re-runs
		 *   the callback from the top. A callback with a non-repeatable side effect
		 *   (creates a remote account, sends a message) is NOT made safe by this catch.
		 *
		 * A validation failure of a setting (Woodev_Plugin_Exception from the settings
		 * handler) is a different path: it carries a one-entry `errors` map (the first failing
		 * setting) and the handler's own message, and on_save does not run.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response|\WP_Error|array<string,mixed>
		 */
		public function save_step( $request ) {
			$step = $this->resolve_step( $request );

			if ( $step instanceof WP_Error ) {
				return $step;
			}

			$step_id   = $step->get_id();
			$handler   = $this->wizard->get_plugin()->get_settings_handler();
			$step_vals = $this->get_step_values( $request, $step );
			$values    = $step_vals['submitted'];

			$validate = $step->get_validation_callback();
			if ( null !== $validate ) {
				try {
					$errors = $this->normalise_validation_result( call_user_func( $validate, $step_vals['effective'], $request ) );
				} catch ( \Throwable $e ) {
					Callback_Failure::log( sprintf( 'validation failed for step "%s"', $step_id ), $e );

					return $this->server_error( 'woodev_setup_step_failed' );
				}

				if ( null !== $errors ) {
					return new WP_Error(
						'woodev_setup_invalid',
						__( 'Проверьте правильность заполнения полей на этом шаге.', 'woodev-plugin-framework' ),
						[
							'status' => 400,
							'errors' => $errors,
						]
					);
				}
			}

			if ( $handler ) {
				foreach ( $step->get_setting_ids() as $sid ) {
					if ( array_key_exists( $sid, $values ) ) {
						try {
							// update_value() validates (throws Woodev_Plugin_Exception) AND persists.
							$handler->update_value( $sid, $values[ $sid ] );
						} catch ( \Woodev_Plugin_Exception $e ) {
							// Issue #397: `errors`, a MAP of setting id => message — the shape the
							// client reads (`err.data.errors`) and this framework's other settings
							// surface returns. One entry: the loop returns on the first failing
							// setting, and collecting every failure would change what is persisted
							// before the refusal.
							return new WP_Error(
								'woodev_setup_invalid',
								$e->getMessage(),
								[
									'status' => $e->getCode() ?: 400,
									'errors' => [ $sid => $e->getMessage() ],
								]
							);
						} catch ( \Throwable $e ) {
							// Unexpected failure (e.g. a third-party hook on update_option threw):
							// log for traceability and return a generic 500 — never leak internals.
							Callback_Failure::log( sprintf( 'save_step failed on "%s"', $sid ), $e );

							return $this->server_error( 'woodev_setup_server_error' );
						}
					}
				}
			}

			$on_save = $step->get_on_save();
			if ( is_callable( $on_save ) ) {
				// Hand the callback only the values for fields declared on this step —
				// never arbitrary extra keys a crafted request may have included.
				$step_values = array_intersect_key( $values, array_flip( $step->get_setting_ids() ) );
				try {
					call_user_func( $on_save, $step_values, $request );
				} catch ( \Throwable $e ) {
					// on_save is the plugin's own (untrusted) callback and may throw an Error
					// (TypeError, ArgumentCountError…) as readily as an Exception. Log the
					// secret-redacted detail and hand the browser only a generic message — a
					// raw message can carry a credential or an internal path. Settings are
					// already persisted at this point (see the docblock).
					Callback_Failure::log( sprintf( 'on_save failed for step "%s"', $step_id ), $e );

					return $this->server_error( 'woodev_setup_step_failed' );
				}
			}

			return rest_ensure_response(
				[
					'saved' => true,
					'step' => $step_id,
				]
			);
		}

		/**
		 * Runs one step action and answers its structured result.
		 *
		 * The callback gets the step's EFFECTIVE values (the submitted, unsaved edits over the
		 * stored values — declared fields only, see get_step_values()) and the request; nothing is persisted unless the callback persists it itself. A callback
		 * returns an Action_Outcome — `success` or `error` + message + optional data — and that
		 * (HTTP 200) is the whole answer for a business outcome, positive or negative. A
		 * throw, or a return of anything else, is an unexpected failure: logged (secrets
		 * masked), answered with a generic HTTP 500 WP_Error.
		 *
		 * A destructive action runs only when the request says `confirmed: true` — the client
		 * sets it after the merchant confirmed; an unconfirmed run is refused with HTTP 400.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response|\WP_Error|array<string,mixed>
		 */
		public function run_action( $request ) {
			$step = $this->resolve_step( $request );

			if ( $step instanceof WP_Error ) {
				return $step;
			}

			$action = $step->get_action( (string) $request->get_param( 'action_id' ) );

			if ( null === $action ) {
				return new WP_Error(
					'woodev_setup_unknown_action',
					__( 'Неизвестное действие.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			if ( $action->is_destructive() && ! filter_var( $request->get_param( 'confirmed' ), FILTER_VALIDATE_BOOLEAN ) ) {
				return new WP_Error(
					'woodev_setup_confirmation_required',
					__( 'Это действие нужно подтвердить.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			$values = $this->get_step_values( $request, $step )['effective'];

			try {
				$result = call_user_func( $action->get_callback(), $values, $request );

				if ( ! $result instanceof Action_Outcome ) {
					throw new \UnexpectedValueException( 'A setup wizard action must return a Woodev\Framework\Setup\Action_Outcome.' );
				}
			} catch ( \Throwable $e ) {
				Callback_Failure::log( sprintf( 'action "%s" failed for step "%s"', $action->get_id(), $step->get_id() ), $e );

				return $this->server_error( 'woodev_setup_action_failed' );
			}

			return rest_ensure_response( $result->to_array() );
		}

		/**
		 * Finalizes the wizard (server-side authority).
		 *
		 * The state only moves forward (D1): a `skipped` request after `completed` is not an
		 * error and changes nothing; the answer carries the state actually in force.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response|\WP_Error|array<string,mixed>
		 */
		public function complete( $request ) {
			$requested = 'skipped' === $request->get_param( 'state' ) ? 'skipped' : 'completed';

			try {
				$state = $this->wizard->complete_setup( $requested );
			} catch ( \Throwable $e ) {
				// Never report success if the completion option was not persisted.
				Callback_Failure::log( 'complete failed', $e );

				return new WP_Error(
					'woodev_setup_complete_failed',
					__( 'Не удалось сохранить статус настройки. Попробуйте ещё раз.', 'woodev-plugin-framework' ),
					[ 'status' => 500 ]
				);
			}

			return rest_ensure_response(
				[
					'complete' => true,
					'state' => $state,
				]
			);
		}
	}

endif;
