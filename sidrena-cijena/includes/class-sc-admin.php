<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin stranica: WooCommerce → Sidrena cijena
 */
final class SC_Admin {
    private const PAGE = 'sidrena-cijena';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_sc_save_settings', [__CLASS__, 'save_settings']);
        add_action('admin_post_sc_import_csv', [__CLASS__, 'import_csv']);
        add_action('admin_post_sc_download_working_csv', [__CLASS__, 'download_working_csv']);
        add_action('admin_post_sc_delete_file', static function (): void {
            $file = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';
            check_admin_referer('sc_delete_file_' . $file);
            if (!current_user_can('manage_woocommerce')) {
                wp_die('Nemate ovlasti.');
            }
            $ok = $file !== '' && SC_Export::delete_file($file);
            self::redirect('cjenici', $ok ? 'Datoteka obrisana: ' . $file : 'Datoteka nije pronađena.', !$ok);
        });
        add_action('admin_post_sc_regen_token', static function (): void {
            check_admin_referer('sc_regen_token');
            if (!current_user_can('manage_woocommerce')) {
                wp_die('Nemate ovlasti.');
            }
            SC_Settings::update(['cron_token' => wp_generate_password(32, false, false)]);
            self::redirect('status', 'Novi token generiran.');
        });
        add_action('admin_notices', [__CLASS__, 'notices']);
        add_filter('plugin_action_links_' . plugin_basename(SC_FILE), static function (array $links): array {
            array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">Postavke</a>');
            return $links;
        });
    }

    public static function url(string $tab = 'status', array $extra = []): string {
        return add_query_arg(array_merge(['page' => self::PAGE, 'tab' => $tab], $extra), admin_url('admin.php'));
    }

    public static function menu(): void {
        add_submenu_page('woocommerce', 'Sidrena cijena', 'Sidrena cijena', 'manage_woocommerce', self::PAGE, [__CLASS__, 'render']);
    }

    public static function assets(string $hook): void {
        if (!str_contains($hook, self::PAGE)) {
            return;
        }
        wp_enqueue_script('sc-admin', SC_URL . 'assets/admin.js', ['jquery'], SC_VERSION, true);
        wp_localize_script('sc-admin', 'scAdmin', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sc_admin'),
        ]);
        wp_add_inline_style('wp-admin', '.sc-card{background:#fff;border:1px solid #c3c4c7;padding:16px 20px;margin:16px 0;max-width:960px}.sc-progress{height:18px;background:#e5e5e5;border-radius:3px;overflow:hidden;margin:8px 0}.sc-progress span{display:block;height:100%;background:#2271b1;width:0;transition:width .2s}.sc-stat{display:inline-block;margin-right:24px}.sc-stat b{font-size:22px;display:block}.sc-warn{color:#b32d2e}.sc-ok{color:#00a32a}');
    }

    public static function notices(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['edit-product', 'woocommerce_page_' . self::PAGE, 'dashboard'], true)) {
            return;
        }
        if (SC_Export::is_stale() && SC_Export::last()) {
            printf(
                '<div class="notice notice-error"><p><strong>Sidrena cijena:</strong> zadnji cjenik generiran je %s, dakle prije više od 24 sata. Provjeri vanjski cron okidač. <a href="%s">Detalji</a></p></div>',
                esc_html(wp_date('d.m.Y. H:i', (int) SC_Export::last()['time'])),
                esc_url(self::url('status'))
            );
        }
        $st = SC_Snapshot::stats();
        if ($st['without'] > 0) {
            printf(
                '<div class="notice notice-warning"><p><strong>Sidrena cijena:</strong> %d proizvoda/varijacija nema zabilježenu sidrenu cijenu. <a href="%s">Zabilježi sidrene cijene</a></p></div>',
                $st['without'],
                esc_url(self::url('status'))
            );
        }
    }

    /* ---------- Render ---------- */

    public static function render(): void {
        $tab = sanitize_key($_GET['tab'] ?? 'status');
        $tabs = ['status' => 'Status i akcije', 'postavke' => 'Postavke', 'cjenici' => 'Cjenici', 'uvoz' => 'Uvoz / izvoz sidrenih cijena'];
        echo '<div class="wrap"><h1>Sidrena cijena</h1>';
        if (!empty($_GET['sc_msg'])) {
            $type = !empty($_GET['sc_err']) ? 'error' : 'success';
            echo '<div class="notice notice-' . $type . ' is-dismissible"><p>' . esc_html(wp_unslash($_GET['sc_msg'])) . '</p></div>';
        }
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $k => $label) {
            printf('<a class="nav-tab %s" href="%s">%s</a>', $tab === $k ? 'nav-tab-active' : '', esc_url(self::url($k)), esc_html($label));
        }
        echo '</nav>';
        match ($tab) {
            'postavke' => self::tab_settings(),
            'cjenici'  => self::tab_files(),
            'uvoz'     => self::tab_import(),
            default    => self::tab_status(),
        };
        echo '</div>';
    }

    private static function tab_status(): void {
        $st   = SC_Snapshot::stats();
        $last = SC_Export::last();
        $next = wp_next_scheduled(SC_Export::CRON_HOOK);
        $s    = SC_Settings::all();
        ?>
        <div class="sc-card">
            <h2>Stanje</h2>
            <div class="sc-stat"><b><?php echo (int) $st['total']; ?></b>proizvoda/varijacija s cijenom</div>
            <div class="sc-stat"><b class="sc-ok"><?php echo (int) $st['with']; ?></b>sa sidrenom cijenom</div>
            <div class="sc-stat"><b class="<?php echo $st['without'] ? 'sc-warn' : 'sc-ok'; ?>"><?php echo (int) $st['without']; ?></b>bez sidrene cijene</div>
            <div class="sc-stat"><b><?php echo (int) $st['excluded']; ?></b>izuzeto na proizvodu</div>
            <p>Referentni datum: <strong><?php echo esc_html(SC_Settings::format_date($s['referentni_datum'])); ?></strong>
            <?php if (!empty($s['alt_kategorije'])) : ?> (kategorije NN 75/2025: <?php echo esc_html(SC_Settings::format_date($s['alt_datum'])); ?>)<?php endif; ?>
            · Obveza isticanja i objave cjenika vrijedi od <strong>1. 10. 2026.</strong></p>
            <?php $active = SC_Settings::display_active(); ?>
            <p>Prikaz sidrene cijene na webshopu: <?php
                if ($s['prikaz_mod'] === 'on') {
                    echo '<strong class="sc-ok">uključen</strong>';
                } elseif ($s['prikaz_mod'] === 'off') {
                    echo '<strong class="sc-warn">isključen</strong>';
                } else {
                    echo $active
                        ? '<strong class="sc-ok">uključen</strong> (automatski od ' . esc_html(SC_Settings::format_date($s['prikaz_od'])) . ')'
                        : '<strong class="sc-warn">isključen</strong>, automatski se uključuje <strong>' . esc_html(SC_Settings::format_date($s['prikaz_od'])) . '</strong>';
                }
            ?> · <a href="<?php echo esc_url(self::url('postavke')); ?>">promijeni</a></p>
        </div>

        <div class="sc-card">
            <h2>1. Zabilježi sidrene cijene</h2>
            <p>Kopira <strong>redovnu cijenu</strong> (bez akcije) svakog proizvoda i varijacije u polje sidrene cijene. Pokreni <strong>odmah</strong>, dok su cijene još one koje su vrijedile na referentni dan. Proizvodi koji već imaju sidrenu cijenu se preskaču, osim ako označiš prepisivanje.</p>
            <p><label><input type="checkbox" id="sc-overwrite"> Prepiši i postojeće sidrene cijene (oprez: briše ručne unose)</label></p>
            <?php if ($init = wp_next_scheduled(SC_Export::INITIAL_HOOK)) : ?>
                <p class="sc-warn">Automatski snapshot sidrenih cijena zakazan je za <?php echo esc_html(wp_date('d.m.Y. H:i', $init)); ?> (pokreće ga prvi zahtjev nakon tog vremena; samo kopira redovne cijene, ne generira cjenik). Možeš i odmah ručno:</p>
            <?php endif; ?>
            <p><button class="button button-primary" id="sc-snapshot-btn">Zabilježi sidrene cijene</button></p>
            <div class="sc-progress" id="sc-snapshot-progress" hidden><span></span></div>
            <p id="sc-snapshot-log"></p>
        </div>

        <div class="sc-card">
            <h2>2. Cjenik (.csv / .xml)</h2>
            <?php if ($last) : ?>
                <p>Zadnji cjenik: <strong><?php echo esc_html(wp_date('d.m.Y. H:i', (int) $last['time'])); ?></strong>
                (<?php echo (int) $last['rows']; ?> redaka<?php if ($last['missing']) : ?>, <span class="sc-warn"><?php echo (int) $last['missing']; ?> bez sidrene cijene</span><?php endif; ?>, <?php echo (int) $last['seconds']; ?> s, pokrenuo: <?php echo esc_html($last['trigger']); ?>)
                · <a href="<?php echo esc_url(SC_Export::files_url() . rawurlencode($last['csv'])); ?>">CSV</a> · <a href="<?php echo esc_url(SC_Export::files_url() . rawurlencode($last['xml'])); ?>">XML</a></p>
            <?php else : ?>
                <p class="sc-warn">Cjenik još nije generiran.</p>
            <?php endif; ?>
            <p>Automatsko generiranje (WP-Cron): svaki dan u <strong><?php echo esc_html($s['cron_vrijeme']); ?></strong>
            <?php echo $next ? '(sljedeće: ' . esc_html(wp_date('d.m.Y. H:i', $next)) . ')' : '<span class="sc-warn">(cron nije zakazan, spremi postavke)</span>'; ?>.
            Datoteke se čuvaju <?php echo (int) $s['retencija_dana']; ?> dana.</p>
            <p><button class="button button-primary" id="sc-export-btn">Generiraj cjenik sada</button>
            <a class="button" href="<?php echo esc_url(SC_Public::url()); ?>" target="_blank">Otvori javnu stranicu cjenika</a></p>
            <div class="sc-progress" id="sc-export-progress" hidden><span></span></div>
            <p id="sc-export-log"></p>
            <p><small>Javni linkovi: <code><?php echo esc_html(SC_Public::url()); ?></code> · <code><?php echo esc_html(SC_Public::url('latest.csv')); ?></code> · <code><?php echo esc_html(SC_Public::url('latest.xml')); ?></code> · <code><?php echo esc_html(SC_Public::url('index.json')); ?></code></small></p>
        </div>

        <div class="sc-card">
            <h2>3. Pouzdano dnevno generiranje bez posjeta kupca</h2>
            <?php $trigger = add_query_arg('sidrena_cron', SC_Settings::cron_token(), home_url('/')); ?>
            <p>Plugin ima tri mehanizma, redom:</p>
            <ol>
                <li><strong>Vanjski okidač (preporučeno):</strong> na serveru (cPanel/Plesk „Cron Jobs“) ili na besplatnom servisu poput cron-job.org zakaži svaki dan u <?php echo esc_html($s['cron_vrijeme']); ?> poziv adrese:<br>
                    <code style="user-select:all"><?php echo esc_html($trigger); ?></code><br>
                    Primjer za cPanel/Linux cron (svaki dan u <?php echo esc_html($s['cron_vrijeme']); ?>):<br>
                    <code style="user-select:all"><?php list($h, $m) = explode(':', $s['cron_vrijeme']); echo esc_html(sprintf('%d %d * * * curl -s "%s" > /dev/null', (int) $m, (int) $h, $trigger)); ?></code><br>
                    <small>Radi neovisno o WP-Cronu i posjetima. Dodaj <code>&amp;only_due=1</code> ako ga zoveš češće (npr. svakih 15 min), tada generira samo ako današnji cjenik još ne postoji.</small></li>
                <li><strong>WP-Cron:</strong> zakazan za <?php echo esc_html($s['cron_vrijeme']); ?>, pokreće ga prvi posjet nakon tog vremena.</li>
                <li><strong>Rezerva:</strong> ako je vrijeme prošlo, a današnji cjenik ne postoji, generira se u pozadini na kraju prvog sljedećeg zahtjeva, i kad WP-Cron loopback ne radi (neki hostinzi ga blokiraju).</li>
            </ol>
            <p><small>Alternativa za WP-CLI: <code>wp sidrena export</code>. <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sc_regen_token'), 'sc_regen_token')); ?>" onclick="return confirm('Stari URL okidača prestaje raditi. Nastaviti?')">Generiraj novi token</a> ako je URL procurio.</small></p>
        </div>
        <?php
    }

    private static function tab_settings(): void {
        $s = SC_Settings::all();
        $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        $attrs = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : [];
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('sc_save_settings'); ?>
            <input type="hidden" name="action" value="sc_save_settings">

            <div class="sc-card">
                <h2>Isticanje sidrene cijene</h2>
                <table class="form-table">
                    <tr><th>Prikaz na webshopu</th>
                        <td><label><input type="radio" name="prikaz_mod" value="datum" <?php checked($s['prikaz_mod'], 'datum'); ?>> Automatski uključi od datuma
                            <input type="date" name="prikaz_od" value="<?php echo esc_attr($s['prikaz_od']); ?>"></label><br>
                            <label><input type="radio" name="prikaz_mod" value="on" <?php checked($s['prikaz_mod'], 'on'); ?>> Uključen odmah</label><br>
                            <label><input type="radio" name="prikaz_mod" value="off" <?php checked($s['prikaz_mod'], 'off'); ?>> Isključen</label>
                            <p class="description">Dok je prikaz isključen, plugin i dalje bilježi sidrene cijene, generira cjenik i čuva podatke. Obveza isticanja počinje 1. 10. 2026.</p></td></tr>
                    <tr><th><label for="referentni_datum">Referentni datum</label></th>
                        <td><input type="date" name="referentni_datum" id="referentni_datum" value="<?php echo esc_attr($s['referentni_datum']); ?>">
                        <p class="description">Odluka NN 101/2026: 10. 9. 2026.</p></td></tr>
                    <tr><th><label for="label">Tekst oznake</label></th>
                        <td><input type="text" class="regular-text" name="label" id="label" value="<?php echo esc_attr($s['label']); ?>">
                        <p class="description">Dostupno: <code>{datum}</code> (10. 9. 2026.), <code>{datum_iso}</code> (2026-09-10), <code>{cijena}</code>. Primjer prikaza: <?php echo wp_kses_post(strtr($s['label'], ['{datum}' => SC_Settings::format_date($s['referentni_datum']), '{datum_iso}' => $s['referentni_datum'], '{cijena}' => wc_price(19.9)])); ?></p></td></tr>
                    <tr><th><label for="label_lang">Tekst oznake po jeziku</label></th>
                        <td><textarea name="label_lang" id="label_lang" rows="4" class="large-text code"><?php echo esc_textarea($s['label_lang']); ?></textarea>
                        <p class="description">Jedan jezik po retku, oblik <code>en: Anchor price ({datum}): {cijena}</code>. Jezik se prepoznaje iz Polylanga, WPML-a, TranslatePressa, parametra <code>?lang=</code> ili WordPress locale-a (trenutno: <code><?php echo esc_html(SC_Settings::current_language()); ?></code> u adminu). Za jezike koji nisu navedeni koristi se engleski, a ako ni njega nema, hrvatski tekst. <code>{datum}</code> se za nehrvatske jezike formatira prema WordPress formatu datuma.</p></td></tr>
                    <tr><th>Košarica</th>
                        <td><label><input type="checkbox" name="prikaz_kosarica" value="1" <?php checked($s['prikaz_kosarica']); ?>> Prikaži sidrenu cijenu i uz stavke u košarici / mini-košarici</label></td></tr>
                    <tr><th>Proizvod bez zabilježene sidrene cijene</th>
                        <td><label><input type="radio" name="prikaz_bez_sidrene" value="nista" <?php checked($s['prikaz_bez_sidrene'], 'nista'); ?>> Ne prikazuj ništa (preporučeno; upozorenje u adminu)</label><br>
                            <label><input type="radio" name="prikaz_bez_sidrene" value="redovna" <?php checked($s['prikaz_bez_sidrene'], 'redovna'); ?>> Prikaži trenutnu redovnu cijenu kao sidrenu</label></td></tr>
                    <tr><th>Novi proizvodi</th>
                        <td><label><input type="checkbox" name="auto_novi" value="1" <?php checked($s['auto_novi']); ?>> Za proizvode kreirane nakon referentnog datuma automatski zabilježi prvu redovnu cijenu kao sidrenu (datum = datum kreiranja)</label></td></tr>
                    <tr><th><label for="izuzete_kategorije">Kategorije izuzete iz isticanja</label></th>
                        <td><select name="izuzete_kategorije[]" id="izuzete_kategorije" multiple size="6" style="min-width:300px">
                            <?php foreach ((array) $cats as $c) : ?>
                                <option value="<?php echo (int) $c->term_id; ?>" <?php selected(in_array($c->term_id, (array) $s['izuzete_kategorije'])); ?>><?php echo esc_html($c->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Npr. Preorder: proizvodi koji nisu bili u ponudi na referentni dan. Za njih se sidrena cijena ne prikazuje, a u cjeniku je polje prazno. Pojedinačni proizvodi se izuzimaju kvačicom na samom proizvodu ili masovnim uređivanjem u popisu proizvoda. Ctrl/Cmd+klik za odabir više.</p></td></tr>
                    <tr><th><label for="alt_kategorije">Kategorije iz Odluke NN 75/2025</label></th>
                        <td><select name="alt_kategorije[]" id="alt_kategorije" multiple size="6" style="min-width:300px">
                            <?php foreach ((array) $cats as $c) : ?>
                                <option value="<?php echo (int) $c->term_id; ?>" <?php selected(in_array($c->term_id, (array) $s['alt_kategorije'])); ?>><?php echo esc_html($c->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Hrana, piće, kozmetika, sredstva za čišćenje, toaletne potrepštine, proizvodi za kućanstvo: za njih ostaje referentni datum
                        <input type="date" name="alt_datum" value="<?php echo esc_attr($s['alt_datum']); ?>">. Za trgovinu pločama i CD-ima ostavi prazno.</p></td></tr>
                </table>
            </div>

            <div class="sc-card">
                <h2>Cjenik: podaci o prodajnom objektu (naziv datoteke)</h2>
                <p class="description">Odluka, točka VI.: naziv datoteke sadrži oblik prodajnog objekta, adresu, oznaku objekta, broj pohrane i vremensku oznaku. Rezultat: <code><?php echo esc_html(SC_Export::build_filename('csv')); ?></code></p>
                <table class="form-table">
                    <tr><th><label>Naziv trgovca</label></th><td><input type="text" class="regular-text" name="naziv_trgovca" value="<?php echo esc_attr($s['naziv_trgovca']); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></td></tr>
                    <tr><th><label>Oblik prodajnog objekta</label></th><td><input type="text" name="oblik_objekta" value="<?php echo esc_attr($s['oblik_objekta']); ?>"> <span class="description">npr. webshop, internetska-trgovina</span></td></tr>
                    <tr><th><label>Adresa</label></th><td><input type="text" class="regular-text" name="adresa" value="<?php echo esc_attr($s['adresa']); ?>" placeholder="Ulica 1, Zagreb ili www.domena.hr"></td></tr>
                    <tr><th><label>Oznaka objekta</label></th><td><input type="text" name="oznaka_objekta" value="<?php echo esc_attr($s['oznaka_objekta']); ?>"></td></tr>
                    <tr><th><label>Broj pohrane</label></th><td><input type="text" name="broj_pohrane" value="<?php echo esc_attr($s['broj_pohrane']); ?>"></td></tr>
                </table>
            </div>

            <div class="sc-card">
                <h2>Cjenik: sadržaj i format</h2>
                <table class="form-table">
                    <tr><th><label>Izvor podatka „marka“</label></th>
                        <td><select name="marka_izvor">
                            <option value="none" <?php selected($s['marka_izvor'], 'none'); ?>>— prazno —</option>
                            <?php if (taxonomy_exists('product_brand')) : ?><option value="product_brand" <?php selected($s['marka_izvor'], 'product_brand'); ?>>Taksonomija „Brands“ (WooCommerce)</option><?php endif; ?>
                            <?php foreach ((array) $attrs as $a) : $tax = 'pa_' . $a->attribute_name; ?>
                                <option value="<?php echo esc_attr($tax); ?>" <?php selected($s['marka_izvor'], $tax); ?>>Atribut: <?php echo esc_html($a->attribute_label); ?></option>
                            <?php endforeach; ?>
                            <?php if (str_starts_with((string) $s['marka_izvor'], 'meta:')) : ?><option value="<?php echo esc_attr($s['marka_izvor']); ?>" selected>Meta polje: <?php echo esc_html(substr($s['marka_izvor'], 5)); ?></option><?php endif; ?>
                        </select>
                        <input type="text" name="marka_meta" placeholder="ili meta ključ, npr. _izdavac" value="">
                        <p class="description">Za ploče i CD-e: izdavač (label) ili izvođač, ovisno o strukturi shopa.</p></td></tr>
                    <tr><th><label>Izvor podatka „barkod“</label></th>
                        <td><select name="barkod_izvor">
                            <option value="gtin_sku" <?php selected($s['barkod_izvor'], 'gtin_sku'); ?>>GTIN/EAN polje, ako je prazno onda SKU</option>
                            <option value="gtin" <?php selected($s['barkod_izvor'], 'gtin'); ?>>Samo GTIN/EAN polje (WooCommerce)</option>
                            <option value="sku" <?php selected($s['barkod_izvor'], 'sku'); ?>>Samo SKU</option>
                            <?php if (str_starts_with((string) $s['barkod_izvor'], 'meta:')) : ?><option value="<?php echo esc_attr($s['barkod_izvor']); ?>" selected>Meta polje: <?php echo esc_html(substr($s['barkod_izvor'], 5)); ?></option><?php endif; ?>
                        </select>
                        <input type="text" name="barkod_meta" placeholder="ili meta ključ, npr. _ean" value=""></td></tr>
                    <tr><th><label>Jedinica mjere (meta ključ)</label></th><td><input type="text" name="jedinica_meta" value="<?php echo esc_attr($s['jedinica_meta']); ?>"> <input type="text" name="cijena_jedinica_meta" value="<?php echo esc_attr($s['cijena_jedinica_meta']); ?>" placeholder="meta ključ cijene za jedinicu"> <span class="description">„ako je primjenjivo“; za ploče/CD-e ostavi prazno</span></td></tr>
                    <tr><th><label>Naziv posebnog oblika prodaje</label></th><td><input type="text" name="naziv_akcije" value="<?php echo esc_attr($s['naziv_akcije']); ?>"> <span class="description">upisuje se kad je proizvod na akciji</span></td></tr>
                    <tr><th>Uključi u cjenik</th>
                        <td><label><input type="checkbox" name="ukljuci_nedostupne" value="1" <?php checked($s['ukljuci_nedostupne']); ?>> proizvode koji nisu na zalihi (označeni „nedostupno“)</label><br>
                            <label><input type="checkbox" name="ukljuci_skrivene" value="1" <?php checked($s['ukljuci_skrivene']); ?>> proizvode skrivene iz kataloga</label></td></tr>
                    <tr><th><label>CSV separator</label></th><td><select name="csv_separator"><option value=";" <?php selected($s['csv_separator'], ';'); ?>>; (točka-zarez)</option><option value="," <?php selected($s['csv_separator'], ','); ?>>, (zarez)</option></select></td></tr>
                    <tr><th><label>Decimalni znak u cjeniku</label></th><td><select name="decimalni_znak"><option value="." <?php selected($s['decimalni_znak'], '.'); ?>>. (točka)</option><option value="," <?php selected($s['decimalni_znak'], ','); ?>>, (zarez)</option></select></td></tr>
                </table>
            </div>

            <div class="sc-card">
                <h2>Cjenik: objava i raspored</h2>
                <table class="form-table">
                    <tr><th><label>Javna adresa cjenika</label></th><td><?php echo esc_html(home_url('/')); ?><input type="text" name="javni_slug" value="<?php echo esc_attr($s['javni_slug']); ?>">/</td></tr>
                    <tr><th><label>Vrijeme dnevnog generiranja</label></th><td><input type="time" name="cron_vrijeme" value="<?php echo esc_attr($s['cron_vrijeme']); ?>"> <span class="description">Odluka: cjenik ažuriran najkasnije do 8:00 za tekući dan</span></td></tr>
                    <tr><th><label>Čuvanje datoteka (dana)</label></th><td><input type="number" min="31" name="retencija_dana" value="<?php echo (int) $s['retencija_dana']; ?>"> <span class="description">Odluka: najmanje 30 dana od objave</span></td></tr>
                </table>
            </div>

            <?php submit_button('Spremi postavke'); ?>
        </form>
        <?php
    }

    private static function tab_files(): void {
        $files = SC_Export::list_files();
        echo '<div class="sc-card"><h2>Objavljene datoteke</h2>';
        echo '<p>Javna stranica: <a href="' . esc_url(SC_Public::url()) . '" target="_blank">' . esc_html(SC_Public::url()) . '</a></p>';
        if (!$files) {
            echo '<p>Nema datoteka.</p></div>';
            return;
        }
        echo '<p class="description">Odluka traži da svaki objavljeni cjenik ostane dostupan 30 dana. Briši samo duplikate nastale višestrukim ručnim generiranjem istog dana.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Datoteka</th><th>Objavljeno</th><th>Veličina</th><th></th></tr></thead><tbody>';
        foreach ($files as $f) {
            $del = wp_nonce_url(admin_url('admin-post.php?action=sc_delete_file&file=' . rawurlencode($f['name'])), 'sc_delete_file_' . $f['name']);
            printf('<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td><a href="%s" class="submitdelete" onclick="return confirm(\'Obrisati datoteku %s?\')">Obriši</a></td></tr>', esc_url($f['url']), esc_html($f['name']), esc_html(wp_date('d.m.Y. H:i', $f['mtime'])), esc_html(size_format($f['size'])), esc_url($del), esc_js($f['name']));
        }
        echo '</tbody></table></div>';
    }

    private static function tab_import(): void {
        $nf = get_transient('sc_import_not_found');
        if (is_array($nf) && $nf) {
            echo '<div class="notice notice-warning"><p><strong>Nisu pronađeni u webshopu (prvih ' . count($nf) . '):</strong> ' . esc_html(implode(', ', $nf)) . '</p></div>';
            delete_transient('sc_import_not_found');
        }
        ?>
        <div class="sc-card">
            <h2>Radni CSV sidrenih cijena</h2>
            <p>Preuzmi popis svih proizvoda s redovnom i sidrenom cijenom, uredi u Excelu i vrati uvozom. Korisno kad su cijene mijenjane nakon referentnog datuma pa sidrene treba unijeti iz vlastite evidencije.</p>
            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sc_download_working_csv'), 'sc_download')); ?>">Preuzmi radni CSV</a></p>
        </div>
        <div class="sc-card">
            <h2>Uvoz sidrenih cijena iz CSV-a</h2>
            <p>Uvoz mijenja <strong>samo polje sidrene cijene</strong> (i njezin datum). Redovna i akcijska cijena, nazivi, zalihe i sve ostalo ostaju netaknuti. Proizvodi koji ne postoje u webshopu se preskaču i ne kreiraju se.</p>
            <p>Format: prvi redak zaglavlje, separator ; ili , (automatski prepoznat), decimalni zarez ili točka.<br>
            Stupac za prepoznavanje proizvoda: <code>sku</code> / <code>sifra</code> / <code>ean</code> / <code>barkod</code> / <code>gtin</code> (traži po SKU-u pa po GTIN/EAN polju) ili <code>id</code>.<br>
            Stupac s cijenom: <code>sidrena_cijena</code> / <code>sidrena</code> / <code>cijena</code>. Opcionalno <code>datum</code> (GGGG-MM-DD).<br>
            Ostali stupci u datoteci se ignoriraju, pa se može uvesti i radni CSV odozgo ili izvoz iz ERP-a.</p>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sc_import_csv'); ?>
                <input type="hidden" name="action" value="sc_import_csv">
                <p><input type="file" name="csv" accept=".csv,text/csv" required></p>
                <p><label><input type="checkbox" name="samo_objavljeni" value="1" checked> Ažuriraj samo objavljene proizvode (preskoči skice i privatne)</label><br>
                   <label><input type="checkbox" name="prazno_brise" value="1"> Prazna vrijednost sidrene cijene briše postojeći zapis (inače se takvi redci preskaču)</label></p>
                <?php submit_button('Uvezi', 'primary', 'submit', false); ?>
            </form>
        </div>
        <div class="sc-card">
            <h2>WooCommerce uvoz/izvoz</h2>
            <p>Ugrađeni WooCommerce CSV izvoz/uvoz proizvoda također prenosi ova polja kao <code>Meta: _sidrena_cijena</code>, <code>Meta: _sidrena_cijena_datum</code> i <code>Meta: _sidrena_cijena_izvor</code>.</p>
        </div>
        <?php
    }

    /* ---------- Handleri ---------- */

    private static function redirect(string $tab, string $msg, bool $err = false): void {
        wp_safe_redirect(self::url($tab, ['sc_msg' => $msg, 'sc_err' => $err ? 1 : 0]));
        exit;
    }

    public static function save_settings(): void {
        check_admin_referer('sc_save_settings');
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Nemate ovlasti.');
        }
        $p = wp_unslash($_POST);
        $date = static fn($v, $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : $d;

        $marka = sanitize_text_field($p['marka_izvor'] ?? 'none');
        if (!empty($p['marka_meta'])) {
            $marka = 'meta:' . sanitize_key($p['marka_meta']);
        }
        $barkod = sanitize_text_field($p['barkod_izvor'] ?? 'gtin_sku');
        if (!empty($p['barkod_meta'])) {
            $barkod = 'meta:' . sanitize_key($p['barkod_meta']);
        }

        SC_Settings::update([
            'referentni_datum'     => $date($p['referentni_datum'] ?? '', '2026-09-10'),
            'alt_datum'            => $date($p['alt_datum'] ?? '', '2025-05-02'),
            'alt_kategorije'       => array_map('intval', (array) ($p['alt_kategorije'] ?? [])),
            'izuzete_kategorije'   => array_map('intval', (array) ($p['izuzete_kategorije'] ?? [])),
            'label'                => wp_kses_post($p['label'] ?? '') ?: 'Sidrena cijena ({datum}): {cijena}',
            'label_lang'           => wp_kses_post($p['label_lang'] ?? ''),
            'prikaz_mod'           => in_array($p['prikaz_mod'] ?? '', ['on', 'off', 'datum'], true) ? $p['prikaz_mod'] : 'datum',
            'prikaz_od'            => $date($p['prikaz_od'] ?? '', '2026-10-01'),
            'prikaz_kosarica'      => empty($p['prikaz_kosarica']) ? 0 : 1,
            'prikaz_bez_sidrene'   => ($p['prikaz_bez_sidrene'] ?? 'nista') === 'redovna' ? 'redovna' : 'nista',
            'auto_novi'            => empty($p['auto_novi']) ? 0 : 1,
            'naziv_trgovca'        => sanitize_text_field($p['naziv_trgovca'] ?? ''),
            'oblik_objekta'        => sanitize_text_field($p['oblik_objekta'] ?? 'webshop'),
            'adresa'               => sanitize_text_field($p['adresa'] ?? ''),
            'oznaka_objekta'       => sanitize_text_field($p['oznaka_objekta'] ?? '1'),
            'broj_pohrane'         => sanitize_text_field($p['broj_pohrane'] ?? '1'),
            'marka_izvor'          => $marka,
            'barkod_izvor'         => $barkod,
            'jedinica_meta'        => sanitize_key($p['jedinica_meta'] ?? ''),
            'cijena_jedinica_meta' => sanitize_key($p['cijena_jedinica_meta'] ?? ''),
            'naziv_akcije'         => sanitize_text_field($p['naziv_akcije'] ?? 'Akcija'),
            'ukljuci_nedostupne'   => empty($p['ukljuci_nedostupne']) ? 0 : 1,
            'ukljuci_skrivene'     => empty($p['ukljuci_skrivene']) ? 0 : 1,
            'csv_separator'        => ($p['csv_separator'] ?? ';') === ',' ? ',' : ';',
            'decimalni_znak'       => ($p['decimalni_znak'] ?? '.') === ',' ? ',' : '.',
            'javni_slug'           => sanitize_title($p['javni_slug'] ?? 'cjenik') ?: 'cjenik',
            'cron_vrijeme'         => preg_match('/^\d{2}:\d{2}$/', (string) ($p['cron_vrijeme'] ?? '')) ? $p['cron_vrijeme'] : '04:00',
            'retencija_dana'       => max(31, (int) ($p['retencija_dana'] ?? 35)),
        ]);

        SC_Export::schedule_cron();
        SC_Public::register_rewrites();
        flush_rewrite_rules();

        self::redirect('postavke', 'Postavke spremljene.');
    }

    public static function download_working_csv(): void {
        check_admin_referer('sc_download');
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Nemate ovlasti.');
        }
        @set_time_limit(0);
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_type, p.post_title, sku.meta_value AS sku, rp.meta_value AS regular, sc.meta_value AS sidrena, sd.meta_value AS datum, si.meta_value AS izvor
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id=p.ID AND sku.meta_key='_sku'
             LEFT JOIN {$wpdb->postmeta} rp ON rp.post_id=p.ID AND rp.meta_key='_regular_price'
             LEFT JOIN {$wpdb->postmeta} sc ON sc.post_id=p.ID AND sc.meta_key='" . SC_Snapshot::META_PRICE . "'
             LEFT JOIN {$wpdb->postmeta} sd ON sd.post_id=p.ID AND sd.meta_key='" . SC_Snapshot::META_DATE . "'
             LEFT JOIN {$wpdb->postmeta} si ON si.post_id=p.ID AND si.meta_key='" . SC_Snapshot::META_SRC . "'
             WHERE p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private','draft')
               AND rp.meta_value IS NOT NULL AND rp.meta_value<>''
             ORDER BY p.ID",
            ARRAY_A
        );
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sidrene-cijene-radni-' . wp_date('Ymd-Hi') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['id', 'tip', 'sku', 'naziv', 'redovna_cijena', 'sidrena_cijena', 'datum', 'izvor'], ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, [$r['ID'], $r['post_type'] === 'product_variation' ? 'varijacija' : 'proizvod', $r['sku'], $r['post_title'], $r['regular'], $r['sidrena'], $r['datum'], $r['izvor']], ';', '"', '');
        }
        exit;
    }

    /** "1.234,56" -> "1234.56", "19,90" -> "19.90", "19.90" -> "19.90" */
    private static function parse_price(string $v): string {
        $v = preg_replace('/[^\d,.\-]/', '', $v);
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace('.', '', $v);
        }
        return str_replace(',', '.', $v);
    }

    public static function import_csv(): void {
        check_admin_referer('sc_import_csv');
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Nemate ovlasti.');
        }
        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            self::redirect('uvoz', 'Datoteka nije primljena.', true);
        }
        $r = self::import_file($_FILES['csv']['tmp_name'], !empty($_POST['samo_objavljeni']), !empty($_POST['prazno_brise']));
        if (!empty($r['error'])) {
            self::redirect('uvoz', $r['error'], true);
        }
        set_transient('sc_import_not_found', $r['not_found'], 10 * MINUTE_IN_SECONDS);
        self::redirect('uvoz', sprintf('Ažurirano: %d · nepromijenjeno: %d · obrisano: %d · preskočeno: %d · nije pronađeno u webshopu: %d.', $r['ok'], $r['unchanged'], $r['cleared'], $r['skipped'], $r['miss']));
    }

    /**
     * Uvoz sidrenih cijena iz CSV datoteke. Mijenja samo meta polja sidrene cijene.
     * @return array{ok:int,unchanged:int,cleared:int,skipped:int,miss:int,not_found:array,error?:string}
     */
    public static function import_file(string $path, bool $only_published = true, bool $empty_clears = false): array {
        @set_time_limit(0);
        $res = ['ok' => 0, 'unchanged' => 0, 'cleared' => 0, 'skipped' => 0, 'miss' => 0, 'not_found' => []];
        $fh = fopen($path, 'r');
        $first = $fh ? fgets($fh) : false;
        if ($first === false) {
            return $res + ['error' => 'Prazna datoteka.'];
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $sep = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $header = array_map(static fn($h) => strtolower(trim(trim($h), '"')), str_getcsv($first, $sep, '"', ''));
        $col = static fn(array $names) => (static function () use ($header, $names) {
            foreach ($names as $n) {
                $i = array_search($n, $header, true);
                if ($i !== false) {
                    return $i;
                }
            }
            return null;
        })();
        $c_sku = $col(['sku', 'sifra', 'šifra', 'ean', 'barkod', 'gtin', 'barcode']);
        $c_id  = $col(['id', 'product_id']);
        $c_pr  = $col(['sidrena_cijena', 'sidrena', 'sidrena cijena', 'cijena', 'price']);
        $c_dt  = $col(['datum', 'sidrena_cijena_datum', 'date']);
        if ($c_pr === null || ($c_sku === null && $c_id === null)) {
            return $res + ['error' => 'Zaglavlje mora sadržavati stupac za proizvod (sku/sifra/ean ili id) i stupac sidrena_cijena.'];
        }

        $ok = 0;
        $miss = 0;
        $cleared = 0;
        $skipped = 0;
        $unchanged = 0;
        $not_found = [];
        while (($row = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            if (count($row) < 2) {
                continue;
            }
            $id  = 0;
            $key = '';
            if ($c_id !== null && !empty($row[$c_id]) && ctype_digit(trim((string) $row[$c_id]))) {
                $id  = (int) $row[$c_id];
                $key = '#' . $id;
            } elseif ($c_sku !== null && trim((string) $row[$c_sku]) !== '') {
                $key = trim((string) $row[$c_sku]);
                $id  = (int) wc_get_product_id_by_sku($key);
                if (!$id) {
                    $id = self::product_id_by_gtin($key);
                }
            }
            $post = $id ? get_post($id) : null;
            if (!$post || !in_array($post->post_type, ['product', 'product_variation'], true)) {
                $miss++;
                if (count($not_found) < 30) {
                    $not_found[] = $key;
                }
                continue;
            }
            if ($only_published) {
                $status = $post->post_type === 'product_variation' && $post->post_parent ? get_post_status($post->post_parent) : $post->post_status;
                if ($status !== 'publish') {
                    $skipped++;
                    continue;
                }
            }
            $price = trim((string) ($row[$c_pr] ?? ''));
            if ($price === '') {
                if ($empty_clears) {
                    SC_Snapshot::clear($id);
                    $cleared++;
                } else {
                    $skipped++;
                }
                continue;
            }
            $price = self::parse_price($price);
            $date = ($c_dt !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) ($row[$c_dt] ?? '')))) ? trim((string) $row[$c_dt]) : SC_Snapshot::reference_date_for($id);
            $clean = wc_format_decimal($price);
            if ((string) get_post_meta($id, SC_Snapshot::META_PRICE, true) === (string) $clean && (string) get_post_meta($id, SC_Snapshot::META_DATE, true) === $date) {
                $unchanged++;
                continue;
            }
            SC_Snapshot::set($id, $clean, $date, 'uvoz');
            $ok++;
        }
        fclose($fh);
        wp_cache_flush();
        return ['ok' => $ok, 'unchanged' => $unchanged, 'cleared' => $cleared, 'skipped' => $skipped, 'miss' => $miss, 'not_found' => $not_found];
    }

    private static function product_id_by_gtin(string $gtin): int {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id
             WHERE pm.meta_key='_global_unique_id' AND pm.meta_value=%s AND p.post_type IN ('product','product_variation') AND p.post_status<>'trash' LIMIT 1",
            $gtin
        ));
        return (int) $id;
    }
}
