<?php
/**
 * Woodev Pickup Point Selection Result
 *
 * The shape a pickup-point selection round-trip is built from: the framework's own
 * verdict (computed via {@see Constraint_Checker}), plus room for a plugin's domain
 * filter to answer with UI advice the framework cannot know on its own.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Selection_Result' ) ) :

	/**
	 * Builds and sanitises the array shape returned by the `POST .../select` route.
	 *
	 * `allowed`/`reason` are the VERDICT: whether this point may be selected, and why not.
	 * `close`/`refresh_checkout`/`point` are ADVICE the domain may volunteer on top of that
	 * verdict — whether the modal should close, whether the checkout should refresh, and a
	 * possibly-corrected point payload. Advice is deliberately THREE-STATE (`bool|null` for
	 * the flags): `null` means "the domain did not speak on this", which tells the browser
	 * to fall back to the plugin's own configured default rather than to a hard-coded one.
	 * An explicit `false` is a decision — "do not close", "do not refresh" — and collapsing
	 * it into `null` would silently turn a deliberate refusal into "no opinion", handing
	 * control back to a default the domain just overrode. Every producer and consumer of
	 * this shape must preserve that distinction; this class exists to make losing it a
	 * class of bug that cannot happen by construction.
	 *
	 * @since 2.0.2
	 */
	class Selection_Result {

		/**
		 * Seeds a selection result from a {@see Constraint_Checker} verdict.
		 *
		 * Only the verdict is known at this point — no domain filter has run yet — so the
		 * three advice fields start `null` ("unspoken"), not `false`. Seeding them `false`
		 * would already be a decision nobody made; `null` correctly says "nothing to say yet".
		 *
		 * @since 2.0.2
		 *
		 * @param array{allowed: bool, reason: string|null} $verdict The computed verdict, as
		 *                                                            returned by
		 *                                                            {@see Constraint_Checker::check()}.
		 *
		 * @return array{
		 *     allowed: bool,
		 *     reason: string|null,
		 *     close: bool|null,
		 *     refresh_checkout: bool|null,
		 *     point: array<string, mixed>|null,
		 * }
		 */
		public static function from_verdict( array $verdict ): array {
			$reason = $verdict['reason'] ?? null;

			return [
				'allowed'          => (bool) ( $verdict['allowed'] ?? false ),
				'reason'           => is_string( $reason ) ? $reason : null,
				'close'            => null,
				'refresh_checkout' => null,
				'point'            => null,
			];
		}

		/**
		 * Validates a plugin domain filter's return and fails closed to the computed result.
		 *
		 * This is deliberately TWO TIERS, not one uniform fail-closed rule:
		 *
		 * `allowed`/`reason` are the verdict, and a filter is trusted with them only as a
		 * matched pair. If `$filtered` is not an array, or `allowed` is missing or not a real
		 * bool, or `reason` is present-but-wrongly-typed, the WHOLE result reverts to
		 * `$computed` — mirroring {@see Constraint_Checker::sanitize_verdict()}'s own
		 * discipline. A filter that cannot express a verdict correctly is not trusted with
		 * any part of it, because a half-adopted verdict (e.g. a real `reason` string paired
		 * with a bogus `allowed`) is worse than no override at all.
		 *
		 * `close`/`refresh_checkout`/`point` are advice, not the verdict, so a malformed one
		 * is normalised INDIVIDUALLY instead of taking the rest of the result down with it. A
		 * plugin author who typos `'close' => 'yes'` while correctly returning a genuine
		 * refusal `reason` must not have that refusal silently discarded — the customer still
		 * needs to read why the point was rejected, even though the typo cost them the intended
		 * auto-close behaviour. The flags fall back to `null` ("unspoken"), never to `false`:
		 * a typo is not a decision either.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed  $filtered The domain filter's return value.
		 * @param array{
		 *     allowed: bool,
		 *     reason: string|null,
		 *     close: bool|null,
		 *     refresh_checkout: bool|null,
		 *     point: array<string, mixed>|null,
		 * } $computed The pre-filter result, used as the fail-closed fallback for the verdict
		 *             tier.
		 *
		 * @return array{
		 *     allowed: bool,
		 *     reason: string|null,
		 *     close: bool|null,
		 *     refresh_checkout: bool|null,
		 *     point: array<string, mixed>|null,
		 * }
		 */
		public static function sanitize( $filtered, array $computed ): array {
			/*
			 * The four verdict-tier guards below are deliberately a COPY of
			 * {@see Constraint_Checker::sanitize_verdict()}'s, not a call to it. Reviewed and
			 * decided 2026-08-06 rather than left to chance:
			 *
			 * - that method is private, and sharing it would mean promoting an internal detail
			 *   to permanent public API on Constraint_Checker to save twelve lines;
			 * - the two guards validate the SAME two keys in service of DIFFERENT contracts —
			 *   a two-key verdict there, a five-key result here — so they are entitled to
			 *   diverge later (a structured `reason` here would not imply one there), and a
			 *   shared predicate would make that legitimate divergence impossible.
			 *
			 * What is NOT acceptable is diverging by ACCIDENT. If you change what counts as a
			 * well-formed verdict here, look at Constraint_Checker::sanitize_verdict() and
			 * decide explicitly whether it should follow; it carries the mirror of this note.
			 */
			if ( ! is_array( $filtered ) ) {
				return $computed;
			}

			if ( ! array_key_exists( 'allowed', $filtered ) || ! is_bool( $filtered['allowed'] ) ) {
				return $computed;
			}

			if ( ! array_key_exists( 'reason', $filtered ) ) {
				return $computed;
			}

			if ( null !== $filtered['reason'] && ! is_string( $filtered['reason'] ) ) {
				return $computed;
			}

			$point = $filtered['point'] ?? null;

			return [
				'allowed'          => $filtered['allowed'],
				'reason'           => $filtered['reason'],
				'close'            => self::sanitize_flag( $filtered['close'] ?? null ),
				'refresh_checkout' => self::sanitize_flag( $filtered['refresh_checkout'] ?? null ),
				'point'            => is_array( $point ) ? $point : null,
			];
		}

		/**
		 * Normalises a single advice flag: a real bool survives, including `false`; anything
		 * else — a missing key, `null`, a string, an int — becomes `null`.
		 *
		 * `false` is not "absent" and must not be conflated with it: `null` means the domain
		 * did not speak, `false` means it spoke and said no. A naive `(bool) $value` cast
		 * would turn every non-bool junk value into a confident `false`, indistinguishable
		 * from a deliberate refusal the browser is meant to trust; `null` is the only fallback
		 * that correctly reads as "fall back to the plugin's own default" instead.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value The raw flag value from a filter's return.
		 *
		 * @return bool|null
		 */
		private static function sanitize_flag( $value ): ?bool {
			return is_bool( $value ) ? $value : null;
		}
	}

endif;
