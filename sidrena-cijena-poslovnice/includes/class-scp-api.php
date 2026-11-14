<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automatski prijem cjenika s blagajne ili iz poslovnog programa (npr. 4D Wand).
 *
 * POST {rest_url}/sidrena-poslovnice/v1/cjenik
 *   zaglavlje  X-SCP-Kljuc: <ključ iz postavki>
 *   polje      datoteka   CSV ili Excel
 *   polje      skladiste  (neobavezno) šifra skladišta; inače se traži u nazivu datoteke
 *
 * GET {rest_url}/sidrena-poslovnice/v1/status  provjera veze i ključa (ništa ne mijenja).
 *
 * Datoteka se pretvara i objavljuje isto kao ručni upload. Odbija se ako je neispravna ili ima
 * puno manje redaka nego zadnja objava te poslovnice, pa krnji izvoz ne može zamijeniti dobar cjenik.
 * Ista datoteka poslana dvaput objavljuje se samo jednom.
 */
final class SCP_Api {
	public const NS    = 'sidrena-poslovnice/v1';
	public const LOG   = 'scp_api_log';
	public const STATE = 'scp_api_stanje';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'scp_published', [ __CLASS__, 'remember_publish' ], 10, 5 );
	}

	public static function endpoint(): string {
		return rest_url( self::NS . '/cjenik' );
	}

	public static function clean_code( string $v ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9.\-]/', '', trim( $v ) ), 0, 32 );
	}

	public static function generate_key(): string {
		return wp_generate_password( 40, false, false );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/cjenik',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'receive' ],
				'permission_callback' => [ __CLASS__, 'auth' ],
			]
		);
		register_rest_route(
			self::NS,
			'/status',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'status' ],
				'permission_callback' => [ __CLASS__, 'auth' ],
			]
		);
	}

	private static function ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/** Provjera ključa, s ograničenjem neuspjelih pokušaja po IP adresi. */
	public static function auth( WP_REST_Request $req ) {
		if ( ! SCP_Settings::get( 'api_ukljuceno' ) ) {
			return new WP_Error( 'scp_iskljuceno', 'Automatski prijem cjenika je isključen u postavkama.', [ 'status' => 403 ] );
		}
		$fail_key = 'scp_api_fail_' . md5( self::ip() );
		$fails    = (int) get_transient( $fail_key );
		if ( $fails >= 10 ) {
			return new WP_Error( 'scp_previse', 'Previše neuspjelih pokušaja, pokušaj za sat vremena.', [ 'status' => 429 ] );
		}
		$expected = (string) SCP_Settings::get( 'api_kljuc' );
		$given    = (string) $req->get_header( 'x-scp-kljuc' );
		if ( $expected === '' || $given === '' || ! hash_equals( $expected, $given ) ) {
			set_transient( $fail_key, $fails + 1, HOUR_IN_SECONDS );
			self::log( '', '', 'odbijeno', 'Pogrešan ili nedostaje ključ.' );
			return new WP_Error( 'scp_kljuc', 'Pogrešan ključ.', [ 'status' => 401 ] );
		}
		return true;
	}

	public static function status(): WP_REST_Response {
		$out = [];
		foreach ( SCP_Settings::stores() as $st ) {
			$out[] = [
				'poslovnica' => $st['naziv'],
				'skladiste'  => $st['skladiste'] ?? '',
				'danas'      => SCP_Files::has_today( $st['id'] ),
			];
		}
		return new WP_REST_Response(
			[
				'status'     => 'ok',
				'verzija'    => SCP_VERSION,
				'vrijeme'    => wp_date( 'c' ),
				'poslovnice' => $out,
			],
			200
		);
	}

	/** Poslovnica po izričitoj šifri ili po šifri skladišta koja je zaseban dio naziva datoteke. */
	public static function find_store( string $code, string $filename ): array {
		$stores = array_values( array_filter( SCP_Settings::stores(), static fn( $s ) => ( $s['skladiste'] ?? '' ) !== '' ) );
		if ( $code !== '' ) {
			foreach ( $stores as $s ) {
				if ( strcasecmp( $s['skladiste'], $code ) === 0 ) {
					return [ $s ];
				}
			}
			return [];
		}
		// Šifra kao cijela riječ u nazivu, npr. "..._PJ2-0000112_2_20261113_111226.csv".
		$name = (string) pathinfo( $filename, PATHINFO_FILENAME );
		return array_values( array_filter( $stores, static fn( $s ) => (bool) preg_match( '/(?<![A-Za-z0-9])' . preg_quote( $s['skladiste'], '/' ) . '(?![A-Za-z0-9])/i', $name ) ) );
	}

	public static function receive( WP_REST_Request $req ) {
		$files = $req->get_file_params();
		$f     = $files['datoteka'] ?? null;
		if ( ! is_array( $f ) || (int) ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
			return self::fail( null, '', 'Datoteka nije primljena (polje "datoteka").', 400 );
		}
		$orig = sanitize_file_name( (string) $f['name'] );
		if ( (int) $f['size'] > 20 * MB_IN_BYTES ) {
			return self::fail( null, $orig, 'Datoteka je veća od 20 MB.', 413 );
		}
		$ext = strtolower( pathinfo( $orig, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, SCP_Convert::supported_extensions(), true ) ) {
			return self::fail( null, $orig, 'Nepodržana vrsta datoteke: ' . $ext, 415 );
		}
		$found = self::find_store( self::clean_code( (string) $req->get_param( 'skladiste' ) ), $orig );
		if ( count( $found ) !== 1 ) {
			$msg = $found ? 'Naziv datoteke odgovara za više poslovnica.' : 'Nijedna poslovnica nema šifru skladišta iz naziva datoteke (postavi je na kartici Poslovnice).';
			return self::fail( null, $orig, $msg, 422 );
		}
		$store = $found[0];

		// Jedna obrada po poslovnici u isto vrijeme.
		$lock = 'scp_api_lock_' . $store['id'];
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( time() - (int) get_option( $lock ) < 10 * MINUTE_IN_SECONDS ) {
				return new WP_Error( 'scp_u_tijeku', 'Obrada za ovu poslovnicu je u tijeku, pokušaj ponovno.', [ 'status' => 409 ] );
			}
			update_option( $lock, time(), false );
		}
		try {
			return self::process( $store, (string) $f['tmp_name'], $ext, $orig );
		} finally {
			delete_option( $lock );
		}
	}

	private static function process( array $store, string $tmp, string $ext, string $orig ) {
		$dan   = wp_date( 'Y-m-d' );
		$hash  = $dan . '|' . (string) sha1_file( $tmp ); // duplikat je samo ista datoteka istog dana
		$state = self::state( $store['id'] );
		if ( in_array( $hash, $state['hashes'], true ) ) {
			self::log( $store['id'], $orig, 'duplikat', 'Ista datoteka je danas već objavljena, preskočeno.' );
			return new WP_REST_Response(
				[
					'status'     => 'vec_objavljeno',
					'poslovnica' => $store['naziv'],
				],
				200
			);
		}

		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}
		$parsed = SCP_Convert::parse( $tmp, $ext );
		if ( $parsed['errors'] ) {
			return self::fail( $store, $orig, implode( ' ', $parsed['errors'] ), 422 );
		}
		$t = SCP_Convert::transform( $parsed, SCP_Settings::all(), $store['id'], $dan );
		$n = count( $t['rows'] );
		if ( ! $n ) {
			return self::fail( $store, $orig, 'U datoteci nema nijednog retka s nazivom i cijenom.', 422 );
		}
		$pct = max( 0, min( 100, (int) SCP_Settings::get( 'api_min_postotak' ) ) );
		if ( $pct > 0 && $state['redaka'] > 0 && $n < $state['redaka'] * $pct / 100 ) {
			return self::fail( $store, $orig, sprintf( 'Datoteka ima %d artikala, a zadnja objava %d (manje od %d %%). Nije objavljeno; ako je ispravno, objavi je ručno.', $n, $state['redaka'], $pct ), 422 );
		}

		$r = SCP_Convert::write( $store, $t['rows'], SCP_Settings::all(), $dan );
		if ( $t['nove'] ) {
			SCP_Bazne::save( $store['id'], $t['nove'], false );
		}
		$state           = self::state( $store['id'] ); // write() je osvježio broj redaka
		$state['hashes'] = array_slice( array_merge( [ $hash ], $state['hashes'] ), 0, 20 );
		self::save_state( $store['id'], $state );
		self::log( $store['id'], $orig, 'objavljeno', sprintf( '%d artikala, %s', $n, $r['csv'] ) );

		return new WP_REST_Response(
			[
				'status'     => 'objavljeno',
				'poslovnica' => $store['naziv'],
				'artikala'   => $n,
				'csv'        => $r['csv'],
				'xml'        => $r['xml'],
				'vrijedi_za' => $r['vrijedi_za'],
				'statistika' => $t['stats'],
			],
			200
		);
	}

	/* ---------- Stanje, dnevnik, obavijesti ---------- */

	/** @return array{redaka:int,vrijeme:int,hashes:array<int,string>} */
	public static function state( string $store_id ): array {
		$all = SCP_Settings::raw( self::STATE );
		$st  = is_array( $all ) && isset( $all[ $store_id ] ) && is_array( $all[ $store_id ] ) ? $all[ $store_id ] : [];
		return [
			'redaka'  => (int) ( $st['redaka'] ?? 0 ),
			'vrijeme' => (int) ( $st['vrijeme'] ?? 0 ),
			'hashes'  => array_values( array_filter( (array) ( $st['hashes'] ?? [] ), 'is_string' ) ),
		];
	}

	private static function save_state( string $store_id, array $state ): void {
		$all              = SCP_Settings::raw( self::STATE );
		$all              = is_array( $all ) ? $all : [];
		$all[ $store_id ] = $state;
		update_option( self::STATE, $all, false );
		wp_cache_delete( self::STATE, 'options' );
	}

	/** Svaka objava (ručna ili automatska) pamti broj artikala za provjeru sljedeće datoteke. */
	public static function remember_publish( array $store, string $csv, string $xml, int $count ): void {
		$state            = self::state( $store['id'] );
		$state['redaka']  = $count;
		$state['vrijeme'] = time();
		self::save_state( $store['id'], $state );
	}

	public static function log( string $store_id, string $file, string $status, string $msg ): void {
		$log = SCP_Settings::raw( self::LOG );
		$log = is_array( $log ) ? $log : [];
		array_unshift(
			$log,
			[
				'vrijeme'    => time(),
				'ip'         => self::ip(),
				'poslovnica' => $store_id,
				'datoteka'   => $file,
				'status'     => $status,
				'poruka'     => $msg,
			]
		);
		update_option( self::LOG, array_slice( $log, 0, 100 ), false );
		wp_cache_delete( self::LOG, 'options' );
	}

	/** @return array<int, array> */
	public static function entries( int $limit = 30 ): array {
		$log = SCP_Settings::raw( self::LOG );
		return array_slice( is_array( $log ) ? $log : [], 0, $limit );
	}

	private static function fail( ?array $store, string $file, string $msg, int $code ): WP_Error {
		self::log( $store['id'] ?? '', $file, 'greska', $msg );
		$key = 'scp_api_mail_' . md5( ( $store['id'] ?? '' ) . $msg );
		if ( ! get_transient( $key ) ) {
			set_transient( $key, 1, HOUR_IN_SECONDS );
			$to = (string) SCP_Settings::get( 'api_email' ) ?: ( (string) SCP_Settings::get( 'podsjetnik_email' ) ?: get_option( 'admin_email' ) );
			wp_mail(
				$to,
				'[' . get_bloginfo( 'name' ) . '] Automatski prijem cjenika nije uspio' . ( $store ? ': ' . $store['naziv'] : '' ),
				"Datoteka: {$file}\nPoslovnica: " . ( $store['naziv'] ?? 'nije prepoznata' ) . "\nRazlog: {$msg}\n\nCjenik te poslovnice nije zamijenjen. Ako se ne riješi do 8:00, objavi ga ručno:\n" . admin_url( 'admin.php?page=scp-cjenik' ) . "\n"
			);
		}
		return new WP_Error( 'scp_odbijeno', $msg, [ 'status' => $code ] );
	}
}
