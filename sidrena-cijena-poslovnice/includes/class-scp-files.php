<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Datoteke cjenika po poslovnici: mape, popis, retencija, index, podsjetnik.
 */
final class SCP_Files {
	public const REMINDER_HOOK = 'scp_daily_reminder';

	public static function init(): void {
		add_action( self::REMINDER_HOOK, [ __CLASS__, 'send_reminder' ] );
	}

	public static function base_dir(): string {
		$u = wp_upload_dir();
		return trailingslashit( $u['basedir'] ) . 'sidrena-cijena/poslovnice/';
	}

	public static function base_url(): string {
		$u = wp_upload_dir();
		return trailingslashit( $u['baseurl'] ) . 'sidrena-cijena/poslovnice/';
	}

	public static function tmp_dir(): string {
		return self::base_dir() . 'tmp/';
	}

	public static function store_dir( string $store_id ): string {
		return self::base_dir() . sanitize_key( $store_id ) . '/';
	}

	public static function store_url( string $store_id ): string {
		return self::base_url() . sanitize_key( $store_id ) . '/';
	}

	public static function ensure_dirs( ?string $store_id = null ): void {
		$dirs = [ self::base_dir(), self::tmp_dir() ];
		if ( $store_id ) {
			$dirs[] = self::store_dir( $store_id );
		}
		foreach ( $dirs as $d ) {
			if ( ! is_dir( $d ) ) {
				wp_mkdir_p( $d );
			}
		}
		if ( ! file_exists( self::tmp_dir() . '.htaccess' ) ) {
			file_put_contents( self::tmp_dir() . '.htaccess', "Require all denied\n" );
		}
		if ( ! file_exists( self::tmp_dir() . 'index.html' ) ) {
			file_put_contents( self::tmp_dir() . 'index.html', '' );
		}
	}

	/** Naziv datoteke po točki VI. Odluke. */
	public static function build_filename( array $store, string $ext, ?int $ts = null ): string {
		$ts    = $ts ?? time();
		$parts = [
			sanitize_title( (string) ( $store['oblik'] ?? '' ) ) ?: 'prodavaonica',
			sanitize_title( (string) ( $store['adresa'] ?? '' ) ) ?: 'adresa',
			sanitize_title( (string) ( $store['oznaka'] ?? '' ) ) ?: sanitize_title( $store['id'] ),
			sanitize_title( (string) ( $store['broj_pohrane'] ?? '' ) ) ?: '1',
			wp_date( 'Ymd_Hi', $ts ),
		];
		return implode( '_', $parts ) . '.' . $ext;
	}

	/** @return array<int, array{name:string,ext:string,size:int,mtime:int,url:string}> najnovije prvo */
	public static function list_files( string $store_id ): array {
		$dir = self::store_dir( $store_id );
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$out = [];
		foreach ( scandir( $dir ) ?: [] as $f ) {
			if ( ! preg_match( '/\.(csv|xml)$/i', $f ) ) {
				continue;
			}
			$out[] = [
				'name'  => $f,
				'ext'   => strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ),
				'size'  => (int) filesize( $dir . $f ),
				'mtime' => (int) filemtime( $dir . $f ),
				'url'   => self::store_url( $store_id ) . rawurlencode( $f ),
			];
		}
		usort( $out, static fn( $a, $b ) => $b['mtime'] <=> $a['mtime'] ?: strcmp( $b['name'], $a['name'] ) );
		return $out;
	}

	/** Najnoviji par (csv, xml) za poslovnicu. */
	public static function latest( string $store_id ): array {
		$latest = [
			'csv' => null,
			'xml' => null,
		];
		foreach ( self::list_files( $store_id ) as $f ) {
			if ( ! $latest[ $f['ext'] ] ) {
				$latest[ $f['ext'] ] = $f;
			}
		}
		return $latest;
	}

	public static function has_today( string $store_id ): bool {
		$l = self::latest( $store_id );
		return ! empty( $l['csv'] ) && wp_date( 'Y-m-d', $l['csv']['mtime'] ) === wp_date( 'Y-m-d' );
	}

	public static function apply_retention( string $store_id ): void {
		$days   = max( 31, (int) SCP_Settings::get( 'retencija_dana' ) );
		$cutoff = time() - $days * DAY_IN_SECONDS;
		foreach ( self::list_files( $store_id ) as $f ) {
			if ( $f['mtime'] < $cutoff ) {
				wp_delete_file( self::store_dir( $store_id ) . $f['name'] );
			}
		}
	}

	public static function delete_file( string $store_id, string $name ): bool {
		$name = basename( $name );
		foreach ( self::list_files( $store_id ) as $f ) {
			if ( $f['name'] === $name ) {
				wp_delete_file( self::store_dir( $store_id ) . $name );
				self::write_index();
				return ! file_exists( self::store_dir( $store_id ) . $name );
			}
		}
		return false;
	}

	/** Odjeljci za javnu stranicu (isti oblik koristi i webshop plugin). */
	public static function sections(): array {
		$out = [];
		foreach ( SCP_Settings::stores() as $store ) {
			$latest = self::latest( $store['id'] );
			$out[]  = [
				'id'      => 'poslovnica-' . sanitize_key( $store['id'] ),
				'naziv'   => $store['naziv'] ?: $store['adresa'],
				'opis'    => trim( ( $store['oblik'] ?? '' ) . ', ' . ( $store['adresa'] ?? '' ), ', ' ),
				'files'   => self::list_files( $store['id'] ),
				'latest'  => [
					'csv' => $latest['csv']['url'] ?? null,
					'xml' => $latest['xml']['url'] ?? null,
				],
				'updated' => $latest['csv']['mtime'] ?? null,
			];
		}
		return $out;
	}

	public static function write_index(): void {
		$data = [
			'trgovac'    => SCP_Settings::get( 'naziv_trgovca' ) ?: get_bloginfo( 'name' ),
			'generirano' => wp_date( 'c' ),
			'objekti'    => array_map(
				static fn( $s ) => [
					'id'        => $s['id'],
					'naziv'     => $s['naziv'],
					'opis'      => $s['opis'],
					'najnoviji' => $s['latest'],
					'datoteke'  => array_map(
						static fn( $f ) => [
							'naziv'    => $f['name'],
							'format'   => $f['ext'],
							'velicina' => $f['size'],
							'datum'    => wp_date( 'c', $f['mtime'] ),
							'url'      => $f['url'],
						],
						$s['files']
					),
				],
				self::sections()
			),
		];
		file_put_contents( self::base_dir() . 'index.json', wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/* ---------- Podsjetnik ---------- */

	public static function schedule_reminder(): void {
		self::unschedule_reminder();
		if ( ! SCP_Settings::get( 'podsjetnik' ) ) {
			return;
		}
		$hhmm = (string) SCP_Settings::get( 'podsjetnik_vrijeme' );
		if ( ! preg_match( '/^\d{1,2}:\d{2}$/', $hhmm ) ) {
			$hhmm = '07:00';
		}
		$tz   = wp_timezone();
		$next = new DateTimeImmutable( 'today ' . $hhmm, $tz );
		if ( $next <= new DateTimeImmutable( 'now', $tz ) ) {
			$next = $next->modify( '+1 day' );
		}
		wp_schedule_event( $next->getTimestamp(), 'daily', self::REMINDER_HOOK );
	}

	public static function unschedule_reminder(): void {
		wp_clear_scheduled_hook( self::REMINDER_HOOK );
	}

	/** Jedan e-mail s popisom poslovnica koje danas još nemaju cjenik. Ne obrađuje podatke. */
	public static function send_reminder(): void {
		if ( ! SCP_Settings::get( 'podsjetnik' ) ) {
			return;
		}
		$missing = [];
		foreach ( SCP_Settings::stores() as $store ) {
			if ( ! self::has_today( $store['id'] ) ) {
				$missing[] = $store['naziv'] ?: $store['adresa'];
			}
		}
		if ( ! $missing ) {
			return;
		}
		$to = (string) SCP_Settings::get( 'podsjetnik_email' ) ?: get_option( 'admin_email' );
		wp_mail(
			$to,
			'[' . get_bloginfo( 'name' ) . '] Cjenik poslovnica još nije objavljen za danas',
			"Za sljedeće poslovnice danas još nije objavljen cjenik (rok je 8:00):\n\n- " . implode( "\n- ", $missing ) . "\n\nObjava: " . admin_url( 'admin.php?page=scp-cjenik' ) . "\n"
		);
	}
}
