<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Content_Rank_Generated_Posts')) {
    final class Content_Rank_Generated_Posts
    {
        public const PAGE_SLUG = 'content-rank-generated-posts';
        private const REGENERATION_SNAPSHOT_PREFIX = 'content_rank_regeneration_snapshot_';
        private const REGENERATION_SNAPSHOT_TTL = 432000;

        public function __construct()
        {
            add_action('admin_menu', array($this, 'admin_menu'), 21);
            add_action('admin_post_content_rank_regenerate_generated_post', array($this, 'handle_regenerate_post'));
            add_action('admin_post_content_rank_delete_generated_post', array($this, 'handle_delete_post'));
            add_action('admin_post_content_rank_bulk_generated_posts_action', array($this, 'handle_bulk_generated_posts_action'));
        }

        public function admin_menu()
        {
            add_submenu_page(
                'content-rank',
                'Posts gerados',
                'Posts gerados',
                'manage_options',
                self::PAGE_SLUG,
                array($this, 'render_page')
            );
        }

        private static function truncate_text($text, $limit = 120)
        {
            $text = trim((string) $text);
            if ($text === '') {
                return '';
            }

            $limit = max(20, intval($limit));
            if (function_exists('mb_strimwidth')) {
                return mb_strimwidth($text, 0, $limit, '...');
            }

            return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
        }

        private static function save_regeneration_snapshot($post_id, $generator, $item)
        {
            $post_id = absint($post_id);
            if ($post_id <= 0 || !is_array($generator) || !is_array($item)) {
                return;
            }

            set_transient(self::REGENERATION_SNAPSHOT_PREFIX . $post_id, array(
                'generator' => $generator,
                'item' => $item,
                'saved_at' => time(),
            ), self::REGENERATION_SNAPSHOT_TTL);
        }

        public static function save_regeneration_snapshot_for_pipeline($post_id, $generator, $item)
        {
            self::save_regeneration_snapshot($post_id, $generator, $item);
        }

        private static function get_regeneration_snapshot($post_id)
        {
            $post_id = absint($post_id);
            if ($post_id <= 0) {
                return array();
            }

            $snapshot = get_transient(self::REGENERATION_SNAPSHOT_PREFIX . $post_id);
            if (!is_array($snapshot) || empty($snapshot['generator']) || !is_array($snapshot['generator']) || empty($snapshot['item']) || !is_array($snapshot['item'])) {
                return array();
            }

            return $snapshot;
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

        private static function get_filtered_query($paged, $per_page, $generator_id = 0, $search = '')
        {
            $args = array(
                'post_type' => 'any',
                'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
                'posts_per_page' => max(1, intval($per_page)),
                'paged' => max(1, intval($paged)),
                'orderby' => 'ID',
                'order' => 'DESC',
                'meta_query' => array(
                    array(
                        'key' => '_content_rank_generator_id',
                        'compare' => 'EXISTS',
                    ),
                ),
            );

            if (intval($generator_id) > 0) {
                $args['meta_query'][] = array(
                    'key' => '_content_rank_generator_id',
                    'value' => intval($generator_id),
                    'compare' => '=',
                    'type' => 'NUMERIC',
                );
            }

            if ($search !== '') {
                $args['s'] = $search;
            }

            return new WP_Query($args);
        }

        private static function get_generator_name($generator_id)
        {
            static $cache = array();
            $generator_id = intval($generator_id);
            if ($generator_id <= 0) {
                return '';
            }

            if (!array_key_exists($generator_id, $cache)) {
                $generator = Content_Rank_Generator::get_generator($generator_id);
                $cache[$generator_id] = !empty($generator['name']) ? $generator['name'] : '';
            }

            return $cache[$generator_id];
        }

        private static function resolve_generated_post_context($post_id)
        {
            $post = get_post($post_id);
            if (!$post) {
                return new WP_Error('content_rank_generated_post_missing', 'Post nao encontrado.');
            }

            $snapshot = self::get_regeneration_snapshot($post_id);
            if (!empty($snapshot)) {
                return array(
                    'generator' => $snapshot['generator'],
                    'item' => $snapshot['item'],
                    'post' => $post,
                    'from_snapshot' => 1,
                );
            }

            $generator_id = intval(get_post_meta($post_id, '_content_rank_generator_id', true));
            if ($generator_id <= 0) {
                return new WP_Error('content_rank_generated_post_missing_generator', 'Este post nao possui gerador vinculado.');
            }

            $generator = Content_Rank_Generator::get_generator($generator_id);
            if (!$generator) {
                return new WP_Error('content_rank_generated_post_generator_missing', 'Gerador original nao encontrado.');
            }

            $item = array(
                'guid' => (string) get_post_meta($post_id, '_content_rank_source_item_guid', true),
                'title' => (string) get_post_meta($post_id, '_content_rank_source_item_title', true),
                'permalink' => (string) get_post_meta($post_id, '_content_rank_source_item_permalink', true),
                'excerpt' => '',
                'content' => '',
                'feed_title' => '',
                'date' => (string) get_post_meta($post_id, '_content_rank_source_timestamp', true),
                'categories' => array(),
                'source_image_url' => (string) get_post_meta($post_id, '_content_rank_source_image_url', true),
                'source_link_url' => (string) get_post_meta($post_id, '_content_rank_source_link_url', true),
                'source_link_text' => (string) get_post_meta($post_id, '_content_rank_source_link_text', true),
                'source_page_title' => (string) get_post_meta($post_id, '_content_rank_source_page_title', true),
                'source_page_excerpt' => (string) get_post_meta($post_id, '_content_rank_source_page_excerpt', true),
                'source_page_content' => (string) get_post_meta($post_id, '_content_rank_source_page_content', true),
                'source_page_content_html' => (string) get_post_meta($post_id, '_content_rank_source_page_content_html', true),
                'source_page_html' => (string) get_post_meta($post_id, '_content_rank_source_page_html', true),
                'source_page_outline' => (string) get_post_meta($post_id, '_content_rank_source_page_outline', true),
                'source_page_outline_sections' => array(),
                'source_video_url' => (string) get_post_meta($post_id, '_content_rank_source_video_url', true),
                'source_video_embed_html' => (string) get_post_meta($post_id, '_content_rank_source_video_embed_html', true),
                'source_video_source' => (string) get_post_meta($post_id, '_content_rank_source_video_source', true),
                'outline_target_h2_min' => intval(get_post_meta($post_id, '_content_rank_outline_target_h2_min', true)),
                'outline_target_h2_max' => intval(get_post_meta($post_id, '_content_rank_outline_target_h2_max', true)),
                'outline_target_h2_count' => intval(get_post_meta($post_id, '_content_rank_outline_target_h2_count', true)),
                'outline_block_quantities' => array(),
                'source_image_selector_class' => (string) get_post_meta($post_id, '_content_rank_source_image_selector_class', true),
                'source_link_selector_class' => (string) get_post_meta($post_id, '_content_rank_source_link_selector_class', true),
                'source_title' => (string) get_post_meta($post_id, '_content_rank_source_title', true),
                'source_url' => (string) get_post_meta($post_id, '_content_rank_source_url', true),
                'keyword' => (string) get_post_meta($post_id, '_content_rank_source_keyword', true),
                'final_slug' => (string) get_post_meta($post_id, '_content_rank_source_final_slug', true),
                'source_context_enriched' => 0,
            );
            if ($item['title'] === '' && !empty($post->post_title)) {
                $item['title'] = (string) $post->post_title;
            }
            if (empty($item['source_title'])) {
                $item['source_title'] = $item['title'] !== '' ? $item['title'] : (!empty($post->post_title) ? (string) $post->post_title : '');
            }
            if (empty($item['source_page_title'])) {
                $item['source_page_title'] = $item['source_title'];
            }
            $original_item = $item;

            $outline_sections_raw = (string) get_post_meta($post_id, '_content_rank_source_page_outline_sections', true);
            if ($outline_sections_raw !== '') {
                $outline_sections = json_decode($outline_sections_raw, true);
                if (is_array($outline_sections)) {
                    $item['source_page_outline_sections'] = $outline_sections;
                }
            }

            $video_sections_raw = (string) get_post_meta($post_id, '_content_rank_source_page_video_sections', true);
            if ($video_sections_raw !== '') {
                $video_sections = json_decode($video_sections_raw, true);
                if (is_array($video_sections)) {
                    $item['source_page_video_sections'] = $video_sections;
                }
            }

            $outline_block_quantities_raw = (string) get_post_meta($post_id, '_content_rank_outline_block_quantities', true);
            if ($outline_block_quantities_raw !== '') {
                $outline_block_quantities = json_decode($outline_block_quantities_raw, true);
                if (is_array($outline_block_quantities)) {
                    $item['outline_block_quantities'] = $outline_block_quantities;
                }
            }

            $source_type = !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss';
            $video_selector_class = !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '';
            $image_selector_class = !empty($generator['image_selector_class'])
                ? sanitize_text_field((string) $generator['image_selector_class'])
                : (string) get_post_meta($post_id, '_content_rank_source_image_selector_class', true);
            $link_selector_class = !empty($generator['link_selector_class'])
                ? sanitize_text_field((string) $generator['link_selector_class'])
                : (string) get_post_meta($post_id, '_content_rank_source_link_selector_class', true);
            $content_image_size_generator = $generator;
            if (empty($content_image_size_generator['content_image_size'])) {
                $content_image_size_generator['content_image_size'] = (string) get_post_meta($post_id, '_content_rank_content_image_size', true);
            }
            $content_image_size = Content_Rank_Generator::get_content_image_size_for_generator($content_image_size_generator);
            $content_selector = !empty($generator['content_selector'])
                ? sanitize_text_field((string) $generator['content_selector'])
                : (string) get_post_meta($post_id, '_content_rank_content_selector', true);
            if (empty($generator['content_image_size'])) {
                $generator['content_image_size'] = $content_image_size;
            }
            if (empty($generator['image_selector_class']) && $image_selector_class !== '') {
                $generator['image_selector_class'] = $image_selector_class;
            }
            if (empty($generator['link_selector_class']) && $link_selector_class !== '') {
                $generator['link_selector_class'] = $link_selector_class;
            }
            if (empty($generator['content_selector']) && $content_selector !== '') {
                $generator['content_selector'] = $content_selector;
            }

            $item = $original_item;
            if (empty($item['source_title'])) {
                $item['source_title'] = $item['title'];
            }
            if (empty($item['source_page_title'])) {
                $item['source_page_title'] = $item['source_title'];
            }
            if (empty($item['source_url']) && !empty($item['permalink'])) {
                $item['source_url'] = $item['permalink'];
            }
            if ($item['source_page_content'] === '' && !empty($item['source_page_content_html'])) {
                $item['source_page_content'] = wp_strip_all_tags((string) $item['source_page_content_html']);
            }

            $title_outline_count = Content_Rank_Generator_Helper::extract_outline_target_h2_count_from_title(
                !empty($post->post_title) ? $post->post_title : (isset($item['title']) ? $item['title'] : ''),
                !empty($item['source_title']) ? $item['source_title'] : ''
            );
            if ($title_outline_count > 0) {
                $item['outline_target_h2_min'] = $title_outline_count;
                $item['outline_target_h2_max'] = $title_outline_count;
                $item['outline_target_h2_count'] = $title_outline_count;
            }

            $item = Content_Rank_Generator::maybe_enrich_rss_item_context($generator, $item);
            $item = Content_Rank_Generator::resolve_item_media_for_generation($generator, $item);

            self::save_regeneration_snapshot($post_id, $generator, $item);

            return array(
                'generator' => $generator,
                'item' => $item,
                'post' => $post,
            );
        }

        public function handle_regenerate_post()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            check_admin_referer('content_rank_regenerate_generated_post', 'content_rank_regenerate_nonce');

            $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
            if ($post_id <= 0) {
                $this->redirect_with_notice('Post invalido.', 'error');
            }

            $context = self::resolve_generated_post_context($post_id);
            if (is_wp_error($context)) {
                $this->redirect_with_notice($context->get_error_message(), 'error');
            }

            $generator = $context['generator'];
            $item = $context['item'];
            $post = $context['post'];
            $original_post_name = ($post instanceof WP_Post && !empty($post->post_name))
                ? (string) $post->post_name
                : '';

            $queued_pipeline = Content_Rank_Generator::queue_staged_generation($generator, $item, $post_id);
            if (is_wp_error($queued_pipeline)) {
                Content_Rank_Generator::force_generated_post_draft($post_id, $queued_pipeline->get_error_message());
                $this->redirect_with_notice($queued_pipeline->get_error_message(), 'error');
            }
            $this->redirect_with_notice('Regeneracao iniciada. Acompanhe o progresso no aviso de geracao.', 'success');

            $article = Content_Rank_Generator_Helper::call_openai($generator, $item);
            if (is_wp_error($article)) {
                Content_Rank_Generator::force_generated_post_draft($post_id, $article->get_error_message());
                $this->redirect_with_notice($article->get_error_message(), 'error');
            }

            try {
            $generated_content_type = !empty($item['content_type'])
                ? (string) $item['content_type']
                : (!empty($article['outline_context']['content_type']) ? (string) $article['outline_context']['content_type'] : '');
            $article['content_html'] = Content_Rank_Generator_Helper::normalize_generated_list_markup(
                isset($article['content_html']) ? $article['content_html'] : '',
                $generated_content_type
            );

            // RSS uses the source-media pipeline. Never publish image URLs
            // invented by the model during regeneration.
            $source_type_for_images = !empty($generator['source_type'])
                ? sanitize_key((string) $generator['source_type'])
                : 'rss';
            if ($source_type_for_images === 'rss' && !empty($article['content_html'])) {
                $article['content_html'] = (string) preg_replace('/<img\b[^>]*>/i', '', (string) $article['content_html']);
            }

            if (!empty($article['content_html']) && !empty($generator['random_bolds_enabled'])) {
                $article['content_html'] = Content_Rank_Generator_Helper::apply_humanized_bold_markup_to_content($article['content_html']);
            }

            $title_outline_count = Content_Rank_Generator_Helper::extract_outline_target_h2_count_from_title(
                !empty($article['title']) ? $article['title'] : '',
                !empty($item['source_title']) ? $item['source_title'] : (!empty($item['title']) ? $item['title'] : '')
            );
            if ($title_outline_count > 0) {
                $item['outline_target_h2_min'] = $title_outline_count;
                $item['outline_target_h2_max'] = $title_outline_count;
                $item['outline_target_h2_count'] = $title_outline_count;
            }

            $content_media_source = method_exists('Content_Rank_Generator', 'normalize_content_media_source')
                ? Content_Rank_Generator::normalize_content_media_source(
                    isset($generator['content_media_source']) ? $generator['content_media_source'] : '',
                    isset($generator['source_content_images_enabled']) ? $generator['source_content_images_enabled'] : null
                )
                : 'source';
            $use_source_video = !empty($generator['source_video_enabled']) && $content_media_source === 'source';
            $source_video_embed_html = '';
            $source_video_url = '';
            $source_video_sections = !empty($item['source_page_video_sections']) && is_array($item['source_page_video_sections'])
                ? $item['source_page_video_sections']
                : array();
            if (empty($source_video_sections) && !empty($item['source_page_html'])) {
                $source_video_sections = Content_Rank_Generator_Helper::extract_video_sections_from_raw_source_html(
                    $item['source_page_html'],
                    !empty($item['permalink']) ? $item['permalink'] : '',
                    !empty($generator['content_selector']) ? $generator['content_selector'] : ''
                );
            }
            $content_image_size = Content_Rank_Generator::get_content_image_size_for_generator($generator);
            $source_type = !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss';
            $is_keyword_list = Content_Rank_Generator::source_type_uses_keyword_list($source_type);
            $is_keyword_list_url_reference = Content_Rank_Generator::generator_uses_keyword_list_url_reference_mode($generator);
            $treat_like_rss = !$is_keyword_list || $is_keyword_list_url_reference;
            if ($treat_like_rss && $use_source_video) {
                // Regeneration follows the same article-level video rule as first generation.
                foreach ($source_video_sections as $source_video_section) {
                    if (!is_array($source_video_section) || empty($source_video_section['videos']) || !is_array($source_video_section['videos'])) {
                        continue;
                    }
                    $first_source_video = reset($source_video_section['videos']);
                    if (is_array($first_source_video)) {
                        $source_video_embed_html = !empty($first_source_video['video_embed_html'])
                            ? trim((string) $first_source_video['video_embed_html'])
                            : '';
                        $source_video_url = !empty($first_source_video['video_url'])
                            ? esc_url_raw(trim((string) $first_source_video['video_url']))
                            : '';
                    }
                    break;
                }
                if ($source_video_embed_html === '' && $source_video_url === '') {
                    $source_video_embed_html = !empty($item['source_video_embed_html']) ? trim((string) $item['source_video_embed_html']) : '';
                    $source_video_url = !empty($item['source_video_url']) ? esc_url_raw(trim((string) $item['source_video_url'])) : '';
                }
            }

            $content_html = isset($article['content_html']) ? (string) $article['content_html'] : '';

            $content_html = Content_Rank_Generator_Helper::apply_internal_links_to_content(
                $content_html,
                $generator,
                array(
                    'post_id' => intval($post_id),
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                )
            );
            $article['content_html'] = $content_html;

            $article['content_html'] = Content_Rank_Generator::convert_html_fragment_to_gutenberg_blocks(
                isset($article['content_html']) ? $article['content_html'] : '',
                $source_video_embed_html,
                $source_video_url
            );

            $article['content_html'] = Content_Rank_Generator_Helper::ensure_content_starts_with_paragraph_html(
                $article['content_html']
            );
            if ($content_media_source === 'none') {
                $article['content_html'] = (string) preg_replace('#<figure\b[^>]*>.*?</figure>|<iframe\b[^>]*>.*?</iframe>|<video\b[^>]*>.*?</video>|<audio\b[^>]*>.*?</audio>|<img\b[^>]*>#is', '', (string) $article['content_html']);
            }

            $content_html = isset($article['content_html']) ? (string) $article['content_html'] : '';

            $content_media_sections = !empty($item['source_page_outline_sections']) && is_array($item['source_page_outline_sections'])
                ? $item['source_page_outline_sections']
                : array();
            $use_interval_content_images = Content_Rank_Generator::generator_uses_interval_content_images($generator);
            if ($use_interval_content_images) {
                $content_media_sections = Content_Rank_Generator::resolve_content_image_sections_for_generation($item, $generator, $article);
            }
            if ($content_media_source === 'source' && !empty($content_media_sections) && is_array($content_media_sections)) {
                $content_image_size = Content_Rank_Generator::get_content_image_size_for_generator($generator);
                $existing_image_map = array();
                if ($content_html !== '') {
                    $existing_image_map = Content_Rank_Generator_Helper::extract_outline_section_image_map_from_content($content_html);
                }
                $excluded_content_image_urls = array();
                if (!empty($item['source_image_url'])) {
                    $excluded_content_image_urls[] = trim((string) $item['source_image_url']);
                }
                $existing_thumbnail_url = get_the_post_thumbnail_url($post_id, 'full');
                if (!empty($existing_thumbnail_url)) {
                    $excluded_content_image_urls[] = (string) $existing_thumbnail_url;
                }
                if ($use_interval_content_images) {
                    $content_html = Content_Rank_Generator_Helper::inject_content_images_by_word_interval(
                            $content_html,
                            $content_media_sections,
                            $post_id,
                            $content_image_size,
                            !empty($generator['content_image_interval_words']) ? intval($generator['content_image_interval_words']) : 500,
                            $excluded_content_image_urls
                        );
                } else {
                    $content_html = Content_Rank_Generator_Helper::inject_outline_section_media_into_content(
                        $content_html,
                        $content_media_sections,
                        $post_id,
                        $content_image_size,
                        !empty($generator['source_link_phrases']) ? $generator['source_link_phrases'] : '',
                        Content_Rank_Generator::generator_uses_source_content_images($generator),
                        false,
                        $generator,
                        array(
                            'post_id' => intval($post_id),
                            'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                            'generated_title' => !empty($article['title']) ? (string) $article['title'] : '',
                            'source_image_url' => !empty($item['source_image_url']) ? trim((string) $item['source_image_url']) : '',
                        ),
                        $existing_image_map,
                        array(),
                        $excluded_content_image_urls
                    );
                }
            }

            $content_html = Content_Rank_Generator_Helper::ensure_content_starts_with_paragraph_html($content_html);
            $content_html = Content_Rank_Generator_Helper::remove_unmatched_trailing_quotes_from_html($content_html);

            $article['content_html'] = $content_html;
            $post_data = Content_Rank_Generator::build_post_data($generator, $article, $item);
            $post_data['ID'] = intval($post_id);
            if ($original_post_name !== '') {
                // Regeneration may update the title, but never changes the indexed URL.
                $post_data['post_name'] = $original_post_name;
            }

            $update_result = wp_update_post($post_data, true);

            if (is_wp_error($update_result)) {
                Content_Rank_Generator::force_generated_post_draft($post_id, $update_result->get_error_message());
                $this->redirect_with_notice($update_result->get_error_message(), 'error');
            }

            $taxonomy_result = Content_Rank_Generator::apply_taxonomies_and_meta($post_id, $generator, $article, $item);
            if (is_wp_error($taxonomy_result)) {
                Content_Rank_Generator::force_generated_post_draft($post_id, $taxonomy_result->get_error_message());
                $this->redirect_with_notice($taxonomy_result->get_error_message(), 'error');
            }
            if (!empty($generator['image_source_mode'])
                && sanitize_key((string) $generator['image_source_mode']) === 'tmdb_composite'
                && class_exists('Content_Rank_TMDB')
            ) {
                Content_Rank_TMDB::localize_article_movie_titles($generator, $item, $article, false);
            }
            $thumbnail_result = Content_Rank_Thumbnail_Helper::set_featured_image(
                $post_id,
                $generator,
                $item,
                $article,
                true
            );
            if (is_wp_error($thumbnail_result)) {
                Content_Rank_Generator::force_generated_post_draft($post_id, $thumbnail_result->get_error_message());
                $this->redirect_with_notice($thumbnail_result->get_error_message(), 'error');
            }

            if (intval(get_post_thumbnail_id($post_id)) <= 0) {
                $thumbnail_error = 'A regeneração falhou porque não foi possível manter ou definir a imagem destacada.';
                Content_Rank_Generator::force_generated_post_draft($post_id, $thumbnail_error);
                $this->redirect_with_notice($thumbnail_error, 'error');
            }

            Content_Rank_Generator::insert_run_log($generator['id'], 'success', 'Post regenerado manualmente', array(
                'request' => array(
                    'post_id' => $post_id,
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                ),
                'response' => array(
                    'title' => !empty($post_data['post_title']) ? $post_data['post_title'] : '',
                ),
            ), $post_id, !empty($item['guid']) ? $item['guid'] : '', !empty($item['permalink']) ? $item['permalink'] : '');

            $view_link = Content_Rank_Generator::get_post_view_link($post_id);
            $edit_link = Content_Rank_Generator::get_post_edit_link($post_id);

            $this->redirect_with_notice('Post regenerado com sucesso.', 'success', array(
                'content_rank_notice_link' => $view_link ? $view_link : $edit_link,
            ));
            } catch (Throwable $error) {
                $message = trim((string) $error->getMessage());
                if ($message === '') {
                    $message = 'Erro inesperado durante a regeneração do post.';
                }
                Content_Rank_Generator::force_generated_post_draft($post_id, $message);
                Content_Rank_Generator::insert_run_log(
                    !empty($generator['id']) ? intval($generator['id']) : 0,
                    'error',
                    $message,
                    array(
                        'request' => array(
                            'post_id' => $post_id,
                            'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                        ),
                        'response' => array(
                            'post_status' => 'draft',
                        ),
                    ),
                    $post_id,
                    !empty($item['guid']) ? $item['guid'] : '',
                    !empty($item['permalink']) ? $item['permalink'] : ''
                );
                $this->redirect_with_notice($message, 'error');
            }
        }

        public function handle_delete_post()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            check_admin_referer('content_rank_delete_generated_post', 'content_rank_delete_generated_post_nonce');

            $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
            if ($post_id <= 0) {
                $this->redirect_with_notice('Post invalido.', 'error');
            }

            if (!get_post($post_id)) {
                $this->redirect_with_notice('Post nao encontrado.', 'error');
            }

            if (!wp_trash_post($post_id)) {
                $this->redirect_with_notice('Nao foi possivel excluir o post.', 'error');
            }

            $this->redirect_with_notice('Post enviado para a lixeira.', 'success');
        }

        public function handle_bulk_generated_posts_action()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            check_admin_referer('content_rank_bulk_generated_posts_action', 'content_rank_bulk_generated_posts_nonce');

            $bulk_action = isset($_POST['bulk_action']) ? sanitize_key(wp_unslash($_POST['bulk_action'])) : '';
            if (!in_array($bulk_action, array('delete', 'publish', 'draft'), true)) {
                $this->redirect_with_notice('Ação em lote inválida.', 'error', $this->get_current_filters_from_request());
            }

            $raw_post_ids = isset($_POST['post_ids']) ? wp_unslash($_POST['post_ids']) : array();
            if (!is_array($raw_post_ids)) {
                $raw_post_ids = array($raw_post_ids);
            }

            $post_ids = array_values(array_unique(array_filter(array_map('intval', $raw_post_ids))));
            if (empty($post_ids)) {
                $this->redirect_with_notice('Selecione ao menos um post.', 'error', $this->get_current_filters_from_request());
            }

            $processed = 0;
            $failed = 0;
            $action_labels = array(
                'delete' => 'enviados para a lixeira',
                'publish' => 'publicados',
                'draft' => 'colocados em rascunho',
            );

            foreach ($post_ids as $post_id) {
                $post = get_post($post_id);
                if (!$post || intval(get_post_meta($post_id, '_content_rank_generator_id', true)) <= 0) {
                    $failed++;
                    continue;
                }

                if ($bulk_action === 'delete') {
                    $result = wp_trash_post($post_id);
                    if (!$result) {
                        $failed++;
                        continue;
                    }
                } else {
                    $result = wp_update_post(array(
                        'ID' => $post_id,
                        'post_status' => $bulk_action,
                    ), true);

                    if (is_wp_error($result)) {
                        $failed++;
                        continue;
                    }
                }

                $processed++;
            }

            if ($processed <= 0) {
                $this->redirect_with_notice('Nao foi possivel aplicar a acao em lote.', 'error', $this->get_current_filters_from_request());
            }

            $message = sprintf(
                _n('%d post %s com sucesso.', '%d posts %s com sucesso.', $processed),
                $processed,
                isset($action_labels[$bulk_action]) ? $action_labels[$bulk_action] : 'atualizados'
            );

            if ($failed > 0) {
                $message .= sprintf(' %d falharam.', $failed);
            }

            $this->redirect_with_notice($message, 'success', $this->get_current_filters_from_request());
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

        private static function render_notice()
        {
            if (empty($_GET['content_rank_notice'])) {
                return;
            }

            $type = isset($_GET['content_rank_notice_type']) ? sanitize_key(wp_unslash($_GET['content_rank_notice_type'])) : 'success';
            $class = 'notice notice-' . ($type === 'error' ? 'error' : 'success');
            $message = sanitize_text_field(wp_unslash($_GET['content_rank_notice']));
            $link = isset($_GET['content_rank_notice_link']) ? esc_url_raw(wp_unslash($_GET['content_rank_notice_link'])) : '';

            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message);
            if ($link !== '' && $type !== 'error') {
                echo ' <a href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer" class="ml-2 inline-flex items-center rounded-md border border-current/20 px-2 py-0.5 text-xs font-semibold text-inherit no-underline">Abrir conteudo</a>';
            }
            echo '</p></div>';
        }

        private function get_current_filters_from_request()
        {
            $filters = array();

            $paged = self::get_request_param('paged', '');
            if ($paged !== '') {
                $filters['paged'] = max(1, intval($paged));
            }

            $search = self::get_request_param('s', '');
            if ($search !== '') {
                $filters['s'] = $search;
            }

            $generator_id = self::get_request_param('generator_id', '');
            if ($generator_id !== '' && intval($generator_id) > 0) {
                $filters['generator_id'] = intval($generator_id);
            }

            return $filters;
        }

        private static function render_post_status_badge($status)
        {
            $label = class_exists('Content_Rank_Generator_Admin')
                ? Content_Rank_Generator_Admin::get_post_status_label($status)
                : ucfirst((string) $status);

            $class = 'bg-slate-100 text-slate-700';
            if ($status === 'publish') {
                $class = 'bg-emerald-100 text-emerald-700';
            } elseif ($status === 'draft' || $status === 'pending') {
                $class = 'bg-amber-100 text-amber-700';
            } elseif ($status === 'private') {
                $class = 'bg-indigo-100 text-indigo-700';
            }

            return '<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
        }

        public function render_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            if (function_exists('nocache_headers')) {
                nocache_headers();
            }

            $paged = max(1, intval(self::get_request_param('paged', 1)));
            $per_page = 20;
            $search = self::get_request_param('s', '');
            $generator_id = intval(self::get_request_param('generator_id', 0));
            $generators = Content_Rank_Generator::get_generators(200);
            $query = self::get_filtered_query($paged, $per_page, $generator_id, $search);
            $total_items = intval($query->found_posts);
            $total_pages = max(1, intval($query->max_num_pages));
            $selected_generator_name = $generator_id > 0 ? self::get_generator_name($generator_id) : '';

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
                <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.25em] text-indigo-600">Content Rank</div>
                        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950 lg:text-[2.15rem]">Posts gerados</h1>
                        <p class="mt-2 max-w-3xl text-[13px] leading-5 text-slate-600">Veja tudo que o plugin já publicou ou salvou e use a regeneração para rodar o mesmo post com o prompt atual do gerador. O slug atual do post é mantido.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=content-rank')); ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-soft transition hover:bg-slate-50">Ir para geradores</a>
                    </div>
                </div>

                <?php self::render_notice(); ?>

                <div class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">
                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="grid gap-4 px-6 py-5 lg:grid-cols-12">
                        <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                        <div class="lg:col-span-5">
                            <label class="mb-1 block text-[13px] font-medium text-slate-700">Buscar</label>
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Título, conteúdo ou origem" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div class="lg:col-span-4">
                            <label class="mb-1 block text-[13px] font-medium text-slate-700">Filtrar por gerador</label>
                            <select name="generator_id" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                <option value="0"<?php selected($generator_id <= 0); ?>>Todos os geradores</option>
                                <?php foreach ($generators as $generator): ?>
                                    <option value="<?php echo esc_attr($generator['id']); ?>"<?php selected($generator_id === intval($generator['id'])); ?>><?php echo esc_html($generator['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end gap-3 lg:col-span-3">
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Filtrar</button>
                            <a href="<?php echo esc_url(add_query_arg(array('page' => self::PAGE_SLUG), admin_url('admin.php'))); ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Limpar</a>
                        </div>
                    </form>
                </div>

                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">
                    <div class="flex flex-col gap-3 border-b border-slate-200 px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-slate-950">Lista de posts</h2>
                            <p class="mt-1 text-[13px] text-slate-500">
                                <?php if ($selected_generator_name !== ''): ?>
                                    Mostrando posts do gerador <strong><?php echo esc_html($selected_generator_name); ?></strong>.
                                <?php else: ?>
                                    Mostrando posts de todos os geradores.
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-4 py-2 text-[13px] text-slate-600">
                            <?php echo esc_html(number_format_i18n($total_items)); ?> post(s)
                        </div>
                    </div>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="content-rank-generated-posts-bulk-form" class="border-b border-slate-200 bg-slate-50 px-6 py-4">
                        <?php wp_nonce_field('content_rank_bulk_generated_posts_action', 'content_rank_bulk_generated_posts_nonce'); ?>
                        <input type="hidden" name="action" value="content_rank_bulk_generated_posts_action" />
                        <input type="hidden" name="paged" value="<?php echo esc_attr($paged); ?>" />
                        <input type="hidden" name="s" value="<?php echo esc_attr($search); ?>" />
                        <input type="hidden" name="generator_id" value="<?php echo esc_attr($generator_id); ?>" />
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                            <div class="min-w-0 flex-1">
                                <label class="mb-1 block text-[13px] font-medium text-slate-700">Ações em lote</label>
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                    <select name="bulk_action" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 sm:max-w-xs">
                                        <option value="">Selecione uma ação</option>
                                        <option value="publish">Publicar</option>
                                        <option value="draft">Colocar em rascunho</option>
                                        <option value="delete">Excluir</option>
                                    </select>
                                    <div class="text-[13px] text-slate-500">Marque os posts desejados na tabela para aplicar a ação em massa.</div>
                                </div>
                            </div>
                            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Aplicar</button>
                        </div>
                    </form>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr class="text-left text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                                    <th class="w-12 px-4 py-3">
                                        <input type="checkbox" class="content-rank-generated-posts-select-all h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" data-content-rank-select-all-generated-posts />
                                    </th>
                                    <th class="px-6 py-3">Post</th>
                                    <th class="px-6 py-3">Gerador</th>
                                    <th class="px-6 py-3">Origem</th>
                                    <th class="px-6 py-3">Status</th>
                                    <th class="px-6 py-3">Data</th>
                                    <th class="px-6 py-3">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <?php if ($query->have_posts()): ?>
                                    <?php
                                    global $post;
                                    foreach ($query->posts as $post):
                                        setup_postdata($post);
                                        $post_id = intval($post->ID);
                                        $generator_id_row = intval(get_post_meta($post_id, '_content_rank_generator_id', true));
                                        $generator_name = self::get_generator_name($generator_id_row);
                                        $source_type = (string) get_post_meta($post_id, '_content_rank_source_type', true);
                                        $source_title = (string) get_post_meta($post_id, '_content_rank_source_title', true);
                                        $source_keyword = (string) get_post_meta($post_id, '_content_rank_source_keyword', true);
                                        $source_url = (string) get_post_meta($post_id, '_content_rank_source_url', true);
                                        $source_permalink = (string) get_post_meta($post_id, '_content_rank_source_item_permalink', true);
                                        $source_external_link = $source_permalink !== '' ? $source_permalink : $source_url;
                                        $source_label = $source_title !== '' ? $source_title : ($source_keyword !== '' ? $source_keyword : ($source_url !== '' ? $source_url : $source_permalink));
                                        $view_link = Content_Rank_Generator::get_post_view_link($post_id);
                                        $edit_link = Content_Rank_Generator::get_post_edit_link($post_id);
                                        $review_products = json_decode((string) get_post_meta($post_id, '_content_rank_review_products_json', true), true);
                                        $is_review_post = is_array($review_products) && !empty($review_products);
                                        // Review Builder uses a virtual generator,
                                        // so it has no generator row to identify.
                                        $can_regenerate = $is_review_post || ($generator_id_row > 0 && !empty($generator_name));
                                        ?>
                                        <tr class="align-top">
                                            <td class="px-4 py-4 align-top">
                                                <input type="checkbox" name="post_ids[]" value="<?php echo esc_attr($post_id); ?>" form="content-rank-generated-posts-bulk-form" class="content-rank-generated-posts-checkbox mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm font-semibold leading-5 text-slate-950"><?php echo esc_html(get_the_title($post_id)); ?></div>
                                                <div class="mt-1 text-[11px] text-slate-500">#<?php echo esc_html($post_id); ?> · <?php echo esc_html(get_post_type($post_id)); ?></div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm font-medium leading-5 text-slate-700"><?php echo esc_html($generator_name !== '' ? $generator_name : ('Gerador #' . $generator_id_row)); ?></div>
                                                <div class="mt-1 text-[11px] text-slate-500"><?php echo esc_html($source_type !== '' ? $source_type : '-'); ?></div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php if ($source_external_link !== ''): ?>
                                                    <a href="<?php echo esc_url($source_external_link); ?>" target="_blank" rel="noopener noreferrer" class="block max-w-md text-sm font-medium leading-5 text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-600">
                                                        <?php echo esc_html(self::truncate_text($source_label !== '' ? $source_label : '-', 120)); ?>
                                                    </a>
                                                    <a href="<?php echo esc_url($source_external_link); ?>" target="_blank" rel="noopener noreferrer" class="mt-1 block max-w-md break-all text-[11px] leading-4 text-slate-500 hover:text-slate-700">
                                                        <?php echo esc_html(self::truncate_text($source_external_link, 140)); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <div class="max-w-md text-sm leading-5 text-slate-700"><?php echo esc_html(self::truncate_text($source_label !== '' ? $source_label : '-', 120)); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php echo wp_kses_post(self::render_post_status_badge(get_post_status($post_id))); ?>
                                            </td>
                                            <td class="px-6 py-4 text-[13px] leading-5 text-slate-600"><?php echo esc_html(get_the_date('Y-m-d H:i', $post_id)); ?></td>
                                            <td class="whitespace-nowrap px-6 py-4">
                                                <div class="flex flex-nowrap items-center gap-2 whitespace-nowrap">
                                                    <?php if ($view_link !== ''): ?>
                                                        <a href="<?php echo esc_url($view_link); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-slate-950" aria-label="Visualizar" title="Visualizar">
                                                            <span class="dashicons dashicons-visibility text-[16px] leading-none"></span>
                                                            <span class="sr-only">Visualizar</span>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($edit_link !== ''): ?>
                                                        <a href="<?php echo esc_url($edit_link); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-slate-950" aria-label="Editar" title="Editar">
                                                            <span class="dashicons dashicons-edit text-[16px] leading-none"></span>
                                                            <span class="sr-only">Editar</span>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($can_regenerate): ?>
                                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="m-0 inline-flex shrink-0" data-swal-confirm="Regerar este post com o prompt atual do gerador?">
                                                            <?php wp_nonce_field('content_rank_regenerate_generated_post', 'content_rank_regenerate_nonce'); ?>
                                                            <input type="hidden" name="action" value="content_rank_regenerate_generated_post" />
                                                            <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>" />
                                                            <button type="submit" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-300 bg-white text-indigo-600 shadow-sm transition hover:bg-slate-50 hover:text-indigo-700" aria-label="Regerar" title="Regerar">
                                                                <span class="dashicons dashicons-update text-[16px] leading-none"></span>
                                                                <span class="sr-only">Regerar</span>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-400 shadow-sm" title="Nao foi possivel identificar o gerador original" aria-label="Regerar indisponivel">
                                                            <span class="dashicons dashicons-update text-[16px] leading-none"></span>
                                                            <span class="sr-only">Regerar indisponivel</span>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (class_exists('Content_Rank_Content_Plans')): ?>
                                                        <a href="<?php echo esc_url(Content_Rank_Content_Plans::build_plan_url($post_id)); ?>" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-emerald-200 bg-white text-emerald-700 shadow-sm transition hover:bg-emerald-50 hover:text-emerald-800" aria-label="Planejar" title="Planejar">
                                                            <span class="dashicons dashicons-chart-area text-[16px] leading-none"></span>
                                                            <span class="sr-only">Planejar</span>
                                                        </a>
                                                    <?php endif; ?>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="m-0 inline-flex shrink-0" data-swal-confirm="Excluir este post gerado?">
                                                        <?php wp_nonce_field('content_rank_delete_generated_post', 'content_rank_delete_generated_post_nonce'); ?>
                                                        <input type="hidden" name="action" value="content_rank_delete_generated_post" />
                                                        <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>" />
                                                        <button type="submit" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-rose-200 bg-white text-rose-600 shadow-sm transition hover:bg-rose-50 hover:text-rose-700" aria-label="Excluir" title="Excluir">
                                                            <span class="dashicons dashicons-trash text-[16px] leading-none"></span>
                                                            <span class="sr-only">Excluir</span>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php wp_reset_postdata(); ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="px-6 py-12 text-center text-sm text-slate-500">Nenhum post gerado encontrado.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="flex items-center justify-between gap-4 border-t border-slate-200 px-6 py-4">
                            <div class="text-sm text-slate-500">Página <?php echo esc_html($paged); ?> de <?php echo esc_html($total_pages); ?></div>
                            <div class="flex flex-wrap items-center gap-2">
                                <?php
                                $pagination_links = paginate_links(array(
                                    'base' => add_query_arg(array(
                                        'page' => self::PAGE_SLUG,
                                        'paged' => '%#%',
                                        's' => $search,
                                        'generator_id' => $generator_id,
                                    ), admin_url('admin.php')),
                                    'format' => '',
                                    'current' => $paged,
                                    'total' => $total_pages,
                                    'type' => 'array',
                                    'prev_text' => '&lsaquo;',
                                    'next_text' => '&rsaquo;',
                                ));

                                if (!empty($pagination_links) && is_array($pagination_links)) {
                                    foreach ($pagination_links as $page_link) {
                                        $page_link = (string) $page_link;
                                        if (strpos($page_link, 'current') !== false) {
                                            $page_link = str_replace(
                                                'page-numbers current',
                                                'page-numbers current inline-flex min-w-10 items-center justify-center rounded-xl border border-indigo-600 bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm',
                                                $page_link
                                            );
                                        } elseif (strpos($page_link, 'dots') !== false) {
                                            $page_link = str_replace(
                                                'page-numbers dots',
                                                'page-numbers dots inline-flex min-w-10 items-center justify-center px-2 py-2 text-sm text-slate-400',
                                                $page_link
                                            );
                                        } elseif (strpos($page_link, 'prev') !== false || strpos($page_link, 'next') !== false) {
                                            $page_link = str_replace(
                                                array('page-numbers prev', 'page-numbers next'),
                                                array(
                                                    'page-numbers prev inline-flex min-w-10 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-slate-950',
                                                    'page-numbers next inline-flex min-w-10 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-slate-950',
                                                ),
                                                $page_link
                                            );
                                        } else {
                                            $page_link = str_replace(
                                                'page-numbers',
                                                'page-numbers inline-flex min-w-10 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-slate-950',
                                                $page_link
                                            );
                                        }
                                        echo wp_kses_post($page_link);
                                    }
                                }
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var master = document.querySelector('[data-content-rank-select-all-generated-posts]');
                    if (!master) {
                        return;
                    }

                    var syncMasterState = function () {
                        var boxes = Array.prototype.slice.call(document.querySelectorAll('.content-rank-generated-posts-checkbox'));
                        if (!boxes.length) {
                            master.checked = false;
                            master.indeterminate = false;
                            return;
                        }

                        var checkedCount = boxes.filter(function (box) {
                            return box.checked;
                        }).length;

                        master.checked = checkedCount === boxes.length;
                        master.indeterminate = checkedCount > 0 && checkedCount < boxes.length;
                    };

                    master.addEventListener('change', function () {
                        var boxes = Array.prototype.slice.call(document.querySelectorAll('.content-rank-generated-posts-checkbox'));
                        boxes.forEach(function (box) {
                            box.checked = master.checked;
                        });
                        syncMasterState();
                    });

                    document.addEventListener('change', function (event) {
                        if (event.target && event.target.classList && event.target.classList.contains('content-rank-generated-posts-checkbox')) {
                            syncMasterState();
                        }
                    });
                });
            </script>
            <?php
        }
    }
}
