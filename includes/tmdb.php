<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Content_Rank_TMDB
{
    public static function localize_article_movie_titles($generator, $item, $article)
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
        foreach (preg_split('/\R/u', $source_titles) as $source_title) {
            $query = self::normalize_source_title($source_title);
            if ($query === '') {
                continue;
            }

            $search = self::search_movie($query, $language);
            if (empty($search['results'][0]) && preg_match('/\s[-|:–—]\s/u', $query)) {
                $short_query = trim((string) preg_replace('/\s[-|:–—]\s.*$/u', '', $query));
                if ($short_query !== '' && $short_query !== $query) {
                    $search = self::search_movie($short_query, $language);
                    $query = $short_query;
                }
            }
            if (empty($search['results'][0]) && $language !== 'en-US') {
                $search = self::search_movie($query, 'en-US');
            }
            if (empty($search['results'][0]) || empty($search['results'][0]['id'])) {
                error_log('[Content Rank][tmdb] titulo nao localizado ' . wp_json_encode(array(
                    'query' => $query,
                    'language' => $language,
                    'error' => isset($search['error']) ? $search['error'] : 'no results',
                ), JSON_UNESCAPED_UNICODE));
                continue;
            }

            $result = $search['results'][0];
            $details = self::movie_details(intval($result['id']), $language);
            $localized_title = !empty($details['title']) ? (string) $details['title'] : (string) $result['title'];
            if ($localized_title === '' || strcasecmp($query, $localized_title) === 0) {
                continue;
            }

            $replacements[$query] = $localized_title;
            if (!empty($result['original_title'])) {
                $replacements[(string) $result['original_title']] = $localized_title;
            }
        }

        if (empty($replacements)) {
            return $article;
        }

        foreach (array('title', 'excerpt', 'meta_description', 'content_html') as $field) {
            if (empty($article[$field]) || !is_string($article[$field])) {
                continue;
            }
            $article[$field] = self::replace_titles($article[$field], $replacements);
        }

        error_log('[Content Rank][tmdb] titulos substituidos ' . wp_json_encode($replacements, JSON_UNESCAPED_UNICODE));
        return $article;
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

    private static function search_movie($query, $language)
    {
        return self::request('search/movie', array(
            'query' => $query,
            'language' => $language,
            'region' => 'BR',
            'include_adult' => 'false',
            'page' => 1,
        ));
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
