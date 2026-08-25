<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Content_Rank_TMDB
{
    public static function localize_article_movie_titles($generator, &$item, $article, $apply_replacements = true)
    {
        $generator = is_array($generator) ? $generator : array();
        $item = is_array($item) ? $item : array();
        $article = is_array($article) ? $article : array();

        $source_titles = Content_Rank_Generator_Helper::build_source_outline_titles_for_prompt($item, 10);
        if ($source_titles === '') {
            return $article;
        }

        $language = self::language_from_generator($generator);
        $replacements = array();
        $movies = array();
        foreach (preg_split('/\R/u', $source_titles) as $source_title) {
            $source_year = self::extract_source_year($source_title);
            $query = self::normalize_source_title($source_title);
            if ($query === '') {
                continue;
            }
            $source_query = $query;

            $search = self::search_movie($query, $language, $source_year);
            if (empty($search['results'][0]) && preg_match('/\s[-|:–—]\s/u', $query)) {
                $short_query = trim((string) preg_replace('/\s[-|:–—]\s.*$/u', '', $query));
                if ($short_query !== '' && $short_query !== $query) {
                    $search = self::search_movie($short_query, $language, $source_year);
                    $query = $short_query;
                }
            }
            if (empty($search['results'][0]) && $language !== 'en-US') {
                $search = self::search_movie($query, 'en-US', $source_year);
            }
            if (empty($search['results'][0]) || empty($search['results'][0]['id'])) {
                error_log('[Content Rank][tmdb] titulo nao localizado ' . wp_json_encode(array(
                    'query' => $query,
                    'language' => $language,
                    'error' => isset($search['error']) ? $search['error'] : 'no results',
                ), JSON_UNESCAPED_UNICODE));
                continue;
            }

            $result = self::choose_search_result($search['results'], $query, $source_year);
            if (empty($result['id'])) {
                continue;
            }
            $details = self::movie_details(intval($result['id']), $language);
            $localized_title = !empty($details['title']) ? (string) $details['title'] : (string) $result['title'];
            if ($language === 'pt-BR' && self::titles_match($localized_title, $query)) {
                $alternative_title = self::get_brazilian_alternative_title(intval($result['id']));
                if ($alternative_title !== '') {
                    $localized_title = $alternative_title;
                }
            }
            $poster_path = !empty($details['poster_path']) ? $details['poster_path'] : (!empty($result['poster_path']) ? $result['poster_path'] : '');
            $movies[] = array(
                'id' => intval($result['id']),
                'title' => $localized_title,
                'original_title' => !empty($details['original_title']) ? $details['original_title'] : (!empty($result['original_title']) ? $result['original_title'] : ''),
                'year' => !empty($details['release_date']) ? substr((string) $details['release_date'], 0, 4) : (!empty($result['release_date']) ? substr((string) $result['release_date'], 0, 4) : ''),
                'poster_url' => $poster_path !== '' ? 'https://image.tmdb.org/t/p/w780' . $poster_path : '',
                'source_query' => $source_query,
            );
            if ($localized_title === '' || self::titles_match($query, $localized_title)) {
                continue;
            }

            $replacements[$source_query] = $localized_title;
            $replacements[$query] = $localized_title;
            if (!empty($result['original_title'])) {
                $replacements[(string) $result['original_title']] = $localized_title;
            }
            if (!empty($details['original_title'])) {
                $replacements[(string) $details['original_title']] = $localized_title;
            }
            foreach (array($source_query, $query, $result['original_title'] ?? '', $details['original_title'] ?? '') as $title_variant) {
                $title_variant = trim((string) $title_variant);
                $without_punctuation = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title_variant));
                if ($without_punctuation !== '' && $without_punctuation !== $title_variant) {
                    $replacements[$without_punctuation] = $localized_title;
                }
            }
        }

        if (!$apply_replacements || empty($replacements)) {
            if (!empty($movies)) {
                $item['tmdb_movies'] = $movies;
            }
            return $article;
        }

        $item['tmdb_movies'] = $movies;

        foreach (array('title', 'excerpt', 'meta_description', 'content_html') as $field) {
            if (empty($article[$field]) || !is_string($article[$field])) {
                continue;
            }
            $article[$field] = self::replace_titles($article[$field], $replacements);
        }

        error_log('[Content Rank][tmdb] titulos substituidos ' . wp_json_encode($replacements, JSON_UNESCAPED_UNICODE));
        return $article;
    }

    public static function create_composite_thumbnail_for_post($post_id, $term, $movies, $bg_color = '#c91414')
    {
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
        $rgb = self::hex_to_rgb($bg_color);
        $base_color = imagecolorallocate($canvas, $rgb[0], $rgb[1], $rgb[2]);
        imagefill($canvas, 0, 0, $base_color);
        $panel_width = (int) floor($width / count($movies));
        $loaded = 0;

        foreach ($movies as $index => $movie) {
            $response = wp_remote_get(esc_url_raw($movie['poster_url']), array('timeout' => 20));
            if (is_wp_error($response)) {
                continue;
            }
            $image = @imagecreatefromstring(wp_remote_retrieve_body($response));
            if (!$image) {
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
            $shadow = imagecolorallocatealpha($canvas, $rgb[0], $rgb[1], $rgb[2], $alpha);
            imagefilledrectangle($canvas, 0, $height - $band_height - 50 + $step, $width, $height - $band_height - 49 + $step, $shadow);
        }
        imagefilledrectangle($canvas, 0, $height - $band_height, $width, $height, $base_color);

        $tmp = wp_tempnam('content-rank-tmdb-thumbnail.jpg');
        if (!$tmp || !imagejpeg($canvas, $tmp, 88)) {
            imagedestroy($canvas);
            return new WP_Error('content_rank_tmdb_thumbnail_save_failed', 'Nao foi possivel salvar a thumbnail composta.');
        }
        imagedestroy($canvas);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_sideload(array(
            'name' => sanitize_title($term) . '-tmdb-thumbnail.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tmp,
            'error' => 0,
            'size' => filesize($tmp),
        ), intval($post_id), 'Thumbnail composta TMDB - ' . $term);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
        }
        return $attachment_id;
    }

    private static function hex_to_rgb($color)
    {
        $color = Content_Rank_Generator::normalize_hex_color($color);
        return array(hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2)));
    }

    private static function extract_source_year($title)
    {
        return preg_match('/\b((?:19|20)\d{2})\b/u', (string) $title, $matches)
            ? (int) $matches[1]
            : 0;
    }

    private static function normalize_source_title($title)
    {
        $title = trim(wp_strip_all_tags((string) $title));
        $title = preg_replace('/^\s*\d{1,3}\s*[.):-]\s*/u', '', $title);
        $title = preg_replace('/\s*\((?:19|20)\d{2}\)\s*/u', ' ', $title);
        $title = trim($title, " \t\n\r\0\x0B'\"“”‘’");
        return trim($title);
    }

    private static function replace_titles($text, $replacements)
    {
        foreach ($replacements as $source => $localized) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($source, '/') . '(?![\p{L}\p{N}])/iu';
            $text = preg_replace($pattern, $localized, $text);
        }
        return $text;
    }

    private static function titles_match($left, $right)
    {
        $normalize = function ($value) {
            $value = remove_accents(wp_strip_all_tags((string) $value));
            return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));
        };
        return $normalize($left) !== '' && $normalize($left) === $normalize($right);
    }

    private static function choose_search_result($results, $query, $year)
    {
        $results = is_array($results) ? $results : array();
        $best = array();
        $best_score = -1;
        foreach ($results as $result) {
            if (!is_array($result) || empty($result['id'])) {
                continue;
            }
            $result_year = !empty($result['release_date']) ? (int) substr((string) $result['release_date'], 0, 4) : 0;
            $score = 0;
            if ($year > 0 && $result_year === $year) {
                $score += 100;
            } elseif ($year > 0) {
                $score -= 50;
            }
            if (!empty($result['title']) && self::titles_match($query, $result['title'])) {
                $score += 50;
            }
            if (!empty($result['original_title']) && self::titles_match($query, $result['original_title'])) {
                $score += 50;
            }
            if ($score > $best_score) {
                $best = $result;
                $best_score = $score;
            }
        }
        return $best;
    }

    private static function get_brazilian_alternative_title($movie_id)
    {
        $response = self::request('movie/' . absint($movie_id) . '/alternative_titles');
        $titles = !empty($response['titles']) && is_array($response['titles']) ? $response['titles'] : array();
        foreach ($titles as $title) {
            if (!empty($title['iso_3166_1']) && strtoupper((string) $title['iso_3166_1']) === 'BR' && !empty($title['title'])) {
                return (string) $title['title'];
            }
        }
        return '';
    }

    private static function language_from_generator($generator)
    {
        $language = !empty($generator['generation_language']) ? strtolower(remove_accents((string) $generator['generation_language'])) : '';
        if (strpos($language, 'ingles') !== false || strpos($language, 'english') !== false) {
            return 'en-US';
        }
        if (strpos($language, 'espanhol') !== false || strpos($language, 'spanish') !== false) {
            return 'es-ES';
        }
        return 'pt-BR';
    }

    private static function search_movie($query, $language, $year = 0)
    {
        $args = array(
            'query' => $query,
            'language' => $language,
            'region' => 'BR',
            'include_adult' => 'false',
            'page' => 1,
        );
        if ((int) $year > 0) {
            $args['year'] = (int) $year;
        }
        return self::request('search/movie', $args);
    }

    private static function movie_details($movie_id, $language)
    {
        return self::request('movie/' . absint($movie_id), array(
            'language' => $language,
            'region' => 'BR',
        ));
    }

    private static function request($path, $query_args = array())
    {
        $settings = Content_Rank_Generator::get_settings();
        $token = trim((string) ($settings['tmdb_read_access_token'] ?? ''));
        $api_key = trim((string) ($settings['tmdb_api_key'] ?? ''));
        if ($token === '' && $api_key === '') {
            return array('error' => 'Configure o token ou a API key do TMDB.');
        }

        $headers = array('Accept' => 'application/json');
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        } else {
            $query_args['api_key'] = $api_key;
        }

        $response = wp_remote_get(add_query_arg($query_args, 'https://api.themoviedb.org/3/' . ltrim($path, '/')), array(
            'timeout' => 15,
            'headers' => $headers,
        ));
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($body)) {
            return array('error' => 'TMDB retornou HTTP ' . intval($status) . '.');
        }
        return $body;
    }
}
