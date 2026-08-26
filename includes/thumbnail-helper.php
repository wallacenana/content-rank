<?php

if (!class_exists('Content_Rank_Thumbnail_Helper')) {
    class Content_Rank_Thumbnail_Helper
    {
        public static function set_featured_image($post_id, $generator, $item, $article, $reuse_existing = false)
        {
            $post_id = intval($post_id);
            $generator = is_array($generator) ? $generator : array();
            $item = is_array($item) ? $item : array();
            $article = is_array($article) ? $article : array();

            if ($post_id <= 0) {
                error_log('[Content Rank][thumbnail] abortado: post_id invalido');
                return false;
            }

            $source_type = !empty($generator['source_type'])
                ? sanitize_key((string) $generator['source_type'])
                : 'rss';
            $is_keyword_list = $source_type === 'keyword_list';
            $keyword_list_mode = !empty($generator['keyword_list_mode'])
                ? (string) $generator['keyword_list_mode']
                : Content_Rank_Generator::get_default_keyword_list_mode();
            $is_url_reference = Content_Rank_Generator::generator_uses_keyword_list_url_reference_mode($generator);
            $treat_like_rss = !$is_keyword_list || $is_url_reference;

            $image_source_mode = !empty($generator['image_source_mode'])
                ? sanitize_key((string) $generator['image_source_mode'])
                : Content_Rank_Generator::normalize_image_source_mode(
                    $source_type,
                    '',
                    isset($generator['pexels_enabled']) ? !empty($generator['pexels_enabled']) : null,
                    $keyword_list_mode
                );
            $existing_thumbnail_id = intval(get_post_thumbnail_id($post_id));
            if ($reuse_existing && $image_source_mode !== 'tmdb_composite' && $existing_thumbnail_id > 0 && wp_attachment_is_image($existing_thumbnail_id)) {
                return $existing_thumbnail_id;
            }
            $title = !empty($article['title'])
                ? (string) $article['title']
                : (!empty($item['source_title']) ? (string) $item['source_title'] : (!empty($item['title']) ? (string) $item['title'] : ''));

            Content_Rank_Generator::log_image_debug('thumbnail_helper_mode', array(
                'post_id' => $post_id,
                'image_source_mode' => $image_source_mode,
                'generator_image_source_mode' => isset($generator['image_source_mode']) ? (string) $generator['image_source_mode'] : '',
            ));
            error_log('[Content Rank][thumbnail] inicio ' . wp_json_encode(array(
                'post_id' => $post_id,
                'mode' => $image_source_mode,
                'title' => $title,
                'existing_id' => $existing_thumbnail_id,
            ), JSON_UNESCAPED_UNICODE));

            if ($image_source_mode === 'tmdb_composite' && class_exists('Content_Rank_TMDB')) {
                if (empty($item['tmdb_movies']) || !is_array($item['tmdb_movies'])) {
                    Content_Rank_TMDB::localize_article_movie_titles($generator, $item, $article, false);
                }
                Content_Rank_Generator::log_image_debug('thumbnail_helper_tmdb_start', array(
                    'post_id' => $post_id,
                    'movies_count' => !empty($item['tmdb_movies']) && is_array($item['tmdb_movies']) ? count($item['tmdb_movies']) : 0,
                ));
                error_log('[Content Rank][thumbnail] TMDB posters=' . (!empty($item['tmdb_movies']) && is_array($item['tmdb_movies']) ? count($item['tmdb_movies']) : 0));
                $composite_result = self::create_tmdb_composite_thumbnail(
                    $post_id,
                    $title,
                    !empty($item['tmdb_movies']) && is_array($item['tmdb_movies']) ? $item['tmdb_movies'] : array(),
                    !empty($generator['tmdb_thumbnail_bg_color']) ? $generator['tmdb_thumbnail_bg_color'] : '#c91414'
                );
                if (!is_wp_error($composite_result) && intval($composite_result) > 0) {
                    error_log('[Content Rank][thumbnail] TMDB concluida attachment=' . intval($composite_result));
                    return intval($composite_result);
                }
                error_log('[Content Rank][thumbnail] TMDB falhou ' . (is_wp_error($composite_result) ? $composite_result->get_error_message() : 'resultado invalido'));
                Content_Rank_Generator::log_image_debug('thumbnail_helper_tmdb_failed', array(
                    'post_id' => $post_id,
                    'error' => is_wp_error($composite_result) ? $composite_result->get_error_message() : 'unknown',
                ));
                return $composite_result;
            }

            $use_source_image = $treat_like_rss
                && Content_Rank_Generator::image_source_mode_uses_source_image($image_source_mode);
            $use_pexels = Content_Rank_Generator::image_source_mode_uses_pexels($image_source_mode);
            $use_dalle = Content_Rank_Generator::image_source_mode_uses_dalle($image_source_mode);
            $source_image_url = '';
            if ($use_source_image) {
                $source_html = self::get_cached_source_html($item);
                $source_image_url = self::extract_og_image_url(
                    $source_html,
                    !empty($item['permalink']) ? (string) $item['permalink'] : ''
                );
            }
            error_log('[Content Rank][thumbnail] fontes ' . wp_json_encode(array(
                'source_url' => $source_image_url,
                'use_pexels' => $use_pexels ? 1 : 0,
                'use_dalle' => $use_dalle ? 1 : 0,
            ), JSON_UNESCAPED_UNICODE));

            Content_Rank_Generator::log_image_debug('thumbnail_helper_start', array(
                'post_id' => $post_id,
                'image_source_mode' => $image_source_mode,
                'use_source_image' => $use_source_image ? 1 : 0,
                'use_pexels' => $use_pexels ? 1 : 0,
                'use_dalle' => $use_dalle ? 1 : 0,
                'has_source_image' => $source_image_url !== '' ? 1 : 0,
            ));

            if ($use_source_image && $source_image_url !== '' && !Content_Rank_Generator::is_probably_bad_featured_image_url($source_image_url, $title)) {
                $source_result = Content_Rank_Generator::download_and_set_featured_image_from_url(
                    $post_id,
                    $source_image_url,
                    $title,
                    'source',
                    '',
                    ''
                );
                if (!is_wp_error($source_result) && intval($source_result) > 0) {
                    error_log('[Content Rank][thumbnail] fonte concluida attachment=' . intval($source_result));
                    update_post_meta($post_id, '_content_rank_source_image_url', esc_url_raw($source_image_url));
                    Content_Rank_Generator::log_image_debug('thumbnail_helper_source_done', array(
                        'post_id' => $post_id,
                        'source_image_url' => $source_image_url,
                    ));
                    return intval($source_result);
                }
            }

            if ($use_pexels) {
                $pexels_result = Content_Rank_Generator::download_and_set_featured_image_from_pexels(
                    $post_id,
                    $generator,
                    $item,
                    $article,
                    $is_keyword_list
                );
                if (!is_wp_error($pexels_result) && intval($pexels_result) > 0) {
                    error_log('[Content Rank][thumbnail] Pexels concluida attachment=' . intval($pexels_result));
                    return intval($pexels_result);
                }
            } elseif ($use_dalle) {
                $dalle_result = Content_Rank_Generator::download_and_set_featured_image_from_dalle(
                    $post_id,
                    $generator,
                    $item,
                    $article,
                    $is_keyword_list
                );
                if (!is_wp_error($dalle_result) && intval($dalle_result) > 0) {
                    error_log('[Content Rank][thumbnail] Dall-e concluida attachment=' . intval($dalle_result));
                    return intval($dalle_result);
                }
            }

            $fallback_id = Content_Rank_Generator::create_placeholder_image_attachment(
                $post_id,
                $title,
                'fallback',
                !empty($item['keyword']) ? $item['keyword'] : '',
                ''
            );

            Content_Rank_Generator::log_image_debug('thumbnail_helper_fallback', array(
                'post_id' => $post_id,
                'attachment_id' => intval($fallback_id),
                'image_source_mode' => $image_source_mode,
            ));
            error_log('[Content Rank][thumbnail] fallback attachment=' . intval($fallback_id));

            return intval($fallback_id) > 0 ? intval($fallback_id) : false;
        }

        private static function create_tmdb_composite_thumbnail($post_id, $term, $movies, $bg_color = '#c91414')
        {
            error_log('[Content Rank][thumbnail] editor TMDB iniciado');
            if (!function_exists('imagecreatetruecolor') || !function_exists('imagecreatefromstring')) {
                return new WP_Error('content_rank_tmdb_gd_missing', 'A extensao GD do PHP nao esta disponivel para montar a thumbnail.');
            }

            $movies = array_values(array_filter(array_slice((array) $movies, 0, 5), function ($movie) {
                return is_array($movie) && !empty($movie['poster_url']);
            }));
            if (empty($movies)) {
                return new WP_Error('content_rank_tmdb_no_posters', 'Nenhum poster do TMDB foi encontrado para montar a thumbnail.');
            }

            $width = 1200;
            $height = 675;
            $band_height = min(202, (int) floor($height * 0.30));
            $canvas = imagecreatetruecolor($width, $height);
            $bg_color = Content_Rank_Generator::normalize_hex_color($bg_color);
            $red = hexdec(substr($bg_color, 1, 2));
            $green = hexdec(substr($bg_color, 3, 2));
            $blue = hexdec(substr($bg_color, 5, 2));
            $base_color = imagecolorallocate($canvas, $red, $green, $blue);
            imagefill($canvas, 0, 0, $base_color);
            $panel_width = (int) floor($width / count($movies));
            $loaded = 0;

            foreach ($movies as $index => $movie) {
                $poster_url = esc_url_raw((string) $movie['poster_url']);
                error_log('[Content Rank][thumbnail] editor baixando ' . ($index + 1) . '/' . count($movies) . ' ' . $poster_url);
                $response = wp_remote_get($poster_url, array('timeout' => 20));
                if (is_wp_error($response)) {
                    error_log('[Content Rank][thumbnail] editor download falhou ' . $response->get_error_message());
                    continue;
                }
                $image = @imagecreatefromstring(wp_remote_retrieve_body($response));
                if (!$image) {
                    error_log('[Content Rank][thumbnail] editor poster invalido');
                    continue;
                }

                $target_width = $index === count($movies) - 1 ? $width - ($panel_width * $index) : $panel_width;
                $source_width = imagesx($image);
                $source_height = imagesy($image);
                $target_ratio = $target_width / $height;
                $source_ratio = $source_width / max(1, $source_height);
                if ($source_ratio > $target_ratio) {
                    $crop_height = $source_height;
                    $crop_width = (int) floor($source_height * $target_ratio);
                    $source_x = (int) floor(($source_width - $crop_width) / 2);
                    $source_y = 0;
                } else {
                    $crop_width = $source_width;
                    $crop_height = (int) floor($source_width / $target_ratio);
                    $source_x = 0;
                    $source_y = (int) floor(($source_height - $crop_height) / 2);
                }
                imagecopyresampled($canvas, $image, $panel_width * $index, 0, $source_x, $source_y, $target_width, $height, $crop_width, $crop_height);
                imagedestroy($image);
                $loaded++;
            }

            if ($loaded === 0) {
                imagedestroy($canvas);
                return new WP_Error('content_rank_tmdb_thumbnail_failed', 'Nao foi possivel baixar os posters do TMDB.');
            }

            imagealphablending($canvas, true);
            for ($step = 0; $step < 50; $step++) {
                $alpha = 100 - (int) floor(($step / 50) * 75);
                $shadow = imagecolorallocatealpha($canvas, $red, $green, $blue, $alpha);
                imagefilledrectangle($canvas, 0, $height - $band_height - 50 + $step, $width, $height - $band_height - 49 + $step, $shadow);
            }
            imagefilledrectangle($canvas, 0, $height - $band_height, $width, $height, $base_color);

            if (!function_exists('wp_tempnam')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $tmp = function_exists('wp_tempnam') ? wp_tempnam('content-rank-tmdb-thumbnail.jpg') : tempnam(sys_get_temp_dir(), 'content-rank-tmdb-');
            if (!$tmp || !imagejpeg($canvas, $tmp, 88)) {
                imagedestroy($canvas);
                return new WP_Error('content_rank_tmdb_thumbnail_save_failed', 'Nao foi possivel salvar a thumbnail composta.');
            }
            imagedestroy($canvas);

            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attachment_id = media_handle_sideload(array(
                'name' => sanitize_title($term) . '-tmdb-thumbnail.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => $tmp,
                'error' => 0,
                'size' => filesize($tmp),
            ), intval($post_id), 'Thumbnail composta TMDB - ' . $term);
            error_log('[Content Rank][thumbnail] editor sideload=' . (is_wp_error($attachment_id) ? $attachment_id->get_error_message() : intval($attachment_id)));
            if (is_wp_error($attachment_id)) {
                @unlink($tmp);
            }
            return $attachment_id;
        }

        private static function get_cached_source_html($item)
        {
            $cached_html = !empty($item['source_page_html'])
                ? (string) $item['source_page_html']
                : '';
            $source_url = !empty($item['permalink'])
                ? trim((string) $item['permalink'])
                : (!empty($item['source_url']) ? trim((string) $item['source_url']) : '');

            // The article HTML may be filtered and therefore omit the page head.
            // Fetch the raw cached page when the cached fragment has no OG image.
            if ($cached_html !== '' && self::extract_og_image_url($cached_html, $source_url) !== '') {
                return $cached_html;
            }

            if ($source_url !== '') {
                $source_html = Content_Rank_Generator_Helper::fetch_source_page_html(
                    $source_url,
                    5,
                    'thumbnail_og'
                );
                if ($source_html !== '') {
                    return (string) $source_html;
                }
            }

            return $cached_html;
        }

        private static function extract_og_image_url($html, $base_url = '')
        {
            $html = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
            if (trim($html) === '') {
                return '';
            }

            $previous_state = libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $loaded = @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            libxml_clear_errors();
            libxml_use_internal_errors($previous_state);
            if (!$loaded) {
                return '';
            }

            foreach ($dom->getElementsByTagName('meta') as $meta_node) {
                if (!($meta_node instanceof DOMElement) || !$meta_node->hasAttribute('content')) {
                    continue;
                }

                $property = '';
                if ($meta_node->hasAttribute('property')) {
                    $property = strtolower(trim((string) $meta_node->getAttribute('property')));
                } elseif ($meta_node->hasAttribute('name')) {
                    $property = strtolower(trim((string) $meta_node->getAttribute('name')));
                }
                if (!in_array($property, array('og:image', 'og:image:url'), true)) {
                    continue;
                }

                $image_url = Content_Rank_Generator::resolve_url_against_base(
                    trim((string) $meta_node->getAttribute('content')),
                    $base_url
                );
                if ($image_url !== '') {
                    return $image_url;
                }
            }

            return '';
        }
    }
}
