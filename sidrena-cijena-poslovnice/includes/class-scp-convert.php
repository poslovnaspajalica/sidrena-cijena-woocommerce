<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pretvorba CSV-a s blagajne u propisanu strukturu cjenika (.csv + .xml).
 * Čista obrada datoteke, bez upita u bazu.
 */
final class SCP_Convert {

	/** Izlazni stupci: identični webshop pluginu (jedinstvena struktura za sve objekte lanca). */
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

	/** Prepoznavanje ulaznih stupaca po nazivu zaglavlja (mala slova, bez dijakritike). */
	private static function aliases(): array {
		return [
			'barkod'          => [ 'barkod', 'barcode', 'ean', 'gtin', 'ean13', 'bar_kod', 'bar kod' ],
			'naziv'           => [ 'naziv', 'naziv_proizvoda', 'naziv proizvoda', 'naziv artikla', 'naziv_artikla', 'naziv robe', 'name', 'artikl', 'proizvod', 'opis' ],
			'sifra'           => [ 'sifra', 'sifra_proizvoda', 'sku', 'code', 'kod', 'artikl_sifra' ],
			'marka'           => [ 'marka', 'brand', 'proizvodac', 'brend' ],
			'cijena'          => [ 'cijena', 'mpc', 'maloprodajna_cijena', 'maloprodajna cijena', 'redovna_cijena', 'redovna cijena', 'price', 'cijena_s_pdv', 'mpc_s_pdv' ],
			'akcijska'        => [ 'akcijska_cijena', 'akcijska cijena', 'akcija', 'sale_price', 'snizena_cijena', 'cijena_akcija', 'akcijska' ],
			'dostupnost'      => [ 'dostupnost', 'dostupno', 'zaliha', 'stanje', 'na_stanju', 'stock', 'availability', 'kolicina', 'količina' ],
			'sidrena'         => [ 'sidrena_cijena', 'sidrena cijena', 'sidrena', 'dodatna_cijena', 'anchor_price' ],
			'sidrena_datum'   => [ 'sidrena_cijena_datum', 'datum_sidrene', 'sidrena_datum' ],
			'jedinica'        => [ 'jedinica_mjere', 'jedinica mjere', 'jm', 'unit' ],
			'cijena_jedinica' => [ 'cijena_za_jedinicu_mjere', 'cijena za jedinicu mjere', 'cijena_jm', 'unit_price' ],
			'naziv_akcije'    => [ 'naziv_posebnog_oblika_prodaje', 'naziv_akcije', 'vrsta_akcije' ],
		];
	}

	private static function norm( string $h ): string {
		$h = mb_strtolower( trim( $h, " \t\"'" ), 'UTF-8' );
		$h = str_replace( [ 'č', 'ć', 'š', 'ž', 'đ' ], [ 'c', 'c', 's', 'z', 'd' ], $h );
		return preg_replace( '/[^a-z0-9_ ]/', '', $h ) ?? $h;
	}

	/** Učitaj datoteku kao UTF-8 tekst (podržava BOM i Windows-1250 s blagajni). */
	public static function read_text( string $path ): string {
		$raw = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokalna prenesena datoteka.
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw ) ?? $raw;
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			// Blagajne često izvoze u Windows-1250 (CP1250).
			$conv = function_exists( 'iconv' ) ? @iconv( 'CP1250', 'UTF-8//IGNORE', $raw ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- iconv baca notice na neispravne bajtove.
			if ( $conv === false && in_array( 'CP1250', mb_list_encodings(), true ) ) {
				$conv = mb_convert_encoding( $raw, 'UTF-8', 'CP1250' );
			}
			$raw = $conv !== false ? $conv : mb_convert_encoding( $raw, 'UTF-8', 'ISO-8859-2' );
		}
		return str_replace( [ "\r\n", "\r" ], "\n", $raw );
	}

	public static function detect_delimiter( string $header_line ): string {
		$best  = ';';
		$count = -1;
		foreach ( [ ';', ',', "\t", '|' ] as $d ) {
			$c = substr_count( $header_line, $d );
			if ( $c > $count ) {
				$count = $c;
				$best  = $d;
			}
		}
		return $best;
	}

	/**
	 * Parsiraj ulazni CSV.
	 * @return array{header:array,map:array,rows:array,delimiter:string,errors:array}
	 */
	public static function parse( string $path, array $forced_map = [] ): array {
		$text  = self::read_text( $path );
		$lines = explode( "\n", $text );
		while ( $lines && trim( (string) end( $lines ) ) === '' ) {
			array_pop( $lines );
		}
		if ( count( $lines ) < 2 ) {
			return [
				'header'    => [],
				'map'       => [],
				'rows'      => [],
				'delimiter' => ';',
				'errors'    => [ 'Datoteka je prazna ili nema redaka s podacima.' ],
			];
		}
		$delim  = self::detect_delimiter( $lines[0] );
		$header = str_getcsv( $lines[0], $delim, '"', '' );
		$normed = array_map( [ __CLASS__, 'norm' ], $header );

		$map  = [];
		$used = [];
		foreach ( $forced_map as $field => $idx ) {
			if ( $idx !== '' && isset( $header[ (int) $idx ] ) ) {
				$map[ $field ]      = (int) $idx;
				$used[ (int) $idx ] = true;
			}
		}
		// Tri prolaza: točan naziv, pa početak naziva, pa sadržava (npr. „Naziv artikla“, „MPC s PDV-om“).
		foreach ( [ 'exact', 'starts', 'contains' ] as $mode ) {
			foreach ( self::aliases() as $field => $names ) {
				if ( isset( $map[ $field ] ) ) {
					continue;
				}
				foreach ( $names as $n ) {
					$n = self::norm( $n );
					foreach ( $normed as $idx => $h ) {
						if ( isset( $used[ $idx ] ) || $h === '' ) {
							continue;
						}
						$hit = match ( $mode ) {
							'exact'  => $h === $n,
							'starts' => str_starts_with( $h, $n ),
							default  => str_contains( $h, $n ),
						};
						if ( $hit ) {
							$map[ $field ] = (int) $idx;
							$used[ $idx ]  = true;
							continue 3;
						}
					}
				}
			}
		}

		$errors = [];
		foreach ( [ 'barkod', 'naziv', 'cijena' ] as $req ) {
			if ( ! isset( $map[ $req ] ) ) {
				$errors[] = sprintf( 'Nedostaje obvezni stupac „%s“. Zaglavlje datoteke: %s', $req, implode( ' | ', $header ) );
			}
		}

		$rows = [];
		if ( ! $errors ) {
			$n = count( $lines );
			for ( $i = 1; $i < $n; $i++ ) {
				if ( trim( $lines[ $i ] ) === '' ) {
					continue;
				}
				$rows[] = str_getcsv( $lines[ $i ], $delim, '"', '' );
			}
		}
		return [
			'header'    => $header,
			'map'       => $map,
			'rows'      => $rows,
			'delimiter' => $delim,
			'errors'    => $errors,
		];
	}

	private static function num( ?string $v ): ?float {
		$v = trim( (string) $v );
		if ( $v === '' ) {
			return null;
		}
		$v = preg_replace( '/[^\d,.\-]/', '', $v ) ?? '';
		if ( str_contains( $v, ',' ) && str_contains( $v, '.' ) ) {
			$v = str_replace( '.', '', $v );
		}
		$v = str_replace( ',', '.', $v );
		return is_numeric( $v ) ? (float) $v : null;
	}

	private static function fmt( ?float $v, array $s ): string {
		if ( $v === null ) {
			return '';
		}
		$str = number_format( $v, 2, '.', '' );
		return ( $s['decimalni_znak'] ?? '.' ) === ',' ? str_replace( '.', ',', $str ) : $str;
	}

	private static function availability( ?string $v, string $fallback ): string {
		$t = strtolower( trim( (string) $v ) );
		if ( $t === '' ) {
			return $fallback;
		}
		if ( is_numeric( $t ) ) {
			return (float) $t > 0 ? 'dostupno' : 'nedostupno';
		}
		$yes = [ 'dostupno', 'da', 'yes', 'y', 'true', 'instock', 'in stock', 'na stanju', 'ima', 'raspolozivo', 'raspoloživo', 'onbackorder' ];
		$no  = [ 'nedostupno', 'ne', 'no', 'n', 'false', 'outofstock', 'out of stock', 'nema', 'rasprodano', '0' ];
		if ( in_array( $t, $yes, true ) ) {
			return 'dostupno';
		}
		if ( in_array( $t, $no, true ) ) {
			return 'nedostupno';
		}
		return $fallback;
	}

	/**
	 * Pretvori parsirane retke u izlazne retke (propisana struktura).
	 * @return array{rows:array,stats:array}
	 */
	public static function transform( array $parsed, array $s ): array {
		$map     = $parsed['map'];
		$g       = static fn( array $r, string $f ): ?string => isset( $map[ $f ], $r[ $map[ $f ] ] ) ? (string) $r[ $map[ $f ] ] : null;
		$stats   = [
			'ukupno'      => 0,
			'akcija'      => 0,
			'bez_sidrene' => 0,
			'bez_cijene'  => 0,
			'bez_barkoda' => 0,
			'nedostupno'  => 0,
		];
		$default = (string) ( $s['dostupnost_zadano'] ?? 'dostupno' );
		$out     = [];
		foreach ( $parsed['rows'] as $r ) {
			$cijena   = self::num( $g( $r, 'cijena' ) );
			$akcijska = self::num( $g( $r, 'akcijska' ) );
			$naziv    = trim( (string) $g( $r, 'naziv' ) );
			$barkod   = trim( (string) $g( $r, 'barkod' ) );
			if ( $naziv === '' && $barkod === '' ) {
				continue;
			}
			if ( $cijena === null ) {
				++$stats['bez_cijene'];
				continue;
			}
			$on_sale = $akcijska !== null && $akcijska > 0 && $akcijska < $cijena;
			$sidrena = self::num( $g( $r, 'sidrena' ) );
			$sd      = trim( (string) $g( $r, 'sidrena_datum' ) );
			$dost    = self::availability( $g( $r, 'dostupnost' ), $default );

			$row = [
				'naziv'                         => $naziv,
				'sifra'                         => trim( (string) $g( $r, 'sifra' ) ),
				'marka'                         => trim( (string) $g( $r, 'marka' ) ),
				'jedinica_mjere'                => trim( (string) $g( $r, 'jedinica' ) ),
				'cijena_za_jedinicu_mjere'      => self::fmt( self::num( $g( $r, 'cijena_jedinica' ) ), $s ),
				'maloprodajna_cijena'           => self::fmt( $on_sale ? $akcijska : $cijena, $s ),
				'posebni_oblik_prodaje'         => $on_sale ? 'DA' : 'NE',
				'naziv_posebnog_oblika_prodaje' => $on_sale ? ( trim( (string) $g( $r, 'naziv_akcije' ) ) ?: (string) $s['naziv_akcije'] ) : '',
				'sidrena_cijena'                => self::fmt( $sidrena, $s ),
				'barkod'                        => $barkod,
				'dostupnost'                    => $dost,
				'sidrena_cijena_datum'          => $sidrena !== null ? ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $sd ) ? $sd : (string) $s['referentni_datum'] ) : '',
				'kategorija'                    => '',
				'url'                           => '',
			];
			++$stats['ukupno'];
			$stats['akcija']      += $on_sale ? 1 : 0;
			$stats['bez_sidrene'] += $sidrena === null ? 1 : 0;
			$stats['bez_barkoda'] += $barkod === '' ? 1 : 0;
			$stats['nedostupno']  += $dost === 'nedostupno' ? 1 : 0;
			$out[]                 = apply_filters( 'scp_output_row', $row, $r, $map );
		}
		return [
			'rows'  => $out,
			'stats' => $stats,
		];
	}

	private static function x( string $v ): string {
		return htmlspecialchars( $v, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/** Zapiši CSV i XML u mapu poslovnice. Vraća nazive datoteka. */
	public static function write( array $store, array $rows, array $s, ?string $vrijedi_za = null ): array {
		$today      = wp_date( 'Y-m-d' );
		$vrijedi_za = ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $vrijedi_za ) && $vrijedi_za <= $today ) ? $vrijedi_za : $today;
		SCP_Files::ensure_dirs( $store['id'] );
		$dir = SCP_Files::store_dir( $store['id'] );
		$ts  = time();
		$csv = SCP_Files::build_filename( $store, 'csv', $ts );
		$xml = SCP_Files::build_filename( $store, 'xml', $ts );
		$i   = 1;
		while ( file_exists( $dir . $csv ) || file_exists( $dir . $xml ) ) {
			$csv = preg_replace( '/(\.csv)$/', "_{$i}$1", SCP_Files::build_filename( $store, 'csv', $ts ) );
			$xml = preg_replace( '/(\.xml)$/', "_{$i}$1", SCP_Files::build_filename( $store, 'xml', $ts ) );
			++$i;
		}
		$sep = (string) ( $s['csv_separator'] ?: ';' );
		$fh  = fopen( $dir . $csv, 'w' );
		fwrite( $fh, "\xEF\xBB\xBF" );
		fputcsv( $fh, self::columns(), $sep, '"', '' );
		foreach ( $rows as $row ) {
			fputcsv( $fh, array_values( $row ), $sep, '"', '' );
		}
		fclose( $fh );

		$xh = fopen( $dir . $xml, 'w' );
		fwrite( $xh, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" );
		fwrite(
			$xh,
			'<cjenik trgovac="' . self::x( (string) ( $s['naziv_trgovca'] ?: get_bloginfo( 'name' ) ) ) . '"'
			. ' oblik_objekta="' . self::x( (string) $store['oblik'] ) . '"'
			. ' adresa="' . self::x( (string) $store['adresa'] ) . '"'
			. ' oznaka_objekta="' . self::x( (string) $store['oznaka'] ) . '"'
			. ' broj_pohrane="' . self::x( (string) $store['broj_pohrane'] ) . '"'
			. ' referentni_datum="' . self::x( (string) $s['referentni_datum'] ) . '"'
			. ' vrijedi_za="' . self::x( $vrijedi_za ) . '"'
			. ' generirano="' . self::x( wp_date( 'c', $ts ) ) . '"'
			. ' valuta="EUR"' . ">\n"
		);
		foreach ( $rows as $row ) {
			fwrite( $xh, "  <proizvod>\n" );
			foreach ( $row as $k => $v ) {
				fwrite( $xh, "    <{$k}>" . self::x( (string) $v ) . "</{$k}>\n" );
			}
			fwrite( $xh, "  </proizvod>\n" );
		}
		fwrite( $xh, "</cjenik>\n" );
		fclose( $xh );

		SCP_Files::remember( $store['id'], $csv, $vrijedi_za );
		SCP_Files::remember( $store['id'], $xml, $vrijedi_za );
		SCP_Files::apply_retention( $store['id'] );
		SCP_Files::write_index();
		do_action( 'scp_published', $store, $csv, $xml, count( $rows ), $vrijedi_za );
		return [
			'csv'        => $csv,
			'xml'        => $xml,
			'time'       => $ts,
			'vrijedi_za' => $vrijedi_za,
		];
	}
}
