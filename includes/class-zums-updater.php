<?php
if (!defined('ABSPATH')) { exit; }

class ZUMS_Updater {
    const OWNER = 'zakariamahboub';
    const REPO  = 'zakaria-unused-media-scanner';
    const API_LATEST = 'https://api.github.com/repos/%s/%s/releases/latest';
    const API_REPO   = 'https://api.github.com/repos/%s/%s';

    public static function init() {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_for_update']);
        add_filter('plugins_api', [__CLASS__, 'plugins_api'], 10, 3);
    }

    private static function http_get($url) {
        $args = [
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/'.get_bloginfo('version').' ZUMS-Updater'
            ],
            'timeout' => 15,
        ];
        $res = wp_remote_get($url, $args);
        if (is_wp_error($res)) return null;
        $code = wp_remote_retrieve_response_code($res);
        if ($code !== 200) return null;
        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }

    private static function latest_release() {
        $url = sprintf(self::API_LATEST, self::OWNER, self::REPO);
        $data = self::http_get($url);
        if (!$data) {
            // fallback to repo info (get default branch commit zip) if no releases
            $repo = self::http_get(sprintf(self::API_REPO, self::OWNER, self::REPO));
            if (!$repo || empty($repo['default_branch'])) return null;
            return [
                'version' => null,
                'zip_url' => 'https://github.com/'.self::OWNER.'/'.self::REPO.'/archive/refs/heads/'.$repo['default_branch'].'.zip',
                'homepage' => 'https://github.com/'.self::OWNER.'/'.self::REPO,
                'changelog' => '',
            ];
        }
        $tag = isset($data['tag_name']) ? $data['tag_name'] : '';
        $version = ltrim($tag, 'vV ');
        $zip = '';
        if (!empty($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (!empty($asset['browser_download_url']) && preg_match('/\.zip$/i', $asset['browser_download_url'])) {
                    $zip = $asset['browser_download_url'];
                    break;
                }
            }
        }
        if (!$zip && !empty($data['zipball_url'])) {
            $zip = $data['zipball_url'];
        }
        return [
            'version'  => $version ?: null,
            'zip_url'  => $zip ?: 'https://github.com/'.self::OWNER.'/'.self::REPO.'/archive/refs/tags/'.$tag.'.zip',
            'homepage' => 'https://github.com/'.self::OWNER.'/'.self::REPO,
            'changelog'=> isset($data['body']) ? $data['body'] : '',
        ];
    }

    public static function check_for_update($transient) {
        if (empty($transient) || !is_object($transient)) return $transient;

        $rel = self::latest_release();
        if (!$rel || empty($rel['zip_url'])) return $transient;

        $current = defined('ZUMS_PLUGIN_VERSION') ? ZUMS_PLUGIN_VERSION : null;
        $remote  = $rel['version'];

        // if remote version is missing (no releases), skip offering updates
        if (!$remote || !$current) return $transient;

        if (version_compare($remote, $current, '>')) {
            $info = new stdClass();
            $info->slug        = 'zakaria-unused-media-scanner';
            $info->plugin      = defined('ZUMS_PLUGIN_BASENAME') ? ZUMS_PLUGIN_BASENAME : 'zakaria-unused-media-scanner/zakaria-unused-media-scanner.php';
            $info->new_version = $remote;
            $info->url         = $rel['homepage'];
            $info->package     = $rel['zip_url']; // zip
            $transient->response[$info->plugin] = $info;
        }
        return $transient;
    }

    public static function plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information') return $result;
        if (empty($args->slug) || $args->slug !== 'zakaria-unused-media-scanner') return $result;

        $rel = self::latest_release();
        $info = new stdClass();
        $info->name          = 'Zakaria Unused Media Scanner';
        $info->slug          = 'zakaria-unused-media-scanner';
        $info->version       = defined('ZUMS_PLUGIN_VERSION') ? ZUMS_PLUGIN_VERSION : '1.0.0';
        $info->author        = '<a href="https://zakariamahboub.ma">Zakaria Mahboub</a>';
        $info->homepage      = $rel ? $rel['homepage'] : 'https://github.com/'.self::OWNER.'/'.self::REPO;
        $info->download_link = $rel ? $rel['zip_url'] : '';
        $info->requires      = '5.6';
        $info->tested        = get_bloginfo('version');
        $info->sections = [
            'description' => 'Scanne les images potentiellement non utilisées (posts, pages, WooCommerce, Elementor, ACF, options, fichiers du thème/Uploads CSS/JS/HTML).',
            'changelog'   => !empty($rel['changelog']) ? wp_kses_post(nl2br($rel['changelog'])) : 'Voir la page GitHub pour le changelog.'
        ];
        return $info;
    }
}

ZUMS_Updater::init();
