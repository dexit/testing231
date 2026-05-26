<?php
/**
 * Define the internationalization functionality.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes
 */
class Custom_Endpoints_Manager_i18n {

    public function load_plugin_textdomain() {
        load_plugin_textdomain(
            'custom-endpoints-manager',
            false,
            dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
        );
    }
}
