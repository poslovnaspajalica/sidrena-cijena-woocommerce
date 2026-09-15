<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Javna stranica /cjenik/ s karticama po prodajnom objektu, stabilni linkovi i index.json.
 * Drugi pluginovi (npr. cjenik poslovnica) dodaju odjeljke filterom `sidrena_cijena_public_sections`.
 */
final class SC_Public {

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_rewrites' ] );
		add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_render' ], 1 );
		add_filter( 'redirect_canonical', [ __CLASS__, 'no_canonical' ] );
	}

	/** Bez kanonskog redirecta (kosa crta) na naše rute. */
	public static function no_canonical( $redirect_url ) {
		return get_query_var( 'sc_cjenik' ) ? false : $redirect_url;
	}

	public static function slug(): string {
		$s = sanitize_title( (string) SC_Settings::get( 'javni_slug' ) );
		return $s ?: 'cjenik';
	}

	public static function url( string $path = '' ): string {
		return home_url( '/' . self::slug() . '/' . $path );
	}

	public static function register_rewrites(): void {
		$slug = self::slug();
		add_rewrite_rule( '^' . $slug . '/?$', 'index.php?sc_cjenik=index', 'top' );
		add_rewrite_rule( '^' . $slug . '/latest\.(csv|xml)$', 'index.php?sc_cjenik=latest&sc_fmt=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $slug . '/index\.json$', 'index.php?sc_cjenik=json', 'top' );
		add_rewrite_rule( '^' . $slug . '/([a-z0-9-]+)/latest\.(csv|xml)$', 'index.php?sc_cjenik=latest&sc_objekt=$matches[1]&sc_fmt=$matches[2]', 'top' );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'sc_cjenik';
		$vars[] = 'sc_fmt';
		$vars[] = 'sc_objekt';
		return $vars;
	}

	/** Odjeljak webshopa. */
	public static function webshop_section(): array {
		$last  = SC_Export::last();
		$files = SC_Export::list_files();
		return [
			'id'      => 'webshop',
			'naziv'   => 'Webshop',
			'opis'    => trim( ( SC_Settings::get( 'oblik_objekta' ) ?: 'webshop' ) . ', ' . SC_Settings::get( 'adresa' ), ', ' ),
			'files'   => $files,
			'latest'  => [
				'csv' => ! empty( $last['csv'] ) ? SC_Export::files_url() . rawurlencode( $last['csv'] ) : null,
				'xml' => ! empty( $last['xml'] ) ? SC_Export::files_url() . rawurlencode( $last['xml'] ) : null,
			],
			'updated' => $last['time'] ?? null,
		];
	}

	/** Svi odjeljci: webshop + ono što dodaju drugi pluginovi. */
	public static function sections(): array {
		$sections = [ self::webshop_section() ];
		$sections = apply_filters( 'sidrena_cijena_public_sections', $sections );
		return array_values( array_filter( (array) $sections, static fn( $s ) => is_array( $s ) && ! empty( $s['id'] ) ) );
	}

	public static function index_data(): array {
		$data = [
			'trgovac'          => SC_Settings::get( 'naziv_trgovca' ) ?: get_bloginfo( 'name' ),
			'referentni_datum' => SC_Settings::get( 'referentni_datum' ),
			'generirano'       => wp_date( 'c' ),
			'objekti'          => [],
		];
		foreach ( self::sections() as $s ) {
			$data['objekti'][] = [
				'id'        => $s['id'],
				'naziv'     => $s['naziv'],
				'opis'      => $s['opis'] ?? '',
				'najnoviji' => $s['latest'],
				'datoteke'  => array_map(
					static fn( $f ) => [
						'naziv'      => $f['name'],
						'format'     => $f['ext'],
						'velicina'   => $f['size'],
						'datum'      => wp_date( 'c', $f['mtime'] ),
						'vrijedi_za' => $f['vrijedi_za'] ?? wp_date( 'Y-m-d', $f['mtime'] ),
						'url'        => $f['url'],
					],
					$s['files']
				),
			];
		}
		// Kompatibilnost sa starim potrošačima index.json (najnoviji webshop + datoteke).
		$data['najnoviji'] = $data['objekti'][0]['najnoviji'] ?? [
			'csv' => null,
			'xml' => null,
		];
		$data['datoteke']  = $data['objekti'][0]['datoteke'] ?? [];
		return apply_filters( 'sidrena_cijena_index_data', $data );
	}

	public static function maybe_render(): void {
		$what = get_query_var( 'sc_cjenik' );
		if ( ! $what ) {
			return;
		}
		nocache_headers();

		if ( $what === 'latest' ) {
			$fmt = get_query_var( 'sc_fmt' ) === 'xml' ? 'xml' : 'csv';
			$id  = sanitize_key( get_query_var( 'sc_objekt' ) ) ?: 'webshop';
			foreach ( self::sections() as $s ) {
				if ( $s['id'] === $id && ! empty( $s['latest'][ $fmt ] ) ) {
					wp_safe_redirect( $s['latest'][ $fmt ], 302 );
					exit;
				}
			}
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Cjenik jos nije generiran.';
			exit;
		}

		if ( $what === 'json' ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Access-Control-Allow-Origin: *' );
			echo wp_json_encode( self::index_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			exit;
		}

		self::render_page( self::sections(), (string) ( SC_Settings::get( 'naziv_trgovca' ) ?: get_bloginfo( 'name' ) ), self::url(), SC_Settings::format_date( (string) SC_Settings::get( 'referentni_datum' ) ) );
		exit;
	}

	/**
	 * Prikaz stranice s karticama po prodajnom objektu.
	 * @param array $sections [ ['id','naziv','opis','files','latest'=>['csv','xml'],'updated'], ... ]
	 */
	public static function render_page( array $sections, string $name, string $base_url, string $ref_date ): void {
		$active = isset( $_GET['objekt'] ) ? sanitize_key( wp_unslash( $_GET['objekt'] ) ) : ( $sections[0]['id'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odabir kartice.
		$ids    = array_column( $sections, 'id' );
		if ( ! in_array( $active, $ids, true ) ) {
			$active = $ids[0] ?? '';
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cjenik - <?php echo esc_html( $name ); ?></title>
<style>
:root{color-scheme:light}body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:960px;margin:2rem auto;padding:0 1rem;color:#222;background:#fff;line-height:1.5}a{color:#1a56b0}
table{border-collapse:collapse;width:100%;margin-top:1rem}th,td{text-align:left;padding:.4rem .6rem;border-bottom:1px solid #ddd;font-size:.95rem}
code{background:#f3f3f3;padding:.1rem .3rem;border-radius:3px;font-size:.9em}small{color:#666}
.tabs{display:flex;flex-wrap:wrap;gap:.25rem;margin:1.5rem 0 0;border-bottom:2px solid #ddd}.tabs a{padding:.5rem .9rem;text-decoration:none;color:#444;border:1px solid transparent;border-bottom:0;border-radius:6px 6px 0 0}.tabs a.on{background:#f3f5f8;border-color:#ddd;color:#111;font-weight:600}
.sec{display:none}.sec.on{display:block}
</style>
</head>
<body>
<h1>Cjenik proizvoda - <?php echo esc_html( $name ); ?></h1>
<p>Cjenici objavljeni sukladno Odluci o objavi cjenika proizvoda i usluga kao mjera izravne kontrole cijena (NN 101/2026). Datoteke su u strojno čitljivom obliku (.csv, .xml) i ostaju dostupne najmanje 30 dana od objave. Sidrena cijena: cijena koja je vrijedila na dan <strong><?php echo esc_html( $ref_date ); ?></strong></p>
<p>Strojno čitljiv popis svih datoteka i objekata: <code><?php echo esc_html( $base_url . 'index.json' ); ?></code></p>
		<?php if ( count( $sections ) > 1 ) : ?>
<nav class="tabs">
			<?php foreach ( $sections as $s ) : ?>
	<a href="<?php echo esc_url( add_query_arg( 'objekt', $s['id'], $base_url ) ); ?>" class="<?php echo $s['id'] === $active ? 'on' : ''; ?>" data-tab="<?php echo esc_attr( $s['id'] ); ?>"><?php echo esc_html( $s['naziv'] ); ?></a>
	<?php endforeach; ?>
</nav>
		<?php endif; ?>
		<?php foreach ( $sections as $s ) : ?>
<section class="sec <?php echo $s['id'] === $active ? 'on' : ''; ?>" id="sec-<?php echo esc_attr( $s['id'] ); ?>">
	<h2><?php echo esc_html( $s['naziv'] ); ?></h2>
			<?php
			if ( ! empty( $s['opis'] ) ) :
				?>
				<p><small><?php echo esc_html( $s['opis'] ); ?></small></p><?php endif; ?>
	<p>Najnoviji cjenik:
			<?php
			if ( ! empty( $s['latest']['csv'] ) ) :
				?>
				<a href="<?php echo esc_url( $s['latest']['csv'] ); ?>">CSV</a><?php endif; ?>
			<?php
			if ( ! empty( $s['latest']['xml'] ) ) :
				?>
				· <a href="<?php echo esc_url( $s['latest']['xml'] ); ?>">XML</a><?php endif; ?>
		<br><small>Stabilni linkovi: <code><?php echo esc_html( $base_url . ( $s['id'] === 'webshop' ? '' : $s['id'] . '/' ) . 'latest.csv' ); ?></code> <code><?php echo esc_html( $base_url . ( $s['id'] === 'webshop' ? '' : $s['id'] . '/' ) . 'latest.xml' ); ?></code></small>
			<?php
			if ( ! empty( $s['updated'] ) ) :
				?>
				<br><small>Zadnja objava: <?php echo esc_html( wp_date( 'd.m.Y. H:i', (int) $s['updated'] ) ); ?></small><?php endif; ?>
	</p>
	<table>
		<thead><tr><th>Datoteka</th><th>Format</th><th>Vrijedi za</th><th>Objavljeno</th><th>Veličina</th></tr></thead>
		<tbody>
			<?php
			if ( empty( $s['files'] ) ) :
				?>
				<tr><td colspan="5">Cjenik još nije objavljen.</td></tr><?php endif; ?>
			<?php foreach ( $s['files'] as $f ) : ?>
			<tr><td><a href="<?php echo esc_url( $f['url'] ); ?>"><?php echo esc_html( $f['name'] ); ?></a></td><td><?php echo esc_html( strtoupper( $f['ext'] ) ); ?></td><td><?php echo esc_html( SC_Settings::format_date( $f['vrijedi_za'] ?? wp_date( 'Y-m-d', $f['mtime'] ) ) ); ?></td><td><?php echo esc_html( wp_date( 'd.m.Y. H:i', $f['mtime'] ) ); ?></td><td><?php echo esc_html( size_format( $f['size'] ) ); ?></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</section>
		<?php endforeach; ?>
<p><small>Stupci: naziv; šifra; marka; jedinica mjere; cijena za jedinicu mjere; maloprodajna cijena; posebni oblik prodaje (DA/NE); naziv posebnog oblika prodaje; sidrena cijena; barkod; dostupnost; datum sidrene cijene; kategorija; url. CSV: UTF-8, separator „<?php echo esc_html( (string) SC_Settings::get( 'csv_separator' ) ); ?>“, vrijednosti u navodnicima.</small></p>
<script>
document.querySelectorAll('.tabs a').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();document.querySelectorAll('.tabs a').forEach(function(x){x.classList.remove('on')});document.querySelectorAll('.sec').forEach(function(x){x.classList.remove('on')});a.classList.add('on');document.getElementById('sec-'+a.dataset.tab).classList.add('on');history.replaceState(null,'',a.href)})});
</script>
</body>
</html>
		<?php
	}
}
