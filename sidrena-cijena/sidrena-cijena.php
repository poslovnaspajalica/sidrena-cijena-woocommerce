<?php
/**
 * Plugin Name: Sidrena cijena za WooCommerce
 * Description: Isticanje sidrene (dodatne) cijene uz aktualnu cijenu i objava strojno čitljivog cjenika (.csv/.xml) prema Odlukama Vlade RH (NN 101/2026) i Zakonu o iznimnim mjerama kontrole cijena (NN 40/2025).
 * Version: 1.1.0
 * Author: Poslovna spajalica
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * Text Domain: sidrena-cijena
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SC_VERSION', '1.1.0');
define('SC_FILE', __FILE__);
define('SC_DIR', plugin_dir_path(__FILE__));
define('SC_URL', plugin_dir_url(__FILE__));

require_once SC_DIR . 'includes/class-sc-settings.php';
require_once SC_DIR . 'includes/class-sc-snapshot.php';
require_once SC_DIR . 'includes/class-sc-display.php';
require_once SC_DIR . 'includes/class-sc-export.php';
require_once SC_DIR . 'includes/class-sc-public.php';
require_once SC_DIR . 'includes/class-sc-product-fields.php';
require_once SC_DIR . 'includes/class-sc-admin.php';

final class Sidrena_Cijena_Plugin {

    public static function init(): void {
        add_action('before_woocommerce_init', [__CLASS__, 'declare_compat']);
        add_action('plugins_loaded', [__CLASS__, 'bootstrap']);
        register_activation_hook(SC_FILE, [__CLASS__, 'activate']);
        register_deactivation_hook(SC_FILE, [__CLASS__, 'deactivate']);
    }

    public static function declare_compat(): void {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', SC_FILE, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', SC_FILE, true);
        }
    }

    public static function bootstrap(): void {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>Plugin <strong>Sidrena cijena</strong> zahtijeva aktivan WooCommerce.</p></div>';
            });
            return;
        }

        self::maybe_upgrade();

        SC_Snapshot::init();
        SC_Display::init();
        SC_Export::init();
        SC_Public::init();
        SC_Product_Fields::init();
        SC_Admin::init();

        if (defined('WP_CLI') && WP_CLI) {
            require_once SC_DIR . 'includes/class-sc-cli.php';
            WP_CLI::add_command('sidrena', 'SC_CLI');
        }
    }

    /** Jednokratne radnje pri promjeni verzije. */
    private static function maybe_upgrade(): void {
        if (get_option('sidrena_cijena_version') === SC_VERSION) {
            return;
        }
        // < 1.0.3: automatski zadatak nakon aktivacije više ne postoji; poništi zaostali.
        wp_clear_scheduled_hook('sidrena_cijena_initial');
        delete_transient('sc_stats');
        delete_transient('sc_export_lock');
        delete_option(SC_Export::STATE_OPT);
        // Instalacije < 1.1.0 imale su WP-Cron uvijek uključen; zadrži to ponašanje samo ako je već bilo aktivno.
        $saved = get_option(SC_Settings::OPTION, []);
        if (is_array($saved) && !isset($saved['cron_nacin']) && get_option('sidrena_cijena_version')) {
            SC_Settings::update(['cron_nacin' => 'wpcron']);
        }
        SC_Export::schedule_cron();
        update_option('sidrena_cijena_version', SC_VERSION, false);
    }

    public static function activate(): void {
        if (!get_option(SC_Settings::OPTION)) {
            update_option(SC_Settings::OPTION, SC_Settings::defaults(), false);
        }
        SC_Export::ensure_dirs();
        SC_Export::schedule_cron();
        // Ništa se ne pokreće automatski: snapshot sidrenih cijena i prvi cjenik su ručne akcije u adminu.
        SC_Public::register_rewrites();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        SC_Export::unschedule_cron();
        flush_rewrite_rules();
    }
}

Sidrena_Cijena_Plugin::init();
