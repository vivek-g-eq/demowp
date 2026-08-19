<?php
/**
 * Core Cloudflare - Encryptor
 *
 * @package Core_Cloudflare
 */

declare( strict_types=1 );

namespace Core_Cloudflare;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Encryptor
 *
 * Single Responsibility: encrypt/decrypt short secret strings (API
 * tokens) for storage at rest, using key material derived from this
 * WordPress installation's own AUTH_KEY / SECURE_AUTH_KEY salts.
 *
 * This does NOT protect against someone who already has full server
 * (and therefore wp-config.php) access — that is not a threat model
 * any application-layer encryption can solve. What it DOES protect
 * against is the much more common case of database-only exposure
 * (a leaked DB backup, a read-only DB credential, a SQL injection
 * elsewhere on the site, a careless third-party plugin querying
 * wp_sitemeta) — in all of those cases the stored value is useless
 * without wp-config.php's secret keys.
 */
final class Encryptor {

	private const CIPHER = 'aes-256-cbc';
	private const PREFIX = 'cc_enc_v1:';

	/**
	 * Derive a stable 32-byte encryption key from WordPress's own
	 * secret keys/salts. These live only in wp-config.php (or the
	 * environment), never in the database, so this key material is
	 * unavailable to anyone with database-only access.
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function key(): string {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
			. ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );

		if ( '' === $material ) {
			// Extremely unlikely on a real WordPress install, but fail safe
			// rather than encrypting with an empty/predictable key.
			$material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		}

		return hash( 'sha256', $material, true );
	}

	/**
	 * Encrypt a plaintext string for storage.
	 *
	 * @param string $plaintext Value to encrypt. Empty string passes through unchanged.
	 * @return string Encrypted, base64-safe string prefixed with a version marker.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext || ! extension_loaded( 'openssl' ) ) {
			return $plaintext;
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$iv        = openssl_random_pseudo_bytes( $iv_length );

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return $plaintext;
		}

		return self::PREFIX . base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypt a value previously produced by encrypt(). Values that
	 * don't carry our version prefix are returned unchanged, so plain
	 * (legacy/unencrypted) values already in the database still work
	 * transparently instead of breaking.
	 *
	 * @param string $stored Value as stored in the database.
	 * @return string Decrypted plaintext, or the original value if it wasn't encrypted / decryption failed.
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored || 0 !== strpos( $stored, self::PREFIX ) || ! extension_loaded( 'openssl' ) ) {
			return $stored;
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$iv        = substr( $raw, 0, $iv_length );
		$ciphertext = substr( $raw, $iv_length );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Whether a stored value is in encrypted form (useful for diagnostics).
	 *
	 * @param string $stored Value as stored in the database.
	 * @return bool
	 */
	public static function is_encrypted( string $stored ): bool {
		return 0 === strpos( $stored, self::PREFIX );
	}
}
