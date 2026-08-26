<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Content_Rank_TMDB
{
    public static function translate_source_outline_titles($generator, &$item, $source_titles)
    {
        $generator = is_array($generator) ? $generator : array();
        $item = is_array($item) ? $item : array();
        $source_titles = trim((string) $source_titles);
        if ($source_titles === '') {
            return '';
        }

        self::localize_article_movie_titles($generator, $item, array(), false, $source_titles);
        error_log('[Content Rank][tmdb-outline] resultados TMDB: ' . (!empty($item['tmdb_movies']) && is_array($item['tmdb_movies']) ? count($item['tmdb_movies']) : 0));
        $localized_by_source = array();
        foreach (!empty($item['tmdb_movies']) && is_array($item['tmdb_movies']) ? $item['tmdb_movies'] : array() as $movie) {
            if (!empty($movie['source_query']) && !empty($movie['title'])) {
                $localized_by_source[self::normalize_title_key($movie['source_query'])] = array(
                    'title' => (string) $movie['title'],
                    'year' => !empty($movie['year']) ? (string) $movie['year'] : '',
                );
            }
        }

        $translated = array();
        foreach (preg_split('/\R/u', $source_titles) as $line) {
            $query = self::normalize_source_title($line);
            $key = self::normalize_title_key($query);
            $movie = isset($localized_by_source[$key]) ? $localized_by_source[$key] : array();
            $title = !empty($movie['title']) ? $movie['title'] : $query;
            $year = !empty($movie['year']) ? $movie['year'] : self::extract_source_year($line);
            if ($title !== '') {
                $translated[] = sprintf('%02d. %s%s', count($translated) + 1, $title, $year !== '' ? ' (' . $year . ')' : '');
            }
        }
        return implode("\n", $translated);
    }

    public static function localize_article_movie_titles($generator, &$item, $article, $apply_replacements = true, $source_titles_override = null)
    {
        $generator = is_array($generator) ? $generator : array();
        $item = is_array($item) ? $item : array();
        $article = is_array($article) ? $article : array();

        $source_titles = is_string($source_titles_override)
            ? trim($source_titles_override)
            : Content_Rank_Generator_Helper::build_raw_source_outline_titles_for_prompt($item, 0);
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
                'thumbnail_url' => $poster_path !== '' ? 'https://image.tmdb.org/t/p/w342' . $poster_path : '',
                'backdrop_url' => !empty($details['backdrop_path']) ? 'https://image.tmdb.org/t/p/w1280' . (string) $details['backdrop_path'] : (!empty($result['backdrop_path']) ? 'https://image.tmdb.org/t/p/w1280' . (string) $result['backdrop_path'] : ''),
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

    public static function build_content_image_sections($movies)
    {
        $sections = array();
        foreach ((array) $movies as $movie) {
            if (!is_array($movie) || empty($movie['title'])) {
                continue;
            }
            $image_url = !empty($movie['thumbnail_url'])
                ? (string) $movie['thumbnail_url']
                : (!empty($movie['poster_url']) ? (string) $movie['poster_url'] : '');
            if ($image_url === '') {
                continue;
            }
            $sections[] = array(
                'h2' => (string) $movie['title'],
                'images' => array(array(
                    'url' => esc_url_raw($image_url),
                    'alt' => (string) $movie['title'],
                )),
            );
        }
        return $sections;
    }

    public static function find_movies_for_thumbnail($query = '', $genre_id = 0, $limit = 5)
    {
        $query = trim((string) $query);
        $genre_id = absint($genre_id);
        $limit = min(5, max(1, absint($limit)));
        $results = array();

        if ($query !== '') {
            $search = self::request('search/movie', array(
                'query' => $query,
                'language' => 'pt-BR',
                'region' => 'BR',
                'include_adult' => 'false',
                'page' => 1,
            ));
            $results = !empty($search['results']) && is_array($search['results']) ? $search['results'] : array();
            if ($genre_id > 0) {
                $filtered = array_filter($results, function ($movie) use ($genre_id) {
                    return is_array($movie) && !empty($movie['genre_ids']) && in_array($genre_id, array_map('absint', (array) $movie['genre_ids']), true);
                });
                if (!empty($filtered)) {
                    $results = array_values($filtered);
                }
            }
        } else {
            $discover = self::request('discover/movie', array(
                'language' => 'pt-BR',
                'region' => 'BR',
                'include_adult' => 'false',
                'sort_by' => 'popularity.desc',
                'with_genres' => $genre_id > 0 ? (string) $genre_id : '',
                'page' => 1,
            ));
            $results = !empty($discover['results']) && is_array($discover['results']) ? $discover['results'] : array();
        }

        $movies = array();
        foreach ($results as $result) {
            if (!is_array($result) || empty($result['id']) || empty($result['poster_path'])) {
                continue;
            }
            $movies[] = array(
                'id' => absint($result['id']),
                'title' => !empty($result['title']) ? (string) $result['title'] : '',
                'original_title' => !empty($result['original_title']) ? (string) $result['original_title'] : '',
                'year' => !empty($result['release_date']) ? substr((string) $result['release_date'], 0, 4) : '',
                'poster_url' => 'https://image.tmdb.org/t/p/w780' . (string) $result['poster_path'],
                'thumbnail_url' => 'https://image.tmdb.org/t/p/w342' . (string) $result['poster_path'],
                'backdrop_url' => !empty($result['backdrop_path']) ? 'https://image.tmdb.org/t/p/w1280' . (string) $result['backdrop_path'] : '',
                'source_query' => $query,
            );
            if (count($movies) >= $limit) {
                break;
            }
        }

        return $movies;
    }

    public static function create_composite_thumbnail_for_post($post_id, $term, $movies, $bg_color = '#c91414', $layout = 'rotate')
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
        $is_single = count($movies) === 1;
        $movie_count = count($movies);
        $layout = sanitize_key((string) $layout);
        if ($layout === 'rotate') {
            $layouts = array('standard', 'skew', 'center_focus');
            $layout = function_exists('wp_rand')
                ? $layouts[wp_rand(0, count($layouts) - 1)]
                : $layouts[array_rand($layouts)];
        }
        if (!in_array($layout, array('standard', 'skew', 'center_focus'), true) || $is_single) {
            $layout = 'standard';
        }
        $canvas = imagecreatetruecolor($width, $height);
        $rgb = self::hex_to_rgb($bg_color);
        $base_color = imagecolorallocate($canvas, $rgb[0], $rgb[1], $rgb[2]);
        imagefill($canvas, 0, 0, $base_color);
        if ($layout === 'skew' && !empty($movies[0])) {
            $background_url = !empty($movies[0]['backdrop_url'])
                ? (string) $movies[0]['backdrop_url']
                : (string) $movies[0]['poster_url'];
            $background_response = wp_remote_get(esc_url_raw($background_url), array('timeout' => 20));
            $background_image = !is_wp_error($background_response)
                ? @imagecreatefromstring(wp_remote_retrieve_body($background_response))
                : false;
            if ($background_image) {
                $background_width = imagesx($background_image);
                $background_height = imagesy($background_image);
                $background_ratio = $width / $height;
                $source_ratio = $background_width / max(1, $background_height);
                if ($source_ratio > $background_ratio) {
                    $crop_height = $background_height;
                    $crop_width = (int) floor($background_height * $background_ratio);
                    $source_x = (int) floor(($background_width - $crop_width) / 2);
                    $source_y = 0;
                } else {
                    $crop_width = $background_width;
                    $crop_height = (int) floor($background_width / $background_ratio);
                    $source_x = 0;
                    $source_y = (int) floor(($background_height - $crop_height) / 2);
                }
                imagecopyresampled($canvas, $background_image, 0, 0, $source_x, $source_y, $width, $height, $crop_width, $crop_height);
                imagedestroy($background_image);
                $background_overlay = imagecolorallocatealpha($canvas, $rgb[0], $rgb[1], $rgb[2], 78);
                imagefilledrectangle($canvas, 0, 0, $width, $height, $background_overlay);
            }
        }
        $gap = $is_single ? 0 : 6;
        $available_width = $width - ($gap * ($movie_count - 1));
        $panel_widths = array_fill(0, $movie_count, (int) floor($available_width / $movie_count));
        if ($layout === 'center_focus' && $movie_count >= 3) {
            $center = (int) floor($available_width * 0.36);
            $side_width = (int) floor(($available_width - $center) / ($movie_count - 1));
            $panel_widths = array_fill(0, $movie_count, $side_width);
            $panel_widths[(int) floor($movie_count / 2)] = $center;
            $panel_widths[$movie_count - 1] += $available_width - array_sum($panel_widths);
        }
        $loaded = 0;
        $cursor_x = 0;

        foreach ($movies as $index => $movie) {
            $image_url = $is_single && !empty($movie['backdrop_url'])
                ? (string) $movie['backdrop_url']
                : (string) $movie['poster_url'];
            error_log('[Content Rank][thumbnail] TMDB baixando ' . ($is_single && !empty($movie['backdrop_url']) ? 'backdrop' : 'poster') . ' ' . ($index + 1) . '/' . count($movies) . ' ' . esc_url_raw($image_url));
            $response = wp_remote_get(esc_url_raw($image_url), array('timeout' => 20));
            if (is_wp_error($response)) {
                error_log('[Content Rank][thumbnail] TMDB download falhou ' . $response->get_error_message());
                continue;
            }
            $image = @imagecreatefromstring(wp_remote_retrieve_body($response));
            if (!$image) {
                error_log('[Content Rank][thumbnail] TMDB resposta nao e imagem');
                continue;
            }
            $target_x = $cursor_x;
            $target_width = $panel_widths[$index];
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
            if ($layout === 'skew') {
                $panel = imagecreatetruecolor($target_width, $height);
                imagecopyresampled($panel, $image, 0, 0, $source_x, $source_y, $target_width, $height, $crop_width, $crop_height);
                $skew = 22;
                for ($row = 0; $row < $height; $row++) {
                    $offset = (int) round($skew * (1 - ($row / max(1, $height - 1))));
                    imagecopy($canvas, $panel, $target_x + $offset, $row, 0, $row, $target_width, 1);
                }
                imagedestroy($panel);
            } else {
                imagecopyresampled($canvas, $image, $target_x, 0, $source_x, $source_y, $target_width, $height, $crop_width, $crop_height);
            }
            imagedestroy($image);
            $loaded++;
            $cursor_x += $target_width + $gap;
        }

        if ($loaded === 0) {
            imagedestroy($canvas);
            return new WP_Error('content_rank_tmdb_thumbnail_failed', 'Nao foi possivel baixar os posters do TMDB.');
        }

        imagealphablending($canvas, true);
        $gradient_height = $band_height;
        $gradient_start = $height - $gradient_height;
        for ($step = 0; $step < $gradient_height; $step++) {
            $progress = $step / max(1, $gradient_height - 1);
            // Use one smooth overlay: transparent at the top, 28% in the
            // middle and approximately 76% at the bottom.
            if ($progress <= 0.28) {
                $opacity = ($progress / 0.28) * 0.36;
            } else {
                $opacity = 0.36 + (($progress - 0.28) / 0.72) * 0.52;
            }
            $gd_alpha = 127 - (int) round($opacity * 127);
            $shadow = imagecolorallocatealpha($canvas, $rgb[0], $rgb[1], $rgb[2], max(0, min(127, $gd_alpha)));
            imagefilledrectangle($canvas, 0, $gradient_start + $step, $width, $gradient_start + $step, $shadow);
        }

        if ($layout === 'skew') {
            $skew = 22;
            $edge_color = imagecolorallocate($canvas, $rgb[0], $rgb[1], $rgb[2]);
            $cursor_x = 0;
            foreach ($panel_widths as $index => $panel_width) {
                $left = $cursor_x;
                $right = $cursor_x + $panel_width;
                imageline($canvas, $left + $skew, 0, $left, $height, $edge_color);
                imageline($canvas, $right + $skew, 0, $right, $height, $edge_color);
                $cursor_x += $panel_width + $gap;
            }
        }
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp = function_exists('wp_tempnam')
            ? wp_tempnam('content-rank-tmdb-thumbnail.jpg')
            : tempnam(sys_get_temp_dir(), 'content-rank-tmdb-');
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
        error_log('[Content Rank][thumbnail] TMDB sideload result=' . (is_wp_error($attachment_id) ? $attachment_id->get_error_message() : intval($attachment_id)));
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

    private static function normalize_title_key($title)
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', remove_accents((string) $title)));
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
