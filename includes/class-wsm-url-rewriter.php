<?php
/**
 * Serialization-aware URL/domain rewriter.
 *
 * @package WPSiteMigrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSM_URL_Rewriter {
	/**
	 * Replacement pairs.
	 *
	 * @var array
	 */
	private $replacements = array();

	/**
	 * Constructor.
	 *
	 * @param array  $source_urls Source URLs.
	 * @param string $target_url Target URL.
	 */
	public function __construct( array $source_urls, $target_url ) {
		$this->replacements = $this->build_replacements( $source_urls, $target_url );
	}

	/**
	 * Rewrite any scalar/array/object value.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public function rewrite_value( $value ) {
		if ( is_string( $value ) ) {
			return $this->rewrite_string( $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->rewrite_value( $item );
			}

			return $value;
		}

		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$value->{$key} = $this->rewrite_value( $item );
			}

			return $value;
		}

		return $value;
	}

	/**
	 * Rewrite a string while preserving serialized payloads.
	 *
	 * @param string $value String.
	 * @return string
	 */
	public function rewrite_string( $value ) {
		if ( '' === $value ) {
			return $value;
		}

		if ( function_exists( 'is_serialized' ) && is_serialized( $value ) ) {
			try {
				$unserialized = @unserialize( trim( $value ), array( 'allowed_classes' => false ) );
				if ( false !== $unserialized || 'b:0;' === trim( $value ) ) {
					return serialize( $this->rewrite_value( $unserialized ) );
				}
			} catch ( Throwable $e ) {
				return strtr( $value, $this->replacements );
			}
		}

		$trimmed = trim( $value );
		if ( $this->looks_like_json( $trimmed ) ) {
			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$rewritten = $this->rewrite_value( $decoded );
				$encoded   = wp_json_encode( $rewritten, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( false !== $encoded ) {
					return $encoded;
				}
			}
		}

		return strtr( $value, $this->replacements );
	}

	/**
	 * Rewrite a text file in place.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function rewrite_text_file( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) || ! is_writable( $path ) ) {
			return false;
		}

		$contents = file_get_contents( $path );
		if ( false === $contents || $this->is_binary_string( $contents ) ) {
			return false;
		}

		$rewritten = strtr( $contents, $this->replacements );
		if ( $rewritten === $contents ) {
			return true;
		}

		return false !== file_put_contents( $path, $rewritten, LOCK_EX );
	}

	/**
	 * Build replacement pairs.
	 *
	 * @param array  $source_urls Source URLs.
	 * @param string $target_url Target URL.
	 * @return array
	 */
	private function build_replacements( array $source_urls, $target_url ) {
		$target_url = untrailingslashit( esc_url_raw( $target_url ) );
		$pairs      = array();

		foreach ( $source_urls as $source_url ) {
			$source_url = untrailingslashit( esc_url_raw( $source_url ) );
			if ( '' === $source_url || $source_url === $target_url ) {
				continue;
			}

			$this->add_pair( $pairs, $source_url, $target_url );
			$this->add_pair( $pairs, trailingslashit( $source_url ), trailingslashit( $target_url ) );
			$this->add_pair( $pairs, str_replace( '/', '\\/', $source_url ), str_replace( '/', '\\/', $target_url ) );
			$this->add_pair( $pairs, rawurlencode( $source_url ), rawurlencode( $target_url ) );

			$source_parts = wp_parse_url( $source_url );
			$target_parts = wp_parse_url( $target_url );
			if ( ! empty( $source_parts['host'] ) && ! empty( $target_parts['host'] ) ) {
				$source_path = isset( $source_parts['path'] ) ? untrailingslashit( $source_parts['path'] ) : '';
				$target_path = isset( $target_parts['path'] ) ? untrailingslashit( $target_parts['path'] ) : '';

				$this->add_pair( $pairs, '//' . $source_parts['host'] . $source_path, '//' . $target_parts['host'] . $target_path );
				$this->add_pair( $pairs, str_replace( '/', '\\/', '//' . $source_parts['host'] . $source_path ), str_replace( '/', '\\/', '//' . $target_parts['host'] . $target_path ) );

				if ( $source_parts['host'] !== $target_parts['host'] ) {
					$this->add_pair( $pairs, $source_parts['host'], $target_parts['host'] );
				}
			}
		}

		uksort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		return $pairs;
	}

	/**
	 * Add a replacement pair.
	 *
	 * @param array  $pairs Pairs.
	 * @param string $from From.
	 * @param string $to To.
	 * @return void
	 */
	private function add_pair( array &$pairs, $from, $to ) {
		if ( '' !== $from && $from !== $to ) {
			$pairs[ $from ] = $to;
		}
	}

	/**
	 * Check if a string looks like JSON.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function looks_like_json( $value ) {
		if ( '' === $value ) {
			return false;
		}

		$first = substr( $value, 0, 1 );
		$last  = substr( $value, -1 );

		return ( '{' === $first && '}' === $last ) || ( '[' === $first && ']' === $last );
	}

	/**
	 * Detect binary contents.
	 *
	 * @param string $contents Contents.
	 * @return bool
	 */
	private function is_binary_string( $contents ) {
		return false !== strpos( substr( $contents, 0, 8192 ), "\0" );
	}
}
