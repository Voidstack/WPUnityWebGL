<?php
require_once 'php/Utils.php';

/**
* Plugin Name: WP Unity WebGL
* Plugin URI:  https://enosistudio.com/
* Description: Displays a Unity WebGL game inside an iframe.
* Version: 1.0
* Author: MARTIN Baptiste / Voidstack
* License: GPL2+
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain: wpunity
* Domain Path: /languages
*/

/** Permet de charger le script de la page d'administration, uniquement pour l'administration (optimisation) */
if(is_admin()){
    require_once plugin_dir_path(__FILE__) . 'php/admin-page.php';
    require_once plugin_dir_path(__FILE__) . 'php/unity-block.php'; // ne s'exécute que dans l'éditeur de blocs (page/post avec Gutenberg)
}

// Ajout du main.css
function unity_enqueue_toolbar_css(): void {
    wp_enqueue_style(
        'unity-toolbar-style',
        plugins_url('css/main.css', __FILE__),
        [],
        filemtime(plugin_dir_path(__FILE__) . 'css/main.css')
    );
}
add_action('wp_enqueue_scripts', 'unity_enqueue_toolbar_css');

// Singleton unity
function wp_unity_enqueue_singleton(): void {
    wp_enqueue_script(
        'unity-loader-singleton',
        plugins_url('js/unity-loader-singleton.js', __FILE__),[],1.0,true
    );
}
add_action('wp_enqueue_scripts', 'wp_unity_enqueue_singleton');

load_plugin_textdomain('wpunity', false, dirname(plugin_basename(__FILE__)) . '/languages');

function unity_enqueue_scripts(string $build_url, string $loader_name, bool $showOptions, bool $showOnMobile, bool $showLogs, string $sizeMode, int $fixedHeight, string $aspectRatio, string $uuid):void {
    wp_enqueue_script(
        'unity-webgl',
        plugins_url('js/client-unity-block.js', __FILE__),
        [],
        filemtime(plugin_dir_path(__FILE__) . 'js/client-unity-block.js'),
        true
    );
    
    wp_localize_script('unity-webgl', 'UnityWebGLData', [
        'buildUrl' => $build_url,
        'loaderName' => $loader_name,
        'showOptions' => $showOptions,
        'showOnMobile' => $showOnMobile,
        'showLogs' => $showLogs,
        'sizeMode' => $sizeMode,
        'fixedHeight' => $fixedHeight,
        'aspectRatio' => $aspectRatio,
        'urlAdmin' => admin_url('/wp-admin/admin.php'),
        'currentUserIsAdmin' => current_user_can('administrator'),
        'admMessage' => __('TempMsg', 'wpunity'),
        'instanceId' => $uuid,
    ]);
    
    // Permet au script client-unity-block d'import client-unity-toolbar
    if (!function_exists('unity_script_type_module')) {
        function unity_script_type_module(string $tag, string $handle): string {
            if ($handle === 'unity-webgl') {
                return str_replace('<script ', '<script type="module" ', $tag);
            }
            return $tag;
        }
        add_filter('script_loader_tag', 'unity_script_type_module', 10, 2);
    }
}

// Definition of the shortcode [unity_webgl build="${attributes.selectedBuild}"]
function unity_build_shortcode(array $atts): string
{
    // Normalize shortcode attributes to lowercase keys and set default values
    // WordPress sometimes messes with uppercase keys in shortcode attributes    
    $atts = shortcode_atts([
        'build' => '',
        'showoptions' => 'true',     // minuscules !
        'showonmobile' => 'false',
        'showlogs' => 'false', // Affiche les logs dans la console
        'sizemode' => 'fixed-height', // fixed-height, full-width, or custom
        'fixedheight' => 500,         // only used if sizeMode is fixed-height
        'aspectratio' => '4/3',       // format attendu : nombre/nombre (ex: 4/3)
    ], array_change_key_case($atts, CASE_LOWER), 'unity_webgl');
    
    // Sanitize and convert attribute values to proper types
    $build_slug = sanitize_title($atts['build']);
    $showOptions = filter_var($atts['showoptions'], FILTER_VALIDATE_BOOLEAN);
    $showOnMobile = filter_var($atts['showonmobile'], FILTER_VALIDATE_BOOLEAN);
    $showLogs = filter_var($atts['showlogs'], FILTER_VALIDATE_BOOLEAN);
    $fixedHeight = intval($atts['fixedheight']);
    $sizeMode = sanitize_text_field($atts['sizemode']);
    $aspectRatio = sanitize_text_field($atts['aspectratio']);
    
    // If no build specified, return an error message
    if (empty($build_slug)) {
        return '<p>' . esc_html__('❌ Unity WebGL Aucun build spécifié.', 'wpunity') . '</p>';
    }
    
    // Determine the local server path and URL to the Unity build directory
    $upload_dir = wp_upload_dir();
    $build_dir_path = trailingslashit($upload_dir['basedir']) . 'unity_webgl/' . $build_slug;
    $build_url = trailingslashit($upload_dir['baseurl']) . 'unity_webgl/' . trailingslashit($build_slug);
    
    // Construct the path to the Unity loader script
    $loader_file = $build_dir_path . '/Build.loader.js';

    // Check if the loader script exists, else show an error
    if (!file_exists($loader_file)) {
        return '<p style="color:red;">' . sprintf(esc_html__('Unity build file not found: %s', 'wpunity'),esc_html($loader_file)) . '</p>';
    }
    
    // Extract the loader name from the loader filename (e.g. "Build")
    $loader_name = basename($loader_file, '.loader.js');
    
    // If visitor is on mobile and game is not allowed on mobile, show a message
    if (wp_is_mobile() && !$showOnMobile) {
        return '<p>' . esc_html__('🚫 Le jeu n’est pas disponible sur mobile. Merci de le lancer depuis un ordinateur pour une meilleure expérience.', 'wpunity') . '</p>';
    }
    
    $uuid = Utils::generate_uuid();
    unity_enqueue_scripts($build_url, $loader_name, $showOptions, $showOnMobile, $showLogs, $sizeMode, $fixedHeight, $aspectRatio, $uuid);
    
    // Start output buffering to capture the HTML output
    ob_start(); ?>
    <div id="<?=$uuid?>-error" style="display: none; padding: 1rem; color:white;"></div>
    <div id="<?=$uuid?>-container">
    <canvas id="<?=$uuid?>-canvas"></canvas>
    </div>
    <?php
    // Return the buffered HTML as the shortcode output
    return ob_get_clean();
}
add_shortcode('unity_webgl', 'unity_build_shortcode');