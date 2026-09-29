<?php
/**
 * Plugin Name: Cafe Menu Manager
 * Description: Custom front-end management dashboard for a single cafe digital menu. Works with Elementor and ACF, with drag & drop ordering.
 * Version: 0.2.3
 * Author: OpenAI
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) { exit; }

define('CMM_VERSION', '0.2.3');
define('CMM_FILE', __FILE__);
define('CMM_DIR', plugin_dir_path(__FILE__));
define('CMM_URL', plugin_dir_url(__FILE__));

require_once CMM_DIR . 'includes/class-cmm-core.php';
require_once CMM_DIR . 'includes/class-cmm-auth.php';
require_once CMM_DIR . 'includes/class-cmm-rest.php';
require_once CMM_DIR . 'includes/class-cmm-elementor.php';

register_activation_hook(__FILE__, ['CMM_Core', 'activate']);
register_deactivation_hook(__FILE__, ['CMM_Core', 'deactivate']);

add_action('plugins_loaded', function () {
    CMM_Core::boot();
    CMM_Auth::boot();
    CMM_REST::boot();
    CMM_Elementor::boot();
});
