<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trajna tablica baznih (sidrenih) cijena po poslovnici.
 *
 * Tablica {prefix}scp_bazne: jedna cijena po artiklu i poslovnici, prepoznaje se po šifri (ili barkodu ako
 * šifre nema). Puni se uvozom cjenika na referentni dan, a artikli koji se pojave kasnije upisuju se pri
 * prvoj objavi s tadašnjom redovnom cijenom i datumom (cijena prvog uvrštenja u ponudu).
 * Aktivacija, deaktivacija i nadogradnja nikad ne brišu podatke iz tablice.
 */
final class SCP_Bazne {
	public const DB_VERSION = '1';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'scp_bazne';
	}

	/** Stvori ili nadogradi tablicu (dbDelta ne briše postojeće podatke). */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  store_id varchar(64) NOT NULL,
  kljuc varchar(100) NOT NULL,
  sifra varchar(64) NOT NULL DEFAULT '',
  barkod varchar(64) NOT NULL DEFAULT '',
  cijena decimal(12,2) NOT NULL,
  datum date NOT NULL,
  izvor varchar(20) NOT NULL DEFAULT '',
  azurirano datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY store_kljuc (store_id,kljuc),
  KEY store_barkod (store_id,barkod)
) {$charset};"
		);
		update_option( 'scp_bazne_db', self::DB_VERSION, false );
	}

	public static function exists(): bool {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) === $t;
	}

	public static function key( string $sifra, string $barkod ): string {
		$sifra = trim( $sifra );
		return $sifra !== '' ? 'S:' . $sifra : 'B:' . trim( $barkod );
	}

	/** Broj zapisa i zadnja promjena za poslovnicu. */
	public static function stats( string $store_id ): array {
		global $wpdb;
		if ( ! self::exists() ) {
			return [
				'ukupno'    => 0,
				'azurirano' => null,
				'po_izvoru' => [],
			];
		}
		$t    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT izvor, COUNT(*) AS n, MAX(azurirano) AS zadnje FROM {$t} WHERE store_id = %s GROUP BY izvor", $store_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- naziv tablice.
		$out  = [
			'ukupno'    => 0,
			'azurirano' => null,
			'po_izvoru' => [],
		];
		foreach ( $rows as $r ) {
			$out['ukupno']                  += (int) $r['n'];
			$out['po_izvoru'][ $r['izvor'] ] = (int) $r['n'];
			$out['azurirano']                = max( (string) $out['azurirano'], (string) $r['zadnje'] );
		}
		return $out;
	}

	/**
	 * Sve bazne cijene poslovnice za brzo traženje.
	 *
	 * @return array{k:array<string,array>,b:array<string,array>}
	 */
	public static function load( string $store_id ): array {
		global $wpdb;
		$map = [
			'k' => [],
			'b' => [],
		];
		if ( ! self::exists() ) {
			return $map;
		}
		$t = self::table();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT kljuc, barkod, cijena, datum FROM {$t} WHERE store_id = %s", $store_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- naziv tablice.
			$v                       = [
				'cijena' => (float) $r['cijena'],
				'datum'  => (string) $r['datum'],
			];
			$map['k'][ $r['kljuc'] ] = $v;
			if ( $r['barkod'] !== '' ) {
				$map['b'][ $r['barkod'] ] = $v;
			}
		}
		return $map;
	}

	/** Pronađi baznu cijenu po šifri, pa po barkodu. */
	public static function find( array $map, string $sifra, string $barkod ): ?array {
		$sifra  = trim( $sifra );
		$barkod = trim( $barkod );
		if ( $sifra !== '' && isset( $map['k'][ 'S:' . $sifra ] ) ) {
			return $map['k'][ 'S:' . $sifra ];
		}
		if ( $barkod !== '' ) {
			if ( isset( $map['b'][ $barkod ] ) ) {
				return $map['b'][ $barkod ];
			}
			if ( isset( $map['k'][ 'B:' . $barkod ] ) ) {
				return $map['k'][ 'B:' . $barkod ];
			}
		}
		return null;
	}

	/**
	 * Upiši bazne cijene.
	 *
	 * @param string $store_id  Poslovnica.
	 * @param array  $items     [ ['sifra','barkod','cijena','datum','izvor'], ... ].
	 * @param bool   $overwrite Prepiši postojeće (inače se postojeće zadržavaju).
	 * @return array{upisano:int,preskoceno:int}
	 */
	public static function save( string $store_id, array $items, bool $overwrite ): array {
		global $wpdb;
		if ( ! self::exists() ) {
			self::install();
		}
		$t     = self::table();
		$now   = current_time( 'mysql' );
		$seen  = [];
		$vals  = [];
		$total = 0;
		foreach ( $items as $it ) {
			$k = self::key( (string) $it['sifra'], (string) $it['barkod'] );
			if ( $k === 'B:' || isset( $seen[ $k ] ) || (float) $it['cijena'] <= 0 ) {
				continue;
			}
			$seen[ $k ] = true;
			$vals[]     = $wpdb->prepare( '(%s,%s,%s,%s,%s,%s,%s,%s)', $store_id, $k, trim( (string) $it['sifra'] ), trim( (string) $it['barkod'] ), number_format( (float) $it['cijena'], 2, '.', '' ), (string) $it['datum'], (string) $it['izvor'], $now );
			++$total;
		}
		$affected = 0;
		$verb     = $overwrite ? 'INSERT' : 'INSERT IGNORE';
		$tail     = $overwrite ? ' ON DUPLICATE KEY UPDATE sifra=VALUES(sifra), barkod=VALUES(barkod), cijena=VALUES(cijena), datum=VALUES(datum), izvor=VALUES(izvor), azurirano=VALUES(azurirano)' : '';
		foreach ( array_chunk( $vals, 500 ) as $chunk ) {
			// Svaki element $chunk je već prošao kroz $wpdb->prepare().
			$r         = $wpdb->query( "{$verb} INTO {$t} (store_id,kljuc,sifra,barkod,cijena,datum,izvor,azurirano) VALUES " . implode( ',', $chunk ) . $tail ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$affected += (int) $r;
		}
		$upisano = $overwrite ? $total : $affected;
		return [
			'upisano'    => $upisano,
			'preskoceno' => $total - $upisano,
		];
	}

	/** Svi zapisi poslovnice (za izvoz). */
	public static function all( string $store_id ): array {
		global $wpdb;
		if ( ! self::exists() ) {
			return [];
		}
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT sifra, barkod, cijena, datum, izvor, azurirano FROM {$t} WHERE store_id = %s ORDER BY sifra, barkod", $store_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- naziv tablice.
	}

	public static function delete_store( string $store_id ): int {
		global $wpdb;
		if ( ! self::exists() ) {
			return 0;
		}
		return (int) $wpdb->delete( self::table(), [ 'store_id' => $store_id ] );
	}
}
