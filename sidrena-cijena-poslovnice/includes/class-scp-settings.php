<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Postavke i popis poslovnica.
 *
 * Pohrana (tablica wp_options):
 *  - scp_poslovnice           popis poslovnica (zaseban zapis, mijenja se samo izričitim spremanjem ili vraćanjem)
 *  - scp_poslovnice_povijest  zadnjih 30 verzija popisa (vrijeme, korisnik, izvor), za vraćanje
 *  - scp_brojaci              sljedeći broj pohrane po poslovnici (mijenja se pri svakoj objavi)
 *  - scp_settings             ostale postavke
 *  - scp_settings_backup      rezervna kopija postavki
 *
 * Svaki zapis čita trenutnu vrijednost izravno iz baze, mimo object cachea, kako zastarjeli keš
 * (Redis, Memcached, LiteSpeed) ne bi mogao prepisati novije podatke starijima.
 */
final class SCP_Settings {
	public const OPTION   = 'scp_settings';
	public const BACKUP   = 'scp_settings_backup';
	public const STORES   = 'scp_poslovnice';
	public const HISTORY  = 'scp_poslovnice_povijest';
	public const COUNTERS = 'scp_brojaci';

	/** Keš unutar jednog zahtjeva. */
	private static ?array $stores_cache = null;

	public static function defaults(): array {
		return [
			'naziv_trgovca'      => '',
			'referentni_datum'   => '2026-09-10',
			'naziv_akcije'       => 'Akcija',
			'marka_zadano'       => '',
			'csv_separator'      => ';',
			'decimalni_znak'     => '.',
			'retencija_dana'     => 35,
			'podsjetnik'         => 1,
			'podsjetnik_vrijeme' => '07:00',
			'podsjetnik_email'   => '',
			'javni_slug'         => 'cjenik',
			'dostupnost_zadano'  => 'dostupno',
		];
	}

	/* ---------- Izravno čitanje i pisanje (mimo object cachea) ---------- */

	/** Vrijednost opcije izravno iz baze; null ako redak ne postoji. */
	public static function raw( string $name ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		return $row ? maybe_unserialize( $row->option_value ) : null;
	}

	/** Zapiši opciju i očisti njezin keš, tako da sljedeće čitanje vidi novu vrijednost. */
	private static function write( string $name, $value ): void {
		update_option( $name, $value, false );
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/* ---------- Postavke ---------- */

	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		$all   = array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
		unset( $all['poslovnice'] ); // poslovnice su u zasebnom zapisu
		$all['poslovnice'] = self::stores();
		return $all;
	}

	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/** Spremi postavke. Popis poslovnica se ovdje nikad ne mijenja. */
	public static function update( array $data ): void {
		unset( $data['poslovnice'] );
		$current = self::raw( self::OPTION );
		$current = is_array( $current ) ? $current : [];
		unset( $current['poslovnice'] );
		$all = array_merge( self::defaults(), $current, $data );
		self::write( self::OPTION, $all );
		self::write( self::BACKUP, $all );
	}

	/* ---------- Poslovnice ---------- */

	/** @return array<int, array> */
	public static function stores(): array {
		if ( self::$stores_cache === null ) {
			$list               = self::raw( self::STORES );
			self::$stores_cache = array_values( array_filter( is_array( $list ) ? $list : [], static fn( $p ) => is_array( $p ) && ! empty( $p['id'] ) ) );
			$counters           = self::raw( self::COUNTERS );
			$counters           = is_array( $counters ) ? $counters : [];
			foreach ( self::$stores_cache as &$st ) {
				$st['broj_pohrane'] = (string) max( 1, (int) ( $counters[ $st['id'] ] ?? $st['broj_pohrane'] ?? 1 ) );
			}
			unset( $st );
		}
		return self::$stores_cache;
	}

	public static function store( string $id ): ?array {
		foreach ( self::stores() as $p ) {
			if ( $p['id'] === $id ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Spremi popis poslovnica i zapiši verziju u povijest.
	 *
	 * @param array  $list   Poslovnice (id, naziv, oblik, adresa, oznaka, broj_pohrane).
	 * @param string $source Izvor promjene (admin, vraćanje, migracija).
	 */
	public static function save_stores( array $list, string $source ): void {
		$list = array_values( array_filter( $list, static fn( $p ) => is_array( $p ) && ! empty( $p['id'] ) ) );
		self::write( self::STORES, $list );

		$counters = self::raw( self::COUNTERS );
		$counters = is_array( $counters ) ? $counters : [];
		foreach ( $list as $st ) {
			$counters[ $st['id'] ] = max( 1, (int) ( $st['broj_pohrane'] ?? 1 ) );
		}
		self::write( self::COUNTERS, $counters );

		$history = self::raw( self::HISTORY );
		$history = is_array( $history ) ? $history : [];
		$user    = wp_get_current_user();
		array_unshift(
			$history,
			[
				'vrijeme'    => time(),
				'korisnik'   => $user && $user->exists() ? $user->user_login : '',
				'izvor'      => $source,
				'poslovnice' => $list,
			]
		);
		self::write( self::HISTORY, array_slice( $history, 0, 30 ) );
		self::$stores_cache = null;
	}

	/** Sljedeći broj pohrane za poslovnicu; povećava samo brojač, popis poslovnica se ne dira. */
	public static function next_seq( string $store_id ): int {
		$counters = self::raw( self::COUNTERS );
		$counters = is_array( $counters ) ? $counters : [];
		$st       = self::store( $store_id );
		$seq      = max( 1, (int) ( $counters[ $store_id ] ?? ( $st['broj_pohrane'] ?? 1 ) ) );
		$counters[ $store_id ] = $seq + 1;
		self::write( self::COUNTERS, $counters );
		self::$stores_cache = null;
		return $seq;
	}

	/** @return array<int, array{vrijeme:int,korisnik:string,izvor:string,poslovnice:array}> */
	public static function history(): array {
		$h = self::raw( self::HISTORY );
		return is_array( $h ) ? $h : [];
	}

	/**
	 * Prijenos iz starijih verzija (poslovnice unutar scp_settings) i vraćanje ako je popis prazan.
	 * Ne piše ništa ako zaseban popis već postoji i nije prazan. Vraća opis što je napravljeno ili ''.
	 */
	public static function migrate_stores(): string {
		$current = self::raw( self::STORES );
		if ( is_array( $current ) && $current ) {
			return '';
		}
		$candidates = [
			'postavke'          => self::raw( self::OPTION ),
			'rezervna kopija'   => self::raw( self::BACKUP ),
		];
		foreach ( $candidates as $label => $src ) {
			if ( is_array( $src ) && ! empty( $src['poslovnice'] ) && is_array( $src['poslovnice'] ) ) {
				self::save_stores( $src['poslovnice'], 'migracija iz: ' . $label );
				return $label;
			}
		}
		foreach ( self::history() as $h ) {
			if ( ! empty( $h['poslovnice'] ) ) {
				self::save_stores( $h['poslovnice'], 'vraćeno iz povijesti' );
				return 'povijest';
			}
		}
		return '';
	}

	/** 2026-09-10 -> 10. 9. 2026. */
	public static function format_date( ?string $ymd ): string {
		$ts = $ymd ? strtotime( $ymd . ' 12:00:00' ) : false;
		return $ts ? sprintf( '%d. %d. %d.', (int) gmdate( 'j', $ts ), (int) gmdate( 'n', $ts ), (int) gmdate( 'Y', $ts ) ) : (string) $ymd;
	}
}
