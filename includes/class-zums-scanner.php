<?php
if (!defined('ABSPATH')) { exit; }

class ZUMS_Scanner {

    // Collect IDs from options/theme_mods (favicon, custom logo, headers, backgrounds)
    private static function collect_used_ids_from_options() {
        $used = [];
        // site_icon (favicon) stores an attachment ID
        $site_icon = get_option('site_icon');
        if ($site_icon) { $used[intval($site_icon)] = true; }

        // theme_mods: custom_logo, header images, backgrounds, banners, icons...
        $mods = get_option('theme_mods_' . get_stylesheet());
        if (is_array($mods)) {
            $pattern = '/(logo|image|header|background|banner|icon|favicon)/i';
            foreach ($mods as $k => $v) {
                if (!preg_match($pattern, (string)$k)) continue;
                if (is_numeric($v)) { $used[intval($v)] = true; }
                if (is_array($v)) {
                    // nested arrays may contain attachment_id or id
                    foreach ($v as $kk => $vv) {
                        if (is_numeric($vv) && preg_match('/(id|attachment_id)$/i', (string)$kk)) {
                            $used[intval($vv)] = true;
                        }
                    }
                }
            }
        }
        return $used;
    }


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
        $upload = wp_upload_dir();
        $blobs = [];

        $posts = $wpdb->get_col("
            SELECT post_content
            FROM {$wpdb->posts}
            WHERE post_status IN ('publish','future','draft','private')
        ");
        $blobs[] = implode(' ', array_filter($posts));

        $elem = $wpdb->get_col("
            SELECT meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_key='_elementor_data' AND meta_value<>''
        ");
        if (!empty($elem)) $blobs[] = implode(' ', $elem);

        $like = '%' . $wpdb->esc_like($upload['baseurl']) . '%';
        $meta_with_urls = $wpdb->get_col($wpdb->prepare("
            SELECT meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_value LIKE %s
        ", $like));
        if (!empty($meta_with_urls)) $blobs[] = implode(' ', $meta_with_urls);

        $options = $wpdb->get_col($wpdb->prepare("
            SELECT option_value FROM {$wpdb->options}
            WHERE option_value LIKE %s
        ", $like));
        if (!empty($options)) $blobs[] = implode(' ', $options);

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
                    if ($content !== false) {
                        $blobs[] = $content;
                    }
                }
            }
        }


        // Elementor generated CSS in uploads (may contain background URLs)
        $upload_dir = wp_upload_dir();
        $elem_css_dir = trailingslashit($upload_dir['basedir']) . 'elementor/css';
        if (is_dir($elem_css_dir)) {
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($elem_css_dir, FilesystemIterator::SKIP_DOTS));
            foreach ($rii as $file) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, ['css'])) {
                    $content = @file_get_contents($file);
                    if ($content !== false) { $blobs[] = $content; }
                }
            }
        }

        return $blobs;
    }

    private static function collect_used_ids_from_meta() {
        global $wpdb;
        $used = [];

        $thumbs = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id'");
        foreach ($thumbs as $v) { $v = intval($v); if ($v) $used[$v] = true; }

        $galleries = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_product_image_gallery' AND meta_value<>''");
        foreach ($galleries as $g) {
            $parts = explode(',', $g);
            foreach ($parts as $p) { $p = intval(trim($p)); if ($p) $used[$p] = true; }
        }

        $variation_thumbs = $wpdb->get_col("
            SELECT pm.meta_value
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE p.post_type='product_variation' AND pm.meta_key='thumbnail_id'
        ");
        foreach ($variation_thumbs as $v) { $v = intval($v); if ($v) $used[$v] = true; }

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

        return $used;
    }

    public static function scan_batch($offset, $limit, $blobs) {
        global $wpdb;
        $attachments = self::get_attachments_batch($offset, $limit);
        if (empty($attachments)) return [];

        $upload = wp_upload_dir();
        $used_meta_ids = self::collect_used_ids_from_meta();
        $used_option_ids = self::collect_used_ids_from_options();
        if (!empty($used_option_ids)) { $used_meta_ids = array_replace($used_meta_ids, $used_option_ids); }

        $big_blob = implode(' ', array_map(function($b){ return is_string($b) ? $b : ''; }, $blobs));

        $unused = [];
        foreach ($attachments as $att) {
            $id = intval($att->ID);
            if (isset($used_meta_ids[$id])) { continue; }

            $url  = wp_get_attachment_url($id);
            $file = basename($url);
            $id_pattern = 'wp-image-' . $id;

            $found = false;

            if ($url && strpos($big_blob, $url) !== false) { $found = true; }
            elseif ($file && strpos($big_blob, $file) !== false) { $found = true; }
            elseif (strpos($big_blob, $id_pattern) !== false) { $found = true; }
            elseif (strpos($big_blob, '\"id\":'.$id) !== false || strpos($big_blob, '\"id\": '.$id) !== false) { $found = true; } // Elementor JSON id
            elseif (strpos($big_blob, 'attachment_id\":'.$id) !== false || strpos($big_blob, 'attachment_id\": '.$id) !== false) { $found = true; } // Some builders attachment_id
            elseif (preg_match('/ids=\"[^\"]*\\b'.$id.'\\b[^\"]*\"/', $big_blob)) { $found = true; } // [gallery ids="..."]
            else {
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
