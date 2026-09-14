<?php
if (!defined('ABSPATH')) {
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
    public static function is_excluded(WC_Product $product): bool {
        $parent_id = $product->is_type('variation') ? $product->get_parent_id() : 0;
        if ($product->get_meta(self::META_EXCL, true) === 'yes') {
            return true;
        }
        if ($parent_id && get_post_meta($parent_id, self::META_EXCL, true) === 'yes') {
            return true;
        }
        $cat_tt = wp_cache_get('excluded_cat_tt', 'sidrena_cijena');
        if ($cat_tt === false) {
            $cat_tt = SC_Settings::excluded_category_term_taxonomy_ids();
            wp_cache_set('excluded_cat_tt', $cat_tt, 'sidrena_cijena');
        }
        if ($cat_tt) {
            $check = $parent_id ?: $product->get_id();
            $terms = wp_get_object_terms($check, 'product_cat', ['fields' => 'tt_ids']);
            if (!is_wp_error($terms) && array_intersect(array_map('intval', $terms), $cat_tt)) {
                return true;
            }
        }
        return (bool) apply_filters('sidrena_cijena_is_excluded', false, $product);
    }

    /** Je li proizvod izuzet iz cjenika. */
    public static function is_excluded_from_pricelist(WC_Product $product): bool {
        $parent_id = $product->is_type('variation') ? $product->get_parent_id() : 0;
        if ($product->get_meta(self::META_NOCJ, true) === 'yes') {
            return true;
        }
        return $parent_id && get_post_meta($parent_id, self::META_NOCJ, true) === 'yes';
    }

    public static function init(): void {
        add_action('wp_ajax_sc_snapshot_batch', [__CLASS__, 'ajax_batch']);

        // Automatsko bilježenje za nove proizvode (kreirane nakon referentnog datuma).
        add_action('woocommerce_new_product', [__CLASS__, 'maybe_fill_new'], 20);
        add_action('woocommerce_new_product_variation', [__CLASS__, 'maybe_fill_new'], 20);
        add_action('woocommerce_update_product', [__CLASS__, 'maybe_fill_new'], 20);
        add_action('woocommerce_update_product_variation', [__CLASS__, 'maybe_fill_new'], 20);
    }

    /**
     * Vrati sidrenu cijenu proizvoda: ['price' => float, 'date' => 'Y-m-d', 'source' => string] ili null.
     * Ne primjenjuje fallback; to radi SC_Display.
     */
    public static function get(WC_Product $product): ?array {
        $raw = $product->get_meta(self::META_PRICE, true);
        if ($raw === '' || $raw === null) {
            return null;
        }
        $price = (float) wc_format_decimal($raw);
        $date  = (string) $product->get_meta(self::META_DATE, true);
        if ($date === '') {
            $date = (string) SC_Settings::get('referentni_datum');
        }
        return [
            'price'  => $price,
            'date'   => $date,
            'source' => (string) $product->get_meta(self::META_SRC, true),
        ];
    }

    public static function set(int $product_id, string $price, string $date, string $source): void {
        update_post_meta($product_id, self::META_PRICE, wc_format_decimal($price));
        update_post_meta($product_id, self::META_DATE, $date);
        update_post_meta($product_id, self::META_SRC, $source);
    }

    public static function clear(int $product_id): void {
        delete_post_meta($product_id, self::META_PRICE);
        delete_post_meta($product_id, self::META_DATE);
        delete_post_meta($product_id, self::META_SRC);
    }

    /** Referentni datum za proizvod (uzima u obzir kategorije s datumom 2. 5. 2025.). */
    public static function reference_date_for(int $product_id, ?array $alt_tt_ids = null): string {
        $alt_tt_ids = $alt_tt_ids ?? SC_Settings::alt_category_term_taxonomy_ids();
        if ($alt_tt_ids) {
            $post = get_post($product_id);
            $check_id = ($post && $post->post_type === 'product_variation' && $post->post_parent) ? (int) $post->post_parent : $product_id;
            global $wpdb;
            $in = implode(',', $alt_tt_ids);
            $hit = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id=%d AND term_taxonomy_id IN ($in)",
                $check_id
            ));
            if ((int) $hit > 0) {
                return (string) SC_Settings::get('alt_datum');
            }
        }
        return (string) SC_Settings::get('referentni_datum');
    }

    /**
     * Jedan batch snapshot-a: kopira _regular_price u _sidrena_cijena.
     * @return array{done:bool,last_id:int,written:int,skipped:int}
     */
    public static function run_batch(int $last_id, int $limit, bool $overwrite): array {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type IN ('product','product_variation')
               AND post_status NOT IN ('trash','auto-draft')
               AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            $last_id,
            $limit
        ));

        if (!$ids) {
            return ['done' => true, 'last_id' => $last_id, 'written' => 0, 'skipped' => 0];
        }

        $ids = array_map('intval', $ids);
        $in  = implode(',', $ids);

        $regular = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key='_regular_price' AND meta_value<>'' AND post_id IN ($in)",
            ARRAY_A
        );

        $existing = [];
        if (!$overwrite) {
            $existing = array_flip(array_map('intval', $wpdb->get_col(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key='" . self::META_PRICE . "' AND meta_value<>'' AND post_id IN ($in)"
            )));
        }

        // Alternativni datum po kategoriji.
        $alt_tt  = SC_Settings::alt_category_term_taxonomy_ids();
        $alt_ids = [];
        if ($alt_tt) {
            $parents = $wpdb->get_results("SELECT ID, post_parent FROM {$wpdb->posts} WHERE ID IN ($in)", ARRAY_A);
            $parent_map = [];
            $check_ids  = [];
            foreach ($parents as $row) {
                $pid = (int) $row['ID'];
                $chk = (int) $row['post_parent'] ?: $pid;
                $parent_map[$pid] = $chk;
                $check_ids[$chk]  = true;
            }
            $cin = implode(',', array_keys($check_ids));
            $tin = implode(',', $alt_tt);
            $in_alt = array_flip(array_map('intval', $wpdb->get_col(
                "SELECT DISTINCT object_id FROM {$wpdb->term_relationships} WHERE object_id IN ($cin) AND term_taxonomy_id IN ($tin)"
            )));
            foreach ($parent_map as $pid => $chk) {
                if (isset($in_alt[$chk])) {
                    $alt_ids[$pid] = true;
                }
            }
        }

        $ref_date = (string) SC_Settings::get('referentni_datum');
        $alt_date = (string) SC_Settings::get('alt_datum');

        $written = 0;
        $skipped = 0;
        foreach ($regular as $row) {
            $pid = (int) $row['post_id'];
            if (isset($existing[$pid])) {
                $skipped++;
                continue;
            }
            $date = isset($alt_ids[$pid]) ? $alt_date : $ref_date;
            self::set($pid, (string) $row['meta_value'], $date, 'snapshot');
            $written++;
        }

        return [
            'done'    => count($ids) < $limit,
            'last_id' => (int) end($ids),
            'written' => $written,
            'skipped' => $skipped,
        ];
    }

    /** Cijeli snapshot u jednom prolazu (WP-CLI / cron). */
    public static function run_full(bool $overwrite = false, int $batch = 1000): array {
        $last = 0;
        $tot  = ['written' => 0, 'skipped' => 0];
        do {
            $r = self::run_batch($last, $batch, $overwrite);
            $last = $r['last_id'];
            $tot['written'] += $r['written'];
            $tot['skipped'] += $r['skipped'];
        } while (!$r['done']);
        wp_cache_flush();
        return $tot;
    }

    public static function ajax_batch(): void {
        check_ajax_referer('sc_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Nemate ovlasti.'], 403);
        }
        $last_id   = isset($_POST['last_id']) ? (int) $_POST['last_id'] : 0;
        $overwrite = !empty($_POST['overwrite']);
        $r = self::run_batch($last_id, 1000, $overwrite);
        if ($r['done']) {
            wp_cache_flush();
        }
        wp_send_json_success($r);
    }

    /** Novi proizvod bez sidrene cijene: prva redovna cijena postaje sidrena, datum = datum kreiranja. */
    public static function maybe_fill_new($product_id): void {
        if (!SC_Settings::get('auto_novi')) {
            return;
        }
        $product = wc_get_product($product_id);
        if (!$product || $product->get_meta(self::META_PRICE, true) !== '') {
            return;
        }
        if ($product->is_type('variable') || $product->is_type('grouped')) {
            return; // roditelji nemaju vlastitu cijenu
        }
        $regular = $product->get_regular_price('edit');
        if ($regular === '' || $regular === null) {
            return;
        }
        $created = $product->get_date_created();
        $created_ymd = $created ? $created->date('Y-m-d') : wp_date('Y-m-d');
        $ref = self::reference_date_for($product_id);
        if ($created_ymd <= $ref) {
            return; // stari proizvod, čeka snapshot ili ručni unos
        }
        self::set($product_id, (string) $regular, $created_ymd, 'novi');
    }

    /** Statistika za admin (izuzeti proizvodi se ne broje). */
    public static function stats(): array {
        global $wpdb;
        $cat_tt = SC_Settings::excluded_category_term_taxonomy_ids();
        $cat_sql = '';
        if ($cat_tt) {
            $in = implode(',', $cat_tt);
            $cat_sql = "AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr WHERE tr.object_id = IF(p.post_type='product_variation', p.post_parent, p.ID) AND tr.term_taxonomy_id IN ($in))";
        }
        $row = $wpdb->get_row(
            "SELECT COUNT(*) AS total, SUM(sc.post_id IS NOT NULL) AS with_sidrena
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} rp ON rp.post_id=p.ID AND rp.meta_key='_regular_price' AND rp.meta_value<>''
             LEFT JOIN {$wpdb->postmeta} sc ON sc.post_id=p.ID AND sc.meta_key='" . self::META_PRICE . "' AND sc.meta_value<>''
             LEFT JOIN {$wpdb->postmeta} ex ON ex.post_id=p.ID AND ex.meta_key='" . self::META_EXCL . "' AND ex.meta_value='yes'
             LEFT JOIN {$wpdb->postmeta} exp ON exp.post_id=p.post_parent AND exp.meta_key='" . self::META_EXCL . "' AND exp.meta_value='yes'
             WHERE p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private')
               AND ex.post_id IS NULL AND exp.post_id IS NULL $cat_sql",
            ARRAY_A
        );
        $excluded = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} ex ON ex.post_id=p.ID AND ex.meta_key='" . self::META_EXCL . "' AND ex.meta_value='yes'
             WHERE p.post_type='product' AND p.post_status IN ('publish','private')"
        );
        return [
            'total'    => (int) ($row['total'] ?? 0),
            'with'     => (int) ($row['with_sidrena'] ?? 0),
            'without'  => (int) ($row['total'] ?? 0) - (int) ($row['with_sidrena'] ?? 0),
            'excluded' => $excluded,
        ];
    }
}
