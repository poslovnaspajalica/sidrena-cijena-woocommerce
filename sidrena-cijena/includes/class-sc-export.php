<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generiranje cjenika (.csv i .xml) u malim koracima izravnim SQL upitima, cron, retencija.
 *
 * Načela:
 *  - nikad se ne pokreće zbog posjeta kupca (samo admin klik, WP-Cron ili vanjski okidač)
 *  - najviše CHUNK proizvoda po koraku, nekoliko laganih upita po koraku, pauza između koraka
 *  - samo jedna obrada istovremeno (atomarno zaključavanje)
 */
final class SC_Export {
	public const CRON_HOOK = 'sidrena_cijena_daily_export';
	public const STATE_OPT = 'sidrena_cijena_export_state';
	public const LAST_OPT  = 'sidrena_cijena_last_export';
	public const LOCK_OPT  = 'sidrena_cijena_export_lock';
	public const CHUNK     = 100;
	public const PAUSE_US  = 150000; // 0,15 s pauze između koraka u pozadinskoj obradi
	public const LOCK_TTL  = 20 * MINUTE_IN_SECONDS;

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'cron' ] );
		add_action( 'wp_ajax_sc_export_batch', [ __CLASS__, 'ajax_batch' ] );
		add_action( 'wp_loaded', [ __CLASS__, 'maybe_external_trigger' ] );
	}

	/* ---------- Direktoriji ---------- */

	public static function base_dir(): string {
		$u = wp_upload_dir();
		return trailingslashit( $u['basedir'] ) . 'sidrena-cijena/';
	}

	public static function base_url(): string {
		$u = wp_upload_dir();
		return trailingslashit( $u['baseurl'] ) . 'sidrena-cijena/';
	}

	public static function files_dir(): string {
		return self::base_dir() . 'cjenik/';
	}

	public static function files_url(): string {
		return self::base_url() . 'cjenik/';
	}

	public static function tmp_dir(): string {
		return self::base_dir() . 'tmp/';
	}

	public static function ensure_dirs(): void {
		foreach ( [ self::base_dir(), self::files_dir(), self::tmp_dir() ] as $d ) {
			if ( ! is_dir( $d ) ) {
				wp_mkdir_p( $d );
			}
		}
		if ( ! file_exists( self::tmp_dir() . 'index.html' ) ) {
			file_put_contents( self::tmp_dir() . 'index.html', '' );
		}
		if ( ! file_exists( self::tmp_dir() . '.htaccess' ) ) {
			file_put_contents( self::tmp_dir() . '.htaccess', "Require all denied\n" );
		}
	}

	/* ---------- Cron ---------- */

	public static function schedule_cron(): void {
		self::unschedule_cron();
		if ( SC_Settings::get( 'cron_nacin' ) !== 'wpcron' ) {
			return;
		}
		$hhmm = (string) SC_Settings::get( 'cron_vrijeme' );
		if ( ! preg_match( '/^\d{1,2}:\d{2}$/', $hhmm ) ) {
			$hhmm = '04:00';
		}
		$tz   = wp_timezone();
		$now  = new DateTimeImmutable( 'now', $tz );
		$next = new DateTimeImmutable( 'today ' . $hhmm, $tz );
		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}
		wp_schedule_event( $next->getTimestamp(), 'daily', self::CRON_HOOK );
	}

	public static function unschedule_cron(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function cron(): void {
		if ( SC_Settings::get( 'cron_nacin' ) !== 'wpcron' ) {
			return;
		}
		self::run_background( 'cron' );
	}

	/* ---------- Zaključavanje (atomarno preko add_option) ---------- */

	public static function acquire_lock( string $who ): bool {
		global $wpdb;
		$val = $who . '|' . time();
		// add_option koristi INSERT; ako redak postoji, ne uspije. Bez autoload-a.
		if ( add_option( self::LOCK_OPT, $val, '', false ) ) {
			return true;
		}
		$cur = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::LOCK_OPT ) );
		$ts  = (int) substr( (string) strrchr( $cur, '|' ), 1 );
		if ( $ts && time() - $ts > self::LOCK_TTL ) {
			// Zastarjelo (proces je umro): preuzmi.
			update_option( self::LOCK_OPT, $val, false );
			return true;
		}
		return false;
	}

	public static function release_lock(): void {
		delete_option( self::LOCK_OPT );
	}

	public static function lock_info(): ?string {
		global $wpdb;
		$cur = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::LOCK_OPT ) );
		if ( ! $cur ) {
			return null;
		}
		$ts = (int) substr( (string) strrchr( (string) $cur, '|' ), 1 );
		return ( time() - $ts > self::LOCK_TTL ) ? null : (string) $cur;
	}

	/* ---------- Naziv datoteke ---------- */

	/** Oznaka objekta: slova, znamenke i crtica, zadržava velika slova (npr. P-01). */
	public static function clean_code( string $v, string $fallback ): string {
		$v = preg_replace( '/[^A-Za-z0-9\-]+/', '-', str_replace( [ ' ', '_' ], '-', trim( $v ) ) ) ?? '';
		$v = trim( $v, '-' );
		return $v !== '' ? $v : $fallback;
	}

	/**
	 * Naziv datoteke po točki VI. Odluke i pojašnjenju Ministarstva (18. 9. 2026.):
	 * oblik_adresa_oznaka_brojpohrane_datum_vrijeme, npr. webshop_ilica-150-zagreb_P-01_104_01.10.2026_07-45.csv
	 * Broj pohrane je redni broj generirane datoteke.
	 */
	public static function build_filename( string $ext, ?int $ts = null, ?int $seq = null ): string {
		$s     = SC_Settings::all();
		$ts    = $ts ?? time();
		$seq   = $seq ?? max( 1, (int) $s['broj_pohrane'] );
		$parts = [
			sanitize_title( (string) $s['oblik_objekta'] ) ?: 'webshop',
			sanitize_title( (string) $s['adresa'] ) ?: 'adresa',
			self::clean_code( (string) $s['oznaka_objekta'], 'P-01' ),
			(string) $seq,
			wp_date( 'd.m.Y_H-i', $ts ),
		];
		return implode( '_', $parts ) . '.' . $ext;
	}

	/* ---------- Stanje ---------- */

	public static function state(): ?array {
		$s = get_option( self::STATE_OPT, null );
		return is_array( $s ) ? $s : null;
	}

	public static function start( string $trigger = 'manual' ): array {
		self::ensure_dirs();
		SC_Snapshot::fill_new_products(); // novi proizvodi bez sidrene, neovisno o načinu unosa
		$id    = wp_generate_password( 8, false );
		$state = [
			'id'      => $id,
			'trigger' => $trigger,
			'started' => time(),
			'last_id' => 0,
			'offset'  => 0,
			'rows'    => 0,
			'missing' => 0,
			'csv_tmp' => self::tmp_dir() . "cjenik_{$id}.csv",
			'xml_tmp' => self::tmp_dir() . "cjenik_{$id}.xml",
			'total'   => self::count_products(),
		];

		$sep = (string) SC_Settings::get( 'csv_separator' ) ?: ';';
		$fh  = fopen( $state['csv_tmp'], 'w' );
		fwrite( $fh, "\xEF\xBB\xBF" );
		fputcsv( $fh, self::columns(), $sep, '"', '' );
		fclose( $fh );

		$s    = SC_Settings::all();
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<cjenik'
			. ' trgovac="' . self::x( $s['naziv_trgovca'] ?: get_bloginfo( 'name' ) ) . '"'
			. ' oblik_objekta="' . self::x( $s['oblik_objekta'] ) . '"'
			. ' adresa="' . self::x( $s['adresa'] ) . '"'
			. ' oznaka_objekta="' . self::x( $s['oznaka_objekta'] ) . '"'
			. ' broj_pohrane="' . self::x( $s['broj_pohrane'] ) . '"'
			. ' referentni_datum="' . self::x( $s['referentni_datum'] ) . '"'
			. ' generirano="' . self::x( wp_date( 'c', $state['started'] ) ) . '"'
			. ' valuta="' . self::x( get_woocommerce_currency() ) . '"'
			. ">\n";
		file_put_contents( $state['xml_tmp'], $xml );

		update_option( self::STATE_OPT, $state, false );
		return $state;
	}

	public static function count_products(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'" );
	}

	/** Jedan korak: najviše CHUNK proizvoda. Vraća stanje; 'done' => true kad je gotovo. */
	public static function step(): array {
		$state = self::state();
		if ( ! $state ) {
			$state = self::start( 'manual' );
		}

		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
				(int) $state['last_id'],
				self::CHUNK
			)
		);
		if ( ! $ids ) {
			return self::finish( $state );
		}
		$ids = array_map( 'intval', $ids );

		$settings = SC_Settings::all();
		$rows     = self::build_rows( $ids, $settings );

		$sep = (string) $settings['csv_separator'] ?: ';';
		$csv = fopen( $state['csv_tmp'], 'a' );
		$xml = fopen( $state['xml_tmp'], 'a' );
		foreach ( $rows as $row ) {
			if ( $row['sidrena_cijena'] === '' && empty( $row['_izuzet'] ) ) {
				++$state['missing'];
			}
			unset( $row['_izuzet'] );
			fputcsv( $csv, array_values( $row ), $sep, '"', '' );
			fwrite( $xml, self::xml_row( $row ) );
			++$state['rows'];
		}
		fclose( $csv );
		fclose( $xml );

		$state['last_id'] = (int) end( $ids );
		$state['offset'] += count( $ids );
		$state['done']    = false;
		update_option( self::STATE_OPT, $state, false );

		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime(); // ne gomilaj postove u memoriji kroz stotine koraka
		}
		return $state;
	}

	private static function finish( array $state ): array {
		file_put_contents( $state['xml_tmp'], "</cjenik>\n", FILE_APPEND );

		$ts  = time();
		$seq = max( 1, (int) SC_Settings::get( 'broj_pohrane' ) );
		// Broj pohrane = redni broj datoteke; raste sa svakom objavom, pa su nazivi uvijek jedinstveni.
		$csv_name = self::build_filename( 'csv', $ts, $seq );
		$xml_name = self::build_filename( 'xml', $ts, $seq );
		while ( file_exists( self::files_dir() . $csv_name ) || file_exists( self::files_dir() . $xml_name ) ) {
			++$seq;
			$csv_name = self::build_filename( 'csv', $ts, $seq );
			$xml_name = self::build_filename( 'xml', $ts, $seq );
		}
		SC_Settings::update( [ 'broj_pohrane' => (string) ( $seq + 1 ) ] );
		rename( $state['csv_tmp'], self::files_dir() . $csv_name );
		rename( $state['xml_tmp'], self::files_dir() . $xml_name );

		$last = [
			'csv'     => $csv_name,
			'xml'     => $xml_name,
			'time'    => $ts,
			'rows'    => (int) $state['rows'],
			'missing' => (int) $state['missing'],
			'trigger' => $state['trigger'],
			'seconds' => $ts - (int) $state['started'],
		];
		update_option( self::LAST_OPT, $last, false );
		delete_option( self::STATE_OPT );

		self::apply_retention();
		self::write_index();
		do_action( 'sidrena_cijena_export_done', $last );

		return $last + [ 'done' => true ];
	}

	public static function abort(): void {
		$state = self::state();
		if ( $state ) {
			wp_delete_file( $state['csv_tmp'] );
			wp_delete_file( $state['xml_tmp'] );
			delete_option( self::STATE_OPT );
		}
	}

	/**
	 * Pozadinska obrada (cron / vanjski okidač / WP-CLI): koraci po CHUNK s pauzom, pod zaključavanjem.
	 * Vraća null ako već radi druga obrada.
	 */
	public static function run_background( string $trigger ): ?array {
		if ( ! self::acquire_lock( $trigger ) ) {
			return null;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}
		ignore_user_abort( true );
		try {
			self::abort();
			self::start( $trigger );
			do {
				$r = self::step();
				if ( empty( $r['done'] ) ) {
					usleep( self::PAUSE_US );
				}
			} while ( empty( $r['done'] ) );
			return $r;
		} finally {
			self::release_lock();
		}
	}

	/** Admin: jedan korak po AJAX zahtjevu. */
	public static function ajax_batch(): void {
		check_ajax_referer( 'sc_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Nemate ovlasti.' ], 403 );
		}
		if ( ! empty( $_POST['restart'] ) ) {
			if ( self::lock_info() ) {
				wp_send_json_error( [ 'message' => 'U tijeku je pozadinsko generiranje (' . self::lock_info() . '). Pričekaj da završi.' ] );
			}
			self::abort();
			self::start( 'manual' );
		}
		$r = self::step();
		wp_send_json_success( $r );
	}

	/** Je li današnji cjenik već generiran (od zakazanog vremena naovamo). */
	public static function is_due(): bool {
		$due_ts = SC_Settings::today_run_timestamp();
		if ( time() < $due_ts ) {
			return false;
		}
		$last = self::last();
		return empty( $last['time'] ) || (int) $last['time'] < $due_ts;
	}

	/**
	 * Vanjski okidač: https://domena/?sidrena_cron=TOKEN. Odmah odgovori, generiraj u pozadini.
	 * &only_due=1: samo ako današnji cjenik ne postoji. &wait=1: sinkrono (testiranje).
	 */
	public static function maybe_external_trigger(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- javni okidač zaštićen tajnim tokenom (hash_equals), ne nonce-om.
		if ( ! isset( $_GET['sidrena_cron'] ) ) {
			return;
		}
		if ( SC_Settings::get( 'cron_nacin' ) === 'iskljuceno' ) {
			status_header( 403 );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode(
				[
					'ok'    => false,
					'error' => 'automatic generation disabled in settings',
				]
			);
			exit;
		}
		if ( ! hash_equals( SC_Settings::cron_token(), sanitize_text_field( wp_unslash( $_GET['sidrena_cron'] ) ) ) ) {
			// Usporavanje pogađanja tokena i bez otkrivanja detalja.
			usleep( 500000 );
			status_header( 403 );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode(
				[
					'ok'    => false,
					'error' => 'forbidden',
				]
			);
			exit;
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		// Najviše jedno pokretanje u 5 minuta, i ako token procuri ne može se izazvati trajno opterećenje.
		if ( get_transient( 'sc_external_rate' ) && empty( $_GET['only_due'] ) ) {
			status_header( 429 );
			echo wp_json_encode(
				[
					'ok'    => false,
					'error' => 'rate limited: at most one run per 5 minutes',
				]
			);
			exit;
		}
		if ( ! empty( $_GET['only_due'] ) && ! self::is_due() ) {
			echo wp_json_encode(
				[
					'ok'      => true,
					'skipped' => 'already generated today',
					'last'    => self::last(),
				]
			);
			exit;
		}
		if ( self::lock_info() ) {
			echo wp_json_encode(
				[
					'ok'    => false,
					'error' => 'export already running',
					'lock'  => self::lock_info(),
				]
			);
			exit;
		}
		if ( ! empty( $_GET['wait'] ) ) {
			set_transient( 'sc_external_rate', 1, 5 * MINUTE_IN_SECONDS );
			$r = self::run_background( 'external' );
			echo wp_json_encode(
				$r === null ? [
					'ok'    => false,
					'error' => 'export already running',
				] : [ 'ok' => true ] + $r
			);
			exit;
		}
		set_transient( 'sc_external_rate', 1, 5 * MINUTE_IN_SECONDS );
		echo wp_json_encode(
			[
				'ok'      => true,
				'started' => true,
				'last'    => self::last(),
			]
		);
		self::finish_request();
		self::run_background( 'external' );
		exit;
	}

	private static function finish_request(): void {
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		} else {
			if ( ! headers_sent() ) {
				header( 'Connection: close' );
				header( 'Content-Length: ' . (int) ob_get_length() );
			}
			while ( ob_get_level() > 0 ) {
				ob_end_flush();
			}
			flush();
		}
	}

	public static function is_stale(): bool {
		$last = self::last();
		return empty( $last['time'] ) || ( time() - (int) $last['time'] ) > 26 * HOUR_IN_SECONDS;
	}

	/* ---------- Redci: izravni SQL, bez WC_Product objekata ---------- */

	public static function columns(): array {
		return [
			'naziv',
			'sifra',
			'marka',
			'jedinica_mjere',
			'cijena_za_jedinicu_mjere',
			'maloprodajna_cijena',
			'posebni_oblik_prodaje',
			'naziv_posebnog_oblika_prodaje',
			'sidrena_cijena',
			'barkod',
			'dostupnost',
			'sidrena_cijena_datum',
			'kategorija',
			'url',
		];
	}

	private static function meta_keys( array $s ): array {
		$keys = [
			'_sku',
			'_global_unique_id',
			'_regular_price',
			'_sale_price',
			'_price',
			'_stock_status',
			'_tax_class',
			'_tax_status',
			SC_Snapshot::META_PRICE,
			SC_Snapshot::META_DATE,
			SC_Snapshot::META_EXCL,
			SC_Snapshot::META_NOCJ,
		];
		foreach ( [ 'jedinica_meta', 'cijena_jedinica_meta' ] as $k ) {
			if ( ! empty( $s[ $k ] ) ) {
				$keys[] = $s[ $k ];
			}
		}
		foreach ( [ 'marka_izvor', 'barkod_izvor' ] as $k ) {
			if ( str_starts_with( (string) $s[ $k ], 'meta:' ) ) {
				$keys[] = substr( $s[ $k ], 5 );
			}
		}
		return array_unique( $keys );
	}

	/** @return array<int, array> redci za zadane ID-jeve proizvoda (uklj. varijacije) */
	public static function build_rows( array $ids, array $s ): array {
		global $wpdb;
		// 1) proizvodi + varijacije
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- popis %d/%s placeholdera izgrađen iz array_fill(), argumenti prosljeđeni kroz prepare().
		$posts      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_parent, post_type, menu_order FROM {$wpdb->posts}
				 WHERE ID IN ($ph)
				    OR (post_type='product_variation' AND post_status='publish' AND post_parent IN ($ph))
				 ORDER BY post_parent, menu_order, ID",
				...$ids,
				...$ids
			),
			ARRAY_A
		);
		$products   = [];
		$variations = [];
		foreach ( $posts as $p ) {
			if ( $p['post_type'] === 'product_variation' ) {
				$variations[ (int) $p['post_parent'] ][] = $p;
			} else {
				$products[ (int) $p['ID'] ] = $p;
			}
		}
		$all_ids = array_merge( $ids, array_map( static fn( $p ) => (int) $p['ID'], array_merge( [], ...array_values( $variations ?: [ [] ] ) ) ) );

		// 2) meta u jednom upitu
		$keys    = array_values( self::meta_keys( $s ) );
		$ph_ids  = implode( ',', array_fill( 0, count( $all_ids ), '%d' ) );
		$ph_keys = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$meta    = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($ph_ids) AND meta_key IN ($ph_keys)", ...$all_ids, ...$keys ), ARRAY_A ) as $m ) {
			$meta[ (int) $m['post_id'] ][ $m['meta_key'] ] = $m['meta_value'];
		}

		// 3) taksonomije u jednom upitu: tip, kategorije, vidljivost, marka
		$taxes     = [ 'product_type', 'product_cat', 'product_visibility' ];
		$brand_tax = '';
		if ( $s['marka_izvor'] === 'product_brand' || str_starts_with( (string) $s['marka_izvor'], 'pa_' ) ) {
			$brand_tax = (string) $s['marka_izvor'];
			$taxes[]   = $brand_tax;
		}
		$ph_tax = implode( ',', array_fill( 0, count( $taxes ), '%s' ) );
		$terms  = [];
		foreach ( $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.object_id, tt.taxonomy, tt.term_taxonomy_id, t.name, t.slug
				 FROM {$wpdb->term_relationships} tr
				 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				 WHERE tr.object_id IN ($ph) AND tt.taxonomy IN ($ph_tax)",
				...$ids,
				...$taxes
			),
			ARRAY_A
		) as $t ) {
			$terms[ (int) $t['object_id'] ][ $t['taxonomy'] ][] = $t;
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$excluded_cat_tt = array_flip( SC_Settings::excluded_category_term_taxonomy_ids() );

		// 4) permalinkovi: napuni keš jednim upitom pa koristi get_permalink
		_prime_post_caches( $ids, false, false );

		$rows = [];
		foreach ( $ids as $pid ) {
			if ( ! isset( $products[ $pid ] ) ) {
				continue;
			}
			$p    = $products[ $pid ];
			$pm   = $meta[ $pid ] ?? [];
			$type = $terms[ $pid ]['product_type'][0]['slug'] ?? 'simple';
			if ( in_array( $type, [ 'grouped', 'external' ], true ) ) {
				continue;
			}
			if ( ( $pm[ SC_Snapshot::META_NOCJ ] ?? '' ) === 'yes' ) {
				continue;
			}
			if ( ! $s['ukljuci_skrivene'] ) {
				foreach ( $terms[ $pid ]['product_visibility'] ?? [] as $t ) {
					if ( $t['slug'] === 'exclude-from-catalog' ) {
						continue 2;
					}
				}
			}
			$excluded = ( $pm[ SC_Snapshot::META_EXCL ] ?? '' ) === 'yes';
			if ( ! $excluded && $excluded_cat_tt ) {
				foreach ( $terms[ $pid ]['product_cat'] ?? [] as $t ) {
					if ( isset( $excluded_cat_tt[ (int) $t['term_taxonomy_id'] ] ) ) {
						$excluded = true;
						break;
					}
				}
			}
			$cats  = implode( ' | ', array_map( static fn( $t ) => $t['name'], $terms[ $pid ]['product_cat'] ?? [] ) );
			$brand = '';
			if ( $brand_tax !== '' ) {
				$brand = implode( ', ', array_map( static fn( $t ) => $t['name'], $terms[ $pid ][ $brand_tax ] ?? [] ) );
			} elseif ( str_starts_with( (string) $s['marka_izvor'], 'meta:' ) ) {
				$brand = (string) ( $pm[ substr( $s['marka_izvor'], 5 ) ] ?? '' );
			}
			$url = get_permalink( $pid );

			$items = ( $type === 'variable' ) ? ( $variations[ $pid ] ?? [] ) : [ $p ];
			foreach ( $items as $item ) {
				$iid = (int) $item['ID'];
				$im  = ( $iid === $pid ) ? $pm : ( ( $meta[ $iid ] ?? [] ) + $pm ); // varijacija nasljeđuje što nema
				if ( ( $im['_regular_price'] ?? '' ) === '' && ( $im['_price'] ?? '' ) === '' ) {
					continue; // bez cijene (npr. nedovršena varijacija)
				}
				if ( ! $s['ukljuci_nedostupne'] && ! in_array( $im['_stock_status'] ?? 'instock', [ 'instock', 'onbackorder' ], true ) ) {
					continue;
				}
				$ibrand = $brand;
				if ( $ibrand === '' && str_starts_with( (string) $s['marka_izvor'], 'meta:' ) ) {
					$ibrand = (string) ( $meta[ $iid ][ substr( $s['marka_izvor'], 5 ) ] ?? '' );
				}
				if ( $ibrand === '' ) {
					$ibrand = trim( (string) ( $s['marka_zadano'] ?? '' ) );
				}
				$rows[] = self::row_from_meta( $iid, (string) $item['post_title'], $im, $ibrand, $cats, $url, $excluded, $s );
			}
		}
		return $rows;
	}

	private static function row_from_meta( int $id, string $name, array $m, string $brand, string $cats, string $url, bool $excluded, array $s ): array {
		$regular = ( $m['_regular_price'] ?? '' ) !== '' ? (float) $m['_regular_price'] : null;
		$sale    = ( $m['_sale_price'] ?? '' ) !== '' ? (float) $m['_sale_price'] : null;
		$price   = ( $m['_price'] ?? '' ) !== '' ? (float) $m['_price'] : (float) $regular;
		$on_sale = $sale !== null && $regular !== null && $sale < $regular && abs( $price - $sale ) < 0.00001;

		$tax_class  = (string) ( $m['_tax_class'] ?? '' );
		$tax_status = (string) ( $m['_tax_status'] ?? 'taxable' );

		$sidrena_raw = $excluded ? '' : (string) ( $m[ SC_Snapshot::META_PRICE ] ?? '' );
		$sidrena     = $sidrena_raw !== '' ? self::fmt_price( self::incl_tax( (float) $sidrena_raw, $tax_class, $tax_status ), $s ) : '';

		$sku  = (string) ( $m['_sku'] ?? '' );
		$gtin = (string) ( $m['_global_unique_id'] ?? '' );
		$src  = (string) $s['barkod_izvor'];
		if ( str_starts_with( $src, 'meta:' ) ) {
			$barcode = (string) ( $m[ substr( $src, 5 ) ] ?? '' );
		} else {
			$barcode = match ( $src ) {
				'gtin'  => $gtin,
				'sku'   => $sku,
				default => $gtin !== '' ? $gtin : $sku,
			};
		}

		$row = [
			'naziv'                         => wp_strip_all_tags( $name ),
			'sifra'                         => $sku,
			'marka'                         => $brand,
			'jedinica_mjere'                => ! empty( $s['jedinica_meta'] ) ? (string) ( $m[ $s['jedinica_meta'] ] ?? '' ) : '',
			'cijena_za_jedinicu_mjere'      => ! empty( $s['cijena_jedinica_meta'] ) ? (string) ( $m[ $s['cijena_jedinica_meta'] ] ?? '' ) : '',
			'maloprodajna_cijena'           => self::fmt_price( self::incl_tax( $price, $tax_class, $tax_status ), $s ),
			'posebni_oblik_prodaje'         => $on_sale ? 'DA' : 'NE',
			'naziv_posebnog_oblika_prodaje' => $on_sale ? (string) $s['naziv_akcije'] : '',
			'sidrena_cijena'                => $sidrena,
			'barkod'                        => $barcode,
			'dostupnost'                    => in_array( $m['_stock_status'] ?? 'instock', [ 'instock', 'onbackorder' ], true ) ? 'dostupno' : 'nedostupno',
			'sidrena_cijena_datum'          => $sidrena !== '' ? (string) ( $m[ SC_Snapshot::META_DATE ] ?? $s['referentni_datum'] ) : '',
			'kategorija'                    => $cats,
			'url'                           => $url,
			'_izuzet'                       => $excluded,
		];
		return apply_filters( 'sidrena_cijena_export_row', $row, $id, $m );
	}

	/** Cijena s PDV-om prema postavkama trgovine (osnovne stope), bez WC_Product objekta. */
	public static function incl_tax( float $price, string $tax_class, string $tax_status ): float {
		static $rates = [];
		if ( $price <= 0 || ! wc_tax_enabled() || $tax_status !== 'taxable' ) {
			return $price;
		}
		if ( wc_prices_include_tax() ) {
			return $price;
		}
		if ( ! isset( $rates[ $tax_class ] ) ) {
			$rates[ $tax_class ] = WC_Tax::get_base_tax_rates( $tax_class );
		}
		if ( ! $rates[ $tax_class ] ) {
			return $price;
		}
		return $price + array_sum( WC_Tax::calc_tax( $price, $rates[ $tax_class ], false ) );
	}

	private static function fmt_price( float $v, array $s ): string {
		$str = number_format( $v, wc_get_price_decimals(), '.', '' );
		if ( ( $s['decimalni_znak'] ?? '.' ) === ',' ) {
			$str = str_replace( '.', ',', $str );
		}
		return $str;
	}

	private static function x( string $v ): string {
		return htmlspecialchars( $v, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	private static function xml_row( array $row ): string {
		$out = "  <proizvod>\n";
		foreach ( $row as $k => $v ) {
			$out .= "    <{$k}>" . self::x( (string) $v ) . "</{$k}>\n";
		}
		return $out . "  </proizvod>\n";
	}

	/* ---------- Datoteke, retencija, index ---------- */

	public static function list_files(): array {
		$dir = self::files_dir();
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$out = [];
		foreach ( scandir( $dir ) ?: [] as $f ) {
			if ( ! preg_match( '/\.(csv|xml)$/i', $f ) ) {
				continue;
			}
			$path  = $dir . $f;
			$out[] = [
				'name'  => $f,
				'ext'   => strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ),
				'size'  => (int) filesize( $path ),
				'mtime' => (int) filemtime( $path ),
				'url'   => self::files_url() . rawurlencode( $f ),
			];
		}
		usort( $out, static fn( $a, $b ) => $b['mtime'] <=> $a['mtime'] ?: strcmp( $b['name'], $a['name'] ) );
		return $out;
	}

	public static function delete_file( string $name ): bool {
		$name = basename( $name );
		$ok   = false;
		foreach ( self::list_files() as $f ) {
			if ( $f['name'] === $name ) {
				$ok = wp_delete_file( self::files_dir() . $name ) === null || ! file_exists( self::files_dir() . $name );
				break;
			}
		}
		if ( $ok ) {
			$last = self::last();
			if ( ( $last['csv'] ?? '' ) === $name || ( $last['xml'] ?? '' ) === $name ) {
				$files = self::list_files();
				$csv   = null;
				$xml   = null;
				foreach ( $files as $f ) {
					if ( $f['ext'] === 'csv' && ! $csv ) {
						$csv = $f; }
					if ( $f['ext'] === 'xml' && ! $xml ) {
						$xml = $f; }
				}
				if ( $csv || $xml ) {
					$last['csv']  = $csv['name'] ?? '';
					$last['xml']  = $xml['name'] ?? '';
					$last['time'] = max( $csv['mtime'] ?? 0, $xml['mtime'] ?? 0 );
					update_option( self::LAST_OPT, $last, false );
				} else {
					delete_option( self::LAST_OPT );
				}
			}
			self::write_index();
		}
		return $ok;
	}

	public static function apply_retention(): void {
		$days   = max( 31, (int) SC_Settings::get( 'retencija_dana' ) );
		$cutoff = time() - $days * DAY_IN_SECONDS;
		foreach ( self::list_files() as $f ) {
			if ( $f['mtime'] < $cutoff ) {
				wp_delete_file( self::files_dir() . $f['name'] );
			}
		}
	}

	public static function write_index(): void {
		$data = class_exists( 'SC_Public' ) ? SC_Public::index_data() : [];
		file_put_contents( self::files_dir() . 'index.json', wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public static function last(): array {
		$l = get_option( self::LAST_OPT, [] );
		return is_array( $l ) ? $l : [];
	}
}
