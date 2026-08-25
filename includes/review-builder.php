<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Content_Rank_Review_Builder')) {
    final class Content_Rank_Review_Builder
    {
        const PAGE_SLUG = 'content-rank-review-builder';
        const STATE_PREFIX = 'content_rank_review_state_';

        public function __construct()
        {
            add_action('admin_menu', array($this, 'admin_menu'), 23);
            add_action('admin_enqueue_scripts', array($this, 'enqueue_media'));
            add_action('admin_post_content_rank_review_analyze', array($this, 'handle_analyze'));
            add_action('admin_post_content_rank_review_generate', array($this, 'handle_generate'));
        }

        public function enqueue_media($hook_suffix)
        {
            if (!isset($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== self::PAGE_SLUG) {
                return;
            }
            wp_enqueue_media();
        }

        public function admin_menu()
        {
            add_submenu_page(
                'content-rank',
                'Reviews de produtos',
                'Reviews',
                'manage_options',
                self::PAGE_SLUG,
                array($this, 'render_page')
            );
        }

        public function render_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            $token = isset($_GET['token']) ? sanitize_key(wp_unslash($_GET['token'])) : '';
            $state = $token !== '' ? get_transient(self::STATE_PREFIX . get_current_user_id() . '_' . $token) : false;
            if (!is_array($state)) {
                $state = array();
            }

            $notice = isset($_GET['content_rank_notice']) ? sanitize_text_field(wp_unslash($_GET['content_rank_notice'])) : '';
            $notice_type = isset($_GET['content_rank_notice_type']) ? sanitize_key(wp_unslash($_GET['content_rank_notice_type'])) : 'success';
            $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
            $products = !empty($state['products']) && is_array($state['products']) ? $state['products'] : array();

            ?>
            <div class="wrap content-rank-review-builder">
                <style>
                    .content-rank-review-builder { max-width: 1280px; }
                    .content-rank-review-builder h1 { margin-bottom: 8px; }
                    .content-rank-review-builder .content-rank-subtitle { color: #64748b; margin: 0 0 24px; }
                    .content-rank-review-builder .content-rank-card { background: #fff; border: 1px solid #dbe3ef; border-radius: 14px; padding: 22px; margin: 16px 0; box-shadow: 0 8px 24px rgba(15, 23, 42, .04); }
                    .content-rank-review-builder label { display: block; font-weight: 600; margin: 0 0 7px; color: #1e293b; }
                    .content-rank-review-builder input[type=text], .content-rank-review-builder input[type=url], .content-rank-review-builder input[type=number], .content-rank-review-builder textarea { width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 11px; }
                    .content-rank-review-builder textarea { resize: vertical; }
                    .content-rank-review-builder .content-rank-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
                    .content-rank-review-builder .content-rank-product { border: 1px solid #dbe3ef; border-radius: 12px; padding: 16px; margin-top: 14px; background: #f8fafc; }
                    .content-rank-review-builder .content-rank-product h3 { margin: 0 0 14px; font-size: 15px; }
                    .content-rank-review-builder .content-rank-product-columns { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; }
                    .content-rank-review-builder .content-rank-reference { border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; background: #fff; color: #475569; }
                    .content-rank-review-builder .content-rank-reference-title { color: #0f172a; font-weight: 700; margin: 0 0 12px; }
                    .content-rank-review-builder .content-rank-reference-row { border-top: 1px solid #f1f5f9; padding: 9px 0 0; margin-top: 9px; overflow-wrap: anywhere; font-size: 12px; }
                    .content-rank-review-builder .content-rank-reference-row strong { display: block; color: #64748b; font-size: 11px; text-transform: uppercase; margin-bottom: 3px; }
                    .content-rank-review-builder .content-rank-reference-image { display: block; width: 150px; height: 110px; object-fit: contain; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; margin-bottom: 6px; }
                    .content-rank-review-builder .content-rank-inputs { border: 1px solid #c7d2fe; border-radius: 10px; padding: 14px; background: #fff; }
                    .content-rank-review-builder .content-rank-inputs-title { color: #1e1b4b; font-weight: 700; margin: 0 0 12px; }
                    .content-rank-review-builder .content-rank-media-picker { display: flex; gap: 8px; align-items: center; }
                    .content-rank-review-builder .content-rank-media-name { color: #64748b; font-size: 12px; overflow-wrap: anywhere; }
                    .content-rank-review-builder .content-rank-actions { display: flex; gap: 10px; align-items: center; margin-top: 18px; }
                    .content-rank-review-builder .content-rank-count { color: #475569; font-weight: 600; }
                    .content-rank-review-builder .content-rank-notice { border-left: 4px solid #22c55e; background: #f0fdf4; padding: 13px 16px; margin: 16px 0; }
                    .content-rank-review-builder .content-rank-notice.error { border-color: #ef4444; background: #fef2f2; }
                    .content-rank-review-builder .content-rank-help { color: #64748b; font-size: 13px; margin-top: 7px; }
                    @media (max-width: 800px) { .content-rank-review-builder .content-rank-grid { grid-template-columns: 1fr; } }
                    @media (max-width: 1000px) { .content-rank-review-builder .content-rank-product-columns { grid-template-columns: 1fr; } }
                </style>

                <h1>Reviews de produtos</h1>
                <p class="content-rank-subtitle">Cole a pagina de referencia, confirme os produtos e gere a review com cards preenchidos pelo PHP.</p>

                <?php if ($notice !== '') : ?>
                    <div class="content-rank-notice <?php echo $notice_type === 'error' ? 'error' : ''; ?>">
                        <?php echo esc_html($notice); ?>
                        <?php if ($post_id > 0) : ?>
                            &nbsp; <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" target="_blank" rel="noopener">Abrir post</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($products)) : ?>
                    <div class="content-rank-card">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="content_rank_review_analyze">
                            <?php wp_nonce_field('content_rank_review_analyze'); ?>
                            <p><label for="content-rank-review-source-html">HTML da pagina</label>
                                <textarea id="content-rank-review-source-html" rows="20" name="source_html" required placeholder="Cole aqui o HTML completo da pagina de produtos..."><?php echo isset($state['source_html']) ? esc_textarea($state['source_html']) : ''; ?></textarea>
                                <span class="content-rank-help">O PHP tenta localizar os cards pela classe informada e usa h3/h2 como fallback.</span>
                            </p>
                            <div class="content-rank-grid">
                                <p><label for="content-rank-review-product-class">Classe dos produtos (opcional)</label>
                                    <input type="text" id="content-rank-review-product-class" name="product_class" value="<?php echo isset($state['product_class']) ? esc_attr($state['product_class']) : ''; ?>" placeholder="Ex.: product-card">
                                </p>
                                <p><label for="content-rank-review-subject">Tema ou titulo da review (opcional)</label>
                                    <input type="text" id="content-rank-review-subject" name="review_subject" value="<?php echo isset($state['review_subject']) ? esc_attr($state['review_subject']) : ''; ?>" placeholder="Ex.: Melhores escovas secadoras">
                                </p>
                            </div>
                            <div class="content-rank-actions"><button type="submit" class="button button-primary">Analisar produtos</button></div>
                        </form>
                    </div>
                <?php else : ?>
                    <div class="content-rank-card">
                        <strong class="content-rank-count"><?php echo esc_html(count($products)); ?> produto(s) encontrado(s)</strong>
                        <p class="content-rank-help">Revise os campos antes de gerar. O link pode ser preenchido agora ou depois.</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="content_rank_review_generate">
                            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                            <?php wp_nonce_field('content_rank_review_generate'); ?>
                            <?php foreach ($products as $index => $product) : $index = absint($index); $field_prefix = 'content-rank-review-product-' . $index; ?>
                                <div class="content-rank-product">
                                    <h3>Produto <?php echo esc_html($index + 1); ?> <code>{{prod<?php echo esc_html($index + 1); ?>}}</code></h3>
                                    <div class="content-rank-product-columns">
                                        <div class="content-rank-reference">
                                            <p class="content-rank-reference-title">Encontrado na pagina</p>
                                            <div class="content-rank-reference-row"><?php if (!empty($product['image_url'])) : ?><img class="content-rank-reference-image" src="<?php echo esc_url($product['image_url']); ?>" alt="<?php echo esc_attr($product['title']); ?>"><?php else : ?>Nao encontrada<?php endif; ?></div>
                                            <div class="content-rank-reference-row"><?php echo !empty($product['title']) ? esc_html($product['title']) : 'Nao encontrado'; ?></div>
                                            <div class="content-rank-reference-row"><?php echo !empty($product['description']) ? esc_html($product['description']) : 'Nao encontrada'; ?></div>
                                            <div class="content-rank-reference-row"><?php echo !empty($product['price_old']) ? esc_html($product['price_old']) : 'Nao encontrado'; ?></div>
                                            <div class="content-rank-reference-row"><?php echo !empty($product['price_current']) ? esc_html($product['price_current']) : 'Nao encontrado'; ?></div>
                                            <div class="content-rank-reference-row"><?php echo !empty($product['installments']) ? esc_html($product['installments']) : 'Nao encontrado'; ?></div>
                                            <div class="content-rank-reference-row"><?php echo !empty($product['rating']) ? esc_html($product['rating']) : 'Nao encontrada'; ?></div>
                                        </div>
                                        <div class="content-rank-inputs">
                                            <p class="content-rank-inputs-title">Dados usados na review</p>
                                            <p><label for="<?php echo esc_attr($field_prefix); ?>-image">Imagem</label><span class="content-rank-media-picker"><button type="button" class="button content-rank-review-image-select" data-target="<?php echo esc_attr($field_prefix); ?>-image" data-name="<?php echo esc_attr($field_prefix); ?>-image-name">Selecionar da biblioteca</button><span class="content-rank-media-name" id="<?php echo esc_attr($field_prefix); ?>-image-name"><?php echo !empty($product['image_url']) ? 'Imagem detectada' : 'Nenhuma imagem selecionada'; ?></span></span><input type="hidden" id="<?php echo esc_attr($field_prefix); ?>-image" name="products[<?php echo esc_attr($index); ?>][image_url]" value="<?php echo !empty($product['image_url']) ? esc_attr($product['image_url']) : ''; ?>"></p>
                                            <p><label for="<?php echo esc_attr($field_prefix); ?>-title">Titulo do produto</label><input required type="text" id="<?php echo esc_attr($field_prefix); ?>-title" name="products[<?php echo esc_attr($index); ?>][title]" value="<?php echo isset($product['title']) ? esc_attr($product['title']) : ''; ?>" placeholder="Ex.: GA.MA Italy Babosa Active Brush"></p>
                                            <p><label for="<?php echo esc_attr($field_prefix); ?>-description">Descricao do produto</label><textarea id="<?php echo esc_attr($field_prefix); ?>-description" name="products[<?php echo esc_attr($index); ?>][description]" rows="3" placeholder="Ex.: Escova secadora com controle de temperatura e uso bivolt."><?php echo isset($product['description']) ? esc_textarea($product['description']) : ''; ?></textarea></p>
                                            <p><label for="<?php echo esc_attr($field_prefix); ?>-old">Valor original</label><input type="text" id="<?php echo esc_attr($field_prefix); ?>-old" name="products[<?php echo esc_attr($index); ?>][price_old]" value="<?php echo isset($product['price_old']) ? esc_attr($product['price_old']) : ''; ?>" placeholder="Ex.: De R$ 229,90"></p>
                                            <p><label for="<?php echo esc_attr($field_prefix); ?>-current">Valor promocional</label><input type="text" id="<?php echo esc_attr($field_prefix); ?>-current" name="products[<?php echo esc_attr($index); ?>][price_current]" value="<?php echo isset($product['price_current']) ? esc_attr($product['price_current']) : ''; ?>" placeholder="Ex.: R$ 199,90"></p>
                                            <p><label for="<?php echo esc_attr($field_prefix); ?>-installments">Valor parcelado</label><input type="text" id="<?php echo esc_attr($field_prefix); ?>-installments" name="products[<?php echo esc_attr($index); ?>][installments]" value="<?php echo isset($product['installments']) ? esc_attr($product['installments']) : ''; ?>" placeholder="Ex.: ou 2x de R$ 99,95 sem juros"></p>
                                            <p><label for="<?php echo esc_attr($field_prefix); ?>-rating">Nota</label><input type="text" id="<?php echo esc_attr($field_prefix); ?>-rating" name="products[<?php echo esc_attr($index); ?>][rating]" value="<?php echo isset($product['rating']) ? esc_attr($product['rating']) : ''; ?>" placeholder="Ex.: 9,8"></p>
                                            <p><label for="<?php echo esc_attr($field_prefix); ?>-link">Link de afiliado</label><input type="url" id="<?php echo esc_attr($field_prefix); ?>-link" name="products[<?php echo esc_attr($index); ?>][link]" value="" placeholder="https://loja.exemplo.com/produto"><span class="content-rank-help">Insira o link depois. Este campo fica vazio até você informar o link de afiliado.</span></p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="content-rank-actions"><button type="submit" class="button button-primary">Gerar review</button><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>">Nova analise</a></div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($products)) : ?>
                <script>
                    (function () {
                        function bindReviewMedia() {
                            if (!window.wp || !wp.media) {
                                window.setTimeout(bindReviewMedia, 100);
                                return;
                            }
                            var frame;
                            document.querySelectorAll('.content-rank-review-image-select').forEach(function (button) {
                                if (button.dataset.arcMediaBound === '1') return;
                                button.dataset.arcMediaBound = '1';
                                button.addEventListener('click', function () {
                                    var target = document.getElementById(button.getAttribute('data-target'));
                                    var name = document.getElementById(button.getAttribute('data-name'));
                                    if (!target) return;
                                    frame = wp.media({
                                        title: 'Selecionar imagem do produto',
                                        button: { text: 'Usar imagem' },
                                        multiple: false
                                    });
                                    frame.on('select', function () {
                                        var attachment = frame.state().get('selection').first().toJSON();
                                        target.value = attachment.url || '';
                                        if (name) name.textContent = attachment.filename || attachment.url || 'Imagem selecionada';
                                    });
                                    frame.open();
                                });
                            });
                        }
                        bindReviewMedia();
                    }());
                </script>
            <?php endif; ?>
            <?php
        }

        public function handle_analyze()
        {
            $this->check_access('content_rank_review_analyze');
            $html = isset($_POST['source_html']) ? wp_unslash($_POST['source_html']) : '';
            $product_class = isset($_POST['product_class']) ? sanitize_text_field(wp_unslash($_POST['product_class'])) : '';
            $review_subject = isset($_POST['review_subject']) ? sanitize_text_field(wp_unslash($_POST['review_subject'])) : '';
            if (trim($html) === '') {
                $this->redirect_error('Cole o HTML da pagina antes de analisar.');
            }

            $products = self::extract_products($html, $product_class);
            if (empty($products)) {
                $this->redirect_error('Nenhum produto foi encontrado. Informe a classe dos cards ou verifique o HTML colado.');
            }

            $token = wp_generate_password(20, false, false);
            set_transient(self::STATE_PREFIX . get_current_user_id() . '_' . $token, array(
                'source_html' => $html,
                'product_class' => $product_class,
                'review_subject' => $review_subject,
                'products' => $products,
            ), DAY_IN_SECONDS);
            wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'token' => $token), admin_url('admin.php')));
            exit;
        }

        public function handle_generate()
        {
            $this->check_access('content_rank_review_generate');
            $token = isset($_POST['token']) ? sanitize_key(wp_unslash($_POST['token'])) : '';
            $state_key = self::STATE_PREFIX . get_current_user_id() . '_' . $token;
            $state = $token !== '' ? get_transient($state_key) : false;
            if (!is_array($state) || empty($state['source_html'])) {
                $this->redirect_error('A analise expirou. Cole o HTML novamente.');
            }

            $submitted_products = self::sanitize_products(isset($_POST['products']) ? wp_unslash($_POST['products']) : array());
            $detected_products = self::extract_products((string) $state['source_html'], isset($state['product_class']) ? (string) $state['product_class'] : '');
            $products = self::merge_detected_products($submitted_products, $detected_products);
            if (empty($products)) {
                $this->redirect_error('Informe pelo menos um produto.');
            }

            $generator = self::build_review_generator();
            $subject = !empty($state['review_subject']) ? sanitize_text_field((string) $state['review_subject']) : '';
            if ($subject === '') {
                $subject = self::extract_document_title((string) $state['source_html']);
            }
            if ($subject === '') {
                $subject = 'Review de produtos';
            }

            $item = array(
                'guid' => 'review-builder:' . $token,
                'title' => $subject,
                'source_title' => $subject,
                'source_page_title' => $subject,
                'source_page_html' => (string) $state['source_html'],
                'source_page_content_html' => (string) $state['source_html'],
                'source_page_content' => (string) $state['source_html'],
                'content' => (string) $state['source_html'],
                'excerpt' => wp_trim_words(wp_strip_all_tags((string) $state['source_html']), 100),
                'source_page_excerpt' => wp_trim_words(wp_strip_all_tags((string) $state['source_html']), 100),
                'permalink' => '',
                'source_url' => '',
                'source_image_url' => !empty($products[0]['image_url']) ? $products[0]['image_url'] : '',
                'keyword' => $subject,
                'content_type' => 'review',
                'recommended_prompt_model_key' => 'review',
                'review_products_prompt' => self::build_products_prompt($products),
                'review_products' => $products,
            );

            $queued = Content_Rank_Generator::queue_staged_generation($generator, $item, 0);
            if (is_wp_error($queued)) {
                $this->redirect_error('Falha ao enfileirar a review: ' . $queued->get_error_message());
            }
            $post_id = !empty($queued['post_id']) ? absint($queued['post_id']) : 0;
            if ($post_id <= 0) {
                $this->redirect_error('A review nao foi enfileirada corretamente.');
            }

            update_post_meta($post_id, '_content_rank_review_products_json', wp_json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            delete_transient($state_key);

            wp_safe_redirect(add_query_arg(array(
                'page' => self::PAGE_SLUG,
                'post_id' => $post_id,
            ), admin_url('admin.php')));
            exit;
        }

        private function check_access($action)
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }
            check_admin_referer($action);
        }

        private function redirect_error($message)
        {
            wp_safe_redirect(add_query_arg(array(
                'page' => self::PAGE_SLUG,
                'content_rank_notice' => $message,
                'content_rank_notice_type' => 'error',
            ), admin_url('admin.php')));
            exit;
        }

        private static function build_review_generator()
        {
            $settings = Content_Rank_Generator::get_settings();
            $generator = array(
                'id' => 0,
                'name' => 'Reviews de produtos',
                'feed_url' => '',
                'source_type' => 'rss',
                'generation_mode' => 'pillar',
                'status' => 'active',
                'post_type' => 'post',
                'post_status' => 'draft',
                'author_id' => get_current_user_id(),
                'category_ids' => '[]',
                'default_category_id' => 0,
                'tags_default' => '[]',
                'custom_taxonomies' => '[]',
                'custom_meta' => '[]',
                'filters_json' => '{}',
                'model' => !empty($settings['default_model']) ? $settings['default_model'] : 'gpt-4.1-mini',
                'temperature' => isset($settings['default_temperature']) ? $settings['default_temperature'] : 0.7,
                'max_tokens' => !empty($settings['default_max_tokens']) ? $settings['default_max_tokens'] : 3000,
                'posts_per_run' => 1,
                'schedule_type' => 'interval',
                'interval_minutes' => 180,
                'jitter_minutes' => 0,
                'daily_start' => '06:00',
                'daily_end' => '22:00',
                'image_source_mode' => 'rss',
                'pexels_enabled' => 0,
                'source_video_enabled' => 0,
                'source_content_images_enabled' => 0,
                'source_content_links_enabled' => 0,
                'content_selector' => '',
                'image_selector_class' => '',
                'link_selector_class' => '',
                'content_image_size' => 'medium_large',
                'content_image_interval_words' => 500,
                'source_context_filters_json' => '{}',
                'seo_enabled' => 1,
                'generation_language' => 'pt-BR',
                'prompt_model_key' => 'review',
                'prompt_models_json' => '',
                'outline_model_key' => 'guide_long',
                'prompt_template' => '',
                'content_prompt_template' => '',
                'random_bolds_enabled' => 0,
                'related_posts_enabled' => 0,
                'internal_links_json' => '[]',
                'source_link_phrases' => '',
            );

            return Content_Rank_Generator::prepare_generator_record($generator);
        }

        private static function sanitize_products($products)
        {
            if (!is_array($products)) {
                return array();
            }
            $clean = array();
            foreach ($products as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $title = isset($product['title']) ? sanitize_text_field((string) $product['title']) : '';
                if ($title === '') {
                    continue;
                }
                $clean[] = array(
                    'title' => $title,
                    'image_url' => !empty($product['image_url']) ? esc_url_raw((string) $product['image_url']) : '',
                    'description' => isset($product['description']) ? sanitize_textarea_field((string) $product['description']) : '',
                    'price_old' => isset($product['price_old']) ? sanitize_text_field((string) $product['price_old']) : '',
                    'price_current' => isset($product['price_current']) ? sanitize_text_field((string) $product['price_current']) : '',
                    'installments' => isset($product['installments']) ? sanitize_text_field((string) $product['installments']) : '',
                    'rating' => isset($product['rating']) ? sanitize_text_field((string) $product['rating']) : '',
                    'link' => !empty($product['link']) ? esc_url_raw((string) $product['link']) : '',
                );
            }
            return $clean;
        }

        private static function build_products_prompt($products)
        {
            $lines = array('Produtos mapeados pelo usuario:');
            foreach ($products as $index => $product) {
                $number = $index + 1;
                $lines[] = sprintf('Produto %d | placeholder={{prod%d}}', $number, $number);
                $lines[] = 'nome: ' . $product['title'];
                $lines[] = 'descricao: ' . ($product['description'] !== '' ? $product['description'] : '[nao informada]');
                $lines[] = 'preco_original: ' . ($product['price_old'] !== '' ? $product['price_old'] : '[nao informado]');
                $lines[] = 'preco_promocional: ' . ($product['price_current'] !== '' ? $product['price_current'] : '[nao informado]');
                $lines[] = 'parcelamento: ' . ($product['installments'] !== '' ? $product['installments'] : '[nao informado]');
                $lines[] = 'nota: ' . ($product['rating'] !== '' ? $product['rating'] : '[nao informada]');
            }
            return implode("\n", $lines);
        }

        private static function merge_detected_products($submitted, $detected)
        {
            if (!is_array($submitted)) {
                return array();
            }
            $merged = array();
            foreach ($submitted as $index => $product) {
                $fallback = isset($detected[$index]) && is_array($detected[$index]) ? $detected[$index] : array();
                foreach (array('title', 'image_url', 'description', 'price_old', 'price_current', 'installments', 'rating', 'link') as $field) {
                    if (empty($product[$field]) && !empty($fallback[$field])) {
                        $product[$field] = $fallback[$field];
                    }
                }
                if (!empty($product['title'])) {
                    $merged[] = $product;
                }
            }
            return $merged;
        }

        private static function extract_products($html, $product_class = '')
        {
            $html = (string) $html;
            $products = array();
            if (!class_exists('DOMDocument')) {
                return $products;
            }

            $previous = libxml_use_internal_errors(true);
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new DOMXPath($dom);
            $nodes = array();
            $class_tokens = preg_split('/\s+/', trim($product_class));
            $class_token = !empty($class_tokens[0]) ? sanitize_html_class($class_tokens[0]) : '';
            if ($class_token !== '') {
                $query = "//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $class_token . " ')]";
                $result = $xpath->query($query);
                if ($result) {
                    foreach ($result as $node) {
                        $nodes[] = $node;
                    }
                }
            }
            if (empty($nodes)) {
                $result = $xpath->query('//h3 | //h2');
                if ($result) {
                    foreach ($result as $node) {
                        $nodes[] = $node;
                    }
                }
            }

            $seen = array();
            foreach ($nodes as $node) {
                $title_node = $node;
                $product_node = $node;
                if (strtolower($node->nodeName) !== 'h2' && strtolower($node->nodeName) !== 'h3') {
                    $heading = $xpath->query('.//h3 | .//h2', $node);
                    if ($heading && $heading->length > 0) {
                        $title_node = $heading->item(0);
                    }
                } else {
                    // When no card class is available, use the nearest parent
                    // that contains media or a product link, not the whole page.
                    for ($level = 0; $level < 4; $level++) {
                        $has_image = $xpath->query('.//img', $product_node);
                        $has_link = $xpath->query('.//a[@href]', $product_node);
                        if (($has_image && $has_image->length > 0) || ($has_link && $has_link->length > 0)) {
                            break;
                        }
                        if (!$product_node->parentNode || !($product_node->parentNode instanceof DOMElement)) {
                            break;
                        }
                        $product_node = $product_node->parentNode;
                    }
                }
                $title = self::normalize_detected_product_title($title_node ? $title_node->textContent : $node->textContent);
                if ($title === '') {
                    continue;
                }
                $key = strtolower($title);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $text = self::clean_text($product_node->textContent);
                $description = '';
                $paragraphs = $xpath->query('.//p', $product_node);
                if ($paragraphs) {
                    foreach ($paragraphs as $paragraph) {
                        $candidate = self::clean_text($paragraph->textContent);
                        if ($candidate !== '' && stripos($candidate, $title) === false && !preg_match('/R\$\s*[\d\.]+[\,\d]*/iu', $candidate)) {
                            $description = $candidate;
                            break;
                        }
                    }
                }
                $image_url = self::find_image_url($xpath, $product_node);
                $link = '';
                $anchors = $xpath->query('.//a[@href]', $product_node);
                if ($anchors && $anchors->length > 0) {
                    $anchor = $anchors->item(0);
                    if ($anchor instanceof DOMElement) {
                        $link = esc_url_raw((string) $anchor->getAttribute('href'));
                    }
                }
                $prices = array();
                if (preg_match_all('/R\$\s*[\d\.]+[\,\d]*/iu', $text, $matches)) {
                    $prices = array_values(array_unique($matches[0]));
                }
                $rating = '';
                if (preg_match('/(?:nota|rating|score)?\s*(\d+(?:[\.,]\d+)?)\s*(?:\/\s*10|estrelas?)/iu', $text, $match)) {
                    $rating = trim((string) $match[1]);
                }
                $installments = '';
                if (preg_match('/(?:ou\s+)?\d+\s*x\s*(?:de\s*)?R\$\s*[\d\.]+[\,\d]*(?:\s+sem\s+juros)?/iu', $text, $match)) {
                    $installments = self::clean_text($match[0]);
                }
                $products[] = array(
                    'title' => $title,
                    'image_url' => $image_url,
                    'description' => $description,
                    'price_old' => count($prices) > 1 && isset($prices[0]) ? self::clean_text($prices[0]) : '',
                    'price_current' => isset($prices[1]) ? self::clean_text($prices[1]) : (isset($prices[0]) ? self::clean_text($prices[0]) : ''),
                    'installments' => $installments,
                    'rating' => $rating,
                    'link' => $link,
                );
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return array_slice($products, 0, 50);
        }

        private static function find_image_url($xpath, $node)
        {
            $images = $xpath->query('.//img', $node);
            if (!$images || $images->length === 0) {
                return '';
            }
            $image = $images->item(0);
            if (!$image instanceof DOMElement) {
                return '';
            }
            foreach (array('src', 'data-src', 'data-lazy-src', 'data-original') as $attribute) {
                $value = trim((string) $image->getAttribute($attribute));
                if ($value !== '' && stripos($value, 'data:') !== 0) {
                    return esc_url_raw($value);
                }
            }
            $srcset = trim((string) $image->getAttribute('srcset'));
            if ($srcset !== '') {
                $parts = preg_split('/\s*,\s*/', $srcset);
                if (!empty($parts[0])) {
                    return esc_url_raw(trim(preg_split('/\s+/', $parts[0])[0]));
                }
            }
            return '';
        }

        private static function clean_text($text)
        {
            return trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $text)));
        }

        private static function normalize_detected_product_title($text)
        {
            $title = self::clean_text($text);
            if ($title === '') {
                return '';
            }

            // Product headings frequently append commercial copy to the name.
            $title = preg_replace('/\s+(?:por|a partir de|desde)\s+R\$.*$/iu', '', $title);
            if (strpos($title, ' - ') !== false) {
                $parts = explode(' - ', $title, 2);
                if (count($parts) === 2 && preg_match('/R\$|\b(?:escova|secadora|oferta|promocao|frete|parcelas?)\b/iu', $parts[1])) {
                    $title = $parts[0];
                }
            }

            return trim($title, " \t\n\r\0\x0B-,:;");
        }

        private static function extract_document_title($html)
        {
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', (string) $html, $match)) {
                return self::clean_text(html_entity_decode((string) $match[1], ENT_QUOTES, 'UTF-8'));
            }
            return '';
        }

        public static function replace_product_placeholders_for_pipeline($content, $products, $post_id = 0)
        {
            return self::replace_product_placeholders($content, $products, $post_id);
        }

        /**
         * Ensure every configured product has a position in the article.
         * The model may omit placeholders when the outline is too compact;
         * distribute missing products after the corresponding headings rather
         * than appending every card to the end of the post.
         */
        private static function ensure_product_placeholders($content, $products)
        {
            $content = (string) $content;
            if ($content === '' || empty($products) || !is_array($products) || stripos($content, 'rc-container') !== false) {
                return $content;
            }

            $present = array();
            if (preg_match_all('/\{\{prod(\d+)\}\}/i', $content, $matches)) {
                foreach ($matches[1] as $number) {
                    $present[absint($number)] = true;
                }
            }

            $missing = array();
            foreach (array_values($products) as $index => $product) {
                $number = $index + 1;
                if (!isset($present[$number])) {
                    $missing[] = $number;
                }
            }
            if (empty($missing)) {
                return $content;
            }

            $heading_matches = array();
            preg_match_all('/<h[2-3]\b[^>]*>.*?<\/h[2-3]>\s*/is', $content, $heading_matches, PREG_OFFSET_CAPTURE);
            $insertions = array();
            foreach ($missing as $number) {
                $heading_index = $number - 1;
                if (!empty($heading_matches[0][$heading_index][0])) {
                    $heading_html = (string) $heading_matches[0][$heading_index][0];
                    $offset = intval($heading_matches[0][$heading_index][1]) + strlen($heading_html);
                    $insertions[$offset][] = "<p>{{prod{$number}}}</p>\n";
                }
            }

            if (!empty($insertions)) {
                krsort($insertions, SORT_NUMERIC);
                foreach ($insertions as $offset => $parts) {
                    $content = substr($content, 0, $offset) . implode('', $parts) . substr($content, $offset);
                }
            }

            // If there are fewer headings than products, place the remaining
            // cards before the final section instead of after the whole post.
            $still_missing = array();
            foreach ($missing as $number) {
                if (strpos($content, '{{prod' . $number . '}}') === false) {
                    $still_missing[] = $number;
                }
            }
            if (!empty($still_missing)) {
                $fallback = '';
                foreach ($still_missing as $number) {
                    $fallback .= "<p>{{prod{$number}}}</p>\n";
                }
                $last_heading = strripos($content, '<h2');
                if ($last_heading !== false) {
                    $content = substr($content, 0, $last_heading) . $fallback . substr($content, $last_heading);
                } else {
                    $content .= "\n" . $fallback;
                }
            }

            return $content;
        }

        private static function replace_product_placeholders($content, $products, $post_id = 0)
        {
            $content = (string) $content;
            $products = array_values(is_array($products) ? $products : array());
            if ($content === '' || empty($products)) {
                return $content;
            }
            if (stripos($content, 'rc-container') === false) {
                $content = self::ensure_product_placeholders($content, $products);
            }
            foreach ($products as $index => $product) {
                $placeholder = '{{prod' . ($index + 1) . '}}';
                if (strpos($content, $placeholder) === false) {
                    continue;
                }
                $card = self::render_product_card($product, $index + 1, $post_id);
                // The model often wraps a standalone placeholder in a
                // paragraph. Remove that wrapper before inserting a div.
                $content = preg_replace(
                    '/<p[^>]*>\s*' . preg_quote($placeholder, '/') . '\s*<\/p>/i',
                    $card,
                    $content,
                    1
                );
                $content = str_replace($placeholder, $card, $content);
            }
            return (string) preg_replace('/\{\{prod\d+\}\}/i', '', $content);
        }

        private static function render_product_card($product, $number, $post_id = 0)
        {
            $title = !empty($product['title']) ? (string) $product['title'] : 'Produto';
            $image_html = '';
            if (!empty($product['image_url'])) {
                $attachment_id = $post_id > 0
                    ? Content_Rank_Generator::download_image_attachment_from_url($post_id, $product['image_url'], $title, 'review_product', $title, '')
                    : 0;
                if ($attachment_id && !is_wp_error($attachment_id)) {
                    $image_html = wp_get_attachment_image(absint($attachment_id), 'medium_large', false, array('alt' => $title, 'class' => 'review-product-image'));
                }
                if ($image_html === '') {
                    $image_html = '<img class="review-product-image" src="' . esc_url($product['image_url']) . '" alt="' . esc_attr($title) . '">';
                }
            }

            $link_html = '';
            if (!empty($product['link'])) {
                $link_html = '<div class="wp-block-buttons is-layout-flex wp-block-buttons-is-layout-flex"><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url($product['link']) . '" target="_blank" rel="nofollow sponsored noopener">Ir para a Loja Oficial &#8594;</a></div></div>';
            }
            $old_html = !empty($product['price_old']) ? '<span class="rc-old">' . esc_html($product['price_old']) . '</span>' : '';
            $current_html = !empty($product['price_current']) ? '<span class="rc-current">' . esc_html($product['price_current']) . '</span>' : '';
            $installment_html = !empty($product['installments']) ? '<span class="rc-inst">' . esc_html($product['installments']) . '</span>' : '';
            $description_html = !empty($product['description']) ? '<p class="rc-desc">' . esc_html($product['description']) . '</p>' : '';
            $rating = !empty($product['rating']) ? esc_html($product['rating']) : '';
            $rating_html = $rating !== '' ? '<div class="rc-stars-row"><span class="rc-stars">★★★★★</span><span class="rc-number">' . $rating . ' <span class="rc-gray">/ 10</span></span></div>' : '';

            return '<div class="rc-container content-rank-review-card"><div class="rc-card"><div class="rc-badge d-none"><span class="rc-icon">&#127942;</span> &#127942; NOSSA ESCOLHA / MELHOR GERAL</div><div class="rc-grid"><div class="rc-img-sec">' . $image_html . '</div><div class="rc-info"><div class="brand-meta"><span class="brand-name">#' . esc_html(str_pad((string) $number, 2, '0', STR_PAD_LEFT)) . '</span></div><h3 class="rc-title">' . esc_html($title) . '</h3>' . $description_html . $rating_html . '<div class="rc-price-cta"><div class="rc-price">' . $old_html . $current_html . $installment_html . '</div>' . $link_html . '</div></div></div></div></div>';
        }
    }
}
