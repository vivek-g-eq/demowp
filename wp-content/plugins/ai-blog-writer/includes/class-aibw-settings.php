<?php
/**
 * Settings storage and retrieval.
 *
 * @package AI_Blog_Writer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AIBW_Settings
 *
 * Manages plugin settings, stored as a single serialized option
 * to keep the options table clean.
 */
class AIBW_Settings {

	/**
	 * Option key used in wp_options.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'aibw_settings';

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_all() {
		$defaults = array(
			'api_key'      => '',
			'api_endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
			'api_model'    => 'llama-3.3-70b-versatile',
			'default_tone' => 'professional',
		);

		$saved = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Fallback value if key not found.
	 * @return mixed
	 */
	public static function get( $key, $fallback = '' ) {
		$settings = self::get_all();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Sanitize a raw settings array before persisting.
	 *
	 * @param array $input Raw input, typically from $_POST.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( $input ) {
		$output = array();

		$output['api_key']      = isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '';
		$output['api_endpoint'] = isset( $input['api_endpoint'] ) ? esc_url_raw( $input['api_endpoint'] ) : 'https://api.groq.com/openai/v1/chat/completions';
		$output['api_model']    = isset( $input['api_model'] ) ? sanitize_text_field( $input['api_model'] ) : 'llama-3.3-70b-versatile';
		$output['default_tone'] = isset( $input['default_tone'] ) ? sanitize_text_field( $input['default_tone'] ) : 'professional';

		return $output;
	}

	/**
	 * Sanitize and persist settings.
	 *
	 * @param array $input Raw input to sanitize and save.
	 */
	public static function save( $input ) {
		update_option( self::OPTION_KEY, self::sanitize( $input ) );
	}
}
