<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Experimental movie-title extraction boundary.
 *
 * This first version only asks the AI for literal source titles. TMDB
 * localization is intentionally a separate step to be added later.
 */
final class Content_Rank_TMDB
{
    public static function extract_item_movie_titles($generator, $item)
    {
        $generator = is_array($generator) ? $generator : array();
        $item = is_array($item) ? $item : array();
        if (empty($item) || !empty($item['tmdb_movie_queries'])) {
            return $item;
        }

        $source_text = '';
        foreach (array('source_page_content', 'source_page_content_html', 'source_page_html', 'content', 'excerpt', 'title') as $key) {
            if (empty($item[$key])) {
                continue;
            }
            $source_text = wp_strip_all_tags((string) $item[$key]);
            $source_text = trim(preg_replace('/\s+/u', ' ', $source_text));
            if ($source_text !== '') {
                break;
            }
        }

        if ($source_text === '') {
            return $item;
        }

        $source_text = function_exists('mb_substr')
            ? mb_substr($source_text, 0, 14000, 'UTF-8')
            : substr($source_text, 0, 14000);
        $movie_limit = 10;
        $normalized_source = function_exists('remove_accents') ? remove_accents($source_text) : $source_text;
        if (preg_match('/\b(10|[1-9])\b.{0,40}\bfilmes?\b/i', $normalized_source, $matches)) {
            $movie_limit = min(10, max(1, intval($matches[1])));
        }

        $schema = array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'movies' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => array(
                            'query' => array('type' => 'string'),
                        ),
                        'required' => array('query'),
                    ),
                ),
            ),
            'required' => array('movies'),
        );

        $prompt = 'Extraia somente os titulos de filmes realmente escritos no conteudo abaixo. '
            . 'Esta e uma etapa tecnica anterior a uma busca no TMDB. '
            . 'O campo query deve copiar literalmente o nome encontrado na fonte. '
            . 'Nao traduza, nao corrija, nao localize e nao substitua por outro idioma. '
            . 'Nao retorne evidence, descricoes, atores, personagens, series ou plataformas. '
            . 'Ignore termos genericos como "filmes da Netflix". Preserve a ordem e retorne no maximo '
            . $movie_limit . ' itens. Retorne somente o JSON solicitado.'
            . "\n\nCONTEUDO:\n" . $source_text;

        $extracted = Content_Rank_Generator::request_openai_json($generator, $prompt, array(
            'stage' => 'tmdb_movie_title_extraction',
            'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
            'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
            'item_title' => !empty($item['title']) ? $item['title'] : '',
            'skip_language_instruction' => 1,
            'response_schema' => $schema,
            'response_schema_name' => 'tmdb_movie_title_extraction',
        ));

        if (is_wp_error($extracted) || empty($extracted['movies']) || !is_array($extracted['movies'])) {
            return $item;
        }

        $queries = array();
        foreach (array_slice($extracted['movies'], 0, $movie_limit) as $movie) {
            $query = is_array($movie) && !empty($movie['query'])
                ? sanitize_text_field((string) $movie['query'])
                : '';
            if ($query === '' || in_array($query, $queries, true)) {
                continue;
            }
            $queries[] = $query;
        }

        if (!empty($queries)) {
            $item['tmdb_movie_queries'] = $queries;
        }

        error_log('[Content Rank][tmdb-extraction] ' . wp_json_encode(array(
            'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
            'queries' => $queries,
        ), JSON_UNESCAPED_UNICODE));

        return $item;
    }
}
