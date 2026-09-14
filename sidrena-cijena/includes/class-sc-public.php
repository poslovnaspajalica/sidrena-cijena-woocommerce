<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Javna stranica /cjenik/ i stabilni linkovi /cjenik/latest.csv, /cjenik/latest.xml, /cjenik/index.json
 */
final class SC_Public {

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_rewrites']);
        add_filter('query_vars', [__CLASS__, 'query_vars']);
        add_action('template_redirect', [__CLASS__, 'maybe_render'], 1);
        add_filter('redirect_canonical', [__CLASS__, 'no_canonical'], 10, 2);
    }

    /** Bez kanonskog redirecta (kosa crta) na naše rute. */
    public static function no_canonical($redirect_url, $requested_url) {
        return get_query_var('sc_cjenik') ? false : $redirect_url;
    }

    public static function slug(): string {
        $s = sanitize_title((string) SC_Settings::get('javni_slug'));
        return $s ?: 'cjenik';
    }

    public static function url(string $path = ''): string {
        return home_url('/' . self::slug() . '/' . $path);
    }

    public static function register_rewrites(): void {
        $slug = self::slug();
        add_rewrite_rule('^' . $slug . '/?$', 'index.php?sc_cjenik=index', 'top');
        add_rewrite_rule('^' . $slug . '/latest\.(csv|xml)$', 'index.php?sc_cjenik=latest&sc_fmt=$matches[1]', 'top');
        add_rewrite_rule('^' . $slug . '/index\.json$', 'index.php?sc_cjenik=json', 'top');
    }

    public static function query_vars(array $vars): array {
        $vars[] = 'sc_cjenik';
        $vars[] = 'sc_fmt';
        return $vars;
    }

    public static function maybe_render(): void {
        $what = get_query_var('sc_cjenik');
        if (!$what) {
            return;
        }
        nocache_headers();

        if ($what === 'latest') {
            $fmt  = get_query_var('sc_fmt') === 'xml' ? 'xml' : 'csv';
            $last = SC_Export::last();
            if (empty($last[$fmt]) || !file_exists(SC_Export::files_dir() . $last[$fmt])) {
                status_header(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo "Cjenik jos nije generiran.";
                exit;
            }
            wp_redirect(SC_Export::files_url() . rawurlencode($last[$fmt]), 302);
            exit;
        }

        if ($what === 'json') {
            $f = SC_Export::files_dir() . 'index.json';
            if (!file_exists($f)) {
                SC_Export::write_index();
            }
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            readfile($f);
            exit;
        }

        self::render_index();
        exit;
    }

    private static function render_index(): void {
        $files = SC_Export::list_files();
        $last  = SC_Export::last();
        $s     = SC_Settings::all();
        $name  = $s['naziv_trgovca'] ?: get_bloginfo('name');
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cjenik - <?php echo esc_html($name); ?></title>
<style>
:root{color-scheme:light}body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:900px;margin:2rem auto;padding:0 1rem;color:#222;background:#fff;line-height:1.5}a{color:#1a56b0}
table{border-collapse:collapse;width:100%;margin-top:1rem}th,td{text-align:left;padding:.4rem .6rem;border-bottom:1px solid #ddd;font-size:.95rem}
code{background:#f3f3f3;padding:.1rem .3rem;border-radius:3px}small{color:#666}
</style>
</head>
<body>
<h1>Cjenik proizvoda - <?php echo esc_html($name); ?></h1>
<p>Cjenici objavljeni sukladno Odluci o objavi cjenika proizvoda i usluga kao mjera izravne kontrole cijena (NN 101/2026). Datoteke su u strojno čitljivom obliku (.csv, .xml) i ostaju dostupne najmanje 30 dana od objave.</p>
<p>
Sidrena cijena: cijena koja je vrijedila na dan <strong><?php echo esc_html(SC_Settings::format_date($s['referentni_datum'])); ?></strong><?php if (!empty($s['alt_kategorije'])) : ?>, odnosno <?php echo esc_html(SC_Settings::format_date($s['alt_datum'])); ?> za kategorije obuhvaćene Odlukom NN 75/2025.<?php endif; ?>
</p>
<p>Stabilni linkovi na najnoviji cjenik:
<code><?php echo esc_html(self::url('latest.csv')); ?></code>
<code><?php echo esc_html(self::url('latest.xml')); ?></code>
<br>Strojno čitljiv popis svih datoteka: <code><?php echo esc_html(self::url('index.json')); ?></code></p>
<?php if ($last) : ?>
<p><small>Zadnje generiranje: <?php echo esc_html(wp_date('d.m.Y. H:i', (int) $last['time'])); ?>, redaka: <?php echo (int) $last['rows']; ?></small></p>
<?php endif; ?>
<table>
<thead><tr><th>Datoteka</th><th>Format</th><th>Objavljeno</th><th>Veličina</th></tr></thead>
<tbody>
<?php if (!$files) : ?>
<tr><td colspan="4">Cjenik još nije generiran.</td></tr>
<?php endif; ?>
<?php foreach ($files as $f) : ?>
<tr>
<td><a href="<?php echo esc_url($f['url']); ?>"><?php echo esc_html($f['name']); ?></a></td>
<td><?php echo esc_html(strtoupper($f['ext'])); ?></td>
<td><?php echo esc_html(wp_date('d.m.Y. H:i', $f['mtime'])); ?></td>
<td><?php echo esc_html(size_format($f['size'])); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p><small>Stupci: naziv; šifra; marka; jedinica mjere; cijena za jedinicu mjere; maloprodajna cijena; posebni oblik prodaje (DA/NE); naziv posebnog oblika prodaje; sidrena cijena; barkod; dostupnost; datum sidrene cijene; kategorija; url. CSV: UTF-8, separator „<?php echo esc_html($s['csv_separator']); ?>“, vrijednosti u navodnicima.</small></p>
</body>
</html>
        <?php
    }
}
