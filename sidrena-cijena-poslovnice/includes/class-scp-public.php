<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Javna stranica: ako je aktivan webshop plugin, dodaje odjeljke na njegovu /cjenik/ stranicu;
 * inače sam registrira /cjenik/ i prikazuje poslovnice.
 */
final class SCP_Public {

	public static function init(): void {
		if ( class_exists( 'SC_Public' ) ) {
			add_filter( 'sidrena_cijena_public_sections', [ __CLASS__, 'add_sections' ] );
			return;
		}
		add_action( 'init', [ __CLASS__, 'register_rewrites' ] );
		add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_render' ], 1 );
		add_filter( 'redirect_canonical', [ __CLASS__, 'no_canonical' ] );
	}

	public static function add_sections( array $sections ): array {
		return array_merge( $sections, SCP_Files::sections() );
	}


	public static function index_objects(): array {
		return array_map(
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
			SCP_Files::sections()
		);
	}

	public static function slug(): string {
		return sanitize_title( (string) SCP_Settings::get( 'javni_slug' ) ) ?: 'cjenik';
	}

	public static function url( string $path = '' ): string {
		return home_url( '/' . self::slug() . '/' . $path );
	}

	public static function register_rewrites(): void {
		$slug = self::slug();
		add_rewrite_rule( '^' . $slug . '/?$', 'index.php?scp_cjenik=index', 'top' );
		add_rewrite_rule( '^' . $slug . '/index\.json$', 'index.php?scp_cjenik=json', 'top' );
		add_rewrite_rule( '^' . $slug . '/([a-z0-9-]+)/latest\.(csv|xml)$', 'index.php?scp_cjenik=latest&scp_objekt=$matches[1]&scp_fmt=$matches[2]', 'top' );
	}

	public static function query_vars( array $vars ): array {
		return array_merge( $vars, [ 'scp_cjenik', 'scp_objekt', 'scp_fmt' ] );
	}

	public static function no_canonical( $redirect_url ) {
		return get_query_var( 'scp_cjenik' ) ? false : $redirect_url;
	}

	public static function maybe_render(): void {
		$what = get_query_var( 'scp_cjenik' );
		if ( ! $what ) {
			return;
		}
		nocache_headers();
		$sections = SCP_Files::sections();

		if ( $what === 'latest' ) {
			$id  = sanitize_key( get_query_var( 'scp_objekt' ) );
			$fmt = get_query_var( 'scp_fmt' ) === 'xml' ? 'xml' : 'csv';
			foreach ( $sections as $s ) {
				if ( $s['id'] === $id && ! empty( $s['latest'][ $fmt ] ) ) {
					wp_safe_redirect( $s['latest'][ $fmt ], 302 );
					exit;
				}
			}
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Cjenik nije pronaden.';
			exit;
		}

		if ( $what === 'json' ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Access-Control-Allow-Origin: *' );
			echo wp_json_encode(
				[
					'trgovac'    => SCP_Settings::get( 'naziv_trgovca' ) ?: get_bloginfo( 'name' ),
					'generirano' => wp_date( 'c' ),
					'objekti'    => self::index_objects(),
				],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
			exit;
		}

		self::render_page( $sections, SCP_Settings::get( 'naziv_trgovca' ) ?: get_bloginfo( 'name' ), self::url(), SCP_Settings::format_date( (string) SCP_Settings::get( 'referentni_datum' ) ) );
		exit;
	}

	/**
	 * Zajednički prikaz stranice s karticama po prodajnom objektu. Koristi ga i webshop plugin.
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
.sec{display:none}.sec.on{display:block}.stale{color:#b32d2e}
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
		<br><small>Stabilni linkovi: <code><?php echo esc_html( $base_url . $s['id'] . '/latest.csv' ); ?></code> <code><?php echo esc_html( $base_url . $s['id'] . '/latest.xml' ); ?></code></small>
			<?php
			if ( ! empty( $s['updated'] ) ) :
				?>
				<br><small>Zadnja objava: <?php echo esc_html( wp_date( 'd.m.Y. H:i', (int) $s['updated'] ) ); ?></small><?php endif; ?>
	</p>
	<table>
		<thead><tr><th>Datoteka</th><th>Format</th><th>Objavljeno</th><th>Veličina</th></tr></thead>
		<tbody>
			<?php
			if ( empty( $s['files'] ) ) :
				?>
				<tr><td colspan="4">Cjenik još nije objavljen.</td></tr><?php endif; ?>
			<?php foreach ( $s['files'] as $f ) : ?>
			<tr><td><a href="<?php echo esc_url( $f['url'] ); ?>"><?php echo esc_html( $f['name'] ); ?></a></td><td><?php echo esc_html( strtoupper( $f['ext'] ) ); ?></td><td><?php echo esc_html( wp_date( 'd.m.Y. H:i', $f['mtime'] ) ); ?></td><td><?php echo esc_html( size_format( $f['size'] ) ); ?></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</section>
		<?php endforeach; ?>
<p><small>Stupci: naziv; šifra; marka; jedinica mjere; cijena za jedinicu mjere; maloprodajna cijena; posebni oblik prodaje (DA/NE); naziv posebnog oblika prodaje; sidrena cijena; barkod; dostupnost; datum sidrene cijene; kategorija; url. CSV: UTF-8, separator „;“, vrijednosti u navodnicima.</small></p>
<script>
document.querySelectorAll('.tabs a').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();document.querySelectorAll('.tabs a').forEach(function(x){x.classList.remove('on')});document.querySelectorAll('.sec').forEach(function(x){x.classList.remove('on')});a.classList.add('on');document.getElementById('sec-'+a.dataset.tab).classList.add('on');history.replaceState(null,'',a.href)})});
</script>
</body>
</html>
		<?php
	}
}
