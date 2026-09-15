<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Polja sidrene cijene u uređivanju proizvoda i varijacija + stupac u popisu proizvoda.
 */
final class SC_Product_Fields {

	public static function init(): void {
		add_action( 'woocommerce_product_options_pricing', [ __CLASS__, 'simple_fields' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_simple' ], 20 );

		add_action( 'woocommerce_variation_options_pricing', [ __CLASS__, 'variation_fields' ], 10, 3 );
		add_action( 'woocommerce_save_product_variation', [ __CLASS__, 'save_variation' ], 20, 2 );

		add_action( 'woocommerce_product_bulk_edit_end', [ __CLASS__, 'bulk_edit_fields' ] );
		add_action( 'woocommerce_product_quick_edit_end', [ __CLASS__, 'bulk_edit_fields' ] );
		add_action( 'woocommerce_product_bulk_edit_save', [ __CLASS__, 'bulk_edit_save' ] );
		add_action( 'woocommerce_product_quick_edit_save', [ __CLASS__, 'bulk_edit_save' ] );

		add_filter( 'manage_edit-product_columns', [ __CLASS__, 'column' ] );
		add_action( 'manage_product_posts_custom_column', [ __CLASS__, 'column_content' ], 10, 2 );
	}

	private static function source_label( string $src ): string {
		return match ( $src ) {
			'snapshot' => 'automatski snapshot',
			'novi'     => 'novi proizvod (prva redovna cijena)',
			'rucno'    => 'ručno uneseno',
			'uvoz'     => 'uvoz iz CSV-a',
			default    => '',
		};
	}

	public static function simple_fields(): void {
		global $post;
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return;
		}
		$s = SC_Snapshot::get( $product );
		echo '<div class="options_group sc-fields">';
		woocommerce_wp_text_input(
			[
				'id'          => '_sidrena_cijena',
				'label'       => 'Sidrena cijena (' . get_woocommerce_currency_symbol() . ')',
				'data_type'   => 'price',
				'value'       => $s ? wc_format_localized_price( $s['price'] ) : '',
				'desc_tip'    => true,
				'description' => 'Redovna cijena (bez akcije) koja je vrijedila na referentni dan. Unosi se isto kao i redovna cijena (s ili bez PDV-a prema postavkama trgovine).' . ( $s && $s['source'] ? ' Izvor: ' . self::source_label( $s['source'] ) . '.' : '' ),
			]
		);
		woocommerce_wp_text_input(
			[
				'id'          => '_sidrena_cijena_datum',
				'label'       => 'Datum sidrene cijene',
				'type'        => 'date',
				'value'       => $s ? $s['date'] : '',
				'placeholder' => (string) SC_Settings::get( 'referentni_datum' ),
				'desc_tip'    => true,
				'description' => 'Prazno = referentni datum iz postavki (' . SC_Settings::format_date( (string) SC_Settings::get( 'referentni_datum' ) ) . ').',
			]
		);
		woocommerce_wp_checkbox(
			[
				'id'          => SC_Snapshot::META_EXCL,
				'label'       => 'Ne ističi sidrenu cijenu',
				'value'       => $product->get_meta( SC_Snapshot::META_EXCL, true ) === 'yes' ? 'yes' : 'no',
				'description' => 'Za proizvode koji nisu bili u ponudi na referentni dan (npr. preorder). Sidrena cijena se ne prikazuje na webshopu, a u cjeniku je polje prazno.',
			]
		);
		woocommerce_wp_checkbox(
			[
				'id'          => SC_Snapshot::META_NOCJ,
				'label'       => 'Ne uključuj u cjenik',
				'value'       => $product->get_meta( SC_Snapshot::META_NOCJ, true ) === 'yes' ? 'yes' : 'no',
				'description' => 'Proizvod se u potpunosti izostavlja iz .csv/.xml cjenika.',
			]
		);
		echo '</div>';
	}

	public static function bulk_edit_fields(): void {
		?>
		<div class="inline-edit-group sc-bulk">
			<label class="alignleft">
				<span class="title">Sidrena cijena</span>
				<span class="input-text-wrap">
					<select name="_sc_bulk_izuzeto">
						<option value="">— Bez promjene —</option>
						<option value="yes">Ne ističi sidrenu cijenu</option>
						<option value="no">Ističi sidrenu cijenu</option>
					</select>
				</span>
			</label>
			<label class="alignleft">
				<span class="title">Cjenik</span>
				<span class="input-text-wrap">
					<select name="_sc_bulk_cjenik">
						<option value="">— Bez promjene —</option>
						<option value="yes">Ne uključuj u cjenik</option>
						<option value="no">Uključi u cjenik</option>
					</select>
				</span>
			</label>
		</div>
		<?php
	}

	public static function bulk_edit_save( WC_Product $product ): void {
		$map     = [
			'_sc_bulk_izuzeto' => SC_Snapshot::META_EXCL,
			'_sc_bulk_cjenik'  => SC_Snapshot::META_NOCJ,
		];
		$changed = false;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WooCommerce je već provjerio nonce masovnog/brzog uređivanja.
		foreach ( $map as $field => $meta ) {
			$v = isset( $_REQUEST[ $field ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $field ] ) ) : '';
			if ( $v === 'yes' ) {
				$product->update_meta_data( $meta, 'yes' );
				$changed = true;
			} elseif ( $v === 'no' ) {
				$product->delete_meta_data( $meta );
				$changed = true;
			}
		}
		// phpcs:enable
		if ( $changed ) {
			$product->save();
		}
	}

	public static function save_simple( int $post_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- woocommerce_process_product_meta se poziva nakon WooCommerce provjere nonce-a.
		if ( ! isset( $_POST['_sidrena_cijena'] ) ) {
			return;
		}
		foreach ( [ SC_Snapshot::META_EXCL, SC_Snapshot::META_NOCJ ] as $meta ) {
			if ( isset( $_POST[ $meta ] ) && sanitize_key( wp_unslash( $_POST[ $meta ] ) ) === 'yes' ) {
				update_post_meta( $post_id, $meta, 'yes' );
			} else {
				delete_post_meta( $post_id, $meta );
			}
		}
		$price = sanitize_text_field( wp_unslash( $_POST['_sidrena_cijena'] ) );
		$date  = isset( $_POST['_sidrena_cijena_datum'] ) ? sanitize_text_field( wp_unslash( $_POST['_sidrena_cijena_datum'] ) ) : '';
		// phpcs:enable
		self::save_from_request( $post_id, $price, $date );
	}

	public static function variation_fields( int $loop, array $variation_data, WP_Post $variation ): void {
		$product = wc_get_product( $variation->ID );
		$s       = $product ? SC_Snapshot::get( $product ) : null;
		echo '<div class="sc-variation-fields" style="clear:both">';
		woocommerce_wp_text_input(
			[
				'id'            => "_sidrena_cijena_var[{$loop}]",
				'name'          => "_sidrena_cijena_var[{$loop}]",
				'label'         => 'Sidrena cijena (' . get_woocommerce_currency_symbol() . ')',
				'data_type'     => 'price',
				'value'         => $s ? wc_format_localized_price( $s['price'] ) : '',
				'wrapper_class' => 'form-row form-row-first',
			]
		);
		woocommerce_wp_text_input(
			[
				'id'            => "_sidrena_cijena_datum_var[{$loop}]",
				'name'          => "_sidrena_cijena_datum_var[{$loop}]",
				'label'         => 'Datum sidrene cijene',
				'type'          => 'date',
				'value'         => $s ? $s['date'] : '',
				'wrapper_class' => 'form-row form-row-last',
			]
		);
		echo '</div>';
	}

	public static function save_variation( int $variation_id, int $i ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- woocommerce_save_product_variation se poziva nakon WooCommerce provjere nonce-a.
		if ( ! isset( $_POST['_sidrena_cijena_var'][ $i ] ) ) {
			return;
		}
		$price = sanitize_text_field( wp_unslash( $_POST['_sidrena_cijena_var'][ $i ] ) );
		$date  = isset( $_POST['_sidrena_cijena_datum_var'][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST['_sidrena_cijena_datum_var'][ $i ] ) ) : '';
		// phpcs:enable
		self::save_from_request( $variation_id, $price, $date );
	}

	private static function save_from_request( int $id, string $price, string $date ): void {
		$price = trim( $price );
		if ( $price === '' ) {
			$existing = get_post_meta( $id, SC_Snapshot::META_PRICE, true );
			if ( $existing !== '' ) {
				SC_Snapshot::clear( $id );
			}
			return;
		}
		$clean = wc_format_decimal( $price );
		$date  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : SC_Snapshot::reference_date_for( $id );

		$old_price = get_post_meta( $id, SC_Snapshot::META_PRICE, true );
		$old_date  = get_post_meta( $id, SC_Snapshot::META_DATE, true );
		if ( (string) $old_price === (string) $clean && (string) $old_date === $date ) {
			return; // ništa se nije promijenilo, zadrži izvor
		}
		SC_Snapshot::set( $id, $clean, $date, 'rucno' );
	}

	public static function column( array $cols ): array {
		$out = [];
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( $k === 'price' ) {
				$out['sc_sidrena'] = 'Sidrena';
			}
		}
		return $out;
	}

	public static function column_content( string $col, int $post_id ): void {
		if ( $col !== 'sc_sidrena' ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}
		if ( SC_Snapshot::is_excluded( $product ) ) {
			echo '<span style="color:#787c82" title="Ne ističe se sidrena cijena">izuzeto</span>';
			if ( SC_Snapshot::is_excluded_from_pricelist( $product ) ) {
				echo '<br><small>bez cjenika</small>';
			}
			return;
		}
		if ( $product->is_type( 'variable' ) ) {
			$r = SC_Display::resolve( $product );
			echo $r ? wp_kses_post( $r['min'] < $r['max'] ? wc_format_price_range( $r['min'], $r['max'] ) : wc_price( $r['min'] ) ) : '<span style="color:#b32d2e">—</span>';
			return;
		}
		$s = SC_Snapshot::get( $product );
		if ( ! $s ) {
			echo '<span style="color:#b32d2e" title="Nije zabilježena">—</span>';
			return;
		}
		echo wp_kses_post( wc_price( $s['price'] ) ) . '<br><small>' . esc_html( SC_Settings::format_date( $s['date'] ) ) . '</small>';
	}
}
