<?php
if (!defined('ABSPATH')) { exit; }

class ZUMS_Scanner {

    public static function count_attachments() {
        global $wpdb;
        return (int) $wpdb->get_var("
            SELECT COUNT(1)
            FROM {$wpdb->posts}
            WHERE post_type='attachment'
              AND post_mime_type LIKE 'image/%'
        ");
    }

    private static function get_attachments_batch($offset, $limit) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("
            SELECT ID, post_title
            FROM {$wpdb->posts}
            WHERE post_type='attachment'
              AND post_mime_type LIKE 'image/%'
            ORDER BY ID ASC
            LIMIT %d OFFSET %d
        ", $limit, $offset));
    }

    public static function build_content_blobs() {
        global $wpdb;

        if (function_exists('set_time_limit')) @set_time_limit(60);
        @ignore_user_abort(true);

        $upload = wp_upload_dir();
        $blobs = [];

        // 1) Contenu des posts
        $posts = $wpdb->get_col("
            SELECT post_content
            FROM {$wpdb->posts}
            WHERE post_status IN ('publish','future','draft','private')
        ");
        if (!empty($posts)) $blobs[] = implode(' ', array_filter($posts));

        // 2) Elementor data & page settings
        $elem = $wpdb->get_col("
            SELECT meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_key IN ('_elementor_data','_elementor_page_settings') AND meta_value<>''
        ");
        if (!empty($elem)) $blobs[] = implode(' ', $elem);

        // 3) Elementor library/kit
        $elib = $wpdb->get_col("
            SELECT post_content
            FROM {$wpdb->posts}
            WHERE post_type IN ('elementor_library','elementor_kit')
              AND post_status IN ('publish','future','draft','private')
        ");
        if (!empty($elib)) $blobs[] = implode(' ', $elib);

        // 4) Metas textuelles contenant des URLs d'uploads
        $like = '%' . $wpdb->esc_like($upload['baseurl']) . '%';
        $meta_with_urls = $wpdb->get_col($wpdb->prepare("
            SELECT meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_value LIKE %s
        ", $like));
        if (!empty($meta_with_urls)) $blobs[] = implode(' ', $meta_with_urls);

        // 5) Options contenant des URLs d'uploads
        $options = $wpdb->get_col($wpdb->prepare("
            SELECT option_value FROM {$wpdb->options}
            WHERE option_value LIKE %s
        ", $like));
        if (!empty($options)) $blobs[] = implode(' ', $options);

        // 6) Customizer Additional CSS
        $custom_css_post_id = intval(get_option('custom_css_post_id'));
        if ($custom_css_post_id) {
            $custom_css_post = get_post($custom_css_post_id);
            if ($custom_css_post && isset($custom_css_post->post_content)) { $blobs[] = (string)$custom_css_post->post_content; }
        }

        // 7) Thème actif (parent + enfant) PHP/CSS/JS/HTML
        $theme_dirs = [];
        $theme = wp_get_theme();
        if ($theme) {
            $theme_dirs[] = $theme->get_stylesheet_directory();
            $parent_dir = $theme->get_template_directory();
            if ($parent_dir && $parent_dir !== $theme_dirs[0]) $theme_dirs[] = $parent_dir;
        }
        foreach ($theme_dirs as $dir) {
            if (!is_dir($dir)) continue;
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($rii as $file) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, ['php','css','js','html'])) {
                    $content = @file_get_contents($file);
                    if ($content !== false) $blobs[] = $content;
                }
            }
        }

        // 8) Uploads: Elementor CSS + tous CSS/JS/HTML (cap à 3000 fichiers, <2MB)
        $upload_dir = wp_upload_dir();
        $elem_css_dir = trailingslashit($upload_dir['basedir']) . 'elementor/css';
        $paths = [];
        if (is_dir($elem_css_dir)) $paths[] = $elem_css_dir;
        $paths[] = trailingslashit($upload_dir['basedir']);
        foreach ($paths as $scan_dir) {
            if (!is_dir($scan_dir)) continue;
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scan_dir, FilesystemIterator::SKIP_DOTS));
            $maxFiles = 3000; $count = 0;
            foreach ($rii as $file) {
                if ($count >= $maxFiles) break;
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, ['css','js','html'])) {
                    $size = @filesize($file);
                    if ($size !== false && $size < 2*1024*1024) {
                        $content = @file_get_contents($file);
                        if ($content !== false) { $blobs[] = $content; $count++; }
                    }
                }
            }
        }

        return $blobs;
    }

    private static function collect_used_ids_from_meta() {
        global $wpdb;
        $used = [];

        // Featured images
        $thumbs = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id'");
        foreach ($thumbs as $v) { $v = intval($v); if ($v) $used[$v] = true; }

        // Woo galleries
        $galleries = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_product_image_gallery' AND meta_value<>''");
        foreach ($galleries as $g) {
            $parts = explode(',', $g);
            foreach ($parts as $p) { $p = intval(trim($p)); if ($p) $used[$p] = true; }
        }

        // Woo variation thumbs
        $variation_thumbs = $wpdb->get_col("
            SELECT pm.meta_value
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE p.post_type='product_variation' AND pm.meta_key='thumbnail_id'
        ");
        foreach ($variation_thumbs as $v) { $v = intval($v); if ($v) $used[$v] = true; }

        // Numeric metas likely holding attachment IDs
        $numeric_metas = $wpdb->get_col("
            SELECT meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_value REGEXP '^[0-9]+$'
              AND (
                meta_key REGEXP '(image|img|thumbnail|background|banner|logo)(_id)?$'
                OR meta_key LIKE '%_image%'
                OR meta_key LIKE '%_img%'
              )
        ");
        foreach ($numeric_metas as $v) { $v = intval($v); if ($v) $used[$v] = true; }

        // Options: site icon & theme mods (custom_logo, headers, backgrounds, etc.)
        $site_icon = get_option('site_icon');
        if ($site_icon) { $used[intval($site_icon)] = true; }

        $mods = get_option('theme_mods_' . get_stylesheet());
        if (is_array($mods)) {
            $pattern = '/(logo|image|header|background|banner|icon|favicon)/i';
            foreach ($mods as $k => $v) {
                if (!preg_match($pattern, (string)$k)) continue;
                if (is_numeric($v)) { $used[intval($v)] = true; }
                if (is_array($v)) {
                    foreach ($v as $kk => $vv) {
                        if (is_numeric($vv)) { $used[intval($vv)] = true; }
                    }
                }
            }
        }

        return $used;
    }

    public static function scan_batch($offset, $limit, $blobs) {
        if (function_exists('set_time_limit')) @set_time_limit(60);
        @ignore_user_abort(true);

        $attachments = self::get_attachments_batch($offset, $limit);
        if (empty($attachments)) return [];

        $used_meta_ids = self::collect_used_ids_from_meta();
        $big_blob = implode(' ', array_map(function($b){ return is_string($b) ? $b : ''; }, $blobs));

        global $wpdb;
        $unused = [];

        foreach ($attachments as $att) {
            $id = intval($att->ID);
            if (isset($used_meta_ids[$id])) continue;

            $url  = wp_get_attachment_url($id);
            $file = $url ? basename($url) : '';
            $id_pattern = 'wp-image-' . $id;
            $found = false;

            // Direct matches
            if ($url && strpos($big_blob, $url) !== false) { $found = true; }
            elseif ($file && strpos($big_blob, $file) !== false) { $found = true; }
            elseif (strpos($big_blob, $id_pattern) !== false) { $found = true; }
            // Elementor JSON / shortcode gallery
            elseif (strpos($big_blob, '\"id\":'.$id) !== false || strpos($big_blob, '\"id\": '.$id) !== false) { $found = true; }
            elseif (strpos($big_blob, 'attachment_id\":'.$id) !== false || strpos($big_blob, 'attachment_id\": '.$id) !== false) { $found = true; }
            elseif (preg_match('/ids=\"[^\"]*\\b'.$id.'\\b[^\"]*\"/', $big_blob)) { $found = true; }
            else {
                // Fallback LIKE checks
                if ($file) {
                    $like = '%' . $wpdb->esc_like($file) . '%';
                    $exists = (int) $wpdb->get_var($wpdb->prepare("
                        SELECT COUNT(1) FROM {$wpdb->posts}
                        WHERE post_content LIKE %s
                    ", $like));
                    if ($exists) $found = true;
                }
                if (!$found && $url) {
                    $like = '%' . $wpdb->esc_like($url) . '%';
                    $exists = (int) $wpdb->get_var($wpdb->prepare("
                        SELECT COUNT(1) FROM {$wpdb->postmeta}
                        WHERE meta_value LIKE %s
                    ", $like));
                    if ($exists) $found = true;
                }
            }

            if (!$found) {
                $thumb = wp_get_attachment_image_src($id, 'thumbnail');
                $unused[] = [
                    'ID' => $id,
                    'title' => get_the_title($id),
                    'url' => $url,
                    'file' => $file,
                    'thumb' => $thumb ? $thumb[0] : $url,
                ];
            }
        }

        return $unused;
    }
}
