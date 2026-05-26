<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes
 */
class Custom_Endpoints_Manager_Public {

    private $plugin_name;
    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
    }

    public function enqueue_styles() {
        wp_enqueue_style(
            $this->plugin_name,
            CEM_PLUGIN_URL . 'public/css/custom-endpoints-manager-public.css',
            array(),
            $this->version,
            'all'
        );
    }

    public function enqueue_scripts() {
        wp_enqueue_script(
            $this->plugin_name,
            CEM_PLUGIN_URL . 'public/js/custom-endpoints-manager-public.js',
            array( 'jquery' ),
            $this->version,
            false
        );
    }
}
