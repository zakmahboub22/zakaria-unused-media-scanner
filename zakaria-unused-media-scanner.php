<?php
/**
 * Plugin Name: Zakaria Unused Media Scanner
 * Plugin URI: https://zakariamahboub.ma
 * Description: Scanne votre site pour lister les images potentiellement non utilisées (articles, pages, produits, Elementor, ACF, options) + références dans les fichiers du thème actif. Export CSV + mise à la corbeille sécurisée. Utilisez de préférence sur un staging et gardez une sauvegarde.
 * Version: 1.0.2
 * Author: Zakaria Mahboub
 * Author URI: https://zakariamahboub.ma
 * Text Domain: zakaria-unused-media-scanner
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Copyright: (c) 2025 Zakaria Mahboub
 */

if (!defined('ABSPATH')) { exit; }

define('ZUMS_PLUGIN_VERSION', '1.0.2');
define('ZUMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZUMS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once ZUMS_PLUGIN_DIR . 'includes/class-zums-scanner.php';

class ZUMS_Plugin {
    public function __construct() {
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_zums_start_scan', [$this, 'ajax_start_scan']);
        add_action('wp_ajax_zums_scan_batch', [$this, 'ajax_scan_batch']);
        add_action('wp_ajax_zums_finish_scan', [$this, 'ajax_finish_scan']);
        add_action('admin_post_zums_download_csv', [$this, 'download_csv']);
        add_action('wp_ajax_zums_trash_selected', [$this, 'ajax_trash_selected']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'settings_link']);
    }

    public function load_textdomain() {
        load_plugin_textdomain('zakaria-unused-media-scanner', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function settings_link($links) {
        $url = esc_url(admin_url('upload.php?page=zums-scanner'));
        $links[] = '<a href="'.$url.'">'.esc_html__('Ouvrir', 'zakaria-unused-media-scanner').'</a>';
        return $links;
    }

    public function register_menu() {
        add_media_page(
            __('Unused Media Scanner', 'zakaria-unused-media-scanner'),
            __('Unused Media Scanner', 'zakaria-unused-media-scanner'),
            'manage_options',
            'zums-scanner',
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'media_page_zums-scanner') return;
        wp_enqueue_script('zums-admin', ZUMS_PLUGIN_URL.'assets/admin.js', ['jquery'], ZUMS_PLUGIN_VERSION, true);
        wp_localize_script('zums-admin', 'ZUMS',
            [
                'ajax' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('zums_nonce'),
                'i18n' => [
                    'starting' => __('Initialisation du scan…', 'zakaria-unused-media-scanner'),
                    'scanning' => __('Scan en cours…', 'zakaria-unused-media-scanner'),
                    'done' => __('Terminé', 'zakaria-unused-media-scanner'),
                    'trashConfirm' => __('Mettre à la corbeille les fichiers sélectionnés ? Cette action est réversible via la corbeille des médias.', 'zakaria-unused-media-scanner'),
                ]
            ]
        );
        wp_enqueue_style('zums-admin-style', ZUMS_PLUGIN_URL.'assets/admin.css', [], ZUMS_PLUGIN_VERSION);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) { wp_die(__('Permission refusée', 'zakaria-unused-media-scanner')); }
        ?>
        <div class="wrap">
            <h1>Zakaria Unused Media Scanner</h1>
            <p><strong><?php echo esc_html__('Conseils importants', 'zakaria-unused-media-scanner'); ?> :</strong>
            <?php echo esc_html__("exécutez idéalement ce scan sur un staging et assurez-vous d'avoir une sauvegarde (base de données + /uploads). Le scan tente de détecter les usages dans : contenu des posts, données Elementor, champs ACF (IDs/URLs), options (images OG), WooCommerce (image à la une, galeries, variations), et références dans les fichiers du thème actif (PHP/CSS/JS/HTML).", 'zakaria-unused-media-scanner'); ?>
            </p>

            <div id="zums-controls">
                <button id="zums-start" class="button button-primary"><?php echo esc_html__('Démarrer le scan', 'zakaria-unused-media-scanner'); ?></button>
                <span id="zums-progress" style="margin-left:10px;"></span>
                <div id="zums-bar" style="width: 100%; background:#f0f0f0; height: 16px; margin-top:10px;">
                    <div id="zums-bar-fill" style="width:0; height:16px; background:#007cba;"></div>
                </div>
            </div>

            <div id="zums-results" style="margin-top:20px; display:none;">
                <h2><?php echo esc_html__('Images potentiellement non utilisées', 'zakaria-unused-media-scanner'); ?></h2>
                <form id="zums-actions" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('zums_nonce', 'zums_nonce_field'); ?>
                    <input type="hidden" name="action" value="zums_download_csv" />
                    <button class="button"><?php echo esc_html__('Télécharger le CSV', 'zakaria-unused-media-scanner'); ?></button>
                </form>

                <p style="margin-top:10px;">
                    <button id="zums-trash-selected" class="button button-secondary"><?php echo esc_html__('Mettre à la corbeille la sélection (sécurisé)', 'zakaria-unused-media-scanner'); ?></button>
                </p>

                <table class="widefat fixed striped" id="zums-table">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="zums-check-all" /></th>
                            <th><?php echo esc_html__('ID', 'zakaria-unused-media-scanner'); ?></th>
                            <th><?php echo esc_html__('Aperçu', 'zakaria-unused-media-scanner'); ?></th>
                            <th><?php echo esc_html__('Titre', 'zakaria-unused-media-scanner'); ?></th>
                            <th><?php echo esc_html__('URL', 'zakaria-unused-media-scanner'); ?></th>
                            <th><?php echo esc_html__('Fichier', 'zakaria-unused-media-scanner'); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <style>
                #zums-table img { max-width:60px; height:auto; }
            </style>
        </div>
        <?php
    }

    public function ajax_start_scan() {
        check_ajax_referer('zums_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $blobs = ZUMS_Scanner::build_content_blobs();
        set_transient('zums_cached_blobs', $blobs, 60*30); // 30 min

        $total = ZUMS_Scanner::count_attachments();
        $batch = 100; // taille de lot
        set_transient('zums_unused_results', [], 60*60);
        wp_send_json_success([ 'total' => $total, 'batch' => $batch ]);
    }

    public function ajax_scan_batch() {
        check_ajax_referer('zums_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit  = isset($_POST['limit']) ? intval($_POST['limit']) : 100;

        $blobs = get_transient('zums_cached_blobs');
        if ($blobs === false) {
            $blobs = ZUMS_Scanner::build_content_blobs();
            set_transient('zums_cached_blobs', $blobs, 60*30);
        }

        $unused = ZUMS_Scanner::scan_batch($offset, $limit, $blobs);
        $stored = get_transient('zums_unused_results');
        if (!is_array($stored)) $stored = [];
        $stored = array_merge($stored, $unused);
        set_transient('zums_unused_results', $stored, 60*60);

        wp_send_json_success([ 'found' => count($unused) ]);
    }

    public function ajax_finish_scan() {
        check_ajax_referer('zums_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $results = get_transient('zums_unused_results');
        if (!is_array($results)) $results = [];

        $csv_lines = ["ID,Title,URL,File"];
        foreach ($results as $r) {
            $csv_lines[] = sprintf('"%d","%s","%s","%s"',
                intval($r['ID']),
                str_replace('"','""',$r['title']),
                str_replace('"','""',$r['url']),
                str_replace('"','""',$r['file'])
            );
        }
        update_option('zums_last_csv', implode("\n", $csv_lines), false);

        wp_send_json_success([ 'results' => $results ]);
    }

    public function download_csv() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        if (!isset($_POST['zums_nonce_field']) || !wp_verify_nonce($_POST['zums_nonce_field'], 'zums_nonce')) { wp_die('bad nonce'); }

        $csv = get_option('zums_last_csv');
        if (!$csv) { $csv = "ID,Title,URL,File\n"; }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=unused-images.csv');
        echo $csv;
        exit;
    }

    public function ajax_trash_selected() {
        check_ajax_referer('zums_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $ids = isset($_POST['ids']) ? (array) $_POST['ids'] : [];
        $trashed = 0;
        foreach ($ids as $id) {
            $id = intval($id);
            if ($id > 0) {
                $res = wp_delete_attachment($id, false);
                if ($res) $trashed++;
            }
        }
        wp_send_json_success([ 'trashed' => $trashed ]);
    }
}

new ZUMS_Plugin();
