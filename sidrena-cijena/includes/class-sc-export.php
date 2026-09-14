<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generiranje cjenika (.csv i .xml), cron, retencija.
 */
final class SC_Export {
    public const CRON_HOOK   = 'sidrena_cijena_daily_export';
    public const INITIAL_HOOK = 'sidrena_cijena_initial';
    public const STATE_OPT   = 'sidrena_cijena_export_state';
    public const LAST_OPT    = 'sidrena_cijena_last_export';
    public const BATCH       = 500;

    public static function init(): void {
        add_action(self::CRON_HOOK, [__CLASS__, 'cron']);
        add_action(self::INITIAL_HOOK, [__CLASS__, 'initial']);
        add_action('wp_loaded', [__CLASS__, 'maybe_external_trigger']);
        add_action('shutdown', [__CLASS__, 'maybe_catch_up'], 999);
        add_action('wp_ajax_sc_export_batch', [__CLASS__, 'ajax_batch']);
    }

    /* ---------- Direktoriji ---------- */

    public static function base_dir(): string {
        $u = wp_upload_dir();
        return trailingslashit($u['basedir']) . 'sidrena-cijena/';
    }

    public static function base_url(): string {
        $u = wp_upload_dir();
        return trailingslashit($u['baseurl']) . 'sidrena-cijena/';
    }

    public static function files_dir(): string {
        return self::base_dir() . 'cjenik/';
    }

    public static function files_url(): string {
        return self::base_url() . 'cjenik/';
    }

    public static function tmp_dir(): string {
        return self::base_dir() . 'tmp/';
    }

    public static function ensure_dirs(): void {
        foreach ([self::base_dir(), self::files_dir(), self::tmp_dir()] as $d) {
            if (!is_dir($d)) {
                wp_mkdir_p($d);
            }
        }
        if (!file_exists(self::tmp_dir() . 'index.html')) {
            file_put_contents(self::tmp_dir() . 'index.html', '');
        }
        if (!file_exists(self::tmp_dir() . '.htaccess')) {
            file_put_contents(self::tmp_dir() . '.htaccess', "Require all denied\n");
        }
    }

    /* ---------- Cron ---------- */

    public static function schedule_cron(): void {
        self::unschedule_cron();
        $hhmm = (string) SC_Settings::get('cron_vrijeme');
        if (!preg_match('/^\d{1,2}:\d{2}$/', $hhmm)) {
            $hhmm = '04:00';
        }
        $tz   = wp_timezone();
        $now  = new DateTimeImmutable('now', $tz);
        $next = new DateTimeImmutable('today ' . $hhmm, $tz);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }
        wp_schedule_event($next->getTimestamp(), 'daily', self::CRON_HOOK);
    }

    public static function unschedule_cron(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function cron(): void {
        self::run_if_free('cron');
    }

    /** Zaključano izvršavanje: sprječava dva istovremena generiranja. */
    public static function run_if_free(string $trigger): ?array {
        if (get_transient('sc_export_lock')) {
            return null;
        }
        set_transient('sc_export_lock', $trigger, 15 * MINUTE_IN_SECONDS);
        try {
            return self::run_full($trigger);
        } finally {
            delete_transient('sc_export_lock');
        }
    }

    /** Je li današnji cjenik već generiran (od zakazanog vremena naovamo). */
    public static function is_due(): bool {
        $due_ts = SC_Settings::today_run_timestamp();
        if (time() < $due_ts) {
            return false;
        }
        $last = self::last();
        return empty($last['time']) || (int) $last['time'] < $due_ts;
    }

    /**
     * Vanjski okidač: https://domena/?sidrena_cron=TOKEN (za sistemski cron ili vanjski servis).
     * Radi neovisno o WP-Cronu. Uvijek generira, i kad danas već postoji cjenik (osim uz ?only_due=1).
     */
    public static function maybe_external_trigger(): void {
        if (!isset($_GET['sidrena_cron'])) {
            return;
        }
        $given = (string) $_GET['sidrena_cron'];
        if (!hash_equals(SC_Settings::cron_token(), $given)) {
            status_header(403);
            header('Content-Type: application/json; charset=utf-8');
            echo wp_json_encode(['ok' => false, 'error' => 'invalid token']);
            exit;
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        if (!empty($_GET['only_due']) && !self::is_due()) {
            echo wp_json_encode(['ok' => true, 'skipped' => 'already generated today', 'last' => self::last()]);
            exit;
        }
        if (get_transient('sc_export_lock')) {
            echo wp_json_encode(['ok' => false, 'error' => 'export already running']);
            exit;
        }
        if (!empty($_GET['wait'])) {
            // Sinkrono (za testiranje); može premašiti timeout web servera na velikim katalozima.
            SC_Snapshot::run_full(false);
            $r = self::run_if_free('external');
            echo wp_json_encode($r === null ? ['ok' => false, 'error' => 'export already running'] : ['ok' => true] + $r);
            exit;
        }
        // Zadano: odmah odgovori, generiraj u pozadini (izbjegava timeout servera).
        echo wp_json_encode(['ok' => true, 'started' => true, 'last' => self::last()]);
        self::finish_request();
        SC_Snapshot::run_full(false);
        self::run_if_free('external');
        exit;
    }

    /** Pošalji odgovor klijentu i nastavi izvršavanje u pozadini. */
    private static function finish_request(): void {
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            if (!headers_sent()) {
                header('Connection: close');
                header('Content-Length: ' . (int) ob_get_length());
            }
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }
    }

    /** Je li zadnji cjenik stariji od 24 h (za upozorenje u adminu). */
    public static function is_stale(): bool {
        $last = self::last();
        return empty($last['time']) || (time() - (int) $last['time']) > 26 * HOUR_IN_SECONDS;
    }

    /**
     * Rezerva: ako je zakazano vrijeme prošlo, a današnji cjenik ne postoji (WP-Cron nije uspio),
     * generiraj ga na kraju prvog zahtjeva, nakon što je odgovor poslan posjetitelju.
     */
    public static function maybe_catch_up(): void {
        if (PHP_SAPI === 'cli' || wp_doing_cron() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)) {
            return;
        }
        if (isset($_GET['sidrena_cron']) || get_transient('sc_export_lock') || !self::is_due()) {
            return;
        }
        self::finish_request();
        SC_Snapshot::run_full(false);
        self::run_if_free('catch-up');
    }

    /** Nakon aktivacije: samo zabilježi sidrene cijene gdje nedostaju (brzo, SQL). Cjenik se ne generira automatski. */
    public static function initial(): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        SC_Snapshot::run_full(false);
    }

    /** Obriši objavljenu datoteku cjenika (admin). */
    public static function delete_file(string $name): bool {
        $name = basename($name);
        $ok = false;
        foreach (self::list_files() as $f) {
            if ($f['name'] === $name) {
                $ok = @unlink(self::files_dir() . $name);
                break;
            }
        }
        if ($ok) {
            $last = self::last();
            if (($last['csv'] ?? '') === $name || ($last['xml'] ?? '') === $name) {
                // Zadnji cjenik = najnoviji preostali par.
                $files = self::list_files();
                $csv = $xml = null;
                foreach ($files as $f) {
                    if ($f['ext'] === 'csv' && !$csv) { $csv = $f; }
                    if ($f['ext'] === 'xml' && !$xml) { $xml = $f; }
                }
                if ($csv || $xml) {
                    $last['csv'] = $csv['name'] ?? '';
                    $last['xml'] = $xml['name'] ?? '';
                    $last['time'] = max($csv['mtime'] ?? 0, $xml['mtime'] ?? 0);
                    update_option(self::LAST_OPT, $last, false);
                } else {
                    delete_option(self::LAST_OPT);
                }
            }
            self::write_index();
        }
        return $ok;
    }

    /* ---------- Naziv datoteke ---------- */

    public static function build_filename(string $ext, ?int $ts = null): string {
        $s = SC_Settings::all();
        $ts = $ts ?? time();
        $parts = [
            sanitize_title((string) $s['oblik_objekta']) ?: 'webshop',
            sanitize_title((string) $s['adresa']) ?: 'adresa',
            sanitize_title((string) $s['oznaka_objekta']) ?: '1',
            sanitize_title((string) $s['broj_pohrane']) ?: '1',
            wp_date('Ymd_Hi', $ts),
        ];
        return implode('_', $parts) . '.' . $ext;
    }

    /* ---------- Batch state ---------- */

    public static function state(): ?array {
        $s = get_option(self::STATE_OPT, null);
        return is_array($s) ? $s : null;
    }

    public static function start(string $trigger = 'manual'): array {
        self::ensure_dirs();
        $id = wp_generate_password(8, false);
        $state = [
            'id'       => $id,
            'trigger'  => $trigger,
            'started'  => time(),
            'offset'   => 0,
            'rows'     => 0,
            'missing'  => 0,
            'csv_tmp'  => self::tmp_dir() . "cjenik_{$id}.csv",
            'xml_tmp'  => self::tmp_dir() . "cjenik_{$id}.xml",
            'total'    => self::count_products(),
        ];

        $sep = (string) SC_Settings::get('csv_separator') ?: ';';
        $fh = fopen($state['csv_tmp'], 'w');
        fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM za Excel
        fputcsv($fh, self::columns(), $sep, '"', '');
        fclose($fh);

        $s = SC_Settings::all();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<cjenik'
            . ' trgovac="' . self::x($s['naziv_trgovca'] ?: get_bloginfo('name')) . '"'
            . ' oblik_objekta="' . self::x($s['oblik_objekta']) . '"'
            . ' adresa="' . self::x($s['adresa']) . '"'
            . ' oznaka_objekta="' . self::x($s['oznaka_objekta']) . '"'
            . ' broj_pohrane="' . self::x($s['broj_pohrane']) . '"'
            . ' referentni_datum="' . self::x($s['referentni_datum']) . '"'
            . ' generirano="' . self::x(wp_date('c', $state['started'])) . '"'
            . ' valuta="' . self::x(get_woocommerce_currency()) . '"'
            . ">\n";
        file_put_contents($state['xml_tmp'], $xml);

        update_option(self::STATE_OPT, $state, false);
        return $state;
    }

    public static function count_products(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'"
        );
    }

    /** Jedan batch. Vraća ažurirano stanje; 'done' => true kad je gotovo. */
    public static function step(): array {
        $state = self::state();
        if (!$state) {
            $state = self::start('manual');
        }

        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' ORDER BY ID ASC LIMIT %d OFFSET %d",
            self::BATCH,
            (int) $state['offset']
        ));

        if (!$ids) {
            return self::finish($state);
        }

        $sep = (string) SC_Settings::get('csv_separator') ?: ';';
        $csv = fopen($state['csv_tmp'], 'a');
        $xml = fopen($state['xml_tmp'], 'a');
        $settings = SC_Settings::all();

        foreach ($ids as $id) {
            $product = wc_get_product((int) $id);
            if (!$product) {
                continue;
            }
            if (!$settings['ukljuci_skrivene'] && $product->get_catalog_visibility() === 'hidden') {
                continue;
            }
            if (SC_Snapshot::is_excluded_from_pricelist($product)) {
                continue;
            }
            $items = [];
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $vid) {
                    $v = wc_get_product($vid);
                    if ($v && $v->get_status() === 'publish') {
                        $items[] = $v;
                    }
                }
            } elseif ($product->is_type('grouped') || $product->is_type('external')) {
                continue;
            } else {
                $items[] = $product;
            }

            foreach ($items as $item) {
                if (!$settings['ukljuci_nedostupne'] && !$item->is_in_stock()) {
                    continue;
                }
                $row = self::row($item, $settings);
                if ($row['sidrena_cijena'] === '' && !SC_Snapshot::is_excluded($item)) {
                    $state['missing']++;
                }
                fputcsv($csv, array_values($row), $sep, '"', '');
                fwrite($xml, self::xml_row($row));
                $state['rows']++;
            }
        }
        fclose($csv);
        fclose($xml);

        $state['offset'] += count($ids);
        $state['done'] = false;
        update_option(self::STATE_OPT, $state, false);

        // Oslobodi memoriju: WP runtime keš raste sa svakim učitanim proizvodom.
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        } elseif (!wp_using_ext_object_cache()) {
            wp_cache_flush();
        }
        return $state;
    }

    private static function finish(array $state): array {
        file_put_contents($state['xml_tmp'], "</cjenik>\n", FILE_APPEND);

        $ts = time();
        $csv_name = self::build_filename('csv', $ts);
        $xml_name = self::build_filename('xml', $ts);
        // Ako u istoj minuti već postoji datoteka, dodaj sufiks.
        $i = 1;
        while (file_exists(self::files_dir() . $csv_name) || file_exists(self::files_dir() . $xml_name)) {
            $csv_name = preg_replace('/(\.csv)$/', "_{$i}$1", self::build_filename('csv', $ts));
            $xml_name = preg_replace('/(\.xml)$/', "_{$i}$1", self::build_filename('xml', $ts));
            $i++;
        }
        rename($state['csv_tmp'], self::files_dir() . $csv_name);
        rename($state['xml_tmp'], self::files_dir() . $xml_name);

        $last = [
            'csv'     => $csv_name,
            'xml'     => $xml_name,
            'time'    => $ts,
            'rows'    => (int) $state['rows'],
            'missing' => (int) $state['missing'],
            'trigger' => $state['trigger'],
            'seconds' => $ts - (int) $state['started'],
        ];
        update_option(self::LAST_OPT, $last, false);
        delete_option(self::STATE_OPT);

        self::apply_retention();
        self::write_index();

        do_action('sidrena_cijena_export_done', $last);

        return $last + ['done' => true];
    }

    public static function abort(): void {
        $state = self::state();
        if ($state) {
            @unlink($state['csv_tmp']);
            @unlink($state['xml_tmp']);
            delete_option(self::STATE_OPT);
        }
    }

    /** Cijeli export u jednom prolazu (cron / WP-CLI). */
    public static function run_full(string $trigger = 'cron'): array {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        ignore_user_abort(true);
        wp_raise_memory_limit('admin');

        self::abort();
        self::start($trigger);
        do {
            $r = self::step();
        } while (empty($r['done']));
        return $r;
    }

    public static function ajax_batch(): void {
        check_ajax_referer('sc_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Nemate ovlasti.'], 403);
        }
        if (!empty($_POST['restart'])) {
            self::abort();
            self::start('manual');
        }
        $r = self::step();
        wp_send_json_success($r);
    }

    /* ---------- Redak ---------- */

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

    public static function row(WC_Product $item, array $s): array {
        $parent = $item->is_type('variation') ? wc_get_product($item->get_parent_id()) : $item;

        $on_sale = $item->is_on_sale();
        $current = self::fmt_price(wc_get_price_including_tax($item), $s);

        $sidrena = SC_Snapshot::is_excluded($item) ? null : SC_Snapshot::get($item);
        $sidrena_val = $sidrena ? self::fmt_price(wc_get_price_including_tax($item, ['price' => $sidrena['price']]), $s) : '';

        $sku  = (string) $item->get_sku();
        $gtin = method_exists($item, 'get_global_unique_id') ? (string) $item->get_global_unique_id() : '';

        $cats = $parent ? wp_get_post_terms($parent->get_id(), 'product_cat', ['fields' => 'names']) : [];
        if (is_wp_error($cats)) {
            $cats = [];
        }

        return [
            'naziv'                         => wp_strip_all_tags($item->get_name()),
            'sifra'                         => $sku,
            'marka'                         => self::brand($item, $parent, $s),
            'jedinica_mjere'                => $s['jedinica_meta'] ? (string) $item->get_meta($s['jedinica_meta'], true) : '',
            'cijena_za_jedinicu_mjere'      => $s['cijena_jedinica_meta'] ? (string) $item->get_meta($s['cijena_jedinica_meta'], true) : '',
            'maloprodajna_cijena'           => $current,
            'posebni_oblik_prodaje'         => $on_sale ? 'DA' : 'NE',
            'naziv_posebnog_oblika_prodaje' => $on_sale ? (string) $s['naziv_akcije'] : '',
            'sidrena_cijena'                => $sidrena_val,
            'barkod'                        => self::barcode($item, $sku, $gtin, $s),
            'dostupnost'                    => $item->is_in_stock() ? 'dostupno' : 'nedostupno',
            'sidrena_cijena_datum'          => $sidrena ? $sidrena['date'] : '',
            'kategorija'                    => implode(' | ', $cats),
            'url'                           => $item->get_permalink(),
        ];
    }

    private static function brand(WC_Product $item, ?WC_Product $parent, array $s): string {
        $src = (string) $s['marka_izvor'];
        if ($src === 'none' || $src === '') {
            return '';
        }
        $pid = $parent ? $parent->get_id() : $item->get_id();
        if ($src === 'product_brand') {
            $t = taxonomy_exists('product_brand') ? wp_get_post_terms($pid, 'product_brand', ['fields' => 'names']) : [];
            return is_wp_error($t) ? '' : implode(', ', $t);
        }
        if (str_starts_with($src, 'meta:')) {
            $k = substr($src, 5);
            $v = (string) $item->get_meta($k, true);
            if ($v === '' && $parent) {
                $v = (string) $parent->get_meta($k, true);
            }
            return $v;
        }
        if (str_starts_with($src, 'pa_')) {
            $v = $item->get_attribute($src);
            if ($v === '' && $parent) {
                $v = $parent->get_attribute($src);
            }
            return (string) $v;
        }
        return '';
    }

    private static function barcode(WC_Product $item, string $sku, string $gtin, array $s): string {
        $src = (string) $s['barkod_izvor'];
        if (str_starts_with($src, 'meta:')) {
            return (string) $item->get_meta(substr($src, 5), true);
        }
        return match ($src) {
            'gtin'    => $gtin,
            'sku'     => $sku,
            default   => $gtin !== '' ? $gtin : $sku, // gtin_sku
        };
    }

    private static function fmt_price(float $v, array $s): string {
        $str = number_format($v, wc_get_price_decimals(), '.', '');
        if (($s['decimalni_znak'] ?? '.') === ',') {
            $str = str_replace('.', ',', $str);
        }
        return $str;
    }

    private static function x(string $v): string {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function xml_row(array $row): string {
        $out = "  <proizvod>\n";
        foreach ($row as $k => $v) {
            $out .= "    <{$k}>" . self::x((string) $v) . "</{$k}>\n";
        }
        return $out . "  </proizvod>\n";
    }

    /* ---------- Datoteke, retencija, index ---------- */

    /** @return array<int, array{name:string,ext:string,size:int,mtime:int,url:string}> najnovije prvo */
    public static function list_files(): array {
        $dir = self::files_dir();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if (!preg_match('/\.(csv|xml)$/i', $f)) {
                continue;
            }
            $path = $dir . $f;
            $out[] = [
                'name'  => $f,
                'ext'   => strtolower(pathinfo($f, PATHINFO_EXTENSION)),
                'size'  => (int) filesize($path),
                'mtime' => (int) filemtime($path),
                'url'   => self::files_url() . rawurlencode($f),
            ];
        }
        usort($out, static fn($a, $b) => $b['mtime'] <=> $a['mtime'] ?: strcmp($b['name'], $a['name']));
        return $out;
    }

    public static function apply_retention(): void {
        $days = max(31, (int) SC_Settings::get('retencija_dana'));
        $cutoff = time() - $days * DAY_IN_SECONDS;
        foreach (self::list_files() as $f) {
            if ($f['mtime'] < $cutoff) {
                @unlink(self::files_dir() . $f['name']);
            }
        }
    }

    public static function write_index(): void {
        $files = self::list_files();
        $last  = get_option(self::LAST_OPT, []);
        $data  = [
            'trgovac'          => SC_Settings::get('naziv_trgovca') ?: get_bloginfo('name'),
            'referentni_datum' => SC_Settings::get('referentni_datum'),
            'generirano'       => wp_date('c'),
            'najnoviji'        => [
                'csv' => !empty($last['csv']) ? self::files_url() . rawurlencode($last['csv']) : null,
                'xml' => !empty($last['xml']) ? self::files_url() . rawurlencode($last['xml']) : null,
            ],
            'datoteke'         => array_map(static fn($f) => [
                'naziv'   => $f['name'],
                'format'  => $f['ext'],
                'velicina'=> $f['size'],
                'datum'   => wp_date('c', $f['mtime']),
                'url'     => $f['url'],
            ], $files),
        ];
        file_put_contents(self::files_dir() . 'index.json', wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function last(): array {
        $l = get_option(self::LAST_OPT, []);
        return is_array($l) ? $l : [];
    }
}
