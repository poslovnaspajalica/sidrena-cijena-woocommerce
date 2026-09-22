<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bilježenje sidrene cijene u meta podatke proizvoda i varijacija.
 */
final class SC_Snapshot {
	public const META_PRICE = '_sidrena_cijena';
	public const META_DATE  = '_sidrena_cijena_datum';
	public const META_SRC   = '_sidrena_cijena_izvor'; // snapshot | novi | rucno | uvoz
	public const META_EXCL  = '_sidrena_izuzeto';       // yes = ne ističi sidrenu cijenu
	public const META_NOCJ  = '_sidrena_bez_cjenika';   // yes = ne uključuj u cjenik

	/** Je li proizvod (ili njegov roditelj / kategorija) izuzet iz isticanja sidrene cijene. */
	public static function is_excluded( WC_Product $product ): bool {
		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : 0;
		if ( $product->get_meta( self::META_EXCL, true ) === 'yes' ) {
			return true;
		}
		if ( $parent_id && get_post_meta( $parent_id, self::META_EXCL, true ) === 'yes' ) {
			return true;
		}
		$cat_tt = wp_cache_get( 'excluded_cat_tt', 'sidrena_cijena' );
		if ( $cat_tt === false ) {
			$cat_tt = SC_Settings::excluded_category_term_taxonomy_ids();
			wp_cache_set( 'excluded_cat_tt', $cat_tt, 'sidrena_cijena' );
		}
		if ( $cat_tt ) {
			$check = $parent_id ?: $product->get_id();
			$terms = wp_get_object_terms( $check, 'product_cat', [ 'fields' => 'tt_ids' ] );
			if ( ! is_wp_error( $terms ) && array_intersect( array_map( 'intval', $terms ), $cat_tt ) ) {
				return true;
			}
		}
		return (bool) apply_filters( 'sidrena_cijena_is_excluded', false, $product );
	}

	/** Je li proizvod izuzet iz cjenika. */
	public static function is_excluded_from_pricelist( WC_Product $product ): bool {
		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : 0;
		if ( $product->get_meta( self::META_NOCJ, true ) === 'yes' ) {
			return true;
		}
		return $parent_id && get_post_meta( $parent_id, self::META_NOCJ, true ) === 'yes';
	}

	public static function init(): void {
		add_action( 'wp_ajax_sc_snapshot_batch', [ __CLASS__, 'ajax_batch' ] );

		// Automatsko bilježenje za nove proizvode (kreirane nakon referentnog datuma).
		add_action( 'woocommerce_new_product', [ __CLASS__, 'maybe_fill_new' ], 20 );
		add_action( 'woocommerce_new_product_variation', [ __CLASS__, 'maybe_fill_new' ], 20 );
		add_action( 'woocommerce_update_product', [ __CLASS__, 'maybe_fill_new' ], 20 );
		add_action( 'woocommerce_update_product_variation', [ __CLASS__, 'maybe_fill_new' ], 20 );
		// Uvoznici i API koji ne prolaze kroz gornje događaje.
		add_action( 'woocommerce_product_import_inserted_product_object', [ __CLASS__, 'maybe_fill_new_object' ], 20 );
		add_action( 'woocommerce_rest_insert_product_object', [ __CLASS__, 'maybe_fill_new_object' ], 20 );
		add_action( 'woocommerce_rest_insert_product_variation_object', [ __CLASS__, 'maybe_fill_new_object' ], 20 );
		// Sinkronizacije koje pišu izravno u bazu (wp_insert_post + meta): provjera na kraju zahtjeva.
		add_action( 'save_post_product', [ __CLASS__, 'defer_check' ], 99 );
		add_action( 'save_post_product_variation', [ __CLASS__, 'defer_check' ], 99 );
		add_action( 'added_post_meta', [ __CLASS__, 'on_price_meta' ], 10, 3 );
		add_action( 'updated_post_meta', [ __CLASS__, 'on_price_meta' ], 10, 3 );
	}

	/** ID-jevi za provjeru na kraju zahtjeva (nakon što uvoznik zapiše cijenu). */
	private static array $deferred = [];

	public static function maybe_fill_new_object( $product ): void {
		if ( $product instanceof WC_Product ) {
			self::maybe_fill_new( $product->get_id() );
		}
	}

	public static function defer_check( int $post_id ): void {
		if ( ! SC_Settings::get( 'auto_novi' ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! self::$deferred ) {
			add_action( 'shutdown', [ __CLASS__, 'run_deferred' ], 5 );
		}
		self::$deferred[ $post_id ] = true;
	}

	/** Cijena zapisana izravno kroz meta (uvoz/sinkronizacija): zabilježi proizvod za provjeru. */
	public static function on_price_meta( $meta_id, $object_id, $meta_key ): void {
		if ( $meta_key === '_regular_price' && in_array( get_post_type( (int) $object_id ), [ 'product', 'product_variation' ], true ) ) {
			self::defer_check( (int) $object_id );
		}
	}

	public static function run_deferred(): void {
		$ids            = array_keys( self::$deferred );
		self::$deferred = [];
		foreach ( array_slice( $ids, 0, 200 ) as $id ) {
			wp_cache_delete( $id, 'post_meta' );
			self::maybe_fill_new( $id );
		}
	}

	/**
	 * Nadoknada: proizvodi kreirani nakon referentnog datuma koji nemaju sidrenu cijenu, neovisno o načinu unosa.
	 * Jedan lagani upit; sidrena = redovna cijena, datum = datum kreiranja. Vraća broj zabilježenih.
	 */
	public static function fill_new_products( int $limit = 500 ): int {
		if ( ! SC_Settings::get( 'auto_novi' ) ) {
			return 0;
		}
		global $wpdb;
		$ref  = (string) SC_Settings::get( 'referentni_datum' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_date, rp.meta_value AS regular
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} rp ON rp.post_id = p.ID AND rp.meta_key = '_regular_price' AND rp.meta_value <> ''
				 LEFT JOIN {$wpdb->postmeta} sc ON sc.post_id = p.ID AND sc.meta_key = %s
				 WHERE p.post_type IN ('product','product_variation')
				   AND p.post_status NOT IN ('trash','auto-draft')
				   AND p.post_date > %s
				   AND (sc.meta_id IS NULL OR sc.meta_value = '')
				 ORDER BY p.ID ASC
				 LIMIT %d",
				self::META_PRICE,
				$ref . ' 23:59:59',
				$limit
			),
			ARRAY_A
		);
		$n = 0;
		foreach ( $rows as $r ) {
			$id = (int) $r['ID'];
			if ( self::reference_date_for( $id ) >= substr( (string) $r['post_date'], 0, 10 ) ) {
				continue; // kategorija s datumom 2. 5. 2025. i proizvod stariji od toga
			}
			self::set( $id, (string) $r['regular'], substr( (string) $r['post_date'], 0, 10 ), 'novi' );
			++$n;
		}
		if ( $n ) {
			delete_transient( 'sc_stats' );
		}
		return $n;
	}

	/**
	 * Vrati sidrenu cijenu proizvoda: ['price' => float, 'date' => 'Y-m-d', 'source' => string] ili null.
	 * Ne primjenjuje fallback; to radi SC_Display.
	 */
	public static function get( WC_Product $product ): ?array {
		$raw = $product->get_meta( self::META_PRICE, true );
		if ( $raw === '' || $raw === null ) {
			return null;
		}
		$price = (float) wc_format_decimal( $raw );
		$date  = (string) $product->get_meta( self::META_DATE, true );
		if ( $date === '' ) {
			$date = (string) SC_Settings::get( 'referentni_datum' );
		}
		return [
			'price'  => $price,
			'date'   => $date,
			'source' => (string) $product->get_meta( self::META_SRC, true ),
		];
	}

	public static function set( int $product_id, string $price, string $date, string $source ): void {
		update_post_meta( $product_id, self::META_PRICE, wc_format_decimal( $price ) );
		update_post_meta( $product_id, self::META_DATE, $date );
		update_post_meta( $product_id, self::META_SRC, $source );
		delete_transient( 'sc_stats' );
	}

	public static function clear( int $product_id ): void {
		delete_post_meta( $product_id, self::META_PRICE );
		delete_post_meta( $product_id, self::META_DATE );
		delete_post_meta( $product_id, self::META_SRC );
		delete_transient( 'sc_stats' );
	}

	/** Referentni datum za proizvod (uzima u obzir kategorije s datumom 2. 5. 2025.). */
	public static function reference_date_for( int $product_id, ?array $alt_tt_ids = null ): string {
		$alt_tt_ids = $alt_tt_ids ?? SC_Settings::alt_category_term_taxonomy_ids();
		if ( $alt_tt_ids ) {
			$post     = get_post( $product_id );
			$check_id = ( $post && $post->post_type === 'product_variation' && $post->post_parent ) ? (int) $post->post_parent : $product_id;
			global $wpdb;
			$ph = implode( ',', array_fill( 0, count( $alt_tt_ids ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- popis %d/%s placeholdera izgrađen iz array_fill(), argumenti prosljeđeni kroz prepare().
			$hit = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id=%d AND term_taxonomy_id IN ($ph)",
					$check_id,
					...array_values( $alt_tt_ids )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
			if ( (int) $hit > 0 ) {
				return (string) SC_Settings::get( 'alt_datum' );
			}
		}
		return (string) SC_Settings::get( 'referentni_datum' );
	}

	/**
	 * Jedan batch snapshot-a: kopira _regular_price u _sidrena_cijena.
	 *
	 * @return array{done:bool,last_id:int,written:int,skipped:int}
	 */
	public static function run_batch( int $last_id, int $limit, bool $overwrite ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
             WHERE post_type IN ('product','product_variation')
               AND post_status NOT IN ('trash','auto-draft')
               AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
				$last_id,
				$limit
			)
		);

		if ( ! $ids ) {
			return [
				'done'    => true,
				'last_id' => $last_id,
				'written' => 0,
				'skipped' => 0,
			];
		}

		$ids = array_map( 'intval', $ids );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- popis %d/%s placeholdera izgrađen iz array_fill(), argumenti prosljeđeni kroz prepare().
		$regular = $wpdb->get_results(
			$wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key='_regular_price' AND meta_value<>'' AND post_id IN ($ph)", ...$ids ),
			ARRAY_A
		);

		$existing = [];
		if ( ! $overwrite ) {
			$existing = array_flip(
				array_map(
					'intval',
					$wpdb->get_col(
						$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value<>'' AND post_id IN ($ph)", self::META_PRICE, ...$ids )
					)
				)
			);
		}

		// Alternativni datum po kategoriji.
		$alt_tt  = SC_Settings::alt_category_term_taxonomy_ids();
		$alt_ids = [];
		if ( $alt_tt ) {
			$parents    = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE ID IN ($ph)", ...$ids ), ARRAY_A );
			$parent_map = [];
			$check_ids  = [];
			foreach ( $parents as $row ) {
				$pid                = (int) $row['ID'];
				$chk                = (int) $row['post_parent'] ?: $pid;
				$parent_map[ $pid ] = $chk;
				$check_ids[ $chk ]  = true;
			}
			$cids   = array_keys( $check_ids );
			$cph    = implode( ',', array_fill( 0, count( $cids ), '%d' ) );
			$tph    = implode( ',', array_fill( 0, count( $alt_tt ), '%d' ) );
			$in_alt = array_flip(
				array_map(
					'intval',
					$wpdb->get_col(
						$wpdb->prepare( "SELECT DISTINCT object_id FROM {$wpdb->term_relationships} WHERE object_id IN ($cph) AND term_taxonomy_id IN ($tph)", ...$cids, ...array_values( $alt_tt ) )
					)
				)
			);
			foreach ( $parent_map as $pid => $chk ) {
				if ( isset( $in_alt[ $chk ] ) ) {
					$alt_ids[ $pid ] = true;
				}
			}
		}

		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$ref_date = (string) SC_Settings::get( 'referentni_datum' );
		$alt_date = (string) SC_Settings::get( 'alt_datum' );
		// Datum kreiranja: proizvodi uvedeni nakon referentnog datuma sidre se na dan prvog uvrštenja.
		$created = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_date FROM {$wpdb->posts} WHERE ID IN ($ph)", ...$ids ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $ph je popis %d placeholdera.
			$created[ (int) $row['ID'] ] = substr( (string) $row['post_date'], 0, 10 );
		}

		$written = 0;
		$skipped = 0;
		$values  = [];
		$touched = [];
		foreach ( $regular as $row ) {
			$pid = (int) $row['post_id'];
			if ( isset( $existing[ $pid ] ) ) {
				++$skipped;
				continue;
			}
			$date   = isset( $alt_ids[ $pid ] ) ? $alt_date : $ref_date;
			$source = 'snapshot';
			if ( isset( $created[ $pid ] ) && $created[ $pid ] > $date ) {
				$date   = $created[ $pid ];
				$source = 'novi';
			}
			$price     = wc_format_decimal( (string) $row['meta_value'] );
			$values[]  = $wpdb->prepare( '(%d,%s,%s)', $pid, self::META_PRICE, $price );
			$values[]  = $wpdb->prepare( '(%d,%s,%s)', $pid, self::META_DATE, $date );
			$values[]  = $wpdb->prepare( '(%d,%s,%s)', $pid, self::META_SRC, $source );
			$touched[] = $pid;
			++$written;
		}

		if ( $touched ) {
			$tph = implode( ',', array_fill( 0, count( $touched ), '%d' ) );
			// Skupni upis (3 reda po proizvodu) umjesto 3× update_post_meta: ~100× manje upita.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- popis %d/%s placeholdera izgrađen iz array_fill(), argumenti prosljeđeni kroz prepare().
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($tph) AND meta_key IN (%s,%s,%s)", ...array_merge( $touched, [ self::META_PRICE, self::META_DATE, self::META_SRC ] ) ) );
			foreach ( array_chunk( $values, 1500 ) as $chunk ) {
				// Svaki element $chunk je već prošao kroz $wpdb->prepare('(%d,%s,%s)').
				$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
			foreach ( $touched as $pid ) {
				wp_cache_delete( $pid, 'post_meta' );
			}
		}

		$done = count( $ids ) < $limit;
		if ( $done ) {
			delete_transient( 'sc_stats' );
		}

		return [
			'done'    => $done,
			'last_id' => (int) end( $ids ),
			'written' => $written,
			'skipped' => $skipped,
		];
	}

	/** Cijeli snapshot u jednom prolazu (WP-CLI / cron). */
	public static function run_full( bool $overwrite = false, int $batch = 100 ): array {
		$last = 0;
		$tot  = [
			'written' => 0,
			'skipped' => 0,
		];
		do {
			$r               = self::run_batch( $last, $batch, $overwrite );
			$last            = $r['last_id'];
			$tot['written'] += $r['written'];
			$tot['skipped'] += $r['skipped'];
		} while ( ! $r['done'] );
		wp_cache_flush();
		return $tot;
	}

	public static function ajax_batch(): void {
		check_ajax_referer( 'sc_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Nemate ovlasti.' ], 403 );
		}
		$last_id   = isset( $_POST['last_id'] ) ? (int) $_POST['last_id'] : 0;
		$overwrite = ! empty( $_POST['overwrite'] );
		$r         = self::run_batch( $last_id, 100, $overwrite );
		if ( $r['done'] ) {
			wp_cache_flush();
			$r['stats'] = self::stats( true );
		}
		wp_send_json_success( $r );
	}

	/** Novi proizvod bez sidrene cijene: prva redovna cijena postaje sidrena, datum = datum kreiranja. */
	public static function maybe_fill_new( $product_id ): void {
		if ( ! SC_Settings::get( 'auto_novi' ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->get_meta( self::META_PRICE, true ) !== '' ) {
			return;
		}
		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
			return; // roditelji nemaju vlastitu cijenu
		}
		$regular = $product->get_regular_price( 'edit' );
		if ( $regular === '' || $regular === null ) {
			return;
		}
		$created     = $product->get_date_created();
		$created_ymd = $created ? $created->date( 'Y-m-d' ) : wp_date( 'Y-m-d' );
		$ref         = self::reference_date_for( $product_id );
		if ( $created_ymd <= $ref ) {
			return; // stari proizvod, čeka snapshot ili ručni unos
		}
		self::set( $product_id, (string) $regular, $created_ymd, 'novi' );
	}

	/**
	 * Statistika za admin (izuzeti proizvodi se ne broje). Keširano 15 min jer je upit težak na velikim katalozima.
	 */
	public static function stats( bool $fresh = false ): array {
		if ( ! $fresh ) {
			$cached = get_transient( 'sc_stats' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$r         = self::compute_stats();
		$r['time'] = time();
		set_transient( 'sc_stats', $r, 15 * MINUTE_IN_SECONDS );
		return $r;
	}

	private static function compute_stats(): array {
		global $wpdb;
		// Lagani upiti po indeksu meta_key; bez višestrukih JOIN-ova nad postmeta.
		$count    = static function ( string $key ) use ( $wpdb ): int {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
                 WHERE m.meta_key = %s AND m.meta_value <> '' AND p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private')",
					$key
				)
			);
		};
		$total    = $count( '_regular_price' );
		$with     = $count( self::META_PRICE );
		$excluded = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key = %s AND m.meta_value = 'yes' AND p.post_type = 'product' AND p.post_status IN ('publish','private')",
				self::META_EXCL
			)
		);
		return [
			'total'    => $total,
			'with'     => $with,
			'without'  => max( 0, $total - $with ),
			'excluded' => $excluded,
		];
	}
}
