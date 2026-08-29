<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Content_Rank_Social')) {
    final class Content_Rank_Social
    {
        public const PAGE_SLUG = 'content-rank-social';
        private const META_PACKAGE = '_content_rank_social_package';

        public function __construct()
        {
            add_action('admin_menu', array($this, 'admin_menu'), 24);
        }

        public function admin_menu()
        {
            add_submenu_page(
                'content-rank',
                'Redes sociais',
                'Redes sociais',
                'manage_options',
                self::PAGE_SLUG,
                array($this, 'render_page')
            );
        }

        public static function maybe_create_package($post_id)
        {
            $post_id = absint($post_id);
            if ($post_id <= 0 || !get_post_meta($post_id, '_content_rank_generator_id', true)) {
                return false;
            }

            $package = self::build_package($post_id);
            if (empty($package)) {
                return false;
            }

            update_post_meta($post_id, self::META_PACKAGE, $package);
            return true;
        }

        private static function build_package($post_id)
        {
            $post = get_post($post_id);
            if (!$post instanceof WP_Post || trim((string) $post->post_content) === '') {
                return array();
            }

            $slides = array();
            $featured_url = get_the_post_thumbnail_url($post_id, 'large');
            $slides[] = array(
                'type' => 'cover',
                'title' => get_the_title($post_id),
                'text' => wp_trim_words(wp_strip_all_tags((string) $post->post_excerpt), 28),
                'image_url' => $featured_url ? esc_url_raw($featured_url) : '',
                'alt' => get_the_title($post_id),
            );

            $html = (string) $post->post_content;
            if (preg_match_all('/<h2\b[^>]*>(.*?)<\/h2>/is', $html, $heading_matches, PREG_OFFSET_CAPTURE)) {
                foreach ($heading_matches[1] as $index => $heading_match) {
                    $heading = trim(wp_strip_all_tags((string) $heading_match[0]));
                    if ($heading === '' || preg_match('/^(conclus[aã]o|conclusion)$/iu', $heading)) {
                        continue;
                    }
                    $start = (int) $heading_matches[0][$index][1] + strlen($heading_matches[0][$index][0]);
                    $end = isset($heading_matches[0][$index + 1][1]) ? (int) $heading_matches[0][$index + 1][1] : strlen($html);
                    $section = substr($html, $start, max(0, $end - $start));
                    $text = wp_trim_words(wp_strip_all_tags($section), 35);
                    $slides[] = array(
                        'type' => 'item',
                        'title' => $heading,
                        'text' => $text,
                        'image_url' => '',
                        'alt' => $heading,
                    );
                }
            }

            $slides[] = array(
                'type' => 'cta',
                'title' => 'Leia o conteúdo completo',
                'text' => 'Acesse o artigo para ver todos os detalhes.',
                'image_url' => '',
                'alt' => 'Leia o conteúdo completo',
            );

            return array(
                'version' => 1,
                'post_id' => $post_id,
                'title' => get_the_title($post_id),
                'canonical_url' => get_permalink($post_id),
                'caption' => get_the_title($post_id) . "\n\n" . wp_trim_words(wp_strip_all_tags((string) $post->post_excerpt), 45),
                'status' => 'draft',
                'created_at' => current_time('mysql'),
                'slides' => $slides,
            );
        }

        public function render_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }
            $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
            $posts = get_posts(array(
                'post_type' => 'post',
                'post_status' => array('draft', 'publish', 'pending', 'future'),
                'posts_per_page' => 50,
                'meta_key' => '_content_rank_generator_id',
                'orderby' => 'date',
                'order' => 'DESC',
            ));
            $package = $post_id > 0 ? get_post_meta($post_id, self::META_PACKAGE, true) : array();
            $package = is_array($package) ? $package : array();
            ?>
            <div class="wrap">
                <h1>Redes sociais</h1>
                <p>Pacotes sociais gerados a partir dos posts do Content Rank.</p>
                <form method="get" style="max-width:700px;margin:20px 0;">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                    <select name="post_id" style="min-width:420px;">
                        <option value="0">Selecione um post</option>
                        <?php foreach ($posts as $post) : ?>
                            <option value="<?php echo esc_attr($post->ID); ?>" <?php selected($post_id, $post->ID); ?>><?php echo esc_html($post->post_title . ' (#' . $post->ID . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button button-primary" type="submit">Abrir pacote</button>
                </form>
                <?php if (!empty($package)) : ?>
                    <div style="background:#fff;border:1px solid #dcdcde;padding:20px;max-width:900px;">
                        <h2><?php echo esc_html($package['title']); ?></h2>
                        <p><strong>Status:</strong> <?php echo esc_html($package['status']); ?> | <a href="<?php echo esc_url($package['canonical_url']); ?>" target="_blank" rel="noopener">Abrir post</a></p>
                        <p><strong>Legenda:</strong><br /><?php echo nl2br(esc_html($package['caption'])); ?></p>
                        <h3>Slides</h3>
                        <ol>
                            <?php foreach ($package['slides'] as $slide) : ?>
                                <li style="margin-bottom:14px;"><strong><?php echo esc_html($slide['title']); ?></strong><br /><?php echo esc_html($slide['text']); ?><?php if (!empty($slide['image_url'])) : ?><br /><img src="<?php echo esc_url($slide['image_url']); ?>" style="max-width:240px;height:auto;margin-top:6px;" /><?php endif; ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                <?php elseif ($post_id > 0) : ?>
                    <div class="notice notice-warning"><p>Este post ainda não possui um pacote social.</p></div>
                <?php endif; ?>
            </div>
            <?php
        }
    }
}
