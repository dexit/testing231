<?php
/**
 * PHP Code Validator.
 *
 * Validates custom PHP code written in microplugins for syntax errors
 * and security issues before it is saved to the cache.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes/functions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates microplugin PHP code for security and syntax issues.
 *
 * @since 1.0.0
 */
class CEM_Code_Validator {

	/**
	 * Functions that may not appear in microplugin code.
	 */
	private const BLACKLIST = array(
		'eval',
		'exec',
		'system',
		'passthru',
		'shell_exec',
		'popen',
		'proc_open',
		'pcntl_exec',
		'file_put_contents',
		'fwrite',
		'file',
		'fopen',
		'tmpfile',
		'tempnam',
		'unlink',
		'rmdir',
		'mkdir',
		'chmod',
		'chown',
		'chgrp',
		'curl_exec',
		'curl_multi_exec',
		'fsockopen',
		'pfsockopen',
		'stream_socket_client',
		'stream_socket_server',
		'mail',
		'wp_mail',
		'wp_remote_request',
		'wp_remote_get',
		'wp_remote_post',
		'include',
		'include_once',
		'require',
		'require_once',
		'__halt_compiler',
		'create_function',
		'assert',
	);

	/**
	 * Validate PHP code for a microplugin.
	 *
	 * @since 1.0.0
	 * @param string $code          Raw PHP code.
	 * @param string $function_name Expected callback function name.
	 * @return array{valid: bool, error?: string, details?: array}
	 */
	public function validate( string $code, string $function_name ): array {
		$blacklist = $this->check_blacklist( $code );
		if ( ! $blacklist['valid'] ) {
			return $blacklist;
		}

		$patterns = $this->check_suspicious_patterns( $code );
		if ( ! $patterns['valid'] ) {
			return $patterns;
		}

		$syntax = $this->check_syntax( $code );
		if ( ! $syntax['valid'] ) {
			return $syntax;
		}

		$func_check = $this->check_function_exists( $code, $function_name );
		if ( ! $func_check['valid'] ) {
			return $func_check;
		}

		return array( 'valid' => true );
	}

	/**
	 * Check that no blacklisted function calls exist in the code.
	 *
	 * @since 1.0.0
	 * @param string $code Raw PHP code.
	 * @return array{valid: bool, error?: string, details?: array}
	 */
	private function check_blacklist( string $code ): array {
		foreach ( self::BLACKLIST as $func ) {
			if ( preg_match( '/\b' . preg_quote( $func, '/' ) . '\s*\(/i', $code ) ) {
				return array(
					'valid'   => false,
					'error'   => sprintf(
						/* translators: %s: forbidden PHP function name */
						__( 'Forbidden function "%s" found in code.', 'custom-endpoints-manager' ),
						$func
					),
					'details' => array(
						'function' => $func,
						'reason'   => 'blacklist',
					),
				);
			}
		}
		return array( 'valid' => true );
	}

	/**
	 * Check for suspicious code patterns that indicate obfuscation or misuse.
	 *
	 * @since 1.0.0
	 * @param string $code Raw PHP code.
	 * @return array{valid: bool, error?: string, details?: array}
	 */
	private function check_suspicious_patterns( string $code ): array {
		$patterns = array(
			'/\$\{/i'          => 'Variable variables',
			'/`[^`]+`/i'       => 'Backtick execution',
			'/base64_decode/i' => 'Base64 decode (obfuscation)',
			'/str_rot13/i'     => 'ROT13 (obfuscation)',
			'/gzinflate/i'     => 'gzinflate (obfuscation)',
			'/\$_(?:GET|POST|REQUEST|COOKIE|SERVER)\s*\[/i' => 'Direct superglobal access — use $request->get_param() instead',
		);

		foreach ( $patterns as $pattern => $description ) {
			if ( preg_match( $pattern, $code ) ) {
				return array(
					'valid'   => false,
					'error'   => sprintf(
						/* translators: %s: description of the detected suspicious pattern */
						__( 'Suspicious pattern detected: %s', 'custom-endpoints-manager' ),
						$description
					),
					'details' => array(
						'pattern' => $description,
						'reason'  => 'suspicious_pattern',
					),
				);
			}
		}
		return array( 'valid' => true );
	}

	/**
	 * Lint the code via `php -l` to catch syntax errors before saving.
	 *
	 * @since 1.0.0
	 * @param string $code Raw PHP code.
	 * @return array{valid: bool, error?: string, details?: array}
	 */
	private function check_syntax( string $code ): array {
		// Ensure PHP opening tag is present for the linter.
		$lint_code = 0 === strpos( ltrim( $code ), '<?php' )
			? $code
			: "<?php\n" . $code;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam -- generating a local temp path for PHP linting.
		$temp = tempnam( sys_get_temp_dir(), 'cem_validate_' );
		if ( ! $temp ) {
			// Cannot lint; allow save — runtime will catch errors.
			return array( 'valid' => true );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing to an ephemeral temp file for PHP linting only.
		file_put_contents( $temp, $lint_code );

		$output      = array();
		$return_code = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- intentional: PHP lint via CLI; no user input reaches the shell.
		exec( 'php -l ' . escapeshellarg( $temp ) . ' 2>&1', $output, $return_code );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing own temp file immediately after linting.
		unlink( $temp );

		if ( 0 !== $return_code ) {
			return array(
				'valid'   => false,
				'error'   => __( 'PHP syntax error in code.', 'custom-endpoints-manager' ),
				'details' => array(
					'output' => implode( "\n", $output ),
					'reason' => 'syntax',
				),
			);
		}

		return array( 'valid' => true );
	}

	/**
	 * Verify that the expected callback function is declared in the code.
	 *
	 * @since 1.0.0
	 * @param string $code          Raw PHP code.
	 * @param string $function_name Expected callback function name.
	 * @return array{valid: bool, error?: string, details?: array}
	 */
	private function check_function_exists( string $code, string $function_name ): array {
		$pattern = '/function\s+' . preg_quote( $function_name, '/' ) . '\s*\(/';
		if ( ! preg_match( $pattern, $code ) ) {
			return array(
				'valid'   => false,
				'error'   => sprintf(
					/* translators: %s: expected PHP callback function name */
					__( 'Function "%s" not found in code.', 'custom-endpoints-manager' ),
					$function_name
				),
				'details' => array(
					'function_name' => $function_name,
					'reason'        => 'missing_function',
				),
			);
		}
		return array( 'valid' => true );
	}
}
