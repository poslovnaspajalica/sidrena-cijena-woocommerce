<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin: objava cjenika (upload s pregledom), poslovnice, objavljene datoteke, postavke.
 */
final class SCP_Admin {
	private const PAGE = 'scp-cjenik';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_scp_upload', [ __CLASS__, 'handle_upload' ] );
		add_action( 'admin_post_scp_publish', [ __CLASS__, 'handle_publish' ] );
		add_action( 'admin_post_scp_save_stores', [ __CLASS__, 'save_stores' ] );
		add_action( 'admin_post_scp_save_settings', [ __CLASS__, 'save_settings' ] );
		add_action( 'admin_post_scp_delete_file', [ __CLASS__, 'delete_file' ] );
		add_action( 'admin_post_scp_template', [ __CLASS__, 'download_template' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notices' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'styles' ] );
	}

	public static function url( string $tab = 'objava', array $extra = [] ): string {
		return add_query_arg(
			array_merge(
				[
					'page' => self::PAGE,
					'tab'  => $tab,
				],
				$extra
			),
			admin_url( 'admin.php' )
		);
	}

	public static function menu(): void {
		add_menu_page( 'Cjenik poslovnica', 'Cjenik poslovnica', SCP_CAP, self::PAGE, [ __CLASS__, 'render' ], 'dashicons-media-spreadsheet', 56 );
	}

	public static function styles( string $hook ): void {
		if ( ! str_contains( $hook, self::PAGE ) ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', '.scp-card{background:#fff;border:1px solid #c3c4c7;padding:16px 20px;margin:16px 0;max-width:1000px}.scp-warn{color:#b32d2e}.scp-ok{color:#00a32a}.scp-stat{display:inline-block;margin-right:22px}.scp-stat b{display:block;font-size:20px}.scp-preview{overflow:auto;max-width:100%}.scp-preview table{font-size:12px;white-space:nowrap}' );
	}

	private static function flash( string $msg, bool $err = false ): void {
		set_transient(
			'scp_flash_' . get_current_user_id(),
			[
				'msg' => $msg,
				'err' => $err,
			],
			MINUTE_IN_SECONDS
		);
	}

	private static function redirect( string $tab, string $msg = '', bool $err = false, array $extra = [] ): void {
		if ( $msg !== '' ) {
			self::flash( $msg, $err );
		}
		wp_safe_redirect( self::url( $tab, $extra ) );
		exit;
	}

	public static function notices(): void {
		if ( ! current_user_can( SCP_CAP ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'dashboard', 'toplevel_page_' . self::PAGE ], true ) ) {
			return;
		}
		$missing = [];
		foreach ( SCP_Settings::stores() as $store ) {
			if ( ! SCP_Files::has_today( $store['id'] ) ) {
				$missing[] = $store['naziv'] ?: $store['adresa'];
			}
		}
		if ( $missing ) {
			printf(
				'<div class="notice notice-warning"><p><strong>Cjenik poslovnica:</strong> danas još nije objavljen za: %s. <a href="%s">Objavi</a></p></div>',
				esc_html( implode( ', ', $missing ) ),
				esc_url( self::url( 'objava' ) )
			);
		}
	}

	/* ---------- Render ---------- */

	public static function render(): void {
		if ( ! current_user_can( SCP_CAP ) ) {
			wp_die( 'Nemate ovlasti.' );
		}
		$tab      = sanitize_key( $_GET['tab'] ?? 'objava' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odabir kartice.
		$is_admin = current_user_can( 'manage_options' );
		$tabs     = [
			'objava'   => 'Objava cjenika',
			'datoteke' => 'Objavljene datoteke',
		];
		if ( $is_admin ) {
			$tabs['poslovnice'] = 'Poslovnice';
			$tabs['postavke']   = 'Postavke';
		}
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'objava';
		}
		echo '<div class="wrap"><h1>Cjenik poslovnica</h1>';
		$flash = get_transient( 'scp_flash_' . get_current_user_id() );
		if ( is_array( $flash ) && ! empty( $flash['msg'] ) ) {
			delete_transient( 'scp_flash_' . get_current_user_id() );
			echo '<div class="notice notice-' . ( ! empty( $flash['err'] ) ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( $flash['msg'] ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a class="nav-tab %s" href="%s">%s</a>', $tab === $k ? 'nav-tab-active' : '', esc_url( self::url( $k ) ), esc_html( $label ) );
		}
		echo '</nav>';
		match ( $tab ) {
			'datoteke'   => self::tab_files(),
			'poslovnice' => self::tab_stores(),
			'postavke'   => self::tab_settings(),
			default      => self::tab_upload(),
		};
		echo '</div>';
	}

	private static function tab_upload(): void {
		$stores  = SCP_Settings::stores();
		$preview = isset( $_GET['pregled'] ) ? get_transient( 'scp_preview_' . sanitize_key( wp_unslash( $_GET['pregled'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token pregleda, nema promjene stanja.
		if ( ! $stores ) {
			echo '<div class="scp-card"><p>Najprije dodaj poslovnice na kartici <a href="' . esc_url( self::url( 'poslovnice' ) ) . '">Poslovnice</a>.</p></div>';
			return;
		}
		?>
		<div class="scp-card">
			<h2>Stanje danas (<?php echo esc_html( wp_date( 'd.m.Y.' ) ); ?>)</h2>
			<?php
			foreach ( $stores as $store ) :
				$ok = SCP_Files::has_today( $store['id'] );
				$l  = SCP_Files::latest( $store['id'] );
				?>
				<div class="scp-stat"><b class="<?php echo $ok ? 'scp-ok' : 'scp-warn'; ?>"><?php echo $ok ? 'objavljen' : 'nije objavljen'; ?></b><?php echo esc_html( $store['naziv'] ?: $store['adresa'] ); ?>
				<?php
				if ( ! empty( $l['csv'] ) ) :
					?>
					<br><small>zadnji: <?php echo esc_html( wp_date( 'd.m.Y. H:i', $l['csv']['mtime'] ) ); ?></small><?php endif; ?></div>
			<?php endforeach; ?>
			<p class="description">Odluka: cjenik ažuriran najkasnije do 8:00 za tekući radni dan.</p>
		</div>

		<?php if ( is_array( $preview ) ) : ?>
		<div class="scp-card">
			<h2>2. Pregled prije objave: <?php echo esc_html( $preview['store']['naziv'] ?: $preview['store']['adresa'] ); ?></h2>
			<?php $st = $preview['stats']; ?>
			<div class="scp-stat"><b><?php echo (int) $st['ukupno']; ?></b>artikala</div>
			<div class="scp-stat"><b><?php echo (int) $st['akcija']; ?></b>na akciji</div>
			<div class="scp-stat"><b><?php echo (int) $st['nedostupno']; ?></b>nedostupno</div>
			<div class="scp-stat"><b class="<?php echo $st['bez_sidrene'] ? 'scp-warn' : 'scp-ok'; ?>"><?php echo (int) $st['bez_sidrene']; ?></b>bez sidrene cijene</div>
			<div class="scp-stat"><b class="<?php echo $st['bez_barkoda'] ? 'scp-warn' : 'scp-ok'; ?>"><?php echo (int) $st['bez_barkoda']; ?></b>bez barkoda</div>
			<div class="scp-stat"><b class="<?php echo $st['bez_cijene'] ? 'scp-warn' : 'scp-ok'; ?>"><?php echo (int) $st['bez_cijene']; ?></b>preskočeno (bez cijene)</div>
			<?php
			if ( $st['bez_sidrene'] ) :
				?>
				<p class="scp-warn">Odluka (točka III.) traži sidrenu cijenu u cjeniku. Ako blagajna nema taj podatak, stupac ostaje prazan; objava je ipak moguća.</p><?php endif; ?>
			<p><small>Prepoznati stupci: 
			<?php
			foreach ( $preview['map'] as $f => $idx ) {
				echo esc_html( $f ) . ' ← „' . esc_html( $preview['header'][ $idx ] ?? '' ) . '“ · '; }
			?>
			</small></p>
			<div class="scp-preview"><table class="widefat striped"><thead><tr>
			<?php
			foreach ( SCP_Convert::columns() as $c ) :
				?>
				<th><?php echo esc_html( $c ); ?></th><?php endforeach; ?></tr></thead><tbody>
			<?php
			foreach ( array_slice( $preview['rows'], 0, 8 ) as $row ) :
				?>
				<tr>
				<?php
				foreach ( $row as $v ) :
					?>
				<td><?php echo esc_html( (string) $v ); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
			</tbody></table></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<?php wp_nonce_field( 'scp_publish' ); ?>
				<input type="hidden" name="action" value="scp_publish">
				<input type="hidden" name="token" value="<?php echo esc_attr( $preview['token'] ); ?>">
				<?php submit_button( 'Objavi cjenik (' . (int) $st['ukupno'] . ' artikala)', 'primary', 'submit', false ); ?>
				<a class="button" href="<?php echo esc_url( self::url( 'objava' ) ); ?>">Odustani</a>
			</form>
		</div>
		<?php endif; ?>

		<div class="scp-card">
			<h2>1. Prenesi CSV s blagajne</h2>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scp_upload' ); ?>
				<input type="hidden" name="action" value="scp_upload">
				<table class="form-table">
					<tr><th><label for="scp-store">Poslovnica</label></th><td><select name="store" id="scp-store" required>
						<?php
						foreach ( $stores as $store ) :
							?>
							<option value="<?php echo esc_attr( $store['id'] ); ?>"><?php echo esc_html( ( $store['naziv'] ?: $store['adresa'] ) . ( $store['naziv'] && $store['adresa'] ? ' (' . $store['adresa'] . ')' : '' ) ); ?></option><?php endforeach; ?>
					</select></td></tr>
					<tr><th><label for="scp-csv">CSV datoteka</label></th><td><input type="file" name="csv" id="scp-csv" accept=".csv,.txt,text/csv,text/plain" required>
						<p class="description">Obvezni stupci: <code>barkod</code>, <code>naziv</code>, <code>cijena</code>. Neobavezni: <code>akcijska_cijena</code>, <code>dostupnost</code>, <code>sidrena_cijena</code>, <code>sifra</code>, <code>marka</code>. Nazivi stupaca se prepoznaju automatski (npr. EAN, MPC, akcija, zaliha). Separator ; ili , ili tab, decimalni zarez ili točka, UTF-8 ili Windows-1250.
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=scp_template' ), 'scp_template' ) ); ?>">Preuzmi predložak CSV-a</a></p></td></tr>
				</table>
				<?php submit_button( 'Učitaj i pregledaj', 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private static function tab_files(): void {
		$stores   = SCP_Settings::stores();
		$is_admin = current_user_can( 'manage_options' );
		echo '<div class="scp-card"><p>Javna stranica: <a href="' . esc_url( SCP_Public::url() ) . '" target="_blank">' . esc_html( SCP_Public::url() ) . '</a></p></div>';
		foreach ( $stores as $store ) {
			$files = SCP_Files::list_files( $store['id'] );
			echo '<div class="scp-card"><h2>' . esc_html( $store['naziv'] ?: $store['adresa'] ) . '</h2>';
			echo '<p><small>Stabilni linkovi: <code>' . esc_html( SCP_Public::url( 'poslovnica-' . sanitize_key( $store['id'] ) . '/latest.csv' ) ) . '</code> <code>' . esc_html( SCP_Public::url( 'poslovnica-' . sanitize_key( $store['id'] ) . '/latest.xml' ) ) . '</code></small></p>';
			if ( ! $files ) {
				echo '<p>Nema objavljenih datoteka.</p></div>';
				continue;
			}
			echo '<table class="widefat striped"><thead><tr><th>Datoteka</th><th>Objavljeno</th><th>Veličina</th>' . ( $is_admin ? '<th></th>' : '' ) . '</tr></thead><tbody>';
			foreach ( $files as $f ) {
				echo '<tr><td><a href="' . esc_url( $f['url'] ) . '">' . esc_html( $f['name'] ) . '</a></td><td>' . esc_html( wp_date( 'd.m.Y. H:i', $f['mtime'] ) ) . '</td><td>' . esc_html( size_format( $f['size'] ) ) . '</td>';
				if ( $is_admin ) {
					$del = wp_nonce_url( admin_url( 'admin-post.php?action=scp_delete_file&store=' . rawurlencode( $store['id'] ) . '&file=' . rawurlencode( $f['name'] ) ), 'scp_delete_' . $store['id'] . '_' . $f['name'] );
					echo '<td><a href="' . esc_url( $del ) . '" class="submitdelete" onclick="return confirm(\'Obrisati datoteku? Odluka traži 30 dana dostupnosti; briši samo duplikate istog dana.\')">Obriši</a></td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}
	}

	private static function tab_stores(): void {
		$stores   = SCP_Settings::stores();
		$stores[] = [
			'id'           => '',
			'naziv'        => '',
			'oblik'        => 'prodavaonica',
			'adresa'       => '',
			'oznaka'       => '',
			'broj_pohrane' => '1',
		]; // prazan redak za novu
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'scp_save_stores' ); ?>
			<input type="hidden" name="action" value="scp_save_stores">
			<div class="scp-card">
				<h2>Poslovnice (prodajni objekti)</h2>
				<p class="description">Podaci ulaze u naziv datoteke prema točki VI. Odluke: oblik prodajnog objekta, adresa, oznaka objekta, broj pohrane. Prazan naziv i adresa = poslovnica se briše. Datoteke već objavljenih cjenika ostaju.</p>
				<table class="widefat striped">
					<thead><tr><th>Naziv</th><th>Oblik objekta</th><th>Adresa</th><th>Oznaka objekta</th><th>Broj pohrane</th><th>Primjer naziva datoteke</th></tr></thead>
					<tbody>
					<?php foreach ( $stores as $i => $s ) : ?>
					<tr>
						<td><input type="hidden" name="p[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( $s['id'] ); ?>"><input type="text" name="p[<?php echo (int) $i; ?>][naziv]" value="<?php echo esc_attr( $s['naziv'] ); ?>" placeholder="npr. Poslovnica Ilica"></td>
						<td><input type="text" name="p[<?php echo (int) $i; ?>][oblik]" value="<?php echo esc_attr( $s['oblik'] ); ?>" placeholder="prodavaonica"></td>
						<td><input type="text" name="p[<?php echo (int) $i; ?>][adresa]" value="<?php echo esc_attr( $s['adresa'] ); ?>" placeholder="Ilica 1, Zagreb" style="width:100%"></td>
						<td><input type="text" name="p[<?php echo (int) $i; ?>][oznaka]" value="<?php echo esc_attr( $s['oznaka'] ); ?>" placeholder="P1" size="6"></td>
						<td><input type="text" name="p[<?php echo (int) $i; ?>][broj_pohrane]" value="<?php echo esc_attr( $s['broj_pohrane'] ); ?>" size="4"></td>
						<td><code><?php echo $s['id'] ? esc_html( SCP_Files::build_filename( $s, 'csv' ) ) : '—'; ?></code></td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php submit_button( 'Spremi poslovnice' ); ?>
		</form>
		<?php
	}

	private static function tab_settings(): void {
		$s = SCP_Settings::all();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'scp_save_settings' ); ?>
			<input type="hidden" name="action" value="scp_save_settings">
			<div class="scp-card">
				<table class="form-table">
					<tr><th><label>Naziv trgovca</label></th><td><input type="text" class="regular-text" name="naziv_trgovca" value="<?php echo esc_attr( $s['naziv_trgovca'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></td></tr>
					<tr><th><label>Referentni datum sidrene cijene</label></th><td><input type="date" name="referentni_datum" value="<?php echo esc_attr( $s['referentni_datum'] ); ?>"> <span class="description">upisuje se uz sidrenu cijenu ako je blagajna isporuči bez datuma</span></td></tr>
					<tr><th><label>Naziv posebnog oblika prodaje</label></th><td><input type="text" name="naziv_akcije" value="<?php echo esc_attr( $s['naziv_akcije'] ); ?>"> <span class="description">kad je akcijska cijena niža od redovne</span></td></tr>
					<tr><th><label>Dostupnost kad stupac nedostaje</label></th><td><select name="dostupnost_zadano"><option value="dostupno" <?php selected( $s['dostupnost_zadano'], 'dostupno' ); ?>>dostupno</option><option value="nedostupno" <?php selected( $s['dostupnost_zadano'], 'nedostupno' ); ?>>nedostupno</option></select></td></tr>
					<tr><th><label>CSV separator</label></th><td><select name="csv_separator"><option value=";" <?php selected( $s['csv_separator'], ';' ); ?>>;</option><option value="," <?php selected( $s['csv_separator'], ',' ); ?>>,</option></select></td></tr>
					<tr><th><label>Decimalni znak u cjeniku</label></th><td><select name="decimalni_znak"><option value="." <?php selected( $s['decimalni_znak'], '.' ); ?>>. (točka)</option><option value="," <?php selected( $s['decimalni_znak'], ',' ); ?>>, (zarez)</option></select></td></tr>
					<tr><th><label>Čuvanje datoteka (dana)</label></th><td><input type="number" min="31" name="retencija_dana" value="<?php echo (int) $s['retencija_dana']; ?>"></td></tr>
					<tr><th><label>Javna adresa</label></th><td><?php echo esc_html( home_url( '/' ) ); ?><input type="text" name="javni_slug" value="<?php echo esc_attr( $s['javni_slug'] ); ?>">/ 
					<?php
					if ( class_exists( 'SC_Public' ) ) :
						?>
						<span class="description">Aktivan je webshop plugin: koristi se njegova stranica i njegova adresa.</span><?php endif; ?></td></tr>
					<tr><th>Podsjetnik e-mailom</th><td><label><input type="checkbox" name="podsjetnik" value="1" <?php checked( $s['podsjetnik'] ); ?>> Svaki dan u <input type="time" name="podsjetnik_vrijeme" value="<?php echo esc_attr( $s['podsjetnik_vrijeme'] ); ?>"> pošalji podsjetnik ako neka poslovnica još nema današnji cjenik</label><br>
						<input type="email" class="regular-text" name="podsjetnik_email" value="<?php echo esc_attr( $s['podsjetnik_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"> <span class="description">primatelj; prazno = admin e-mail</span></td></tr>
				</table>
			</div>
			<?php submit_button( 'Spremi postavke' ); ?>
		</form>
		<?php
	}

	/* ---------- Handleri ---------- */

	public static function handle_upload(): void {
		check_admin_referer( 'scp_upload' );
		if ( ! current_user_can( SCP_CAP ) ) {
			wp_die( 'Nemate ovlasti.' );
		}
		$store_id = isset( $_POST['store'] ) ? sanitize_key( wp_unslash( $_POST['store'] ) ) : '';
		$store    = SCP_Settings::store( $store_id );
		if ( ! $store ) {
			self::redirect( 'objava', 'Nepoznata poslovnica.', true );
		}
		$tmp = isset( $_FILES['csv']['tmp_name'] ) ? sanitize_text_field( (string) $_FILES['csv']['tmp_name'] ) : '';
		if ( $tmp === '' || ! is_uploaded_file( $tmp ) ) {
			self::redirect( 'objava', 'Datoteka nije primljena.', true );
		}
		if ( absint( $_FILES['csv']['size'] ?? 0 ) > 20 * MB_IN_BYTES ) {
			self::redirect( 'objava', 'Datoteka je veća od 20 MB.', true );
		}
		$parsed = SCP_Convert::parse( $tmp );
		if ( $parsed['errors'] ) {
			self::redirect( 'objava', implode( ' ', $parsed['errors'] ), true );
		}
		$t = SCP_Convert::transform( $parsed, SCP_Settings::all() );
		if ( ! $t['rows'] ) {
			self::redirect( 'objava', 'U datoteci nema nijednog retka s barkodom/nazivom i cijenom.', true );
		}
		// Spremi pretvorene retke u tmp mapu, u pregledu se pokazuje i potvrđuje objava.
		SCP_Files::ensure_dirs();
		$token = wp_generate_password( 16, false );
		file_put_contents( SCP_Files::tmp_dir() . 'pregled_' . $token . '.json', wp_json_encode( $t['rows'] ) );
		set_transient(
			'scp_preview_' . $token,
			[
				'token'  => $token,
				'store'  => $store,
				'header' => $parsed['header'],
				'map'    => $parsed['map'],
				'stats'  => $t['stats'],
				'rows'   => array_slice( $t['rows'], 0, 8 ),
				'user'   => get_current_user_id(),
			],
			HOUR_IN_SECONDS
		);
		self::redirect( 'objava', '', false, [ 'pregled' => $token ] );
	}

	public static function handle_publish(): void {
		check_admin_referer( 'scp_publish' );
		if ( ! current_user_can( SCP_CAP ) ) {
			wp_die( 'Nemate ovlasti.' );
		}
		$token   = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$preview = $token ? get_transient( 'scp_preview_' . $token ) : null;
		$path    = SCP_Files::tmp_dir() . 'pregled_' . $token . '.json';
		if ( ! is_array( $preview ) || (int) $preview['user'] !== get_current_user_id() || ! file_exists( $path ) ) {
			self::redirect( 'objava', 'Pregled je istekao, prenesi datoteku ponovno.', true );
		}
		$rows = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokalna privremena datoteka.
		delete_transient( 'scp_preview_' . $token );
		wp_delete_file( $path );
		if ( ! is_array( $rows ) || ! $rows ) {
			self::redirect( 'objava', 'Podaci pregleda nisu čitljivi, prenesi datoteku ponovno.', true );
		}
		$r = SCP_Convert::write( $preview['store'], $rows, SCP_Settings::all() );
		self::redirect( 'objava', sprintf( 'Cjenik objavljen: %s (%d artikala). XML: %s', $r['csv'], count( $rows ), $r['xml'] ) );
	}

	public static function save_stores(): void {
		check_admin_referer( 'scp_save_stores' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nemate ovlasti.' );
		}
		$in  = isset( $_POST['p'] ) && is_array( $_POST['p'] ) ? wp_unslash( $_POST['p'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- svako polje se sanitizira ispod.
		$out = [];
		foreach ( $in as $p ) {
			$naziv  = sanitize_text_field( (string) ( $p['naziv'] ?? '' ) );
			$adresa = sanitize_text_field( (string) ( $p['adresa'] ?? '' ) );
			if ( $naziv === '' && $adresa === '' ) {
				continue;
			}
			$id = sanitize_key( (string) ( $p['id'] ?? '' ) );
			if ( $id === '' ) {
				$id = sanitize_key( sanitize_title( $p['oznaka'] ?: $naziv ?: $adresa ) ) ?: 'p' . ( count( $out ) + 1 );
				while ( in_array( $id, array_column( $out, 'id' ), true ) ) {
					$id .= '1';
				}
			}
			$out[] = [
				'id'           => $id,
				'naziv'        => $naziv,
				'oblik'        => sanitize_text_field( (string) ( $p['oblik'] ?? 'prodavaonica' ) ) ?: 'prodavaonica',
				'adresa'       => $adresa,
				'oznaka'       => sanitize_text_field( (string) ( $p['oznaka'] ?? '' ) ),
				'broj_pohrane' => sanitize_text_field( (string) ( $p['broj_pohrane'] ?? '1' ) ) ?: '1',
			];
		}
		SCP_Settings::update( [ 'poslovnice' => $out ] );
		foreach ( $out as $s ) {
			SCP_Files::ensure_dirs( $s['id'] );
		}
		SCP_Files::write_index();
		self::redirect( 'poslovnice', 'Poslovnice spremljene.' );
	}

	public static function save_settings(): void {
		check_admin_referer( 'scp_save_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nemate ovlasti.' );
		}
		$p = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- svako polje se sanitizira ispod.
		SCP_Settings::update(
			[
				'naziv_trgovca'      => sanitize_text_field( (string) ( $p['naziv_trgovca'] ?? '' ) ),
				'referentni_datum'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $p['referentni_datum'] ?? '' ) ) ? (string) $p['referentni_datum'] : '2026-09-10',
				'naziv_akcije'       => sanitize_text_field( (string) ( $p['naziv_akcije'] ?? 'Akcija' ) ) ?: 'Akcija',
				'dostupnost_zadano'  => ( $p['dostupnost_zadano'] ?? '' ) === 'nedostupno' ? 'nedostupno' : 'dostupno',
				'csv_separator'      => ( $p['csv_separator'] ?? ';' ) === ',' ? ',' : ';',
				'decimalni_znak'     => ( $p['decimalni_znak'] ?? '.' ) === ',' ? ',' : '.',
				'retencija_dana'     => max( 31, (int) ( $p['retencija_dana'] ?? 35 ) ),
				'javni_slug'         => sanitize_title( (string) ( $p['javni_slug'] ?? 'cjenik' ) ) ?: 'cjenik',
				'podsjetnik'         => empty( $p['podsjetnik'] ) ? 0 : 1,
				'podsjetnik_vrijeme' => preg_match( '/^\d{2}:\d{2}$/', (string) ( $p['podsjetnik_vrijeme'] ?? '' ) ) ? (string) $p['podsjetnik_vrijeme'] : '07:00',
				'podsjetnik_email'   => sanitize_email( (string) ( $p['podsjetnik_email'] ?? '' ) ),
			]
		);
		SCP_Files::schedule_reminder();
		if ( ! class_exists( 'SC_Public' ) ) {
			SCP_Public::register_rewrites();
			flush_rewrite_rules();
		}
		self::redirect( 'postavke', 'Postavke spremljene.' );
	}

	public static function delete_file(): void {
		$store = isset( $_GET['store'] ) ? sanitize_key( wp_unslash( $_GET['store'] ) ) : '';
		$file  = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		check_admin_referer( 'scp_delete_' . $store . '_' . $file );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nemate ovlasti.' );
		}
		$ok = $store !== '' && $file !== '' && SCP_Settings::store( $store ) && SCP_Files::delete_file( $store, $file );
		self::redirect( 'datoteke', $ok ? 'Datoteka obrisana: ' . $file : 'Datoteka nije pronađena.', ! $ok );
	}

	public static function download_template(): void {
		check_admin_referer( 'scp_template' );
		if ( ! current_user_can( SCP_CAP ) ) {
			wp_die( 'Nemate ovlasti.' );
		}
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="predlozak-cjenik-poslovnica.csv"' );
		echo "\xEF\xBB\xBF";
		echo "barkod;naziv;cijena;akcijska_cijena;dostupnost;sidrena_cijena;sifra;marka\n";
		echo "3859890000011;\"Primjer proizvod A (CD)\";10,49;;dostupno;10,49;ART-0001;Primjer marka\n";
		echo "3859890000028;\"Primjer proizvod B (LP)\";19,00;15,00;dostupno;19,00;ART-0002;\n";
		echo "3859890000035;\"Primjer proizvod C (majica)\";9,00;;nedostupno;9,00;;\n";
		exit;
	}
}
