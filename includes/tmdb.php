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
        if (empty($settings['tmdb_api_key'])) {
            echo '<div class="notice notice-warning"><p>Configure a chave do TMDB em Content Rank &gt; Configuracoes.</p></div>';
        }
        if (is_array($test) && !empty($test['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html($test['error']) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('content_rank_test_tmdb', 'content_rank_test_tmdb_nonce');
        echo '<input type="hidden" name="action" value="content_rank_test_tmdb" />';
        echo '<p><label for="content-rank-tmdb-query"><strong>Titulo para pesquisar</strong></label><br /><input id="content-rank-tmdb-query" type="text" name="query" class="regular-text" placeholder="The Hangover" required /></p>';
        echo '<p><button type="submit" class="button button-primary">Pesquisar no TMDB</button></p></form>';
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

    private function search_movies($query)
    {
        $query = trim((string) $query);
        $settings = Content_Rank_Generator::get_settings();
        $api_key = trim((string) ($settings['tmdb_api_key'] ?? ''));
        if ($api_key === '') {
            return array('error' => 'Informe a chave do TMDB nas configuracoes.');
        }
        if ($query === '') {
            return array('error' => 'Informe um titulo para pesquisar.');
        }
        $url = add_query_arg(array('api_key' => $api_key, 'query' => $query, 'language' => 'pt-BR', 'region' => 'BR', 'include_adult' => 'false', 'page' => 1), 'https://api.themoviedb.org/3/search/movie');
        $response = wp_remote_get($url, array('timeout' => 15, 'headers' => array('Accept' => 'application/json')));
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
            $results[] = array('id' => intval($movie['id'] ?? 0), 'title' => (string) ($movie['title'] ?? ''), 'original_title' => (string) ($movie['original_title'] ?? ''), 'year' => $release_date !== '' ? substr($release_date, 0, 4) : '', 'poster_url' => !empty($movie['poster_path']) ? 'https://image.tmdb.org/t/p/w342' . $movie['poster_path'] : '');
        }
        return array('results' => $results);
    }
}
