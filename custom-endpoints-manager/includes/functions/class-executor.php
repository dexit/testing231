<?php
/**
 * Custom PHP Function Executor
 *
 * Sandboxed execution environment for microplugin callbacks.
 * Uses PHP namespace isolation + temp files (never eval).
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes/functions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CEM_Code_Executor {

    private const TIMEOUT_SECONDS    = 5;
    private const MEMORY_LIMIT_BYTES = 16 * 1024 * 1024; // 16 MB

    /**
     * Execute a microplugin callback in an isolated namespace.
     *
     * @param string           $function_name  Expected function name in the code.
     * @param string           $code           Raw PHP code from the microplugin.
     * @param WP_REST_Request  $request        The REST request (passed to the callback).
     * @return mixed Callback return value.
     * @throws Exception On validation failure, missing function, or runtime errors.
     */
    public function execute( string $function_name, string $code, WP_REST_Request $request ) {
        $validator  = new CEM_Code_Validator();
        $validation = $validator->validate( $code, $function_name );
        if ( ! $validation['valid'] ) {
            throw new Exception( $validation['error'] );
        }

        $old_memory_limit = ini_get( 'memory_limit' );
        $old_time_limit   = ini_get( 'max_execution_time' );

        ini_set( 'memory_limit', '16M' );
        set_time_limit( self::TIMEOUT_SECONDS );

        try {
            $namespace    = 'CEM\\Custom\\' . str_replace( '.', '', uniqid( 'fn_', true ) );
            $wrapped_code = $this->wrap_code( $code, $namespace );
            $temp_file    = $this->create_temp_file( $wrapped_code );

            try {
                require_once $temp_file;

                $fqfn = "{$namespace}\\{$function_name}";
                if ( ! function_exists( $fqfn ) ) {
                    throw new Exception( "Function '{$function_name}' not found after include." );
                }

                $cem_class = "{$namespace}\\CEM";
                $cem_class::_init( $request );

                $memory_before  = memory_get_usage( true );
                $start_time     = microtime( true );

                $result = $fqfn( $request );

                $execution_time = microtime( true ) - $start_time;
                $memory_used    = memory_get_usage( true ) - $memory_before;

                if ( $execution_time > self::TIMEOUT_SECONDS ) {
                    throw new Exception( "Microplugin callback exceeded timeout ({$execution_time}s)." );
                }
                if ( $memory_used > self::MEMORY_LIMIT_BYTES ) {
                    throw new Exception( 'Microplugin callback exceeded memory limit (' . size_format( $memory_used ) . ').' );
                }

                return $result;

            } finally {
                if ( file_exists( $temp_file ) ) {
                    unlink( $temp_file );
                }
            }
        } finally {
            ini_set( 'memory_limit', $old_memory_limit );
            set_time_limit( (int) $old_time_limit );
        }
    }

    /**
     * Wrap PHP code in a unique namespace, injecting CEM template tag class and WP use-imports.
     */
    private function wrap_code( string $code, string $namespace ): string {
        $code = ltrim( $code );
        if ( strncmp( $code, '<?php', 5 ) === 0 ) {
            $code = substr( $code, 5 );
        } elseif ( strncmp( $code, '<?', 2 ) === 0 ) {
            $code = substr( $code, 2 );
        }

        $preamble = <<<'PREAMBLE'

use \WP_REST_Request;
use \WP_REST_Response;
use \WP_Error;
use \WP_Post;
use \WP_User;
use \WP_Query;
use \CEM_Function_Library as Lib;

/**
 * Context-aware template tag facade injected by the executor.
 * Available in every microplugin — no import required.
 *
 * CEM::param('name')          — get request param (sanitized)
 * CEM::params()               — all request params as array
 * CEM::body()                 — decoded JSON request body
 * CEM::header('X-My-Header')  — request header value
 * CEM::method()               — HTTP method string (GET/POST/…)
 * CEM::route()                — REST route string (/cem/v1/…)
 * CEM::user()                 — WP_User for current user or null
 * CEM::user_id()              — current user ID (0 = not logged in)
 * CEM::option($key,$default)  — get_option() shorthand
 * CEM::now($format)           — current_time() shorthand
 * CEM::site_url($path)        — site_url() shorthand
 * CEM::response($data,$code)  — new WP_REST_Response($data,$code)
 * CEM::error($code,$msg,$http)— new WP_Error with status
 */
class CEM {
    private static $req;

    public static function _init( \WP_REST_Request $r ): void {
        self::$req = $r;
    }

    public static function param( string $key, $default = null ) {
        $val = self::$req->get_param( $key );
        return null !== $val ? $val : $default;
    }

    public static function params(): array {
        return (array) self::$req->get_params();
    }

    public static function body(): array {
        $raw = self::$req->get_body();
        if ( '' === trim( $raw ) ) {
            return array();
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    public static function header( string $key ): ?string {
        return self::$req->get_header( $key );
    }

    public static function method(): string {
        return self::$req->get_method();
    }

    public static function route(): string {
        return self::$req->get_route();
    }

    public static function user(): ?\WP_User {
        $id = get_current_user_id();
        return $id ? get_userdata( $id ) : null;
    }

    public static function user_id(): int {
        return get_current_user_id();
    }

    public static function option( string $key, $default = false ) {
        return get_option( $key, $default );
    }

    public static function now( string $format = 'mysql' ): string {
        return current_time( $format );
    }

    public static function site_url( string $path = '' ): string {
        return site_url( $path );
    }

    public static function response( $data, int $status = 200 ): \WP_REST_Response {
        return new \WP_REST_Response( $data, $status );
    }

    public static function error( string $code, string $message, int $status = 400 ): \WP_Error {
        return new \WP_Error( $code, $message, array( 'status' => $status ) );
    }
}

PREAMBLE;

        return "<?php\nnamespace {$namespace};\n" . $preamble . $code;
    }

    /**
     * Write code to a temp file and return its path.
     *
     * @throws Exception If file creation fails.
     */
    private function create_temp_file( string $code ): string {
        $temp = tempnam( sys_get_temp_dir(), 'cem_exec_' );
        if ( false === $temp ) {
            throw new Exception( 'Failed to create temporary execution file.' );
        }
        if ( false === file_put_contents( $temp, $code ) ) {
            throw new Exception( 'Failed to write to temporary execution file.' );
        }
        return $temp;
    }
}
