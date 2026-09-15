<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prikaz sidrene cijene na frontendu.
 */
final class SC_Display {

	public static function init(): void {
		add_filter( 'woocommerce_get_price_html', [ __CLASS__, 'price_html' ], PHP_INT_MAX, 2 ); // zadnji: neki pluginovi (npr. najniža cijena u 30 dana) grade HTML ispočetka
		add_filter( 'woocommerce_cart_item_price', [ __CLASS__, 'cart_item_price' ], PHP_INT_MAX, 2 );
		add_shortcode( 'sidrena_cijena', [ __CLASS__, 'shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'styles' ] );
		add_action( 'woocommerce_before_shop_loop_item', [ __CLASS__, 'loop_start' ], 0 );
		add_action( 'woocommerce_after_shop_loop_item', [ __CLASS__, 'loop_end' ], 9999 );
		add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'blocks_support' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'blocks_script' ], 20 );
	}

	/** Čisti tekst sidrene cijene, npr. "Sidrena cijena (10. 9. 2026.): 10,49 €" (za blokove, e-mail, feedove). */
	public static function render_text( WC_Product $product ): string {
		$html = self::render( $product );
		if ( $html === '' ) {
			return '';
		}
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/** Store API: dodaj sidrenu cijenu na stavke košarice (Cart / Checkout / Mini-cart blokovi). */
	public static function blocks_support(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			[
				'endpoint'        => 'cart-item',
				'namespace'       => 'sidrena-cijena',
				'data_callback'   => static function ( $cart_item ): array {
					$product = $cart_item['data'] ?? null;
					$label   = ( $product instanceof WC_Product && SC_Settings::get( 'prikaz_kosarica' ) ) ? self::render_text( $product ) : '';
					return [ 'label' => $label ];
				},
				'schema_callback' => static fn(): array => [
					'label' => [
						'description' => 'Sidrena cijena (tekst)',
						'type'        => 'string',
						'readonly'    => true,
					],
				],
				'schema_type'     => ARRAY_A,
			]
		);
	}

	public static function blocks_script(): void {
		if ( ! SC_Settings::display_active() || ! SC_Settings::get( 'prikaz_kosarica' ) || ! wp_script_is( 'wc-blocks-checkout', 'registered' ) ) {
			return;
		}
		if ( ! ( function_exists( 'is_cart' ) && is_cart() ) && ! ( function_exists( 'is_checkout' ) && is_checkout() ) && ! has_block( 'woocommerce/mini-cart' ) ) {
			return;
		}
		wp_enqueue_script( 'sidrena-cijena-blocks', SC_URL . 'assets/blocks.js', [ 'wc-blocks-checkout' ], SC_VERSION, true );
	}

	/** Jesmo li unutar petlje proizvoda (listing, povezani proizvodi...). */
	private static bool $in_loop = false;

	public static function styles(): void {
		$size = (string) SC_Settings::get( 'font_size' );
		if ( ! preg_match( '/^\d+(\.\d+)?(px|em|rem|%)$/', $size ) ) {
			$size = '0.7em';
		}
		$color  = sanitize_hex_color( (string) SC_Settings::get( 'boja' ) );
		$weight = in_array( (string) SC_Settings::get( 'font_weight' ), [ '300', '400', '500', '600', '700' ], true ) ? (string) SC_Settings::get( 'font_weight' ) : '400';
		$custom = (string) SC_Settings::get( 'custom_css' );
		wp_register_style( 'sidrena-cijena', false, [], SC_VERSION );
		wp_enqueue_style( 'sidrena-cijena' );
		wp_add_inline_style(
			'sidrena-cijena',
			'.sc-sidrena{display:block;font-size:' . $size . ';font-weight:' . $weight . ';' . ( $color ? 'color:' . $color . ';' : 'opacity:.85;' ) . 'margin-top:.15em;line-height:1.25;white-space:normal;overflow-wrap:anywhere;text-decoration:none}' .
			'del .sc-sidrena,ins .sc-sidrena{display:none}' .
			'.sc-sidrena .sc-amount{white-space:nowrap}' .
			( $custom !== '' ? "\n" . wp_strip_all_tags( $custom ) : '' )
		);
	}

	public static function loop_start(): void {
		self::$in_loop = true;
	}

	public static function loop_end(): void {
		self::$in_loop = false;
	}

	/** Listing kontekst: unutar WooCommerce petlje ili bilo koja stranica koja nije pojedinačni proizvod. */
	public static function is_loop_context(): bool {
		if ( self::$in_loop ) {
			return true;
		}
		return ! ( function_exists( 'is_product' ) && is_product() );
	}

	/**
	 * Sidrena cijena za prikaz (s PDV-om prema postavkama trgovine).
	 *
	 * @return array{min:float,max:float,date:string}|null
	 */
	public static function resolve( WC_Product $product ): ?array {
		if ( SC_Snapshot::is_excluded( $product ) ) {
			return null;
		}
		if ( $product->is_type( 'variable' ) ) {
			$children = array_map( 'intval', $product->get_children() );
			if ( ! $children ) {
				return null;
			}
			global $wpdb;
			$ph = implode( ',', array_fill( 0, count( $children ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- popis %d/%s placeholdera izgrađen iz array_fill(), argumenti prosljeđeni kroz prepare().
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.post_id, m.meta_value, d.meta_value AS datum FROM {$wpdb->postmeta} m
					 JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_status = 'publish'
					 LEFT JOIN {$wpdb->postmeta} d ON d.post_id = m.post_id AND d.meta_key = %s
					 WHERE m.post_id IN ($ph) AND m.meta_key = %s AND m.meta_value <> ''",
					...array_merge( [ SC_Snapshot::META_DATE ], $children, [ SC_Snapshot::META_PRICE ] )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
			$min  = null;
			$max  = null;
			$date = '';
			foreach ( $rows as $r ) {
				$v    = (float) wc_get_price_to_display(
					$product,
					[
						'price' => (float) $r['meta_value'],
						'qty'   => 1,
					]
				);
				$min  = $min === null ? $v : min( $min, $v );
				$max  = $max === null ? $v : max( $max, $v );
				$date = $date ?: (string) $r['datum'];
			}
			if ( $min === null ) {
				return null;
			}
			return [
				'min'  => $min,
				'max'  => $max,
				'date' => $date ?: (string) SC_Settings::get( 'referentni_datum' ),
			];
		}

		if ( $product->is_type( 'grouped' ) ) {
			return null; // svaki child ima svoju cijenu i svoju sidrenu
		}

		$s = SC_Snapshot::get( $product );
		if ( ! $s ) {
			if ( SC_Settings::get( 'prikaz_bez_sidrene' ) === 'redovna' ) {
				$regular = $product->get_regular_price();
				if ( $regular === '' || $regular === null ) {
					return null;
				}
				$s = [
					'price' => (float) $regular,
					'date'  => (string) SC_Settings::get( 'referentni_datum' ),
				];
			} else {
				return null;
			}
		}

		$display = (float) wc_get_price_to_display(
			$product,
			[
				'price' => $s['price'],
				'qty'   => 1,
			]
		);
		return [
			'min'  => $display,
			'max'  => $display,
			'date' => $s['date'],
		];
	}

	public static function render( WC_Product $product, bool $force = false ): string {
		if ( ! $force && ! SC_Settings::display_active() ) {
			return '';
		}
		$r = self::resolve( $product );
		if ( ! $r ) {
			return '';
		}
		$amount = $r['min'] < $r['max']
			? wc_format_price_range( $r['min'], $r['max'] )
			: wc_price( $r['min'] );

		$lang  = SC_Settings::current_language();
		$loop  = self::is_loop_context();
		$label = SC_Settings::label_for( $lang, $loop );
		$out   = strtr(
			$label,
			[
				'{datum}'     => SC_Settings::format_date( $r['date'], $lang ),
				'{datum_iso}' => $r['date'],
				'{cijena}'    => '<span class="sc-amount">' . $amount . '</span>',
			]
		);
		return '<span class="sc-sidrena' . ( $loop ? ' sc-sidrena--loop' : ' sc-sidrena--single' ) . '" lang="' . esc_attr( $lang ) . '">' . $out . '</span>';
	}

	public static function price_html( $html, $product ) {
		if ( ! $product instanceof WC_Product ) {
			return $html;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $html;
		}
		if ( ! is_string( $html ) || $html === '' || str_contains( $html, 'sc-sidrena' ) ) {
			return $html;
		}
		$extra = self::render( $product );
		if ( $extra === '' ) {
			return $html;
		}
		return $html . $extra;
	}

	public static function cart_item_price( $price_html, $cart_item ) {
		if ( ! SC_Settings::get( 'prikaz_kosarica' ) ) {
			return $price_html;
		}
		if ( ! is_string( $price_html ) || str_contains( $price_html, 'sc-sidrena' ) ) {
			return $price_html;
		}
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof WC_Product ) {
			return $price_html;
		}
		return $price_html . self::render( $product );
	}

	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			[
				'id'    => 0,
				'force' => 0,
			],
			$atts,
			'sidrena_cijena'
		);
		$id   = (int) $atts['id'];
		if ( ! $id ) {
			global $product;
			if ( $product instanceof WC_Product ) {
				$id = $product->get_id();
			}
		}
		$p = $id ? wc_get_product( $id ) : null;
		return $p ? self::render( $p, ! empty( $atts['force'] ) ) : '';
	}
}
