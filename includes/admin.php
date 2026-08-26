<?php

if (!defined('ABSPATH')) {
    exit;
}

class Content_Rank_Generator_Admin
{
    public function __construct()
    {
        add_action('admin_notices', array(__CLASS__, 'render_notice'));
        add_action('admin_post_content_rank_test_tmdb_thumbnail', array($this, 'handle_test_tmdb_thumbnail'));
    }

    public function admin_menu()
    {
        add_menu_page(
            'Content Rank',
            'Content Rank',
            'manage_options',
            'content-rank',
            array($this, 'render_admin_page'),
            'dashicons-rss',
            31
        );
        remove_submenu_page('content-rank', 'content-rank');
        add_submenu_page(
            'content-rank',
            'Geradores',
            'Geradores',
            'manage_options',
            'content-rank',
            array($this, 'render_admin_page')
        );
        add_submenu_page(
            'content-rank',
            'Importação',
            'Importação',
            'manage_options',
            'content-rank-keyword-lists',
            array($this, 'render_keyword_lists_page')
        );
        remove_submenu_page('content-rank', 'content-rank-keyword-lists');
        add_submenu_page(
            'content-rank',
            'Keyword lists',
            'Keyword lists',
            'manage_options',
            'content-rank-keyword-lists',
            array($this, 'render_keyword_lists_page')
        );
    }

    public function admin_menu_late()
    {
        add_submenu_page(
            'content-rank',
            'Configurações',
            'Configurações',
            'manage_options',
            'content-rank-global-settings',
            array($this, 'render_global_settings_page'),
            999
        );
        add_submenu_page(
            'content-rank',
            'Teste de thumbnail TMDB',
            'Teste de thumbnail TMDB',
            'manage_options',
            'content-rank-tmdb-thumbnail-test',
            array($this, 'render_tmdb_thumbnail_test_page'),
            998
        );
    }

    public function render_tmdb_thumbnail_test_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }

        $attachment_id = isset($_GET['attachment_id']) ? absint($_GET['attachment_id']) : 0;
        $error = isset($_GET['tmdb_error']) ? sanitize_text_field(wp_unslash($_GET['tmdb_error'])) : '';
        $movies = isset($_GET['movies']) ? json_decode(base64_decode((string) wp_unslash($_GET['movies'])), true) : array();
        $movies = is_array($movies) ? $movies : array();
        ?>
        <div class="wrap">
            <h1>Teste de thumbnail TMDB</h1>
            <p>Consulta o TMDB e monta uma imagem sem gerar conteúdo ou consumir créditos da OpenAI.</p>
            <?php if ($error !== '') : ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
            <?php if ($attachment_id > 0) : ?>
                <div class="notice notice-success"><p>Thumbnail criada. Anexo #<?php echo esc_html($attachment_id); ?>.</p></div>
                <p><img src="<?php echo esc_url(wp_get_attachment_image_url($attachment_id, 'large')); ?>" style="max-width:1200px;height:auto;display:block;background:#111;" /></p>
                <?php if (!empty($movies)) : ?><p><strong>Filmes usados:</strong> <?php echo esc_html(implode(', ', array_map(function ($movie) { return !empty($movie['title']) ? $movie['title'] : ''; }, $movies))); ?></p><?php endif; ?>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:620px;background:#fff;padding:20px;border:1px solid #ccd0d4;">
                <input type="hidden" name="action" value="content_rank_test_tmdb_thumbnail" />
                <?php wp_nonce_field('content_rank_test_tmdb_thumbnail'); ?>
                <p><label for="content-rank-tmdb-query"><strong>Keyword ou termo</strong></label><br /><input id="content-rank-tmdb-query" type="text" name="query" class="regular-text" placeholder="Ex.: filmes infantis" /></p>
                <p><label for="content-rank-tmdb-genre"><strong>Gênero TMDB</strong></label><br /><select id="content-rank-tmdb-genre" name="genre_id" class="regular-text"><option value="0">Qualquer gênero</option><option value="16">Animação</option><option value="10751">Família</option><option value="12">Aventura</option><option value="35">Comédia</option><option value="14">Fantasia</option><option value="28">Ação</option><option value="18">Drama</option><option value="10749">Romance</option><option value="878">Ficção científica</option></select></p>
                <p><label for="content-rank-tmdb-limit"><strong>Quantidade</strong></label><br /><input id="content-rank-tmdb-limit" type="number" name="limit" value="5" min="1" max="5" /></p>
                <p><label for="content-rank-tmdb-color"><strong>Cor da faixa</strong></label><br /><input id="content-rank-tmdb-color" type="color" name="bg_color" value="#0f2d80" /></p>
                <p><label><input type="checkbox" name="auto_color" value="1" /> Extrair cor automaticamente da primeira imagem</label></p>
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var layout = document.querySelector('select[name="layout"]');
                    if (!layout) return;
                    var options = { rotate: 'Aleatório', spotlight: 'Filme em destaque', blur_background: 'Fundo desfocado' };
                    Object.keys(options).forEach(function (value) {
                        if (!layout.querySelector('option[value="' + value + '"]')) {
                            var option = document.createElement('option');
                            option.value = value;
                            option.textContent = options[value];
                            layout.appendChild(option);
                        }
                    });
                });
                </script>
                <p><label for="content-rank-tmdb-layout"><strong>Estilo da composição</strong></label><br /><select id="content-rank-tmdb-layout" name="layout" class="regular-text"><option value="standard">Painéis padrão</option><option value="skew">Painéis skew</option><option value="center_focus">Filme central maior</option></select></p>
                <p><button type="submit" class="button button-primary">Gerar thumbnail</button></p>
            </form>
        </div>
        <?php
    }

    public function handle_test_tmdb_thumbnail()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }
        check_admin_referer('content_rank_test_tmdb_thumbnail');
        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        $genre_id = isset($_POST['genre_id']) ? absint($_POST['genre_id']) : 0;
        $limit = isset($_POST['limit']) ? min(5, max(1, absint($_POST['limit']))) : 5;
        $auto_color = !empty($_POST['auto_color']);
        $bg_color = $auto_color ? 'auto' : (isset($_POST['bg_color']) ? Content_Rank_Generator::normalize_hex_color(wp_unslash($_POST['bg_color']), '#0f2d80') : '#0f2d80');
        $layout = isset($_POST['layout']) ? sanitize_key(wp_unslash($_POST['layout'])) : 'standard';
        $layout = in_array($layout, array('rotate', 'standard', 'skew', 'center_focus', 'spotlight', 'blur_background'), true) ? $layout : 'rotate';
        $movies = class_exists('Content_Rank_TMDB') ? Content_Rank_TMDB::find_movies_for_thumbnail($query, $genre_id, $limit) : array();
        if (empty($movies)) {
            $url = add_query_arg(array('page' => 'content-rank-tmdb-thumbnail-test', 'tmdb_error' => 'Nenhum filme com poster foi encontrado no TMDB.'), admin_url('admin.php'));
            wp_safe_redirect($url);
            exit;
        }
        $attachment_id = Content_Rank_TMDB::create_composite_thumbnail_for_post(0, $query !== '' ? $query : 'tmdb-teste', $movies, $bg_color, $layout);
        if (is_wp_error($attachment_id)) {
            $url = add_query_arg(array('page' => 'content-rank-tmdb-thumbnail-test', 'tmdb_error' => $attachment_id->get_error_message()), admin_url('admin.php'));
            wp_safe_redirect($url);
            exit;
        }
        $encoded_movies = base64_encode(wp_json_encode($movies));
        $url = add_query_arg(array('page' => 'content-rank-tmdb-thumbnail-test', 'attachment_id' => absint($attachment_id), 'movies' => $encoded_movies), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        $settings = Content_Rank_Generator::get_settings();
        $generators = Content_Rank_Generator::get_generators(200);
        $keyword_lists = Content_Rank_Generator::get_keyword_lists(200);
        $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $editing_generator = $edit_id > 0 ? Content_Rank_Generator::get_generator($edit_id) : array();
        $prompt_models = Content_Rank_Generator::get_prompt_models($editing_generator);

        $users = Content_Rank_Generator::get_content_author_users();
        $categories = get_categories(array('hide_empty' => false));
        $log_rows = Content_Rank_Generator::get_recent_runs(30);

        ob_start();

?>
        <style>
        </style>
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
            <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between w-3xl">
                <div class="w-3xl">
                    <div class="text-xs font-semibold text-indigo-600">Content Rank</div>
                    <h1 class="mt-2 text-lg font-semibold tracking-tight text-slate-950">Configurações globais</h1>
                </div>
                <div class="flex flex-wrap items-center gap-3 w-3xl">
                    <button type="button" data-open-generator-import-modal class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-soft transition hover:bg-slate-50" aria-label="Importar gerador" title="Importar gerador">
                        <span class="dashicons dashicons-download text-[18px] leading-none"></span>
                        <span class="sr-only">Importar gerador</span>
                    </button>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mb-0">
                        <?php wp_nonce_field('content_rank_export_generators', 'content_rank_export_generators_nonce'); ?>
                        <input type="hidden" name="action" value="content_rank_export_generators" />
                        <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-soft transition hover:bg-slate-50" aria-label="Exportar geradores" title="Exportar geradores">
                            <span class="dashicons dashicons-upload text-[18px] leading-none"></span>
                            <span class="sr-only">Exportar geradores</span>
                        </button>
                    </form>
                    <button type="button" data-open-generator-modal class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Adicionar gerador</button>
                </div>
            </div>

            <div class="space-y-6">
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">
                    <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-4">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">Tabela de geradores</h2>
                        </div>
                        <div class="text-sm text-slate-500">
                            <?php echo esc_html(count($generators)); ?> gerador(es)
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <th class="px-6 py-3">Nome</th>
                                    <th class="px-6 py-3">Status</th>
                                    <th class="px-6 py-3">Categoria</th>
                                    <th class="px-6 py-3">Agendamento</th>
                                    <th class="px-6 py-3">Próxima execução</th>
                                    <th class="px-6 py-3">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <?php if (empty($generators)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-10 text-center text-sm text-slate-500">Nenhum gerador criado ainda.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($generators as $generator): ?>
                                        <?php
                                        $generator_status_label = Content_Rank_Generator::get_generator_status_label($generator['status']);
                                        $schedule_label = Content_Rank_Generator::get_schedule_type_label($generator['schedule_type']);
                                        $generation_mode_label = Content_Rank_Generator::get_generation_mode_label(isset($generator['generation_mode']) ? $generator['generation_mode'] : Content_Rank_Generator::get_default_generation_mode());
                                        $category_label = '-';
                                        $generator_category_ids = array();
                                        if (isset($generator['category_ids'])) {
                                            $decoded_category_ids = json_decode((string) $generator['category_ids'], true);
                                            $generator_category_ids = is_array($decoded_category_ids) ? array_values(array_filter(array_map('intval', $decoded_category_ids))) : array();
                                        }
                                        if (!empty($generator_category_ids)) {
                                            $category_names = array();
                                            foreach ($generator_category_ids as $category_id) {
                                                $category = get_term($category_id, 'category');
                                                if ($category && !is_wp_error($category)) {
                                                    $category_names[] = $category->name;
                                                }
                                            }
                                            if (!empty($category_names)) {
                                                $category_label = implode(', ', $category_names);
                                            }
                                        }

                                        ?>
                                        <tr class="align-top">
                                            <td class="px-6 py-4">
                                                <div class="font-semibold text-slate-950"><?php echo esc_html($generator['name']); ?></div>
                                                <div class="mt-1 break-all text-sm text-slate-500">
                                                    <?php if (!empty($generator['source_type']) && Content_Rank_Generator::source_type_uses_keyword_list($generator['source_type'])): ?>
                                                        <?php
                                                        $linked_list = null;
                                                        foreach ($keyword_lists as $candidate_list) {
                                                            if (intval($candidate_list['id']) === intval($generator['list_id'])) {
                                                                $linked_list = $candidate_list;
                                                                break;
                                                            }
                                                        }
                                                        ?>
                                                        <?php echo esc_html($linked_list ? $linked_list['list_name'] : 'Lista de palavras-chave'); ?>
                                                    <?php else: ?>
                                                        <?php echo esc_html($generator['feed_url']); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="mt-1 text-xs text-slate-400">Tipo: <?php echo esc_html($generation_mode_label); ?></div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?php echo $generator['status'] === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'; ?>">
                                                    <?php echo esc_html($generator_status_label); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-slate-700"><?php echo esc_html($category_label); ?></td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm font-medium text-slate-700"><?php echo esc_html($schedule_label); ?></div>
                                                <div class="mt-1 text-xs text-slate-500">
                                                    A cada <?php echo esc_html($generator['interval_minutes']); ?> min + variação <?php echo esc_html($generator['jitter_minutes']); ?> min
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-slate-600"><?php echo esc_html($generator['next_run_at'] ?: '-'); ?></td>
                                            <td class="px-6 py-4">
                                                <div class="content-rank-generator-actions flex flex-wrap gap-2">
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                        <?php wp_nonce_field('content_rank_toggle_generator', 'content_rank_toggle_nonce'); ?>
                                                        <input type="hidden" name="action" value="content_rank_toggle_generator" />
                                                        <input type="hidden" name="generator_id" value="<?php echo esc_attr($generator['id']); ?>" />
                                                        <button type="submit" class="content-rank-generator-action-btn inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-700 transition hover:bg-slate-50" aria-label="<?php echo $generator['status'] === 'active' ? 'Pausar' : 'Iniciar'; ?>" title="<?php echo $generator['status'] === 'active' ? 'Pausar' : 'Iniciar'; ?>">
                                                            <span class="dashicons dashicons-<?php echo $generator['status'] === 'active' ? 'controls-pause' : 'controls-play'; ?> text-[17px] leading-none"></span>
                                                            <span class="sr-only"><?php echo $generator['status'] === 'active' ? 'Pausar' : 'Iniciar'; ?></span>
                                                        </button>
                                                    </form>
                                                    <button
                                                        type="button"
                                                        data-edit-generator-id="<?php echo esc_attr($generator['id']); ?>"
                                                        class="content-rank-generator-action-btn inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-700 transition hover:bg-slate-50"
                                                        aria-label="Editar"
                                                        title="Editar">
                                                        <span class="dashicons dashicons-edit text-[17px] leading-none"></span>
                                                        <span class="sr-only">Editar</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        data-open-manual-run-modal
                                                        data-generator-id="<?php echo esc_attr($generator['id']); ?>"
                                                        data-generator-name="<?php echo esc_attr($generator['name']); ?>"
                                                        class="content-rank-generator-action-btn content-rank-generator-action-btn--primary inline-flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600 text-white transition hover:bg-indigo-500"
                                                        aria-label="Escolher item"
                                                        title="Escolher item">
                                                        <span class="dashicons dashicons-list-view text-[17px] leading-none"></span>
                                                        <span class="sr-only">Escolher item</span>
                                                    </button>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                        <?php wp_nonce_field('content_rank_export_generator', 'content_rank_export_generator_nonce'); ?>
                                                        <input type="hidden" name="action" value="content_rank_export_generator" />
                                                        <input type="hidden" name="generator_id" value="<?php echo esc_attr($generator['id']); ?>" />
                                                        <button type="submit" class="content-rank-generator-action-btn inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-700 transition hover:bg-slate-50" aria-label="Exportar" title="Exportar">
                                                            <span class="dashicons dashicons-download text-[17px] leading-none"></span>
                                                            <span class="sr-only">Exportar</span>
                                                        </button>
                                                    </form>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                        <?php wp_nonce_field('content_rank_duplicate_generator', 'content_rank_duplicate_nonce'); ?>
                                                        <input type="hidden" name="action" value="content_rank_duplicate_generator" />
                                                        <input type="hidden" name="generator_id" value="<?php echo esc_attr($generator['id']); ?>" />
                                                        <button type="submit" class="content-rank-generator-action-btn inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-700 transition hover:bg-slate-50" aria-label="Duplicar" title="Duplicar">
                                                            <span class="dashicons dashicons-admin-page text-[17px] leading-none"></span>
                                                            <span class="sr-only">Duplicar</span>
                                                        </button>
                                                    </form>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-swal-confirm="Excluir este gerador?">
                                                        <?php wp_nonce_field('content_rank_delete_generator', 'content_rank_delete_nonce'); ?>
                                                        <input type="hidden" name="action" value="content_rank_delete_generator" />
                                                        <input type="hidden" name="generator_id" value="<?php echo esc_attr($generator['id']); ?>" />
                                                        <button type="submit" class="content-rank-generator-action-btn inline-flex h-10 w-10 items-center justify-center rounded-lg border border-rose-200 bg-rose-50 text-rose-700 transition hover:bg-rose-100" aria-label="Excluir" title="Excluir">
                                                            <span class="dashicons dashicons-trash text-[17px] leading-none"></span>
                                                            <span class="sr-only">Excluir</span>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div id="content-rank-settings-modal" class="fixed inset-0 z-50 hidden">
                <div id="content-rank-settings-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                <div class="relative mx-auto flex min-h-full max-w-3xl items-center px-4 py-8 sm:px-6 lg:px-8">
                    <div class="max-h-[90vh] w-full overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200">
                        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">Configurações globais</h2>
                                <p class="mt-1 text-sm text-slate-500">Ajuste as credenciais e padrões usados por todos os geradores.</p>
                            </div>
                            <button type="button" data-close-settings-modal class="rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="max-h-[calc(90vh-82px)] overflow-y-auto p-6">
                            <?php wp_nonce_field('content_rank_save_settings', 'content_rank_settings_nonce'); ?>
                            <input type="hidden" name="action" value="content_rank_save_settings" />
                            <div class="space-y-4">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Chave da API da OpenAI</label>
                                    <input type="password" name="openai_api_key" value="<?php echo esc_attr($settings['openai_api_key']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Chave da API do Pexels</label>
                                    <input type="password" name="pexels_api_key" value="<?php echo esc_attr($settings['pexels_api_key']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                                    <div class="mb-3">
                                        <h3 class="text-sm font-semibold text-slate-900">TMDB experimental</h3>
                                        <p class="mt-1 text-xs text-slate-600">Credenciais usadas pela futura localizaÃ§Ã£o de tÃ­tulos de filmes.</p>
                                    </div>
                                    <div class="space-y-3">
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Token de leitura da API</label>
                                            <input type="password" name="tmdb_read_access_token" value="<?php echo esc_attr($settings['tmdb_read_access_token']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">API key v3 (opcional)</label>
                                            <input type="password" name="tmdb_api_key" value="<?php echo esc_attr($settings['tmdb_api_key']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Modelo padrão</label>
                                    <input type="text" name="default_model" value="<?php echo esc_attr($settings['default_model']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Temperatura padrão</label>
                                        <input type="number" step="0.1" min="0" max="2" name="default_temperature" value="<?php echo esc_attr($settings['default_temperature']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Máximo de tokens</label>
                                        <input type="number" min="256" name="default_max_tokens" value="<?php echo esc_attr($settings['default_max_tokens']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                    </div>
                                </div>
                            </div>
                            <div class="mt-6 flex flex-col gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-sm text-slate-500">Esses valores viram padrão ao criar ou duplicar geradores.</p>
                                <div class="flex items-center gap-3">
                                    <button type="button" data-close-settings-modal class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Cancelar</button>
                                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">Salvar configurações</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div id="content-rank-runs-modal" class="fixed inset-0 z-50 hidden">
                <div id="content-rank-runs-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                <div class="relative mx-auto flex min-h-full max-w-4xl items-center px-4 py-8 sm:px-6 lg:px-8">
                    <div class="max-h-[90vh] w-full overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200">
                        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">Execuções recentes</h2>
                                <p class="mt-1 text-sm text-slate-500">Histórico das últimas execuções do sistema.</p>
                            </div>
                            <button type="button" data-close-runs-modal class="rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>
                        <div class="max-h-[calc(90vh-82px)] overflow-y-auto p-6">
                            <div class="overflow-hidden rounded-2xl border border-slate-200">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-slate-200">
                                        <thead class="bg-slate-50">
                                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                <th class="px-5 py-3">Horário</th>
                                                <th class="px-5 py-3">Status</th>
                                                <th class="px-5 py-3">Mensagem</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 bg-white">
                                            <?php if (empty($log_rows)): ?>
                                                <tr>
                                                    <td colspan="3" class="px-5 py-8 text-center text-sm text-slate-500">Nenhuma execução registrada ainda.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($log_rows as $row): ?>
                                                    <tr class="align-top">
                                                        <td class="px-5 py-4 text-sm text-slate-600"><?php echo esc_html($row['created_at']); ?></td>
                                                        <td class="px-5 py-4">
                                                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?php echo $row['status'] === 'error' ? 'bg-rose-100 text-rose-700' : (($row['status'] === 'warning' || $row['status'] === 'info') ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'); ?>">
                                                                <?php echo esc_html(Content_Rank_Generator::get_run_status_label($row['status'])); ?>
                                                            </span>
                                                        </td>
                                                        <td class="px-5 py-4 text-sm text-slate-700">
                                                            <div><?php echo esc_html($row['message']); ?></div>
                                                            <?php $run_summary = Content_Rank_Generator::format_run_log_summary($row); ?>
                                                            <?php if ($run_summary !== ''): ?>
                                                                <div class="mt-1 text-xs leading-5 text-slate-500"><?php echo esc_html($run_summary); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="content-rank-manual-run-modal" class="fixed inset-0 z-50 hidden">
                <div id="content-rank-manual-run-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                <div class="relative mx-auto flex min-h-full max-w-5xl items-center px-4 py-8 sm:px-6 lg:px-8">
                    <div class="max-h-[90vh] w-full overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200">
                        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 id="content-rank-manual-run-title" class="text-xl font-semibold text-slate-950">Escolher item</h2>
                                <p id="content-rank-manual-run-subtitle" class="mt-1 text-sm text-slate-500">Escolha um item disponível para gerar um post único.</p>
                            </div>
                            <button type="button" data-close-manual-run-modal class="rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-3">
                            <div class="text-sm text-slate-600">Itens disponíveis: <span id="content-rank-manual-run-count" class="font-semibold text-slate-950">0</span></div>
                            <button type="button" id="content-rank-manual-run-refresh" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Atualizar itens</button>
                        </div>
                        <div class="max-h-[calc(90vh-140px)] overflow-y-auto p-6">
                            <div id="content-rank-manual-run-status" class="hidden mb-4 rounded-xl border px-4 py-3 text-sm"></div>
                            <div id="content-rank-manual-run-loading" class="flex items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-10 text-sm text-slate-500">Carregando itens...</div>
                            <div id="content-rank-manual-run-empty" class="hidden rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-10 text-center text-sm text-slate-500">Nenhum item disponível. Todos os itens já foram processados.</div>
                            <div id="content-rank-manual-run-list" class="space-y-4"></div>
                            <form id="content-rank-manual-run-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hidden">
                                <?php wp_nonce_field('content_rank_run_generator', 'content_rank_run_nonce'); ?>
                                <input type="hidden" name="action" value="content_rank_run_generator" />
                                <input type="hidden" name="generator_id" value="" />
                                <input type="hidden" name="item_guid" value="" />
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div id="content-rank-generator-import-modal" class="fixed inset-0 z-50 hidden">
                <div id="content-rank-generator-import-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                <div class="relative mx-auto flex min-h-full max-w-3xl items-center px-4 py-8 sm:px-6 lg:px-8">
                    <div class="max-h-[90vh] w-full overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200">
                        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">Importar gerador</h2>
                                <p class="mt-1 text-sm text-slate-500">Envie um JSON exportado de um gerador. O arquivo pode conter um item único ou uma lista de itens.</p>
                            </div>
                            <button type="button" data-close-generator-import-modal class="rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="max-h-[calc(90vh-82px)] overflow-y-auto p-6" >
                            <?php wp_nonce_field('content_rank_import_generator', 'content_rank_import_generator_nonce'); ?>
                            <input type="hidden" name="action" value="content_rank_import_generators" />
                            <div class="space-y-4">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Arquivo JSON</label>
                                    <input type="file" name="generator_json_file" accept=".json,application/json" class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-white file:transition hover:file:bg-slate-800" />
                                </div>
                            </div>
                            <div class="mt-6 flex flex-col gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-sm text-slate-500">Envie um arquivo JSON exportado de um gerador. O import reaproveita os mesmos campos do formulário do gerador, incluindo prompts, outline e taxonomias.</p>
                                <div class="flex items-center gap-3">
                                    <button type="button" data-close-generator-import-modal class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Cancelar</button>
                                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Importar JSON</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div id="content-rank-generator-modal" class="fixed inset-0 z-50 hidden">
                <div id="content-rank-generator-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                <div class="relative mx-auto flex min-h-full max-w-7xl items-center px-4 py-8 sm:px-6 lg:px-8">
                    <div class="max-h-[90vh] w-full overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200">
                        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 id="content-rank-generator-modal-title" class="text-xl font-semibold text-slate-950">Adicionar gerador</h2>
                                <p class="mt-1 text-sm text-slate-500">Configure tudo aqui e salve sem sair da tabela.</p>
                            </div>
                            <button type="button" data-close-generator-modal class="rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>
                        <form id="content-rank-generator-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="max-h-[calc(80vh-82px)] overflow-y-auto p-6">
                            <?php wp_nonce_field('content_rank_save_generator', 'content_rank_generator_nonce'); ?>
                            <input type="hidden" name="action" value="content_rank_save_generator" />
                            <input type="hidden" name="generator_id" value="<?php echo esc_attr(isset($editing_generator['id']) ? $editing_generator['id'] : ''); ?>" />

                            <div class="content-rank-generator-fields grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Nome</label>
                                    <input type="text" name="name" required value="<?php echo esc_attr(isset($editing_generator['name']) ? $editing_generator['name'] : ''); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Tipo de geração</label>
                                    <label class="content-rank-switch"><input type="checkbox" name="generation_mode" value="satellite" <?php checked(isset($editing_generator['generation_mode']) && Content_Rank_Generator::normalize_generation_mode((string) $editing_generator['generation_mode']) === 'satellite'); ?> /><span class="content-rank-switch__track" aria-hidden="true"></span><span class="content-rank-switch__state">Satélite</span></label>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Modelo de conteúdo</label>
                                    <select name="prompt_model_key" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="" <?php selected(isset($editing_generator['prompt_model_key']) ? $editing_generator['prompt_model_key'] : '', ''); ?>>Automático</option>
                                        <?php foreach ($prompt_models as $prompt_model): ?>
                                            <?php if (empty($prompt_model['key'])) { continue; } ?>
                                            <option value="<?php echo esc_attr($prompt_model['key']); ?>" <?php selected(isset($editing_generator['prompt_model_key']) ? $editing_generator['prompt_model_key'] : '', $prompt_model['key']); ?>><?php echo esc_html($prompt_model['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="mt-1 text-xs text-slate-500">Automático deixa o planejamento escolher; os demais fixam o modelo deste gerador.</p>
                                </div>
                                <div <?php echo (!empty($editing_generator['generation_mode']) && Content_Rank_Generator::normalize_generation_mode((string) $editing_generator['generation_mode']) === 'satellite') ? 'class="hidden"' : ''; ?>>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Fonte do gerador</label>
                                    <select name="source_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="rss" <?php selected(isset($editing_generator['source_type']) ? $editing_generator['source_type'] : '', 'rss'); ?>>RSS</option>
                                        <option value="spreadsheet" <?php selected(isset($editing_generator['source_type']) ? $editing_generator['source_type'] : '', 'spreadsheet'); ?>>Planilha</option>
                                        <option value="keyword_list" <?php selected(isset($editing_generator['source_type']) ? $editing_generator['source_type'] : '', 'keyword_list'); ?>>Keyword list</option>
                                    </select>
                                </div>
                                <?php
                                $editing_source_type = !empty($editing_generator['source_type']) ? sanitize_key((string) $editing_generator['source_type']) : 'keyword_list';
                                $editing_generation_mode = !empty($editing_generator['generation_mode']) ? Content_Rank_Generator::normalize_generation_mode((string) $editing_generator['generation_mode']) : 'pillar';
                                $editing_is_list_source = Content_Rank_Generator::source_type_uses_keyword_list($editing_source_type);
                                $editing_is_spreadsheet = $editing_source_type === 'spreadsheet';
                                ?>
                                <div data-feed-url-field class="<?php echo ($editing_generation_mode === 'satellite' || $editing_is_list_source) ? 'hidden' : ''; ?>">
                                    <label class="mb-1 block text-sm font-medium text-slate-700">URL do feed / fonte</label>
                                    <input type="url" name="feed_url" value="<?php echo esc_attr(isset($editing_generator['feed_url']) ? $editing_generator['feed_url'] : ''); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div data-list-id-field class="<?php echo ($editing_generation_mode === 'satellite' || !$editing_is_list_source) ? 'hidden' : ''; ?>">
                                    <label data-list-source-label class="mb-1 block text-sm font-medium text-slate-700"><?php echo $editing_is_spreadsheet ? 'Planilha' : 'Keyword list'; ?></label>
                                    <select name="list_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="0" data-list-placeholder <?php selected(isset($editing_generator['list_id']) ? intval($editing_generator['list_id']) : 0, 0); ?>><?php echo $editing_is_spreadsheet ? 'Selecione uma planilha' : 'Selecione uma keyword list'; ?></option>
                                        <?php foreach ($keyword_lists as $keyword_list): ?>
                                            <?php $list_source_kind = (isset($keyword_list['file_type']) && $keyword_list['file_type'] === 'keyword_list') ? 'keyword_list' : 'spreadsheet'; ?>
                                            <option value="<?php echo esc_attr($keyword_list['id']); ?>" data-list-source="<?php echo esc_attr($list_source_kind); ?>" <?php selected(isset($editing_generator['list_id']) ? intval($editing_generator['list_id']) : 0, intval($keyword_list['id'])); ?>><?php echo esc_html($keyword_list['list_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div data-keyword-list-mode-field class="hidden">
                                    <label data-list-mode-label class="mb-1 block text-sm font-medium text-slate-700">Modo da planilha</label>
                                    <select name="keyword_list_mode" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="keywords" <?php selected(isset($editing_generator['keyword_list_mode']) ? $editing_generator['keyword_list_mode'] : '', 'keywords'); ?>>Só palavras-chave</option>
                                        <option value="url_reference" <?php selected(isset($editing_generator['keyword_list_mode']) ? $editing_generator['keyword_list_mode'] : '', 'url_reference'); ?>>Palavra-chave + URL de referência</option>
                                    </select>
                                </div>
                                <div data-tavily-field class="hidden">
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Usar Tavily no planejamento</label>
                                    <select name="tavily_enabled" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="0" <?php selected(isset($editing_generator['tavily_enabled']) ? intval($editing_generator['tavily_enabled']) : 0, 0); ?>>Não</option>
                                        <option value="1" <?php selected(isset($editing_generator['tavily_enabled']) ? intval($editing_generator['tavily_enabled']) : 0, 1); ?>>Sim</option>
                                    </select>
                                    <p class="mt-1 text-xs text-slate-500">Faz uma pesquisa do Tavily para enriquecer o planejamento desta keyword. A integração global também precisa estar ativa.</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Status do gerador</label>
                                    <select name="status" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="active" <?php selected(isset($editing_generator['status']) ? $editing_generator['status'] : '', 'active'); ?>>Ativo</option>
                                        <option value="inactive" <?php selected(isset($editing_generator['status']) ? $editing_generator['status'] : '', 'inactive'); ?>>Inativo</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Status do post</label>
                                    <select name="post_status" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <?php foreach (array('draft', 'publish', 'pending', 'private', 'future') as $status): ?>
                                            <option value="<?php echo esc_attr($status); ?>" <?php selected(isset($editing_generator['post_status']) ? $editing_generator['post_status'] : '', $status); ?>><?php echo esc_html(self::get_post_status_label($status)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Autor</label>
                                    <select name="author_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="0" <?php selected(isset($editing_generator['author_id']) ? intval($editing_generator['author_id']) : 0, 0); ?>>Usuário atual</option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?php echo esc_attr($user->ID); ?>" <?php selected(isset($editing_generator['author_id']) ? intval($editing_generator['author_id']) : 0, intval($user->ID)); ?>><?php echo esc_html($user->display_name . ' (' . $user->user_login . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Posts por execução</label>
                                    <input type="number" min="1" name="posts_per_run" value="<?php echo esc_attr(isset($editing_generator['posts_per_run']) && $editing_generator['posts_per_run'] !== '' ? $editing_generator['posts_per_run'] : 1); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Tipo de agendamento</label>
                                    <select name="schedule_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="interval">Intervalo + variação</option>
                                        <option value="daily_random">Janela diária aleatória</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Minutos de intervalo</label>
                                    <input type="number" min="1" name="interval_minutes" value="180" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Minutos de variação</label>
                                    <input type="number" min="0" name="jitter_minutes" value="30" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Início diário</label>
                                    <input type="text" name="daily_start" value="<?php echo esc_attr(isset($editing_generator['daily_start']) ? $editing_generator['daily_start'] : ''); ?>" placeholder="HH:MM" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Fim diário</label>
                                    <input type="text" name="daily_end" value="<?php echo esc_attr(isset($editing_generator['daily_end']) ? $editing_generator['daily_end'] : ''); ?>" placeholder="HH:MM" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Fonte da imagem</label>
                                    <select name="image_source_mode" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="rss">Fonte do RSS</option>
                                        <option value="rss_or_pexels">Fonte do RSS ou Pexels</option>
                                        <option value="rss_or_dalle">Fonte do RSS ou Dall-e</option>
                                        <option value="pexels">Pexels</option>
                                        <option value="dalle">Dall-e</option>
                                        <option value="tmdb_composite">TMDB - thumbnail composta</option>
                                    </select>
                                </div>
                                <div data-tmdb-thumbnail-field class="hidden">
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Cor da faixa inferior</label>
                                    <div data-tmdb-color-picker><input type="color" name="tmdb_thumbnail_bg_color" value="#c91414" class="h-11 w-20 cursor-pointer rounded-lg border border-slate-300 bg-white p-1" /></div>
                                    <label class="mt-2 block text-sm text-slate-600"><input type="checkbox" name="tmdb_thumbnail_auto_color" value="1" class="mr-2" /> Extrair a cor automaticamente da imagem principal</label>
                                    <label class="mt-3 mb-1 block text-sm font-medium text-slate-700">Estilo da thumbnail</label>
                                    <select name="tmdb_thumbnail_layout" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="rotate">Aleatório</option>
                                        <option value="standard">Painéis uniformes</option>
                                        <option value="skew">Skew para a direita</option>
                                        <option value="center_focus">Destaque central</option>
                                        <option value="spotlight">Destaque no primeiro filme</option>
                                        <option value="blur_background">Fundo desfocado</option>
                                    </select>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function () {
                                        var tmdbAutoColor = document.querySelector('input[name="tmdb_thumbnail_auto_color"]');
                                        var tmdbColorPicker = document.querySelector('[data-tmdb-color-picker]');
                                        if (tmdbAutoColor && tmdbColorPicker) {
                                            var syncTmdbColorPicker = function () {
                                                tmdbColorPicker.style.display = tmdbAutoColor.checked ? 'none' : '';
                                            };
                                            tmdbAutoColor.addEventListener('change', syncTmdbColorPicker);
                                            syncTmdbColorPicker();
                                        }
                                        var tmdbTranslation = document.querySelector('select[name="tmdb_title_translation_enabled"]');
                                        if (!tmdbTranslation) return;
                                        var field = tmdbTranslation.closest('div');
                                        var label = field ? field.querySelector('label') : null;
                                        var description = field ? field.querySelector('p') : null;
                                        if (label) label.textContent = 'Localizar títulos de filmes via TMDB';
                                        var noOption = tmdbTranslation.querySelector('option[value="0"]');
                                        if (noOption) noOption.textContent = 'Não';
                                        if (description) description.textContent = 'Usa os títulos encontrados na estrutura da fonte e substitui os nomes no artigo final.';
                                    });
                                    </script>
                                    <p class="mt-1 text-xs text-slate-500">A faixa ocupa no mÃ¡ximo 30% da thumbnail. A sombra usa a mesma cor.</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Localizar tÃ­tulos de filmes via TMDB</label>
                                    <select name="tmdb_title_translation_enabled" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="0">NÃ£o</option>
                                        <option value="1">Sim (experimental)</option>
                                    </select>
                                    <p class="mt-1 text-xs text-slate-500">Usa os tÃ­tulos encontrados na estrutura da fonte e substitui os nomes no artigo final.</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Consulta no Pexels</label>
                                    <input type="text" name="pexels_query" value="<?php echo esc_attr(Content_Rank_Generator::get_default_pexels_query()); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Usar vídeo da fonte</label>
                                    <select name="source_video_enabled" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="0" selected>Não</option>
                                        <option value="1">Sim</option>
                                    </select>
                                </div>
                                <div class="grid gap-4 md:col-span-2 md:grid-cols-2" data-rss-source-media-toggle-field>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Usar imagens da fonte</label>
                                        <select name="source_content_images_enabled" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                            <option value="1" selected>Sim</option>
                                            <option value="0">Não</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Usar links da fonte</label>
                                        <select name="source_content_links_enabled" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                            <option value="1" selected>Sim</option>
                                            <option value="0">Não</option>
                                        </select>
                                    </div>
                                </div>
                                <div data-rss-video-selector-field>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Classe do wrapper do vídeo</label>
                                    <input type="text" name="video_selector_class" placeholder="slide-key image-holder" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div class="grid gap-4 md:col-span-2 md:grid-cols-2" data-rss-source-selectors-field>
                                    <div data-rss-image-selector-field>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Classe da imagem da fonte</label>
                                        <input type="text" name="image_selector_class" placeholder="responsive-img img-article-square" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                    </div>
                                    <div data-rss-link-selector-field>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Classe do link da fonte</label>
                                        <input type="text" name="link_selector_class" placeholder="affiliate-single" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Seletor do conteúdo da página</label>
                                    <input type="text" name="content_selector" placeholder="article-body, #article-body" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div data-rss-image-size-field>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Tamanho das imagens no conteúdo</label>
                                    <select name="content_image_size" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="thumbnail">Thumbnail</option>
                                        <option value="medium" selected>Médio</option>
                                        <option value="medium_large">Médio grande</option>
                                        <option value="large">Grande</option>
                                        <option value="full">Original</option>
                                    </select>
                                </div>
                                <div data-rss-image-interval-field class="<?php echo !$editing_is_list_source ? 'hidden' : ''; ?>">
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Intervalo de imagens (palavras)</label>
                                    <input type="number" name="content_image_interval_words" min="100" max="5000" step="50" value="500" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                    <p class="mt-1 text-xs text-slate-500">Usado em keyword list e planilhas. Insere uma imagem a cada intervalo.</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Negritos aleatórios no conteúdo</label>
                                    <select name="random_bolds_enabled" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="0" <?php selected(isset($editing_generator['random_bolds_enabled']) ? intval($editing_generator['random_bolds_enabled']) : 0, 0); ?>>Não</option>
                                        <option value="1" <?php selected(isset($editing_generator['random_bolds_enabled']) ? intval($editing_generator['random_bolds_enabled']) : 0, 1); ?>>Sim</option>
                                    </select>
                                </div>
                                <div class="md:col-span-2" data-rss-link-phrases-field>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Frases do link da fonte</label>
                                    <textarea name="source_link_phrases" rows="4" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Assista na plataforma&#10;Veja no catálogo&#10;Confira a fonte"><?php echo esc_textarea(Content_Rank_Generator::get_default_source_link_cta_phrases()); ?></textarea>
                                </div>
                                <div class="md:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4" data-rss-source-filters-field>
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-800">Filtros da fonte</label>
                                        </div>
                                    </div>
                                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                                        <div class="md:col-span-2">
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Frases para excluir</label>
                                            <textarea name="source_context_exclude_phrases" rows="4" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="IMDb - 4.8/10&#10;4.8/10&#10;Watch on Netflix"><?php echo esc_textarea(isset($editing_generator['source_context_exclude_phrases']) ? $editing_generator['source_context_exclude_phrases'] : ''); ?></textarea>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Rótulo da nota</label>
                                            <input type="text" name="source_context_rating_label" value="<?php echo esc_attr(isset($editing_generator['source_context_rating_label']) && $editing_generator['source_context_rating_label'] !== '' ? $editing_generator['source_context_rating_label'] : 'IMDb'); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Nota mínima</label>
                                            <input type="number" step="0.1" min="0" max="10" name="source_context_min_rating" value="<?php echo esc_attr(isset($editing_generator['source_context_min_rating']) && $editing_generator['source_context_min_rating'] !== '' ? $editing_generator['source_context_min_rating'] : '0'); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Manter sem nota</label>
                                            <select name="source_context_keep_unrated" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                <option value="1" <?php selected(isset($editing_generator['source_context_keep_unrated']) ? intval($editing_generator['source_context_keep_unrated']) : 0, 1); ?>>Sim</option>
                                                <option value="0" <?php selected(isset($editing_generator['source_context_keep_unrated']) ? intval($editing_generator['source_context_keep_unrated']) : 0, 0); ?>>Não</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">SEO ativado</label>
                                    <select name="seo_enabled" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="1">Sim</option>
                                        <option value="0">Não</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Linguagem final de geração</label>
                                    <input type="text" name="generation_language" value="<?php echo esc_attr(Content_Rank_Generator::get_default_generation_language()); ?>" placeholder="Português do Brasil" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div class="hidden md:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-800">Sugestões de posts</label>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Ativar sugestões</label>
                                            <select name="related_posts_enabled" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 sm:w-44">
                                                <option value="1">Sim</option>
                                                <option value="0">Não</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Posição</label>
                                            <select name="related_posts_position" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                <option value="end">No final do conteúdo</option>
                                                <option value="paragraphs">A cada X parágrafos</option>
                                                <option value="words">A cada X palavras</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Intervalo</label>
                                            <input type="number" min="1" name="related_posts_interval" value="4" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Mínimo de H2</label>
                                            <input type="number" min="0" name="related_posts_min_h2" value="1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Quantidade por bloco</label>
                                            <input type="number" min="1" name="related_posts_links_per_block" value="2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Apenas mesma categoria</label>
                                            <select name="related_posts_same_category_only" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                <option value="1">Sim</option>
                                                <option value="0">Não</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Permitir fallback</label>
                                            <select name="related_posts_allow_fallback" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                <option value="1">Sim</option>
                                                <option value="0">Não</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Estilo</label>
                                            <select name="related_posts_style" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                <option value="list">Lista</option>
                                                <option value="inline">Inline</option>
                                                <option value="cards">Cards</option>
                                            </select>
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Frases do marcador</label>
                                            <textarea name="related_posts_phrases" rows="4" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Você também pode gostar de:\nLeia também:\nVeja também:"><?php echo esc_textarea(Content_Rank_Generator::get_default_related_posts_phrases()); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="md:col-span-2 grid gap-5 md:grid-cols-2 content-rank-generator-tax-grid essanao">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Categorias do WordPress</label>
                                        <div class="max-h-64 overflow-auto rounded-xl border border-slate-300 bg-white p-3" data-category-checkbox-list>
                                            <?php if (!empty($categories)): ?>
                                                <div class="space-y-2">
                                                    <?php foreach ($categories as $category): ?>
                                                        <label class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-1 text-sm text-slate-700 transition hover:bg-slate-50">
                                                            <input type="checkbox" name="category_ids[]" value="<?php echo esc_attr($category->term_id); ?>" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                                                            <span><?php echo esc_html($category->name); ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-sm text-slate-500">Nenhuma categoria encontrada</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Tags do WordPress</label>
                                        <input type="text" name="tags_default" value="<?php echo esc_attr(isset($editing_generator['tags_default']) ? implode(', ', (array) json_decode((string) $editing_generator['tags_default'], true)) : ''); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="tag 1, tag 2, tag 3" />
                                    </div>
                                </div>
                                <div class="hidden md:col-span-2" data-default-category-field>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Categoria padrão</label>
                                    <select name="default_category_id" class="w-full max-w-sm rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="0">Selecione uma categoria marcada</option>
                                    </select>
                                </div>
                                <div class="md:col-span-2 w-full rounded-2xl essanao border border-slate-200 bg-slate-50 p-4" data-internal-links-field>
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-800">Links internos manuais</label>
                                        </div>
                                        <button type="button" data-add-internal-link class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Adicionar link</button>
                                    </div>
                                    <div class="mt-4 w-full space-y-3" data-internal-links-rows></div>
                                    <input type="hidden" name="internal_links_count" value="<?php echo esc_attr(isset($editing_generator['internal_links_count']) ? intval($editing_generator['internal_links_count']) : 0); ?>" data-internal-links-count />
                                    <textarea name="internal_links_json" class="hidden" data-internal-links-json></textarea>
                                </div>
                                <div class="essanao mt-6 grid w-full gap-4 border-t border-slate-200 pt-5 lg:grid-cols-[1fr_auto] lg:items-center">
                                    <div class="flex w-full items-center gap-3 lg:w-auto lg:justify-end">
                                        <button type="button" data-close-generator-modal class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Cancelar</button>
                                        <button id="content-rank-generator-submit" type="submit" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Salvar gerador</button>
                                    </div>
                                </div>
                        </form>
                    </div>
                </div>
            </div>

            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />

            <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

            <script>
                (function() {
                    var generators = <?php echo wp_json_encode(array_values($generators)); ?>;
                    var defaults = <?php echo wp_json_encode(array(
                                        'generator_id' => '',
                                        'name' => '',
                                        'feed_url' => '',
                                        'source_type' => 'keyword_list',
                                        'generation_mode' => 'pillar',
                                        'prompt_model_key' => '',
                                        'list_id' => '0',
                                        'keyword_list_mode' => 'keywords',
                                        'tavily_enabled' => '0',
                                        'status' => 'active',
                                        'post_type' => 'post',
                                        'post_status' => 'draft',
                                        'author_id' => '0',
                                        'posts_per_run' => '1',
                                        'schedule_type' => 'interval',
                                        'interval_minutes' => '180',
                                        'jitter_minutes' => '30',
                                        'daily_start' => '',
                                        'daily_end' => '',
                                        'image_source_mode' => '',
                                        'tmdb_thumbnail_bg_color' => '#c91414',
                                        'tmdb_thumbnail_layout' => 'rotate',
                                        'tmdb_thumbnail_auto_color' => '0',
                                        'tmdb_title_translation_enabled' => '0',
                                        'pexels_query' => Content_Rank_Generator::get_default_pexels_query(),
                                        'source_video_enabled' => '0',
                                        'source_content_images_enabled' => '1',
                                        'source_content_links_enabled' => '1',
                                        'video_selector_class' => '',
                                        'image_selector_class' => '',
                                        'link_selector_class' => '',
                                        'content_selector' => '',
                                        'content_image_size' => 'medium',
                                        'content_image_interval_words' => '500',
                                        'random_bolds_enabled' => '0',
                                        'source_link_phrases' => Content_Rank_Generator::get_default_source_link_cta_phrases(),
                                        'source_context_exclude_phrases' => '',
                                        'source_context_rating_label' => 'IMDb',
                                        'source_context_min_rating' => '0',
                                        'source_context_keep_unrated' => '0',
                                        'seo_enabled' => '1',
                                        'generation_language' => Content_Rank_Generator::get_default_generation_language(),
                                        'category_ids' => array(),
                                        'default_category_id' => '0',
                                        'tags_default' => array(),
                                        'prompt_template' => Content_Rank_Generator::get_default_prompt_template(),
                                        'content_prompt_template' => Content_Rank_Generator::get_default_content_prompt_template_visible(),
                                        'keyword_prompt_template' => Content_Rank_Generator::get_default_keyword_prompt_template(),
                                        'related_posts_enabled' => '0',
                                        'related_posts_position' => 'end',
                                        'related_posts_interval' => '4',
                                        'related_posts_min_h2' => '1',
                                        'related_posts_links_per_block' => '2',
                                        'related_posts_same_category_only' => '1',
                                        'related_posts_allow_fallback' => '1',
                                        'related_posts_style' => 'list',
                                        'related_posts_phrases' => Content_Rank_Generator::get_default_related_posts_phrases(),
                                        'internal_links_count' => '0',
                                        'internal_links_json' => '[]',
                                    )); ?>;
                    var editId = <?php echo intval($edit_id); ?>;
                    var settingsModal = document.getElementById('content-rank-settings-modal');
                    var settingsBackdrop = document.getElementById('content-rank-settings-backdrop');
                    var runsModal = document.getElementById('content-rank-runs-modal');
                    var runsBackdrop = document.getElementById('content-rank-runs-backdrop');
                    var manualRunModal = document.getElementById('content-rank-manual-run-modal');
                    var manualRunBackdrop = document.getElementById('content-rank-manual-run-backdrop');
                    var manualRunTitle = document.getElementById('content-rank-manual-run-title');
                    var manualRunSubtitle = document.getElementById('content-rank-manual-run-subtitle');
                    var manualRunCount = document.getElementById('content-rank-manual-run-count');
                    var manualRunRefresh = document.getElementById('content-rank-manual-run-refresh');
                    var manualRunStatus = document.getElementById('content-rank-manual-run-status');
                    var manualRunLoading = document.getElementById('content-rank-manual-run-loading');
                    var manualRunEmpty = document.getElementById('content-rank-manual-run-empty');
                    var manualRunList = document.getElementById('content-rank-manual-run-list');
                    var manualRunForm = document.getElementById('content-rank-manual-run-form');
                    var modal = document.getElementById('content-rank-generator-modal');
                    var backdrop = document.getElementById('content-rank-generator-backdrop');
                    var form = document.getElementById('content-rank-generator-form');
                    var titleEl = document.getElementById('content-rank-generator-modal-title');
                    var submitEl = document.getElementById('content-rank-generator-submit');
                    var internalLinksField = form.querySelector('[data-internal-links-field]');
                    var internalLinksRows = form.querySelector('[data-internal-links-rows]');
                    var internalLinksJson = form.querySelector('[data-internal-links-json]');
                    var internalLinksCount = form.querySelector('[data-internal-links-count]');
                    var internalLinksAddButton = form.querySelector('[data-add-internal-link]');
                    var feedUrlField = form.querySelector('[data-feed-url-field]');
                    var listIdField = form.querySelector('[data-list-id-field]');
                    var keywordListModeField = form.querySelector('[data-keyword-list-mode-field]');
                    var tavilyField = form.querySelector('[data-tavily-field]');
                    var tmdbThumbnailField = form.querySelector('[data-tmdb-thumbnail-field]');
                    var postsPerRunField = byName('posts_per_run') ? byName('posts_per_run').parentElement : null;
                    var videoSelectorField = form.querySelector('[data-rss-video-selector-field]');
                    var imageIntervalField = form.querySelector('[data-rss-image-interval-field]');
                    var apiBase = <?php echo wp_json_encode(rest_url('content-rank/v1')); ?>;
                    var restNonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
                    window.ContentRankGenerator = window.ContentRankGenerator || {};
                    window.ContentRankGenerator.generators = generators;
                    window.ContentRankGenerator.defaults = defaults;
                    window.ContentRankGenerator.editId = editId;
                    window.ContentRankGenerator.apiBase = apiBase;
                    window.ContentRankGenerator.restNonce = restNonce;
                    var openModalCount = 0;
                    var manualRunCurrentGeneratorId = '';
                    var manualRunCurrentGeneratorName = '';
                    var manualRunLoadingRequest = null;
                    var manualRunRefreshTimer = null;
                    var manualRunRefreshCooldownSeconds = 12;

                    function hideFieldByName(name) {
                        var el = byName(name);
                        if (el && el.parentElement) {
                            el.parentElement.classList.add('hidden');
                        }
                    }

                    function byName(name) {
                        return form.querySelector('[name="' + name + '"]');
                    }

                    hideFieldByName('pexels_query');
                    hideFieldByName('source_context_rating_label');
                    hideFieldByName('source_context_min_rating');
                    hideFieldByName('source_context_keep_unrated');
                    hideFieldByName('seo_enabled');
                    hideFieldByName('prompt_model_key');
                    hideFieldByName('status');

                    var tmdbTitleField = byName('tmdb_title_translation_enabled');
                    if (tmdbTitleField && tmdbTitleField.parentElement) {
                        var tmdbDescription = tmdbTitleField.parentElement.querySelector('p');
                        if (tmdbDescription) {
                            tmdbDescription.remove();
                        }
                    }

                    function setValue(name, value) {
                        var el = byName(name);
                        if (el) {
                            if (el.type === 'checkbox') {
                                el.checked = name === 'generation_mode' ? String(value) === 'satellite' : (String(value) === '1' || value === true);
                                var stateLabel = el.parentElement ? el.parentElement.querySelector('[data-switch-state]') : null;
                                if (stateLabel) {
                                    stateLabel.textContent = name === 'generation_mode' ? (el.checked ? 'Satélite' : 'Pilar') : (el.checked ? 'Sim' : 'Não');
                                }
                            } else {
                                el.value = value !== undefined && value !== null ? value : '';
                            }
                            if (typeof Event === 'function') {
                                el.dispatchEvent(new Event('change', {
                                    bubbles: true
                                }));
                            } else if (document.createEvent) {
                                var changeEvent = document.createEvent('Event');
                                changeEvent.initEvent('change', true, false);
                                el.dispatchEvent(changeEvent);
                            }
                        }
                    }

                    function promptLooksLikeRss(text) {
                        var value = String(text || '');
                        return value.indexOf('Você é um editor jornalístico especializado em reescrever conteúdo de RSS.') !== -1 ||
                            value.indexOf('Você é um jornalista de portal focado em SEO e no estilo GEO') !== -1 ||
                            value.indexOf('[DIRETRIZES DE ESCRITA E ESTILO (GEO)]') !== -1;
                    }

                    function promptLooksLikeKeyword(text) {
                        return String(text || '').indexOf('Você é um editor de conteúdo especializado em criar artigos originais a partir de planilhas e palavras-chave.') !== -1;
                    }

                    function isKeywordListSourceType(sourceType) {
                        return ['keyword_list', 'spreadsheet'].indexOf(String(sourceType || '')) !== -1;
                    }

                    function isSpreadsheetSourceType(sourceType) {
                        return String(sourceType || '') === 'spreadsheet';
                    }

                    function getDefaultImageSourceModeForType(sourceType, keywordListMode) {
                        if (isKeywordListSourceType(sourceType)) {
                            return String(keywordListMode || 'keywords') === 'url_reference' ? 'rss_or_pexels' : 'pexels';
                        }
                        return 'rss_or_pexels';
                    }

                    function normalizeImageSourceModeForType(sourceType, keywordListMode, value) {
                        var mode = String(value || '').trim();
                        var allowed = ['rss', 'rss_or_pexels', 'rss_or_dalle', 'pexels', 'dalle', 'tmdb_composite'];
                        if (allowed.indexOf(mode) === -1) {
                            return getDefaultImageSourceModeForType(sourceType, keywordListMode);
                        }
                        if (isKeywordListSourceType(sourceType) && String(keywordListMode || 'keywords') !== 'url_reference') {
                            if (mode === 'rss' || mode === 'rss_or_pexels') {
                                return 'pexels';
                            }
                            if (mode === 'rss_or_dalle') {
                                return 'dalle';
                            }
                        }
                        return mode;
                    }

                    function normalizePromptForSourceType(sourceType, keywordListMode, value) {
                        var current = String(value || '').trim();
                        if (!current) {
                            if (isKeywordListSourceType(sourceType)) {
                                return String(keywordListMode || 'keywords') === 'url_reference' ? defaults.prompt_template : defaults.keyword_prompt_template;
                            }
                            return defaults.prompt_template;
                        }
                        if (isKeywordListSourceType(sourceType)) {
                            if (String(keywordListMode || 'keywords') === 'url_reference') {
                                if (current === defaults.keyword_prompt_template) {
                                    return defaults.prompt_template;
                                }
                                return current;
                            }
                            if (current === defaults.prompt_template) {
                                return defaults.keyword_prompt_template;
                            }
                            return current;
                        }
                        if (current === defaults.keyword_prompt_template) {
                            return defaults.prompt_template;
                        }
                        return current;
                    }

                    function setMultiSelect(name, values) {
                        var el = byName(name);
                        if (!el) {
                            return;
                        }
                        var lookup = {};
                        (values || []).forEach(function(value) {
                            lookup[String(value)] = true;
                        });
                        Array.prototype.forEach.call(el.options, function(option) {
                            option.selected = !!lookup[String(option.value)];
                        });
                        if (typeof Event === 'function') {
                            el.dispatchEvent(new Event('change', {
                                bubbles: true
                            }));
                        } else if (document.createEvent) {
                            var changeEvent = document.createEvent('Event');
                            changeEvent.initEvent('change', true, false);
                            el.dispatchEvent(changeEvent);
                        }
                    }

                    function setCheckboxGroup(name, values) {
                        var lookup = {};
                        (values || []).forEach(function(value) {
                            lookup[String(value)] = true;
                        });
                        form.querySelectorAll('input[name="' + name + '"]').forEach(function(input) {
                            input.checked = !!lookup[String(input.value)];
                        });
                    }

                    function getCheckedValues(name) {
                        var values = [];
                        form.querySelectorAll('input[name="' + name + '"]').forEach(function(input) {
                            if (input.checked) {
                                values.push(String(input.value));
                            }
                        });
                        return values;
                    }

                    function listToText(value) {
                        if (Array.isArray(value)) {
                            return value.filter(function(item) {
                                return String(item || '').trim() !== '';
                            }).join(', ');
                        }
                        return String(value || '');
                    }

                    function syncDefaultCategoryField() {
                        var defaultCategoryField = form.querySelector('[data-default-category-field]');
                        var defaultCategoryEl = byName('default_category_id');
                        var selectedCategoryInputs = form.querySelectorAll('input[name="category_ids[]"]:checked');
                        var selectedCategoryValues = [];
                        selectedCategoryInputs.forEach(function(input) {
                            selectedCategoryValues.push(String(input.value));
                        });
                        var showField = selectedCategoryValues.length > 1;

                        if (defaultCategoryField) {
                            defaultCategoryField.classList.toggle('hidden', !showField);
                        }

                        if (!defaultCategoryEl) {
                            return;
                        }

                        defaultCategoryEl.innerHTML = '';
                        if (!showField) {
                            defaultCategoryEl.value = '0';
                            return;
                        }

                        selectedCategoryInputs.forEach(function(input) {
                            var option = document.createElement('option');
                            option.value = String(input.value);
                            option.textContent = input.closest('label') ? input.closest('label').textContent.replace(/\s+/g, ' ').trim() : String(input.value);
                            defaultCategoryEl.appendChild(option);
                        });

                        var currentValue = String(defaultCategoryEl.value || '0');
                        var currentExists = selectedCategoryValues.indexOf(currentValue) !== -1;
                        if (currentValue === '0' || !currentExists) {
                            defaultCategoryEl.value = selectedCategoryValues.length ? selectedCategoryValues[0] : '0';
                        }
                    }

                    function initSelect2Fields() {
                        var $ = window.jQuery;
                        if (!$ || !$.fn || !$.fn.select2) {
                            return;
                        }
                    }

                    function convertBooleanSelectsToSwitches() {
                        var names = ['tavily_enabled', 'tmdb_title_translation_enabled', 'source_video_enabled', 'source_content_images_enabled', 'source_content_links_enabled', 'random_bolds_enabled', 'source_context_keep_unrated', 'seo_enabled', 'related_posts_enabled', 'related_posts_same_category_only', 'related_posts_allow_fallback'];
                        names.forEach(function(name) {
                            var select = form.querySelector('select[name="' + name + '"]');
                            if (!select || select.options.length !== 2) return;
                            var values = Array.prototype.map.call(select.options, function(option) { return String(option.value); });
                            if (values.indexOf('0') === -1 || values.indexOf('1') === -1) return;
                            var label = document.createElement('label');
                            label.className = 'content-rank-switch';
                            var input = document.createElement('input');
                            input.type = 'checkbox'; input.name = name; input.value = '1'; input.checked = String(select.value) === '1';
                            var track = document.createElement('span'); track.className = 'content-rank-switch__track'; track.setAttribute('aria-hidden', 'true');
                            var state = document.createElement('span'); state.className = 'content-rank-switch__state'; state.setAttribute('data-switch-state', ''); state.textContent = input.checked ? 'Sim' : 'Não';
                            label.appendChild(input); label.appendChild(track); label.appendChild(state);
                            input.addEventListener('change', function() { state.textContent = input.checked ? 'Sim' : 'Não'; });
                            select.parentNode.replaceChild(label, select);
                        });
                    }

                    function syncSourceFields() {
                        var generationModeEl = byName('generation_mode');
                        var generationMode = generationModeEl ? (generationModeEl.type === 'checkbox' ? (generationModeEl.checked ? 'satellite' : 'pillar') : generationModeEl.value) : 'pillar';
                        var sourceTypeEl = byName('source_type');
                        var sourceType = sourceTypeEl ? sourceTypeEl.value : 'keyword_list';
                        var keywordListModeEl = byName('keyword_list_mode');
                        var keywordListMode = keywordListModeEl ? keywordListModeEl.value : 'keywords';
                        var imageSourceModeEl = byName('image_source_mode');
                        var isSatelliteMode = generationMode === 'satellite';

                        var listSelect = byName('list_id');
                        var listSourceLabel = listIdField ? listIdField.querySelector('[data-list-source-label]') : null;
                        var listModeLabel = keywordListModeField ? keywordListModeField.querySelector('[data-list-mode-label]') : null;
                        var listPlaceholder = listSelect ? listSelect.querySelector('[data-list-placeholder]') : null;
                        var isSpreadsheetSource = isSpreadsheetSourceType(sourceType);
                        if (listSourceLabel) {
                            listSourceLabel.textContent = isSpreadsheetSource ? 'Planilha' : 'Keyword list';
                        }
                        if (listModeLabel) {
                            listModeLabel.textContent = isSpreadsheetSource ? 'Modo da planilha' : 'Modo da keyword list';
                        }
                        if (listPlaceholder) {
                            listPlaceholder.textContent = isSpreadsheetSource ? 'Selecione uma planilha' : 'Selecione uma keyword list';
                        }
                        if (listSelect) {
                            Array.prototype.forEach.call(listSelect.options, function(option) {
                                if (!option.value || !isKeywordListSourceType(sourceType)) {
                                    option.hidden = false;
                                    option.disabled = false;
                                    return;
                                }
                                var isMatchingSource = String(option.getAttribute('data-list-source') || '') === String(sourceType);
                                option.hidden = !isMatchingSource;
                                option.disabled = !isMatchingSource;
                            });
                            var selectedOption = listSelect.options[listSelect.selectedIndex];
                            if (selectedOption && selectedOption.hidden) {
                                listSelect.value = '0';
                            }
                        }

                        if (sourceTypeEl && sourceTypeEl.parentElement) {
                            sourceTypeEl.parentElement.classList.toggle('hidden', isSatelliteMode);
                        }

                        if (feedUrlField) {
                            feedUrlField.classList.toggle('hidden', isSatelliteMode || isKeywordListSourceType(sourceType));
                        }
                        if (listIdField) {
                            listIdField.classList.toggle('hidden', isSatelliteMode || !isKeywordListSourceType(sourceType));
                        }
                        if (keywordListModeField) {
                            keywordListModeField.classList.toggle('hidden', isSatelliteMode || !isSpreadsheetSource);
                        }
                        if (tavilyField) {
                            tavilyField.classList.toggle('hidden', isSatelliteMode || sourceType !== 'keyword_list');
                        }
                        if (imageIntervalField) {
                            imageIntervalField.classList.toggle('hidden', isSatelliteMode || !isKeywordListSourceType(sourceType));
                        }
                        if (postsPerRunField) {
                            postsPerRunField.classList.toggle('hidden', !isSatelliteMode);
                        }
                        if (videoSelectorField) {
                            var showVideoSelector = !isSatelliteMode && (sourceType === 'rss' || (isSpreadsheetSource && keywordListMode === 'url_reference'));
                            videoSelectorField.classList.toggle('hidden', !showVideoSelector);
                        }
                        if (imageSourceModeEl) {
                            imageSourceModeEl.value = normalizeImageSourceModeForType(sourceType, keywordListMode, imageSourceModeEl.value);
                        }
                        if (tmdbThumbnailField) {
                            tmdbThumbnailField.classList.toggle('hidden', !imageSourceModeEl || imageSourceModeEl.value !== 'tmdb_composite');
                        }

                        var promptEl = byName('prompt_template');
                        if (promptEl && !isSatelliteMode) {
                            promptEl.value = normalizePromptForSourceType(sourceType, keywordListMode, promptEl.value);
                        }
                    }

                    function parseListValue(value) {
                        if (Array.isArray(value)) {
                            return value;
                        }
                        if (typeof value === 'string' && value !== '') {
                            try {
                                var parsed = JSON.parse(value);
                                if (Array.isArray(parsed)) {
                                    return parsed;
                                }
                            } catch (e) {}
                            return value.split(',').map(function(part) {
                                return part.trim();
                            }).filter(Boolean);
                        }
                        return [];
                    }

                    function parseObjectValue(value) {
                        if (value && typeof value === 'object' && !Array.isArray(value)) {
                            return value;
                        }
                        if (typeof value === 'string' && value !== '') {
                            try {
                                var parsed = JSON.parse(value);
                                if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                                    return parsed;
                                }
                            } catch (e) {}
                        }
                        return {};
                    }

                    function parseInternalLinkRules(value) {
                        if (Array.isArray(value)) {
                            return value;
                        }
                        if (typeof value === 'string' && value !== '') {
                            try {
                                var parsed = JSON.parse(value);
                                if (Array.isArray(parsed)) {
                                    return parsed;
                                }
                            } catch (e) {}
                        }
                        return [];
                    }

                    function normalizeInternalLinkRule(rule) {
                        rule = rule || {};

                        function toFlag(value) {
                            if (value === true || value === 1 || value === '1' || value === 'true' || value === 'on') {
                                return '1';
                            }
                            return '0';
                        }
                        return {
                            quantity: Math.max(1, parseInt(rule.quantity, 10) || 1),
                            phrase: String(rule.phrase || rule.word || rule.keyword || rule.anchor_text || '').trim(),
                            url: String(rule.url || rule.link || rule.target_url || '').trim(),
                            target_blank: toFlag(rule.target_blank),
                            nofollow: toFlag(rule.nofollow),
                            sponsored: toFlag(rule.sponsored),
                            ugc: toFlag(rule.ugc)
                        };
                    }

                    function collectInternalLinkRules() {
                        if (!internalLinksRows) {
                            return [];
                        }

                        var rules = [];
                        internalLinksRows.querySelectorAll('[data-internal-link-row]').forEach(function(row) {
                            var quantityEl = row.querySelector('[data-internal-link-quantity]');
                            var phraseEl = row.querySelector('[data-internal-link-phrase]');
                            var urlEl = row.querySelector('[data-internal-link-url]');
                            var targetBlankEl = row.querySelector('[data-internal-link-target-blank]');
                            var nofollowEl = row.querySelector('[data-internal-link-nofollow]');
                            var sponsoredEl = row.querySelector('[data-internal-link-sponsored]');
                            var ugcEl = row.querySelector('[data-internal-link-ugc]');

                            var rule = normalizeInternalLinkRule({
                                quantity: quantityEl ? quantityEl.value : 1,
                                phrase: phraseEl ? phraseEl.value : '',
                                url: urlEl ? urlEl.value : '',
                                target_blank: targetBlankEl && targetBlankEl.checked ? 1 : 0,
                                nofollow: nofollowEl && nofollowEl.checked ? 1 : 0,
                                sponsored: sponsoredEl && sponsoredEl.checked ? 1 : 0,
                                ugc: ugcEl && ugcEl.checked ? 1 : 0
                            });

                            if (!rule.phrase && !rule.url && rule.quantity === 1 && rule.target_blank === '0' && rule.nofollow === '0' && rule.sponsored === '0' && rule.ugc === '0') {
                                return;
                            }

                            rules.push(rule);
                        });

                        return rules;
                    }

                    function calculateInternalLinksCount(rules) {
                        var total = 0;
                        (rules || []).forEach(function(rule) {
                            var normalized = normalizeInternalLinkRule(rule);
                            total += Math.max(1, parseInt(normalized.quantity, 10) || 1);
                        });
                        return total;
                    }

                    function syncInternalLinksField() {
                        if (!internalLinksJson) {
                            return;
                        }
                        var rules = collectInternalLinkRules();
                        internalLinksJson.value = JSON.stringify(rules);
                        if (internalLinksCount) {
                            internalLinksCount.value = String(calculateInternalLinksCount(rules));
                        }
                    }

                    function buildInternalLinkRowMarkup(rule) {
                        rule = normalizeInternalLinkRule(rule);
                        return [
                            '<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-internal-link-row>',
                            '  <div class="grid gap-3 md:grid-cols-12">',
                            '    <div class="md:col-span-2">',
                                '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Quantidade</label>',
                                '      <input type="number" min="1" value="' + escapeHtml(rule.quantity) + '" data-internal-link-quantity class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />',
                            '    </div>',
                            '    <div class="md:col-span-5">',
                            '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Palavra</label>',
                            '      <input type="text" value="' + escapeHtml(rule.phrase) + '" data-internal-link-phrase class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Ex.: Netflix" />',
                            '    </div>',
                            '    <div class="md:col-span-5">',
                            '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Link</label>',
                            '      <input type="url" value="' + escapeHtml(rule.url) + '" data-internal-link-url class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="https://seusite.com/exemplo" />',
                            '    </div>',
                            '    <div class="md:col-span-2">',
                            '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Atributos</label>',
                            '      <div class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">',
                            '        <label class="flex items-center gap-2"><input type="checkbox" data-internal-link-target-blank ' + (rule.target_blank === '1' ? 'checked' : '') + ' /> target blank</label>',
                            '        <label class="flex items-center gap-2"><input type="checkbox" data-internal-link-nofollow ' + (rule.nofollow === '1' ? 'checked' : '') + ' /> nofollow</label>',
                            '        <label class="flex items-center gap-2"><input type="checkbox" data-internal-link-sponsored ' + (rule.sponsored === '1' ? 'checked' : '') + ' /> sponsored</label>',
                            '        <label class="flex items-center gap-2"><input type="checkbox" data-internal-link-ugc ' + (rule.ugc === '1' ? 'checked' : '') + ' /> ugc</label>',
                            '      </div>',
                            '    </div>',
                            '  </div>',
                            '  <div class="mt-3 flex justify-end">',
                            '    <button type="button" data-remove-internal-link class="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100">Remover</button>',
                            '  </div>',
                            '</div>'
                        ].join('');
                    }

                    function renderInternalLinkRows(rules) {
                        if (!internalLinksRows) {
                            return;
                        }

                        var normalizedRules = [];
                        if (Array.isArray(rules)) {
                            normalizedRules = rules.map(function(rule) {
                                return normalizeInternalLinkRule(rule);
                            });
                        }
                        if (!normalizedRules.length) {
                            normalizedRules = [normalizeInternalLinkRule({})];
                        }

                        internalLinksRows.innerHTML = normalizedRules.map(function(rule) {
                            return buildInternalLinkRowMarkup(rule);
                        }).join('');
                        syncInternalLinksField();
                    }

                    function objectToLines(objectValue) {
                        var lines = [];
                        Object.keys(objectValue || {}).forEach(function(key) {
                            var value = objectValue[key];
                            if (Array.isArray(value)) {
                                value = value.join(',');
                            }
                            lines.push(key + '=' + value);
                        });
                        return lines.join('\n');
                    }

                    function escapeHtml(value) {
                        return String(value === undefined || value === null ? '' : value)
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;')
                            .replace(/'/g, '&#039;');
                    }

                    function parseJsonPayload(text) {
                        var value = text === undefined || text === null ? '' : String(text);
                        value = value.replace(/^\uFEFF/, '').trim();
                        if (!value) {
                            return null;
                        }
                        try {
                            return JSON.parse(value);
                        } catch (error) {
                            var jsonStart = value.search(/[\{\[]/);
                            if (jsonStart > 0) {
                                try {
                                    return JSON.parse(value.slice(jsonStart));
                                } catch (fallbackError) {}
                            }
                            return {
                                success: false,
                                message: value || 'Resposta invalida'
                            };
                        }
                    }

                    function api(path, options) {
                        var fetchOptions = options || {};
                        fetchOptions.credentials = 'same-origin';
                        fetchOptions.headers = fetchOptions.headers || {};
                        fetchOptions.headers['X-WP-Nonce'] = restNonce;
                        return fetch(apiBase + path, fetchOptions).then(function(response) {
                            return response.text().then(function(text) {
                                var payload = parseJsonPayload(text);
                                return {
                                    ok: response.ok,
                                    status: response.status,
                                    payload: payload
                                };
                            });
                        });
                    }

                    function setManualRunStatus(message, type) {
                        if (!manualRunStatus) {
                            return;
                        }
                        if (!message) {
                            manualRunStatus.className = 'hidden mb-4 rounded-xl border px-4 py-3 text-sm';
                            manualRunStatus.textContent = '';
                            return;
                        }
                        var classes = 'mb-4 rounded-xl border px-4 py-3 text-sm';
                        if (type === 'error') {
                            classes += ' border-rose-200 bg-rose-50 text-rose-700';
                        } else if (type === 'success') {
                            classes += ' border-emerald-200 bg-emerald-50 text-emerald-700';
                        } else {
                            classes += ' border-slate-200 bg-slate-50 text-slate-600';
                        }
                        manualRunStatus.className = classes;
                        manualRunStatus.textContent = message;
                    }

                    function setManualRunLoading(isLoading) {
                        if (manualRunLoading) {
                            manualRunLoading.classList.toggle('hidden', !isLoading);
                        }
                        if (manualRunList) {
                            manualRunList.classList.toggle('hidden', isLoading);
                        }
                        if (manualRunEmpty && !isLoading) {
                            manualRunEmpty.classList.add('hidden');
                        }
                    }

                    function clearManualRunRefreshCooldown() {
                        if (manualRunRefreshTimer) {
                            clearTimeout(manualRunRefreshTimer);
                            manualRunRefreshTimer = null;
                        }
                        if (manualRunRefresh) {
                            manualRunRefresh.disabled = false;
                            manualRunRefresh.textContent = 'Atualizar itens';
                        }
                    }

                    function startManualRunRefreshCooldown(generatorId) {
                        if (!generatorId) {
                            return;
                        }
                        clearManualRunRefreshCooldown();
                        var seconds = Math.max(1, parseInt(manualRunRefreshCooldownSeconds, 10) || 12);
                        if (manualRunRefresh) {
                            manualRunRefresh.disabled = true;
                            manualRunRefresh.textContent = 'Aguarde ' + seconds + 's';
                        }
                        setManualRunStatus('Aguarde ' + seconds + ' segundos para nao ser bloqueado como bot.', 'warning');
                        manualRunRefreshTimer = window.setTimeout(function() {
                            manualRunRefreshTimer = null;
                            if (manualRunRefresh) {
                                manualRunRefresh.disabled = false;
                                manualRunRefresh.textContent = 'Atualizar itens';
                            }
                            if (manualRunCurrentGeneratorId) {
                                loadManualRunItems(manualRunCurrentGeneratorId);
                            }
                        }, seconds * 1000);
                    }

                    function setManualRunItems(items) {
                        if (!manualRunList) {
                            return;
                        }

                        manualRunList.innerHTML = '';
                        if (manualRunEmpty) {
                            manualRunEmpty.classList.add('hidden');
                        }
                        if (manualRunCount) {
                            manualRunCount.textContent = String(items.length);
                        }

                        if (!items.length) {
                            if (manualRunEmpty) {
                                manualRunEmpty.classList.remove('hidden');
                            }
                            return;
                        }

                        items.forEach(function(item) {
                            var excerpt = item.excerpt ? escapeHtml(item.excerpt) : '';
                            var permalink = item.permalink ? escapeHtml(item.permalink) : '';
                            var date = item.date_label ? escapeHtml(item.date_label) : (item.date ? escapeHtml(item.date) : '');
                            var card = document.createElement('article');
                            card.className = 'rounded-2xl border border-slate-200 bg-slate-50 p-5 shadow-sm';
                            card.innerHTML = [
                                '<div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">',
                                '  <div class="min-w-0 flex-1">',
                                '    <div class="flex flex-wrap items-center gap-2">',
                                '      <h3 class="text-base font-semibold text-slate-950">' + escapeHtml(item.title || '(Sem título)') + '</h3>',
                                '      ' + (date ? '<span class="rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600 ring-1 ring-slate-200">' + date + '</span>' : ''),
                                '    </div>',
                                excerpt ? '    <p class="mt-2 text-sm leading-6 text-slate-600">' + excerpt + '</p>' : '',
                                permalink ? '    <a href="' + permalink + '" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex break-all text-sm text-indigo-600 hover:text-indigo-500">' + permalink + '</a>' : '',
                                '  </div>',
                                '  <div class="flex-shrink-0">',
                                '    <button type="button" data-run-item-guid="' + escapeHtml(item.guid || '') + '" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500">Gerar este item</button>',
                                '  </div>',
                                '</div>'
                            ].join('');
                            manualRunList.appendChild(card);
                        });
                    }

                    function submitManualRunItem(itemGuid) {
                        if (!manualRunForm) {
                            return;
                        }
                        if (window.ContentRankGeneratorManualRunInFlight) {
                            return;
                        }
                        var generatorIdField = manualRunForm.querySelector('[name="generator_id"]');
                        var itemGuidField = manualRunForm.querySelector('[name="item_guid"]');
                        if (generatorIdField) {
                            generatorIdField.value = manualRunCurrentGeneratorId || '';
                        }
                        if (itemGuidField) {
                            itemGuidField.value = itemGuid || '';
                        }
                        if (window.ContentRankGenerationToast && typeof window.ContentRankGenerationToast.start === 'function') {
                            window.ContentRankGenerationToast.start([], 'Gerando item selecionado...');
                        }
                        window.ContentRankGeneratorManualRunInFlight = true;
                        manualRunForm.submit();
                    }

                    function loadManualRunItems(generatorId) {
                        if (!generatorId) {
                            return;
                        }
                        manualRunCurrentGeneratorId = String(generatorId);
                        setManualRunStatus('', '');
                        if (manualRunTitle) {
                            manualRunTitle.textContent = 'Escolher item';
                        }
                        if (manualRunSubtitle) {
                            manualRunSubtitle.textContent = 'Escolha um item disponível para gerar um post único.';
                        }
                        setManualRunLoading(true);

                        if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
                            manualRunLoadingRequest.abort();
                        }
                        manualRunLoadingRequest = typeof AbortController !== 'undefined' ? new AbortController() : null;

                        api('/generators/' + encodeURIComponent(generatorId) + '/items?limit=30', {
                            method: 'GET',
                            signal: manualRunLoadingRequest ? manualRunLoadingRequest.signal : undefined
                        }).then(function(result) {
                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Não foi possível carregar os itens do feed.');
                            }
                            var payload = result.payload;
                            manualRunCurrentGeneratorName = payload.generator && payload.generator.name ? String(payload.generator.name) : '';
                            if (manualRunTitle) {
                                manualRunTitle.textContent = manualRunCurrentGeneratorName ? ('Escolher item: ' + manualRunCurrentGeneratorName) : 'Escolher item';
                            }
                            if (manualRunSubtitle) {
                                manualRunSubtitle.textContent = 'Escolha um item disponível para gerar um post único.';
                            }
                            setManualRunItems(payload.items || []);
                            if (!payload.items || !payload.items.length) {
                                if (manualRunEmpty) {
                                    manualRunEmpty.classList.remove('hidden');
                                    var listCounts = payload.list_counts || {};
                                    var pendingCount = parseInt(listCounts.pending_rows || 0, 10) || 0;
                                    var processingCount = parseInt(listCounts.processing_rows || 0, 10) || 0;
                                    var failedCount = parseInt(listCounts.failed_rows || 0, 10) || 0;
                                    if (pendingCount > 0) {
                                        manualRunEmpty.textContent = 'Existem ' + pendingCount + ' item(ns) pendente(s), mas nenhum passou pelos filtros atuais.';
                                    } else if (processingCount > 0 || failedCount > 0) {
                                        manualRunEmpty.textContent = 'Nao ha itens pendentes. ' + processingCount + ' em processamento e ' + failedCount + ' com falha.';
                                    } else {
                                        manualRunEmpty.textContent = 'Nenhum item pendente. Todos os itens elegiveis ja foram processados.';
                                    }
                                }
                            }
                            if (manualRunCount) {
                                manualRunCount.textContent = String((payload.items || []).length);
                            }
                            setManualRunStatus('', '');
                        }).catch(function(error) {
                            if (error && error.name === 'AbortError') {
                                return;
                            }
                            if (manualRunList) {
                                manualRunList.innerHTML = '';
                            }
                            if (manualRunEmpty) {
                                manualRunEmpty.classList.add('hidden');
                            }
                            setManualRunStatus(error.message || 'Falha ao carregar os itens do feed.', 'error');
                        }).finally(function() {
                            setManualRunLoading(false);
                        });
                    }

                    function applyDefaults() {
                        setValue('generator_id', defaults.generator_id);
                        setValue('name', defaults.name);
                        setValue('feed_url', defaults.feed_url);
                        setValue('generation_mode', defaults.generation_mode);
                        setValue('prompt_model_key', defaults.prompt_model_key);
                        setValue('source_type', defaults.source_type);
                        setValue('list_id', defaults.list_id);
                        setValue('keyword_list_mode', defaults.keyword_list_mode);
                        setValue('tavily_enabled', defaults.tavily_enabled);
                        setValue('status', defaults.status);
                        setValue('post_type', defaults.post_type);
                        setValue('post_status', defaults.post_status);
                        setValue('author_id', defaults.author_id);
                        setValue('posts_per_run', defaults.posts_per_run);
                        setValue('schedule_type', defaults.schedule_type);
                        setValue('interval_minutes', defaults.interval_minutes);
                        setValue('jitter_minutes', defaults.jitter_minutes);
                        setValue('daily_start', defaults.daily_start);
                        setValue('daily_end', defaults.daily_end);
                        setValue('image_source_mode', normalizeImageSourceModeForType(defaults.source_type, defaults.keyword_list_mode, defaults.image_source_mode || getDefaultImageSourceModeForType(defaults.source_type, defaults.keyword_list_mode)));
                        setValue('tmdb_thumbnail_bg_color', defaults.tmdb_thumbnail_bg_color);
                        setValue('tmdb_thumbnail_layout', defaults.tmdb_thumbnail_layout);
                        setValue('tmdb_thumbnail_auto_color', defaults.tmdb_thumbnail_auto_color);
                        setValue('tmdb_title_translation_enabled', defaults.tmdb_title_translation_enabled);
                        setValue('pexels_query', defaults.pexels_query);
                        setValue('source_video_enabled', defaults.source_video_enabled);
                        setValue('video_selector_class', defaults.video_selector_class);
                        setValue('content_selector', defaults.content_selector);
                        setValue('content_image_size', defaults.content_image_size);
                        setValue('content_image_interval_words', defaults.content_image_interval_words || '500');
                        setValue('random_bolds_enabled', defaults.random_bolds_enabled);
                        setValue('source_link_phrases', defaults.source_link_phrases);
                        setValue('seo_enabled', defaults.seo_enabled);
                        setValue('generation_language', defaults.generation_language);
                        setValue('related_posts_enabled', defaults.related_posts_enabled);
                        setValue('related_posts_position', defaults.related_posts_position);
                        setValue('related_posts_interval', defaults.related_posts_interval);
                        setValue('related_posts_min_h2', defaults.related_posts_min_h2);
                        setValue('related_posts_links_per_block', defaults.related_posts_links_per_block);
                        setValue('related_posts_same_category_only', defaults.related_posts_same_category_only);
                        setValue('related_posts_allow_fallback', defaults.related_posts_allow_fallback);
                        setValue('related_posts_style', defaults.related_posts_style);
                        setValue('related_posts_phrases', defaults.related_posts_phrases);
                        setValue('internal_links_json', defaults.internal_links_json);
                        setValue('default_category_id', defaults.default_category_id);
                        setCheckboxGroup('category_ids[]', []);
                        setValue('tags_default', listToText(defaults.tags_default));
                        syncDefaultCategoryField();
                        renderInternalLinkRows(parseInternalLinkRules(defaults.internal_links_json));
                        syncSourceFields();
                        if (titleEl) {
                            titleEl.textContent = 'Adicionar gerador';
                        }
                        if (submitEl) {
                            submitEl.textContent = 'Salvar gerador';
                        }
                    }

                    function fillForm(generator) {
                        applyDefaults();
                        if (!generator) {
                            return;
                        }

                        setValue('generator_id', generator.id);
                        setValue('name', generator.name);
                        setValue('feed_url', generator.feed_url);
                        setValue('generation_mode', generator.generation_mode || defaults.generation_mode);
                        setValue('prompt_model_key', typeof generator.prompt_model_key !== 'undefined' ? generator.prompt_model_key : defaults.prompt_model_key);
                        setValue('source_type', generator.source_type || defaults.source_type);
                        setValue('list_id', typeof generator.list_id !== 'undefined' ? String(generator.list_id) : defaults.list_id);
                        setValue('keyword_list_mode', generator.keyword_list_mode || defaults.keyword_list_mode);
                        setValue('tavily_enabled', typeof generator.tavily_enabled !== 'undefined' ? generator.tavily_enabled : defaults.tavily_enabled);
                        setValue('status', generator.status);
                        setValue('post_type', generator.post_type);
                        setValue('post_status', generator.post_status);
                        setValue('author_id', generator.author_id);
                        setValue('posts_per_run', generator.posts_per_run);
                        setValue('schedule_type', generator.schedule_type);
                        setValue('interval_minutes', generator.interval_minutes);
                        setValue('jitter_minutes', generator.jitter_minutes);
                        setValue('daily_start', generator.daily_start);
                        setValue('daily_end', generator.daily_end);
                        setValue('image_source_mode', normalizeImageSourceModeForType(generator.source_type || defaults.source_type, generator.keyword_list_mode || defaults.keyword_list_mode, generator.image_source_mode || (typeof generator.pexels_enabled !== 'undefined' ? (String(generator.pexels_enabled) === '1' ? 'rss_or_pexels' : 'rss') : defaults.image_source_mode)));
                        setValue('tmdb_thumbnail_bg_color', generator.tmdb_thumbnail_bg_color || defaults.tmdb_thumbnail_bg_color);
                        setValue('tmdb_thumbnail_layout', generator.tmdb_thumbnail_layout || defaults.tmdb_thumbnail_layout);
                        setValue('tmdb_thumbnail_auto_color', String(typeof generator.tmdb_thumbnail_auto_color !== 'undefined' ? generator.tmdb_thumbnail_auto_color : defaults.tmdb_thumbnail_auto_color));
                        setValue('tmdb_title_translation_enabled', typeof generator.tmdb_title_translation_enabled !== 'undefined' ? String(generator.tmdb_title_translation_enabled) : defaults.tmdb_title_translation_enabled);
                        setValue('pexels_query', generator.pexels_query || defaults.pexels_query);
                        setValue('source_video_enabled', String(typeof generator.source_video_enabled !== 'undefined' ? generator.source_video_enabled : defaults.source_video_enabled));
                        setValue('video_selector_class', generator.video_selector_class || defaults.video_selector_class);
                        setValue('content_selector', generator.content_selector || defaults.content_selector);
                        setValue('content_image_size', generator.content_image_size || defaults.content_image_size);
                        setValue('content_image_interval_words', generator.content_image_interval_words || defaults.content_image_interval_words || '500');
                        setValue('random_bolds_enabled', String(typeof generator.random_bolds_enabled !== 'undefined' ? generator.random_bolds_enabled : defaults.random_bolds_enabled));
                        setValue('source_link_phrases', generator.source_link_phrases || defaults.source_link_phrases);
                        setValue('seo_enabled', String(typeof generator.seo_enabled !== 'undefined' ? generator.seo_enabled : defaults.seo_enabled));
                        setValue('generation_language', generator.generation_language || defaults.generation_language);
                        setValue('related_posts_enabled', String(typeof generator.related_posts_enabled !== 'undefined' ? generator.related_posts_enabled : defaults.related_posts_enabled));
                        setValue('related_posts_position', generator.related_posts_position || defaults.related_posts_position);
                        setValue('related_posts_interval', typeof generator.related_posts_interval !== 'undefined' ? generator.related_posts_interval : defaults.related_posts_interval);
                        setValue('related_posts_min_h2', typeof generator.related_posts_min_h2 !== 'undefined' ? generator.related_posts_min_h2 : defaults.related_posts_min_h2);
                        setValue('related_posts_links_per_block', typeof generator.related_posts_links_per_block !== 'undefined' ? generator.related_posts_links_per_block : defaults.related_posts_links_per_block);
                        setValue('related_posts_same_category_only', String(typeof generator.related_posts_same_category_only !== 'undefined' ? generator.related_posts_same_category_only : defaults.related_posts_same_category_only));
                        setValue('related_posts_allow_fallback', String(typeof generator.related_posts_allow_fallback !== 'undefined' ? generator.related_posts_allow_fallback : defaults.related_posts_allow_fallback));
                        setValue('related_posts_style', generator.related_posts_style || defaults.related_posts_style);
                        setValue('related_posts_phrases', generator.related_posts_phrases || defaults.related_posts_phrases);
                        setValue('internal_links_json', generator.internal_links_json || defaults.internal_links_json);
                        setCheckboxGroup('category_ids[]', parseListValue(generator.category_ids));
                        setValue('default_category_id', typeof generator.default_category_id !== 'undefined' ? String(generator.default_category_id) : defaults.default_category_id);
                        setValue('tags_default', listToText(parseListValue(generator.tags_default)));
                        syncDefaultCategoryField();
                        renderInternalLinkRows(parseInternalLinkRules(generator.internal_links_json || defaults.internal_links_json));
                        syncSourceFields();

                        if (titleEl) {
                            titleEl.textContent = 'Editar gerador';
                        }
                        if (submitEl) {
                            submitEl.textContent = 'Atualizar gerador';
                        }
                    }

                    var sourceTypeEl = byName('source_type');
                    if (sourceTypeEl) {
                        sourceTypeEl.addEventListener('change', syncSourceFields);
                    }
                    var generationModeEl = byName('generation_mode');
                    if (generationModeEl) {
                        generationModeEl.addEventListener('change', syncSourceFields);
                    }
                    var keywordListModeEl = byName('keyword_list_mode');
                    if (keywordListModeEl) {
                        keywordListModeEl.addEventListener('change', syncSourceFields);
                    }
                    form.querySelectorAll('input[name="category_ids[]"]').forEach(function(input) {
                        input.addEventListener('change', syncDefaultCategoryField);
                    });
                    var defaultCategoryEl = byName('default_category_id');
                    if (defaultCategoryEl) {
                        defaultCategoryEl.addEventListener('change', syncDefaultCategoryField);
                    }

                    if (internalLinksRows) {
                        internalLinksRows.addEventListener('input', syncInternalLinksField);
                        internalLinksRows.addEventListener('change', syncInternalLinksField);
                        internalLinksRows.addEventListener('click', function(event) {
                            var button = event.target && event.target.closest ? event.target.closest('[data-remove-internal-link]') : null;
                            if (!button) {
                                return;
                            }
                            var row = button.closest('[data-internal-link-row]');
                            if (row) {
                                row.remove();
                                syncInternalLinksField();
                            }
                        });
                    }

                    if (internalLinksAddButton) {
                        internalLinksAddButton.addEventListener('click', function() {
                            var currentRules = collectInternalLinkRules();
                            if (!currentRules.length && internalLinksRows) {
                                var rowCount = internalLinksRows.querySelectorAll('[data-internal-link-row]').length;
                                for (var i = 0; i < rowCount; i++) {
                                    currentRules.push(normalizeInternalLinkRule({}));
                                }
                            }
                            currentRules.push(normalizeInternalLinkRule({}));
                            renderInternalLinkRows(currentRules);
                        });
                    }

                    if (form) {
                        form.addEventListener('submit', function() {
                            syncInternalLinksField();
                        });
                    }

                    convertBooleanSelectsToSwitches();
                    initSelect2Fields();

                    function syncBodyLock() {
                        document.body.classList.toggle('overflow-hidden', openModalCount > 0);
                    }

                    function openModal(targetModal) {
                        if (!targetModal || !targetModal.classList.contains('hidden')) {
                            return;
                        }
                        targetModal.classList.remove('hidden');
                        openModalCount++;
                        syncBodyLock();
                    }

                    function closeModal(targetModal) {
                        if (!targetModal || targetModal.classList.contains('hidden')) {
                            return;
                        }
                        targetModal.classList.add('hidden');
                        openModalCount = Math.max(0, openModalCount - 1);
                        syncBodyLock();
                    }

                    function resetGeneratorForm() {
                        form.reset();
                        applyDefaults();
                    }

                    document.querySelectorAll('[data-open-settings-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            openModal(settingsModal);
                        });
                    });

                    document.querySelectorAll('[data-open-runs-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            openModal(runsModal);
                        });
                    });

                    document.querySelectorAll('[data-open-manual-run-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            var generatorId = String(button.getAttribute('data-generator-id') || '');
                            var generatorName = String(button.getAttribute('data-generator-name') || '');
                            manualRunCurrentGeneratorId = generatorId;
                            manualRunCurrentGeneratorName = generatorName;
                            if (manualRunSubtitle) {
                                manualRunSubtitle.textContent = generatorName ? ('Carregando itens do gerador "' + generatorName + '"...') : 'Carregando itens disponíveis...';
                            }
                            if (manualRunTitle) {
                                manualRunTitle.textContent = 'Escolher item';
                            }
                            setManualRunStatus('', '');
                            setManualRunLoading(true);
                            if (manualRunList) {
                                manualRunList.innerHTML = '';
                            }
                            if (manualRunEmpty) {
                                manualRunEmpty.classList.add('hidden');
                            }
                            openModal(manualRunModal);
                            loadManualRunItems(generatorId);
                        });
                    });

                    document.querySelectorAll('[data-open-generator-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            fillForm(null);
                            openModal(modal);
                        });
                    });

                    document.querySelectorAll('[data-edit-generator-id]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            if (button.tagName && button.tagName.toLowerCase() === 'a' && button.getAttribute('href')) {
                                window.location.href = button.getAttribute('href');
                                return;
                            }
                            var id = String(button.getAttribute('data-edit-generator-id') || '');
                            var generator = generators.find(function(item) {
                                return String(item.id) === id;
                            });
                            fillForm(generator || null);
                            openModal(modal);
                        });
                    });

                    document.querySelectorAll('[data-close-generator-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            closeModal(modal);
                            resetGeneratorForm();
                        });
                    });

                    document.querySelectorAll('[data-close-settings-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            closeModal(settingsModal);
                        });
                    });

                    document.querySelectorAll('[data-close-runs-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            closeModal(runsModal);
                        });
                    });

                    document.querySelectorAll('[data-close-manual-run-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            closeModal(manualRunModal);
                            clearManualRunRefreshCooldown();
                            setManualRunStatus('', '');
                            if (manualRunList) {
                                manualRunList.innerHTML = '';
                            }
                            if (manualRunEmpty) {
                                manualRunEmpty.classList.add('hidden');
                            }
                            if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
                                manualRunLoadingRequest.abort();
                            }
                        });
                    });

                    if (backdrop) {
                        backdrop.addEventListener('click', function() {
                            closeModal(modal);
                            resetGeneratorForm();
                        });
                    }

                    if (settingsBackdrop) {
                        settingsBackdrop.addEventListener('click', function() {
                            closeModal(settingsModal);
                        });
                    }

                    if (runsBackdrop) {
                        runsBackdrop.addEventListener('click', function() {
                            closeModal(runsModal);
                        });
                    }

                    if (manualRunBackdrop) {
                        manualRunBackdrop.addEventListener('click', function() {
                            closeModal(manualRunModal);
                            clearManualRunRefreshCooldown();
                            setManualRunStatus('', '');
                            if (manualRunList) {
                                manualRunList.innerHTML = '';
                            }
                            if (manualRunEmpty) {
                                manualRunEmpty.classList.add('hidden');
                            }
                            if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
                                manualRunLoadingRequest.abort();
                            }
                        });
                    }

                    if (manualRunRefresh) {
                        manualRunRefresh.addEventListener('click', function() {
                            if (manualRunCurrentGeneratorId) {
                                startManualRunRefreshCooldown(manualRunCurrentGeneratorId);
                            }
                        });
                    }

                    if (manualRunList) {
                        manualRunList.addEventListener('click', function(event) {
                            var button = event.target && event.target.closest ? event.target.closest('[data-run-item-guid]') : null;
                            if (!button) {
                                return;
                            }
                            var itemGuid = String(button.getAttribute('data-run-item-guid') || '');
                            if (itemGuid !== '') {
                                submitManualRunItem(itemGuid);
                            }
                        });
                    }

                    document.addEventListener('keydown', function(event) {
                        if (event.key === 'Escape') {
                            if (modal && !modal.classList.contains('hidden')) {
                                closeModal(modal);
                                resetGeneratorForm();
                            }
                            if (settingsModal && !settingsModal.classList.contains('hidden')) {
                                closeModal(settingsModal);
                            }
                            if (runsModal && !runsModal.classList.contains('hidden')) {
                                closeModal(runsModal);
                            }
                            if (manualRunModal && !manualRunModal.classList.contains('hidden')) {
                                closeModal(manualRunModal);
                                clearManualRunRefreshCooldown();
                                setManualRunStatus('', '');
                                if (manualRunList) {
                                    manualRunList.innerHTML = '';
                                }
                                if (manualRunEmpty) {
                                    manualRunEmpty.classList.add('hidden');
                                }
                                if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
                                    manualRunLoadingRequest.abort();
                                }
                            }
                        }
                    });

                    if (editId > 0) {
                        var initialGenerator = generators.find(function(item) {
                            return String(item.id) === String(editId);
                        });
                        if (initialGenerator) {
                            fillForm(initialGenerator);
                            openModal(modal);
                        }
                    } else {
                        applyDefaults();
                    }
                })();
            </script>
        </div>
    <?php

        echo ob_get_clean();
    }

    public function render_global_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        $settings = Content_Rank_Generator::get_settings();
        $blacklist_entries = class_exists('Content_Rank_Global_Filters') ? Content_Rank_Global_Filters::get_entries() : array();

        ob_start();
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
        <div class="wrap content-rank-wrap min-h-screen bg-slate-100 text-slate-900 flex flex-col items-stretch">
            <h1 class="screen-reader-text">Content Rank</h1>
            <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="text-xs font-semibold text-indigo-600">Content Rank</div>
                    <h1 class="mt-2 text-lg font-semibold tracking-tight text-slate-950">Configurações globais</h1>
                    <p class="mt-2 max-w-3xl text-sm text-slate-600">Ajuste as credenciais e padrões usados por todos os geradores.</p>
                </div>
            </div>

            <section class="w-full max-w-3xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-soft">
                <div class="border-b border-slate-200 px-6 py-4">
                    <h2 class="text-lg font-semibold text-slate-950">Credenciais e padrões</h2>
                    <p class="mt-1 text-sm text-slate-500">Esses valores viram padrão ao criar ou duplicar geradores.</p>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="p-6">
                    <?php wp_nonce_field('content_rank_save_settings', 'content_rank_settings_nonce'); ?>
                    <input type="hidden" name="action" value="content_rank_save_settings" />
                    <div class="mb-6 flex flex-wrap gap-2 border-b border-slate-200 pb-4" data-settings-tabs>
                        <button type="button" data-settings-tab-button="general" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Geral</button>
                        <button type="button" data-settings-tab-button="links" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700">Links globais</button>
                        <button type="button" data-settings-tab-button="blacklist" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700">Blacklist</button>
                    </div>
                    <div class="space-y-4">
                        <div data-settings-tab-panel="general" class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Chave da API da OpenAI</label>
                                <input type="password" name="openai_api_key" value="<?php echo esc_attr($settings['openai_api_key']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Modelo padrão</label>
                                <input type="text" name="default_model" value="<?php echo esc_attr($settings['default_model']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Temperatura padrão</label>
                                    <input type="number" step="0.1" min="0" max="2" name="default_temperature" value="<?php echo esc_attr($settings['default_temperature']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Máximo de tokens</label>
                                    <input type="number" min="256" name="default_max_tokens" value="<?php echo esc_attr($settings['default_max_tokens']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                            </div>
                        </div>
                        <div data-settings-tab-panel="general" class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <label class="mb-1 block text-sm font-medium text-slate-700">Chave da API do Pexels</label>
                            <input type="password" name="pexels_api_key" value="<?php echo esc_attr($settings['pexels_api_key']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div data-settings-tab-panel="general" class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                            <div class="mb-3">
                                <h3 class="text-sm font-semibold text-slate-900">TMDB experimental</h3>
                                <p class="mt-1 text-xs text-slate-600">Credenciais usadas pela futura localizaÃ§Ã£o de tÃ­tulos de filmes.</p>
                            </div>
                            <div class="space-y-4">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Token de leitura da API</label>
                                    <input type="password" name="tmdb_read_access_token" value="<?php echo esc_attr($settings['tmdb_read_access_token']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">API key v3 (opcional)</label>
                                    <input type="password" name="tmdb_api_key" value="<?php echo esc_attr($settings['tmdb_api_key']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                            </div>
                        </div>
                        <div data-settings-tab-panel="general" class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="mb-3">
                                <h3 class="text-sm font-semibold text-slate-900">Tavily</h3>
                                <p class="mt-1 text-xs text-slate-500">Busca externa opcional para enriquecer o planejamento com dados recentes.</p>
                            </div>
                            <div class="space-y-4">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Chave da API do Tavily</label>
                                    <input type="password" name="tavily_api_key" value="<?php echo esc_attr($settings['tavily_api_key']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Modo Tavily</label>
                                        <select name="tavily_search_depth" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                            <option value="basic" <?php selected($settings['tavily_search_depth'], 'basic'); ?>>Basic</option>
                                            <option value="advanced" <?php selected($settings['tavily_search_depth'], 'advanced'); ?>>Advanced</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Máximo de resultados</label>
                                        <input type="number" min="1" max="10" name="tavily_max_results" value="<?php echo esc_attr($settings['tavily_max_results']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                    </div>
                                </div>
                                <label class="flex items-center gap-3 text-sm text-slate-700">
                                    <input type="checkbox" name="tavily_enabled" value="1" <?php checked(!empty($settings['tavily_enabled'])); ?> class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                                    Ativar pesquisa Tavily
                                </label>
                                <label class="flex items-center gap-3 text-sm text-slate-700">
                                    <input type="checkbox" name="tavily_include_answer" value="1" <?php checked(!empty($settings['tavily_include_answer'])); ?> class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                                    Incluir resposta resumida no prompt
                                </label>
                            </div>
                        </div>
                        <div id="content-rank-global-links-section" data-settings-tab-panel="links" class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-900">Links globais</h3>
                                    <p class="mt-1 text-xs text-slate-500">Cadastre frases e URLs que podem ser aplicadas automaticamente em qualquer geração.</p>
                                </div>
                            </div>
                            <div class="mt-4 space-y-3" data-global-internal-links-rows></div>
                            <textarea name="global_internal_links_json" class="hidden" data-global-internal-links-json><?php echo esc_textarea(isset($settings['global_internal_links_json']) ? $settings['global_internal_links_json'] : '[]'); ?></textarea>
                            <div class="mt-4 flex justify-end">
                                <button type="button" data-add-global-internal-link class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Adicionar link</button>
                            </div>
                        </div>
                        <div data-settings-tab-panel="blacklist" class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <?php
                            $blacklist_lines = array();
                            foreach ($blacklist_entries as $blacklist_entry) {
                                if (!empty($blacklist_entry['value'])) {
                                    $blacklist_lines[] = (string) $blacklist_entry['value'];
                                }
                            }
                            ?>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Termos e fontes bloqueados</label>
                            <textarea name="blacklist_json" rows="10" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Uma palavra, frase ou dominio por linha"><?php echo esc_textarea(implode("\n", $blacklist_lines)); ?></textarea>
                            <p class="mt-2 text-xs text-slate-500">Qualquer palavra, frase ou dominio informado aqui sera ignorado pelos geradores. Fontes que retornarem HTTP 402 ou 403 continuam sendo adicionadas automaticamente.</p>
                        </div>
                    </div>
                    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-slate-500">Esses valores viram padrão ao criar ou duplicar geradores.</p>
                        <div class="flex items-center gap-3">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=content-rank')); ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Cancelar</a>
                            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">Salvar configurações</button>
                        </div>
                    </div>
                </form>
            </section>
        </div>
        <script>
            (function() {
                var rowsRoot = document.querySelector('[data-global-internal-links-rows]');
                var jsonField = document.querySelector('[data-global-internal-links-json]');
                var addButton = document.querySelector('[data-add-global-internal-link]');

                if (!rowsRoot || !jsonField) {
                    return;
                }

                function escapeHtml(value) {
                    return String(value === undefined || value === null ? '' : value)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');
                }

                function toFlag(value) {
                    if (value === true || value === 1 || value === '1' || value === 'true' || value === 'on') {
                        return '1';
                    }
                    return '0';
                }

                function normalizeRule(rule) {
                    rule = rule || {};
                    return {
                        quantity: Math.max(1, parseInt(rule.quantity, 10) || 1),
                        phrase: String(rule.phrase || rule.word || rule.keyword || rule.anchor_text || '').trim(),
                        url: String(rule.url || rule.link || rule.target_url || '').trim(),
                        target_blank: toFlag(rule.target_blank),
                        nofollow: toFlag(rule.nofollow),
                        sponsored: toFlag(rule.sponsored),
                        ugc: toFlag(rule.ugc)
                    };
                }

                function buildRow(rule) {
                    rule = normalizeRule(rule);
                    return [
                        '<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-global-internal-link-row>',
                        '  <div class="grid gap-3 md:grid-cols-12">',
                        '    <div class="md:col-span-2">',
                        '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Quantidade</label>',
                        '      <input type="number" min="1" value="' + escapeHtml(rule.quantity) + '" data-global-internal-link-quantity class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />',
                        '    </div>',
                        '    <div class="md:col-span-4">',
                        '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Palavra</label>',
                        '      <input type="text" value="' + escapeHtml(rule.phrase) + '" data-global-internal-link-phrase class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Ex.: Disney" />',
                        '    </div>',
                        '    <div class="md:col-span-4">',
                        '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Link</label>',
                        '      <input type="url" value="' + escapeHtml(rule.url) + '" data-global-internal-link-url class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="https://seusite.com/exemplo" />',
                        '    </div>',
                        '    <div class="md:col-span-2">',
                        '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Atributos</label>',
                        '      <div class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">',
                        '        <label class="flex items-center gap-2"><input type="checkbox" data-global-internal-link-target-blank ' + (rule.target_blank === '1' ? 'checked' : '') + ' /> target blank</label>',
                        '        <label class="flex items-center gap-2"><input type="checkbox" data-global-internal-link-nofollow ' + (rule.nofollow === '1' ? 'checked' : '') + ' /> nofollow</label>',
                        '        <label class="flex items-center gap-2"><input type="checkbox" data-global-internal-link-sponsored ' + (rule.sponsored === '1' ? 'checked' : '') + ' /> sponsored</label>',
                        '        <label class="flex items-center gap-2"><input type="checkbox" data-global-internal-link-ugc ' + (rule.ugc === '1' ? 'checked' : '') + ' /> ugc</label>',
                        '      </div>',
                        '    </div>',
                        '  </div>',
                        '  <div class="mt-3 flex justify-end">',
                        '    <button type="button" data-remove-global-internal-link class="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100">Remover</button>',
                        '  </div>',
                        '</div>'
                    ].join('');
                }

                function collectRules() {
                    var rules = [];
                    rowsRoot.querySelectorAll('[data-global-internal-link-row]').forEach(function(row) {
                        var quantityEl = row.querySelector('[data-global-internal-link-quantity]');
                        var phraseEl = row.querySelector('[data-global-internal-link-phrase]');
                        var urlEl = row.querySelector('[data-global-internal-link-url]');
                        var targetBlankEl = row.querySelector('[data-global-internal-link-target-blank]');
                        var nofollowEl = row.querySelector('[data-global-internal-link-nofollow]');
                        var sponsoredEl = row.querySelector('[data-global-internal-link-sponsored]');
                        var ugcEl = row.querySelector('[data-global-internal-link-ugc]');

                        var rule = normalizeRule({
                            quantity: quantityEl ? quantityEl.value : 1,
                            phrase: phraseEl ? phraseEl.value : '',
                            url: urlEl ? urlEl.value : '',
                            target_blank: targetBlankEl && targetBlankEl.checked ? 1 : 0,
                            nofollow: nofollowEl && nofollowEl.checked ? 1 : 0,
                            sponsored: sponsoredEl && sponsoredEl.checked ? 1 : 0,
                            ugc: ugcEl && ugcEl.checked ? 1 : 0
                        });

                        if (!rule.phrase && !rule.url && rule.quantity === 1 && rule.target_blank === '0' && rule.nofollow === '0' && rule.sponsored === '0' && rule.ugc === '0') {
                            return;
                        }

                        rules.push(rule);
                    });
                    return rules;
                }

                function syncField() {
                    jsonField.value = JSON.stringify(collectRules());
                }

                function renderRows(rules) {
                    var normalized = [];
                    if (Array.isArray(rules)) {
                        normalized = rules.map(function(rule) {
                            return normalizeRule(rule);
                        });
                    }
                    if (!normalized.length) {
                        normalized = [normalizeRule({})];
                    }

                    rowsRoot.innerHTML = normalized.map(buildRow).join('');
                    syncField();
                }

                function appendGlobalInternalLinkRow() {
                    rowsRoot.insertAdjacentHTML('beforeend', buildRow(normalizeRule({})));
                    syncField();
                }

                rowsRoot.addEventListener('input', syncField);
                rowsRoot.addEventListener('change', syncField);
                rowsRoot.addEventListener('click', function(event) {
                    var button = event.target && event.target.closest ? event.target.closest('[data-remove-global-internal-link]') : null;
                    if (!button) {
                        return;
                    }
                    var row = button.closest('[data-global-internal-link-row]');
                    if (row) {
                        row.remove();
                        syncField();
                    }
                });

                if (addButton) {
                    addButton.addEventListener('click', function() {
                        appendGlobalInternalLinkRow();
                    });
                }

                try {
                    renderRows(JSON.parse(jsonField.value || '[]'));
                } catch (error) {
                    renderRows([]);
                }
            })();

            (function() {
                var buttons = document.querySelectorAll('[data-settings-tab-button]');
                var panels = document.querySelectorAll('[data-settings-tab-panel]');

                function setTab(tab) {
                    panels.forEach(function(panel) {
                        panel.classList.toggle('hidden', panel.getAttribute('data-settings-tab-panel') !== tab);
                    });
                    buttons.forEach(function(button) {
                        var active = button.getAttribute('data-settings-tab-button') === tab;
                        button.classList.toggle('bg-slate-900', active);
                        button.classList.toggle('text-white', active);
                        button.classList.toggle('font-semibold', active);
                        button.classList.toggle('border', !active);
                        button.classList.toggle('border-slate-300', !active);
                        button.classList.toggle('bg-white', !active);
                        button.classList.toggle('text-slate-700', !active);
                        button.classList.toggle('font-medium', !active);
                    });
                }

                buttons.forEach(function(button) {
                    button.addEventListener('click', function() {
                        setTab(button.getAttribute('data-settings-tab-button') || 'general');
                    });
                });
                setTab('general');
            })();

        </script>
    <?php

        echo ob_get_clean();
    }

    public function render_keyword_lists_page()
    {
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        $global_settings = Content_Rank_Generator::get_settings();
        $keyword_lists = Content_Rank_Generator::get_keyword_lists(200);
        $summary = array(
            'lists' => count($keyword_lists),
            'rows' => 0,
            'pending' => 0,
            'generated' => 0,
            'failed' => 0,
            'processing' => 0,
            'blocked' => 0,
            'invalid' => 0,
        );

        foreach ($keyword_lists as &$keyword_list) {
            $keyword_list['counts'] = Content_Rank_Generator::bulk_get_list_counts(intval($keyword_list['id']));
            $summary['rows'] += intval($keyword_list['counts']['total_rows']);
            $summary['pending'] += intval($keyword_list['counts']['pending_rows']);
            $summary['generated'] += intval($keyword_list['counts']['generated_rows']);
            $summary['failed'] += intval($keyword_list['counts']['failed_rows']);
            $summary['processing'] += intval($keyword_list['counts']['processing_rows']);
            $summary['blocked'] += intval($keyword_list['counts']['blocked_rows']);
            $summary['invalid'] += intval($keyword_list['counts']['invalid_rows']);
        }
        unset($keyword_list);

        $post_types = get_post_types(array('public' => true), 'objects');
        $users = Content_Rank_Generator::get_content_author_users();
        $categories = get_categories(array('hide_empty' => false));
        $tags = get_terms(array(
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ));
        if (is_wp_error($tags)) {
            $tags = array();
        }
        $public_taxonomies = get_taxonomies(array('public' => true), 'objects');
        if (!is_array($public_taxonomies)) {
            $public_taxonomies = array();
        }
        $api_base = rest_url('content-rank/v1');
        $rest_nonce = wp_create_nonce('wp_rest');

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
                    <div class="text-xs font-semibold text-indigo-600">Content Rank</div>
                    <h1 class="mt-2 text-lg font-semibold tracking-tight text-slate-950">Keyword lists</h1>
                    <p class="mt-2 max-w-3xl text-sm text-slate-600">Importe CSV, XLS ou XLSX usando uma coluna de palavras-chave. URL, slug, título e demais campos são opcionais.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" data-open-keyword-import-modal class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Adicionar lista</button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=content-rank')); ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-soft transition hover:bg-slate-50">Ir para geradores</a>
                </div>
            </div>

            <div class="space-y-6">
                <section class="hidden overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">
                    <div class="border-b border-slate-200 px-6 py-4">
                        <h2 class="hidden text-lg font-semibold text-slate-950">Nova keyword list</h2>
                        <p class="mt-1 text-sm text-slate-500">Informe uma frase-chave ou título por linha. Os itens gerados deixam de ficar pendentes automaticamente.</p>
                    </div>
                    <div class="grid gap-4 p-6 md:grid-cols-[280px_1fr_auto] md:items-end">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Nome da lista</label>
                            <input id="content-rank-legacy-manual-keyword-list-name" type="text" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Ex.: Filmes para gerar" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Keywords ou títulos</label>
                            <textarea id="content-rank-legacy-manual-keyword-list-values" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="kw 1&#10;kw 2&#10;kw 3"></textarea>
                        </div>
                        <button type="button" id="content-rank-legacy-create-manual-keyword-list" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Cadastrar lista</button>
                    </div>
                    <div id="content-rank-legacy-manual-keyword-list-status" class="hidden px-6 pb-5 text-sm"></div>
                </section>

                <style>
                    #content-rank-keyword-source-title + p {
                        display: none !important;
                    }
                </style>
                <div id="content-rank-keyword-import-modal" class="fixed inset-0 z-50 hidden">
                    <div id="content-rank-keyword-import-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                    <div class="relative mx-auto flex min-h-full max-w-7xl items-start px-4 pt-16 pb-8 sm:px-6 sm:pt-20 sm:pb-10 lg:px-8">
                        <div class="absolute right-8 top-8 z-10">
                            <button type="button" data-close-keyword-import-modal class="rounded-full bg-white/90 p-2 text-slate-500 shadow-soft transition hover:bg-white hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>
                        <section class="w-full max-h-[calc(100vh-4rem)] overflow-y-auto overscroll-contain rounded-2xl border border-slate-200 bg-white shadow-soft">
                            <div class="border-b border-slate-200 px-6 py-4">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h2 id="content-rank-keyword-source-title" class="text-lg font-semibold text-slate-950">Adicionar lista</h2>
                                        <p class="mt-1 text-sm text-slate-500">Etapa 1: analise o arquivo e selecione as colunas antes de gravar a lista. Se existir a coluna <strong>Timestamp</strong>, ela será usada como data de publicação no WordPress.</p>
                                    </div>
                                    <div class="hidden grid grid-cols-2 gap-3 text-sm text-slate-500 sm:grid-cols-4">
                                        <div class="rounded-xl bg-slate-50 px-3 py-2">
                                            <div class="text-xs uppercase tracking-wide text-slate-400">Listas</div>
                                            <div class="font-semibold text-slate-900"><?php echo esc_html($summary['lists']); ?></div>
                                        </div>
                                        <div class="rounded-xl bg-slate-50 px-3 py-2">
                                            <div class="text-xs uppercase tracking-wide text-slate-400">Linhas</div>
                                            <div class="font-semibold text-slate-900"><?php echo esc_html($summary['rows']); ?></div>
                                        </div>
                                        <div class="rounded-xl bg-slate-50 px-3 py-2">
                                            <div class="text-xs uppercase tracking-wide text-slate-400">Pendentes</div>
                                            <div class="font-semibold text-slate-900"><?php echo esc_html($summary['pending']); ?></div>
                                        </div>
                                        <div class="rounded-xl bg-slate-50 px-3 py-2">
                                            <div class="text-xs uppercase tracking-wide text-slate-400">Geradas</div>
                                            <div class="font-semibold text-slate-900"><?php echo esc_html($summary['generated']); ?></div>
                                        </div>
                                        <div class="rounded-xl bg-slate-50 px-3 py-2">
                                            <div class="text-xs uppercase tracking-wide text-slate-400">Falhas</div>
                                            <div class="font-semibold text-slate-900"><?php echo esc_html($summary['failed']); ?></div>
                                        </div>
                                        <div class="rounded-xl bg-slate-50 px-3 py-2">
                                            <div class="text-xs uppercase tracking-wide text-slate-400">Processando</div>
                                            <div class="font-semibold text-slate-900"><?php echo esc_html($summary['processing']); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="p-6">
                                <div class="mb-6 flex flex-wrap gap-2 border-b border-slate-200 pb-4">
                                    <button type="button" data-keyword-source-tab="spreadsheet" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Planilha</button>
                                    <button type="button" data-keyword-source-tab="keyword_list" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Keyword list</button>
                                </div>
                                <div id="content-rank-keyword-manual-panel" class="hidden">
                                    <div class="max-w-3xl rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                        <h3 class="text-base font-semibold text-slate-950">Keyword list</h3>
                                        <p class="mt-1 text-sm text-slate-500">Digite uma frase-chave ou título por linha. Cada item gerado deixa de aparecer como pendente.</p>
                                        <div class="mt-4 space-y-4">
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Nome da lista</label>
                                                <input id="content-rank-manual-keyword-list-name" type="text" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Ex.: Filmes para gerar" />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Keywords ou títulos</label>
                                                <textarea id="content-rank-manual-keyword-list-values" rows="12" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="kw 1&#10;kw 2&#10;kw 3"></textarea>
                                            </div>
                                            <div class="flex flex-wrap items-center gap-3">
                                                <button type="button" id="content-rank-create-manual-keyword-list" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Cadastrar lista</button>
                                                <div id="content-rank-manual-keyword-list-status" class="text-sm text-slate-500"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="content-rank-keyword-spreadsheet-panel">
                                <div class="grid gap-4 md:grid-cols-[1fr_220px]">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Nome da lista</label>
                                        <input id="content-rank-keyword-list-name" type="text" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Ex.: Semrush - Vestibulares" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Arquivo</label>
                                        <input id="content-rank-keyword-file" type="file" accept=".csv,.xls,.xlsx" class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-white file:transition hover:file:bg-slate-800" />
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center gap-3">
                                    <button type="button" id="content-rank-keyword-analyze-btn" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Analisar planilha</button>
                                    <button type="button" id="content-rank-keyword-clear-btn" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Limpar</button>
                                    <div id="content-rank-keyword-upload-status" class="text-sm text-slate-500"></div>
                                </div>

                                <div id="content-rank-keyword-preview-panel" class="hidden mt-6 rounded-2xl border border-slate-200 bg-slate-50">
                                    <div class="border-b border-slate-200 px-5 py-4">
                                        <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                                            <div>
                                                <h3 class="text-base font-semibold text-slate-950">Mapeamento das colunas</h3>
                                                <p class="mt-1 text-sm text-slate-500">A coluna de palavra-chave é obrigatória. URL, slug e campos extras são opcionais.</p>
                                            </div>
                                            <div id="content-rank-keyword-preview-summary" class="text-sm text-slate-500"></div>
                                        </div>
                                    </div>

                                    <div class="grid gap-4 border-b border-slate-200 px-5 py-5 md:grid-cols-2">
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Coluna da keyword</label>
                                            <select id="content-rank-keyword-column-keyword" class="content-rank-keyword-column-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"></select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Coluna do título</label>
                                            <select id="content-rank-keyword-column-title" class="content-rank-keyword-column-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"></select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Coluna da URL (opcional)</label>
                                            <select id="content-rank-keyword-column-url" class="content-rank-keyword-column-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"></select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Coluna da slug final (opcional)</label>
                                            <select id="content-rank-keyword-column-slug" class="content-rank-keyword-column-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"></select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Coluna de conteúdo</label>
                                            <select id="content-rank-keyword-column-content" class="content-rank-keyword-column-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"></select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Coluna de tags</label>
                                            <select id="content-rank-keyword-column-tags" class="content-rank-keyword-column-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"></select>
                                        </div>
                                    </div>

                                    <div class="px-5 py-4">
                                        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <button type="button" id="content-rank-keyword-import-btn" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Importar lista</button>
                                            </div>
                                        </div>
                                        <div id="content-rank-keyword-preview-table" class="overflow-hidden rounded-2xl border border-slate-200 bg-white"></div>
                                    </div>
                                </div>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>

                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">
                    <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-4">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">Keyword lists</h2>
                            <p class="mt-1 text-sm text-slate-500">Abra uma lista para ajustar colunas ou revisar a prévia das linhas.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" id="content-rank-keyword-refresh-btn" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Atualizar</button>
                            <div class="rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-500"><?php echo esc_html(count($keyword_lists)); ?> lista(s)</div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <th class="px-6 py-3">Nome</th>
                                    <th class="px-6 py-3">Tipo</th>
                                    <th class="px-6 py-3">Linhas</th>
                                    <th class="px-6 py-3">Pendentes</th>
                                    <th class="px-6 py-3">Geradas</th>
                                    <th class="px-6 py-3">Falhas</th>
                                    <th class="px-6 py-3">Processando</th>
                                    <th class="px-6 py-3">Bloqueadas</th>
                                    <th class="px-6 py-3">Atualizado</th>
                                    <th class="px-6 py-3">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <?php if (empty($keyword_lists)): ?>
                                    <tr>
                                        <td colspan="10" class="px-6 py-10 text-center text-sm text-slate-500">Nenhuma lista importada ainda.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($keyword_lists as $keyword_list): ?>
                                        <tr class="align-top">
                                            <td class="px-6 py-4">
                                                <div class="font-semibold text-slate-950"><?php echo esc_html($keyword_list['list_name']); ?></div>
                                                <div class="mt-1 text-xs text-slate-500"><?php echo esc_html($keyword_list['original_filename']); ?></div>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-slate-700"><?php echo esc_html(strtoupper($keyword_list['file_type'])); ?></td>
                                            <td class="px-6 py-4 text-sm text-slate-700"><?php echo esc_html(intval($keyword_list['counts']['total_rows'])); ?></td>
                                            <td class="px-6 py-4 text-sm text-slate-700"><?php echo esc_html(intval($keyword_list['counts']['pending_rows'])); ?></td>
                                            <td class="px-6 py-4 text-sm text-slate-700"><?php echo esc_html(intval($keyword_list['counts']['generated_rows'])); ?></td>
                                            <td class="px-6 py-4 text-sm text-slate-700"><?php echo esc_html(intval($keyword_list['counts']['failed_rows'])); ?></td>
                                            <td class="px-6 py-4 text-sm text-slate-700"><?php echo esc_html(intval($keyword_list['counts']['processing_rows'])); ?></td>
                                            <td class="px-6 py-4 text-sm text-slate-700"><?php echo esc_html(intval($keyword_list['counts']['blocked_rows'])); ?></td>
                                            <td class="px-6 py-4 text-sm text-slate-600"><?php echo esc_html($keyword_list['updated_at'] ?: '-'); ?></td>
                                            <td class="px-6 py-4">
                                                <div class="flex flex-wrap gap-2">
                                                    <?php if (isset($keyword_list['file_type']) && $keyword_list['file_type'] === 'keyword_list'): ?>
                                                        <button type="button" data-edit-keyword-list data-list-id="<?php echo esc_attr($keyword_list['id']); ?>" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Editar</button>
                                                    <?php endif; ?>
                                                    <button type="button" data-open-keyword-list-modal data-list-id="<?php echo esc_attr($keyword_list['id']); ?>" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-indigo-500">Abrir</button>
                                                    <button type="button" data-open-keyword-generate-modal data-list-id="<?php echo esc_attr($keyword_list['id']); ?>" class="inline-flex items-center rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-emerald-500">Gerar</button>
                                                    <button type="button" data-delete-keyword-list-id="<?php echo esc_attr($keyword_list['id']); ?>" class="inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 transition hover:bg-rose-100">Excluir</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div id="content-rank-keyword-list-modal" class="fixed inset-0 z-50 hidden">
                <div id="content-rank-keyword-list-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                <div class="relative mx-auto flex min-h-full max-w-7xl items-center px-4 py-8 sm:px-6 lg:px-8">
                    <div class="max-h-[90vh] w-full overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200">
                        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 id="content-rank-keyword-list-modal-title" class="text-xl font-semibold text-slate-950">Detalhe da lista</h2>
                                <p id="content-rank-keyword-list-modal-subtitle" class="mt-1 text-sm text-slate-500">Carregando detalhes...</p>
                            </div>
                            <button type="button" data-close-keyword-list-modal class="rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>
                        <div class="max-h-[calc(90vh-82px)] overflow-y-auto p-6">
                            <div id="content-rank-keyword-list-modal-status" class="hidden mb-4 rounded-xl border px-4 py-3 text-sm"></div>

                            <div id="content-rank-keyword-list-modal-counts" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"></div>

                            <div class="mt-6 grid gap-6 lg:grid-cols-[0.95fr_1.05fr]">
                                <div class="space-y-6">
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                        <div class="border-b border-slate-200 px-4 py-3">
                                            <h3 class="text-sm font-semibold text-slate-950">Mapeamento de colunas</h3>
                                        </div>
                                        <div id="content-rank-keyword-list-modal-mapping" class="grid gap-4 px-4 py-4 sm:grid-cols-2"></div>
                                    </div>

                                    <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                        <div class="border-b border-slate-200 px-4 py-3">
                                            <h3 class="text-sm font-semibold text-slate-950">Informações do arquivo</h3>
                                        </div>
                                        <div id="content-rank-keyword-list-modal-info" class="space-y-2 px-4 py-4 text-sm text-slate-600"></div>
                                    </div>
                                </div>

                                <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                    <div class="border-b border-slate-200 px-4 py-3">
                                        <h3 class="text-sm font-semibold text-slate-950">Prévia das linhas</h3>
                                    </div>
                                    <div id="content-rank-keyword-list-modal-preview" class="overflow-x-auto"></div>
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-col gap-3 border-t border-slate-200 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <button type="button" id="content-rank-keyword-delete-current-list" class="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-medium text-rose-700 transition hover:bg-rose-100">Excluir lista</button>
                            <div class="flex items-center gap-3">
                                <button type="button" id="content-rank-keyword-open-generate-btn" class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-500">Gerar em lote</button>
                                <button type="button" data-close-keyword-list-modal class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Fechar</button>
                                <button type="button" id="content-rank-keyword-save-map-btn" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500">Salvar mapeamento</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="content-rank-keyword-generate-modal" class="fixed inset-0 z-50 hidden">
                <div id="content-rank-keyword-generate-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                <div class="relative mx-auto flex min-h-full max-w-7xl items-center px-4 py-8 sm:px-6 lg:px-8">
                    <div class="max-h-[92vh] w-full overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200">
                        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 id="content-rank-keyword-generate-title" class="text-xl font-semibold text-slate-950">Gerar em lote</h2>
                                <p id="content-rank-keyword-generate-subtitle" class="mt-1 text-sm text-slate-500">Escolha a quantidade, aplique filtros e configure a criação do WordPress.</p>
                            </div>
                            <button type="button" data-close-keyword-generate-modal class="rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>

                        <div class="max-h-[calc(92vh-82px)] overflow-y-auto p-6">
                            <div class="grid gap-6 xl:grid-cols-[0.92fr_1.08fr]">
                                <div class="space-y-6">
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <div class="flex items-center justify-between gap-3">
                                            <div>
                                                <div class="text-xs uppercase tracking-wide text-slate-400">Lista selecionada</div>
                                                <div id="content-rank-keyword-generate-list-name" class="mt-1 text-base font-semibold text-slate-950">-</div>
                                            </div>
                                            <button type="button" id="content-rank-keyword-generate-refresh-count" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Atualizar quantidade</button>
                                        </div>
                                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                            <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                                                <div class="text-xs uppercase tracking-wide text-slate-400">Disponíveis agora</div>
                                                <div id="content-rank-keyword-generate-available-count" class="mt-1 text-2xl font-semibold text-slate-950">0</div>
                                            </div>
                                            <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                                                <div class="text-xs uppercase tracking-wide text-slate-400">Serão gerados</div>
                                                <div id="content-rank-keyword-generate-target-count" class="mt-1 text-2xl font-semibold text-slate-950">0</div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <div class="mb-4">
                                            <h3 class="text-sm font-semibold text-slate-950">Quantidade</h3>
                                            <p class="mt-1 text-sm text-slate-500">Informe quantos itens quer gerar agora.</p>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Gerar quantos?</label>
                                            <input id="content-rank-keyword-generate-requested" type="number" min="1" value="1" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                        <p id="content-rank-keyword-generate-count-msg" class="mt-3 text-sm text-slate-500">Os filtros são aplicados em conjunto. Clique em atualizar para ver quantos itens batem.</p>
                                    </div>

                                    <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <h3 class="text-sm font-semibold text-indigo-950">Pronto para gerar</h3>
                                                <p class="mt-1 text-sm text-indigo-700">Quando a quantidade estiver correta, clique para iniciar a geração dos itens da planilha.</p>
                                            </div>
                                            <button type="button" id="content-rank-keyword-generate-run-cta" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500">Iniciar geração</button>
                                        </div>
                                    </div>

                                    <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                                            <div>
                                                <h3 class="text-sm font-semibold text-slate-950">Filtros</h3>
                                                <p class="mt-1 text-xs text-slate-500">Todos os filtros são combinados com AND.</p>
                                            </div>
                                            <button type="button" id="content-rank-keyword-add-filter" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Adicionar filtro</button>
                                        </div>
                                        <div id="content-rank-keyword-generate-filters" class="space-y-3 px-4 py-4"></div>
                                    </div>
                                </div>

                                <div class="space-y-6">
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                        <div class="border-b border-slate-200 px-4 py-3">
                                            <h3 class="text-sm font-semibold text-slate-950">Opções de criação do WordPress</h3>
                                        </div>
                                        <div class="grid gap-4 px-4 py-4 md:grid-cols-2">
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Tipo de post</label>
                                                <select id="content-rank-keyword-generate-post-type" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                    <?php foreach ($post_types as $pt): ?>
                                                        <option value="<?php echo esc_attr($pt->name); ?>"><?php echo esc_html($pt->labels->singular_name); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Status do post</label>
                                                <select id="content-rank-keyword-generate-post-status" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                    <?php foreach (array('draft', 'publish', 'pending', 'private', 'future') as $status): ?>
                                                        <option value="<?php echo esc_attr($status); ?>"><?php echo esc_html(self::get_post_status_label($status)); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Autor</label>
                                                <select id="content-rank-keyword-generate-author" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                    <option value="0">Usuário atual</option>
                                                    <?php foreach ($users as $user): ?>
                                                        <option value="<?php echo esc_attr($user->ID); ?>"><?php echo esc_html($user->display_name . ' (' . $user->user_login . ')'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Linguagem final</label>
                                                <input id="content-rank-keyword-generate-language" type="text" value="<?php echo esc_attr(Content_Rank_Generator::get_default_generation_language()); ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Modelo</label>
                                                <input id="content-rank-keyword-generate-model" type="text" value="<?php echo esc_attr($global_settings['default_model']); ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Temperatura</label>
                                                <input id="content-rank-keyword-generate-temperature" type="number" step="0.1" min="0" max="2" value="<?php echo esc_attr($global_settings['default_temperature']); ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Máximo de tokens</label>
                                                <input id="content-rank-keyword-generate-max-tokens" type="number" min="256" value="<?php echo esc_attr($global_settings['default_max_tokens']); ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Consulta no Pexels</label>
                                                <input id="content-rank-keyword-generate-pexels-query" type="text" value="<?php echo esc_attr(Content_Rank_Generator::get_default_pexels_query()); ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                            </div>
                                            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 md:col-span-2">
                                                <div class="text-sm font-medium text-amber-900">Pexels obrigatório</div>
                                                <p class="mt-1 text-xs text-amber-700">Listas por planilha sempre usam imagens do Pexels. Imagens do site de origem são ignoradas.</p>
                                            </div>
                                            <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
                                                <input id="content-rank-keyword-generate-source-video-enabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                                                <div>
                                                    <label for="content-rank-keyword-generate-source-video-enabled" class="block text-sm font-medium text-slate-700">Usar vídeo da fonte</label>
                                                    <p class="text-xs text-slate-500">Se houver vídeo na origem, ele entra no post.</p>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
                                                <input id="content-rank-keyword-generate-seo-enabled" type="checkbox" checked class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                                                <div>
                                                    <label for="content-rank-keyword-generate-seo-enabled" class="block text-sm font-medium text-slate-700">Ativar SEO</label>
                                                    <p class="text-xs text-slate-500">Preenche Yoast, Rank Math, SmartCrawl e AIOSEO quando disponíveis.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid gap-6 md:grid-cols-2">
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                            <div class="border-b border-slate-200 px-4 py-3">
                                                <h3 class="text-sm font-semibold text-slate-950">Categorias</h3>
                                            </div>
                                            <div class="px-4 py-4">
                                                <select id="content-rank-keyword-generate-categories" multiple size="8" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                    <?php foreach ($categories as $category): ?>
                                                        <option value="<?php echo esc_attr($category->term_id); ?>"><?php echo esc_html($category->name); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                            <div class="border-b border-slate-200 px-4 py-3">
                                                <h3 class="text-sm font-semibold text-slate-950">Tags</h3>
                                            </div>
                                            <div class="px-4 py-4">
                                                <select id="content-rank-keyword-generate-tags" multiple size="8" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                    <?php foreach ($tags as $tag): ?>
                                                        <option value="<?php echo esc_attr($tag->name); ?>"><?php echo esc_html($tag->name); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid gap-6 md:grid-cols-2">
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                            <div class="border-b border-slate-200 px-4 py-3">
                                                <h3 class="text-sm font-semibold text-slate-950">Taxonomias personalizadas</h3>
                                            </div>
                                            <div class="px-4 py-4">
                                                <textarea id="content-rank-keyword-generate-taxonomies" rows="5" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="taxonomia=term1,term2"></textarea>
                                                <p class="mt-2 text-xs text-slate-500">Use uma linha por taxonomia. Ex.: `series=principal,secundaria`.</p>
                                                <?php
                                                $public_taxonomy_labels = array();
                                                foreach ($public_taxonomies as $public_taxonomy) {
                                                    $public_taxonomy_labels[] = !empty($public_taxonomy->labels->name) ? $public_taxonomy->labels->name : $public_taxonomy->name;
                                                }
                                                ?>
                                                <p class="mt-2 text-xs text-slate-500">Taxonomias públicas detectadas: <?php echo esc_html(!empty($public_taxonomy_labels) ? implode(', ', $public_taxonomy_labels) : '-'); ?></p>
                                            </div>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                            <div class="border-b border-slate-200 px-4 py-3">
                                                <h3 class="text-sm font-semibold text-slate-950">Metadados personalizados</h3>
                                            </div>
                                            <div class="px-4 py-4">
                                                <textarea id="content-rank-keyword-generate-meta" rows="5" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="meta_key=valor"></textarea>
                                                <p class="mt-2 text-xs text-slate-500">Use uma linha por meta. Ex.: `_seo_title=Meu título`.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-3 border-t border-slate-200 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-sm text-slate-500">Clique em atualizar quantidade após aplicar filtros para ver o total elegível.</div>
                            <div class="flex items-center gap-3">
                                <button type="button" data-close-keyword-generate-modal class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Fechar</button>
                                <button type="button" id="content-rank-keyword-generate-run-btn" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500">Iniciar geração</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                (function() {
                    var apiBase = <?php echo wp_json_encode($api_base); ?>;
                    var restNonce = <?php echo wp_json_encode($rest_nonce); ?>;
                    var keywordLists = <?php echo wp_json_encode(array_values($keyword_lists)); ?>;
                    var currentPreview = null;
                    var currentUploadFile = null;
                    var currentDetailList = null;
                    var openModalCount = 0;

                    var fileInput = document.getElementById('content-rank-keyword-file');
                    var listNameInput = document.getElementById('content-rank-keyword-list-name');
                    var analyzeButton = document.getElementById('content-rank-keyword-analyze-btn');
                    var clearButton = document.getElementById('content-rank-keyword-clear-btn');
                    var uploadStatus = document.getElementById('content-rank-keyword-upload-status');
                    var importModal = document.getElementById('content-rank-keyword-import-modal');
                    var importBackdrop = document.getElementById('content-rank-keyword-import-backdrop');
                    var openImportButtons = document.querySelectorAll('[data-open-keyword-import-modal]');
                    var closeImportButtons = document.querySelectorAll('[data-close-keyword-import-modal]');
                    var importLogsPanel = document.getElementById('content-rank-keyword-import-logs-panel');
                    var importLogsTable = document.getElementById('content-rank-keyword-import-logs');
                    var clearImportLogsButton = document.getElementById('content-rank-keyword-clear-import-logs');
                    var previewPanel = document.getElementById('content-rank-keyword-preview-panel');
                    var previewSummary = document.getElementById('content-rank-keyword-preview-summary');
                    var previewTable = document.getElementById('content-rank-keyword-preview-table');
                    var importButton = document.getElementById('content-rank-keyword-import-btn');
                    var manualListNameInput = document.getElementById('content-rank-manual-keyword-list-name');
                    var manualListValuesInput = document.getElementById('content-rank-manual-keyword-list-values');
                    var manualListButton = document.getElementById('content-rank-create-manual-keyword-list');
                    var manualListStatus = document.getElementById('content-rank-manual-keyword-list-status');
                    var sourceTitle = document.getElementById('content-rank-keyword-source-title');
                    var manualPanel = document.getElementById('content-rank-keyword-manual-panel');
                    var spreadsheetPanel = document.getElementById('content-rank-keyword-spreadsheet-panel');
                    var sourceTabButtons = document.querySelectorAll('[data-keyword-source-tab]');
                    var manualListEditingId = 0;
                    var resetPreviewButton = document.getElementById('content-rank-keyword-reset-preview');
                    var refreshButton = document.getElementById('content-rank-keyword-refresh-btn');

                    var listModal = document.getElementById('content-rank-keyword-list-modal');
                    var listBackdrop = document.getElementById('content-rank-keyword-list-backdrop');
                    var listModalTitle = document.getElementById('content-rank-keyword-list-modal-title');
                    var listModalSubtitle = document.getElementById('content-rank-keyword-list-modal-subtitle');
                    var listModalStatus = document.getElementById('content-rank-keyword-list-modal-status');
                    var listModalCounts = document.getElementById('content-rank-keyword-list-modal-counts');
                    var listModalMapping = document.getElementById('content-rank-keyword-list-modal-mapping');
                    var listModalInfo = document.getElementById('content-rank-keyword-list-modal-info');
                    var listModalPreview = document.getElementById('content-rank-keyword-list-modal-preview');
                    var saveMapButton = document.getElementById('content-rank-keyword-save-map-btn');
                    var deleteCurrentListButton = document.getElementById('content-rank-keyword-delete-current-list');
                    var openGenerateFromListButton = document.getElementById('content-rank-keyword-open-generate-btn');

                    var generateModal = document.getElementById('content-rank-keyword-generate-modal');
                    var generateBackdrop = document.getElementById('content-rank-keyword-generate-backdrop');
                    var generateModalTitle = document.getElementById('content-rank-keyword-generate-title');
                    var generateModalSubtitle = document.getElementById('content-rank-keyword-generate-subtitle');
                    var generateListName = document.getElementById('content-rank-keyword-generate-list-name');
                    var generateAvailableCount = document.getElementById('content-rank-keyword-generate-available-count');
                    var generateTargetCount = document.getElementById('content-rank-keyword-generate-target-count');
                    var generateRequestedInput = document.getElementById('content-rank-keyword-generate-requested');
                    var generateRefreshCountButton = document.getElementById('content-rank-keyword-generate-refresh-count');
                    var generateCountMessage = document.getElementById('content-rank-keyword-generate-count-msg');
                    var generateFiltersContainer = document.getElementById('content-rank-keyword-generate-filters');
                    var generateAddFilterButton = document.getElementById('content-rank-keyword-add-filter');
                    var generateRunButton = document.getElementById('content-rank-keyword-generate-run-btn');
                    var generateRunCtaButton = document.getElementById('content-rank-keyword-generate-run-cta');
                    var generateCancelButtons = document.querySelectorAll('[data-close-keyword-generate-modal]');
                    var generatePostTypeSelect = document.getElementById('content-rank-keyword-generate-post-type');
                    var generatePostStatusSelect = document.getElementById('content-rank-keyword-generate-post-status');
                    var generateAuthorSelect = document.getElementById('content-rank-keyword-generate-author');
                    var generateLanguageInput = document.getElementById('content-rank-keyword-generate-language');
                    var generateModelInput = document.getElementById('content-rank-keyword-generate-model');
                    var generateTemperatureInput = document.getElementById('content-rank-keyword-generate-temperature');
                    var generateMaxTokensInput = document.getElementById('content-rank-keyword-generate-max-tokens');
                    var generatePexelsQueryInput = document.getElementById('content-rank-keyword-generate-pexels-query');
                    var generateSourceVideoEnabledInput = document.getElementById('content-rank-keyword-generate-source-video-enabled');
                    var generateSeoEnabledInput = document.getElementById('content-rank-keyword-generate-seo-enabled');
                    var generateCategoriesSelect = document.getElementById('content-rank-keyword-generate-categories');
                    var generateTagsSelect = document.getElementById('content-rank-keyword-generate-tags');
                    var generateTaxonomiesTextarea = document.getElementById('content-rank-keyword-generate-taxonomies');
                    var generateMetaTextarea = document.getElementById('content-rank-keyword-generate-meta');

                    [
                        generateModelInput,
                        generateTemperatureInput,
                        generateMaxTokensInput,
                        generatePexelsQueryInput,
                        generateSeoEnabledInput
                    ].forEach(function(el) {
                        if (el && el.parentElement) {
                            el.parentElement.classList.add('hidden');
                        }
                    });
                    var currentGenerateList = null;
                    var currentGenerateAvailableCount = null;
                    var currentGenerateCountReady = false;
                    var currentGenerateRunToken = 0;
                    var generateCountRequestTimer = null;
                    var generateFilterCounter = 0;

                    function syncBodyLock() {
                        document.body.classList.toggle('overflow-hidden', openModalCount > 0);
                    }

                    function openModal(modal) {
                        if (!modal || !modal.classList.contains('hidden')) {
                            return;
                        }
                        modal.classList.remove('hidden');
                        openModalCount++;
                        syncBodyLock();
                    }

                    function closeModal(modal) {
                        if (!modal || modal.classList.contains('hidden')) {
                            return;
                        }
                        modal.classList.add('hidden');
                        openModalCount = Math.max(0, openModalCount - 1);
                        syncBodyLock();
                    }

                    function setStatus(target, message, type) {
                        if (!target) {
                            return;
                        }
                        if (!message) {
                            target.className = 'hidden mb-4 rounded-xl border px-4 py-3 text-sm';
                            target.textContent = '';
                            return;
                        }
                        var classes = 'mb-4 rounded-xl border px-4 py-3 text-sm';
                        if (type === 'error') {
                            classes += ' border-rose-200 bg-rose-50 text-rose-700';
                        } else if (type === 'success') {
                            classes += ' border-emerald-200 bg-emerald-50 text-emerald-700';
                        } else {
                            classes += ' border-amber-200 bg-amber-50 text-amber-700';
                        }
                        target.className = classes;
                        target.textContent = message;
                    }

                    function parseJsonPayload(text) {
                        var value = text === undefined || text === null ? '' : String(text);
                        value = value.replace(/^\uFEFF/, '').trim();
                        if (!value) {
                            return null;
                        }
                        try {
                            return JSON.parse(value);
                        } catch (error) {
                            var jsonStart = value.search(/[\{\[]/);
                            if (jsonStart > 0) {
                                try {
                                    return JSON.parse(value.slice(jsonStart));
                                } catch (fallbackError) {}
                            }
                            return {
                                success: false,
                                message: value || 'Resposta invalida'
                            };
                        }
                    }

                    function api(path, options) {
                        var fetchOptions = options || {};
                        fetchOptions.credentials = 'same-origin';
                        fetchOptions.headers = fetchOptions.headers || {};
                        fetchOptions.headers['X-WP-Nonce'] = restNonce;
                        return fetch(apiBase + path, fetchOptions).then(function(response) {
                            return response.text().then(function(text) {
                                var payload = parseJsonPayload(text);
                                return {
                                    ok: response.ok,
                                    status: response.status,
                                    payload: payload
                                };
                            });
                        });
                    }

                    function setKeywordSourceTab(tab) {
                        var isManual = String(tab || '') === 'keyword_list';
                        if (manualPanel) {
                            manualPanel.classList.toggle('hidden', !isManual);
                        }
                        if (spreadsheetPanel) {
                            spreadsheetPanel.classList.toggle('hidden', isManual);
                        }
                        if (sourceTitle) {
                            sourceTitle.textContent = isManual ? (manualListEditingId ? 'Editar keyword list' : 'Adicionar keyword list') : 'Adicionar planilha';
                        }
                        sourceTabButtons.forEach(function(button) {
                            var active = String(button.getAttribute('data-keyword-source-tab') || '') === String(tab || '');
                            button.className = active
                                ? 'rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white'
                                : 'rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700';
                        });
                    }

                    function resetManualKeywordEditor() {
                        manualListEditingId = 0;
                        if (manualListNameInput) {
                            manualListNameInput.value = '';
                        }
                        if (manualListValuesInput) {
                            manualListValuesInput.value = '';
                        }
                        if (manualListStatus) {
                            manualListStatus.textContent = '';
                            manualListStatus.className = 'text-sm text-slate-500';
                        }
                        if (manualListButton) {
                            manualListButton.disabled = false;
                            manualListButton.textContent = 'Cadastrar lista';
                        }
                    }

                    async function openManualKeywordEditor(listId) {
                        if (!listId) {
                            return;
                        }
                        resetManualKeywordEditor();
                        manualListEditingId = parseInt(listId, 10) || 0;
                        setKeywordSourceTab('keyword_list');
                        openModal(importModal);
                        if (manualListStatus) {
                            manualListStatus.textContent = 'Carregando lista...';
                            manualListStatus.className = 'text-sm text-slate-500';
                        }

                        try {
                            var result = await api('/keyword-lists/' + encodeURIComponent(manualListEditingId), { method: 'GET' });
                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error(result.payload && result.payload.message ? result.payload.message : 'Nao foi possivel carregar a keyword list.');
                            }
                            var rows = result.payload.rows || [];
                            var pendingKeywords = rows.filter(function(row) {
                                return row && row.row_status === 'pending';
                            }).map(function(row) {
                                return row && row.keyword ? row.keyword : (row && row.row_data && row.row_data.keyword ? row.row_data.keyword : '');
                            }).filter(function(keyword) {
                                return String(keyword || '').trim() !== '';
                            });
                            if (manualListNameInput) {
                                manualListNameInput.value = result.payload.list && result.payload.list.list_name ? result.payload.list.list_name : '';
                            }
                            if (manualListValuesInput) {
                                manualListValuesInput.value = pendingKeywords.join('\n');
                            }
                            if (manualListButton) {
                                manualListButton.textContent = 'Salvar alterações';
                            }
                            if (manualListStatus) {
                                manualListStatus.textContent = 'Edite o nome ou as keywords pendentes.';
                                manualListStatus.className = 'text-sm text-slate-500';
                            }
                        } catch (error) {
                            if (manualListStatus) {
                                manualListStatus.textContent = error.message || 'Erro ao carregar a keyword list.';
                                manualListStatus.className = 'text-sm text-rose-600';
                            }
                        }
                    }

                    function createManualKeywordList() {
                        var listName = manualListNameInput ? manualListNameInput.value.trim() : '';
                        var keywords = manualListValuesInput ? manualListValuesInput.value.trim() : '';
                        if (!listName || !keywords) {
                            if (manualListStatus) {
                                manualListStatus.textContent = 'Informe o nome da lista e ao menos uma keyword.';
                                manualListStatus.className = 'text-sm text-rose-600';
                            }
                            return;
                        }

                        if (manualListButton) {
                            manualListButton.disabled = true;
                            manualListButton.textContent = manualListEditingId ? 'Salvando...' : 'Cadastrando...';
                        }

                        var endpoint = manualListEditingId
                            ? '/keyword-lists/' + encodeURIComponent(manualListEditingId) + '/manual'
                            : '/keyword-lists/manual';
                        api(endpoint, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                list_name: listName,
                                keywords: keywords
                            })
                        }).then(function(result) {
                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error(result.payload && result.payload.message ? result.payload.message : 'Nao foi possivel cadastrar a lista.');
                            }
                            if (manualListStatus) {
                                manualListStatus.textContent = manualListEditingId ? 'Keyword list atualizada com sucesso.' : 'Keyword list cadastrada com sucesso.';
                                manualListStatus.className = 'text-sm text-emerald-600';
                            }
                            if (manualListNameInput) {
                                manualListNameInput.value = '';
                            }
                            if (manualListValuesInput) {
                                manualListValuesInput.value = '';
                            }
                            window.setTimeout(function() {
                                window.location.reload();
                            }, 500);
                        }).catch(function(error) {
                            if (manualListStatus) {
                                manualListStatus.textContent = error.message || 'Erro ao salvar a lista.';
                                manualListStatus.className = 'text-sm text-rose-600';
                            }
                        }).finally(function() {
                            if (manualListButton) {
                                manualListButton.disabled = false;
                                manualListButton.textContent = manualListEditingId ? 'Salvar alterações' : 'Cadastrar lista';
                            }
                        });
                    }

                    function escapeHtml(value) {
                        return String(value === undefined || value === null ? '' : value)
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;')
                            .replace(/'/g, '&#039;');
                    }

                    function buildSelectOptions(headers, selected) {
                        var options = ['<option value="">Selecione...</option>'];
                        (headers || []).forEach(function(header) {
                            var escaped = escapeHtml(header);
                            var isSelected = String(header) === String(selected) ? ' selected' : '';
                            options.push('<option value="' + escaped + '"' + isSelected + '>' + escaped + '</option>');
                        });
                        return options.join('');
                    }

                    var generateFilterOperators = [{
                            value: 'contains',
                            label: 'Contém'
                        },
                        {
                            value: 'equals',
                            label: 'Igual a'
                        },
                        {
                            value: 'not_equals',
                            label: 'Diferente de'
                        },
                        {
                            value: 'greater',
                            label: 'Maior que'
                        },
                        {
                            value: 'greater_or_equal',
                            label: 'Maior ou igual'
                        },
                        {
                            value: 'less',
                            label: 'Menor que'
                        },
                        {
                            value: 'less_or_equal',
                            label: 'Menor ou igual'
                        },
                        {
                            value: 'empty',
                            label: 'Vazio'
                        },
                        {
                            value: 'not_empty',
                            label: 'Não vazio'
                        }
                    ];

                    function buildGenerateOperatorOptions(selected) {
                        return generateFilterOperators.map(function(item) {
                            var isSelected = String(item.value) === String(selected) ? ' selected' : '';
                            return '<option value="' + escapeHtml(item.value) + '"' + isSelected + '>' + escapeHtml(item.label) + '</option>';
                        }).join('');
                    }

                    function getGenerateHeaders() {
                        if (currentGenerateList && currentGenerateList.headers) {
                            return currentGenerateList.headers;
                        }
                        if (currentDetailList && currentDetailList.headers) {
                            return currentDetailList.headers;
                        }
                        return [];
                    }

                    function renderGenerateFilterRow(filter) {
                        var headers = getGenerateHeaders();
                        var item = filter || {};
                        var rowId = 'filter-' + (++generateFilterCounter);
                        return [
                            '<div data-generate-filter-row data-filter-id="' + escapeHtml(rowId) + '" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-3 md:grid-cols-[1.1fr_0.8fr_1fr_auto]">',
                            '<div>',
                            '<label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Coluna</label>',
                            '<select data-filter-column class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildSelectOptions(headers, item.column || '') + '</select>',
                            '</div>',
                            '<div>',
                            '<label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Condição</label>',
                            '<select data-filter-operator class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildGenerateOperatorOptions(item.operator || 'contains') + '</select>',
                            '</div>',
                            '<div>',
                            '<label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Valor</label>',
                            '<input data-filter-value type="text" value="' + escapeHtml(item.value || '') + '" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Digite o valor" />',
                            '</div>',
                            '<div class="flex items-end">',
                            '<button type="button" data-remove-filter class="inline-flex h-[42px] items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Remover</button>',
                            '</div>',
                            '</div>'
                        ].join('');
                    }

                    function renderGenerateFilters(filters) {
                        if (!generateFiltersContainer) {
                            return;
                        }
                        var list = Array.isArray(filters) && filters.length ? filters : [{}];
                        generateFiltersContainer.innerHTML = list.map(function(filter) {
                            return renderGenerateFilterRow(filter);
                        }).join('');
                        updateGenerateTargetSummary();
                    }

                    function getGenerateFilters() {
                        var filters = [];
                        if (!generateFiltersContainer) {
                            return filters;
                        }

                        generateFiltersContainer.querySelectorAll('[data-generate-filter-row]').forEach(function(row) {
                            var columnSelect = row.querySelector('[data-filter-column]');
                            var operatorSelect = row.querySelector('[data-filter-operator]');
                            var valueInput = row.querySelector('[data-filter-value]');
                            var column = columnSelect ? columnSelect.value : '';
                            var operator = operatorSelect ? operatorSelect.value : 'contains';
                            var value = valueInput ? valueInput.value : '';

                            if (!column) {
                                return;
                            }
                            if (!operator) {
                                operator = 'contains';
                            }
                            if (operator !== 'empty' && operator !== 'not_empty' && String(value).trim() === '') {
                                return;
                            }

                            filters.push({
                                column: column,
                                operator: operator,
                                value: value
                            });
                        });

                        return filters;
                    }

                    function getSelectMultiValues(selectEl) {
                        if (!selectEl || !selectEl.options) {
                            return [];
                        }
                        return Array.prototype.slice.call(selectEl.options).filter(function(option) {
                            return option.selected;
                        }).map(function(option) {
                            return option.value;
                        });
                    }

                    function gatherGenerateSettings() {
                        return {
                            post_type: generatePostTypeSelect ? generatePostTypeSelect.value : 'post',
                            post_status: generatePostStatusSelect ? generatePostStatusSelect.value : 'draft',
                            author_id: generateAuthorSelect ? generateAuthorSelect.value : '0',
                            generation_language: generateLanguageInput ? generateLanguageInput.value : '',
                            pexels_enabled: 1,
                            source_video_enabled: generateSourceVideoEnabledInput && generateSourceVideoEnabledInput.checked ? 1 : 0,
                            category_ids: getSelectMultiValues(generateCategoriesSelect),
                            tags_default: getSelectMultiValues(generateTagsSelect),
                            custom_taxonomies: generateTaxonomiesTextarea ? generateTaxonomiesTextarea.value : '',
                            custom_meta: generateMetaTextarea ? generateMetaTextarea.value : ''
                        };
                    }

                    function updateGenerateTargetSummary() {
                        if (!generateAvailableCount || !generateTargetCount) {
                            return;
                        }

                        var available = currentGenerateAvailableCount === null ? 0 : parseInt(currentGenerateAvailableCount, 10) || 0;
                        var requested = 1;
                        if (generateRequestedInput) {
                            requested = Math.max(1, parseInt(generateRequestedInput.value, 10) || 1);
                        }
                        var target = Math.min(requested, available);

                        generateAvailableCount.textContent = String(available);
                        generateTargetCount.textContent = String(target);

                        if (generateCountMessage) {
                            if (!currentGenerateCountReady) {
                                generateCountMessage.textContent = 'Contagem inicial da lista. Clique em atualizar quantidade para recalcular com os filtros.';
                            } else if (available <= 0) {
                                var totalRows = currentGenerateList && currentGenerateList.counts ? (parseInt(currentGenerateList.counts.total_rows || 0, 10) || 0) : 0;
                                if (totalRows <= 0) {
                                    generateCountMessage.textContent = 'Esta lista não possui linhas válidas para gerar. A importação removeu as linhas inválidas ou a planilha não tinha URLs elegíveis.';
                                } else {
                                    generateCountMessage.textContent = 'Nenhum item elegível com estes filtros.';
                                }
                            } else {
                                generateCountMessage.textContent = 'Itens elegíveis: ' + available + '. A geração vai parar quando atingir a quantidade solicitada ou quando acabar a lista.';
                            }
                        }
                    }

                    function scheduleGenerateCountRefresh() {
                        if (generateCountRequestTimer) {
                            window.clearTimeout(generateCountRequestTimer);
                        }
                        currentGenerateCountReady = false;
                        generateCountRequestTimer = window.setTimeout(function() {
                            refreshGenerateAvailability();
                        }, 450);
                    }

                    async function refreshGenerateAvailability() {
                        if (!currentGenerateList || !currentGenerateList.id) {
                            return 0;
                        }

                        var filters = getGenerateFilters();
                        if (generateCountMessage) {
                            generateCountMessage.textContent = 'Calculando itens elegíveis...';
                        }

                        try {
                            var result = await api('/keyword-lists/' + currentGenerateList.id + '/generate', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    preview: true,
                                    filters: filters
                                })
                            });

                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Não foi possível calcular a quantidade');
                            }

                            currentGenerateAvailableCount = parseInt(result.payload.available_count || 0, 10) || 0;
                            currentGenerateCountReady = true;
                            updateGenerateTargetSummary();
                            return currentGenerateAvailableCount;
                        } catch (error) {
                            currentGenerateAvailableCount = currentGenerateAvailableCount === null ? 0 : currentGenerateAvailableCount;
                            currentGenerateCountReady = true;
                            updateGenerateTargetSummary();
                            window.alert(error.message || 'Erro ao calcular a quantidade.');
                            return currentGenerateAvailableCount;
                        }
                    }

                    function openGenerateModalWithList(listData) {
                        if (!listData) {
                            return;
                        }

                        currentGenerateList = listData;
                        currentGenerateAvailableCount = currentGenerateList && currentGenerateList.counts ? parseInt(currentGenerateList.counts.pending_rows || 0, 10) || 0 : 0;
                        currentGenerateCountReady = false;
                        if (generateModalTitle) {
                            generateModalTitle.textContent = 'Gerar em lote';
                        }
                        if (generateModalSubtitle) {
                            generateModalSubtitle.textContent = (currentGenerateList.list_name || '-') + ' · ' + (currentGenerateList.original_filename || '-');
                        }
                        if (generateListName) {
                            generateListName.textContent = currentGenerateList.list_name || '-';
                        }

                        renderGenerateFilters([{}]);
                        updateGenerateTargetSummary();
                        if (generateCountMessage) {
                            var totalRows = currentGenerateList && currentGenerateList.counts ? (parseInt(currentGenerateList.counts.total_rows || 0, 10) || 0) : 0;
                            if (totalRows <= 0) {
                                generateCountMessage.textContent = 'Esta lista ficou vazia após a limpeza de linhas inválidas. Reimporte um arquivo com URLs/slugs elegíveis para gerar.';
                            }
                        }
                        openModal(generateModal);
                        window.setTimeout(function() {
                            if (generateRequestedInput) {
                                generateRequestedInput.focus();
                            }
                        }, 100);
                        refreshGenerateAvailability();
                    }

                    async function openGenerateModal(listId) {
                        if (!listId) {
                            return;
                        }

                        if (listModal && !listModal.classList.contains('hidden')) {
                            closeModal(listModal);
                        }

                        var existing = null;
                        if (currentDetailList && String(currentDetailList.id) === String(listId)) {
                            existing = currentDetailList;
                        }

                        if (existing) {
                            openGenerateModalWithList(existing);
                            return;
                        }

                        try {
                            var result = await api('/keyword-lists/' + listId, {
                                method: 'GET'
                            });
                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Não foi possível carregar a lista');
                            }
                            openGenerateModalWithList(result.payload.list || null);
                        } catch (error) {
                            window.alert(error.message || 'Erro ao carregar a lista.');
                        }
                    }

                    async function runGenerateBatch() {
                        if (!currentGenerateList || !currentGenerateList.id) {
                            return;
                        }

                        var requested = 1;
                        if (generateRequestedInput) {
                            requested = Math.max(1, parseInt(generateRequestedInput.value, 10) || 1);
                        }

                        var filters = getGenerateFilters();
                        var settings = gatherGenerateSettings();

                        if (!currentGenerateCountReady || currentGenerateAvailableCount === null) {
                            await refreshGenerateAvailability();
                        }

                        var available = Math.max(0, parseInt(currentGenerateAvailableCount, 10) || 0);
                        var target = Math.min(requested, available);

                        if (target <= 0) {
                            window.alert('Nenhum item elegível para gerar com os filtros atuais.');
                            return;
                        }

                        var generated = 0;
                        var runLabel = generateRunButton ? generateRunButton.textContent : 'Gerar agora';
                        currentGenerateRunToken++;
                        var runToken = currentGenerateRunToken;

                        if (generateRunButton) {
                            generateRunButton.disabled = true;
                            generateRunButton.textContent = 'Gerando...';
                        }

                        try {
                            while (generated < target) {
                                if (runToken !== currentGenerateRunToken) {
                                    break;
                                }

                                var result = await api('/keyword-lists/' + currentGenerateList.id + '/generate', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json'
                                    },
                                    body: JSON.stringify({
                                        filters: filters,
                                        settings: settings
                                    })
                                });

                                if (!result.ok || !result.payload || !result.payload.success) {
                                    throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Falha ao gerar o item');
                                }

                                if (result.payload.done) {
                                    break;
                                }

                                var generatedResult = result.payload.result || {};
                                generated++;
                                currentGenerateAvailableCount = Math.max(0, (currentGenerateAvailableCount || 0) - 1);
                                updateGenerateTargetSummary();
                            }

                            if (generated <= 0 && target > 0) {
                                window.alert('Nenhum item foi gerado.');
                            }
                        } catch (error) {
                            window.alert(error.message || 'Erro ao gerar em lote.');
                        } finally {
                            if (generateRunButton) {
                                generateRunButton.disabled = false;
                                generateRunButton.textContent = runLabel || 'Gerar agora';
                            }
                        }
                    }

                    function renderPreviewTable(headers, rows) {
                        var html = [];
                        html.push('<table class="min-w-full divide-y divide-slate-200 text-sm">');
                        html.push('<thead class="bg-slate-50"><tr>');
                        (headers || []).forEach(function(header) {
                            html.push('<th class="px-4 py-3 text-left font-semibold text-slate-600">' + escapeHtml(header) + '</th>');
                        });
                        html.push('</tr></thead>');
                        html.push('<tbody class="divide-y divide-slate-100 bg-white">');
                        if (!rows || !rows.length) {
                            html.push('<tr><td colspan="' + Math.max(1, (headers || []).length) + '" class="px-4 py-6 text-center text-slate-500">Nenhuma linha disponivel.</td></tr>');
                        } else {
                            rows.forEach(function(row) {
                                html.push('<tr class="align-top">');
                                (headers || []).forEach(function(header) {
                                    html.push('<td class="max-w-[220px] px-4 py-3 text-slate-700"><div class="truncate">' + escapeHtml(row && row[header] ? row[header] : '') + '</div></td>');
                                });
                                html.push('</tr>');
                            });
                        }
                        html.push('</tbody></table>');
                        return html.join('');
                    }

                    function renderImportLogs(logs) {
                        if (!importLogsPanel || !importLogsTable) {
                            return;
                        }

                        var items = Array.isArray(logs) ? logs : [];
                        if (!items.length) {
                            importLogsTable.innerHTML = '<div class="rounded-xl border border-dashed border-rose-200 bg-white px-4 py-6 text-sm text-rose-700">Nenhum log de erro para exibir.</div>';
                            importLogsPanel.classList.add('hidden');
                            return;
                        }

                        var html = [];
                        html.push('<table class="min-w-full divide-y divide-rose-200 text-sm">');
                        html.push('<thead class="bg-rose-100/70"><tr class="text-left text-xs font-semibold uppercase tracking-wide text-rose-700">');
                        html.push('<th class="px-3 py-2">Linha</th>');
                        html.push('<th class="px-3 py-2">Código</th>');
                        html.push('<th class="px-3 py-2">Mensagem</th>');
                        html.push('</tr></thead><tbody class="divide-y divide-rose-100 bg-white">');
                        items.forEach(function(log) {
                            html.push('<tr class="align-top">');
                            html.push('<td class="px-3 py-2 text-rose-900">' + escapeHtml(log.row_number || '-') + '</td>');
                            html.push('<td class="px-3 py-2 text-rose-700">' + escapeHtml(log.code || '-') + '</td>');
                            var message = escapeHtml(log.message || '-');
                            var details = [];
                            if (log.keyword) {
                                details.push('keyword: ' + escapeHtml(log.keyword));
                            }
                            if (log.source_url) {
                                details.push('url: ' + escapeHtml(log.source_url));
                            }
                            if (log.final_slug) {
                                details.push('slug: ' + escapeHtml(log.final_slug));
                            }
                            if (details.length) {
                                message += '<div class="mt-1 text-xs text-rose-600">' + details.join(' · ') + '</div>';
                            }
                            html.push('<td class="px-3 py-2 text-rose-900">' + message + '</td>');
                            html.push('</tr>');
                        });
                        html.push('</tbody></table>');

                        importLogsTable.innerHTML = html.join('');
                        importLogsPanel.classList.remove('hidden');
                    }

                    function getColumnMapValues() {
                        return {
                            keyword_column: document.getElementById('content-rank-keyword-column-keyword') ? document.getElementById('content-rank-keyword-column-keyword').value : '',
                            source_title_column: document.getElementById('content-rank-keyword-column-title') ? document.getElementById('content-rank-keyword-column-title').value : '',
                            source_url_column: document.getElementById('content-rank-keyword-column-url') ? document.getElementById('content-rank-keyword-column-url').value : '',
                            slug_column: document.getElementById('content-rank-keyword-column-slug') ? document.getElementById('content-rank-keyword-column-slug').value : '',
                            content_column: document.getElementById('content-rank-keyword-column-content') ? document.getElementById('content-rank-keyword-column-content').value : '',
                            tags_column: document.getElementById('content-rank-keyword-column-tags') ? document.getElementById('content-rank-keyword-column-tags').value : ''
                        };
                    }

                    function setColumnMapValues(columnMap, headers) {
                        var map = columnMap || {};
                        document.getElementById('content-rank-keyword-column-keyword').innerHTML = buildSelectOptions(headers, map.keyword_column || '');
                        document.getElementById('content-rank-keyword-column-title').innerHTML = buildSelectOptions(headers, map.source_title_column || '');
                        document.getElementById('content-rank-keyword-column-url').innerHTML = buildSelectOptions(headers, map.source_url_column || '');
                        document.getElementById('content-rank-keyword-column-slug').innerHTML = buildSelectOptions(headers, map.slug_column || '');
                        document.getElementById('content-rank-keyword-column-content').innerHTML = buildSelectOptions(headers, map.content_column || '');
                        document.getElementById('content-rank-keyword-column-tags').innerHTML = buildSelectOptions(headers, map.tags_column || '');
                    }

                    function getCurrentHeaders() {
                        if (currentPreview && currentPreview.headers) {
                            return currentPreview.headers;
                        }
                        if (currentDetailList && currentDetailList.headers) {
                            return currentDetailList.headers;
                        }
                        return [];
                    }

                    function renderPreview(payload) {
                        currentPreview = payload;
                        if (!payload) {
                            previewPanel.classList.add('hidden');
                            currentUploadFile = null;
                            return;
                        }

                        var headers = payload.headers || [];
                        setColumnMapValues(payload.detected_column_map || {}, headers);
                        previewSummary.textContent = (payload.file && payload.file.name ? payload.file.name + ' · ' : '') + (payload.row_count || 0) + ' linha(s) lida(s)';
                        previewTable.innerHTML = renderPreviewTable(headers, payload.rows || []);
                        previewPanel.classList.remove('hidden');
                        openModalCount = Math.max(openModalCount, 0);
                    }

                    async function analyzeFile() {
                        var file = fileInput && fileInput.files ? fileInput.files[0] : null;
                        var listName = listNameInput ? listNameInput.value.trim() : '';

                        if (!file) {
                            setStatus(uploadStatus, 'Selecione um arquivo CSV, XLS ou XLSX.', 'error');
                            return;
                        }

                        var formData = new FormData();
                        formData.append('file', file);
                        formData.append('list_name', listName);

                        if (analyzeButton) {
                            analyzeButton.disabled = true;
                            analyzeButton.textContent = 'Analisando...';
                        }
                        setStatus(uploadStatus, 'Lendo planilha e detectando colunas...', 'warning');

                        try {
                            var result = await api('/keyword-lists/preview', {
                                method: 'POST',
                                body: formData
                            });

                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Falha ao analisar a planilha');
                            }

                            currentUploadFile = file;
                            renderPreview(result.payload);
                            setStatus(uploadStatus, 'Planilha analisada com sucesso. Ajuste os campos e importe quando estiver pronto.', 'success');
                        } catch (error) {
                            setStatus(uploadStatus, error.message || 'Erro ao analisar a planilha.', 'error');
                            previewPanel.classList.add('hidden');
                            currentPreview = null;
                            currentUploadFile = null;
                        } finally {
                            if (analyzeButton) {
                                analyzeButton.disabled = false;
                                analyzeButton.textContent = 'Analisar planilha';
                            }
                        }
                    }

                    async function importList() {
                        if (!currentUploadFile) {
                            setStatus(uploadStatus, 'Analise a planilha antes de importar.', 'error');
                            return;
                        }

                        var columnMap = getColumnMapValues();
                        var formData = new FormData();
                        formData.append('file', currentUploadFile);
                        formData.append('list_name', listNameInput ? listNameInput.value.trim() : '');
                        formData.append('column_map', JSON.stringify(columnMap));

                        if (importButton) {
                            importButton.disabled = true;
                            importButton.textContent = 'Importando...';
                        }
                        setStatus(uploadStatus, 'Importando lista...', 'warning');

                        try {
                            var result = await api('/keyword-lists', {
                                method: 'POST',
                                body: formData
                            });
                            var payload = result.payload || {};
                            var payloadLogs = Array.isArray(payload.logs) ? payload.logs : [];

                            if (payloadLogs.length) {
                                renderImportLogs(payloadLogs);
                            }

                            if (!result.ok || !payload.success) {
                                if (payloadLogs.length) {
                                    renderImportLogs(payloadLogs);
                                }
                                throw new Error(payload.message ? payload.message : 'Falha ao importar a lista');
                            }

                            var imported = payload.list || {};
                            var importedRows = imported.inserted_rows || 0;
                            var invalidRows = imported.invalid_rows || 0;
                            var duplicateRows = imported.duplicate_rows || 0;
                            var parts = [];
                            if (importedRows) {
                                parts.push(importedRows + ' linha(s) importada(s)');
                            }
                            if (invalidRows) {
                                parts.push(invalidRows + ' invalida(s) ignorada(s)');
                            }
                            if (duplicateRows) {
                                parts.push(duplicateRows + ' duplicada(s) ignorada(s)');
                            }
                            setStatus(uploadStatus, parts.length ? ('Lista importada com sucesso. ' + parts.join(', ') + '.') : 'Lista importada com sucesso.', 'success');
                            if (payloadLogs.length) {
                                renderImportLogs(payloadLogs);
                            }
                            window.setTimeout(function() {
                                window.location.reload();
                            }, 600);
                        } catch (error) {
                            setStatus(uploadStatus, error.message || 'Erro ao importar a lista.', 'error');
                        } finally {
                            if (importButton) {
                                importButton.disabled = false;
                                importButton.textContent = 'Importar lista';
                            }
                        }
                    }

                    function clearPreview() {
                        currentPreview = null;
                        currentUploadFile = null;
                        if (fileInput) {
                            fileInput.value = '';
                        }
                        if (previewPanel) {
                            previewPanel.classList.add('hidden');
                        }
                        if (previewSummary) {
                            previewSummary.textContent = '';
                        }
                        if (previewTable) {
                            previewTable.innerHTML = '';
                        }
                        setStatus(uploadStatus, '', '');
                        renderImportLogs([]);
                    }

                    function renderCounts(counts) {
                        return [
                            '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-400">Total</div><div class="mt-1 text-lg font-semibold text-slate-950">' + escapeHtml(counts.total_rows || 0) + '</div></div>',
                            '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-400">Pendentes</div><div class="mt-1 text-lg font-semibold text-slate-950">' + escapeHtml(counts.pending_rows || 0) + '</div></div>',
                            '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-400">Geradas</div><div class="mt-1 text-lg font-semibold text-slate-950">' + escapeHtml(counts.generated_rows || 0) + '</div></div>',
                            '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-400">Falhas</div><div class="mt-1 text-lg font-semibold text-slate-950">' + escapeHtml(counts.failed_rows || 0) + '</div></div>',
                            '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-400">Processando</div><div class="mt-1 text-lg font-semibold text-slate-950">' + escapeHtml(counts.processing_rows || 0) + '</div></div>',
                            '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-400">Bloqueadas</div><div class="mt-1 text-lg font-semibold text-slate-950">' + escapeHtml(counts.blocked_rows || 0) + '</div></div>',
                            '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-400">Inválidas</div><div class="mt-1 text-lg font-semibold text-slate-950">' + escapeHtml(counts.invalid_rows || 0) + '</div></div>'
                        ].join('');
                    }

                    function renderListMapping(headers, columnMap) {
                        var map = columnMap || {};
                        var parts = [];
                        parts.push('<div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Keyword</label><select id="content-rank-keyword-list-map-keyword" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildSelectOptions(headers, map.keyword_column || '') + '</select></div>');
                        parts.push('<div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Título</label><select id="content-rank-keyword-list-map-title" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildSelectOptions(headers, map.source_title_column || '') + '</select></div>');
                        parts.push('<div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">URL</label><select id="content-rank-keyword-list-map-url" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildSelectOptions(headers, map.source_url_column || '') + '</select></div>');
                        parts.push('<div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Slug final</label><select id="content-rank-keyword-list-map-slug" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildSelectOptions(headers, map.slug_column || '') + '</select></div>');
                        parts.push('<div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Conteúdo</label><select id="content-rank-keyword-list-map-content" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildSelectOptions(headers, map.content_column || '') + '</select></div>');
                        parts.push('<div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Tags</label><select id="content-rank-keyword-list-map-tags" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildSelectOptions(headers, map.tags_column || '') + '</select></div>');
                        return parts.join('');
                    }

                    function readListMap() {
                        return {
                            keyword_column: document.getElementById('content-rank-keyword-list-map-keyword') ? document.getElementById('content-rank-keyword-list-map-keyword').value : '',
                            source_title_column: document.getElementById('content-rank-keyword-list-map-title') ? document.getElementById('content-rank-keyword-list-map-title').value : '',
                            source_url_column: document.getElementById('content-rank-keyword-list-map-url') ? document.getElementById('content-rank-keyword-list-map-url').value : '',
                            slug_column: document.getElementById('content-rank-keyword-list-map-slug') ? document.getElementById('content-rank-keyword-list-map-slug').value : '',
                            content_column: document.getElementById('content-rank-keyword-list-map-content') ? document.getElementById('content-rank-keyword-list-map-content').value : '',
                            tags_column: document.getElementById('content-rank-keyword-list-map-tags') ? document.getElementById('content-rank-keyword-list-map-tags').value : ''
                        };
                    }

                    function renderListPreviewRows(headers, rows) {
                        var preparedRows = [];
                        (rows || []).forEach(function(row) {
                            if (row && row.row_data && typeof row.row_data === 'object') {
                                preparedRows.push(row.row_data);
                            } else {
                                preparedRows.push(row);
                            }
                        });
                        return renderPreviewTable(headers, preparedRows);
                    }

                    async function openListDetail(listId) {
                        if (!listId) {
                            return;
                        }

                        setStatus(listModalStatus, 'Carregando detalhes da lista...', 'warning');
                        openModal(listModal);

                        try {
                            var result = await api('/keyword-lists/' + listId, {
                                method: 'GET'
                            });
                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Não foi possível carregar a lista');
                            }

                            currentDetailList = result.payload.list || null;
                            var headers = currentDetailList && currentDetailList.headers ? currentDetailList.headers : [];
                            var columnMap = currentDetailList && currentDetailList.column_map ? currentDetailList.column_map : {};
                            var counts = currentDetailList && currentDetailList.counts ? currentDetailList.counts : {};
                            var rows = result.payload.rows || [];

                            if (listModalTitle) {
                                listModalTitle.textContent = currentDetailList ? currentDetailList.list_name : 'Detalhe da lista';
                            }
                            if (listModalSubtitle) {
                                listModalSubtitle.textContent = currentDetailList ? (currentDetailList.original_filename + ' · ' + (currentDetailList.file_type || '').toUpperCase()) : '';
                            }
                            if (listModalCounts) {
                                listModalCounts.innerHTML = renderCounts(counts);
                            }
                            if (listModalMapping) {
                                listModalMapping.innerHTML = renderListMapping(headers, columnMap);
                            }
                            if (listModalInfo) {
                                listModalInfo.innerHTML = [
                                    '<div><span class="font-medium text-slate-900">Arquivo original:</span> ' + escapeHtml(currentDetailList.original_filename || '-') + '</div>',
                                    '<div><span class="font-medium text-slate-900">Tipo:</span> ' + escapeHtml((currentDetailList.file_type || '-').toUpperCase()) + '</div>',
                                    '<div><span class="font-medium text-slate-900">Criada em:</span> ' + escapeHtml(currentDetailList.created_at || '-') + '</div>',
                                    '<div><span class="font-medium text-slate-900">Atualizada em:</span> ' + escapeHtml(currentDetailList.updated_at || '-') + '</div>',
                                    '<div><span class="font-medium text-slate-900">Colunas detectadas:</span> ' + escapeHtml((headers || []).length) + '</div>',
                                    '<div><span class="font-medium text-slate-900">Linhas na prévia:</span> ' + escapeHtml(rows.length) + '</div>'
                                ].join('');
                            }
                            if (listModalPreview) {
                                listModalPreview.innerHTML = renderListPreviewRows(headers, rows);
                            }
                            setStatus(listModalStatus, '', '');
                            openModal(listModal);
                        } catch (error) {
                            setStatus(listModalStatus, error.message || 'Erro ao carregar lista.', 'error');
                        }
                    }

                    async function saveCurrentListMap() {
                        if (!currentDetailList) {
                            return;
                        }

                        var columnMap = readListMap();
                        setStatus(listModalStatus, 'Salvando mapeamento...', 'warning');

                        try {
                            var result = await api('/keyword-lists/' + currentDetailList.id + '/columns', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    column_map: columnMap
                                })
                            });

                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Não foi possível salvar o mapeamento');
                            }

                            setStatus(listModalStatus, 'Mapeamento salvo com sucesso.', 'success');
                            window.setTimeout(function() {
                                window.location.reload();
                            }, 600);
                        } catch (error) {
                            setStatus(listModalStatus, error.message || 'Erro ao salvar o mapeamento.', 'error');
                        }
                    }

                    async function deleteListById(listId, statusTarget) {
                        if (!listId) {
                            return;
                        }
                        if (window.ContentRankGeneratorSwal && typeof window.ContentRankGeneratorSwal.confirm === 'function') {
                            var confirmed = await window.ContentRankGeneratorSwal.confirm('Excluir esta lista e todas as linhas importadas?', {
                                title: 'Confirmacao'
                            });
                            if (!confirmed) {
                                return;
                            }
                        } else if (!window.confirm('Excluir esta lista e todas as linhas importadas?')) {
                            return;
                        }

                        if (statusTarget) {
                            setStatus(statusTarget, 'Excluindo lista...', 'warning');
                        }

                        try {
                            var result = await api('/keyword-lists/' + listId, {
                                method: 'DELETE'
                            });
                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Não foi possível excluir a lista');
                            }
                            window.location.reload();
                        } catch (error) {
                            if (statusTarget) {
                                setStatus(statusTarget, error.message || 'Erro ao excluir a lista.', 'error');
                            } else {
                                if (window.ContentRankGeneratorSwal && typeof window.ContentRankGeneratorSwal.error === 'function') {
                                    window.ContentRankGeneratorSwal.error(error.message || 'Erro ao excluir a lista.', 'Erro');
                                } else {
                                    window.alert(error.message || 'Erro ao excluir a lista.');
                                }
                            }
                        }
                    }

                    if (analyzeButton) {
                        analyzeButton.addEventListener('click', analyzeFile);
                    }

                    if (manualListButton) {
                        manualListButton.addEventListener('click', createManualKeywordList);
                    }

                    sourceTabButtons.forEach(function(button) {
                        button.addEventListener('click', function() {
                            if (String(button.getAttribute('data-keyword-source-tab') || '') === 'spreadsheet') {
                                resetManualKeywordEditor();
                            }
                            setKeywordSourceTab(button.getAttribute('data-keyword-source-tab') || 'spreadsheet');
                        });
                    });

                    if (importButton) {
                        importButton.addEventListener('click', importList);
                    }

                    if (clearButton) {
                        clearButton.addEventListener('click', clearPreview);
                    }

                    if (clearImportLogsButton) {
                        clearImportLogsButton.addEventListener('click', function() {
                            renderImportLogs([]);
                            setStatus(uploadStatus, '', '');
                        });
                    }

                    if (resetPreviewButton) {
                        resetPreviewButton.addEventListener('click', clearPreview);
                    }

                    if (refreshButton) {
                        refreshButton.addEventListener('click', function() {
                            window.location.reload();
                        });
                    }

                    document.querySelectorAll('[data-open-keyword-list-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            openListDetail(button.getAttribute('data-list-id'));
                        });
                    });

                    document.querySelectorAll('[data-edit-keyword-list]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            openManualKeywordEditor(button.getAttribute('data-list-id'));
                        });
                    });

                    openImportButtons.forEach(function(button) {
                        button.addEventListener('click', function() {
                            resetManualKeywordEditor();
                            setKeywordSourceTab('spreadsheet');
                            openModal(importModal);
                        });
                    });

                    document.querySelectorAll('[data-open-keyword-generate-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            openGenerateModal(button.getAttribute('data-list-id'));
                        });
                    });

                    document.querySelectorAll('[data-close-keyword-list-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            closeModal(listModal);
                        });
                    });

                    closeImportButtons.forEach(function(button) {
                        button.addEventListener('click', function() {
                            closeModal(importModal);
                        });
                    });

                    if (listBackdrop) {
                        listBackdrop.addEventListener('click', function() {
                            closeModal(listModal);
                        });
                    }

                    if (importBackdrop) {
                        importBackdrop.addEventListener('click', function() {
                            closeModal(importModal);
                        });
                    }

                    if (saveMapButton) {
                        saveMapButton.addEventListener('click', saveCurrentListMap);
                    }

                    if (deleteCurrentListButton) {
                        deleteCurrentListButton.addEventListener('click', function() {
                            if (currentDetailList) {
                                deleteListById(currentDetailList.id, listModalStatus);
                            }
                        });
                    }

                    if (openGenerateFromListButton) {
                        openGenerateFromListButton.addEventListener('click', function() {
                            if (currentDetailList) {
                                closeModal(listModal);
                                openGenerateModal(currentDetailList.id);
                            }
                        });
                    }

                    if (generateBackdrop) {
                        generateBackdrop.addEventListener('click', function() {
                            closeModal(generateModal);
                        });
                    }

                    generateCancelButtons.forEach(function(button) {
                        button.addEventListener('click', function() {
                            closeModal(generateModal);
                        });
                    });

                    if (generateAddFilterButton) {
                        generateAddFilterButton.addEventListener('click', function() {
                            if (!generateFiltersContainer) {
                                return;
                            }
                            generateFiltersContainer.insertAdjacentHTML('beforeend', renderGenerateFilterRow({}));
                            updateGenerateTargetSummary();
                            scheduleGenerateCountRefresh();
                        });
                    }

                    if (generateFiltersContainer) {
                        generateFiltersContainer.addEventListener('click', function(event) {
                            var button = event.target.closest('[data-remove-filter]');
                            if (!button) {
                                return;
                            }
                            var row = button.closest('[data-generate-filter-row]');
                            if (row) {
                                row.remove();
                            }
                            if (generateFiltersContainer.querySelectorAll('[data-generate-filter-row]').length === 0) {
                                generateFiltersContainer.innerHTML = renderGenerateFilterRow({});
                            }
                            updateGenerateTargetSummary();
                            scheduleGenerateCountRefresh();
                        });
                        generateFiltersContainer.addEventListener('input', function() {
                            updateGenerateTargetSummary();
                            scheduleGenerateCountRefresh();
                        });
                        generateFiltersContainer.addEventListener('change', function() {
                            updateGenerateTargetSummary();
                            scheduleGenerateCountRefresh();
                        });
                    }

                    if (generateRequestedInput) {
                        generateRequestedInput.addEventListener('input', function() {
                            updateGenerateTargetSummary();
                        });
                        generateRequestedInput.addEventListener('change', function() {
                            updateGenerateTargetSummary();
                        });
                    }

                    if (generateRefreshCountButton) {
                        generateRefreshCountButton.addEventListener('click', function() {
                            refreshGenerateAvailability();
                        });
                    }

                    if (generateRunButton) {
                        generateRunButton.addEventListener('click', runGenerateBatch);
                    }

                    if (generateRunCtaButton) {
                        generateRunCtaButton.addEventListener('click', runGenerateBatch);
                    }

                    document.querySelectorAll('[data-delete-keyword-list-id]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            deleteListById(button.getAttribute('data-delete-keyword-list-id'));
                        });
                    });

                    if (fileInput) {
                        fileInput.addEventListener('change', function() {
                            currentPreview = null;
                            currentUploadFile = null;
                            if (previewPanel) {
                                previewPanel.classList.add('hidden');
                            }
                            if (previewSummary) {
                                previewSummary.textContent = '';
                            }
                            if (previewTable) {
                                previewTable.innerHTML = '';
                            }
                            setStatus(uploadStatus, '', '');
                        });
                    }

                    document.addEventListener('keydown', function(event) {
                        if (event.key === 'Escape') {
                            if (importModal && !importModal.classList.contains('hidden')) {
                                closeModal(importModal);
                                return;
                            }
                            if (generateModal && !generateModal.classList.contains('hidden')) {
                                closeModal(generateModal);
                                return;
                            }
                            if (listModal && !listModal.classList.contains('hidden')) {
                                closeModal(listModal);
                            }
                        }
                    });
                })();
            </script>
        </div>
<?php
    }

    public static function render_notice()
    {
        if (empty($_GET['content_rank_notice'])) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (in_array($page, array('content-rank-generated-posts', 'content-rank-link-suggestions', 'content-rank-content-plans'), true)) {
            return;
        }

        $type = isset($_GET['content_rank_notice_type']) ? sanitize_key(wp_unslash($_GET['content_rank_notice_type'])) : 'success';
        $class = 'notice notice-' . ($type === 'error' ? 'error' : 'success');
        $message = sanitize_text_field(wp_unslash($_GET['content_rank_notice']));
        $link = isset($_GET['content_rank_notice_link']) ? esc_url_raw(wp_unslash($_GET['content_rank_notice_link'])) : '';

        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message);
        if ($link !== '' && $type !== 'error') {
            echo ' <a href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer" class="ml-2 inline-flex items-center rounded-md border border-current/20 px-2 py-0.5 text-xs font-semibold text-inherit no-underline">Abrir conteúdo</a>';
        }
        echo '</p></div>';
    }

    public static function get_post_status_label($status)
    {
        $map = array(
            'draft' => 'Rascunho',
            'publish' => 'Publicado',
            'pending' => 'Pendente',
            'private' => 'Privado',
            'future' => 'Agendado',
        );
        return isset($map[$status]) ? $map[$status] : ucfirst((string) $status);
    }
}
