<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Content_Rank_Link_Suggestions')) {
    final class Content_Rank_Link_Suggestions
    {
        public const PAGE_SLUG = 'content-rank-link-suggestions';

        private const META_JSON = '_content_rank_link_suggestions_json';
        private const META_GENERATED_AT = '_content_rank_link_suggestions_generated_at';
        private const META_SOURCE_POST_ID = '_content_rank_link_suggestions_source_post_id';
        private const META_CUSTOM_PROMPT = '_content_rank_link_suggestions_custom_prompt';
        private const META_REQUESTED_COUNT = '_content_rank_link_suggestions_requested_count';
        private const META_REWRITE_CONTEXTUAL = '_content_rank_link_suggestions_rewrite_contextual';
        private const META_APPLIED_AT = '_content_rank_link_suggestions_applied_at';
        private const META_APPLIED_COUNT = '_content_rank_link_suggestions_applied_count';

        public function __construct()
        {
            add_action('admin_menu', array($this, 'admin_menu'), 22);
            add_action('wp_ajax_content_rank_link_suggestions_search_posts', array($this, 'ajax_search_posts'));
            add_action('admin_post_content_rank_generate_link_suggestions', array($this, 'handle_generate_link_suggestions'));
            add_action('admin_post_content_rank_apply_link_suggestions', array($this, 'handle_apply_link_suggestions'));
            add_action('admin_post_content_rank_clear_link_suggestions', array($this, 'handle_clear_link_suggestions'));
        }

        public function admin_menu()
        {
            add_submenu_page(
                'content-rank',
                'Sugestões de links',
                'Sugestões de links',
                'manage_options',
                self::PAGE_SLUG,
                array($this, 'render_page')
            );

            // Keep this page accessible for manually testing existing posts.
        }

        public function register_row_action_filters()
        {
            if (!is_admin()) {
                return;
            }

            $post_types = get_post_types(array('show_ui' => true), 'names');
            if (empty($post_types) || !is_array($post_types)) {
                return;
            }

            foreach ($post_types as $post_type) {
                add_filter($post_type . '_row_actions', array($this, 'add_row_action'), 20, 2);
            }
        }

        public function add_row_action($actions, $post)
        {
            if (!$post instanceof WP_Post || !current_user_can('manage_options')) {
                return $actions;
            }

            $url = self::build_page_url($post->ID);
            if ($url === '') {
                return $actions;
            }

            $actions['content_rank_link_suggestions'] = '<a href="' . esc_url($url) . '" aria-label="Lincagem automática" title="Lincagem automática">Lincagem automática</a>';
            return $actions;
        }

        private static function build_page_url($post_id)
        {
            $post_id = intval($post_id);
            if ($post_id <= 0) {
                return '';
            }

            return add_query_arg(array(
                'page' => self::PAGE_SLUG,
                'post_id' => $post_id,
            ), admin_url('admin.php'));
        }

        private static function get_request_param($key, $default = '')
        {
            if (!isset($_GET[$key])) {
                return $default;
            }

            $value = wp_unslash($_GET[$key]);
            if (is_array($value)) {
                return $default;
            }

            return sanitize_text_field((string) $value);
        }

        private static function normalize_plain_text($text)
        {
            $text = trim(wp_strip_all_tags((string) $text));
            $text = preg_replace('/\s+/', ' ', $text);
            return trim((string) $text);
        }

        private static function lift_execution_time_limit($seconds = 300)
        {
            $seconds = max(30, intval($seconds));
            if (function_exists('set_time_limit')) {
                @set_time_limit($seconds);
            }
            if (function_exists('ini_set')) {
                @ini_set('max_execution_time', (string) $seconds);
            }
        }

        private static function get_default_generator_context()
        {
            $settings = class_exists('Content_Rank_Generator') ? Content_Rank_Generator::get_settings() : array();

            return array(
                'id' => 0,
                'name' => get_bloginfo('name'),
                'source_type' => 'post',
                'model' => !empty($settings['default_model']) ? (string) $settings['default_model'] : '',
                'temperature' => isset($settings['default_temperature']) ? floatval($settings['default_temperature']) : 0.4,
                'max_tokens' => isset($settings['default_max_tokens']) ? max(512, intval($settings['default_max_tokens'])) : 2000,
                'generation_language' => class_exists('Content_Rank_Generator')
                    ? Content_Rank_Generator::get_default_generation_language()
                    : get_bloginfo('language'),
            );
        }

        private static function get_source_context($post_id)
        {
            $post_id = intval($post_id);
            $post = $post_id > 0 ? get_post($post_id) : null;
            if (!$post instanceof WP_Post) {
                return new WP_Error('content_rank_link_suggestions_post_missing', 'Post não encontrado.');
            }

            $generator = array();
            $generator_id = intval(get_post_meta($post_id, '_content_rank_generator_id', true));
            if ($generator_id > 0 && class_exists('Content_Rank_Generator')) {
                $generator = Content_Rank_Generator::get_generator($generator_id);
            }
            if (empty($generator) || !is_array($generator)) {
                $generator = self::get_default_generator_context();
            }

            $source_title = self::normalize_plain_text(get_the_title($post));
            $source_content_html = (string) $post->post_content;
            return array(
                'generator' => $generator,
                'post' => $post,
                'source' => array(
                    'id' => $post_id,
                    'title' => $source_title,
                    'content_html' => $source_content_html,
                ),
            );
        }

        private static function build_picker_post_item($post)
        {
            if (!$post instanceof WP_Post) {
                return array();
            }

            $title = self::normalize_plain_text(get_the_title($post));
            return array(
                'id' => intval($post->ID),
                'title' => $title,
                'label' => $title !== '' ? $title . ' - ' . $post->post_type : ('Post ' . intval($post->ID)),
                'post_type' => $post->post_type,
                'status' => $post->post_status,
            );
        }

        private static function query_picker_posts($search = '', $page = 1, $per_page = 10)
        {
            $search = self::normalize_plain_text($search);
            $page = max(1, intval($page));
            $per_page = max(1, min(20, intval($per_page)));
            $chunk_size = max($per_page * 4, 20);

            $post_types = array_values(array_diff(get_post_types(array('public' => true), 'names'), array('attachment', 'revision', 'nav_menu_item')));
            if (empty($post_types)) {
                $post_types = array('post');
            }

            $query_args = array(
                'post_type' => $post_types,
                'post_status' => array('publish'),
                'posts_per_page' => $chunk_size,
                'offset' => ($page - 1) * $chunk_size,
                'orderby' => 'date',
                'order' => 'DESC',
            );

            if ($search !== '') {
                $query_args['s'] = $search;
            }

            $posts = get_posts($query_args);
            if (empty($posts) || !is_array($posts)) {
                return array(
                    'items' => array(),
                    'has_more' => false,
                );
            }

            $items = array();
            foreach ($posts as $post) {
                if (!$post instanceof WP_Post) {
                    continue;
                }

                $items[] = self::build_picker_post_item($post);
                if (count($items) >= $per_page) {
                    break;
                }
            }

            return array(
                'items' => $items,
                'has_more' => count($posts) >= $chunk_size,
            );
        }

        public function ajax_search_posts()
        {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Permissao negada.'), 403);
            }

            check_ajax_referer('content_rank_link_suggestions_posts_search', 'nonce');

            $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
            $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
            $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;

            $result = self::query_picker_posts($search, $page, $per_page);
            wp_send_json_success($result);
        }

        private static function render_picker_post_button($item, $selected_post_id = 0)
        {
            $item = is_array($item) ? $item : array();
            $post_id = isset($item['id']) ? intval($item['id']) : 0;
            $post_title = isset($item['title']) ? self::normalize_plain_text($item['title']) : '';
            $post_type = isset($item['post_type']) ? self::normalize_plain_text($item['post_type']) : 'post';
            $is_active = $selected_post_id > 0 && $post_id === intval($selected_post_id);
            $button = '<button type="button" class="content-rank-link-picker-item w-full rounded-xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-indigo-200' . ($is_active ? ' border-indigo-500 bg-indigo-50' : ' border-slate-200 bg-white hover:bg-slate-50') . '" data-post-id="' . esc_attr($post_id) . '" data-post-title="' . esc_attr($post_title) . '" data-post-type="' . esc_attr($post_type) . '" aria-pressed="' . ($is_active ? 'true' : 'false') . '">';
            $button .= '<div class="font-medium text-slate-900">' . esc_html(isset($item['label']) && $item['label'] !== '' ? $item['label'] : (isset($item['title']) ? $item['title'] : 'Post')) . '</div>';
            $button .= '<div class="mt-1 text-xs text-slate-500">ID ' . esc_html($post_id) . ' · ' . esc_html($post_type) . '</div>';
            $button .= '</button>';
            return $button;
        }

        private static function render_posts_selector($selected_post_id = 0)
        {
            $selected_post_id = intval($selected_post_id);
            $selected_post = $selected_post_id > 0 ? get_post($selected_post_id) : null;
            $selected_title = $selected_post instanceof WP_Post ? self::normalize_plain_text(get_the_title($selected_post)) : '';
            $selected_meta = $selected_post instanceof WP_Post ? 'ID ' . intval($selected_post_id) : '';
            $button_label = $selected_title !== '' ? $selected_title : 'Selecionar post';

            $search_nonce = wp_create_nonce('content_rank_link_suggestions_posts_search');
            $initial = self::query_picker_posts('', 1, 10);
            $has_more = !empty($initial['has_more']);
            $items = !empty($initial['items']) && is_array($initial['items']) ? $initial['items'] : array();

            echo '<div id="content-rank-link-picker" class="relative space-y-3" data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr($search_nonce) . '" data-per-page="10" data-current-page="1" data-has-more="' . ($has_more ? '1' : '0') . '" data-selected-title="' . esc_attr($selected_title) . '" data-selected-meta="' . esc_attr($selected_meta) . '">';
            echo '<input type="hidden" name="source_post_id" id="content-rank-link-picker-value" value="' . esc_attr($selected_post_id) . '" />';
            echo '<button type="button" id="content-rank-link-picker-toggle" class="flex w-full items-center justify-between rounded-2xl border border-slate-300 bg-white px-4 py-3 text-left text-sm font-medium text-slate-900 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-200" aria-expanded="false">';
            echo '<span id="content-rank-link-picker-label">' . esc_html($button_label) . '</span>';
            echo '<svg class="h-4 w-4 shrink-0 text-slate-400 transition" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false"><path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            echo '</button>';
            echo '<div id="content-rank-link-picker-menu" class="absolute left-0 right-0 top-full z-20 mt-2 hidden rounded-2xl border border-slate-200 bg-white shadow-soft">';
            echo '<div class="flex items-center gap-2 border-b border-slate-200 p-3">';
            echo '<input id="content-rank-link-picker-search" type="search" class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Buscar post..." autocomplete="off" />';
            echo '<button type="button" id="content-rank-link-picker-search-btn" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Buscar</button>';
            echo '</div>';
            echo '<div id="content-rank-link-picker-results" class="max-h-80 space-y-2 overflow-y-auto p-3 pr-1">';
            if (!empty($items)) {
                foreach ($items as $item) {
                    echo self::render_picker_post_button($item, $selected_post_id);
                }
            }
            echo '</div>';
            echo '<div class="border-t border-slate-200 p-3">';
            echo '<button type="button" id="content-rank-link-picker-load-more" class="' . ($has_more ? '' : 'hidden ') . 'inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Carregar mais</button>';
            echo '<p id="content-rank-link-picker-empty" class="hidden px-1 pt-2 text-sm text-slate-500">Nenhum post encontrado.</p>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        private static function get_suggestions_meta($post_id)
        {
            $raw = (string) get_post_meta(intval($post_id), self::META_JSON, true);
            if ($raw === '') {
                return array();
            }

            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : array();
        }

        private static function render_notice()
        {
            $notice = self::get_request_param('content_rank_notice', '');
            if ($notice === '') {
                return;
            }

            $class = 'notice-success';
            $message = '';

            if ($notice === 'generated') {
                $count = intval(self::get_request_param('content_rank_count', 0));
                $message = $count > 0 ? sprintf('Sugestões geradas com sucesso. %d item(s) pronto(s) para aplicar.', $count) : 'Sugestões geradas com sucesso.';
            } elseif ($notice === 'applied') {
                $count = intval(self::get_request_param('content_rank_count', 0));
                $message = $count > 0 ? sprintf('Links aplicados com sucesso. %d link(s) inserido(s).', $count) : 'Links aplicados com sucesso.';
            } elseif ($notice === 'cleared') {
                $message = 'Sugestões removidas.';
            } elseif ($notice === 'error') {
                $message = self::get_request_param('content_rank_message', 'Não foi possivel concluir a operacao.');
                $class = 'notice-error';
            } else {
                $message = $notice;
                $class = self::get_request_param('content_rank_notice_type', 'success') === 'error' ? 'notice-error' : 'notice-success';
            }

            if ($message === '') {
                return;
            }

            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        private static function render_suggestions_table($plan)
        {
            if (empty($plan) || !is_array($plan)) {
                echo '<div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">Nenhuma sugestao gerada ainda.</div>';
                return;
            }

            $suggestions = !empty($plan['suggestions']) && is_array($plan['suggestions']) ? $plan['suggestions'] : array();
            if (empty($suggestions)) {
                $candidate_count = isset($plan['candidates_count']) ? intval($plan['candidates_count']) : 0;
                if (!empty($plan['search_terms']) && is_array($plan['search_terms'])) {
                    echo '<p class="mt-3 text-xs text-slate-500">Termos pesquisados: ' . esc_html(implode(', ', $plan['search_terms'])) . '</p>';
                }
                echo '<div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">' . ($candidate_count > 0 ? 'Nenhum encaixe válido encontrado nos parágrafos para os títulos candidatos.' : 'Nenhum post publicado com termos relevantes no título foi encontrado.') . '</div>';
                return;
            }

            echo '<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">';
            echo '<div class="border-b border-slate-200 px-6 py-4">';
            echo '<div class="flex flex-wrap items-start justify-between gap-3">';
            echo '<div>';
            echo '<h2 class="text-lg font-semibold text-slate-950">Sugestões salvas</h2>';
            if (isset($plan['candidates_count'])) {
                echo '<p class="mt-1 text-xs text-slate-500">' . esc_html(intval($plan['candidates_count'])) . ' título(s) passaram pelo filtro de correspondência.</p>';
            }
            if (!empty($plan['generated_at'])) {
                echo '<p class="mt-1 text-sm text-slate-500">Gerado em ' . esc_html($plan['generated_at']) . '</p>';
            }
            if (!empty($plan['applied_count'])) {
                echo '<p class="mt-1 text-sm text-emerald-700">' . esc_html(intval($plan['applied_count'])) . ' link(s) aplicado(s) ao conteúdo.</p>';
            }
            echo '</div>';
            echo '<div class="text-sm text-slate-500">' . esc_html(count($suggestions)) . ' sugestao(oes)</div>';
            echo '</div>';
            echo '</div>';

            echo '<div class="overflow-x-auto">';
            echo '<table class="min-w-full divide-y divide-slate-200">';
            echo '<thead class="bg-slate-50"><tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">';
            echo '<th class="px-6 py-3">#</th>';
            echo '<th class="px-6 py-3">Ancora</th>';
            echo '<th class="px-6 py-3">Post ID</th>';
            echo '<th class="px-6 py-3">Titulo</th>';
            echo '<th class="px-6 py-3">Texto proposto</th>';
            echo '</tr></thead>';
            echo '<tbody class="divide-y divide-slate-100 bg-white">';
            foreach ($suggestions as $index => $suggestion) {
                $anchor = !empty($suggestion['anchor']) ? (string) $suggestion['anchor'] : '-';
                $post_id = !empty($suggestion['post_id']) ? intval($suggestion['post_id']) : 0;
                $title = !empty($suggestion['title']) ? (string) $suggestion['title'] : '-';
                $replacement = !empty($suggestion['replacement']) ? (string) $suggestion['replacement'] : 'Usar o texto existente';

                echo '<tr class="align-top">';
                echo '<td class="px-6 py-4 text-sm text-slate-600">' . esc_html(intval($index) + 1) . '</td>';
                echo '<td class="px-6 py-4 text-sm text-slate-900">' . esc_html($anchor) . '</td>';
                echo '<td class="px-6 py-4 text-sm text-slate-700">' . esc_html($post_id > 0 ? $post_id : '-') . '</td>';
                echo '<td class="px-6 py-4 text-sm font-medium text-slate-900">' . esc_html($title) . '</td>';
                echo '<td class="px-6 py-4 text-sm text-slate-700">' . esc_html($replacement) . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
            echo '</div>';
        }

        private static function save_plan($post_id, $plan)
        {
            update_post_meta($post_id, self::META_JSON, wp_slash(wp_json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
            update_post_meta($post_id, self::META_GENERATED_AT, $plan['generated_at']);
            update_post_meta($post_id, self::META_SOURCE_POST_ID, $post_id);
            update_post_meta($post_id, self::META_CUSTOM_PROMPT, wp_slash($plan['custom_prompt']));
            update_post_meta($post_id, self::META_REQUESTED_COUNT, $plan['requested_count']);
            update_post_meta($post_id, self::META_REWRITE_CONTEXTUAL, !empty($plan['rewrite_contextual']) ? 1 : 0);
            delete_post_meta($post_id, self::META_APPLIED_AT);
            delete_post_meta($post_id, self::META_APPLIED_COUNT);
            Content_Rank_Contextual_Links::trace($plan, $post_id, 'persistence', 'plan_saved', array(
                'accepted_count' => count($plan['suggestions']), 'rejected_count' => count($plan['rejections'] ?? array()),
                'stored_plan_matches' => get_post_meta($post_id, self::META_JSON, true) === wp_json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        }

        private static function apply_saved_plan($post_id, $plan)
        {
            $content = (string) get_post_field('post_content', $post_id);
            Content_Rank_Contextual_Links::trace($plan, $post_id, 'persistence', 'started', array(
                'expected_hash' => $plan['content_hash'] ?? '', 'actual_hash' => hash('sha256', $content),
                'planning_rejections' => $plan['rejections'] ?? array()));
            if (empty($plan['content_hash']) || !hash_equals($plan['content_hash'], hash('sha256', $content))) {
                Content_Rank_Contextual_Links::trace($plan, $post_id, 'persistence', 'stopped', array('reason' => 'content_changed_since_analysis'));
                return new WP_Error('content_rank_links_changed', 'O conteúdo mudou desde a análise. Gere novas sugestões antes de aplicar.');
            }
            $result = Content_Rank_Contextual_Links::apply($content, $plan, $post_id, 'save_validation');
            $result['planning_rejections'] = $plan['rejections'] ?? array();
            $result['save_verified'] = false;
            if ($result['applied_count'] > 0) {
                Content_Rank_Contextual_Links::trace($plan, $post_id, 'persistence', 'write_requested', array('prepared_count' => $result['applied_count'],
                    'expected_output_hash' => hash('sha256', $result['content_html'])));
                $saved = wp_update_post(wp_slash(array('ID' => $post_id, 'post_content' => $result['content_html'])), true);
                if (is_wp_error($saved)) {
                    Content_Rank_Contextual_Links::trace($plan, $post_id, 'persistence', 'write_failed', array('message' => $saved->get_error_message()));
                    return $saved;
                }
                $stored_content = (string) get_post_field('post_content', $post_id, 'raw');
                $result['save_verified'] = $stored_content === $result['content_html'];
                Content_Rank_Contextual_Links::trace($plan, $post_id, 'persistence', 'write_result', array('returned_post_id' => intval($saved),
                    'stored_content_matches' => $result['save_verified'], 'actual_output_hash' => hash('sha256', $stored_content)));
            } else {
                Content_Rank_Contextual_Links::trace($plan, $post_id, 'persistence', 'write_skipped', array('reason' => 'no_valid_edits',
                    'planning_rejections' => $plan['rejections'] ?? array(), 'save_rejections' => $result['rejections']));
            }
            update_post_meta($post_id, self::META_APPLIED_AT, current_time('mysql'));
            update_post_meta($post_id, self::META_APPLIED_COUNT, $result['applied_count']);
            Content_Rank_Contextual_Links::trace($plan, $post_id, 'persistence', 'finished', array('prepared_count' => $result['applied_count'],
                'save_verified' => $result['save_verified'], 'stored_applied_count' => intval(get_post_meta($post_id, self::META_APPLIED_COUNT, true))));
            return array_merge($plan, $result);
        }

        public function handle_generate_link_suggestions()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Permissão negada.');
            }
            check_admin_referer('content_rank_generate_link_suggestions', 'content_rank_link_suggestions_nonce');
            self::lift_execution_time_limit(300);
            $post_id = isset($_POST['source_post_id']) ? intval($_POST['source_post_id']) : 0;
            if (!current_user_can('edit_post', $post_id)) {
                wp_die('Permissão negada.');
            }
            $count = max(1, min(4, isset($_POST['suggestion_count']) ? intval($_POST['suggestion_count']) : 4));
            $custom_prompt = isset($_POST['custom_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['custom_prompt'])) : '';
            $rewrite_contextual = !empty($_POST['rewrite_contextual']);
            $context = self::get_source_context($post_id);
            if (is_wp_error($context)) {
                $this->redirect_with_notice($context->get_error_message(), 'error', array('post_id' => $post_id));
            }
            $plan = Content_Rank_Contextual_Links::plan($context['source']['content_html'], $context['generator'], $post_id, $context['source'], $count, $custom_prompt, $rewrite_contextual);
            if (is_wp_error($plan)) {
                $this->redirect_with_notice($plan->get_error_message(), 'error', array('post_id' => $post_id));
            }
            self::save_plan($post_id, $plan);
            $this->redirect_with_notice(empty($plan['suggestions']) ? 'Nenhum encaixe válido encontrado para os posts candidatos.' : 'generated', 'success', array(
                'post_id' => $post_id, 'content_rank_count' => count($plan['suggestions']),
            ));
        }

        public function handle_apply_link_suggestions()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Permissão negada.');
            }
            check_admin_referer('content_rank_apply_link_suggestions', 'content_rank_link_suggestions_nonce');
            $post_id = isset($_POST['source_post_id']) ? intval($_POST['source_post_id']) : 0;
            if (!current_user_can('edit_post', $post_id)) {
                wp_die('Permissão negada.');
            }
            $result = self::apply_saved_plan($post_id, self::get_suggestions_meta($post_id));
            if (is_wp_error($result)) {
                $this->redirect_with_notice($result->get_error_message(), 'error', array('post_id' => $post_id));
            }
            $this->redirect_with_notice($result['applied_count'] > 0 ? 'applied' : 'Nenhum link foi inserido no conteúdo.', 'success', array(
                'post_id' => $post_id, 'content_rank_count' => $result['applied_count'],
            ));
        }

        public static function generate_and_apply_link_suggestions_to_post($post_id, $generator = array(), $requested_count = 4, $custom_prompt = '', $content_html = '', $context = array(), $rewrite_contextual = null)
        {
            $post = get_post($post_id);
            if (!$post instanceof WP_Post) {
                return new WP_Error('content_rank_link_suggestions_post_missing', 'Post não encontrado.');
            }
            $content_html = $content_html !== '' ? $content_html : (string) $post->post_content;
            $context['title'] = $context['title'] ?? $post->post_title;
            $use_contextual = $rewrite_contextual === null
                ? (!empty($generator['contextual_links_enabled']) || !empty($generator['contextual_links_rewrite_enabled']))
                : !empty($rewrite_contextual);
            $plan = Content_Rank_Contextual_Links::plan($content_html, $generator, $post_id, $context, $requested_count, $custom_prompt, $use_contextual);
            if (is_wp_error($plan)) {
                return $plan;
            }
            self::save_plan($post_id, $plan);
            $result = self::apply_saved_plan($post_id, $plan);
            return $result;
        }

        public function handle_clear_link_suggestions()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Permissao negada.');
            }

            check_admin_referer('content_rank_clear_link_suggestions', 'content_rank_link_suggestions_nonce');

            $post_id = isset($_POST['source_post_id']) ? intval($_POST['source_post_id']) : 0;
            if ($post_id > 0) {
                delete_post_meta($post_id, self::META_JSON);
                delete_post_meta($post_id, self::META_GENERATED_AT);
                delete_post_meta($post_id, self::META_SOURCE_POST_ID);
                delete_post_meta($post_id, self::META_CUSTOM_PROMPT);
                delete_post_meta($post_id, self::META_REQUESTED_COUNT);
                delete_post_meta($post_id, self::META_REWRITE_CONTEXTUAL);
                delete_post_meta($post_id, self::META_APPLIED_AT);
                delete_post_meta($post_id, self::META_APPLIED_COUNT);
            }

            $this->redirect_with_notice('cleared', 'success', array(
                'post_id' => $post_id,
            ));
        }

        private function redirect_with_notice($message, $type = 'success', $extra = array())
        {
            $url = add_query_arg(array_merge(array(
                'page' => self::PAGE_SLUG,
                'content_rank_notice' => $message,
                'content_rank_notice_type' => $type,
            ), $extra), admin_url('admin.php'));

            wp_safe_redirect($url);
            exit;
        }

        private static function render_selected_card($selected_post_id)
        {
            $selected_post_id = intval($selected_post_id);
            $selected_post = $selected_post_id > 0 ? get_post($selected_post_id) : null;
            $title = $selected_post instanceof WP_Post ? self::normalize_plain_text(get_the_title($selected_post)) : '';
            $post_type = $selected_post instanceof WP_Post && !empty($selected_post->post_type) ? $selected_post->post_type : 'post';
            $hidden_class = $selected_post instanceof WP_Post ? '' : ' hidden';

            $html = '<div id="content-rank-link-selected-card" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm' . esc_attr($hidden_class) . '">';
            $html .= '<div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Post selecionado</div>';
            $html .= '<div id="content-rank-link-selected-title" class="mt-2 text-base font-semibold text-slate-950">' . esc_html($title) . '</div>';
            $html .= '<div id="content-rank-link-selected-meta" class="mt-1 text-sm text-slate-500">' . ($selected_post instanceof WP_Post ? 'ID ' . esc_html($selected_post_id) . ' · ' . esc_html($post_type) : '') . '</div>';
            $html .= '</div>';

            return $html;
        }

        public function render_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            if (function_exists('nocache_headers')) {
                nocache_headers();
            }

            $selected_post_id = intval(self::get_request_param('post_id', 0));
            $plan = $selected_post_id > 0 ? self::get_suggestions_meta($selected_post_id) : array();
            if (!empty($plan)) {
                $plan['applied_count'] = intval(get_post_meta($selected_post_id, self::META_APPLIED_COUNT, true));
            }
            $stored_generated_at = $selected_post_id > 0 ? (string) get_post_meta($selected_post_id, self::META_GENERATED_AT, true) : '';
            if ($stored_generated_at !== '') {
                $plan['generated_at'] = $stored_generated_at;
            }
            $stored_custom_prompt = $selected_post_id > 0 ? (string) get_post_meta($selected_post_id, self::META_CUSTOM_PROMPT, true) : '';
            if ($stored_custom_prompt !== '' && empty($plan['custom_prompt'])) {
                $plan['custom_prompt'] = $stored_custom_prompt;
            }
            $stored_requested_count = $selected_post_id > 0 ? intval(get_post_meta($selected_post_id, self::META_REQUESTED_COUNT, true)) : 0;
            if ($stored_requested_count > 0 && empty($plan['requested_count'])) {
                $plan['requested_count'] = $stored_requested_count;
            }
            if ($selected_post_id > 0 && !isset($plan['rewrite_contextual'])) {
                $plan['rewrite_contextual'] = intval(get_post_meta($selected_post_id, self::META_REWRITE_CONTEXTUAL, true)) ? 1 : 0;
            }

            $selected_post = $selected_post_id > 0 ? get_post($selected_post_id) : null;
            $selected_title = $selected_post instanceof WP_Post ? self::normalize_plain_text(get_the_title($selected_post)) : '';
            $selected_meta = $selected_post instanceof WP_Post ? 'ID ' . intval($selected_post_id) . ' · ' . $selected_post->post_type : '';

            ?>
            <script>
                window.tailwind = window.tailwind || {};
                window.tailwind.config = {
                    theme: {
                        extend: {
                            boxShadow: {
                                soft: '0 20px 50px -30px rgba(15, 23, 42, 0.35)'
                            }
                        }
                    }
                };
            </script>
            <script src="https://cdn.tailwindcss.com"></script>
            <div class="wrap content-rank-wrap min-h-screen bg-slate-100 text-slate-900">
                <h1 class="screen-reader-text">Content Rank</h1>
                <div class="mb-6">
                    <div class="text-xs font-semibold uppercase tracking-[0.25em] text-indigo-600">Content Rank</div>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Sugestões de links</h1>
                </div>

                <?php self::render_notice(); ?>

                <div class="grid gap-6 xl:grid-cols-[360px_minmax(0,1fr)]">
                    <aside class="space-y-6">
                        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-soft">
                            <div>
                                <h2 class="text-lg font-semibold text-slate-950">Parametros</h2>
                            </div>

                            <div class="mt-5">
                                <?php self::render_posts_selector($selected_post_id); ?>
                            </div>

                            <div class="mt-5">
                                <?php echo self::render_selected_card($selected_post_id); ?>
                            </div>

                            <div id="content-rank-link-options" class="mt-5 space-y-4 <?php echo $selected_post instanceof WP_Post ? '' : 'hidden'; ?>">
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="space-y-4">
                                    <?php wp_nonce_field('content_rank_generate_link_suggestions', 'content_rank_link_suggestions_nonce'); ?>
                                    <input type="hidden" name="action" value="content_rank_generate_link_suggestions" />
                                    <input type="hidden" name="source_post_id" id="content-rank-link-picker-value-form" value="<?php echo esc_attr($selected_post_id); ?>" />

                                    <div>
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Qtd. links</label>
                                        <input type="number" min="1" max="4" name="suggestion_count" value="<?php echo esc_attr(isset($plan['requested_count']) ? min(4, intval($plan['requested_count'])) : 4); ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                    </div>

                                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-700">
                                        <input type="checkbox" name="rewrite_contextual" value="1" <?php checked(!empty($plan['rewrite_contextual'])); ?> class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-200" />
                                        <span><strong class="font-semibold text-slate-900">Permitir reescrita contextual</strong><span class="mt-1 block text-xs text-slate-500">A IA pode propor uma pequena frase de ligação quando houver relação factual. O PHP valida e insere o link; se não houver ponte natural, não força.</span></span>
                                    </label>

                                    <details class="group rounded-2xl border border-slate-200 bg-slate-50">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-700">
                                            <span>Prompt personalizado</span>
                                            <svg class="h-4 w-4 shrink-0 text-slate-400 transition group-open:rotate-180" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false"><path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </summary>
                                        <div class="border-t border-slate-200 px-4 py-4">
                                            <textarea name="custom_prompt" rows="5" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Instrucoes extras para a IA, se precisar."><?php echo esc_textarea(isset($plan['custom_prompt']) ? $plan['custom_prompt'] : ''); ?></textarea>
                                        </div>
                                    </details>

                                    <button type="submit" id="content-rank-link-generate-button" <?php echo $selected_post instanceof WP_Post ? '' : 'disabled="disabled"'; ?> class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-emerald-500 <?php echo $selected_post instanceof WP_Post ? '' : 'opacity-50 cursor-not-allowed'; ?>">Gerar sugestões</button>
                                </form>

                                <?php if (!empty($plan) && !empty($plan['suggestions']) && is_array($plan['suggestions'])): ?>
                                    <?php if (empty($plan['applied_count'])): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('content_rank_apply_link_suggestions', 'content_rank_link_suggestions_nonce'); ?>
                                        <input type="hidden" name="action" value="content_rank_apply_link_suggestions" />
                                        <input type="hidden" name="source_post_id" value="<?php echo esc_attr($selected_post_id); ?>" />
                                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-slate-800">Aplicar links</button>
                                    </form>
                                    <?php endif; ?>

                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mt-3" data-swal-confirm="Remover as sugestoes salvas deste post?">
                                        <?php wp_nonce_field('content_rank_clear_link_suggestions', 'content_rank_link_suggestions_nonce'); ?>
                                        <input type="hidden" name="action" value="content_rank_clear_link_suggestions" />
                                        <input type="hidden" name="source_post_id" value="<?php echo esc_attr($selected_post_id); ?>" />
                                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-600 transition hover:bg-rose-100">Limpar sugestões</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </aside>

                    <main class="min-w-0">
                        <?php self::render_suggestions_table($plan); ?>
                    </main>
                </div>
            </div>

            <script>
                (function () {
                    const picker = document.getElementById('content-rank-link-picker');
                    const optionsWrap = document.getElementById('content-rank-link-options');
                    const toggleButton = document.getElementById('content-rank-link-picker-toggle');
                    const labelNode = document.getElementById('content-rank-link-picker-label');
                    const menu = document.getElementById('content-rank-link-picker-menu');
                    const searchInput = document.getElementById('content-rank-link-picker-search');
                    const searchButton = document.getElementById('content-rank-link-picker-search-btn');
                    const results = document.getElementById('content-rank-link-picker-results');
                    const loadMoreButton = document.getElementById('content-rank-link-picker-load-more');
                    const emptyState = document.getElementById('content-rank-link-picker-empty');
                    const generateButton = document.getElementById('content-rank-link-generate-button');
                    const valueInputs = document.querySelectorAll('input[name="source_post_id"], #content-rank-link-picker-value-form');
                    const selectedCard = document.getElementById('content-rank-link-selected-card');
                    const selectedTitle = document.getElementById('content-rank-link-selected-title');
                    const selectedMeta = document.getElementById('content-rank-link-selected-meta');
                    const ajaxUrl = picker ? picker.dataset.ajaxUrl : '';
                    const nonce = picker ? picker.dataset.nonce : '';
                    const perPage = picker ? parseInt(picker.dataset.perPage || '10', 10) : 10;
                    let currentPage = picker ? parseInt(picker.dataset.currentPage || '1', 10) : 1;
                    let hasMore = picker ? picker.dataset.hasMore === '1' : false;
                    let currentSearch = '';
                    let selectedPostId = 0;
                    let loading = false;
                    let searchTimer = null;

                    if (!picker || !results || !optionsWrap || !toggleButton || !menu || !labelNode) {
                        return;
                    }

                    const escapeHtml = (value) => {
                        return String(value ?? '')
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;')
                            .replace(/'/g, '&#039;');
                    };

                    const setSelectedId = (postId) => {
                        selectedPostId = parseInt(postId || '0', 10) || 0;
                        valueInputs.forEach((input) => {
                            input.value = selectedPostId > 0 ? String(selectedPostId) : '';
                        });
                        if (selectedCard) {
                            selectedCard.classList.toggle('hidden', selectedPostId <= 0);
                        }
                        syncOptionsVisibility();
                        syncGenerateState();
                    };

                    const syncOptionsVisibility = () => {
                        const hasSearchText = searchInput ? searchInput.value.trim() !== '' : false;
                        const shouldShowOptions = selectedPostId > 0 || hasSearchText;
                        optionsWrap.classList.toggle('hidden', !shouldShowOptions);
                    };

                    const syncGenerateState = () => {
                        if (!generateButton) {
                            return;
                        }
                        const canGenerate = selectedPostId > 0;
                        generateButton.disabled = !canGenerate;
                        generateButton.classList.toggle('opacity-50', !canGenerate);
                        generateButton.classList.toggle('cursor-not-allowed', !canGenerate);
                    };

                    const setMenuOpen = (isOpen) => {
                        menu.classList.toggle('hidden', !isOpen);
                        toggleButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                        if (isOpen && searchInput) {
                            window.setTimeout(() => searchInput.focus(), 0);
                        }
                    };

                    const syncActiveButtons = () => {
                        const buttons = results.querySelectorAll('.content-rank-link-picker-item');
                        buttons.forEach((button) => {
                            const buttonId = parseInt(button.dataset.postId || '0', 10) || 0;
                            const active = selectedPostId > 0 && buttonId === selectedPostId;
                            button.setAttribute('aria-pressed', active ? 'true' : 'false');
                            button.classList.toggle('border-indigo-500', active);
                            button.classList.toggle('bg-indigo-50', active);
                            button.classList.toggle('border-slate-200', !active);
                            button.classList.toggle('bg-white', !active);
                        });
                    };

                    if (results) {
                        results.addEventListener('click', (event) => {
                            const button = event.target.closest('.content-rank-link-picker-item');
                            if (!button || !results.contains(button)) {
                                return;
                            }

                            const item = {
                                id: parseInt(button.dataset.postId || '0', 10) || 0,
                                title: button.dataset.postTitle || button.textContent || 'Post',
                                post_type: button.dataset.postType || 'post'
                            };

                            if (item.id > 0) {
                                selectPost(item);
                            }
                        });
                    }

                    const updateLabel = (text) => {
                        labelNode.textContent = text && String(text).trim() !== '' ? String(text) : 'Selecionar post';
                    };

                    const selectPost = (item) => {
                        setSelectedId(item.id);
                        updateLabel(item.title || 'Selecionar post');
                        if (selectedTitle) {
                            selectedTitle.textContent = item.title || 'Post';
                        }
                        if (selectedMeta) {
                            selectedMeta.textContent = 'ID ' + String(item.id || 0) + ' · ' + String(item.post_type || 'post');
                        }
                        setMenuOpen(false);
                        syncActiveButtons();
                    };

                    const renderButton = (item) => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'content-rank-link-picker-item w-full rounded-xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-indigo-200';
                        button.dataset.postId = item.id;
                        button.dataset.postTitle = item.title || '';
                        button.dataset.postType = item.post_type || 'post';
                        button.setAttribute('aria-pressed', selectedPostId > 0 && parseInt(item.id || '0', 10) === selectedPostId ? 'true' : 'false');
                        if (selectedPostId > 0 && parseInt(item.id || '0', 10) === selectedPostId) {
                            button.classList.add('border-indigo-500', 'bg-indigo-50');
                        } else {
                            button.classList.add('border-slate-200', 'bg-white', 'hover:bg-slate-50');
                        }

                        button.innerHTML = '<div class="font-medium text-slate-900">' + escapeHtml(item.label || item.title || 'Post') + '</div><div class="mt-1 text-xs text-slate-500">ID ' + escapeHtml(item.id || 0) + ' · ' + escapeHtml(item.post_type || 'post') + '</div>';
                        return button;
                    };

                    const setLoadMoreState = () => {
                        if (!loadMoreButton) {
                            return;
                        }
                        loadMoreButton.classList.toggle('hidden', !hasMore);
                    };

                    const setEmptyState = (isEmpty) => {
                        if (!emptyState) {
                            return;
                        }
                        emptyState.classList.toggle('hidden', !isEmpty);
                    };

                    const fetchPosts = async ({ search = '', page = 1, append = false } = {}) => {
                        if (loading) {
                            return;
                        }
                        loading = true;
                        if (searchButton) {
                            searchButton.disabled = true;
                        }
                        if (loadMoreButton) {
                            loadMoreButton.disabled = true;
                        }

                        try {
                            const response = await fetch(ajaxUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                                },
                                body: new URLSearchParams({
                                    action: 'content_rank_link_suggestions_search_posts',
                                    nonce: nonce,
                                    search: search,
                                    page: String(page),
                                    per_page: String(perPage)
                                }).toString()
                            });

                            const payload = await response.json();
                            if (!payload || !payload.success) {
                                throw new Error((payload && payload.data && payload.data.message) ? payload.data.message : 'Não foi possivel carregar os posts.');
                            }

                            const data = payload.data || {};
                            const items = Array.isArray(data.items) ? data.items : [];
                            hasMore = !!data.has_more;
                            picker.dataset.hasMore = hasMore ? '1' : '0';
                            currentPage = page;

                            if (!append) {
                                results.innerHTML = '';
                            }

                            if (items.length === 0 && !append) {
                                setEmptyState(true);
                            } else {
                                setEmptyState(false);
                                items.forEach((item) => {
                                    results.appendChild(renderButton(item));
                                });
                            }

                            setLoadMoreState();
                            syncActiveButtons();
                        } catch (error) {
                            console.error(error);
                            if (!append) {
                                results.innerHTML = '<p class="text-sm text-rose-600">Falha ao carregar os posts. Tente novamente.</p>';
                            }
                            setLoadMoreState();
                        } finally {
                            loading = false;
                            if (searchButton) {
                                searchButton.disabled = false;
                            }
                            if (loadMoreButton) {
                                loadMoreButton.disabled = false;
                            }
                        }
                    };

                    const runSearch = () => {
                        currentSearch = searchInput ? searchInput.value.trim() : '';
                        syncOptionsVisibility();
                        fetchPosts({ search: currentSearch, page: 1, append: false });
                    };

                    const initialSelectedInput = Array.from(valueInputs).find((input) => parseInt(input.value || '0', 10) > 0);
                    if (initialSelectedInput) {
                        selectedPostId = parseInt(initialSelectedInput.value || '0', 10) || 0;
                        optionsWrap.classList.remove('hidden');
                        if (selectedCard) {
                            selectedCard.classList.remove('hidden');
                        }
                    }

                    if (selectedPostId <= 0) {
                        optionsWrap.classList.add('hidden');
                        if (selectedCard) {
                            selectedCard.classList.add('hidden');
                        }
                    }
                    syncGenerateState();

                    const initialTitle = picker.dataset.selectedTitle || '';
                    const initialMeta = picker.dataset.selectedMeta || '';
                    if (initialTitle !== '') {
                        updateLabel(initialTitle);
                    }
                    if (selectedTitle && initialTitle !== '') {
                        selectedTitle.textContent = initialTitle;
                    }
                    if (selectedMeta && initialMeta !== '') {
                        selectedMeta.textContent = initialMeta;
                    }

                    setLoadMoreState();
                    setEmptyState(results.querySelectorAll('.content-rank-link-picker-item').length === 0);
                    syncActiveButtons();

                    if (toggleButton) {
                        toggleButton.addEventListener('click', () => {
                            const isOpen = menu.classList.contains('hidden');
                            setMenuOpen(isOpen);
                        });
                    }

                    document.addEventListener('click', (event) => {
                        if (!picker.contains(event.target)) {
                            setMenuOpen(false);
                        }
                    });

                    document.addEventListener('keydown', (event) => {
                        if (event.key === 'Escape') {
                            setMenuOpen(false);
                        }
                    });

                    if (searchInput) {
                        searchInput.addEventListener('input', () => {
                            window.clearTimeout(searchTimer);
                            searchTimer = window.setTimeout(runSearch, 250);
                            syncOptionsVisibility();
                        });
                        searchInput.addEventListener('keydown', (event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                runSearch();
                            }
                        });
                    }

                    if (searchButton) {
                        searchButton.addEventListener('click', runSearch);
                    }

                    if (loadMoreButton) {
                        loadMoreButton.addEventListener('click', () => {
                            if (!hasMore) {
                                return;
                            }
                            fetchPosts({ search: currentSearch, page: currentPage + 1, append: true });
                        });
                    }
                })();
            </script>
            <?php
        }
    }
}
