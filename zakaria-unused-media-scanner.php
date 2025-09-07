<?php
/**
 * Plugin Name: Zakaria Unused Media Scanner
 * Plugin URI: https://zakariamahboub.ma
 * Description: Scanne votre site pour lister les images potentiellement non utilisées (posts, pages, WooCommerce, Elementor, ACF, options), fichiers du thème et assets dans /uploads. Export CSV, corbeille, reprise de scan, lot ajustable et mode diagnostic.
 * Version: 1.1.2
 * Author: Zakaria Mahboub
 * Author URI: https://zakariamahboub.ma
 * Text Domain: zakaria-unused-media-scanner
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

define('ZUMS_PLUGIN_VERSION', '1.1.2');
define('ZUMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZUMS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once ZUMS_PLUGIN_DIR . 'includes/class-zums-scanner.php';

class ZUMS_Plugin {
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_ums_start_scan',  [$this, 'ajax_start_scan']);
        add_action('wp_ajax_ums_scan_batch',  [$this, 'ajax_scan_batch']);
        add_action('wp_ajax_ums_finish_scan', [$this, 'ajax_finish_scan']);
        add_action('wp_ajax_zums_trash_selected', [$this, 'ajax_trash_selected']);

        add_action('wp_ajax_zums_get_state',       [$this, 'ajax_get_state']);
        add_action('wp_ajax_zums_reset_state',     [$this, 'ajax_reset_state']);
        add_action('wp_ajax_zums_get_last_error',  [$this, 'ajax_get_last_error']);
        add_action('admin_post_ums_clear_error',   [$this, 'clear_error']);
        add_action('admin_post_ums_download_csv',  [$this, 'download_csv']);
    }

    private function arm_shutdown_logger($ctx, $extra = []) {
        register_shutdown_function(function() use ($ctx, $extra) {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $payload = [
                    'time' => time(),
                    'context' => $ctx,
                    'type' => $e['type'],
                    'message' => $e['message'],
                    'file' => $e['file'],
                    'line' => $e['line'],
                    'extra' => $extra,
                ];
                update_option('zums_last_error', $payload, false);
            }
        });
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
        wp_localize_script('zums-admin', 'UMS',
            [
                'ajax' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('zums_nonce'),
                'i18n' => [
                    'starting' => __('Initialisation du scan…', 'zakaria-unused-media-scanner'),
                    'scanning' => __('Scan en cours…', 'zakaria-unused-media-scanner'),
                    'done' => __('Terminé', 'zakaria-unused-media-scanner'),
                    'resumeAsk' => __('Un scan précédent a été trouvé. Reprendre ?', 'zakaria-unused-media-scanner')
                ]
            ]
        );
        wp_enqueue_style('zums-admin-style', ZUMS_PLUGIN_URL.'assets/admin.css', [], ZUMS_PLUGIN_VERSION);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) { wp_die(__('Permission refusée', 'zakaria-unused-media-scanner')); }
        $last = get_option('zums_last_error');
        ?>
        <div class="wrap">
            <h1>Zakaria Unused Media Scanner</h1>
            <?php if (!empty($last) && is_array($last)): ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e('Dernière erreur AJAX détectée (diagnostic)', 'zakaria-unused-media-scanner'); ?></strong></p>
                    <p><?php echo esc_html($last['message'] ?? ''); ?></p>
                    <p><code><?php echo esc_html(($last['file'] ?? '').':'.($last['line'] ?? '')); ?></code></p>
                    <p><?php esc_html_e('Contexte', 'zakaria-unused-media-scanner'); ?>: <?php echo esc_html($last['context'] ?? ''); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('zums_nonce', 'zums_nonce_field'); ?>
                        <input type="hidden" name="action" value="ums_clear_error" />
                        <button class="button"><?php esc_html_e('Effacer ce message', 'zakaria-unused-media-scanner'); ?></button>
                    </form>
                </div>
            <?php endif; ?>

            <p><strong><?php echo esc_html__('Conseils importants', 'zakaria-unused-media-scanner'); ?> :</strong>
            <?php echo esc_html__("exécutez idéalement ce scan sur un staging et assurez-vous d'avoir une sauvegarde (base de données + /uploads).", 'zakaria-unused-media-scanner'); ?>
            </p>

            <div id="zums-controls">
                <button id="ums-start" class="button button-primary"><?php echo esc_html__('Démarrer le scan', 'zakaria-unused-media-scanner'); ?></button>
                <label style="margin-left:10px;">
                    <?php echo esc_html__('Taille du lot', 'zakaria-unused-media-scanner'); ?>:
                    <input type="number" id="ums-batch" min="10" max="200" step="10" value="40" style="width:80px;">
                </label>
                <span id="ums-progress" style="margin-left:10px;"></span>
                <div id="ums-bar" style="width: 100%; background:#f0f0f0; height: 16px; margin-top:10px;">
                    <div id="ums-bar-fill" style="width:0; height:16px; background:#007cba;"></div>
                </div>
            </div>

            <div id="ums-results" style="margin-top:20px; display:none;">
                <h2><?php echo esc_html__('Images potentiellement non utilisées', 'zakaria-unused-media-scanner'); ?></h2>
                <form id="ums-actions" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('zums_nonce', 'zums_nonce_field'); ?>
                    <input type="hidden" name="action" value="ums_download_csv" />
                    <button class="button"><?php echo esc_html__('Télécharger le CSV', 'zakaria-unused-media-scanner'); ?></button>
                </form>

                <p style="margin-top:10px;">
                    <button id="ums-trash-selected" class="button button-secondary"><?php echo esc_html__('Mettre à la corbeille la sélection (sécurisé)', 'zakaria-unused-media-scanner'); ?></button>
                </p>

                <table class="widefat fixed striped" id="ums-table">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="ums-check-all" /></th>
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
                #ums-table img { max-width:60px; height:auto; }
            </style>
        </div>
        <?php
    }

    public function ajax_start_scan() {
        check_ajax_referer('zums_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
        $this->arm_shutdown_logger('start_scan');

        if (function_exists('set_time_limit')) @set_time_limit(60);
        @ignore_user_abort(true);

        $blobs = ZUMS_Scanner::build_content_blobs();
        set_transient('zums_cached_blobs', $blobs, 60*180); // 3h TTL

        $total = ZUMS_Scanner::count_attachments();
        $batch = isset($_POST['batch']) ? max(10, intval($_POST['batch'])) : 40;

        // reset résultats & state
        set_transient('zums_unused_results', [], 60*60);
        update_option('zums_scan_state', ['offset'=>0,'total'=>$total,'batch'=>$batch,'started'=>time()], false);

        wp_send_json_success([ 'total' => $total, 'batch' => $batch ]);
    }

    public function ajax_scan_batch() {
        check_ajax_referer('zums_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit  = isset($_POST['limit']) ? intval($_POST['limit']) : 40;
        $this->arm_shutdown_logger('scan_batch', ['offset'=>$offset,'limit'=>$limit]);

        if (function_exists('set_time_limit')) @set_time_limit(60);
        @ignore_user_abort(true);

        $blobs = get_transient('zums_cached_blobs');
        if ($blobs === false) {
            $blobs = ZUMS_Scanner::build_content_blobs();
        }
        // Touch TTL
        set_transient('zums_cached_blobs', $blobs, 60*180);

        $unused = ZUMS_Scanner::scan_batch($offset, $limit, $blobs);

        // Accumuler
        $stored = get_transient('zums_unused_results');
        if (!is_array($stored)) $stored = [];
        $stored = array_merge($stored, $unused);
        set_transient('zums_unused_results', $stored, 60*60);

        // Update state
        $state = get_option('zums_scan_state', []);
        if (!is_array($state)) $state = [];
        $state['offset'] = $offset + $limit;
        update_option('zums_scan_state', $state, false);

        wp_send_json_success([ 'found' => count($unused) ]);
    }

    public function ajax_finish_scan() {
        check_ajax_referer('zums_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
        $this->arm_shutdown_logger('finish_scan');

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

    public function ajax_get_state() {
        check_ajax_referer('zums_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
        $state = get_option('zums_scan_state', []);
        if (!is_array($state)) $state = [];
        wp_send_json_success($state);
    }

    public function ajax_reset_state() {
        check_ajax_referer('zums_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
        delete_option('zums_scan_state');
        wp_send_json_success(true);
    }

    public function ajax_get_last_error() {
        check_ajax_referer('zums_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
        $last = get_option('zums_last_error');
        if (!$last) wp_send_json_success(null);
        wp_send_json_success($last);
    }

    public function clear_error() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        if (!isset($_POST['zums_nonce_field']) || !wp_verify_nonce($_POST['zums_nonce_field'], 'zums_nonce')) { wp_die('bad nonce'); }
        delete_option('zums_last_error');
        wp_safe_redirect(admin_url('upload.php?page=zums-scanner'));
        exit;
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
