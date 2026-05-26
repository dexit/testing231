<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes
 */

/**
 * Public-facing plugin functionality.
 *
 * @since 1.0.0
 */
class Custom_Endpoints_Manager_Public {

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string $plugin_name Plugin slug.
	 * @param string $version     Plugin version.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Enqueue public styles.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_styles() {
		wp_enqueue_style(
			$this->plugin_name,
			CEM_PLUGIN_URL . 'public/css/custom-endpoints-manager-public.css',
			array(),
			$this->version,
			'all'
		);
	}

	/**
	 * Enqueue public scripts.
	 *
	 * @since 1.0.0
	 */
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
