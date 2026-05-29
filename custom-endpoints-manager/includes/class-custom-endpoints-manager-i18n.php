<?php
/**
 * Define the internationalization functionality.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes
 */

/**
 * Internationalization loader.
 *
 * @since 1.0.0
 */
class Custom_Endpoints_Manager_I18n {

	/**
	 * Load the plugin text domain.
	 *
	 * @since 1.0.0
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'custom-endpoints-manager',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);
	}
}
