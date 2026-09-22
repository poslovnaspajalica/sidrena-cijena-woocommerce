<?php
/**
 * Plugin Name: Sidrena cijena - cjenik poslovnica
 * Description: Dnevna objava strojno čitljivog cjenika (.csv/.xml) za fizičke poslovnice prema Odluci NN 101/2026: ručni upload CSV-a ili Excela s blagajne, pretvorba u propisanu strukturu, objava na stranici /cjenik/. Radi samostalno ili uz plugin "Sidrena cijena za WooCommerce".
 * Version: 1.2.2
 * Author: Poslovna spajalica
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: sidrena-cijena-poslovnice
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCP_VERSION', '1.2.2' );
define( 'SCP_FILE', __FILE__ );
define( 'SCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCP_URL', plugin_dir_url( __FILE__ ) );
define( 'SCP_CAP', 'scp_upload_cjenik' );

if ( file_exists( SCP_DIR . 'vendor/autoload.php' ) ) {
	require_once SCP_DIR . 'vendor/autoload.php'; // PhpSpreadsheet za .xls/.xlsx
}
require_once SCP_DIR . 'includes/class-scp-settings.php';
require_once SCP_DIR . 'includes/class-scp-files.php';
require_once SCP_DIR . 'includes/class-scp-convert.php';
require_once SCP_DIR . 'includes/class-scp-admin.php';
require_once SCP_DIR . 'includes/class-scp-public.php';

final class Sidrena_Cijena_Poslovnice_Plugin {

	public static function init(): void {
		add_action( 'plugins_loaded', [ __CLASS__, 'bootstrap' ] );
		register_activation_hook( SCP_FILE, [ __CLASS__, 'activate' ] );
		register_deactivation_hook( SCP_FILE, [ __CLASS__, 'deactivate' ] );
	}

	public static function bootstrap(): void {
		self::maybe_upgrade();
		SCP_Files::init();
		SCP_Admin::init();
		SCP_Public::init();
	}

	/** Jednokratne radnje pri promjeni verzije (nadogradnja zipom ne pokreće aktivaciju). */
	private static function maybe_upgrade(): void {
		if ( get_option( 'scp_version' ) === SCP_VERSION ) {
			return;
		}
		if ( SCP_Settings::restore_if_missing() ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-warning"><p><strong>Cjenik poslovnica:</strong> postavke poslovnica vraćene su iz rezervne kopije nakon nadogradnje.</p></div>';
				}
			);
		}
		if ( ! get_option( SCP_Settings::OPTION ) ) {
			update_option( SCP_Settings::OPTION, SCP_Settings::defaults(), false );
		}
		if ( ! get_option( SCP_Settings::BACKUP ) ) {
			update_option( SCP_Settings::BACKUP, SCP_Settings::all(), false );
		}
		SCP_Files::ensure_dirs();
		SCP_Files::schedule_reminder();
		// Rute /cjenik/ se mogu promijeniti između verzija; osvježi rewrite pravila nakon što su registrirana.
		add_action(
			'init',
			static function (): void {
				if ( ! class_exists( 'SC_Public' ) ) {
					SCP_Public::register_rewrites();
				}
				flush_rewrite_rules();
			},
			99
		);
		update_option( 'scp_version', SCP_VERSION, false );
	}

	public static function activate(): void {
		SCP_Settings::restore_if_missing();
		if ( ! get_option( SCP_Settings::OPTION ) ) {
			update_option( SCP_Settings::OPTION, SCP_Settings::defaults(), false );
		}
		if ( ! get_option( SCP_Settings::BACKUP ) ) {
			update_option( SCP_Settings::BACKUP, SCP_Settings::all(), false );
		}
		// Ovlast za upload: administrator i voditelj trgovine, plus zasebna uloga za djelatnike poslovnica.
		foreach ( [ 'administrator', 'shop_manager' ] as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( SCP_CAP );
			}
		}
		if ( ! get_role( 'scp_poslovnica' ) ) {
			add_role(
				'scp_poslovnica',
				'Cjenik poslovnice',
				[
					'read'  => true,
					SCP_CAP => true,
				]
			);
		}
		SCP_Files::ensure_dirs();
		SCP_Files::schedule_reminder();
		SCP_Public::register_rewrites();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		SCP_Files::unschedule_reminder();
		flush_rewrite_rules();
	}
}

Sidrena_Cijena_Poslovnice_Plugin::init();
