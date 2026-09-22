<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SC_Settings {
	public const OPTION = 'sidrena_cijena_settings';

	public static function defaults(): array {
		return [
			// Isticanje
			'referentni_datum'     => '2026-09-10',
			'alt_datum'            => '2025-05-02',
			'alt_kategorije'       => [],
			'izuzete_kategorije'   => [],
			'label'                => 'Cijena na {datum}: {cijena}',
			'label_lang'           => "en: Price on {datum}: {cijena}\nde: Preis am {datum}: {cijena}\nit: Prezzo al {datum}: {cijena}",
			'label_loop'           => '',   // kraći tekst za listinge; prazno = isti kao label
			'label_loop_lang'      => '',
			'font_size'            => '0.7em',
			'boja'                 => '',   // prazno = naslijeđena
			'font_weight'          => '400',
			'custom_css'           => '',
			'prikaz_mod'           => 'datum', // on | off | datum
			'prikaz_od'            => '2026-10-01',
			'prikaz_kosarica'      => 1,
			'prikaz_bez_sidrene'   => 'nista', // nista | redovna
			'auto_novi'            => 1,
			// Cjenik / datoteka
			'oblik_objekta'        => 'webshop',
			'adresa'               => '',
			'oznaka_objekta'       => '1',
			'broj_pohrane'         => '1',
			'naziv_trgovca'        => '',
			'cron_nacin'           => 'rucno',   // rucno | wpcron
			'cron_vrijeme'         => '04:00',
			'cron_token'           => '',
			'retencija_dana'       => 35,
			'csv_separator'        => ';',
			'decimalni_znak'       => '.',
			'marka_izvor'          => 'none',      // none | product_brand | pa_<atribut> | meta:<kljuc>
			'marka_zadano' => '', // upisuje se kad izvor marke ne da vrijednost
			'barkod_izvor'         => 'gtin_sku',  // gtin_sku | gtin | sku | meta:<kljuc>
			'jedinica_meta'        => '',
			'cijena_jedinica_meta' => '',
			'naziv_akcije'         => 'Akcija',
			'javni_slug'           => 'cjenik',
			'ukljuci_nedostupne'   => 1,
			'ukljuci_skrivene'     => 1,
		];
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		return array_merge( self::defaults(), $saved );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function update( array $data ): void {
		$all = array_merge( self::all(), $data );
		update_option( self::OPTION, $all, false );
		wp_cache_delete( 'excluded_cat_tt', 'sidrena_cijena' );
	}

	/** 2026-09-10 -> 10. 9. 2026. (hr) ili prema WP formatu datuma za druge jezike. */
	public static function format_date( ?string $ymd, ?string $lang = null ): string {
		if ( ! $ymd ) {
			return '';
		}
		$ts = strtotime( $ymd . ' 12:00:00' );
		if ( ! $ts ) {
			return $ymd;
		}
		$lang = $lang ?? 'hr';
		if ( $lang === 'hr' ) {
			return sprintf( '%d. %d. %d.', (int) gmdate( 'j', $ts ), (int) gmdate( 'n', $ts ), (int) gmdate( 'Y', $ts ) );
		}
		return date_i18n( get_option( 'date_format' ) ?: 'F j, Y', $ts );
	}

	/** Tajni token za vanjski cron okidač (generira se pri prvom pozivu). */
	public static function cron_token(): string {
		$t = (string) self::get( 'cron_token' );
		if ( $t === '' ) {
			$t = wp_generate_password( 32, false, false );
			self::update( [ 'cron_token' => $t ] );
		}
		return $t;
	}

	/** Vrijeme (timestamp) današnjeg zakazanog generiranja u vremenskoj zoni stranice. */
	public static function today_run_timestamp(): int {
		$hhmm = (string) self::get( 'cron_vrijeme' );
		if ( ! preg_match( '/^\d{1,2}:\d{2}$/', $hhmm ) ) {
			$hhmm = '04:00';
		}
		return ( new DateTimeImmutable( 'today ' . $hhmm, wp_timezone() ) )->getTimestamp();
	}

	/** Je li prikaz sidrene cijene na frontendu trenutno aktivan. */
	public static function display_active(): bool {
		$mod = (string) self::get( 'prikaz_mod' );
		if ( $mod === 'on' ) {
			$active = true;
		} elseif ( $mod === 'off' ) {
			$active = false;
		} else {
			$od     = (string) self::get( 'prikaz_od' );
			$active = $od === '' || wp_date( 'Y-m-d' ) >= $od;
		}
		return (bool) apply_filters( 'sidrena_cijena_display_active', $active );
	}

	/**
	 * Trenutni jezik frontenda kao dvoslovni kod (hr, en, de...).
	 * Redom: Polylang, WPML, TranslatePress, ?lang=, WordPress locale. Filter: sidrena_cijena_current_language.
	 */
	public static function current_language(): string {
		$lang = '';
		if ( function_exists( 'pll_current_language' ) ) {
			$lang = (string) pll_current_language( 'slug' );
		}
		if ( $lang === '' && defined( 'ICL_LANGUAGE_CODE' ) ) {
			$lang = (string) ICL_LANGUAGE_CODE;
		}
		if ( $lang === '' && has_filter( 'wpml_current_language' ) ) {
			$lang = (string) apply_filters( 'wpml_current_language', '' );
		}
		if ( $lang === '' && ! empty( $GLOBALS['TRP_LANGUAGE'] ) ) {
			$lang = (string) $GLOBALS['TRP_LANGUAGE'];
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- čitanje jezika za prikaz, bez promjene stanja.
		if ( $lang === '' && ! is_admin() && isset( $_GET['lang'] ) ) {
			$lang = sanitize_key( (string) wp_unslash( $_GET['lang'] ) );
		}
		// phpcs:enable
		if ( $lang === '' ) {
			$lang = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		}
		$lang = strtolower( substr( str_replace( '-', '_', (string) $lang ), 0, 2 ) ) ?: 'hr';
		return (string) apply_filters( 'sidrena_cijena_current_language', $lang );
	}

	/** Tekst oznake za jezik; $loop = kraći tekst za listinge ako je definiran. */
	public static function label_for( string $lang, bool $loop = false ): string {
		if ( $loop && trim( (string) self::get( 'label_loop' ) ) !== '' ) {
			$labels  = self::parse_label_lang( (string) self::get( 'label_loop_lang' ) );
			$default = (string) self::get( 'label_loop' );
		} else {
			$labels  = self::parse_label_lang( (string) self::get( 'label_lang' ) );
			$default = (string) self::get( 'label' );
		}
		$label = $labels[ $lang ] ?? ( $lang === 'hr' ? '' : ( $labels['en'] ?? '' ) );
		if ( $label === '' || $lang === 'hr' ) {
			$label = $default;
		}
		return (string) apply_filters( 'sidrena_cijena_label', $label, $lang, $loop );
	}

	/** "en: Anchor price..." po retku -> ['en' => 'Anchor price...'] */
	public static function parse_label_lang( string $raw ): array {
		$out = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( ! preg_match( '/^\s*([a-zA-Z]{2})(?:[_-][a-zA-Z]{2})?\s*[:=]\s*(.+?)\s*$/', $line, $m ) ) {
				continue;
			}
			$out[ strtolower( $m[1] ) ] = $m[2];
		}
		return $out;
	}

	/** term_taxonomy_id-jevi kategorija izuzetih iz isticanja (uklj. podkategorije). */
	public static function excluded_category_term_taxonomy_ids(): array {
		return self::category_tt_ids( (array) self::get( 'izuzete_kategorije' ) );
	}

	/** Term IDs (uklj. podkategorije) kategorija s alternativnim datumom (2. 5. 2025.). */
	public static function alt_category_term_taxonomy_ids(): array {
		return self::category_tt_ids( (array) self::get( 'alt_kategorije' ) );
	}

	private static function category_tt_ids( array $term_ids ): array {
		$ids = array_map( 'intval', $term_ids );
		$ids = array_filter( $ids );
		if ( ! $ids ) {
			return [];
		}
		$all = $ids;
		foreach ( $ids as $id ) {
			$children = get_term_children( $id, 'product_cat' );
			if ( is_array( $children ) ) {
				$all = array_merge( $all, array_map( 'intval', $children ) );
			}
		}
		$all = array_unique( $all );
		global $wpdb;
		$all = array_values( array_map( 'intval', $all ) );
		$ph  = implode( ',', array_fill( 0, count( $all ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- popis %d/%s placeholdera izgrađen iz array_fill(), argumenti prosljeđeni kroz prepare().
		$tt = $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy='product_cat' AND term_id IN ($ph)", ...$all ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		return array_map( 'intval', $tt );
	}
}
