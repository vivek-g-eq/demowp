<?php
/**
 * Handles outbound communication with the AI provider.
 *
 * @package AI_Blog_Writer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AIBW_API_Handler
 *
 * Talks to an OpenAI-compatible Chat Completions endpoint and
 * returns a clean, ready-to-save title/content pair.
 */
class AIBW_API_Handler {

	/**
	 * Request a generated blog post from the AI.
	 *
	 * @param string $topic    The blog topic.
	 * @param string $keywords Comma separated focus keywords.
	 * @param string $tone     Desired writing tone.
	 * @param string $length   Desired length: short|medium|long.
	 * @return array|WP_Error {
	 *     @type string $title   Post title.
	 *     @type string $content Post content as HTML.
	 * }
	 */
	public function generate_content( $topic, $keywords, $tone, $length ) {
		$api_key = AIBW_Settings::get( 'api_key' );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'aibw_missing_key',
				esc_html__( 'AI API key is not configured. Please add it under AI Blog Writer > Settings.', 'ai-blog-writer' )
			);
		}

		$prompt = $this->build_prompt( $topic, $keywords, $tone, $length );

		$request_body = array(
			'model'       => AIBW_Settings::get( 'api_model', 'gpt-4o-mini' ),
			'temperature' => 0.7,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => 'You are a professional blog writer and SEO expert. Always respond with valid JSON only, no extra commentary, no markdown fences.',
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);

		$endpoint = AIBW_Settings::get( 'api_endpoint', 'https://api.groq.com/openai/v1/chat/completions' );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_response( $response, $topic );
	}

	/**
	 * Build the instruction prompt sent to the model.
	 *
	 * @param string $topic    Blog topic.
	 * @param string $keywords Focus keywords.
	 * @param string $tone     Writing tone.
	 * @param string $length   Length option key.
	 * @return string
	 */
	private function build_prompt( $topic, $keywords, $tone, $length ) {
		$word_count_map = array(
			'short'  => '300-500',
			'medium' => '600-900',
			'long'   => '1200-1600',
		);

		$target_words = isset( $word_count_map[ $length ] ) ? $word_count_map[ $length ] : $word_count_map['medium'];

		return sprintf(
			'Write a well-structured, SEO-friendly blog post about "%1$s". Include these keywords naturally where relevant: %2$s. Tone: %3$s. Target length: %4$s words. Respond strictly as JSON with exactly two keys: "title" (string) and "content" (an HTML string using <h2>, <p>, and <ul> tags only, no markdown syntax).',
			$topic,
			$keywords,
			$tone,
			$target_words
		);
	}

	/**
	 * Parse and validate the raw HTTP response from the API.
	 *
	 * @param array  $response wp_remote_post() response array.
	 * @param string $topic    Original topic, used as a title fallback.
	 * @return array|WP_Error
	 */
	private function parse_response( $response, $topic ) {
		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $raw_body, true );

		if ( 200 !== (int) $status_code ) {
			$message = isset( $data['error']['message'] )
				? sanitize_text_field( $data['error']['message'] )
				: esc_html__( 'Unknown error occurred while contacting the AI provider.', 'ai-blog-writer' );

			return new WP_Error( 'aibw_api_error', $message );
		}

		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'aibw_empty_response',
				esc_html__( 'The AI did not return any content. Please try again.', 'ai-blog-writer' )
			);
		}

		$raw_content = $data['choices'][0]['message']['content'];
		$parsed      = json_decode( $raw_content, true );

		if ( ! is_array( $parsed ) || empty( $parsed['title'] ) || empty( $parsed['content'] ) ) {
			// Fallback: model did not return clean JSON. Use topic as title.
			return array(
				'title'   => $topic,
				'content' => $raw_content,
			);
		}

		return array(
			'title'   => $parsed['title'],
			'content' => $parsed['content'],
		);
	}
}
