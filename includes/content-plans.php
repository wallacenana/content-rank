<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Content_Rank_Content_Plans')) {
    final class Content_Rank_Content_Plans
    {
        public const PAGE_SLUG = 'content-rank-content-plans';
        private const META_PLAN_JSON = '_content_rank_content_plan_json';
        private const META_PLAN_GENERATED_AT = '_content_rank_content_plan_generated_at';
        private const META_PLAN_GENERATOR_ID = '_content_rank_content_plan_generator_id';
        private const META_PLAN_PILLAR_POST_ID = '_content_rank_content_plan_pillar_post_id';
        private const META_PLAN_SATELLITE_COUNT = '_content_rank_content_plan_satellite_count';
        private const META_PLAN_CONTENT_MODEL_TYPE = '_content_rank_content_plan_content_model_type';
        private const META_PLAN_PROMPT_MODEL_KEY = '_content_rank_content_plan_prompt_model_key';
        private const META_PLAN_OUTLINE_MODEL_KEY = '_content_rank_content_plan_outline_model_key';
        private const META_PLAN_TAVILY_JSON = '_content_rank_content_plan_tavily_json';
        private const META_PLAN_PLANNING_CUSTOM_PROMPT = '_content_rank_content_plan_planning_custom_prompt';
        private const MAX_PLANNING_SOURCE_WORDS = 1000;

        public function __construct()
        {
            add_action('admin_menu', array($this, 'admin_menu'), 22);
            add_action('wp_ajax_content_rank_content_plan_search_posts', array($this, 'ajax_search_posts'));
            add_action('admin_post_content_rank_generate_content_plan', array($this, 'handle_generate_plan'));
            add_action('admin_post_content_rank_generate_content_satellites', array($this, 'handle_generate_satellites'));
            add_action('admin_post_content_rank_clear_content_plan', array($this, 'handle_clear_plan'));
            add_action('transition_post_status', array($this, 'handle_satellite_publish_transition'), 20, 3);
        }

        public function admin_menu()
        {
            add_submenu_page(
                'content-rank',
                'Planejamento',
                'Planejamento',
                'manage_options',
                self::PAGE_SLUG,
                array($this, 'render_page')
            );
        }

        private static function get_request_param($key, $default = '')
        {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- admin read-only param helper.
            if (!isset($_GET[$key])) {
                return $default;
            }
            $value = wp_unslash($_GET[$key]);
            // phpcs:enable WordPress.Security.NonceVerification.Recommended

            if (is_array($value)) {
                return $default;
            }

            return sanitize_text_field((string) $value);
        }

        private static function get_generated_posts($limit = 30, $content_model_type = 'pillar')
        {
            $limit = max(1, intval($limit));
            $content_model_type = class_exists('Content_Rank_Generator')
                ? Content_Rank_Generator::normalize_content_model_type($content_model_type)
                : sanitize_key((string) $content_model_type);
            if ($content_model_type !== 'pillar' && $content_model_type !== 'satellite') {
                $content_model_type = 'pillar';
            }

            $posts = get_posts(array(
                'post_type' => 'any',
                'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
                'posts_per_page' => max(200, $limit * 10),
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_query' => array(
                    array(
                        'key' => '_content_rank_generator_id',
                        'compare' => 'EXISTS',
                    ),
                ),
            ));

            if (empty($posts) || !is_array($posts)) {
                return array();
            }

            $filtered = array();
            foreach ($posts as $post) {
                if (!$post instanceof WP_Post) {
                    continue;
                }

                $stored_content_model_type = (string) get_post_meta($post->ID, '_content_rank_content_model_type', true);
                if ($stored_content_model_type === '') {
                    $has_satellite_plan_marker = get_post_meta($post->ID, '_content_rank_content_plan_satellite_index', true) !== '';
                    $stored_content_model_type = $has_satellite_plan_marker ? 'satellite' : 'pillar';
                }
                if (class_exists('Content_Rank_Generator')) {
                    $stored_content_model_type = Content_Rank_Generator::normalize_content_model_type($stored_content_model_type);
                }
                if ($stored_content_model_type !== $content_model_type) {
                    continue;
                }

                $filtered[] = $post;
                if (count($filtered) >= $limit) {
                    break;
                }
            }

            return $filtered;
        }

        public static function build_plan_url($post_id)
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
                add_filter($post_type . '_row_actions', array($this, 'add_plan_row_action'), 20, 2);
            }
        }

        public function add_plan_row_action($actions, $post)
        {
            if (!$post instanceof WP_Post || !current_user_can('manage_options')) {
                return $actions;
            }

            $plan_url = self::build_plan_url($post->ID);
            if ($plan_url === '') {
                return $actions;
            }

            $actions['content_rank_plan'] = '<a href="' . esc_url($plan_url) . '" aria-label="Planejamento" title="Planejamento">Planejamento</a>';
            return $actions;
        }

        private static function build_picker_post_item($post)
        {
            if (!$post instanceof WP_Post) {
                return array();
            }

            $generator_id = intval(get_post_meta($post->ID, '_content_rank_generator_id', true));
            $generator_name = '';
            if ($generator_id > 0 && class_exists('Content_Rank_Generator')) {
                $generator = Content_Rank_Generator::get_generator($generator_id);
                if (!empty($generator['name'])) {
                    $generator_name = (string) $generator['name'];
                }
            }

            $title = self::normalize_plain_text(get_the_title($post));
            $label = $title;
            if ($generator_name !== '') {
                $label .= ' - ' . $generator_name;
            }

            return array(
                'id' => intval($post->ID),
                'title' => $title,
                'label' => $label,
                'url' => get_permalink($post),
                'post_type' => get_post_type($post),
                'generator_name' => $generator_name,
            );
        }

        private static function query_picker_posts($search = '', $page = 1, $per_page = 10)
        {
            $search = self::normalize_plain_text($search);
            $page = max(1, intval($page));
            $per_page = max(1, min(20, intval($per_page)));
            $chunk_size = max($per_page * 4, 20);

            $query_args = array(
                'post_type' => 'any',
                'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
                'posts_per_page' => $chunk_size,
                'offset' => ($page - 1) * $chunk_size,
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_query' => array(
                    array(
                        'key' => '_content_rank_generator_id',
                        'compare' => 'EXISTS',
                    ),
                ),
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

                $stored_content_model_type = (string) get_post_meta($post->ID, '_content_rank_content_model_type', true);
                if ($stored_content_model_type === '') {
                    $has_satellite_plan_marker = get_post_meta($post->ID, '_content_rank_content_plan_satellite_index', true) !== '';
                    $stored_content_model_type = $has_satellite_plan_marker ? 'satellite' : 'pillar';
                }
                if (class_exists('Content_Rank_Generator')) {
                    $stored_content_model_type = Content_Rank_Generator::normalize_content_model_type($stored_content_model_type);
                }
                if ($stored_content_model_type !== 'pillar') {
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
                wp_send_json_error(array('message' => 'Permissão negada.'), 403);
            }

            check_ajax_referer('content_rank_content_plan_posts_search', 'nonce');

            $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
            $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
            $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;

            $result = self::query_picker_posts($search, $page, $per_page);
            wp_send_json_success($result);
        }

        private static function limit_plain_text_words($text, $max_words = self::MAX_PLANNING_SOURCE_WORDS)
        {
            $text = self::normalize_plain_text($text);
            $max_words = max(1, intval($max_words));
            if ($text === '') {
                return '';
            }

            if (function_exists('wp_trim_words')) {
                return trim((string) wp_trim_words($text, $max_words));
            }

            $parts = preg_split('/\s+/', $text);
            if (!is_array($parts) || empty($parts)) {
                return $text;
            }

            return trim(implode(' ', array_slice($parts, 0, $max_words)));
        }

        private static function limit_item_for_planning($item, $max_words = self::MAX_PLANNING_SOURCE_WORDS)
        {
            $item = is_array($item) ? $item : array();
            $max_words = max(1, intval($max_words));

            foreach (array('excerpt', 'content', 'source_page_excerpt', 'source_page_content', 'source_page_outline') as $key) {
                if (!empty($item[$key])) {
                    $item[$key] = self::limit_plain_text_words((string) $item[$key], $max_words);
                }
            }

            return $item;
        }

        private static function build_default_generator_context($post_id = 0)
        {
            $post_id = max(0, intval($post_id));
            $synthetic_generator_id = $post_id > 0 ? (100000000 + $post_id) : 0;
            return array(
                'id' => $synthetic_generator_id,
                'name' => $post_id > 0 ? 'Lincagem manual' : get_bloginfo('name'),
                'source_type' => 'post',
                'generation_language' => class_exists('Content_Rank_Generator')
                    ? Content_Rank_Generator::get_default_generation_language()
                    : get_bloginfo('language'),
                'content_length_class' => class_exists('Content_Rank_Generator')
                    ? Content_Rank_Generator::get_default_content_length_class()
                    : 'medium',
                'prompt_model_key' => '',
                'outline_model_key' => '',
                'prompt_models' => class_exists('Content_Rank_Generator')
                    ? Content_Rank_Generator::get_default_prompt_models()
                    : array(),
                'prompt_models_json' => class_exists('Content_Rank_Generator')
                    ? wp_json_encode(Content_Rank_Generator::get_default_prompt_models(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : '',
                'seo_enabled' => 1,
                'post_type' => 'post',
                'post_status' => 'draft',
            );
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

        private static function normalize_plain_text($text)
        {
            $text = trim(wp_strip_all_tags((string) $text));
            $text = preg_replace('/\s+/', ' ', $text);
            return trim((string) $text);
        }

        private static function fetch_tavily_research($query, $max_results = 3)
        {
            $query = self::normalize_plain_text($query);
            if ($query === '' || !class_exists('Content_Rank_Generator_Helper')) {
                return array();
            }

            $settings = class_exists('Content_Rank_Generator') ? Content_Rank_Generator::get_settings() : array();
            $context = Content_Rank_Generator_Helper::fetch_tavily_search_context(
                $query,
                $max_results,
                !empty($settings['tavily_include_answer']),
                true
            );
            if (!is_array($context) || empty($context)) {
                return array();
            }

            return $context;
        }

        private static function format_tavily_research_for_prompt($context)
        {
            if (!is_array($context) || empty($context)) {
                return '';
            }

            $lines = array();
            if (!empty($context['query'])) {
                $lines[] = 'Consulta Tavily: ' . self::normalize_plain_text((string) $context['query']);
            }
            if (!empty($context['answer'])) {
                $lines[] = 'Resposta Tavily: ' . self::normalize_plain_text((string) $context['answer']);
            }
            if (!empty($context['results']) && is_array($context['results'])) {
                $count = 0;
                foreach ($context['results'] as $result) {
                    if (!is_array($result)) {
                        continue;
                    }
                    $count++;
                    if ($count > 5) {
                        break;
                    }
                    $result_line = trim(
                        ($count . '. ' . (!empty($result['title']) ? self::normalize_plain_text((string) $result['title']) : 'Resultado')) .
                        (!empty($result['content']) ? ' — ' . self::normalize_plain_text((string) $result['content']) : '') .
                        (!empty($result['url']) ? ' (' . esc_url_raw((string) $result['url']) . ')' : '')
                    );
                    if ($result_line !== '') {
                        $lines[] = $result_line;
                    }
                }
            }

            return self::limit_plain_text_words(implode("\n", $lines), 220);
        }

        private static function get_post_excerpt_text($post)
        {
            if (!$post instanceof WP_Post) {
                return '';
            }

            $excerpt = trim((string) $post->post_excerpt);
            if ($excerpt !== '') {
                return self::normalize_plain_text($excerpt);
            }

            return self::normalize_plain_text(wp_trim_words(wp_strip_all_tags((string) $post->post_content), 40));
        }

        private static function resolve_pillar_context($post_id)
        {
            $post = get_post($post_id);
            if (!$post) {
                return new WP_Error('content_rank_content_plan_post_missing', 'Post pilar nao encontrado.');
            }

            $generator_id = intval(get_post_meta($post_id, '_content_rank_generator_id', true));
            $generator = array();
            if ($generator_id > 0 && class_exists('Content_Rank_Generator')) {
                $generator = Content_Rank_Generator::get_generator($generator_id);
            }
            if (empty($generator) || !is_array($generator)) {
                $generator = self::build_default_generator_context($post_id);
            }

            $source_title = (string) get_post_meta($post_id, '_content_rank_source_title', true);
            $source_url = (string) get_post_meta($post_id, '_content_rank_source_url', true);
            $source_page_title = (string) get_post_meta($post_id, '_content_rank_source_page_title', true);
            $source_page_excerpt = (string) get_post_meta($post_id, '_content_rank_source_page_excerpt', true);
            $source_page_content = (string) get_post_meta($post_id, '_content_rank_source_page_content', true);
            $source_page_outline = (string) get_post_meta($post_id, '_content_rank_source_page_outline', true);
            $content_model_type = (string) get_post_meta($post_id, '_content_rank_content_model_type', true);
            if ($content_model_type === '' && isset($generator['content_model_type']) && class_exists('Content_Rank_Generator')) {
                $content_model_type = Content_Rank_Generator::normalize_content_model_type($generator['content_model_type']);
            }
            if ($content_model_type === '') {
                $content_model_type = 'pillar';
            }
            $source_url = $source_url !== '' ? $source_url : get_permalink($post_id);
            $source_page_title = $source_page_title !== '' ? $source_page_title : (string) $post->post_title;
            $source_page_excerpt = $source_page_excerpt !== '' ? $source_page_excerpt : self::get_post_excerpt_text($post);
            $source_page_content = $source_page_content !== '' ? $source_page_content : (string) $post->post_content;
            $source_page_outline = $source_page_outline !== '' ? $source_page_outline : '';

            $item = array(
                'guid' => (string) get_post_meta($post_id, '_content_rank_source_item_guid', true),
                'title' => (string) $post->post_title,
                'source_title' => $source_title !== '' ? $source_title : (string) $post->post_title,
                'permalink' => get_permalink($post_id),
                'source_url' => $source_url,
                'excerpt' => self::get_post_excerpt_text($post),
                'content' => self::normalize_plain_text($post->post_content),
                'feed_title' => !empty($generator['name']) ? (string) $generator['name'] : get_bloginfo('name'),
                'date' => (string) get_post_meta($post_id, '_content_rank_source_timestamp', true),
                'categories' => wp_get_post_terms($post_id, 'category', array('fields' => 'names')),
                'tags' => wp_get_post_terms($post_id, 'post_tag', array('fields' => 'names')),
                'source_image_url' => (string) get_post_meta($post_id, '_content_rank_source_image_url', true),
                'source_link_url' => (string) get_post_meta($post_id, '_content_rank_source_link_url', true),
                'source_link_text' => (string) get_post_meta($post_id, '_content_rank_source_link_text', true),
                'source_page_title' => $source_page_title,
                'source_page_excerpt' => $source_page_excerpt,
                'source_page_content' => $source_page_content,
                'source_page_content_html' => $source_page_content,
                'post_content_html' => (string) $post->post_content,
                'source_page_outline' => $source_page_outline,
                'source_page_outline_sections' => array(),
                'source_video_url' => (string) get_post_meta($post_id, '_content_rank_source_video_url', true),
                'source_video_embed_html' => (string) get_post_meta($post_id, '_content_rank_source_video_embed_html', true),
                'source_video_source' => (string) get_post_meta($post_id, '_content_rank_source_video_source', true),
                'source_image_selector_class' => (string) get_post_meta($post_id, '_content_rank_source_image_selector_class', true),
                'source_link_selector_class' => (string) get_post_meta($post_id, '_content_rank_source_link_selector_class', true),
                'source_context_enriched' => 1,
                'pillar_post_id' => intval($post_id),
                'content_model_type' => $content_model_type,
            );

            $outline_sections_raw = (string) get_post_meta($post_id, '_content_rank_source_page_outline_sections', true);
            if ($outline_sections_raw !== '') {
                $outline_sections = json_decode($outline_sections_raw, true);
                if (is_array($outline_sections)) {
                    $item['source_page_outline_sections'] = $outline_sections;
                }
            }

            if (empty($item['source_page_content']) && !empty($post->post_content)) {
                $item['source_page_content'] = self::normalize_plain_text($post->post_content);
            }
            if (empty($item['source_page_excerpt'])) {
                $item['source_page_excerpt'] = $item['excerpt'];
            }
            if (empty($item['source_page_title'])) {
                $item['source_page_title'] = $item['title'];
            }

            $item = self::limit_item_for_planning($item, self::MAX_PLANNING_SOURCE_WORDS);

            return array(
                'generator' => $generator,
                'item' => $item,
                'post' => $post,
            );
        }

        private static function get_pillar_subject_terms($pillar_title)
        {
            $pillar_title = html_entity_decode(wp_strip_all_tags((string) $pillar_title), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
            $pillar_title = self::normalize_plain_text($pillar_title);
            if ($pillar_title === '') {
                return array();
            }

            $generic_terms = array(
                'a', 'as', 'ao', 'aos', 'artigo', 'com', 'como', 'da', 'das', 'de', 'do', 'dos',
                'em', 'filme', 'filmes', 'forma', 'formas', 'guia', 'jogo', 'jogos', 'lista',
                'melhor', 'melhores', 'na', 'nas', 'no', 'nos', 'noticia', 'novo', 'novos',
                'para', 'passo', 'passos', 'por', 'que', 'recompensa', 'recompensas', 'serie',
                'series', 'sem', 'sobre', 'tutorial', 'um', 'uma', 'usar', 'uso', 'e', 'os',
                'of', 'the', 'this', 'with', 'and', 'best', 'new', 'how', 'to', 'in', 'on',
            );

            preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}_-]*/u', $pillar_title, $matches);
            $terms = array();
            foreach (!empty($matches[0]) ? $matches[0] : array() as $term) {
                $term = trim((string) $term);
                $normalized = function_exists('remove_accents') ? remove_accents($term) : $term;
                $normalized = strtolower($normalized);
                if ($normalized === '' || is_numeric($normalized) || strlen($normalized) < 4 || in_array($normalized, $generic_terms, true)) {
                    continue;
                }
                $terms[$normalized] = $term;
            }

            return array_values($terms);
        }

        private static function satellite_matches_pillar_subject($satellite_title, $anchor_phrase, $pillar_title)
        {
            $subject_terms = self::get_pillar_subject_terms($pillar_title);
            if (empty($subject_terms)) {
                return true;
            }

            $satellite_context = (string) $satellite_title . ' ' . (string) $anchor_phrase;
            $satellite_context = function_exists('remove_accents') ? remove_accents($satellite_context) : $satellite_context;
            $satellite_context = strtolower($satellite_context);
            foreach ($subject_terms as $term) {
                $term = function_exists('remove_accents') ? remove_accents((string) $term) : (string) $term;
                $term = strtolower($term);
                if ($term !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u', $satellite_context)) {
                    return true;
                }
            }

            return false;
        }

        private static function normalize_satellite_item($satellite, $index, $source_post_id = 0, $source_content = '', $pillar_title = '')
        {
            $satellite = is_array($satellite) ? $satellite : array();
            $title = !empty($satellite['title']) ? sanitize_text_field((string) $satellite['title']) : ('Satélite ' . intval($index));
            $slug = !empty($satellite['slug']) ? sanitize_title((string) $satellite['slug']) : sanitize_title($title);
            $focus_keyword = !empty($satellite['focus_keyword']) ? sanitize_text_field((string) $satellite['focus_keyword']) : '';
            $focus_keyword = trim((string) preg_replace('/\s+/u', ' ', $focus_keyword));
            $focus_keyword_word_count = $focus_keyword !== ''
                ? count(preg_split('/\s+/u', $focus_keyword, -1, PREG_SPLIT_NO_EMPTY))
                : 0;
            if ($focus_keyword_word_count < 2 || $focus_keyword_word_count > 10 || preg_match('/[.!?;:]$/u', $focus_keyword)) {
                $focus_keyword = '';
            }
            $candidate_anchor = !empty($satellite['anchor_phrase']) ? trim((string) $satellite['anchor_phrase']) : '';
            $candidate_anchor = $candidate_anchor !== ''
                ? trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($candidate_anchor)))
                : '';
            // Punctuation is not part of the anchor link. Keep the literal words
            // and remove only terminal punctuation before matching the source.
            $candidate_anchor = rtrim($candidate_anchor, ".!?;:");
            $candidate_anchor_word_count = $candidate_anchor !== ''
                ? count(preg_split('/\s+/u', $candidate_anchor, -1, PREG_SPLIT_NO_EMPTY))
                : 0;
            // An anchor is a short contextual phrase, never a complete paragraph.
            // Keeping this boundary in PHP also protects the link stage when the
            // model ignores the output instructions.
            if (
                $candidate_anchor_word_count < 3
                || $candidate_anchor_word_count > 14
                || preg_match('/[.!?](?:\s|$)/u', $candidate_anchor)
                || strpos($candidate_anchor, "\n") !== false
            ) {
                $candidate_anchor = '';
            }
            $anchor_phrase = $candidate_anchor !== '' && $source_content !== ''
                ? self::find_exact_source_anchor($source_content, $candidate_anchor)
                : '';
            if (
                $focus_keyword !== ''
                && !self::satellite_matches_pillar_subject(
                    $title . ' ' . $focus_keyword,
                    $anchor_phrase !== '' ? $anchor_phrase : $candidate_anchor,
                    $pillar_title
                )
            ) {
                $focus_keyword = '';
                $anchor_phrase = '';
            }

            return array(
                'index' => intval($index),
                'title' => $title,
                'slug' => $slug,
                'focus_keyword' => $focus_keyword,
                'anchor_phrase' => $anchor_phrase,
                'content_angle' => self::normalize_satellite_content_angle(isset($satellite['content_angle']) ? $satellite['content_angle'] : ''),
            );
        }

        private static function normalize_satellite_content_angle($value)
        {
            $value = sanitize_key((string) $value);
            $allowed = array('lista', 'artigo', 'review', 'faq', 'tutorial', 'comparativo');
            if (class_exists('Content_Rank_Generator')) {
                foreach (Content_Rank_Generator::get_prompt_models() as $prompt_model) {
                    if (!empty($prompt_model['key'])) {
                        $prompt_model_key = sanitize_key((string) $prompt_model['key']);
                        if ($prompt_model_key !== 'noticia') {
                            $allowed[] = $prompt_model_key;
                        }
                    }
                }
            }
            $allowed = array_values(array_unique($allowed));
            return in_array($value, $allowed, true) ? $value : 'artigo';
        }

        /**
         * Accept an anchor only when the exact phrase exists in readable source text.
         * Headings and existing links are excluded so generated links never target
         * a title or duplicate an existing link.
         */
        private static function find_exact_source_anchor($source_content, $candidate, $required_keyword = '')
        {
            $source_content = trim((string) $source_content);
            $candidate = trim(wp_strip_all_tags((string) $candidate));
            $required_keyword = trim(wp_strip_all_tags((string) $required_keyword));
            if ($source_content === '' || $candidate === '' || !class_exists('DOMDocument') || !class_exists('DOMXPath')) {
                return '';
            }

            $candidate_normalized = preg_replace('/\s+/u', ' ', $candidate);
            $keyword_normalized = preg_replace('/\s+/u', ' ', $required_keyword);
            if ($candidate_normalized === '') {
                return '';
            }
            $candidate_word_count = count(preg_split('/\s+/u', $candidate_normalized, -1, PREG_SPLIT_NO_EMPTY));
            if (
                $candidate_word_count < 3
                || $candidate_word_count > 14
                || preg_match('/[.!?](?:\s|$)/u', $candidate_normalized)
                || strpos($candidate_normalized, "\n") !== false
            ) {
                return '';
            }

            // The keyword identifies the target, but the link must use a wider
            // contextual phrase that literally contains the complete keyword.
            if ($keyword_normalized !== '' && function_exists('mb_stripos')) {
                $candidate_search = function_exists('remove_accents') ? remove_accents($candidate_normalized) : $candidate_normalized;
                $keyword_search = function_exists('remove_accents') ? remove_accents($keyword_normalized) : $keyword_normalized;
                if (mb_stripos($candidate_search, $keyword_search, 0, 'UTF-8') === false) {
                    return '';
                }

                $candidate_compare = mb_strtolower($candidate_search, 'UTF-8');
                $keyword_compare = mb_strtolower($keyword_search, 'UTF-8');
                if ($candidate_compare === $keyword_compare) {
                    return '';
                }
            }

            $dom = new DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><div id="content-rank-anchor-root">' . $source_content . '</div>');
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (!$loaded) {
                return '';
            }

            $xpath = new DOMXPath($dom);
            // Keep the lead clean: the first two paragraphs are never anchor sources.
            $query = '//*[@id="content-rank-anchor-root"]//p[count(preceding::p) >= 2 and not(ancestor-or-self::a) and not(ancestor-or-self::h1) and not(ancestor-or-self::h2) and not(ancestor-or-self::h3) and not(ancestor-or-self::h4) and not(ancestor-or-self::h5) and not(ancestor-or-self::h6) and not(ancestor-or-self::script) and not(ancestor-or-self::style) and not(ancestor-or-self::pre) and not(ancestor-or-self::code)]'
                . ' | //*[@id="content-rank-anchor-root"]//li[not(ancestor-or-self::a) and not(ancestor-or-self::h1) and not(ancestor-or-self::h2) and not(ancestor-or-self::h3) and not(ancestor-or-self::h4) and not(ancestor-or-self::h5) and not(ancestor-or-self::h6) and not(ancestor-or-self::script) and not(ancestor-or-self::style) and not(ancestor-or-self::pre) and not(ancestor-or-self::code)]'
                . ' | //*[@id="content-rank-anchor-root"]//blockquote[not(ancestor-or-self::a) and not(ancestor-or-self::h1) and not(ancestor-or-self::h2) and not(ancestor-or-self::h3) and not(ancestor-or-self::h4) and not(ancestor-or-self::h5) and not(ancestor-or-self::h6) and not(ancestor-or-self::script) and not(ancestor-or-self::style) and not(ancestor-or-self::pre) and not(ancestor-or-self::code)]';
            $nodes = $xpath->query($query);
            if (!$nodes) {
                return '';
            }

            foreach ($nodes as $node) {
                $text = preg_replace('/\s+/u', ' ', trim(self::get_readable_anchor_text($node)));
                if ($text === '' || $candidate_normalized === '' || !function_exists('mb_stripos')) {
                    continue;
                }
                $offset = mb_stripos($text, $candidate_normalized, 0, 'UTF-8');
                if ($offset !== false) {
                    return trim(mb_substr($text, $offset, mb_strlen($candidate_normalized, 'UTF-8'), 'UTF-8'));
                }
            }

            return '';
        }

        private static function get_readable_anchor_text($node)
        {
            if (!$node instanceof DOMNode) {
                return '';
            }
            if ($node->nodeType === XML_TEXT_NODE) {
                return (string) $node->nodeValue;
            }
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                return '';
            }

            $tag_name = strtolower((string) $node->nodeName);
            if (in_array($tag_name, array('a', 'script', 'style', 'pre', 'code', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true)) {
                return '';
            }

            $text = '';
            foreach ($node->childNodes as $child) {
                $text .= self::get_readable_anchor_text($child);
            }

            return $text;
        }

        private static function build_anchor_source_text($source_content)
        {
            $source_content = trim((string) $source_content);
            if ($source_content === '') {
                return '';
            }
            if (!class_exists('DOMDocument') || !class_exists('DOMXPath') || strpos($source_content, '<') === false) {
                return self::normalize_plain_text($source_content);
            }

            $dom = new DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><div id="content-rank-anchor-source">' . $source_content . '</div>');
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (!$loaded) {
                return self::normalize_plain_text($source_content);
            }

            $xpath = new DOMXPath($dom);
            $query = '//*[@id="content-rank-anchor-source"]//p[count(preceding::p) >= 2 and not(ancestor-or-self::a) and not(ancestor-or-self::h1) and not(ancestor-or-self::h2) and not(ancestor-or-self::h3) and not(ancestor-or-self::h4) and not(ancestor-or-self::h5) and not(ancestor-or-self::h6) and not(ancestor-or-self::script) and not(ancestor-or-self::style) and not(ancestor-or-self::pre) and not(ancestor-or-self::code)]'
                . ' | //*[@id="content-rank-anchor-source"]//li[not(ancestor-or-self::a) and not(ancestor-or-self::h1) and not(ancestor-or-self::h2) and not(ancestor-or-self::h3) and not(ancestor-or-self::h4) and not(ancestor-or-self::h5) and not(ancestor-or-self::h6) and not(ancestor-or-self::script) and not(ancestor-or-self::style) and not(ancestor-or-self::pre) and not(ancestor-or-self::code)]'
                . ' | //*[@id="content-rank-anchor-source"]//blockquote[not(ancestor-or-self::a) and not(ancestor-or-self::h1) and not(ancestor-or-self::h2) and not(ancestor-or-self::h3) and not(ancestor-or-self::h4) and not(ancestor-or-self::h5) and not(ancestor-or-self::h6) and not(ancestor-or-self::script) and not(ancestor-or-self::style) and not(ancestor-or-self::pre) and not(ancestor-or-self::code)]';
            $nodes = $xpath->query($query);
            $parts = array();
            if ($nodes) {
                foreach ($nodes as $node) {
                    $text = preg_replace('/\s+/u', ' ', trim(self::get_readable_anchor_text($node)));
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
            }

            return self::limit_plain_text_words(implode("\n", $parts), self::MAX_PLANNING_SOURCE_WORDS);
        }

        private static function build_satellite_schedule_datetime($generator, $index, $total_count = 0)
        {
            $generator = is_array($generator) ? $generator : array();
            $index = max(1, intval($index));
            $total_count = max(0, intval($total_count));
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');

            try {
                $base = new DateTimeImmutable('now', $timezone);
                $window_start = 0;
                $window_end = 0;
                if (class_exists('Content_Rank_Generator') && method_exists('Content_Rank_Generator', 'get_generator_daily_window')) {
                    $window = Content_Rank_Generator::get_generator_daily_window($generator, $base->getTimestamp());
                    $window_start = !empty($window[0]) ? intval($window[0]) : 0;
                    $window_end = !empty($window[1]) ? intval($window[1]) : 0;
                }
                if ($window_start <= 0 || $window_end <= 0) {
                    $day_start = $base->setTime(0, 0, 0);
                    $window_start = $day_start->setTime(6, 0, 0)->getTimestamp();
                    $window_end = $day_start->setTime(22, 0, 0)->getTimestamp();
                }

                $start_timestamp = max($base->getTimestamp(), $window_start);
                if ($base->getTimestamp() > $window_end) {
                    if (class_exists('Content_Rank_Generator') && method_exists('Content_Rank_Generator', 'get_generator_daily_window')) {
                        $next_window = Content_Rank_Generator::get_generator_daily_window($generator, $base->getTimestamp() + DAY_IN_SECONDS);
                        $window_start = !empty($next_window[0]) ? intval($next_window[0]) : $window_start;
                        $window_end = !empty($next_window[1]) ? intval($next_window[1]) : $window_end;
                    } else {
                        $day_start = $base->setTime(0, 0, 0)->modify('+1 day');
                        $window_start = $day_start->setTime(6, 0, 0)->getTimestamp();
                        $window_end = $day_start->setTime(22, 0, 0)->getTimestamp();
                    }
                    $start_timestamp = $window_start;
                } elseif ($start_timestamp > $window_end) {
                    $fallback_start = $window_end - max(0, ($total_count - 1)) * (10 * MINUTE_IN_SECONDS);
                    $start_timestamp = max($window_start, $fallback_start);
                }

                $available_window = max(0, $window_end - $start_timestamp);
                $desired_gap = 45 * MINUTE_IN_SECONDS;
                $gap = $desired_gap;
                if ($total_count > 1 && $available_window > 0) {
                    $gap = max(10 * MINUTE_IN_SECONDS, intval(floor($available_window / max(1, $total_count - 1))));
                    $gap = min($gap, $desired_gap);
                }

                $minutes_offset = ($index - 1) * max(10, intval($gap / MINUTE_IN_SECONDS));
                $scheduled = (new DateTimeImmutable('@' . $start_timestamp))->setTimezone($timezone);
                if ($minutes_offset > 0) {
                    $scheduled = $scheduled->modify('+' . $minutes_offset . ' minutes');
                }
                if ($scheduled->getTimestamp() < $start_timestamp) {
                    $scheduled = (new DateTimeImmutable('@' . $start_timestamp))->setTimezone($timezone);
                }
                if ($scheduled->getTimestamp() > $window_end) {
                    $scheduled = (new DateTimeImmutable('@' . $window_end))->setTimezone($timezone);
                }
                return $scheduled->format('Y-m-d H:i:s');
            } catch (Exception $exception) {
                return current_time('mysql');
            }
        }

        private static function normalize_plan_response($plan, $satellite_count, $source_post_id = 0, $source_content = '', $pillar_title = '')
        {
            $plan = is_array($plan) ? $plan : array();
            $satellite_count = max(1, intval($satellite_count));
            $normalized = array(
                'title' => !empty($plan['title']) ? sanitize_text_field((string) $plan['title']) : '',
                'slug' => !empty($plan['slug']) ? sanitize_title((string) $plan['slug']) : '',
                'satellites' => array(),
            );

            $raw_satellites = array();
            $satellite_sources = array($plan);
            foreach (array('plan', 'data', 'result', 'content_plan') as $container_key) {
                if (!empty($plan[$container_key]) && is_array($plan[$container_key])) {
                    $satellite_sources[] = $plan[$container_key];
                }
            }
            foreach ($satellite_sources as $satellite_source) {
                foreach (array('satellites', 'suggestions', 'items') as $list_key) {
                    if (!empty($satellite_source[$list_key]) && is_array($satellite_source[$list_key])) {
                        $raw_satellites = $satellite_source[$list_key];
                        break 2;
                    }
                }
            }

            $used_anchor_phrases = array();
            // Inspect the complete response. Invalid or duplicate candidates must
            // not prevent later valid suggestions from reaching the requested count.
            foreach ($raw_satellites as $index => $satellite) {
                if (count($normalized['satellites']) >= $satellite_count) {
                    break;
                }
                $normalized_satellite = self::normalize_satellite_item($satellite, $index + 1, $source_post_id, $source_content, $pillar_title);
                if ($normalized_satellite['focus_keyword'] === '' || $normalized_satellite['anchor_phrase'] === '') {
                    continue;
                }
                $anchor_key = function_exists('remove_accents')
                    ? remove_accents($normalized_satellite['anchor_phrase'])
                    : $normalized_satellite['anchor_phrase'];
                $anchor_key = strtolower(trim((string) preg_replace('/\s+/u', ' ', $anchor_key)));
                if ($anchor_key === '' || isset($used_anchor_phrases[$anchor_key])) {
                    continue;
                }
                $used_anchor_phrases[$anchor_key] = true;
                $normalized_satellite['index'] = count($normalized['satellites']) + 1;
                $normalized['satellites'][] = $normalized_satellite;
            }

            return $normalized;
        }

        private static function build_plan_prompt($generator, $item, $satellite_count, $planning_custom_prompt = '')
        {
            $satellite_count = max(1, intval($satellite_count));

            $pillar_title = !empty($item['title']) ? self::normalize_plain_text($item['title']) : '';
            // Use the final pillar post as the anchor source. The reference page
            // may be in another language or differ from the published content.
            if (!empty($item['post_content_html'])) {
                $pillar_source_content = (string) $item['post_content_html'];
            } elseif (!empty($item['source_page_content_html'])) {
                $pillar_source_content = (string) $item['source_page_content_html'];
            } elseif (!empty($item['source_page_content'])) {
                $pillar_source_content = (string) $item['source_page_content'];
            } else {
                $pillar_source_content = !empty($item['content']) ? (string) $item['content'] : '';
            }
            $pillar_content = self::limit_plain_text_words($pillar_source_content, self::MAX_PLANNING_SOURCE_WORDS);
            $anchor_source_content = self::build_anchor_source_text($pillar_source_content);
            $generation_language = !empty($generator['generation_language']) ? Content_Rank_Generator::normalize_generation_language_value($generator['generation_language']) : Content_Rank_Generator::get_default_generation_language();
            $planning_custom_prompt = self::normalize_plain_text($planning_custom_prompt);
            $available_prompt_models = class_exists('Content_Rank_Generator') ? Content_Rank_Generator::get_prompt_models($generator) : array();
            $available_prompt_model_lines = array();
            foreach ($available_prompt_models as $prompt_model) {
                if (is_array($prompt_model) && !empty($prompt_model['key']) && sanitize_key((string) $prompt_model['key']) !== 'noticia') {
                    $available_prompt_model_lines[] = Content_Rank_Generator::format_prompt_model_for_prompt($prompt_model);
                }
            }
            $available_prompt_models_text = implode("\n", $available_prompt_model_lines);

            $lines = array(
                'Voce e um estrategista editorial que planeja novos conteudos a partir de um post pilar.',
                'Idioma obrigatorio: gere todos os campos textuais em ' . $generation_language . '. Preserve outro idioma somente em nomes proprios, marcas, obras ou termos que precisem permanecer assim.',
                'Use somente o titulo do pilar e o texto fornecido abaixo. Nao use conhecimento externo, pesquisa, memoria ou frases que nao estejam no texto elegivel.',
                'PROCESSO OBRIGATORIO:',
                '1. Identifique a intencao central do titulo do pilar e crie oportunidades que aprofundem exatamente esse assunto.',
                '2. Para cada oportunidade, escolha primeiro uma frase existente no bloco elegivel abaixo.',
                '3. Copie em anchor_phrase somente um trecho contextual curto dessa frase, com 3 a 14 palavras consecutivas. A sequencia deve ser continua e literal, sem juntar partes diferentes.',
                '4. anchor_phrase nunca pode ser um paragrafo inteiro, o texto completo do post, duas frases, uma lista ou uma frase terminada em ponto. Nao traduza, resuma, corrija, complete, adapte ou reescreva o trecho.',
                '5. Depois transforme a intencao da frase em uma focus_keyword que pareca uma busca real feita por uma pessoa. A KW pode ser diferente da anchor_phrase, mas deve manter o mesmo assunto, objeto e problema pratico.',
                '6. Escolha exatamente ' . $satellite_count . ' oportunidades distintas e use uma anchor_phrase diferente em cada objeto. Se nao houver frases validas suficientes, retorne apenas as candidatas possiveis; nunca invente frases para completar a quantidade.',
                'QUALIDADE EDITORIAL OBRIGATORIA:',
                '- Crie pautas evergreen com uma pergunta, decisao, explicacao, comparacao, uso pratico, erro, impacto ou analise especifica.',
                '- Nao crie uma pauta que apenas informe o status atual, diga que algo existe, recomende acompanhar ou monitorar fontes, avise que algo pode expirar ou diga que novos itens podem surgir.',
                '- Nao transforme uma frase factual em uma noticia. O satelite deve desenvolver uma intencao de busca propria, mas sempre dentro do mesmo assunto do pilar.',
                '- Evite titulos obvios ou vazios como "entenda que recompensas existem", "fique atento aos codigos" ou "acompanhe as novidades".',
                '- A focus_keyword deve ser uma consulta especifica, com entidade e intencao. Ela precisa deixar claro o que a pessoa quer descobrir, fazer, comparar ou resolver.',
                '- Nunca use como KW um rotulo abstrato ou amplo, como "checagem de codigos", "acompanhamento de fontes oficiais", "impacto das recompensas gratuitas", "momento de surgimento de codigos" ou apenas "recompensas gratuitas".',
                '- Prefira consultas concretas como "como resgatar codigos TOUCHLINE no Roblox", "onde inserir codigo TOUCHLINE", "o que os codigos TOUCHLINE dao no Roblox" ou "como usar recompensas do TOUCHLINE no jogo".',
                '- Se a KW puder servir para qualquer jogo, produto, plataforma ou assunto apenas trocando o nome, ela esta ampla demais. Torne a entidade, a acao e o problema especificos.',
                '- Nao escolha FAQ apenas para variar os tipos. Use FAQ somente quando houver uma duvida objetiva. Use comparativo somente quando existirem dois objetos concretos para comparar. Use tutorial somente quando houver uma acao com etapas.',
                '- content_angle nunca pode ser noticia. Use o modelo adequado ao tipo de busca, nao uma palavra generica ou um formato escolhido apenas para diversificar a lista.',
                'A resposta deve trazer as chaves: title, satellites.',
                'satellites deve ser um array com no maximo ' . $satellite_count . ' objetos.',
                'Cada objeto deve ter: title, focus_keyword, anchor_phrase, content_angle.',
                'Defina a focus_keyword de cada satélite como uma expressão de busca curta, natural e específica, com 2 a 10 palavras. Ela pode ser derivada da intenção da âncora, mas não pode ser genérica ou desconectada do assunto do pilar.',
                'Se nao conseguir copiar uma frase literal do bloco elegivel, use anchor_phrase vazio. Nunca use frases do titulo, H1-H6, menus, links, rodape, pesquisa externa ou conhecimento geral.',
                'Use content_angle somente com um dos modelos existentes abaixo, exceto noticia. Use o key exato, sem inventar categorias como "analise" ou "contexto", e nunca coloque o nome do modelo como prefixo artificial do title.',
                'Apenas o texto entre INICIO DO TEXTO ELEGIVEL e FIM DO TEXTO ELEGIVEL pode ser usado como anchor_phrase.',
                $planning_custom_prompt !== '' ? 'Prompt personalizado do usuario: ' . $planning_custom_prompt : '',
                'Titulo do post pilar: ' . $pillar_title,
                "INICIO DO TEXTO ELEGIVEL PARA ANCHOR_PHRASE\n" . $anchor_source_content . "\nFIM DO TEXTO ELEGIVEL PARA ANCHOR_PHRASE",
                'Modelos existentes para content_angle:' . ($available_prompt_models_text !== '' ? "\n" . $available_prompt_models_text : ' nenhum modelo configurado.'),
            );

            $lines = array_values(array_filter($lines, 'strlen'));

            return implode("\n", $lines);
        }

        private static function get_plan_meta($post_id)
        {
            $raw = (string) get_post_meta($post_id, self::META_PLAN_JSON, true);
            if ($raw === '') {
                return array();
            }

            $plan = json_decode($raw, true);
            if (is_array($plan)) {
                $pillar_post_id = !empty($plan['pillar_post_id']) ? intval($plan['pillar_post_id']) : intval($post_id);
                $pillar_title = !empty($plan['pillar_title'])
                    ? (string) $plan['pillar_title']
                    : ($pillar_post_id > 0 ? get_the_title($pillar_post_id) : '');
                $pillar_content = $pillar_post_id > 0 ? (string) get_post_field('post_content', $pillar_post_id) : '';
                if (!empty($plan['satellites']) && is_array($plan['satellites'])) {
                    $normalized = self::normalize_plan_response(
                        $plan,
                        count($plan['satellites']),
                        $pillar_post_id,
                        $pillar_content,
                        $pillar_title
                    );
                    $plan['satellites'] = $normalized['satellites'];
                }
                return $plan;
            }

            return self::recover_plan_meta($raw);
        }

        /**
         * Recovers the useful plan fields when an older Tavily payload contains invalid JSON.
         */
        private static function recover_plan_meta($raw)
        {
            $raw = (string) $raw;
            $satellites_key = strpos($raw, '"satellites"');
            if ($satellites_key === false) {
                return array();
            }

            $array_start = strpos($raw, '[', $satellites_key);
            if ($array_start === false) {
                return array();
            }

            $depth = 0;
            $in_string = false;
            $escaped = false;
            $array_end = null;
            $length = strlen($raw);
            for ($index = $array_start; $index < $length; $index++) {
                $character = $raw[$index];
                if ($in_string) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($character === '\\') {
                        $escaped = true;
                    } elseif ($character === '"') {
                        $in_string = false;
                    }
                    continue;
                }

                if ($character === '"') {
                    $in_string = true;
                    continue;
                }
                if ($character === '[') {
                    $depth++;
                } elseif ($character === ']') {
                    $depth--;
                    if ($depth === 0) {
                        $array_end = $index;
                        break;
                    }
                }
            }

            if ($array_end === null) {
                return array();
            }

            $satellites = json_decode(substr($raw, $array_start, $array_end - $array_start + 1), true);
            if (!is_array($satellites)) {
                return array();
            }

            $recovered = array('satellites' => $satellites);
            foreach (array('title', 'slug', 'generated_at', 'pillar_post_id', 'generator_id', 'satellite_count') as $key) {
                $pattern = '/"' . preg_quote($key, '/') . '"\s*:\s*("(?:\\\\.|[^"\\\\])*"|-?\d+(?:\.\d+)?)/';
                if (!preg_match($pattern, $raw, $matches)) {
                    continue;
                }

                $value = $matches[1];
                if ($value !== '' && $value[0] === '"') {
                    $decoded_value = json_decode($value, true);
                    $recovered[$key] = is_string($decoded_value) ? $decoded_value : trim($value, '"');
                } else {
                    $recovered[$key] = strpos($value, '.') !== false ? (float) $value : (int) $value;
                }
            }

            return $recovered;
        }

        private static function encode_plan_meta($plan)
        {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }

            $encoded = wp_json_encode($plan, $flags);
            if (is_string($encoded) && json_last_error() === JSON_ERROR_NONE) {
                return $encoded;
            }

            // Do not let optional Tavily research invalidate the plan that renders the table.
            $fallback = is_array($plan) ? $plan : array();
            unset($fallback['tavily_context']);
            $encoded = wp_json_encode($fallback, $flags);

            return is_string($encoded) ? $encoded : '{}';
        }

        private static function render_notice()
        {
            $notice = self::get_request_param('content_rank_notice', '');
            if ($notice === '') {
                return;
            }

            $message = '';
            $class = 'notice-success';

            if ($notice === 'plan_saved') {
                $message = 'Plano editorial salvo com sucesso.';
            } elseif ($notice === 'plan_cleared') {
                $message = 'Plano editorial removido.';
            } elseif ($notice === 'satellites_generated') {
                $count = intval(self::get_request_param('content_rank_count', 0));
                $message = $count > 0
                    ? sprintf('Satélites gerados com sucesso. %d post(s) criado(s) e linkados ao pilar.', $count)
                    : 'Satélites gerados com sucesso.';
            } elseif ($notice === 'satellite_error') {
                $message = self::get_request_param('content_rank_message', 'Não foi possível gerar os satélites.');
                $class = 'notice-error';
            } elseif ($notice === 'plan_error') {
                $message = self::get_request_param('content_rank_message', 'Não foi possível gerar o plano editorial.');
                $class = 'notice-error';
            }

            if ($message === '') {
                return;
            }

            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        public function handle_generate_plan()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Permissão negada.');
            }

            check_admin_referer('content_rank_generate_content_plan', 'content_rank_content_plan_nonce');
            self::lift_execution_time_limit(300);

            $post_id = isset($_POST['pillar_post_id']) ? intval($_POST['pillar_post_id']) : 0;
            $satellite_count = isset($_POST['satellite_count']) ? intval($_POST['satellite_count']) : 5;
            $satellite_count = max(1, min(12, $satellite_count));
            $planning_custom_prompt = isset($_POST['planning_custom_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['planning_custom_prompt'])) : '';
            update_post_meta($post_id, self::META_PLAN_PLANNING_CUSTOM_PROMPT, $planning_custom_prompt);

            $context = self::resolve_pillar_context($post_id);
            if (is_wp_error($context)) {
                $redirect = add_query_arg(array(
                    'page' => self::PAGE_SLUG,
                    'post_id' => $post_id,
                    'content_rank_notice' => 'plan_error',
                    'content_rank_message' => $context->get_error_message(),
                ), admin_url('admin.php'));
                wp_safe_redirect($redirect);
                exit;
            }

            $generator = $context['generator'];
            $item = $context['item'];
            $item = self::limit_item_for_planning($item, self::MAX_PLANNING_SOURCE_WORDS);
            $prompt = self::build_plan_prompt($generator, $item, $satellite_count, $planning_custom_prompt);
            $plan = Content_Rank_Generator::request_openai_json($generator, $prompt, array(
                'stage' => 'content_plan',
                'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
                'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                'item_title' => !empty($item['title']) ? $item['title'] : '',
                'preserve_extra_fields' => 1,
                'allow_missing_content_html' => 1,
                'source_context_enriched' => 1,
                'satellite_count' => $satellite_count,
                'response_schema_name' => 'content_rank_satellite_plan',
                'response_schema_description' => 'Planejamento de conteudos satelites relacionado ao mesmo assunto central do post pilar.',
                'response_schema' => array(
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => array(
                        'title' => array('type' => 'string'),
                        'slug' => array('type' => 'string'),
                        'satellites' => array(
                            'type' => 'array',
                            'items' => array(
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => array(
                                    'title' => array('type' => 'string'),
                                    'focus_keyword' => array('type' => 'string'),
                                    'anchor_phrase' => array('type' => 'string'),
                                    'content_angle' => array('type' => 'string'),
                                ),
                                'required' => array('title', 'focus_keyword', 'anchor_phrase', 'content_angle'),
                            ),
                        ),
                    ),
                    'required' => array('title', 'slug', 'satellites'),
                ),
            ));

            if (is_wp_error($plan)) {
                $redirect = add_query_arg(array(
                    'page' => self::PAGE_SLUG,
                    'post_id' => $post_id,
                    'content_rank_notice' => 'plan_error',
                    'content_rank_message' => $plan->get_error_message(),
                ), admin_url('admin.php'));
                wp_safe_redirect($redirect);
                exit;
            }

            $normalized_plan = self::normalize_plan_response(
                $plan,
                $satellite_count,
                $post_id,
                (string) get_post_field('post_content', $post_id),
                !empty($item['title']) ? (string) $item['title'] : ''
            );
            $normalized_plan['pillar_post_id'] = $post_id;
            $normalized_plan['generator_id'] = intval($generator['id']);
            $normalized_plan['satellite_count'] = $satellite_count;
            $normalized_plan['generated_at'] = current_time('mysql');
            $normalized_plan['pillar_title'] = !empty($item['title']) ? $item['title'] : '';
            $normalized_plan['pillar_url'] = !empty($item['permalink']) ? $item['permalink'] : '';
            $normalized_plan['pillar_categories'] = !empty($item['categories']) && is_array($item['categories']) ? array_values($item['categories']) : array();
            $normalized_plan['pillar_tags'] = !empty($item['tags']) && is_array($item['tags']) ? array_values($item['tags']) : array();
            $normalized_plan['content_model_type'] = !empty($item['content_model_type']) ? Content_Rank_Generator::normalize_content_model_type($item['content_model_type']) : 'pillar';
            $normalized_plan['content_model_label'] = Content_Rank_Generator::get_content_model_label($normalized_plan['content_model_type']);
            $normalized_plan['planning_custom_prompt'] = $planning_custom_prompt;
            $normalized_plan['tavily_query'] = '';
            $normalized_plan['tavily_context'] = array();
            $normalized_plan['tavily_text'] = '';
            $normalized_plan['recommended_prompt_model_key'] = '';
            $normalized_plan['recommended_outline_model_key'] = '';
            $normalized_plan['outline_context'] = array();

            update_post_meta($post_id, self::META_PLAN_JSON, self::encode_plan_meta($normalized_plan));
            delete_post_meta($post_id, self::META_PLAN_TAVILY_JSON);
            update_post_meta($post_id, self::META_PLAN_GENERATED_AT, current_time('mysql'));
            update_post_meta($post_id, self::META_PLAN_GENERATOR_ID, intval($generator['id']));
            update_post_meta($post_id, self::META_PLAN_PILLAR_POST_ID, $post_id);
            update_post_meta($post_id, self::META_PLAN_SATELLITE_COUNT, $satellite_count);
            update_post_meta($post_id, self::META_PLAN_CONTENT_MODEL_TYPE, $normalized_plan['content_model_type']);
            update_post_meta($post_id, self::META_PLAN_PROMPT_MODEL_KEY, $normalized_plan['recommended_prompt_model_key']);
            update_post_meta($post_id, self::META_PLAN_OUTLINE_MODEL_KEY, $normalized_plan['recommended_outline_model_key']);
            delete_post_meta($post_id, '_content_rank_content_plan_satellite_post_ids');
            delete_post_meta($post_id, '_content_rank_content_plan_generated_satellites');

            $redirect = add_query_arg(array(
                'page' => self::PAGE_SLUG,
                'post_id' => $post_id,
                'content_rank_notice' => 'plan_saved',
            ), admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        public function handle_clear_plan()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Permissão negada.');
            }

            check_admin_referer('content_rank_clear_content_plan', 'content_rank_content_plan_nonce');

            $post_id = isset($_POST['pillar_post_id']) ? intval($_POST['pillar_post_id']) : 0;
            if ($post_id > 0) {
                delete_post_meta($post_id, self::META_PLAN_JSON);
                delete_post_meta($post_id, self::META_PLAN_GENERATED_AT);
                delete_post_meta($post_id, self::META_PLAN_GENERATOR_ID);
                delete_post_meta($post_id, self::META_PLAN_PILLAR_POST_ID);
                delete_post_meta($post_id, self::META_PLAN_SATELLITE_COUNT);
                delete_post_meta($post_id, self::META_PLAN_CONTENT_MODEL_TYPE);
                delete_post_meta($post_id, self::META_PLAN_PROMPT_MODEL_KEY);
                delete_post_meta($post_id, self::META_PLAN_OUTLINE_MODEL_KEY);
                delete_post_meta($post_id, self::META_PLAN_PLANNING_CUSTOM_PROMPT);
                delete_post_meta($post_id, self::META_PLAN_TAVILY_JSON);
                delete_post_meta($post_id, '_content_rank_content_plan_satellite_post_ids');
                delete_post_meta($post_id, '_content_rank_content_plan_generated_satellites');
            }

            $redirect = add_query_arg(array(
                'page' => self::PAGE_SLUG,
                'post_id' => $post_id,
                'content_rank_notice' => 'plan_cleared',
            ), admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        private static function build_satellite_generation_item($context, $plan, $satellite, $tavily_context = array())
        {
            $context = is_array($context) ? $context : array();
            $plan = is_array($plan) ? $plan : array();
            $tavily_context = is_array($tavily_context) ? $tavily_context : array();

            $generator = !empty($context['generator']) && is_array($context['generator']) ? $context['generator'] : array();
            $post = !empty($context['post']) && $context['post'] instanceof WP_Post ? $context['post'] : null;
            $pillar_post_id = $post ? intval($post->ID) : intval(!empty($plan['pillar_post_id']) ? $plan['pillar_post_id'] : 0);
            $pillar_title = '';
            if (!empty($plan['pillar_title'])) {
                $pillar_title = self::normalize_plain_text((string) $plan['pillar_title']);
            } elseif ($post instanceof WP_Post) {
                $pillar_title = self::normalize_plain_text(get_the_title($post));
            }
            if ($pillar_title === '') {
                $pillar_title = !empty($satellite['title']) ? self::normalize_plain_text((string) $satellite['title']) : 'Pilar';
            }

            $pillar_url = '';
            if (!empty($plan['pillar_url'])) {
                $pillar_url = esc_url_raw((string) $plan['pillar_url']);
            } elseif ($post instanceof WP_Post) {
                $pillar_url = esc_url_raw(get_permalink($post));
            }

            $pillar_content = '';
            if (!empty($context['item']) && is_array($context['item']) && !empty($context['item']['content'])) {
                $pillar_content = self::normalize_plain_text((string) $context['item']['content']);
            } elseif ($post instanceof WP_Post) {
                $pillar_content = self::normalize_plain_text((string) $post->post_content);
            }

            // Preserve the validated plan data while rebuilding the generation item.
            // Re-normalizing without the pillar context would erase valid anchors.
            $satellite = self::normalize_satellite_item(
                $satellite,
                isset($satellite['index']) ? intval($satellite['index']) : 1,
                $pillar_post_id,
                $pillar_content,
                $pillar_title
            );

            $content_angle = !empty($satellite['content_angle']) ? self::normalize_plain_text((string) $satellite['content_angle']) : '';
            $anchor_phrase = !empty($satellite['anchor_phrase']) ? self::normalize_plain_text((string) $satellite['anchor_phrase']) : '';
            $tavily_query = !empty($tavily_context['query']) ? self::normalize_plain_text((string) $tavily_context['query']) : '';
            $tavily_text = self::format_tavily_research_for_prompt($tavily_context);
            $source_content = implode("\n", array_filter(array(
                'Pilar: ' . $pillar_title,
                $pillar_content !== '' ? 'Conteúdo do pilar: ' . $pillar_content : '',
                $tavily_text !== '' ? 'Pesquisa externa auxiliar: ' . $tavily_text : '',
                $content_angle !== '' ? 'Tipo de conteúdo: ' . $content_angle : '',
                $anchor_phrase !== '' ? 'Âncora planejada: ' . $anchor_phrase : '',
            )));

            return array(
                'guid' => 'content-plan:' . $pillar_post_id . ':' . intval($satellite['index']),
                'title' => $satellite['title'],
                'source_title' => $satellite['title'],
                'keyword' => !empty($satellite['focus_keyword']) ? $satellite['focus_keyword'] : $satellite['title'],
                'permalink' => '',
                'source_url' => $pillar_url,
                'excerpt' => '',
                'content' => $source_content,
                'feed_title' => !empty($generator['name']) ? (string) $generator['name'] : get_bloginfo('name'),
                'date' => self::build_satellite_schedule_datetime($generator, intval($satellite['index']), !empty($plan['satellites']) && is_array($plan['satellites']) ? count($plan['satellites']) : 0),
                'categories' => !empty($context['item']['categories']) && is_array($context['item']['categories']) ? $context['item']['categories'] : array(),
                'tags' => !empty($context['item']['tags']) && is_array($context['item']['tags']) ? $context['item']['tags'] : array(),
                'source_page_title' => $pillar_title,
                'source_page_excerpt' => '',
                'source_page_content' => $pillar_content,
                'source_page_outline' => '',
                'source_page_outline_sections' => array(),
                'source_context_enriched' => 1,
                'tavily_query' => $tavily_query,
                'tavily_context' => $tavily_context,
                'tavily_text' => $tavily_text,
                'content_model_type' => 'satellite',
                'final_slug' => !empty($satellite['slug']) ? $satellite['slug'] : sanitize_title($satellite['title']),
                'content_plan_pillar_post_id' => $pillar_post_id,
                'content_plan_pillar_title' => $pillar_title,
                'content_plan_pillar_url' => $pillar_url,
                'content_plan_satellite_index' => intval($satellite['index']),
                'content_plan_satellite_title' => $satellite['title'],
                'content_plan_satellite_slug' => !empty($satellite['slug']) ? $satellite['slug'] : sanitize_title($satellite['title']),
                'content_plan_satellite_anchor_phrase' => $anchor_phrase,
                'content_plan_satellite_focus_keyword' => !empty($satellite['focus_keyword']) ? $satellite['focus_keyword'] : '',
                'content_plan_satellite_content_angle' => $content_angle,
                'content_plan_satellite' => $satellite,
                'content_plan_backlink_links' => array(
                    array(
                        'title' => $pillar_title !== '' ? $pillar_title : 'Voltar ao pilar',
                        'url' => $pillar_url,
                    ),
                ),
                'content_plan_backlink_label' => 'Voltar ao pilar:',
            );
        }

        private static function persist_generated_satellite_links($pillar_post_id, $plan, $generated_satellite_posts)
        {
            $pillar_post_id = intval($pillar_post_id);
            $generated_satellite_posts = is_array($generated_satellite_posts) ? array_values($generated_satellite_posts) : array();
            if ($pillar_post_id <= 0 || empty($generated_satellite_posts)) {
                return false;
            }

            $satellite_ids = array();
            foreach ($generated_satellite_posts as $generated) {
                if (empty($generated['post_id'])) {
                    continue;
                }
                $post_id = intval($generated['post_id']);
                if ($post_id <= 0) {
                    continue;
                }
                $satellite_ids[] = $post_id;
            }

            // Legacy immediate-link block intentionally remains unreachable. Links
            // are now applied only by handle_satellite_publish_transition().
            if (false) {
                $current_content = Content_Rank_Generator_Helper::inject_content_plan_links_into_content(
                    $current_content,
                    $pillar_links,
                    'pillar',
                    'Você também pode gostar de:'
                );
                wp_update_post(array(
                    'ID' => $pillar_post_id,
                    'post_content' => $current_content,
                ));
            }

            update_post_meta($pillar_post_id, '_content_rank_content_plan_satellite_post_ids', wp_json_encode($satellite_ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            update_post_meta($pillar_post_id, '_content_rank_content_plan_generated_satellites', wp_json_encode($generated_satellite_posts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (is_array($plan) && !empty($plan)) {
                $plan['generated_satellite_post_ids'] = $satellite_ids;
                $plan['generated_satellite_posts'] = $generated_satellite_posts;
                update_post_meta($pillar_post_id, self::META_PLAN_JSON, self::encode_plan_meta($plan));
            }

            return true;
        }

        public function handle_generate_satellites()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Permissão negada.');
            }

            check_admin_referer('content_rank_generate_content_satellites', 'content_rank_content_satellites_nonce');
            self::lift_execution_time_limit(300);

            $post_id = isset($_POST['pillar_post_id']) ? intval($_POST['pillar_post_id']) : 0;
            $context = self::resolve_pillar_context($post_id);
            if (is_wp_error($context)) {
                $redirect = add_query_arg(array(
                    'page' => self::PAGE_SLUG,
                    'post_id' => $post_id,
                    'content_rank_notice' => 'plan_error',
                    'content_rank_message' => $context->get_error_message(),
                ), admin_url('admin.php'));
                wp_safe_redirect($redirect);
                exit;
            }

            $plan = self::get_plan_meta($post_id);
            $satellites = !empty($plan['satellites']) && is_array($plan['satellites']) ? $plan['satellites'] : array();
            if (empty($satellites)) {
                $redirect = add_query_arg(array(
                    'page' => self::PAGE_SLUG,
                    'post_id' => $post_id,
                    'content_rank_notice' => 'satellite_error',
                    'content_rank_message' => 'Não existe plano salvo com satélites para gerar.',
                ), admin_url('admin.php'));
                wp_safe_redirect($redirect);
                exit;
            }

            $generator = $context['generator'];
            $satellite_generator = $generator;
            $satellite_generator['source_type'] = 'rss';
            $satellite_generator['content_model_type'] = 'satellite';
            $satellite_generator['use_final_slug'] = 1;
            $satellite_generator['post_status'] = 'future';

            $generated_posts = array();
            $errors = array();
            $global_settings = class_exists('Content_Rank_Generator') ? Content_Rank_Generator::get_settings() : array();
            $tavily_enabled = !empty($global_settings['tavily_enabled']);
            $tavily_max_results = !empty($global_settings['tavily_max_results']) ? intval($global_settings['tavily_max_results']) : 3;

            foreach ($satellites as $satellite) {
                $normalized_satellite = self::normalize_satellite_item(
                    $satellite,
                    isset($satellite['index']) ? intval($satellite['index']) : (count($generated_posts) + 1),
                    $post_id,
                    (string) get_post_field('post_content', $post_id),
                    !empty($plan['pillar_title']) ? (string) $plan['pillar_title'] : get_the_title($post_id)
                );
                if ($normalized_satellite['focus_keyword'] === '') {
                    $errors[] = 'A sugestao foi descartada porque nao manteve o assunto principal do post-pilar.';
                    continue;
                }
                $satellite_tavily_context = array();
                if ($tavily_enabled) {
                    $satellite_query = !empty($normalized_satellite['focus_keyword'])
                        ? self::normalize_plain_text((string) $normalized_satellite['focus_keyword'])
                        : self::normalize_plain_text((string) $normalized_satellite['title']);
                    if ($satellite_query !== '') {
                        $satellite_tavily_context = self::fetch_tavily_research($satellite_query, $tavily_max_results);
                    }
                }
                $item = self::build_satellite_generation_item($context, $plan, $normalized_satellite, $satellite_tavily_context);
                if (!Content_Rank_Generator::claim_item_processing_slot($satellite_generator['id'], $item)) {
                    $errors[] = 'Item já estava em processamento.';
                    continue;
                }
                $post_result = Content_Rank_Generator::create_post_from_generator_item($satellite_generator, $item);
                if (is_wp_error($post_result)) {
                    Content_Rank_Generator::mark_item_failed($satellite_generator['id'], $item, $post_result->get_error_code(), $post_result->get_error_message());
                    $errors[] = $post_result->get_error_message();
                    continue;
                }

                $generated_posts[] = array(
                    'post_id' => intval($post_result),
                    'title' => $normalized_satellite['title'],
                    'slug' => (string) get_post_field('post_name', $post_result),
                    'url' => get_permalink($post_result),
                    'anchor_phrase' => $normalized_satellite['anchor_phrase'],
                );
            }

            self::persist_generated_satellite_links($post_id, $plan, $generated_posts);

            $redirect_args = array(
                'page' => self::PAGE_SLUG,
                'post_id' => $post_id,
            );
            if (!empty($generated_posts)) {
                $redirect_args['content_rank_notice'] = 'satellites_generated';
                $redirect_args['content_rank_count'] = count($generated_posts);
            } else {
                $redirect_args['content_rank_notice'] = 'satellite_error';
                $redirect_args['content_rank_message'] = !empty($errors) ? implode(' | ', array_slice($errors, 0, 3)) : 'Não foi possível gerar os satélites.';
            }

            $redirect = add_query_arg($redirect_args, admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        public function handle_satellite_publish_transition($new_status, $old_status, $post)
        {
            if ($new_status !== 'publish' || $old_status === 'publish' || !($post instanceof WP_Post)) {
                return;
            }

            if (intval(get_post_meta($post->ID, 'content_plan_pillar_post_id', true)) > 0) {
                self::apply_satellite_link_when_published($post->ID);
            }

            $pending_satellites = get_posts(array(
                'post_type' => 'any',
                'post_status' => 'publish',
                'posts_per_page' => 50,
                'fields' => 'ids',
                'meta_query' => array(
                    array(
                        'key' => 'content_plan_pillar_post_id',
                        'value' => $post->ID,
                        'compare' => '=',
                    ),
                ),
            ));
            foreach ($pending_satellites as $satellite_id) {
                self::apply_satellite_link_when_published($satellite_id);
            }
        }

        private static function apply_satellite_link_when_published($satellite_post_id)
        {
            $satellite_post_id = intval($satellite_post_id);
            $pillar_post_id = intval(get_post_meta($satellite_post_id, 'content_plan_pillar_post_id', true));
            $anchor_phrase = trim((string) get_post_meta($satellite_post_id, 'content_plan_satellite_anchor_phrase', true));
            if ($satellite_post_id <= 0 || $pillar_post_id <= 0 || $anchor_phrase === '') {
                if ($satellite_post_id > 0) {
                    update_post_meta($satellite_post_id, 'content_plan_link_status', $anchor_phrase === '' ? 'no_exact_anchor' : 'pending');
                }
                return false;
            }

            $pillar = get_post($pillar_post_id);
            if (!$pillar || $pillar->post_status !== 'publish') {
                update_post_meta($satellite_post_id, 'content_plan_link_status', 'pending_pillar');
                return false;
            }

            $pillar_content = (string) $pillar->post_content;
            $exact_anchor = self::find_exact_source_anchor($pillar_content, $anchor_phrase);
            if ($exact_anchor === '') {
                update_post_meta($satellite_post_id, 'content_plan_link_status', 'no_exact_anchor');
                return false;
            }

            $updated_content = Content_Rank_Generator_Helper::inject_content_plan_links_into_content(
                $pillar_content,
                array(array(
                    'anchor_phrase' => $exact_anchor,
                    'url' => get_permalink($satellite_post_id),
                )),
                'pillar',
                ''
            );
            if ($updated_content === $pillar_content) {
                update_post_meta($satellite_post_id, 'content_plan_link_status', 'already_linked_or_not_applied');
                return false;
            }

            $result = wp_update_post(array(
                'ID' => $pillar_post_id,
                'post_content' => $updated_content,
            ), true);
            if (is_wp_error($result)) {
                update_post_meta($satellite_post_id, 'content_plan_link_status', 'error');
                return false;
            }

            update_post_meta($satellite_post_id, 'content_plan_link_status', 'applied');
            update_post_meta($satellite_post_id, 'content_plan_link_anchor', $exact_anchor);
            update_post_meta($satellite_post_id, 'content_plan_link_target_url', esc_url_raw(get_permalink($satellite_post_id)));
            return true;
        }

        private static function render_picker_post_button($item, $selected_post_id = 0)
        {
            $item = is_array($item) ? $item : array();
            $item_id = isset($item['id']) ? intval($item['id']) : 0;
            if ($item_id <= 0) {
                return;
            }

            $is_selected = $selected_post_id > 0 && $selected_post_id === $item_id;
            $classes = array(
                'content-rank-plan-picker-item',
                'w-full',
                'rounded-xl',
                'border',
                'px-4',
                'py-3',
                'text-left',
                'text-sm',
                'transition',
                'focus:outline-none',
                'focus:ring-2',
                'focus:ring-indigo-200',
            );
            if ($is_selected) {
                $classes[] = 'border-indigo-500';
                $classes[] = 'bg-indigo-50';
            } else {
                $classes[] = 'border-slate-200';
                $classes[] = 'bg-white';
                $classes[] = 'hover:bg-slate-50';
            }

            echo '<button type="button" class="' . esc_attr(implode(' ', $classes)) . '" data-post-id="' . esc_attr($item_id) . '" data-post-title="' . esc_attr(!empty($item['title']) ? $item['title'] : '') . '" data-post-url="' . esc_attr(!empty($item['url']) ? $item['url'] : '') . '" data-post-type="' . esc_attr(!empty($item['post_type']) ? $item['post_type'] : 'post') . '">';
            echo esc_html(!empty($item['title']) ? $item['title'] : 'Post');
            echo '</button>';
        }

        private static function render_posts_selector($selected_post_id = 0)
        {
            $selected_post_id = intval($selected_post_id);
            $selected_post = $selected_post_id > 0 ? get_post($selected_post_id) : null;
            $search_nonce = wp_create_nonce('content_rank_content_plan_posts_search');
            $initial_results = self::query_picker_posts('', 1, 10);
            $items = !empty($initial_results['items']) && is_array($initial_results['items']) ? $initial_results['items'] : array();
            $has_more = !empty($initial_results['has_more']);
            $button_label = $selected_post instanceof WP_Post ? self::normalize_plain_text(get_the_title($selected_post)) : 'Selecionar post';

            echo '<div id="content-rank-plan-picker" class="relative space-y-3" data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr($search_nonce) . '" data-per-page="10" data-current-page="1" data-has-more="' . ($has_more ? '1' : '0') . '">';
            echo '<input type="hidden" name="pillar_post_id" id="content-rank-plan-picker-value" value="' . esc_attr($selected_post_id) . '" />';
            echo '<button type="button" id="content-rank-plan-picker-toggle" class="flex w-full items-center justify-between rounded-2xl border border-slate-300 bg-white px-4 py-3 text-left text-sm font-medium text-slate-900 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-200" aria-expanded="false">';
            echo '<span id="content-rank-plan-picker-label">' . esc_html($button_label) . '</span>';
            echo '<span class="text-slate-400">⌄</span>';
            echo '</button>';

            echo '<div id="content-rank-plan-picker-menu" class="absolute left-0 right-0 top-full z-20 mt-2 hidden rounded-2xl border border-slate-200 bg-white shadow-soft">';
            echo '<div class="border-b border-slate-200 p-3">';
            echo '<div class="flex gap-2">';
            echo '<input id="content-rank-plan-picker-search" type="search" class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Pesquisar..." autocomplete="off" />';
            echo '<button type="button" id="content-rank-plan-picker-search-btn" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Buscar</button>';
            echo '</div>';
            echo '</div>';
            echo '<div id="content-rank-plan-picker-results" class="max-h-80 space-y-2 overflow-y-auto p-3 pr-1">';
            if (!empty($items)) {
                foreach ($items as $item) {
                    self::render_picker_post_button($item, $selected_post_id);
                }
            } else {
                echo '<p class="text-sm text-slate-500">Nenhum post encontrado.</p>';
            }
            echo '</div>';
            echo '<div class="mt-3 flex justify-center">';
            echo '<button type="button" id="content-rank-plan-picker-load-more" class="' . ($has_more ? '' : 'hidden ') . 'inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Carregar mais</button>';
            echo '</div>';
            echo '<p id="content-rank-plan-picker-empty" class="hidden px-3 pb-3 text-sm text-slate-500">Nenhum resultado encontrado.</p>';
            echo '</div>';
            echo '</div>';
        }

        private static function render_plan_table($plan)
        {
            if (empty($plan) || !is_array($plan)) {
                echo '<div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">Nenhum plano gerado ainda.</div>';
                return;
            }

            $satellites = !empty($plan['satellites']) && is_array($plan['satellites']) ? $plan['satellites'] : array();

            echo '<div class="space-y-4">';
            echo '<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">';
            echo '<div class="flex flex-wrap items-center justify-between gap-3">';
            echo '<div>';
            echo '<h3 class="text-lg font-semibold text-slate-950">Plano editorial</h3>';
            if (!empty($plan['generated_at'])) {
                echo '<p class="mt-1 text-sm text-slate-500">Gerado em ' . esc_html($plan['generated_at']) . '</p>';
            }
            if (!empty($plan['content_model_label'])) {
                echo '<p class="mt-1 text-sm text-slate-500">Modelo editorial: ' . esc_html($plan['content_model_label']) . '</p>';
            }
            if (!empty($plan['generated_satellite_posts']) && is_array($plan['generated_satellite_posts'])) {
                echo '<p class="mt-1 text-sm text-slate-500">Satélites gerados: ' . esc_html(count($plan['generated_satellite_posts'])) . '</p>';
            }
            echo '</div>';
            echo '<div class="text-sm text-slate-500">' . esc_html(count($satellites)) . ' satélite(s)</div>';
            echo '</div>';
            echo '</div>';

            echo '<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">';
            echo '<table class="min-w-full divide-y divide-slate-200">';
            echo '<thead class="bg-slate-50">';
            echo '<tr>';
            echo '<th class="px-4 py-3 text-left text-xs font-semibold text-slate-500">#</th>';
            echo '<th class="px-4 py-3 text-left text-xs font-semibold text-slate-500">Título do satélite</th>';
            echo '<th class="px-4 py-3 text-left text-xs font-semibold text-slate-500">Âncora</th>';
            echo '<th class="px-4 py-3 text-left text-xs font-semibold text-slate-500">KW</th>';
             echo '<th class="px-4 py-3 text-left text-xs font-semibold text-slate-500">Tipo</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody class="divide-y divide-slate-100 bg-white">';
            foreach ($satellites as $satellite) {
                $index = isset($satellite['index']) ? intval($satellite['index']) : 0;
                echo '<tr>';
                echo '<td class="px-4 py-4 text-sm font-medium text-slate-500">' . esc_html($index > 0 ? $index : '-') . '</td>';
                echo '<td class="px-4 py-4 text-sm font-semibold text-slate-900">' . esc_html(isset($satellite['title']) ? $satellite['title'] : '-') . '</td>';
                echo '<td class="px-4 py-4 text-sm text-slate-700">' . esc_html(isset($satellite['anchor_phrase']) ? $satellite['anchor_phrase'] : '-') . '</td>';
                echo '<td class="px-4 py-4 text-sm text-slate-700">' . esc_html(isset($satellite['focus_keyword']) ? $satellite['focus_keyword'] : '-') . '</td>';
                 echo '<td class="px-4 py-4 text-sm text-slate-700">' . esc_html(isset($satellite['content_angle']) ? $satellite['content_angle'] : '-') . '</td>';
                echo '</tr>';
            }
            if (empty($satellites)) {
                 echo '<tr><td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">O plano não trouxe satélites.</td></tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
            echo '</div>';
        }

        public function render_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Permissão negada.');
            }

            if (function_exists('nocache_headers')) {
                nocache_headers();
            }

            $selected_post_id = intval(self::get_request_param('post_id', 0));
            $selected_post = $selected_post_id > 0 ? get_post($selected_post_id) : null;
            $plan = $selected_post_id > 0 ? self::get_plan_meta($selected_post_id) : array();
            $generated_at = $selected_post_id > 0 ? (string) get_post_meta($selected_post_id, self::META_PLAN_GENERATED_AT, true) : '';
            if (!empty($generated_at)) {
                $plan['generated_at'] = $generated_at;
            }
            $stored_planning_custom_prompt = $selected_post_id > 0 ? (string) get_post_meta($selected_post_id, self::META_PLAN_PLANNING_CUSTOM_PROMPT, true) : '';
            $plan_prompt_model_key = $selected_post_id > 0 ? (string) get_post_meta($selected_post_id, self::META_PLAN_PROMPT_MODEL_KEY, true) : '';
            $plan_outline_model_key = $selected_post_id > 0 ? (string) get_post_meta($selected_post_id, self::META_PLAN_OUTLINE_MODEL_KEY, true) : '';
            if ($plan_prompt_model_key !== '') {
                $plan['recommended_prompt_model_key'] = $plan_prompt_model_key;
            }
            if ($plan_outline_model_key !== '') {
                $plan['recommended_outline_model_key'] = $plan_outline_model_key;
            }
            if ($stored_planning_custom_prompt !== '' && empty($plan['planning_custom_prompt'])) {
                $plan['planning_custom_prompt'] = $stored_planning_custom_prompt;
            }

            $current_generator = null;
            if ($selected_post_id > 0) {
                $generator_id = intval(get_post_meta($selected_post_id, '_content_rank_generator_id', true));
                if ($generator_id > 0 && class_exists('Content_Rank_Generator')) {
                    $current_generator = Content_Rank_Generator::get_generator($generator_id);
                }
            }
            if (!$current_generator && $selected_post_id > 0) {
                $current_generator = self::build_default_generator_context($selected_post_id);
            }

            $selected_content_model_type = '';
            if (!empty($plan['content_model_type'])) {
                $selected_content_model_type = Content_Rank_Generator::normalize_content_model_type($plan['content_model_type']);
            }
            if ($selected_content_model_type === '' && $selected_post_id > 0) {
                $stored_content_model_type = (string) get_post_meta($selected_post_id, '_content_rank_content_model_type', true);
                if ($stored_content_model_type !== '') {
                    $selected_content_model_type = Content_Rank_Generator::normalize_content_model_type($stored_content_model_type);
                }
            }
            if ($selected_content_model_type === '' && $current_generator && !empty($current_generator['content_model_type'])) {
                $selected_content_model_type = Content_Rank_Generator::normalize_content_model_type($current_generator['content_model_type']);
            }
            if ($selected_content_model_type === '') {
                $selected_content_model_type = Content_Rank_Generator::get_default_content_model_type();
            }

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
            <div class="wrap">
                <div class="mb-6">
                    <p class="text-xs font-semibold text-indigo-500">Content Rank</p>
                    <h1 class="text-3xl font-semibold text-slate-950">Planejamento</h1>
                </div>

                <?php self::render_notice(); ?>

                <div class="grid gap-6 xl:grid-cols-[360px_minmax(0,1fr)]">
                    <aside class="space-y-6">
                        <div class=" border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex items-start justify-between">
                                <div>
                                    <h2 class="text-xl font-semibold text-slate-950">Parâmetros do Plano</h2>
                                </div>
                            </div>
                            <div class="mt-5"><?php self::render_posts_selector($selected_post_id); ?></div>

                            <div id="content-rank-plan-options" class="mt-5 space-y-4 <?php echo $selected_post instanceof WP_Post ? '' : 'hidden'; ?>">
                                <form id="content-rank-content-plan-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="space-y-4">
                                    <?php wp_nonce_field('content_rank_generate_content_plan', 'content_rank_content_plan_nonce'); ?>
                                    <input type="hidden" name="action" value="content_rank_generate_content_plan" />
                                    <input type="hidden" name="pillar_post_id" id="content-rank-content-plan-post-id" value="<?php echo esc_attr($selected_post instanceof WP_Post ? $selected_post->ID : 0); ?>" />

                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <label class="mb-1 block text-xs font-semibold text-slate-500">Qtd. satélites</label>
                                            <input type="number" min="1" max="12" name="satellite_count" value="<?php echo esc_attr(isset($plan['satellite_count']) ? intval($plan['satellite_count']) : 5); ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                    </div>

                                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-emerald-500">Criar planejamento</button>
                                </form>

                                <?php if (!empty($plan)): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('content_rank_generate_content_satellites', 'content_rank_content_satellites_nonce'); ?>
                                        <input type="hidden" name="action" value="content_rank_generate_content_satellites" />
                                        <input type="hidden" name="pillar_post_id" value="<?php echo esc_attr($selected_post instanceof WP_Post ? $selected_post->ID : 0); ?>" />
                                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-slate-800">Gerar satélites</button>
                                    </form>

                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mt-3" data-swal-confirm="Remover o plano salvo deste post pilar?">
                                        <?php wp_nonce_field('content_rank_clear_content_plan', 'content_rank_content_plan_nonce'); ?>
                                        <input type="hidden" name="action" value="content_rank_clear_content_plan" />
                                        <input type="hidden" name="pillar_post_id" value="<?php echo esc_attr($selected_post instanceof WP_Post ? $selected_post->ID : 0); ?>" />
                                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-600 transition hover:bg-rose-100">Limpar plano</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </aside>

                    <main class="min-w-0">
                        <?php self::render_plan_table($plan); ?>
                    </main>
                </div>
            </div>
            <script>
                (function () {
                    const picker = document.getElementById('content-rank-plan-picker');
                    const optionsWrap = document.getElementById('content-rank-plan-options');
                    const toggleButton = document.getElementById('content-rank-plan-picker-toggle');
                    const labelNode = document.getElementById('content-rank-plan-picker-label');
                    const menu = document.getElementById('content-rank-plan-picker-menu');
                    const searchInput = document.getElementById('content-rank-plan-picker-search');
                    const searchButton = document.getElementById('content-rank-plan-picker-search-btn');
                    const results = document.getElementById('content-rank-plan-picker-results');
                    const loadMoreButton = document.getElementById('content-rank-plan-picker-load-more');
                    const emptyState = document.getElementById('content-rank-plan-picker-empty');
                    const valueInputs = document.querySelectorAll('input[name="pillar_post_id"]');
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
                        optionsWrap.classList.toggle('hidden', selectedPostId <= 0);
                    };

                    const setMenuOpen = (isOpen) => {
                        menu.classList.toggle('hidden', !isOpen);
                        toggleButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                        if (isOpen && searchInput) {
                            window.setTimeout(() => searchInput.focus(), 0);
                        }
                    };

                    const syncActiveButtons = () => {
                        const buttons = results.querySelectorAll('.content-rank-plan-picker-item');
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
                            const button = event.target.closest('.content-rank-plan-picker-item');
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
                        setMenuOpen(false);
                        syncActiveButtons();
                    };

                    const renderButton = (item) => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'content-rank-plan-picker-item w-full rounded-xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-indigo-200';
                        button.dataset.postId = item.id;
                        button.dataset.postType = item.post_type || 'post';
                        button.dataset.postTitle = item.title || '';
                        button.setAttribute('aria-pressed', selectedPostId > 0 && parseInt(item.id || '0', 10) === selectedPostId ? 'true' : 'false');
                        if (selectedPostId > 0 && parseInt(item.id || '0', 10) === selectedPostId) {
                            button.classList.add('border-indigo-500', 'bg-indigo-50');
                        } else {
                            button.classList.add('border-slate-200', 'bg-white', 'hover:bg-slate-50');
                        }

                        button.innerHTML = escapeHtml(item.title || 'Post');
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
                                    action: 'content_rank_content_plan_search_posts',
                                    nonce: nonce,
                                    search: search,
                                    page: String(page),
                                    per_page: String(perPage)
                                }).toString()
                            });

                            const payload = await response.json();
                            if (!payload || !payload.success) {
                                throw new Error((payload && payload.data && payload.data.message) ? payload.data.message : 'Não foi possível carregar os posts.');
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
                        fetchPosts({ search: currentSearch, page: 1, append: false });
                    };

                    const initialSelectedInput = Array.from(valueInputs).find((input) => parseInt(input.value || '0', 10) > 0);
                    if (initialSelectedInput) {
                        selectedPostId = parseInt(initialSelectedInput.value || '0', 10) || 0;
                        optionsWrap.classList.remove('hidden');
                    }

                    if (selectedPostId <= 0) {
                        optionsWrap.classList.add('hidden');
                    }

                    setLoadMoreState();
                    setEmptyState(results.querySelectorAll('.content-rank-plan-picker-item').length === 0);
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

                    if (selectedPostId > 0) {
                        const selectedButton = results.querySelector('.content-rank-plan-picker-item[aria-pressed="true"]');
                        if (selectedButton) {
                            updateLabel(selectedButton.textContent.trim());
                        }
                    }
                })();
            </script>
<?php
        }
    }
}
