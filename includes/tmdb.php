<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Content_Rank_TMDB
{
    const TEST_TRANSIENT_PREFIX = 'content_rank_tmdb_test_';

    public function boot()
    {
        add_action('admin_menu', array($this, 'admin_menu'), 30);
        add_action('admin_post_content_rank_test_tmdb', array($this, 'handle_test'));
        add_action('admin_post_content_rank_generate_tmdb_post', array($this, 'handle_generate_post'));
    }

    public function admin_menu()
    {
        add_submenu_page('content-rank', 'TMDB Experimental', 'TMDB Experimental', 'manage_options', 'content-rank-tmdb', array($this, 'render_page'));
    }

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }
        $settings = Content_Rank_Generator::get_settings();
        $test = get_transient(self::TEST_TRANSIENT_PREFIX . get_current_user_id());
        if ($test !== false) {
            delete_transient(self::TEST_TRANSIENT_PREFIX . get_current_user_id());
        }
        echo '<div class="wrap"><h1>TMDB Experimental</h1><p>Teste a identificacao de titulos antes de conectar o TMDB aos geradores.</p>';
        if (empty($settings['tmdb_read_access_token']) && empty($settings['tmdb_api_key'])) {
            echo '<div class="notice notice-warning"><p>Configure o token do TMDB em Content Rank &gt; Configuracoes.</p></div>';
        }
        if (is_array($test) && !empty($test['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html($test['error']) . '</p></div>';
        }
        if (is_array($test) && !empty($test['success'])) {
            echo '<div class="notice notice-success"><p>' . esc_html($test['message']) . ' ';
            if (!empty($test['post_url'])) {
                echo '<a href="' . esc_url($test['post_url']) . '">Editar post</a>.';
            }
            echo '</p></div>';
            if (!empty($test['thumbnail_error'])) {
                echo '<div class="notice notice-warning"><p>Post criado, mas a thumbnail nao foi gerada: ' . esc_html($test['thumbnail_error']) . '</p></div>';
            }
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('content_rank_test_tmdb', 'content_rank_test_tmdb_nonce');
        echo '<input type="hidden" name="action" value="content_rank_test_tmdb" />';
        echo '<p><label for="content-rank-tmdb-query"><strong>Titulo para pesquisar</strong></label><br /><input id="content-rank-tmdb-query" type="text" name="query" class="regular-text" placeholder="The Hangover" required /></p>';
        echo '<p><button type="submit" class="button button-primary">Pesquisar no TMDB</button></p></form>';
        echo '<hr /><h2>Gerar post experimental</h2><p>Use um termo como <code>3 filmes de animacao recentes na Netflix</code>. O resultado sera salvo como rascunho.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('content_rank_generate_tmdb_post', 'content_rank_generate_tmdb_post_nonce');
        echo '<input type="hidden" name="action" value="content_rank_generate_tmdb_post" />';
        echo '<p><input type="text" name="term" class="regular-text" placeholder="3 filmes de animacao recentes na Netflix" required /> ';
        echo '<button type="submit" class="button button-primary">Gerar post e thumbnail</button></p></form>';
        if (is_array($test) && !empty($test['results'])) {
            echo '<h2>Resultados</h2><table class="widefat striped"><thead><tr><th>Poster</th><th>Titulo brasileiro</th><th>Titulo original</th><th>Ano</th><th>TMDB ID</th></tr></thead><tbody>';
            foreach ($test['results'] as $result) {
                echo '<tr><td>';
                if (!empty($result['poster_url'])) {
                    echo '<img src="' . esc_url($result['poster_url']) . '" alt="" style="width:60px;height:90px;object-fit:cover;" />';
                }
                echo '</td><td><strong>' . esc_html($result['title']) . '</strong></td><td>' . esc_html($result['original_title']) . '</td><td>' . esc_html($result['year']) . '</td><td>' . esc_html($result['id']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p style="margin-top:24px;color:#646970;">TMDB fornece os dados e imagens. Use a atribuicao exigida antes de qualquer uso publico.</p></div>';
    }

    public function handle_test()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }
        check_admin_referer('content_rank_test_tmdb', 'content_rank_test_tmdb_nonce');
        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        set_transient(self::TEST_TRANSIENT_PREFIX . get_current_user_id(), $this->search_movies($query), MINUTE_IN_SECONDS * 5);
        wp_safe_redirect(admin_url('admin.php?page=content-rank-tmdb'));
        exit;
    }

    public function handle_generate_post()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }
        check_admin_referer('content_rank_generate_tmdb_post', 'content_rank_generate_tmdb_post_nonce');

        $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
        $result = $this->generate_post_from_term($term);
        set_transient(self::TEST_TRANSIENT_PREFIX . get_current_user_id(), $result, MINUTE_IN_SECONDS * 10);
        wp_safe_redirect(admin_url('admin.php?page=content-rank-tmdb'));
        exit;
    }

    public static function resolve_item_movies($generator, $item)
    {
        $generator = is_array($generator) ? $generator : array();
        $item = is_array($item) ? $item : array();
        if (empty($item) || !empty($item['tmdb_movies'])) {
            return $item;
        }

        $source_text = '';
        foreach (array('source_page_content', 'source_page_content_html', 'source_page_html', 'content') as $key) {
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
            foreach (array('source_title', 'title', 'keyword') as $key) {
                if (!empty($item[$key])) {
                    $source_text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $item[$key])));
                    if ($source_text !== '') {
                        break;
                    }
                }
            }
        }
        $source_text = function_exists('mb_substr') ? mb_substr($source_text, 0, 14000, 'UTF-8') : substr($source_text, 0, 14000);
        if ($source_text === '') {
            return $item;
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
        $movie_limit = 10;
        $normalized_source_text = function_exists('remove_accents') ? remove_accents($source_text) : $source_text;
        if (preg_match('/\b(10|[1-9])\b.{0,40}\bfilmes?\b/i', $normalized_source_text, $count_match)) {
            $movie_limit = min(10, max(1, intval($count_match[1])));
        }
        $prompt = 'Extraia do conteudo abaixo somente os titulos de filmes realmente mencionados. Nao traduza, nao explique e nao gere descricoes: retorne apenas o nome de cada filme como aparece na fonte, no campo query. Nao inclua atores, diretores, personagens, series ou plataformas. Se o titulo da fonte for uma manchete como "Nome do filme + frase editorial", isole o nome do filme. Nunca retorne um termo generico como "filmes romanticos" ou "filmes da Netflix" como se fosse um filme. A pauta pede no maximo ' . $movie_limit . ' filmes; respeite esse limite e preserve a ordem em que aparecem na fonte. Retorne apenas JSON no formato solicitado.' . "\n\nCONTEUDO:\n" . $source_text;
        $extracted = Content_Rank_Generator::request_openai_json($generator, $prompt, array(
            'stage' => 'tmdb_movie_entity_extraction',
            'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
            'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
            'item_title' => !empty($item['title']) ? $item['title'] : '',
            'response_schema' => $schema,
            'response_schema_name' => 'tmdb_movie_entities',
        ));
        if (is_wp_error($extracted) || empty($extracted['movies']) || !is_array($extracted['movies'])) {
            return $item;
        }

        $service = new self();
        $movies = array();
        foreach (array_slice($extracted['movies'], 0, $movie_limit) as $entity) {
            $query = !empty($entity['query']) ? sanitize_text_field((string) $entity['query']) : '';
            if ($query === '') {
                continue;
            }
            $search = $service->search_movies($query, self::tmdb_language_from_generator($generator));
            if (empty($search['results'][0])) {
                continue;
            }
            $movie = $search['results'][0];
            $movie['source_query'] = $query;
            $movies[] = $movie;
        }
        if (!empty($movies)) {
            $item['tmdb_movies'] = $movies;
        }
        return $item;
    }

    public static function refresh_item_movies_language($generator, $item)
    {
        $generator = is_array($generator) ? $generator : array();
        $item = is_array($item) ? $item : array();
        if (empty($item['tmdb_movies']) || !is_array($item['tmdb_movies'])) {
            return $item;
        }

        $service = new self();
        $language = self::tmdb_language_from_generator($generator);
        $movies = array();
        foreach ($item['tmdb_movies'] as $movie) {
            if (!is_array($movie)) {
                continue;
            }
            $query = !empty($movie['source_query']) ? (string) $movie['source_query'] : (!empty($movie['original_title']) ? (string) $movie['original_title'] : (string) ($movie['title'] ?? ''));
            $query = trim($query);
            if ($query === '') {
                continue;
            }
            $search = $service->search_movies($query, $language);
            if (!empty($search['results'][0]) && is_array($search['results'][0])) {
                $localized = $search['results'][0];
                $localized['source_query'] = $query;
                $movies[] = $localized;
            } else {
                $movies[] = $movie;
            }
        }
        if (!empty($movies)) {
            $item['tmdb_movies'] = $movies;
        }
        return $item;
    }

    public static function create_composite_thumbnail_for_post($post_id, $term, $movies)
    {
        return (new self())->create_composite_thumbnail($post_id, $term, $movies);
    }

    private static function tmdb_language_from_generator($generator)
    {
        $language = !empty($generator['generation_language']) ? strtolower(remove_accents((string) $generator['generation_language'])) : '';
        if (strpos($language, 'ingles') !== false || strpos($language, 'english') !== false) {
            return 'en-US';
        }
        if (strpos($language, 'espanhol') !== false || strpos($language, 'spanish') !== false) {
            return 'es-ES';
        }
        if (strpos($language, 'frances') !== false || strpos($language, 'french') !== false) {
            return 'fr-FR';
        }
        if (strpos($language, 'italiano') !== false || strpos($language, 'italian') !== false) {
            return 'it-IT';
        }
        if (strpos($language, 'alemao') !== false || strpos($language, 'german') !== false) {
            return 'de-DE';
        }
        return 'pt-BR';
    }

    private function generate_post_from_term($term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return array('error' => 'Informe um termo para gerar o post.');
        }
        $movies = $this->discover_movies_from_term($term);
        if (is_wp_error($movies)) {
            return array('error' => $movies->get_error_message());
        }
        if (empty($movies)) {
            return array('error' => 'Nenhum filme foi encontrado para esse termo.');
        }

        $settings = Content_Rank_Generator::get_settings();
        $list = array('id' => 0, 'list_name' => 'TMDB Experimental');
        $generator = Content_Rank_Generator::bulk_build_manual_generator($list, array(
            'model' => $settings['default_model'],
            'temperature' => $settings['default_temperature'],
            'max_tokens' => $settings['default_max_tokens'],
            'post_status' => 'draft',
            'post_type' => 'post',
            'generation_language' => Content_Rank_Generator::get_default_generation_language(),
            'seo_enabled' => 1,
            'image_source_mode' => 'pexels',
            'source_content_images_enabled' => 0,
        ));
        $generator = Content_Rank_Generator::prepare_generator_record($generator);
        $item = array(
            'guid' => 'tmdb-test:' . md5($term . '|' . microtime(true)),
            'title' => $term,
            'keyword' => $term,
            'source_title' => $term,
            'source_url' => '',
            'permalink' => '',
            'excerpt' => '',
            'content' => '',
            'categories' => array(),
            'source_image_url' => '',
            'source_link_url' => '',
            'source_link_text' => '',
            'source_video_url' => '',
            'tmdb_movies' => $movies,
        );
        $post_id = Content_Rank_Generator::create_post_from_generator_item($generator, $item);
        if (is_wp_error($post_id)) {
            return array('error' => $post_id->get_error_message());
        }

        $thumbnail_id = $this->create_composite_thumbnail($post_id, $term, $movies);
        if (!is_wp_error($thumbnail_id) && intval($thumbnail_id) > 0) {
            set_post_thumbnail($post_id, intval($thumbnail_id));
        }
        return array(
            'success' => true,
            'message' => 'Post experimental criado como rascunho.',
            'post_id' => intval($post_id),
            'post_url' => get_edit_post_link($post_id, 'raw'),
            'thumbnail_id' => !is_wp_error($thumbnail_id) ? intval($thumbnail_id) : 0,
            'thumbnail_error' => is_wp_error($thumbnail_id) ? $thumbnail_id->get_error_message() : '',
        );
    }

    private function discover_movies_from_term($term)
    {
        $normalized = function_exists('remove_accents') ? remove_accents(mb_strtolower($term, 'UTF-8')) : strtolower($term);
        preg_match('/\b([1-9]|1[0-2])\b/', $normalized, $quantity_match);
        $quantity = !empty($quantity_match[1]) ? min(5, max(1, intval($quantity_match[1]))) : 3;

        $genres = array(
            'animacao' => 16,
            'acao' => 28,
            'aventura' => 12,
            'comedia' => 35,
            'drama' => 18,
            'terror' => 27,
            'suspense' => 53,
            'romance' => 10749,
            'fantasia' => 14,
            'ficcao cientifica' => 878,
            'documentario' => 99,
        );
        $genre_id = 0;
        foreach ($genres as $label => $id) {
            if (strpos($normalized, $label) !== false) {
                $genre_id = $id;
                break;
            }
        }

        $has_discovery_filter = $genre_id > 0 || strpos($normalized, 'netflix') !== false || strpos($normalized, 'recent') !== false;
        if (!$has_discovery_filter) {
            $search = $this->search_movies($term);
            return !empty($search['results']) ? array_slice($search['results'], 0, $quantity) : array();
        }

        $provider_id = 0;
        if (strpos($normalized, 'netflix') !== false) {
            $providers = $this->tmdb_request('watch/providers/movie', array('watch_region' => 'BR', 'language' => 'pt-BR'));
            if (is_wp_error($providers)) {
                return $providers;
            }
            foreach ((array) ($providers['results'] ?? array()) as $provider) {
                if (stripos((string) ($provider['provider_name'] ?? ''), 'netflix') !== false) {
                    $provider_id = intval($provider['provider_id'] ?? 0);
                    break;
                }
            }
        }

        $args = array(
            'language' => 'pt-BR',
            'region' => 'BR',
            'sort_by' => 'popularity.desc',
            'include_adult' => 'false',
            'page' => 1,
        );
        if ($genre_id > 0) {
            $args['with_genres'] = $genre_id;
        }
        if ($provider_id > 0) {
            $args['watch_region'] = 'BR';
            $args['with_watch_providers'] = $provider_id;
            $args['with_watch_monetization_types'] = 'flatrate';
        }
        if (strpos($normalized, 'recent') !== false) {
            $args['primary_release_date.gte'] = gmdate('Y-m-d', strtotime('-5 years'));
        }

        $data = $this->tmdb_request('discover/movie', $args);
        if (is_wp_error($data)) {
            return $data;
        }
        $results = array();
        foreach (array_slice((array) ($data['results'] ?? array()), 0, $quantity) as $movie) {
            $release_date = !empty($movie['release_date']) ? (string) $movie['release_date'] : '';
            $results[] = array(
                'id' => intval($movie['id'] ?? 0),
                'title' => (string) ($movie['title'] ?? ''),
                'original_title' => (string) ($movie['original_title'] ?? ''),
                'year' => $release_date !== '' ? substr($release_date, 0, 4) : '',
                'overview' => (string) ($movie['overview'] ?? ''),
                'poster_url' => !empty($movie['poster_path']) ? 'https://image.tmdb.org/t/p/w780' . $movie['poster_path'] : '',
            );
        }
        return $results;
    }

    private function tmdb_request($path, $args = array())
    {
        $settings = Content_Rank_Generator::get_settings();
        $read_access_token = trim((string) ($settings['tmdb_read_access_token'] ?? ''));
        $api_key = trim((string) ($settings['tmdb_api_key'] ?? ''));
        if ($read_access_token === '' && $api_key === '') {
            return new WP_Error('content_rank_tmdb_missing_credentials', 'Informe o token ou a API key do TMDB nas configuracoes.');
        }
        $headers = array('Accept' => 'application/json');
        if ($read_access_token !== '') {
            $headers['Authorization'] = 'Bearer ' . $read_access_token;
        } else {
            $args['api_key'] = $api_key;
        }
        $url = add_query_arg($args, 'https://api.themoviedb.org/3/' . ltrim($path, '/'));
        $response = wp_remote_get($url, array('timeout' => 20, 'headers' => $headers));
        if (is_wp_error($response)) {
            return $response;
        }
        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($body)) {
            return new WP_Error('content_rank_tmdb_request_failed', 'TMDB retornou uma resposta invalida (HTTP ' . intval($status) . ').');
        }
        return $body;
    }

    private function create_composite_thumbnail($post_id, $term, $movies)
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecreatefromstring')) {
            return new WP_Error('content_rank_tmdb_gd_missing', 'A extensao GD do PHP nao esta disponivel para montar a thumbnail.');
        }
        $movies = array_values(array_filter((array) $movies, function ($movie) {
            return is_array($movie) && !empty($movie['poster_url']);
        }));
        if (empty($movies)) {
            return new WP_Error('content_rank_tmdb_no_posters', 'Nenhum poster foi encontrado para montar a thumbnail.');
        }

        $canvas_width = 1200;
        $canvas_height = 675;
        $canvas = imagecreatetruecolor($canvas_width, $canvas_height);
        $background = imagecolorallocate($canvas, 12, 12, 14);
        imagefill($canvas, 0, 0, $background);
        $panel_width = (int) floor($canvas_width / count($movies));
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
            $source_width = imagesx($image);
            $source_height = imagesy($image);
            $target_width = $index === count($movies) - 1 ? $canvas_width - ($panel_width * $index) : $panel_width;
            $source_ratio = $source_width / max(1, $source_height);
            $target_ratio = $target_width / $canvas_height;
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
            imagecopyresampled($canvas, $image, $panel_width * $index, 0, $source_x, $source_y, $target_width, $canvas_height, $crop_width, $crop_height);
            imagedestroy($image);
            $loaded++;
        }

        if ($loaded === 0) {
            imagedestroy($canvas);
            return new WP_Error('content_rank_tmdb_thumbnail_failed', 'Nao foi possivel baixar os posters para a thumbnail.');
        }

        $tmp = wp_tempnam('content-rank-tmdb-thumbnail.jpg');
        if (!$tmp || !imagejpeg($canvas, $tmp, 88)) {
            imagedestroy($canvas);
            return new WP_Error('content_rank_tmdb_thumbnail_save_failed', 'Nao foi possivel salvar a thumbnail composta.');
        }
        imagedestroy($canvas);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $file = array(
            'name' => sanitize_title($term) . '-tmdb-thumbnail.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tmp,
            'error' => 0,
            'size' => filesize($tmp),
        );
        $attachment_id = media_handle_sideload($file, intval($post_id), 'Thumbnail composta TMDB - ' . $term);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
        }
        return $attachment_id;
    }

    private function search_movies($query, $language = 'pt-BR')
    {
        $query = trim((string) $query);
        $settings = Content_Rank_Generator::get_settings();
        $read_access_token = trim((string) ($settings['tmdb_read_access_token'] ?? ''));
        $api_key = trim((string) ($settings['tmdb_api_key'] ?? ''));
        if ($read_access_token === '' && $api_key === '') {
            return array('error' => 'Informe o token ou a API key do TMDB nas configuracoes.');
        }
        if ($query === '') {
            return array('error' => 'Informe um titulo para pesquisar.');
        }
        $query_args = array('query' => $query, 'language' => sanitize_text_field($language), 'region' => 'BR', 'include_adult' => 'false', 'page' => 1);
        $headers = array('Accept' => 'application/json');
        if ($read_access_token !== '') {
            $headers['Authorization'] = 'Bearer ' . $read_access_token;
        } else {
            $query_args['api_key'] = $api_key;
        }
        $url = add_query_arg($query_args, 'https://api.themoviedb.org/3/search/movie');
        $response = wp_remote_get($url, array('timeout' => 15, 'headers' => $headers));
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }
        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($body)) {
            return array('error' => 'TMDB retornou uma resposta invalida (HTTP ' . intval($status) . ').');
        }
        $results = array();
        foreach (array_slice((array) ($body['results'] ?? array()), 0, 10) as $movie) {
            $release_date = !empty($movie['release_date']) ? (string) $movie['release_date'] : '';
            $results[] = array('id' => intval($movie['id'] ?? 0), 'title' => (string) ($movie['title'] ?? ''), 'original_title' => (string) ($movie['original_title'] ?? ''), 'year' => $release_date !== '' ? substr($release_date, 0, 4) : '', 'overview' => (string) ($movie['overview'] ?? ''), 'poster_url' => !empty($movie['poster_path']) ? 'https://image.tmdb.org/t/p/w342' . $movie['poster_path'] : '');
        }
        return array('results' => $results);
    }
}
