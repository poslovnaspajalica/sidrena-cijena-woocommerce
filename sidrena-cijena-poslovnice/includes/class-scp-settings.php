<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SCP_Settings {
	public const OPTION = 'scp_settings';

	public static function defaults(): array {
		return [
			'naziv_trgovca'      => '',
			'poslovnice'         => [], // popis poslovnica: id, naziv, oblik, adresa, oznaka, broj_pohrane
			'referentni_datum'   => '2026-09-10',
			'naziv_akcije'       => 'Akcija',
			'marka_zadano' => '',
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

	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	public const BACKUP = 'scp_settings_backup';

	public static function update( array $data ): void {
		$all = array_merge( self::all(), $data );
		update_option( self::OPTION, $all, false );
		// Rezervna kopija: ako glavne postavke nestanu (nadogradnja, čišćenje baze, keš), vraćaju se odavde.
		update_option( self::BACKUP, $all, false );
	}

	/**
	 * Vrati postavke iz rezervne kopije ako glavne nedostaju ili su bez poslovnica, a kopija ih ima.
	 * Vraća true ako je nešto vraćeno.
	 */
	public static function restore_if_missing(): bool {
		$main   = get_option( self::OPTION, null );
		$backup = get_option( self::BACKUP, null );
		if ( ! is_array( $backup ) || empty( $backup['poslovnice'] ) ) {
			return false;
		}
		if ( ! is_array( $main ) || empty( $main['poslovnice'] ) ) {
			update_option( self::OPTION, array_merge( is_array( $main ) ? $main : [], $backup ), false );
			return true;
		}
		return false;
	}

	/** @return array<int, array> */
	public static function stores(): array {
		$s = (array) self::get( 'poslovnice' );
		return array_values( array_filter( $s, static fn( $p ) => is_array( $p ) && ! empty( $p['id'] ) ) );
	}

	public static function store( string $id ): ?array {
		foreach ( self::stores() as $p ) {
			if ( $p['id'] === $id ) {
				return $p;
			}
		}
		return null;
	}

	/** 2026-09-10 -> 10. 9. 2026. */
	public static function format_date( ?string $ymd ): string {
		$ts = $ymd ? strtotime( $ymd . ' 12:00:00' ) : false;
		return $ts ? sprintf( '%d. %d. %d.', (int) gmdate( 'j', $ts ), (int) gmdate( 'n', $ts ), (int) gmdate( 'Y', $ts ) ) : (string) $ymd;
	}
}
