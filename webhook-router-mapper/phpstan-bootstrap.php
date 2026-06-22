<?php
/**
 * PHPStan bootstrap — defines plugin constants that are set at runtime
 * by webhook-router-mapper.php so that static analysis can resolve them.
 */

define( 'WRM_PLUGIN_URL', 'https://example.com/wp-content/plugins/webhook-router-mapper/' );
define( 'WRM_PLUGIN_DIR', __DIR__ . '/' );
define( 'WRM_VERSION', '1.3.0' );
define( 'WRM_DB_VERSION', '1.3' );
