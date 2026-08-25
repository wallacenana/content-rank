<?php
/*
Plugin Name: Content Rank
Description: Geradores RSS com reescrita com IA, imagens do Pexels, SEO, execucoes manuais e agendamento aleatorio.
Version: 1.9.56
Author: Wallace Tavares e Codex
Plugin URI: https://content-rank.com/
License: GPLv2 or later
Text Domain: content-rank
*/

if (!defined('ABSPATH')) {
    exit;
}

if (!ob_get_level()) {
    // Keep redirects safe even if an include emits stray output.
    // Also strip invisible UTF-8 BOM bytes that can leak into the frontend HTML.
    ob_start(static function ($buffer) {
        if ($buffer === '') {
            return $buffer;
        }

        return str_replace("\xEF\xBB\xBF", '', $buffer);
    });
}

if (!defined('CONTENT_RANK_GENERATOR_PLUGIN_FILE')) {
    define('CONTENT_RANK_GENERATOR_PLUGIN_FILE', __FILE__);
}
if (!defined('CONTENT_RANK_GENERATOR_PLUGIN_DIR')) {
    define('CONTENT_RANK_GENERATOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('CONTENT_RANK_GENERATOR_UPDATE_ENABLED')) {
    define('CONTENT_RANK_GENERATOR_UPDATE_ENABLED', true);
}
if (!defined('CONTENT_RANK_GENERATOR_UPDATE_MANIFEST_URL')) {
    define('CONTENT_RANK_GENERATOR_UPDATE_MANIFEST_URL', 'https://raw.githubusercontent.com/wallacenana/content-rank/main/update.json?v=1.9.56');
}

$content_rank_autoload_file = CONTENT_RANK_GENERATOR_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($content_rank_autoload_file)) {
    require_once $content_rank_autoload_file;
}

require_once __DIR__ . '/plugin.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/tmdb.php';
require_once __DIR__ . '/includes/global-filters.php';
require_once __DIR__ . '/includes/thumbnail-helper.php';
require_once __DIR__ . '/includes/pexels-media.php';
require_once __DIR__ . '/includes/generated-posts.php';
require_once __DIR__ . '/includes/content-plans.php';
require_once __DIR__ . '/includes/link-suggestions.php';
require_once __DIR__ . '/includes/related-posts.php';
require_once __DIR__ . '/includes/prompt-settings.php';
require_once __DIR__ . '/includes/updater.php';
require_once __DIR__ . '/includes/review-builder.php';
require_once __DIR__ . '/includes/license-client.php';

if (!class_exists('Content_Rank_Generator')) {
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    final class Content_Rank_Generator
    {
        const VERSION = '1.9.56';
        const DB_VERSION = '1.8.5';
        const FEATURED_IMAGE_MIN_WIDTH = 1200;
        const FEATURED_IMAGE_MIN_HEIGHT = 675;
        const FEATURED_IMAGE_TARGET_RATIO = 1.7777777778;
        const FEATURED_IMAGE_RATIO_TOLERANCE = 0.12;
        const CRON_HOOK = 'content_rank_tick';
        const STAGED_GENERATION_HOOK = 'content_rank_generation_stage';
        const GENERATION_PIPELINE_META = '_content_rank_generation_pipeline';
        const OPTION_KEY = 'content_rank_settings';
        const OPTION_KEY_PROMPT_MODELS = 'content_rank_prompt_models';
        const OPTION_KEY_OUTLINE_MODELS = 'content_rank_outline_models';
        const TABLE_SUFFIX_GENERATORS = 'content_rank_generators';
        const TABLE_SUFFIX_RUNS = 'content_rank_runs';
        const TABLE_SUFFIX_ITEMS = 'content_rank_items';
        const TABLE_SUFFIX_LISTS = 'content_rank_keyword_lists';
        const TABLE_SUFFIX_LIST_ROWS = 'content_rank_keyword_list_rows';
        const TABLE_SUFFIX_IMPORT_LOGS = 'content_rank_keyword_import_logs';

        private static $instance = null;
        public static $table_generators;
        public static $table_runs;
        public static $table_items;
        public static $table_lists;
        public static $table_list_rows;
        public static $table_import_logs;
        public static $last_openai_response_id = '';
        private static $staged_generation_request_processed = false;

        public static function instance()
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct()
        {
            global $wpdb;
            self::$table_generators = $wpdb->prefix . self::TABLE_SUFFIX_GENERATORS;
            self::$table_runs = $wpdb->prefix . self::TABLE_SUFFIX_RUNS;
            self::$table_items = $wpdb->prefix . self::TABLE_SUFFIX_ITEMS;
            self::$table_lists = $wpdb->prefix . self::TABLE_SUFFIX_LISTS;
            self::$table_list_rows = $wpdb->prefix . self::TABLE_SUFFIX_LIST_ROWS;
            self::$table_import_logs = $wpdb->prefix . self::TABLE_SUFFIX_IMPORT_LOGS;
        }

        public function admin_menu()
        {
            if (class_exists('Content_Rank_Generator_Admin')) {
                (new Content_Rank_Generator_Admin())->admin_menu();
            }
        }

        public function register_rest_routes()
        {
            if (class_exists('Content_Rank_Generator_REST')) {
                (new Content_Rank_Generator_REST())->register_rest_routes();
            }
        }

        public static function activate()
        {
            self::instance()->maybe_upgrade_schema();
            self::instance()->ensure_cron_scheduled();

            $settings = get_option(self::OPTION_KEY, array());
            if (!is_array($settings)) {
                $settings = array();
            }
            $settings = array_merge(self::default_settings(), $settings);
            update_option(self::OPTION_KEY, $settings, false);

            if (class_exists('Content_Rank_Related_Posts')) {
                $related_defaults = Content_Rank_Related_Posts::get_default_settings();
                $related_settings = get_option(Content_Rank_Related_Posts::OPTION_KEY, array());
                if (!is_array($related_settings)) {
                    $related_settings = array();
                }
                update_option(Content_Rank_Related_Posts::OPTION_KEY, array_merge($related_defaults, $related_settings), false);
            }

            if (class_exists('Content_Rank_Outline_Models')) {
                $outline_defaults = Content_Rank_Outline_Models::get_default_models();
                $outline_settings = get_option(self::OPTION_KEY_OUTLINE_MODELS, array());
                if (!is_array($outline_settings) || empty($outline_settings)) {
                    update_option(self::OPTION_KEY_OUTLINE_MODELS, $outline_defaults, false);
                }
            }

            if (class_exists('Content_Rank_Prompt_Settings')) {
                Content_Rank_Prompt_Settings::maybe_migrate_prompt_settings();
            }

            self::instance()->seed_next_run_for_active_generators();
        }

        public static function deactivate()
        {
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
            while ($timestamp) {
                wp_unschedule_event($timestamp, self::CRON_HOOK);
                $timestamp = wp_next_scheduled(self::CRON_HOOK);
            }
        }

        public function boot()
        {
            self::maybe_upgrade_schema();
            if (class_exists('Content_Rank_Generator_Updater')) {
                new Content_Rank_Generator_Updater(CONTENT_RANK_GENERATOR_PLUGIN_FILE);
            }
            if (class_exists('Content_Rank_Pexels_Media')) {
                new Content_Rank_Pexels_Media();
            }
            if (class_exists('Content_Rank_Related_Posts')) {
                new Content_Rank_Related_Posts();
            }
            if (class_exists('Content_Rank_Outline_Models')) {
                new Content_Rank_Outline_Models();
            }
            if (class_exists('Content_Rank_Prompt_Settings')) {
                Content_Rank_Prompt_Settings::maybe_migrate_prompt_settings();
                new Content_Rank_Prompt_Settings();
            }
            if (class_exists('Content_Rank_Generated_Posts')) {
                new Content_Rank_Generated_Posts();
            }
            if (class_exists('Content_Rank_Link_Suggestions')) {
                new Content_Rank_Link_Suggestions();
            }
            if (class_exists('Content_Rank_Content_Plans')) {
                new Content_Rank_Content_Plans();
            }
            if (class_exists('Content_Rank_Review_Builder')) {
                new Content_Rank_Review_Builder();
            }
            if (class_exists('Content_Rank_License_Client')) {
                Content_Rank_License_Client::init();
            }
            add_action('admin_menu', array($this, 'admin_menu'));
            add_action('admin_menu', array(new Content_Rank_Generator_Admin(), 'admin_menu_late'), 999);
            add_action('admin_init', array($this, 'resume_pending_staged_generations'));
            add_action('admin_footer', array($this, 'render_staged_generation_toast'));
            add_action('admin_post_content_rank_save_settings', array($this, 'handle_save_settings'));
            add_action('admin_post_content_rank_save_generator', array($this, 'handle_save_generator'));
            add_action('admin_post_content_rank_delete_generator', array($this, 'handle_delete_generator'));
            add_action('admin_post_content_rank_run_generator', array($this, 'handle_run_generator'));
            add_action('admin_post_content_rank_duplicate_generator', array($this, 'handle_duplicate_generator'));
            add_action('admin_post_content_rank_export_generator', array($this, 'handle_export_generator'));
            add_action('admin_post_content_rank_export_generators', array($this, 'handle_export_generators'));
            add_action('admin_post_content_rank_import_generators', array($this, 'handle_import_generators'));
            add_action('trashed_post', array($this, 'handle_generated_post_deleted'), 10, 1);
            add_action('before_delete_post', array($this, 'handle_generated_post_deleted'), 10, 1);
            add_action(self::CRON_HOOK, array($this, 'cron_tick'));
            add_action(self::STAGED_GENERATION_HOOK, array($this, 'process_staged_generation'), 10, 1);
            add_action('wp_ajax_content_rank_process_staged_generation', array($this, 'handle_async_staged_generation'));
            add_action('wp_ajax_nopriv_content_rank_process_staged_generation', array($this, 'handle_async_staged_generation'));
            add_action('wp_ajax_content_rank_get_staged_generation_status', array($this, 'handle_staged_generation_status'));
            add_filter('cron_schedules', array($this, 'add_cron_schedule'));
            add_filter('pre_reschedule_event', array($this, 'avoid_duplicate_cron_reschedule'), 10, 3);
            add_action('rest_api_init', array($this, 'register_rest_routes'));
            add_action('init', array($this, 'ensure_cron_scheduled'));
            self::normalize_active_generator_next_runs();
        }

        public function add_cron_schedule($schedules)
        {
            if (!isset($schedules['alpha_five_minutes'])) {
                $schedules['alpha_five_minutes'] = array(
                    'interval' => 300,
                    'display'  => 'A cada 5 minutos',
                );
            }
            return $schedules;
        }

        protected static function migrate_legacy_storage()
        {
            global $wpdb;

            // Version this migration separately so an earlier partial run can be repaired.
            $migration_option = 'content_rank_legacy_storage_migrated_v2';
            if (get_option($migration_option, '') === self::DB_VERSION) {
                return;
            }

            $option_pairs = array(
                'alpha_rss_ai_settings' => self::OPTION_KEY,
                'alpha_rss_ai_prompt_models' => self::OPTION_KEY_PROMPT_MODELS,
                'alpha_rss_ai_outline_models' => self::OPTION_KEY_OUTLINE_MODELS,
                'alpha_rss_ai_related_posts_settings' => 'content_rank_related_posts_settings',
                'alpha_rss_ai_db_version' => 'content_rank_db_version',
            );

            foreach ($option_pairs as $legacy_key => $current_key) {
                $legacy_value = get_option($legacy_key, null);
                if ($legacy_value === null) {
                    continue;
                }

                // The old installation is the source of truth during this one-time rebrand.
                update_option($current_key, $legacy_value, false);
            }

            $table_pairs = array(
                $wpdb->prefix . 'arc_generators' => self::$table_generators,
                $wpdb->prefix . 'arc_runs' => self::$table_runs,
                $wpdb->prefix . 'arc_items' => self::$table_items,
                $wpdb->prefix . 'arc_keyword_lists' => self::$table_lists,
                $wpdb->prefix . 'arc_keyword_list_rows' => self::$table_list_rows,
                $wpdb->prefix . 'arc_keyword_import_logs' => self::$table_import_logs,
            );

            $migration_failed = false;

            foreach ($table_pairs as $legacy_table => $current_table) {
                if ($legacy_table === $current_table) {
                    continue;
                }

                $legacy_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($legacy_table)));
                if ($legacy_exists !== $legacy_table) {
                    continue;
                }

                $current_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($current_table)));
                if ($current_exists !== $current_table) {
                    if (false === $wpdb->query("CREATE TABLE `{$current_table}` LIKE `{$legacy_table}`")) {
                        $migration_failed = true;
                        continue;
                    }
                }

                $legacy_columns = $wpdb->get_col("SHOW COLUMNS FROM `{$legacy_table}`", 0);
                $current_columns = $wpdb->get_col("SHOW COLUMNS FROM `{$current_table}`", 0);
                $columns = array_values(array_intersect($legacy_columns, $current_columns));
                if (empty($columns)) {
                    $migration_failed = true;
                    continue;
                }

                $column_sql = implode(', ', array_map(function ($column) {
                    return '`' . str_replace('`', '``', $column) . '`';
                }, $columns));
                if (false === $wpdb->query("INSERT IGNORE INTO `{$current_table}` ({$column_sql}) SELECT {$column_sql} FROM `{$legacy_table}`")) {
                    $migration_failed = true;
                }
            }

            $legacy_meta_keys = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_key, meta_value FROM `{$wpdb->postmeta}` WHERE meta_key LIKE %s",
                    $wpdb->esc_like('_arc_') . '%'
                ),
                ARRAY_A
            );
            foreach ($legacy_meta_keys as $legacy_meta) {
                $post_id = intval($legacy_meta['post_id']);
                $legacy_meta_key = (string) $legacy_meta['meta_key'];
                $current_meta_key = str_replace('_arc_', '_content_rank_', $legacy_meta_key);
                if ($post_id < 1 || $current_meta_key === $legacy_meta_key || metadata_exists('post', $post_id, $current_meta_key)) {
                    continue;
                }

                add_post_meta($post_id, $current_meta_key, $legacy_meta['meta_value']);
            }

            $legacy_cron = 'alpha_rss_ai_generator_tick';
            while ($timestamp = wp_next_scheduled($legacy_cron)) {
                wp_unschedule_event($timestamp, $legacy_cron);
            }
            if (function_exists('wp_unschedule_hook')) {
                wp_unschedule_hook('alpha_rss_ai_generator_generation_stage');
            }

            if (!$migration_failed) {
                // Keep the old tables as a rollback source; the new names are now authoritative.
                update_option($migration_option, self::DB_VERSION, false);
            }
        }

        public static function maybe_upgrade_schema()
        {
            global $wpdb;

            self::migrate_legacy_storage();
            $stored_version = get_option('content_rank_db_version', '0');
            $needs_create_tables = false;

            $required_tables = array(
                self::$table_generators,
                self::$table_runs,
                self::$table_items,
                self::$table_lists,
                self::$table_list_rows,
                self::$table_import_logs,
            );

            foreach ($required_tables as $table_name) {
                if (empty($table_name)) {
                    $needs_create_tables = true;
                    break;
                }

                $found_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name)));
                if ($found_table !== $table_name) {
                    $needs_create_tables = true;
                    break;
                }
            }

            if (!$needs_create_tables && !empty(self::$table_generators)) {
                $required_generator_columns = array(
                    'generation_mode',
                    'source_post_id',
                    'default_category_id',
                    'prompt_models_json',
                    'prompt_model_key',
                    'internal_links_count',
                    'content_image_interval_words',
                    'random_bolds_enabled',
                    'tmdb_title_translation_enabled',
                    'tmdb_thumbnail_bg_color',
                );
                foreach ($required_generator_columns as $column_name) {
                    $found_column = $wpdb->get_var($wpdb->prepare(
                        "SHOW COLUMNS FROM `" . self::$table_generators . "` LIKE %s",
                        $column_name
                    ));
                    if (empty($found_column)) {
                        $needs_create_tables = true;
                        break;
                    }
                }
            }

            if ($needs_create_tables) {
                self::create_tables();
                update_option('content_rank_db_version', self::DB_VERSION, false);
                return;
            }

            self::maybe_upgrade_items_schema_columns();
            self::maybe_upgrade_generators_schema_columns();

            if ($stored_version !== self::DB_VERSION) {
                update_option('content_rank_db_version', self::DB_VERSION, false);
            }
        }

        protected static function maybe_upgrade_items_schema_columns()
        {
            global $wpdb;

            if (empty(self::$table_items)) {
                return;
            }

            $columns_to_check = array(
                'title_embedding_model' => 'varchar(120) DEFAULT NULL',
                'title_embedding_json' => 'longtext DEFAULT NULL',
                'semantic_duplicate_post_id' => 'bigint(20) unsigned DEFAULT NULL',
                'semantic_duplicate_score' => 'decimal(6,4) DEFAULT NULL',
                'semantic_duplicate_method' => 'varchar(40) DEFAULT NULL',
                'item_status' => "varchar(20) NOT NULL DEFAULT 'processing'",
            );

            foreach ($columns_to_check as $column_name => $column_definition) {
                $found_column = $wpdb->get_var($wpdb->prepare(
                    "SHOW COLUMNS FROM `" . self::$table_items . "` LIKE %s",
                    $column_name
                ));
                if (!empty($found_column)) {
                    continue;
                }

                $wpdb->query("ALTER TABLE `" . self::$table_items . "` ADD COLUMN `" . $column_name . "` " . $column_definition);
            }
        }

        protected static function maybe_upgrade_generators_schema_columns()
        {
            global $wpdb;

            if (empty(self::$table_generators)) {
                return;
            }

            $columns_to_check = array(
                'tavily_enabled' => array(
                    'definition' => 'tinyint(1) NOT NULL DEFAULT 0',
                    'after' => 'keyword_list_mode',
                ),
                'content_image_interval_words' => array(
                    'definition' => 'int(11) NOT NULL DEFAULT 500',
                    'after' => 'content_image_size',
                ),
                'tmdb_title_translation_enabled' => array(
                    'definition' => 'tinyint(1) NOT NULL DEFAULT 0',
                    'after' => 'keyword_list_mode',
                ),
                'tmdb_thumbnail_bg_color' => array(
                    'definition' => "varchar(7) NOT NULL DEFAULT '#c91414'",
                    'after' => 'tmdb_title_translation_enabled',
                ),
            );

            foreach ($columns_to_check as $column_name => $column_data) {
                $found_column = $wpdb->get_var($wpdb->prepare(
                    "SHOW COLUMNS FROM `" . self::$table_generators . "` LIKE %s",
                    $column_name
                ));
                if (!empty($found_column)) {
                    continue;
                }

                $wpdb->query(
                    "ALTER TABLE `" . self::$table_generators . "` ADD COLUMN `" . $column_name . "` " . $column_data['definition'] . " AFTER `" . $column_data['after'] . "`"
                );
            }
        }

        public function ensure_cron_scheduled()
        {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + 300, 'alpha_five_minutes', self::CRON_HOOK);
            }
        }

        public function avoid_duplicate_cron_reschedule($pre, $event, $wp_error)
        {
            if ($pre !== null || !is_object($event) || empty($event->hook) || $event->hook !== self::CRON_HOOK) {
                return $pre;
            }

            $timestamp = isset($event->timestamp) ? absint($event->timestamp) : 0;
            if ($timestamp <= 0) {
                return $pre;
            }

            // Another concurrent cron request may have saved the next event
            // already. Treat that state as success instead of logging a false
            // could_not_set error from update_option().
            $scheduled_event = wp_get_scheduled_event(
                self::CRON_HOOK,
                isset($event->args) && is_array($event->args) ? $event->args : array(),
                $timestamp
            );
            if ($scheduled_event) {
                return true;
            }

            return $pre;
        }

        public function maybe_process_pending_jobs()
        {
            self::process_pending_jobs();
        }

        public static function process_pending_jobs()
        {
            if (get_transient('content_rank_pending_jobs_lock')) {
                return;
            }

            set_transient('content_rank_pending_jobs_lock', 1, 3 * MINUTE_IN_SECONDS);
            self::normalize_active_generator_next_runs();
            self::process_due_generators();
            self::process_due_generated_future_posts(1);
            delete_transient('content_rank_pending_jobs_lock');
        }

        public static function get_generator_daily_window($generator, $base_timestamp = null)
        {
            $generator = is_array($generator) ? $generator : array();
            $raw_start = isset($generator['daily_start']) ? trim((string) $generator['daily_start']) : '';
            $raw_end = isset($generator['daily_end']) ? trim((string) $generator['daily_end']) : '';
            if ($raw_start === '' || $raw_end === '') {
                return array(0, 0);
            }

            $start_seconds = self::parse_time_to_seconds($raw_start);
            $end_seconds = self::parse_time_to_seconds($raw_end);
            if ($end_seconds <= $start_seconds) {
                return array(0, 0);
            }

            $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
            $reference_timestamp = $base_timestamp ? intval($base_timestamp) : time();
            $day_start_value = wp_date('Y-m-d 00:00:00', $reference_timestamp, $timezone);
            $day_start_dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $day_start_value, $timezone);
            $day_start = $day_start_dt instanceof DateTimeImmutable ? $day_start_dt->getTimestamp() : strtotime($day_start_value);

            return array(
                max(0, intval($day_start) + $start_seconds),
                max(0, intval($day_start) + $end_seconds),
            );
        }

        public static function create_tables()
        {
            global $wpdb;
            if (!function_exists('dbDelta')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }

            $charset_collate = $wpdb->get_charset_collate();

            $sql_generators = "CREATE TABLE " . self::$table_generators . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(255) NOT NULL,
                feed_url text NOT NULL,
                source_type varchar(20) NOT NULL DEFAULT 'rss',
                generation_mode varchar(20) NOT NULL DEFAULT 'pillar',
                source_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
                list_id bigint(20) unsigned NOT NULL DEFAULT 0,
                keyword_list_mode varchar(20) NOT NULL DEFAULT 'keywords',
                tavily_enabled tinyint(1) NOT NULL DEFAULT 0,
                tmdb_title_translation_enabled tinyint(1) NOT NULL DEFAULT 0,
                tmdb_thumbnail_bg_color varchar(7) NOT NULL DEFAULT '#c91414',
                status varchar(20) NOT NULL DEFAULT 'active',
                post_type varchar(60) NOT NULL DEFAULT 'post',
                post_status varchar(20) NOT NULL DEFAULT 'draft',
                author_id bigint(20) unsigned NOT NULL DEFAULT 0,
                category_ids longtext DEFAULT NULL,
                default_category_id bigint(20) unsigned NOT NULL DEFAULT 0,
                tags_default longtext DEFAULT NULL,
                custom_taxonomies longtext DEFAULT NULL,
                custom_meta longtext DEFAULT NULL,
                filters_json longtext DEFAULT NULL,
                model varchar(120) NOT NULL DEFAULT 'gpt-4.1-mini',
                temperature decimal(4,2) NOT NULL DEFAULT 0.70,
                max_tokens int(11) NOT NULL DEFAULT 3000,
                content_length_class varchar(20) NOT NULL DEFAULT 'medium',
                posts_per_run int(11) NOT NULL DEFAULT 1,
                schedule_type varchar(20) NOT NULL DEFAULT 'interval',
                interval_minutes int(11) NOT NULL DEFAULT 180,
                jitter_minutes int(11) NOT NULL DEFAULT 30,
                daily_start varchar(5) NOT NULL DEFAULT '',
                daily_end varchar(5) NOT NULL DEFAULT '',
                image_source_mode varchar(30) NOT NULL DEFAULT 'rss_or_pexels',
                pexels_enabled tinyint(1) NOT NULL DEFAULT 1,
                pexels_query varchar(255) NOT NULL DEFAULT '{{pexels_tags}}',
                source_video_enabled tinyint(1) NOT NULL DEFAULT 0,
                source_content_images_enabled tinyint(1) NOT NULL DEFAULT 1,
                source_content_links_enabled tinyint(1) NOT NULL DEFAULT 1,
                video_selector_class varchar(255) NOT NULL DEFAULT '',
                image_selector_class varchar(255) NOT NULL DEFAULT '',
                link_selector_class varchar(255) NOT NULL DEFAULT '',
                content_selector varchar(255) NOT NULL DEFAULT '',
                content_image_size varchar(20) NOT NULL DEFAULT 'medium',
                content_image_interval_words int(11) NOT NULL DEFAULT 500,
                random_bolds_enabled tinyint(1) NOT NULL DEFAULT 0,
                seo_enabled tinyint(1) NOT NULL DEFAULT 1,
                generation_language varchar(80) NOT NULL DEFAULT 'Português do Brasil',
                prompt_template longtext DEFAULT NULL,
                content_prompt_template longtext DEFAULT NULL,
                prompt_models_json longtext DEFAULT NULL,
                prompt_model_key varchar(120) NOT NULL DEFAULT '',
                outline_model_key varchar(120) NOT NULL DEFAULT '',
                related_posts_enabled tinyint(1) NOT NULL DEFAULT 0,
                related_posts_position varchar(20) NOT NULL DEFAULT 'end',
                related_posts_interval int(11) NOT NULL DEFAULT 4,
                related_posts_min_h2 int(11) NOT NULL DEFAULT 1,
                related_posts_links_per_block int(11) NOT NULL DEFAULT 2,
                related_posts_same_category_only tinyint(1) NOT NULL DEFAULT 1,
                related_posts_allow_fallback tinyint(1) NOT NULL DEFAULT 1,
                related_posts_style varchar(20) NOT NULL DEFAULT 'list',
                related_posts_phrases longtext DEFAULT NULL,
                internal_links_count int(11) NOT NULL DEFAULT 0,
                internal_links_json longtext DEFAULT NULL,
                source_link_phrases longtext DEFAULT NULL,
                source_context_filters_json longtext DEFAULT NULL,
                last_run_at datetime DEFAULT NULL,
                next_run_at datetime DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY status_next_run (status, next_run_at)
            ) {$charset_collate};";

            $sql_lists = "CREATE TABLE " . self::$table_lists . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                list_name varchar(255) NOT NULL,
                original_filename varchar(255) NOT NULL,
                file_path text DEFAULT NULL,
                file_type varchar(20) NOT NULL,
                headers_json longtext DEFAULT NULL,
                column_map_json longtext DEFAULT NULL,
                total_rows int(11) DEFAULT 0,
                generated_rows int(11) DEFAULT 0,
                pending_rows int(11) DEFAULT 0,
                invalid_rows int(11) DEFAULT 0,
                failed_rows int(11) DEFAULT 0,
                status varchar(20) NOT NULL DEFAULT 'active',
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) {$charset_collate};";

            $sql_list_rows = "CREATE TABLE " . self::$table_list_rows . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                list_id bigint(20) unsigned NOT NULL,
                `row_number` int(11) NOT NULL,
                row_data longtext NOT NULL,
                keyword varchar(255) DEFAULT NULL,
                source_title text DEFAULT NULL,
                source_url text DEFAULT NULL,
                final_slug varchar(255) DEFAULT NULL,
                slug_extension varchar(20) DEFAULT NULL,
                slug_is_valid tinyint(1) NOT NULL DEFAULT 1,
                row_status varchar(20) NOT NULL DEFAULT 'pending',
                post_id bigint(20) unsigned DEFAULT NULL,
                error_message text DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at datetime DEFAULT NULL,
                PRIMARY KEY (id),
                KEY list_status (list_id, row_status),
                KEY list_row (list_id, `row_number`)
            ) {$charset_collate};";

            $sql_runs = "CREATE TABLE " . self::$table_runs . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                generator_id bigint(20) unsigned NOT NULL,
                item_guid varchar(255) DEFAULT NULL,
                item_permalink text DEFAULT NULL,
                post_id bigint(20) unsigned DEFAULT NULL,
                status varchar(20) NOT NULL DEFAULT 'info',
                message text DEFAULT NULL,
                request_json longtext DEFAULT NULL,
                response_json longtext DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY generator_status (generator_id, status),
                KEY item_guid (item_guid(191))
            ) {$charset_collate};";

            $sql_import_logs = "CREATE TABLE " . self::$table_import_logs . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                list_id bigint(20) unsigned NOT NULL DEFAULT 0,
                `row_number` int(11) NOT NULL DEFAULT 0,
                level varchar(20) NOT NULL DEFAULT 'error',
                code varchar(120) DEFAULT NULL,
                message text DEFAULT NULL,
                row_data longtext DEFAULT NULL,
                context_json longtext DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY list_level (list_id, level),
                KEY list_row (list_id, `row_number`)
            ) {$charset_collate};";

            $sql_items = "CREATE TABLE " . self::$table_items . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                generator_id bigint(20) unsigned NOT NULL,
                item_guid varchar(255) NOT NULL,
                item_permalink text DEFAULT NULL,
                item_title text DEFAULT NULL,
                post_id bigint(20) unsigned DEFAULT NULL,
                item_status varchar(20) NOT NULL DEFAULT 'processing',
                item_hash varchar(64) DEFAULT NULL,
                title_embedding_model varchar(120) DEFAULT NULL,
                title_embedding_json longtext DEFAULT NULL,
                semantic_duplicate_post_id bigint(20) unsigned DEFAULT NULL,
                semantic_duplicate_score decimal(6,4) DEFAULT NULL,
                semantic_duplicate_method varchar(40) DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY generator_item (generator_id, item_guid(191)),
                KEY generator_post (generator_id, post_id),
                KEY generator_status (generator_id, item_status)
            ) {$charset_collate};";

            $schema_queries = array(
                $sql_generators,
                $sql_lists,
                $sql_list_rows,
                $sql_runs,
                $sql_import_logs,
                $sql_items,
            );

            foreach ($schema_queries as $schema_query) {
                dbDelta($schema_query);
            }
        }

        public static function default_settings()
        {
            return array(
                'openai_api_key' => '',
                'pexels_api_key' => '',
                'tmdb_read_access_token' => '',
                'tmdb_api_key' => '',
                'global_internal_links_json' => '[]',
                'blacklist_json' => '[]',
                'tavily_api_key' => '',
                'tavily_enabled' => 0,
                'tavily_max_results' => 3,
                'tavily_include_answer' => 1,
                'tavily_search_depth' => 'basic',
                'default_model' => 'gpt-4.1-mini',
                'default_temperature' => 0.7,
                'default_max_tokens' => 3000,
                'semantic_dedup_enabled' => 1,
                'semantic_dedup_model' => 'text-embedding-3-small',
                'semantic_dedup_threshold' => 0.88,
                'semantic_dedup_lookback' => 300,
            );
        }

        public static function get_default_generation_language()
        {
            return 'Português do Brasil';
        }

        public static function get_default_content_length_class()
        {
            return 'medium';
        }

        public static function normalize_content_length_class($value)
        {
            $value = str_replace('-', '_', sanitize_key((string) $value));
            if ($value === 'xlarge' || $value === 'extra_grande' || $value === 'extra_large') {
                $value = 'extra_large';
            }

            $allowed = array('small', 'medium', 'large', 'extra_large');
            if (!in_array($value, $allowed, true)) {
                return self::get_default_content_length_class();
            }

            return $value;
        }

        public static function get_content_length_range($content_length_class)
        {
            $content_length_class = self::normalize_content_length_class($content_length_class);

            switch ($content_length_class) {
                case 'small':
                    return array(
                        'class' => 'small',
                        'label' => 'Pequeno',
                        'min_words' => 300,
                        'max_words' => 500,
                    );
                case 'large':
                    return array(
                        'class' => 'large',
                        'label' => 'Grande',
                        'min_words' => 1000,
                        'max_words' => 2500,
                    );
                case 'extra_large':
                    return array(
                        'class' => 'extra_large',
                        'label' => 'Extra grande',
                        'min_words' => 2500,
                        'max_words' => 5000,
                    );
                case 'medium':
                default:
                    return array(
                        'class' => 'medium',
                        'label' => 'Medio',
                        'min_words' => 500,
                        'max_words' => 1000,
                    );
            }
        }

        public static function pick_content_length_target_words($content_length_class)
        {
            $range = self::get_content_length_range($content_length_class);
            $min_words = isset($range['min_words']) ? max(1, intval($range['min_words'])) : 500;
            $max_words = isset($range['max_words']) ? max($min_words, intval($range['max_words'])) : $min_words;
            if ($min_words >= $max_words) {
                return $min_words;
            }

            if (function_exists('wp_rand')) {
                return wp_rand($min_words, $max_words);
            }

            try {
                return random_int($min_words, $max_words);
            } catch (Throwable $error) {
                return $min_words;
            }
        }

        public static function get_content_length_target_h2_count($target_words)
        {
            $target_words = intval($target_words);
            if ($target_words <= 0) {
                return 0;
            }

            $estimated = (int) round($target_words / 300);
            if ($estimated < 2) {
                $estimated = 2;
            }
            if ($estimated > 16) {
                $estimated = 16;
            }

            return $estimated;
        }

        public static function get_default_pexels_query()
        {
            return '{{pexels_tags}}';
        }

        public static function get_default_source_link_cta_phrases()
        {
            return implode("\n", array(
                'Assista na plataforma',
                'Veja no catálogo',
                'Confira a fonte',
                'Abra a referência',
                'Acesse o link',
            ));
        }

        public static function get_default_related_posts_phrases()
        {
            return implode("\n", array(
                'Você também pode gostar de:',
                'Leia também:',
                'Veja também:',
                'Confira também:',
            ));
        }

        public static function get_default_outline_models()
        {
            return array(
                array(
                    'key' => 'news_short',
                    'name' => 'Noticia curta',
                    'description' => 'Estrutura enxuta para noticias rapidas, com abertura direta e fechamento simples.',
                    'target_h2_min' => 3,
                    'target_h2_max' => 3,
                    'blocks' => array(
                        array('type' => 'h2', 'label' => 'H2 principal', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 1),
                        array('type' => 'paragraph', 'label' => 'Parágrafo', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 2),
                        array('type' => 'conclusion', 'label' => 'Conclusão', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 1),
                    ),
                ),
                array(
                    'key' => 'list_article',
                    'name' => 'Lista / ranking',
                    'description' => 'Modelo para artigos em lista, rankings e seleções com blocos visuais.',
                    'target_h2_min' => 4,
                    'target_h2_max' => 6,
                    'blocks' => array(
                        array('type' => 'intro_with_title', 'label' => 'Introdução com título', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 1),
                        array('type' => 'h2', 'label' => 'H2 da lista', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 1),
                        array('type' => 'paragraph', 'label' => 'Parágrafo', 'notes' => '', 'quantity_min' => 2, 'quantity_max' => 4),
                        array('type' => 'bullet', 'label' => 'Lista em bullets', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 3),
                        array('type' => 'table', 'label' => 'Tabela', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 2),
                        array('type' => 'image', 'label' => 'Imagem', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 1),
                        array('type' => 'button', 'label' => 'Botão', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 1),
                        array('type' => 'conclusion', 'label' => 'Conclusão', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 1),
                    ),
                ),
                array(
                    'key' => 'guide_long',
                    'name' => 'Guia longo',
                    'description' => 'Modelo mais completo, pensado para conteúdo longo com mais profundidade e ritmo editorial.',
                    'target_h2_min' => 5,
                    'target_h2_max' => 8,
                    'blocks' => array(
                        array('type' => 'intro_with_title', 'label' => 'Introdução com título', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 1),
                        array('type' => 'h2', 'label' => 'H2 principal', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 1),
                        array('type' => 'paragraph', 'label' => 'Parágrafo', 'notes' => '', 'quantity_min' => 2, 'quantity_max' => 4),
                        array('type' => 'list', 'label' => 'Lista', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 2),
                        array('type' => 'table', 'label' => 'Tabela', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 2),
                        array('type' => 'image', 'label' => 'Imagem', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 1),
                        array('type' => 'button', 'label' => 'Botão', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 1),
                        array('type' => 'conclusion', 'label' => 'Conclusão', 'notes' => '', 'quantity_min' => 1, 'quantity_max' => 1),
                    ),
                ),
            );
        }

        public static function get_default_prompt_models()
        {
            return array(
                array(
                    'key' => 'lista',
                    'name' => 'Lista',
                    'description' => 'Modelo para listas, rankings, selecoes e comparativos.',
                    'outline_model_key' => 'list_article',
                    'seo_prompt_template' => self::get_default_list_prompt_template(),
                    'content_prompt_template' => self::get_default_content_prompt_template_visible() . "\n\nDirecao editorial: mantenha a estrutura em lista, com bloco de resposta logo no primeiro H2 e itens diretos.",
                ),
                array(
                    'key' => 'artigo',
                    'name' => 'Artigo',
                    'description' => 'Modelo para artigos, guias e textos mais profundos.',
                    'outline_model_key' => 'guide_long',
                    'seo_prompt_template' => self::get_default_prompt_template() . "\n\nDireção editorial: priorize artigo, guia e explicação aprofundada, sem cara de lista.",
                    'content_prompt_template' => self::get_default_content_prompt_template_visible() . "\n\nDireção editorial: desenvolva um artigo editorial com contexto, transições fluidas e aprofundamento progressivo.",
                ),
                array(
                    'key' => 'noticia',
                    'name' => 'Noticia',
                    'description' => 'Modelo para notícias curtas, objetivas e diretas ao ponto.',
                    'outline_model_key' => 'news_short',
                    'seo_prompt_template' => self::get_default_prompt_template() . "\n\nDireção editorial: priorize notícia curta, objetiva, factual e com entrada rápida.",
                    'content_prompt_template' => self::get_default_content_prompt_template_visible() . "\n\nDireção editorial: escreva como notícia curta, com lead forte, contexto rápido e fechamento enxuto.",
                ),
                array(
                    'key' => 'review',
                    'name' => 'Review',
                    'description' => 'Modelo para reviews, resenhas e avaliações comparativas.',
                    'outline_model_key' => 'guide_long',
                    'seo_prompt_template' => self::get_default_prompt_template() . "\n\nDireção editorial: priorize review, resenha e avaliação crítica com conclusão clara e critério editorial.",
                    'content_prompt_template' => self::get_default_content_prompt_template_visible() . "\n\nDireção editorial: escreva uma review detalhada, com contexto, pontos fortes, pontos fracos e veredito final.",
                ),
                array(
                    'key' => 'faq',
                    'name' => 'FAQ',
                    'description' => 'Modelo para perguntas e respostas objetivas.',
                    'outline_model_key' => 'guide_long',
                    'seo_prompt_template' => self::get_default_prompt_template() . "\n\nDireção editorial: priorize perguntas frequentes, respostas objetivas e estrutura direta para dúvidas comuns.",
                    'content_prompt_template' => self::get_default_content_prompt_template_visible() . "\n\nDireção editorial: organize o conteúdo em perguntas e respostas curtas, claras e práticas.",
                ),
                array(
                    'key' => 'tutorial',
                    'name' => 'Tutorial',
                    'description' => 'Modelo para passo a passo, instruções e guias práticos.',
                    'outline_model_key' => 'guide_long',
                    'seo_prompt_template' => self::get_default_prompt_template() . "\n\nDireção editorial: priorize passo a passo, instruções claras e aplicação prática do tema.",
                    'content_prompt_template' => self::get_default_content_prompt_template_visible() . "\n\nDireção editorial: escreva como tutorial, com etapas numeradas, orientação prática e progressão lógica.",
                ),
                array(
                    'key' => 'comparativo',
                    'name' => 'Comparativo',
                    'description' => 'Modelo para comparações, versus e análises lado a lado.',
                    'outline_model_key' => 'list_article',
                    'seo_prompt_template' => self::get_default_prompt_template() . "\n\nDireção editorial: priorize comparação, contraste entre opções e critérios objetivos de escolha.",
                    'content_prompt_template' => self::get_default_content_prompt_template_visible() . "\n\nDireção editorial: apresente os itens em comparação direta, deixando diferenças, semelhanças e conclusão final claras.",
                ),
            );
        }

        public static function get_default_list_prompt_template()
        {
            return "Você é um editor especializado em SEO, Google Discover e portais de entretenimento.\n"
                . "Analise a notícia fornecida e gere apenas os metadados da publicação.\n\n"
                . "REGRAS GERAIS\n"
                . "Estritamente proibido colocar ano diferente de 2026, pois estamos em 2026, a não ser que a kw peça, mas tem que colocar coisas criativas, como \"filmes de 2013 que ainda fazem sucesso\" (só exemplo, não use isso em todo título).\n"
                . "Escreva tudo em Português do Brasil.\n"
                . "Use o nome da obra mais conhecido pelo público brasileiro.\n"
                . "Tudo o que for relacionado a medidas, quilometragem, distância, dentre tudo isso, deve ser sempre no padrão brasileiro (km/h, metros, kg etc)\n\n"
                . "TÍTULO\n"
                . "Use essa forma:\n"
                . "[número (até 10)/Filmes/Séries/Plataforma/gênero do filme] (brinque com a ordem) + [curiosidade/descoberta/problema/comparação com outra série ou filme (brinque com a ordem)]\n"
                . "Evite capitalização desnecessária, somente em nomes, siglas e primeira letra\n"
                . "O número, streaming e gênero devem estar no começo do título\n"
                . "Não gere títulos genéricos, como \"que parecem joias escondidas\"\n"
                . "Cada título deve ter a dor real dos usuários. Por exemplo, se um título for comparado com interestellar, um possível sentimento é a saudade e isso pode ser explorado. Cada título deve trabalhar um sentimento diferente; pode ser a saudade, mas não necessariamente a saudade.\n"
                . "Prefira o nome oficial da obra no Brasil.\n"
                . "Evite nomes de atores pouco conhecidos.\n"
                . "Sentimento de Polêmica/Validação: para quem gosta de julgar ou entender por que a internet está brigando por um filme (ex.: que dividem opiniões desde 2024).\n"
                . "Regra de Data Imutável: estamos em 2026. Se a fonte falar de filmes lançados em 2024, brinque com o tempo de forma criativa (ex.: \"filmes de 2024 que você ainda precisa ver em 2026\" ou \"que envelheceram bem\"). Nunca cite anos passados como se fossem o ano atual.\n"
                . "Limite de Tamanho: o título final deve ter obrigatoriamente entre 55 e 70 caracteres (contando espaços).\n"
                . "Localização: Use sempre os nomes oficiais das obras no Brasil e evite citar atores pouco conhecidos pelo público geral brasileiro.\n\n"
                . "Exemplos:\n"
                . "5 animações da Netflix que os adultos acabam gostando mais que as crianças\n"
                . "7 filmes de suspense na Netflix para quem acha que já viu tudo\n"
                . "5 suspenses de 2024 na Netflix que você precisa dar uma segunda chance hoje\n"
                . "4 Séries curtas da Netflix que você consegue terminar em dois dias\n\n"
                . "SLUG\n"
                . "Minúsculas, sem acentos.\n"
                . "Separado por hífens.\n"
                . "Remova artigos e palavras desnecessárias.\n"
                . "Mantenha apenas os termos mais relevantes.\n\n"
                . "RESUMO\n"
                . "18 a 24 palavras.\n"
                . "Resuma a principal novidade de forma direta.\n"
                . "Não repita o título.\n\n"
                . "META DESCRIÇÃO\n"
                . "140 a 160 caracteres.\n"
                . "Inclua a palavra-chave foco.\n"
                . "Resuma a novidade principal.\n"
                . "Termine com um CTA sutil e convidativo.\n\n"
                . "PALAVRA-CHAVE FOCO\n"
                . "Escolha apenas uma.\n"
                . "Prioridade: 1. Nome da obra, 2. Nome da franquia, 3. Principal acontecimento/revelação.\n\n"
                . "TAGS\n"
                . "As tags devem ser geradas pela IA com base no conteúdo, com no máximo 4 termos.\n"
                . "Se houver {{selected_tags}}, use-as apenas como referência opcional, nao como limite.\n"
                . "Se a lista estiver vazia, ainda assim retorne tags coerentes com o conteúdo.\n\n"
                . "PEXELS_TAGS\n"
                . "Máximo 4 termos.\n"
                . "Use apenas elementos visuais concretos (ex: godzilla, nova york, dragao, arranha ceu, monstro gigante, traje preto, etc.).\n"
                . "Evite termos genéricos (filme, cinema, trailer, ação, etc.).";
        }

        public static function strip_backend_source_context_from_prompt($prompt_template)
        {
            $prompt_template = (string) $prompt_template;
            if ($prompt_template === '') {
                return '';
            }

            $patterns = array(
                '/^\s*Resumo da fonte:.*(?:\r?\n|$)/mi',
                '/^\s*Conteudo da fonte:.*(?:\r?\n|$)/mi',
                '/^\s*Titulo do item:.*(?:\r?\n|$)/mi',
                '/^\s*Link do item:.*(?:\r?\n|$)/mi',
                '/^\s*T[ií]tulo da fonte:.*(?:\r?\n|$)/mi',
                '/^\s*Titulo da origem:.*(?:\r?\n|$)/mi',
                '/^\s*T[ií]tulo da origem:.*(?:\r?\n|$)/mi',
                '/^\s*T[ií]tulo da pagina de origem:.*(?:\r?\n|$)/mi',
                '/^\s*T[ií]tulo da p[aá]gina de origem:.*(?:\r?\n|$)/mi',
                '/^\s*Idioma final:.*(?:\r?\n|$)/mi',
            );

            return trim(preg_replace($patterns, '', $prompt_template));
        }

        public static function get_prompt_output_suffix()
        {
            return "FORMATO DE SAIDA\n"
                . "Retorne exclusivamente o JSON válido com exatamente estas chaves:\n"
                . "JSON{\n"
                . '  "title": "",' . "\n"
                . '  "slug": "",' . "\n"
                . '  "resumo": "",' . "\n"
                . '  "meta_descricao": "",' . "\n"
                . '  "palavra_chave_foco": "",' . "\n"
                . '  "tags": [],' . "\n"
                . '  "pexels_tags": []' . "\n"
                . "}";
        }

        public static function get_content_prompt_output_suffix()
        {
            return "FORMATO FINAL\n"
                . "Saída: retorne APENAS um JSON válido com exatamente esta estrutura:\n"
                . "{\n"
                . "  \"content_html\": \"\"\n"
                . "}\n"
                . "Não inclua title, slug, resumo, meta_descricao, tags, pexels_tags, focus_keyword ou qualquer outra chave.\n"
                . "Não escreva texto fora do JSON.\n"
                . "Se não houver conteúdo, ainda assim retorne algum erro/formato inválido.";
        }

        public static function append_content_prompt_output_suffix($prompt)
        {
            $prompt = (string) $prompt;
            $suffix = self::get_content_prompt_output_suffix();

            if ($prompt === '') {
                return $suffix;
            }

            if (strpos($prompt, 'Retorne APENAS o JSON com a chave "content_html"') !== false) {
                return $prompt;
            }

            return rtrim($prompt) . "\n\n" . $suffix;
        }

        public static function normalize_prompt_model_definition($model)
        {
            $model = is_array($model) ? $model : array();
            $key = !empty($model['key']) ? self::normalize_prompt_model_key((string) $model['key']) : '';
            if ($key === '') {
                return array();
            }

            $defaults = self::get_default_prompt_model($key);
            $name = !empty($model['name']) ? sanitize_text_field((string) $model['name']) : (!empty($defaults['name']) ? $defaults['name'] : ucfirst($key));
            $description = !empty($model['description']) ? sanitize_textarea_field((string) $model['description']) : (!empty($defaults['description']) ? $defaults['description'] : '');
            $outline_model_key = !empty($model['outline_model_key']) ? sanitize_key((string) $model['outline_model_key']) : (!empty($defaults['outline_model_key']) ? sanitize_key((string) $defaults['outline_model_key']) : self::get_default_outline_model_key());
            $outline_prompt_template = isset($model['outline_prompt_template']) ? trim((string) wp_kses_post($model['outline_prompt_template'])) : '';
            $seo_prompt_template = isset($model['seo_prompt_template']) ? trim((string) wp_kses_post($model['seo_prompt_template'])) : '';
            $content_prompt_template = isset($model['content_prompt_template']) ? trim((string) wp_kses_post($model['content_prompt_template'])) : '';
            $seo_prompt_template = self::strip_backend_source_context_from_prompt($seo_prompt_template);
            $content_prompt_template = self::strip_backend_source_context_from_prompt($content_prompt_template);

            if ($outline_prompt_template === '') {
                $outline_model = self::get_outline_model($outline_model_key);
                $outline_prompt_template = self::format_outline_model_for_prompt($outline_model, array());
            }
            if ($seo_prompt_template === '') {
                $seo_prompt_template = !empty($defaults['seo_prompt_template']) ? $defaults['seo_prompt_template'] : self::get_default_prompt_template();
            }
            if ($content_prompt_template === '') {
                $content_prompt_template = !empty($defaults['content_prompt_template']) ? $defaults['content_prompt_template'] : self::get_default_content_prompt_template_visible();
            }

            return array(
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'outline_model_key' => $outline_model_key,
                'outline_prompt_template' => $outline_prompt_template,
                'seo_prompt_template' => $seo_prompt_template,
                'content_prompt_template' => $content_prompt_template,
            );
        }

        public static function normalize_prompt_models($models)
        {
            if (!is_array($models) || empty($models)) {
                $models = self::get_default_prompt_models();
            }

            $normalized = array();
            foreach ($models as $model) {
                $model = self::normalize_prompt_model_definition($model);
                if (!empty($model['key'])) {
                    $normalized[$model['key']] = $model;
                }
            }

            if (empty($normalized)) {
                foreach (self::get_default_prompt_models() as $model) {
                    $normalized[$model['key']] = self::normalize_prompt_model_definition($model);
                }
            }

            return array_values($normalized);
        }

        public static function get_prompt_models_storage()
        {
            $storage = get_option(self::OPTION_KEY_PROMPT_MODELS, array());
            return is_array($storage) ? $storage : array();
        }

        public static function save_prompt_models_storage($models, $migrated_from_generator_id = 0)
        {
            $normalized_models = self::normalize_prompt_models($models);
            $payload = array(
                'prompt_models_json' => wp_json_encode($normalized_models, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'prompt_models_initialized' => 1,
                'prompt_models_migrated_from_generator_id' => max(0, intval($migrated_from_generator_id)),
            );

            update_option(self::OPTION_KEY_PROMPT_MODELS, $payload, false);

            $settings = self::get_settings();
            $settings['prompt_models_json'] = $payload['prompt_models_json'];
            $settings['prompt_models_initialized'] = 1;
            $settings['prompt_models_migrated_from_generator_id'] = $payload['prompt_models_migrated_from_generator_id'];
            update_option(self::OPTION_KEY, $settings, false);

            return $payload;
        }

        public static function get_default_prompt_model_key()
        {
            return '';
        }

        public static function normalize_prompt_model_key($prompt_model_key = '')
        {
            $prompt_model_key = sanitize_key((string) $prompt_model_key);
            if ($prompt_model_key === '') {
                return '';
            }

            $aliases = array(
                'article' => 'artigo',
                'artigo' => 'artigo',
                'guide' => 'artigo',
                'guide_long' => 'artigo',
                'analysis' => 'artigo',
                'analise' => 'artigo',
                'opinion' => 'artigo',
                'opiniao' => 'artigo',
                'list' => 'lista',
                'lista' => 'lista',
                'ranking' => 'lista',
                'ranking_list' => 'lista',
                'list_article' => 'lista',
                'review' => 'review',
                'resenha' => 'review',
                'avaliacao' => 'review',
                'faq' => 'faq',
                'tutorial' => 'tutorial',
                'howto' => 'tutorial',
                'how_to' => 'tutorial',
                'passo_a_passo' => 'tutorial',
                'comparativo' => 'comparativo',
                'comparison' => 'comparativo',
                'versus' => 'comparativo',
                'vs' => 'comparativo',
                'noticia' => 'noticia',
                'news' => 'noticia',
                'news_short' => 'noticia',
            );

            if (isset($aliases[$prompt_model_key])) {
                return $aliases[$prompt_model_key];
            }

            return $prompt_model_key;
        }

        public static function get_default_content_model_type()
        {
            return 'pillar';
        }

        public static function get_default_generation_mode()
        {
            return 'pillar';
        }

        public static function normalize_generation_mode($generation_mode = '')
        {
            $generation_mode = sanitize_key((string) $generation_mode);
            $allowed = array('pillar', 'satellite');
            if (in_array($generation_mode, $allowed, true)) {
                return $generation_mode;
            }

            return self::get_default_generation_mode();
        }

        public static function get_generation_mode_label($generation_mode = '')
        {
            $generation_mode = self::normalize_generation_mode($generation_mode);
            switch ($generation_mode) {
                case 'satellite':
                    return 'Satelite';
                case 'pillar':
                default:
                    return 'Pilar';
            }
        }

        public static function normalize_content_model_type($content_model_type = '')
        {
            $content_model_type = sanitize_key((string) $content_model_type);
            $aliases = array(
                'pillar' => 'pillar',
                'pilhar' => 'pillar',
                'content_pillar' => 'pillar',
                'satellite' => 'satellite',
                'satelite' => 'satellite',
                'content_satellite' => 'satellite',
            );

            if ($content_model_type !== '' && isset($aliases[$content_model_type])) {
                return $aliases[$content_model_type];
            }

            return self::get_default_content_model_type();
        }

        public static function get_content_model_label($content_model_type = '')
        {
            $content_model_type = self::normalize_content_model_type($content_model_type);

            switch ($content_model_type) {
                case 'satellite':
                    return 'Satélite';
                case 'pillar':
                default:
                    return 'Pilar';
            }
        }

        public static function get_default_prompt_model($prompt_model_key = '')
        {
            $prompt_model_key = self::normalize_prompt_model_key($prompt_model_key);
            foreach (self::get_default_prompt_models() as $model) {
                if (!empty($model['key']) && $model['key'] === $prompt_model_key) {
                    return $model;
                }
            }

            return array();
        }

        public static function get_prompt_models($generator = array())
        {
            $generator = is_array($generator) ? $generator : array();
            $models = array();

            $prompt_models_settings = self::get_prompt_models_storage();
            if (!empty($prompt_models_settings['prompt_models_json'])) {
                $decoded = json_decode((string) $prompt_models_settings['prompt_models_json'], true);
                if (is_array($decoded)) {
                    $models = $decoded;
                }
            }

            if (empty($models)) {
                $settings = self::get_settings();
                if (!empty($settings['prompt_models_json'])) {
                    $decoded = json_decode((string) $settings['prompt_models_json'], true);
                    if (is_array($decoded)) {
                        $models = $decoded;
                    }
                }
            }

            if (empty($models) && !empty($generator['prompt_models_json'])) {
                $decoded = json_decode((string) $generator['prompt_models_json'], true);
                if (is_array($decoded)) {
                    $models = $decoded;
                }
            }

            if (empty($models) && !empty($generator['prompt_models']) && is_array($generator['prompt_models'])) {
                $models = $generator['prompt_models'];
            }

            return self::normalize_prompt_models($models);
        }

        public static function get_prompt_model($prompt_model_key = '', $generator = array())
        {
            $prompt_model_key = self::normalize_prompt_model_key($prompt_model_key);
            $models = self::get_prompt_models($generator);
            foreach ($models as $model) {
                if (!empty($model['key']) && $model['key'] === $prompt_model_key) {
                    return $model;
                }
            }

            return array();
        }

        public static function get_prompt_model_key_for_content_type($content_type = '', $outline_context = array(), $generator = array())
        {
            $content_type = sanitize_key((string) $content_type);
            $outline_context = is_array($outline_context) ? $outline_context : array();
            $generator = is_array($generator) ? $generator : array();

            $content_type_map = array(
                'list' => 'lista',
                'lista' => 'lista',
                'ranking' => 'lista',
                'ranking_list' => 'lista',
                'list_article' => 'lista',
                'article' => 'artigo',
                'artigo' => 'artigo',
                'guide' => 'artigo',
                'guide_long' => 'artigo',
                'opinion' => 'artigo',
                'analysis' => 'artigo',
                'opiniao' => 'artigo',
                'analise' => 'artigo',
                'review' => 'review',
                'resenha' => 'review',
                'avaliacao' => 'review',
                'analysis_review' => 'review',
                'faq' => 'faq',
                'perguntas_frequentes' => 'faq',
                'questions' => 'faq',
                'question_answer' => 'faq',
                'tutorial' => 'tutorial',
                'howto' => 'tutorial',
                'how_to' => 'tutorial',
                'passo_a_passo' => 'tutorial',
                'comparativo' => 'comparativo',
                'comparison' => 'comparativo',
                'versus' => 'comparativo',
                'vs' => 'comparativo',
                'noticia' => 'noticia',
                'news' => 'noticia',
                'news_short' => 'noticia',
            );

            if ($content_type !== '' && isset($content_type_map[$content_type])) {
                return $content_type_map[$content_type];
            }

            $outline_model_key = '';
            if (!empty($outline_context['recommended_outline_model_key'])) {
                $outline_model_key = sanitize_key((string) $outline_context['recommended_outline_model_key']);
            } elseif (!empty($generator['outline_model_key'])) {
                $outline_model_key = sanitize_key((string) $generator['outline_model_key']);
            }

            $outline_to_prompt_map = array(
                'list_article' => 'lista',
                'guide_long' => 'artigo',
                'news_short' => 'noticia',
            );

            if ($outline_model_key !== '' && isset($outline_to_prompt_map[$outline_model_key])) {
                return self::normalize_prompt_model_key($outline_to_prompt_map[$outline_model_key]);
            }

            return self::normalize_prompt_model_key($content_type);
        }

        public static function get_generator_prompt_model($generator, $outline_context = array())
        {
            $generator = is_array($generator) ? $generator : array();
            $outline_context = is_array($outline_context) ? $outline_context : array();

            // A model selected in the generator is explicit; automatic mode is represented by an empty key.
            if (!empty($generator['prompt_model_key'])) {
                $explicit_prompt_model_key = self::normalize_prompt_model_key($generator['prompt_model_key']);
                $explicit_prompt_model = self::get_prompt_model($explicit_prompt_model_key, $generator);
                if (!empty($explicit_prompt_model)) {
                    return $explicit_prompt_model;
                }
            }

            $prompt_model_key = '';
            if (!empty($outline_context['recommended_prompt_model_key'])) {
                $prompt_model_key = self::normalize_prompt_model_key($outline_context['recommended_prompt_model_key']);
            } else {
                $prompt_model_key = self::get_prompt_model_key_for_content_type(
                    !empty($outline_context['content_type']) ? $outline_context['content_type'] : '',
                    $outline_context,
                    $generator
                );
            }

            if ($prompt_model_key === '' && !empty($generator['prompt_model_key'])) {
                $prompt_model_key = self::normalize_prompt_model_key($generator['prompt_model_key']);
            }

            if ($prompt_model_key === '' && !empty($outline_context['recommended_outline_model_key'])) {
                $outline_model_key = sanitize_key((string) $outline_context['recommended_outline_model_key']);
                $models = self::get_prompt_models($generator);
                foreach ($models as $model) {
                    if (!empty($model['outline_model_key']) && $model['outline_model_key'] === $outline_model_key) {
                        $prompt_model_key = !empty($model['key']) ? self::normalize_prompt_model_key((string) $model['key']) : '';
                        break;
                    }
                }
            }

            if ($prompt_model_key === '') {
                return array();
            }

            return self::get_prompt_model($prompt_model_key, $generator);
        }

        public static function format_prompt_model_for_prompt($prompt_model)
        {
            $prompt_model = is_array($prompt_model) ? $prompt_model : array();
            $lines = array();
            $key = !empty($prompt_model['key']) ? sanitize_key((string) $prompt_model['key']) : '';
            $name = !empty($prompt_model['name']) ? sanitize_text_field((string) $prompt_model['name']) : '';
            $description = !empty($prompt_model['description']) ? sanitize_textarea_field((string) $prompt_model['description']) : '';
            $outline_model_key = !empty($prompt_model['outline_model_key']) ? sanitize_key((string) $prompt_model['outline_model_key']) : '';

            $lines[] = '- key=' . $key . ' | name=' . $name;
            if ($description !== '') {
                $lines[] = '  description=' . $description;
            }
            if ($outline_model_key !== '') {
                $lines[] = '  outline_model_key=' . $outline_model_key;
            }

            return implode("\n", $lines);
        }

        public static function normalize_outline_quantity_range($min, $max, $default_min = 1, $default_max = 1)
        {
            $min = intval($min);
            $max = intval($max);
            $default_min = max(1, intval($default_min));
            $default_max = max(1, intval($default_max));

            if ($min <= 0 && $max <= 0) {
                $min = $default_min;
                $max = $default_max;
            } elseif ($min <= 0) {
                $min = $max > 0 ? $max : $default_min;
            } elseif ($max <= 0) {
                $max = $min > 0 ? $min : $default_max;
            }

            $min = max(1, $min);
            $max = max(1, $max);
            if ($max < $min) {
                $swap = $min;
                $min = $max;
                $max = $swap;
            }

            return array(
                'min' => $min,
                'max' => $max,
            );
        }

        public static function pick_outline_quantity($min, $max, $fallback = 1)
        {
            $range = self::normalize_outline_quantity_range($min, $max, $fallback, $fallback);
            if ($range['min'] >= $range['max']) {
                return $range['min'];
            }

            if (function_exists('wp_rand')) {
                return wp_rand($range['min'], $range['max']);
            }

            try {
                return random_int($range['min'], $range['max']);
            } catch (Throwable $error) {
                return $range['min'];
            }
        }

        public static function get_outline_model_target_h2_range($outline_model)
        {
            $outline_model = is_array($outline_model) ? $outline_model : array();
            $min = isset($outline_model['target_h2_min']) ? intval($outline_model['target_h2_min']) : 0;
            $max = isset($outline_model['target_h2_max']) ? intval($outline_model['target_h2_max']) : 0;
            if ($min <= 0 && $max <= 0 && isset($outline_model['target_h2_count'])) {
                $min = intval($outline_model['target_h2_count']);
                $max = intval($outline_model['target_h2_count']);
            }
            $range = self::normalize_outline_quantity_range($min, $max, 3, 3);
            $range['min'] = max(3, intval($range['min']));
            $range['max'] = max($range['min'], max(3, intval($range['max'])));

            return $range;
        }

        public static function get_outline_block_quantity_range($block)
        {
            $block = is_array($block) ? $block : array();
            $min = isset($block['quantity_min']) ? intval($block['quantity_min']) : 0;
            $max = isset($block['quantity_max']) ? intval($block['quantity_max']) : 0;
            if ($min <= 0 && $max <= 0) {
                $min = 1;
                $max = 1;
            }

            return self::normalize_outline_quantity_range($min, $max, 1, 1);
        }

        public static function get_default_outline_model_key()
        {
            $models = self::get_default_outline_models();
            return !empty($models) && !empty($models[0]['key']) ? (string) $models[0]['key'] : '';
        }

        public static function log_pipeline_debug($label, $context = array())
        {
            return null;
        }

        public static function apply_outline_model_context($generator, $outline_context = array())
        {
            $generator = is_array($generator) ? $generator : array();
            $outline_context = is_array($outline_context) ? $outline_context : array();

            $recommended_outline_model_key = !empty($outline_context['recommended_outline_model_key'])
                ? sanitize_key((string) $outline_context['recommended_outline_model_key'])
                : '';
            $outline_model_key = $recommended_outline_model_key !== ''
                ? $recommended_outline_model_key
                : (!empty($generator['outline_model_key']) ? sanitize_key((string) $generator['outline_model_key']) : self::get_default_outline_model_key());

            $outline_model = self::get_outline_model($outline_model_key);
            if (empty($outline_model)) {
                $outline_model_key = self::get_default_outline_model_key();
                $outline_model = self::get_outline_model($outline_model_key);
            }

            $outline_context['outline_model'] = $outline_model;
            $outline_context['outline_model_key'] = !empty($outline_model['key']) ? (string) $outline_model['key'] : $outline_model_key;
            $outline_context['outline_model_name'] = !empty($outline_model['name']) ? (string) $outline_model['name'] : '';
            $outline_context['outline_model_text'] = self::format_outline_model_for_prompt($outline_model, $outline_context);
            $outline_context['outline_target_h2_min'] = !empty($outline_model['target_h2_min']) ? intval($outline_model['target_h2_min']) : 0;
            $outline_context['outline_target_h2_max'] = !empty($outline_model['target_h2_max']) ? intval($outline_model['target_h2_max']) : 0;
            $outline_context['outline_target_h2_count'] = !empty($outline_model['target_h2_count']) ? intval($outline_model['target_h2_count']) : 0;

            return $outline_context;
        }

        public static function get_outline_block_label($type)
        {
            $map = array(
                'intro' => 'Introdução',
                'h2' => 'H2',
                'h3' => 'H3',
                'paragraph' => 'Parágrafo',
                'list' => 'Lista',
                'bullet' => 'Bullet',
                'table' => 'Tabela',
                'image' => 'Imagem',
                'button' => 'Botão',
                'conclusion' => 'Conclusão',
            );

            $type = sanitize_key((string) $type);
            return isset($map[$type]) ? $map[$type] : ucfirst($type);
        }

        public static function normalize_outline_block_definition($block)
        {
            $block = is_array($block) ? $block : array();
            $type = isset($block['type']) ? sanitize_key((string) $block['type']) : 'paragraph';
            $allowed_types = array('intro', 'h2', 'h3', 'paragraph', 'list', 'bullet', 'table', 'image', 'button', 'conclusion');
            if (!in_array($type, $allowed_types, true)) {
                $type = 'paragraph';
            }

            $label = isset($block['label']) ? sanitize_text_field((string) $block['label']) : '';
            if ($label === '') {
                $label = self::get_outline_block_label($type);
            }

            $quantity_range = self::get_outline_block_quantity_range($block);

            return array(
                'type' => $type,
                'label' => $label,
                'notes' => isset($block['notes']) ? sanitize_text_field((string) $block['notes']) : '',
                'quantity_min' => intval($quantity_range['min']),
                'quantity_max' => intval($quantity_range['max']),
            );
        }

        public static function normalize_outline_model_definition($model)
        {
            $model = is_array($model) ? $model : array();
            $key = isset($model['key']) ? sanitize_key((string) $model['key']) : '';
            $name = isset($model['name']) ? sanitize_text_field((string) $model['name']) : '';
            $description = isset($model['description']) ? sanitize_textarea_field((string) $model['description']) : '';
            $target_h2_range = self::get_outline_model_target_h2_range($model);
            $blocks = array();
            if (!empty($model['blocks']) && is_array($model['blocks'])) {
                foreach ($model['blocks'] as $block) {
                    $blocks[] = self::normalize_outline_block_definition($block);
                }
            }

            return array(
                'key' => $key,
                'name' => $name !== '' ? $name : ucwords(str_replace(array('-', '_'), ' ', $key)),
                'description' => $description,
                'target_h2_min' => $target_h2_range['min'],
                'target_h2_max' => $target_h2_range['max'],
                'target_h2_count' => $target_h2_range['min'] === $target_h2_range['max'] ? $target_h2_range['min'] : 0,
                'blocks' => $blocks,
            );
        }

        public static function normalize_outline_models($models)
        {
            if (!is_array($models) || empty($models)) {
                return self::get_default_outline_models();
            }

            $normalized = array();
            foreach ($models as $model) {
                $model = self::normalize_outline_model_definition($model);
                if ($model['key'] === '') {
                    continue;
                }
                $normalized[$model['key']] = $model;
            }

            if (empty($normalized)) {
                return self::get_default_outline_models();
            }

            return array_values($normalized);
        }

        public static function get_outline_models()
        {
            $models = get_option(self::OPTION_KEY_OUTLINE_MODELS, array());
            if (!is_array($models) || empty($models)) {
                $models = self::get_default_outline_models();
            }

            return self::normalize_outline_models($models);
        }

        public static function get_outline_model($outline_model_key = '')
        {
            $outline_model_key = sanitize_key((string) $outline_model_key);
            $models = self::get_outline_models();
            foreach ($models as $model) {
                if (!empty($model['key']) && $model['key'] === $outline_model_key) {
                    return $model;
                }
            }

            if (!empty($models)) {
                return $models[0];
            }

            return array(
                'key' => '',
                'name' => '',
                'description' => '',
                'target_h2_min' => 0,
                'target_h2_max' => 0,
                'target_h2_count' => 0,
                'blocks' => array(),
            );
        }

        public static function get_generator_outline_model($generator, $outline_context = array())
        {
            $outline_context = self::apply_outline_model_context($generator, $outline_context);
            return !empty($outline_context['outline_model']) && is_array($outline_context['outline_model'])
                ? $outline_context['outline_model']
                : self::get_outline_model(self::get_default_outline_model_key());
        }

        public static function format_outline_model_for_prompt($outline_model, $outline_context = array())
        {
            $outline_model = is_array($outline_model) ? $outline_model : array();
            $outline_context = is_array($outline_context) ? $outline_context : array();

            $key = !empty($outline_model['key']) ? sanitize_key((string) $outline_model['key']) : '';
            $name = !empty($outline_model['name']) ? sanitize_text_field((string) $outline_model['name']) : '';
            $description = !empty($outline_model['description']) ? sanitize_text_field((string) $outline_model['description']) : '';
            $target_h2_min = isset($outline_context['outline_target_h2_min']) ? intval($outline_context['outline_target_h2_min']) : (isset($outline_model['target_h2_min']) ? intval($outline_model['target_h2_min']) : 0);
            $target_h2_max = isset($outline_context['outline_target_h2_max']) ? intval($outline_context['outline_target_h2_max']) : (isset($outline_model['target_h2_max']) ? intval($outline_model['target_h2_max']) : 0);
            $target_h2_count = isset($outline_context['outline_target_h2_count']) ? intval($outline_context['outline_target_h2_count']) : (isset($outline_model['target_h2_count']) ? intval($outline_model['target_h2_count']) : 0);

            $lines = array();
            $lines[] = 'Modelo: ' . ($name !== '' ? $name : 'Outline');
            if ($key !== '') {
                $lines[] = 'Chave: ' . $key;
            }
            if ($description !== '') {
                $lines[] = 'Descricao: ' . $description;
            }
            $lines[] = 'Faixa de H2 do modelo: ' . intval($target_h2_min) . '-' . intval($target_h2_max);
            if ($target_h2_count > 0) {
                $lines[] = 'H2 do modelo como referencia: ' . intval($target_h2_count);
            }

            if (!empty($outline_model['blocks']) && is_array($outline_model['blocks'])) {
                $lines[] = 'Blocos de referencia:';
                foreach ($outline_model['blocks'] as $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    $label = !empty($block['label']) ? sanitize_text_field((string) $block['label']) : self::get_outline_block_label(isset($block['type']) ? $block['type'] : 'paragraph');
                    $type = !empty($block['type']) ? sanitize_key((string) $block['type']) : 'paragraph';
                    $min = isset($block['quantity_min']) ? intval($block['quantity_min']) : 1;
                    $max = isset($block['quantity_max']) ? intval($block['quantity_max']) : 1;
                    $notes = !empty($block['notes']) ? sanitize_text_field((string) $block['notes']) : '';
                    $line = '- ' . $label . ' (' . $type . ', ' . $min . '-' . $max . ')';
                    if ($notes !== '') {
                        $line .= ' - ' . $notes;
                    }
                    $lines[] = $line;
                }
            }

            return implode("\n", $lines);
        }

        public static function normalize_generation_language_value($value)
        {
            $value = trim((string) $value);
            if ($value === '' || $value === 'Português do Brasil') {
                return self::get_default_generation_language();
            }
            return $value;
        }

        public static function get_generator_status_label($status)
        {
            $map = array(
                'active' => 'Ativo',
                'inactive' => 'Inativo',
            );
            return isset($map[$status]) ? $map[$status] : ucfirst((string) $status);
        }

        public static function get_schedule_type_label($type)
        {
            $map = array(
                'interval' => 'Intervalo + variação',
                'daily_random' => 'Janela diária aleatória',
            );
            return isset($map[$type]) ? $map[$type] : ucfirst((string) $type);
        }

        public static function get_run_status_label($status)
        {
            $map = array(
                'success' => 'Sucesso',
                'error' => 'Erro',
                'info' => 'Informação',
                'warning' => 'Aviso',
            );
            return isset($map[$status]) ? $map[$status] : ucfirst((string) $status);
        }

        public static function get_settings()
        {
            $settings = get_option(self::OPTION_KEY, array());
            if (!is_array($settings)) {
                $settings = array();
            }
            return array_merge(self::default_settings(), $settings);
        }

        public static function get_default_keyword_list_mode()
        {
            return 'keywords';
        }

        public static function source_type_uses_keyword_list($source_type)
        {
            return in_array(sanitize_key((string) $source_type), array('keyword_list', 'spreadsheet'), true);
        }

        public static function keyword_list_mode_uses_source_url($keyword_list_mode)
        {
            return sanitize_key((string) $keyword_list_mode) === 'url_reference';
        }

        public static function generator_uses_keyword_list_url_reference_mode($generator)
        {
            if (empty($generator['source_type']) || sanitize_key((string) $generator['source_type']) !== 'spreadsheet') {
                return false;
            }

            $keyword_list_mode = !empty($generator['keyword_list_mode']) ? sanitize_key((string) $generator['keyword_list_mode']) : self::get_default_keyword_list_mode();
            return self::keyword_list_mode_uses_source_url($keyword_list_mode);
        }

        public static function generator_uses_source_page_context($generator)
        {
            $source_type = !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss';
            // Keyword lists have no reference page. Only RSS and spreadsheet
            // rows with an explicit URL may use source-page context.
            return $source_type === 'rss' || self::generator_uses_keyword_list_url_reference_mode($generator);
        }

        public static function generator_uses_source_content_images($generator)
        {
            if (isset($generator['source_content_images_enabled'])) {
                return !empty($generator['source_content_images_enabled']);
            }

            if (isset($generator['source_content_media_enabled'])) {
                return !empty($generator['source_content_media_enabled']);
            }

            return true;
        }

        public static function generator_uses_source_content_links($generator)
        {
            if (isset($generator['source_content_links_enabled'])) {
                return !empty($generator['source_content_links_enabled']);
            }

            if (isset($generator['source_content_media_enabled'])) {
                return !empty($generator['source_content_media_enabled']);
            }

            return true;
        }

        public static function generator_uses_satellite_mode($generator)
        {
            $generation_mode = isset($generator['generation_mode']) ? self::normalize_generation_mode((string) $generator['generation_mode']) : self::get_default_generation_mode();
            return $generation_mode === 'satellite';
        }

        public static function get_content_author_users()
        {
            return get_users(array(
                'role__in' => array('administrator', 'editor'),
                'orderby' => 'display_name',
                'order' => 'ASC',
                'fields' => array('ID', 'display_name', 'user_login'),
            ));
        }

        public static function normalize_content_author_id($author_id, $fallback_to_current = true)
        {
            $author_id = intval($author_id);
            if ($author_id > 0) {
                $user = get_user_by('id', $author_id);
                if ($user && array_intersect(array('administrator', 'editor'), (array) $user->roles)) {
                    return $author_id;
                }
            }

            if ($fallback_to_current) {
                $current_user_id = get_current_user_id();
                if ($current_user_id > 0) {
                    $current_user = get_user_by('id', $current_user_id);
                    if ($current_user && array_intersect(array('administrator', 'editor'), (array) $current_user->roles)) {
                        return $current_user_id;
                    }
                }
            }

            return 0;
        }

        public static function get_generator_source_context_filters($generator)
        {
            $filters_json = !empty($generator['source_context_filters_json']) ? trim((string) $generator['source_context_filters_json']) : '';
            if ($filters_json === '') {
                return array();
            }

            $decoded = json_decode($filters_json, true);
            if (!is_array($decoded)) {
                return array();
            }

            return Content_Rank_Generator_Helper::normalize_source_context_filters($decoded);
        }

        public static function normalize_hex_color($value, $fallback = '#c91414')
        {
            $value = strtolower(trim((string) $value));
            if (preg_match('/^#[0-9a-f]{6}$/i', $value)) {
                return $value;
            }
            return strtolower($fallback);
        }

        public static function get_default_image_source_mode($source_type = 'rss', $keyword_list_mode = 'keywords')
        {
            return 'rss_or_pexels';
        }

        public static function normalize_image_source_mode($source_type, $image_source_mode = '', $legacy_pexels_enabled = null, $keyword_list_mode = 'keywords')
        {
            $source_type = sanitize_key((string) $source_type);
            $image_source_mode = sanitize_key((string) $image_source_mode);
            $keyword_list_mode = sanitize_key((string) $keyword_list_mode);
            $allowed = array('rss', 'rss_or_pexels', 'rss_or_dalle', 'pexels', 'dalle', 'tmdb_composite');

            if ($image_source_mode !== '' && in_array($image_source_mode, $allowed, true)) {
                return $image_source_mode;
            }

            if ($legacy_pexels_enabled !== null) {
                return !empty($legacy_pexels_enabled) ? 'rss_or_pexels' : 'rss';
            }

            return self::get_default_image_source_mode($source_type, $keyword_list_mode);
        }

        public static function build_satellite_schedule_datetime($generator, $index, $total_count = 0, $schedule_base_timestamp = null)
        {
            $generator = is_array($generator) ? $generator : array();
            $index = max(1, intval($index));
            $total_count = max(0, intval($total_count));
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');

            try {
                $base_timestamp = $schedule_base_timestamp !== null
                    ? intval($schedule_base_timestamp)
                    : time();
                $base = (new DateTimeImmutable('@' . $base_timestamp))->setTimezone($timezone);
                $window = self::get_generator_daily_window($generator, $base->getTimestamp());
                $window_start = !empty($window[0]) ? intval($window[0]) : 0;
                $window_end = !empty($window[1]) ? intval($window[1]) : 0;

                if ($window_start > 0 && $window_end > 0) {
                    $start_timestamp = max($base->getTimestamp(), $window_start);
                    if ($base->getTimestamp() > $window_end) {
                        $next_window = self::get_generator_daily_window($generator, $base->getTimestamp() + DAY_IN_SECONDS);
                        $window_start = !empty($next_window[0]) ? intval($next_window[0]) : $window_start;
                        $window_end = !empty($next_window[1]) ? intval($next_window[1]) : $window_end;
                        $start_timestamp = $window_start;
                    } elseif ($start_timestamp > $window_end) {
                        $start_timestamp = $window_start;
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
                    if ($scheduled->getTimestamp() > $window_end) {
                        $scheduled = (new DateTimeImmutable('@' . $window_end))->setTimezone($timezone);
                    }
                    return $scheduled->format('Y-m-d H:i:s');
                }

                $scheduled = $base->modify('+' . (10 + (($index - 1) * 45)) . ' minutes');
                return $scheduled->format('Y-m-d H:i:s');
            } catch (Exception $exception) {
                return current_time('mysql');
            }
        }

        public static function image_source_mode_uses_source_image($image_source_mode)
        {
            return in_array(sanitize_key((string) $image_source_mode), array('rss', 'rss_or_pexels', 'rss_or_dalle'), true);
        }

        public static function image_source_mode_uses_pexels($image_source_mode)
        {
            return in_array(sanitize_key((string) $image_source_mode), array('rss_or_pexels', 'pexels'), true);
        }

        public static function image_source_mode_uses_dalle($image_source_mode)
        {
            return in_array(sanitize_key((string) $image_source_mode), array('rss_or_dalle', 'dalle'), true);
        }

        /**
         * Fetch a reusable Pexels pool for interval-based content images.
         * This is used only by keyword-list and spreadsheet content when the
         * configured image mode allows Pexels.
         */
        public static function fetch_content_image_sections_from_pexels($generator, $item, $article, $max_images = 20)
        {
            $settings = self::get_settings();
            $api_key = !empty($settings['pexels_api_key']) ? trim((string) $settings['pexels_api_key']) : '';
            if ($api_key === '') {
                return array();
            }

            $query = self::build_pexels_query($generator, $item, $article);
            if ($query === '') {
                return array();
            }

            $response = wp_remote_get(add_query_arg(array(
                'query' => $query,
                'per_page' => max(5, min(80, intval($max_images))),
                'orientation' => 'landscape',
            ), 'https://api.pexels.com/v1/search'), array(
                'timeout' => 45,
                'headers' => array(
                    'Authorization' => $api_key,
                ),
            ));
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return array();
            }

            $data = json_decode((string) wp_remote_retrieve_body($response), true);
            if (empty($data['photos']) || !is_array($data['photos'])) {
                return array();
            }

            $images = array();
            $seen = array();
            foreach ($data['photos'] as $photo) {
                if (!is_array($photo) || empty($photo['src']) || !is_array($photo['src'])) {
                    continue;
                }

                $image_url = '';
                foreach (array('large', 'large2x', 'original') as $source_key) {
                    if (!empty($photo['src'][$source_key])) {
                        $image_url = esc_url_raw((string) $photo['src'][$source_key]);
                        break;
                    }
                }
                if ($image_url === '' || self::is_probably_bad_featured_image_url($image_url, $query)) {
                    continue;
                }

                $image_key = strtolower(rtrim($image_url, '#'));
                if (isset($seen[$image_key])) {
                    continue;
                }
                $seen[$image_key] = true;
                $images[] = array(
                    'url' => $image_url,
                    'source' => 'pexels',
                    'credit' => !empty($photo['photographer']) ? sanitize_text_field((string) $photo['photographer']) : '',
                );
                if (count($images) >= max(1, min(80, intval($max_images)))) {
                    break;
                }
            }

            return empty($images) ? array() : array(
                array(
                    'h2' => '',
                    'heading_level' => 2,
                    'images' => $images,
                ),
            );
        }

        public static function resolve_content_image_sections_for_generation($item, $generator, $article = array())
        {
            $generator = is_array($generator) ? $generator : array();
            $article = is_array($article) ? $article : array();
            $content_html = !empty($article['content_html']) ? (string) $article['content_html'] : '';
            if ($content_html !== '') {
                $interval_words = !empty($generator['content_image_interval_words'])
                    ? max(100, min(5000, intval($generator['content_image_interval_words'])))
                    : 500;
                $word_count = preg_match_all('/\S+/u', trim(wp_strip_all_tags($content_html)));
                $target_image_count = max(1, (int) floor($word_count / $interval_words));
                $existing_image_count = preg_match_all('/<img\b/i', $content_html);
                if ($existing_image_count >= $target_image_count) {
                    return array();
                }
            }
            $image_source_mode = !empty($generator['image_source_mode'])
                ? self::normalize_image_source_mode(
                    !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
                    $generator['image_source_mode'],
                    isset($generator['pexels_enabled']) ? !empty($generator['pexels_enabled']) : null,
                    !empty($generator['keyword_list_mode']) ? $generator['keyword_list_mode'] : self::get_default_keyword_list_mode()
                )
                : self::get_default_image_source_mode();

            $sections = array();
            if (self::image_source_mode_uses_source_image($image_source_mode) && self::generator_uses_source_content_images($generator)) {
                $sections = Content_Rank_Generator_Helper::resolve_content_image_sections_for_item($item, $generator);
            }

            if (!empty($sections)) {
                return $sections;
            }

            if (self::image_source_mode_uses_pexels($image_source_mode)) {
                return self::fetch_content_image_sections_from_pexels($generator, $item, $article, 20);
            }

            return array();
        }

        public static function generator_uses_interval_content_images($generator)
        {
            $generator = is_array($generator) ? $generator : array();
            $source_type = !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss';
            // RSS keeps the original source-media pipeline. The interval field
            // belongs only to keyword lists and spreadsheets.
            return self::source_type_uses_keyword_list($source_type);
        }

        public static function prepare_generator_record($generator)
        {
            if (!is_array($generator)) {
                return $generator;
            }

            $settings = self::get_settings();
            $source_type = !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss';
            // Older generators stored every list-backed source as keyword_list.
            // Resolve the label from the linked list so imported spreadsheets keep
            // their original behavior and are shown as Planilha in the editor.
            if ($source_type === 'keyword_list' && !empty($generator['list_id'])) {
                $linked_list = self::get_keyword_list(intval($generator['list_id']));
                if ($linked_list && sanitize_key((string) $linked_list['file_type']) !== 'keyword_list') {
                    $source_type = 'spreadsheet';
                }
            }
            $generation_mode = isset($generator['generation_mode']) ? self::normalize_generation_mode((string) $generator['generation_mode']) : self::get_default_generation_mode();
            $keyword_list_mode = isset($generator['keyword_list_mode']) ? sanitize_key((string) $generator['keyword_list_mode']) : self::get_default_keyword_list_mode();
            if (!self::source_type_uses_keyword_list($source_type)) {
                $keyword_list_mode = self::get_default_keyword_list_mode();
            }
            if ($source_type === 'keyword_list') {
                $keyword_list_mode = 'keywords';
            }
            $legacy_pexels_enabled = isset($generator['pexels_enabled']) ? !empty($generator['pexels_enabled']) : null;
            $image_source_mode = isset($generator['image_source_mode']) ? (string) $generator['image_source_mode'] : '';
            $image_source_mode = self::normalize_image_source_mode($source_type, $image_source_mode, $legacy_pexels_enabled, $keyword_list_mode);
            $prompt_template = isset($generator['prompt_template']) ? trim((string) $generator['prompt_template']) : '';
            if ($prompt_template === '' || self::prompt_template_looks_like_rss_default($prompt_template) || self::prompt_template_looks_like_keyword_default($prompt_template)) {
                $prompt_template = self::normalize_prompt_template_for_source_type($source_type, '', $keyword_list_mode);
            }
            $content_prompt_template = isset($generator['content_prompt_template']) ? trim((string) $generator['content_prompt_template']) : '';
            if ($content_prompt_template === '') {
                $content_prompt_template = self::get_default_content_prompt_template_visible();
            }
            $prompt_models = self::get_prompt_models();
            if (empty($prompt_models)) {
                $prompt_models = self::get_default_prompt_models();
            }
            $prompt_model_key = isset($generator['prompt_model_key']) ? sanitize_key((string) $generator['prompt_model_key']) : '';
            $prompt_model_keys = array();
            foreach ($prompt_models as $prompt_model) {
                if (!empty($prompt_model['key'])) {
                    $prompt_model_keys[] = (string) $prompt_model['key'];
                }
            }
            if ($prompt_model_key !== '' && !empty($prompt_model_keys) && !in_array($prompt_model_key, $prompt_model_keys, true)) {
                $prompt_model_key = '';
            }
            $generator['content_length_class'] = isset($generator['content_length_class']) ? self::normalize_content_length_class((string) $generator['content_length_class']) : self::get_default_content_length_class();
            $generator['generation_mode'] = $generation_mode;
            $generator['source_post_id'] = $generation_mode === 'satellite' ? 0 : (isset($generator['source_post_id']) ? max(0, intval($generator['source_post_id'])) : 0);
            $generator['default_category_id'] = isset($generator['default_category_id']) ? max(0, intval($generator['default_category_id'])) : 0;
            $generator['model'] = $settings['default_model'];
            $generator['temperature'] = floatval($settings['default_temperature']);
            $generator['max_tokens'] = intval($settings['default_max_tokens']);
            $generator['content_length_class'] = self::get_default_content_length_class();
            $generator['prompt_model_key'] = $prompt_model_key;
            $generator['prompt_models'] = $prompt_models;
            $generator['prompt_models_json'] = wp_json_encode($prompt_models, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $generator['outline_model_key'] = isset($generator['outline_model_key']) ? sanitize_key((string) $generator['outline_model_key']) : self::get_default_outline_model_key();
            $available_outline_models = self::get_outline_models();
            $available_outline_model_keys = array();
            foreach ($available_outline_models as $outline_model) {
                if (!empty($outline_model['key'])) {
                    $available_outline_model_keys[] = (string) $outline_model['key'];
                }
            }
            if ($generator['outline_model_key'] === '' || (!empty($available_outline_model_keys) && !in_array($generator['outline_model_key'], $available_outline_model_keys, true))) {
                $generator['outline_model_key'] = self::get_default_outline_model_key();
            }

            $generator['keyword_list_mode'] = $keyword_list_mode;
            $generator['tmdb_title_translation_enabled'] = !empty($generator['tmdb_title_translation_enabled']) ? 1 : 0;
            $generator['tmdb_thumbnail_bg_color'] = self::normalize_hex_color(isset($generator['tmdb_thumbnail_bg_color']) ? $generator['tmdb_thumbnail_bg_color'] : '#c91414');
            $generator['image_source_mode'] = $image_source_mode;
            $generator['image_selector_class'] = isset($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '';
            $generator['link_selector_class'] = isset($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '';
            $generator['content_selector'] = isset($generator['content_selector']) ? sanitize_text_field((string) $generator['content_selector']) : '';
            $generator['content_image_size'] = self::get_content_image_size_for_generator($generator);
            $generator['content_image_interval_words'] = isset($generator['content_image_interval_words'])
                ? max(100, min(5000, intval($generator['content_image_interval_words'])))
                : 500;
            $generator['content_length_class'] = self::normalize_content_length_class(isset($generator['content_length_class']) ? $generator['content_length_class'] : self::get_default_content_length_class());
            $generator['source_content_images_enabled'] = isset($generator['source_content_images_enabled'])
                ? (!empty($generator['source_content_images_enabled']) ? 1 : 0)
                : (isset($generator['source_content_media_enabled']) ? (!empty($generator['source_content_media_enabled']) ? 1 : 0) : 1);
            $generator['source_content_links_enabled'] = isset($generator['source_content_links_enabled'])
                ? (!empty($generator['source_content_links_enabled']) ? 1 : 0)
                : (isset($generator['source_content_media_enabled']) ? (!empty($generator['source_content_media_enabled']) ? 1 : 0) : 1);
            $generator['source_link_phrases'] = isset($generator['source_link_phrases']) ? sanitize_textarea_field((string) $generator['source_link_phrases']) : '';
            $generator['pexels_query'] = !empty($settings['pexels_query']) ? sanitize_text_field($settings['pexels_query']) : self::get_default_pexels_query();
            $source_context_filters = array(
                'exclude_phrases' => array(),
                'rating_label' => !empty($settings['source_context_rating_label']) ? sanitize_text_field($settings['source_context_rating_label']) : 'IMDb',
                'min_rating' => isset($settings['source_context_min_rating']) ? max(0, floatval(str_replace(',', '.', sanitize_text_field((string) $settings['source_context_min_rating'])))) : 0,
                'keep_unrated' => !empty($settings['source_context_keep_unrated']) ? 1 : 0,
            );
            if (!empty($generator['source_context_filters_json'])) {
                $decoded_filters = json_decode((string) $generator['source_context_filters_json'], true);
                if (is_array($decoded_filters)) {
                    $source_context_filters = Content_Rank_Generator_Helper::normalize_source_context_filters($decoded_filters);
                }
            }
            $generator['source_context_filters_json'] = isset($generator['source_context_filters_json']) ? trim((string) $generator['source_context_filters_json']) : '';
            $generator['source_context_exclude_phrases'] = !empty($source_context_filters['exclude_phrases']) ? implode("\n", (array) $source_context_filters['exclude_phrases']) : '';
            $generator['source_context_rating_label'] = isset($source_context_filters['rating_label']) ? (string) $source_context_filters['rating_label'] : 'IMDb';
            $generator['source_context_min_rating'] = isset($source_context_filters['min_rating']) ? (string) $source_context_filters['min_rating'] : '0';
            $generator['source_context_keep_unrated'] = !empty($source_context_filters['keep_unrated']) ? 1 : 0;
            $generator['pexels_enabled'] = self::image_source_mode_uses_pexels($image_source_mode) ? 1 : 0;
            $generator['source_type'] = $source_type;
            $generator['prompt_template'] = $prompt_template;
            $generator['content_prompt_template'] = $content_prompt_template;

            return $generator;
        }

        public static function sanitize_settings($raw)
        {
            $current = self::get_settings();
            $current['openai_api_key'] = isset($raw['openai_api_key']) ? sanitize_text_field(wp_unslash($raw['openai_api_key'])) : '';
            $current['pexels_api_key'] = isset($raw['pexels_api_key']) ? sanitize_text_field(wp_unslash($raw['pexels_api_key'])) : '';
            $current['tmdb_read_access_token'] = isset($raw['tmdb_read_access_token']) ? sanitize_text_field(wp_unslash($raw['tmdb_read_access_token'])) : $current['tmdb_read_access_token'];
            $current['tmdb_api_key'] = isset($raw['tmdb_api_key']) ? sanitize_text_field(wp_unslash($raw['tmdb_api_key'])) : $current['tmdb_api_key'];
            if (isset($raw['global_internal_links_json'])) {
                $global_internal_links_raw = wp_unslash($raw['global_internal_links_json']);
                $current['global_internal_links_json'] = wp_json_encode(Content_Rank_Generator_Helper::parse_internal_link_rules($global_internal_links_raw), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (isset($raw['blacklist_json'])) {
                $current['blacklist_json'] = wp_json_encode(
                    Content_Rank_Global_Filters::sanitize_textarea_entries(
                        wp_unslash($raw['blacklist_json']),
                        isset($current['blacklist_json']) ? $current['blacklist_json'] : array()
                    ),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
            $current['tavily_api_key'] = isset($raw['tavily_api_key']) ? sanitize_text_field(wp_unslash($raw['tavily_api_key'])) : $current['tavily_api_key'];
            $current['tavily_enabled'] = isset($raw['tavily_enabled']) ? (!empty($raw['tavily_enabled']) ? 1 : 0) : 0;
            $current['tavily_max_results'] = isset($raw['tavily_max_results']) ? max(1, min(10, intval($raw['tavily_max_results']))) : $current['tavily_max_results'];
            $current['tavily_include_answer'] = isset($raw['tavily_include_answer']) ? (!empty($raw['tavily_include_answer']) ? 1 : 0) : 0;
            $current['tavily_search_depth'] = isset($raw['tavily_search_depth']) ? sanitize_key(wp_unslash($raw['tavily_search_depth'])) : $current['tavily_search_depth'];
            if (!in_array($current['tavily_search_depth'], array('basic', 'advanced'), true)) {
                $current['tavily_search_depth'] = 'basic';
            }
            $current['default_model'] = isset($raw['default_model']) ? sanitize_text_field(wp_unslash($raw['default_model'])) : $current['default_model'];
            $current['default_temperature'] = isset($raw['default_temperature']) ? floatval($raw['default_temperature']) : $current['default_temperature'];
            $current['default_max_tokens'] = isset($raw['default_max_tokens']) ? max(256, intval($raw['default_max_tokens'])) : $current['default_max_tokens'];
            $current['semantic_dedup_enabled'] = isset($raw['semantic_dedup_enabled']) ? (!empty($raw['semantic_dedup_enabled']) ? 1 : 0) : $current['semantic_dedup_enabled'];
            $current['semantic_dedup_model'] = isset($raw['semantic_dedup_model']) ? sanitize_text_field(wp_unslash($raw['semantic_dedup_model'])) : $current['semantic_dedup_model'];
            $current['semantic_dedup_threshold'] = isset($raw['semantic_dedup_threshold']) ? max(0.0, min(1.0, floatval($raw['semantic_dedup_threshold']))) : $current['semantic_dedup_threshold'];
            $current['semantic_dedup_lookback'] = isset($raw['semantic_dedup_lookback']) ? max(25, intval($raw['semantic_dedup_lookback'])) : $current['semantic_dedup_lookback'];
            return $current;
        }



        public static function redirect_with_notice($message, $type = 'success', $extra = array())
        {
            $url = add_query_arg(array_merge(array(
                'page' => 'content-rank',
                'content_rank_notice' => $message,
                'content_rank_notice_type' => $type,
            ), $extra), admin_url('admin.php'));
            wp_safe_redirect($url);
            exit;
        }

        public static function get_generators($limit = 200)
        {
            global $wpdb;
            $limit = max(1, intval($limit));
            $generators = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::$table_generators . " ORDER BY created_at DESC LIMIT %d", $limit), ARRAY_A);
            if (!is_array($generators)) {
                return array();
            }

            foreach ($generators as &$generator) {
                $generator = self::prepare_generator_record($generator);
            }
            unset($generator);

            return $generators;
        }

        public static function get_generator($id)
        {
            global $wpdb;
            $generator = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$table_generators . " WHERE id = %d", intval($id)), ARRAY_A);
            return self::prepare_generator_record($generator);
        }

        public static function get_keyword_lists($limit = 200)
        {
            global $wpdb;
            $limit = max(1, intval($limit));
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::$table_lists . " ORDER BY created_at DESC LIMIT %d", $limit), ARRAY_A);
        }

        public static function get_keyword_list($id)
        {
            global $wpdb;
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$table_lists . " WHERE id = %d", intval($id)), ARRAY_A);
        }

        public static function get_post_view_link($post_id)
        {
            $post_id = intval($post_id);
            if ($post_id <= 0) {
                return '';
            }

            $post_status = get_post_status($post_id);
            if ($post_status === 'publish') {
                $permalink = get_permalink($post_id);
                if (!empty($permalink)) {
                    return $permalink;
                }
            } else {
                $preview_link = get_preview_post_link($post_id);
                if (!empty($preview_link)) {
                    return $preview_link;
                }
            }

            $permalink = get_permalink($post_id);
            return !empty($permalink) ? $permalink : '';
        }

        public static function get_post_edit_link($post_id)
        {
            $post_id = intval($post_id);
            if ($post_id <= 0) {
                return '';
            }

            $edit_link = get_edit_post_link($post_id, 'raw');
            if (!empty($edit_link)) {
                if (strpos($edit_link, 'action=edit') === false) {
                    $edit_link = add_query_arg('action', 'edit', $edit_link);
                }
                return $edit_link;
            }

            return admin_url('post.php?post=' . $post_id . '&action=edit');
        }

        public static function bulk_tables()
        {
            return array(
                'lists' => self::$table_lists,
                'rows' => self::$table_list_rows,
            );
        }

        public static function bulk_normalize_key($value)
        {
            $value = remove_accents((string) $value);
            $value = strtolower(trim($value));
            $value = preg_replace('/[^a-z0-9]+/', '', $value);
            return $value;
        }

        public static function bulk_sanitize_cell($value)
        {
            if (is_null($value)) {
                return '';
            }

            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            if (is_array($value) || is_object($value)) {
                return '';
            }

            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }

            $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
            return trim(wp_strip_all_tags($value));
        }

        public static function bulk_make_unique_header($header, $existing_headers)
        {
            $base = trim((string) $header);
            if ($base === '') {
                $base = 'Column';
            }

            $candidate = $base;
            $counter = 2;
            while (in_array($candidate, $existing_headers, true)) {
                $candidate = $base . ' ' . $counter;
                $counter++;
            }

            return $candidate;
        }

        public static function bulk_detect_column_map($headers)
        {
            $normalized_headers = array();
            foreach ($headers as $header) {
                $normalized_headers[self::bulk_normalize_key($header)] = $header;
            }

            $find = function ($candidates) use ($normalized_headers) {
                foreach ($candidates as $candidate) {
                    $key = self::bulk_normalize_key($candidate);
                    if (isset($normalized_headers[$key])) {
                        return $normalized_headers[$key];
                    }
                }
                return '';
            };

            $source_url_column = $find(array('url', 'sourceurl', 'source_url', 'link', 'pageurl', 'page_url'));

            return array(
                'keyword_column' => $find(array('keyword', 'keywords', 'frasechave', 'frase-chave', 'termo', 'termos')),
                'source_title_column' => $find(array('title', 'título', 'títulooriginal', 'headline')),
                'source_url_column' => $source_url_column,
                'slug_column' => $find(array('slug', 'finalslug', 'final_url', 'finalurl')) ?: $source_url_column,
                'content_column' => $find(array('content', 'conteúdo', 'body', 'text')),
                'tags_column' => $find(array('tags', 'tag')),
            );
        }

        public static function bulk_detect_csv_delimiter($file_path)
        {
            $file_path = (string) $file_path;
            if ($file_path === '') {
                return ',';
            }

            $line = '';
            if (function_exists('wp_read_file')) {
                $contents = wp_read_file($file_path);
                if (is_string($contents) && $contents !== '') {
                    $line_parts = preg_split('/\r\n|\r|\n/', $contents);
                    $line = !empty($line_parts) ? (string) reset($line_parts) : '';
                }
            }

            if ($line === false) {
                return ',';
            }

            $delimiters = array(',', ';', "\t", '|');
            $best_delimiter = ',';
            $best_count = 0;

            foreach ($delimiters as $delimiter) {
                $count = substr_count($line, $delimiter);
                if ($count > $best_count) {
                    $best_count = $count;
                    $best_delimiter = $delimiter;
                }
            }

            return $best_delimiter;
        }



        public static function bulk_row_to_assoc($headers, $row_values)
        {
            $row_data = array();
            foreach ($headers as $index => $header) {
                $row_data[$header] = isset($row_values[$index]) ? self::bulk_sanitize_cell($row_values[$index]) : '';
            }

            return $row_data;
        }

        public static function bulk_blocked_slug_extensions()
        {
            return array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'zip', 'rar', '7z', 'tar', 'gz', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp3', 'mp4', 'avi', 'mov', 'mkv', 'wmv', 'xml', 'json', 'js', 'css', 'txt', 'odt', 'html', 'htm', 'php', 'asp', 'aspx', 'jsp', 'xhtml', 'shtml');
        }

        public static function bulk_extract_slug_from_candidate($candidate)
        {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                return array(
                    'slug' => '',
                    'extension' => '',
                    'valid' => false,
                    'source_url' => '',
                    'original' => '',
                );
            }

            $is_url = filter_var($candidate, FILTER_VALIDATE_URL);
            $source_url = $is_url ? $candidate : '';
            $path = $is_url ? (string) wp_parse_url($candidate, PHP_URL_PATH) : $candidate;
            $path = trim((string) $path);
            $path = trim($path, '/');

            if ($path === '') {
                return array(
                    'slug' => '',
                    'extension' => '',
                    'valid' => false,
                    'source_url' => $source_url,
                    'original' => $candidate,
                );
            }

            $parts = array_values(array_filter(explode('/', $path)));
            $last_part = !empty($parts) ? end($parts) : $path;
            $extension = strtolower(pathinfo($last_part, PATHINFO_EXTENSION));
            if ($extension && in_array($extension, self::bulk_blocked_slug_extensions(), true)) {
                return array(
                    'slug' => '',
                    'extension' => $extension,
                    'valid' => false,
                    'source_url' => $source_url,
                    'original' => $candidate,
                );
            }

            $slug_source = $extension ? pathinfo($last_part, PATHINFO_FILENAME) : $last_part;
            $slug = sanitize_title(rawurldecode($slug_source));

            return array(
                'slug' => $slug,
                'extension' => $extension,
                'valid' => $slug !== '',
                'source_url' => $source_url,
                'original' => $candidate,
            );
        }

        public static function bulk_resolve_slug_info($candidate)
        {
            $slug_info = self::bulk_extract_slug_from_candidate($candidate);
            if (!empty($slug_info['valid'])) {
                return $slug_info;
            }

            return $slug_info;
        }

        public static function bulk_normalize_url_for_dedupe($candidate)
        {
            $candidate = trim((string) $candidate);
            $candidate = self::resolve_google_alerts_redirect_url($candidate);
            if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_URL)) {
                return '';
            }

            $parts = wp_parse_url($candidate);
            if (empty($parts['host'])) {
                return '';
            }

            $host = strtolower($parts['host']);
            $host = preg_replace('/^www\./i', '', $host);
            $port = isset($parts['port']) ? intval($parts['port']) : 0;
            if ($port > 0 && !in_array($port, array(80, 443), true)) {
                $host .= ':' . $port;
            }

            $path = isset($parts['path']) ? rawurldecode((string) $parts['path']) : '';
            $path = preg_replace('#/+#', '/', $path);
            $path = rtrim($path, '/');
            if ($path === '/') {
                $path = '';
            }

            return $host . $path;
        }

        public static function bulk_find_row_value($row_data, $column_name)
        {
            if (empty($column_name) || !is_array($row_data)) {
                return '';
            }

            $target_key = self::bulk_normalize_key($column_name);
            foreach ($row_data as $header => $value) {
                if (self::bulk_normalize_key($header) === $target_key) {
                    return self::bulk_sanitize_cell($value);
                }
            }

            return '';
        }

        public static function bulk_parse_timestamp_value($value)
        {
            $raw = trim((string) $value);
            if ($raw === '') {
                return '';
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                return $raw . ' 00:00:00';
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $raw)) {
                return preg_replace('/\s+/', ' ', $raw) . ':00';
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $raw)) {
                return preg_replace('/\s+/', ' ', $raw);
            }

            $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');

            try {
                $numeric = is_numeric($raw) ? (float) $raw : null;
                $digits_only = preg_match('/^\d+$/', $raw) ? $raw : '';

                if ($digits_only !== '' && strlen($digits_only) === 8) {
                    $date = DateTime::createFromFormat('Ymd', $digits_only, $timezone);
                    if ($date instanceof DateTimeInterface) {
                        return $date->format('Y-m-d H:i:s');
                    }
                }

                if ($digits_only !== '' && strlen($digits_only) === 14) {
                    $date = DateTime::createFromFormat('YmdHis', $digits_only, $timezone);
                    if ($date instanceof DateTimeInterface) {
                        return $date->format('Y-m-d H:i:s');
                    }
                }

                if ($numeric !== null && $numeric > 0 && $numeric < 1000000 && class_exists('\PhpOffice\PhpSpreadsheet\Shared\Date')) {
                    $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($numeric);
                    if ($date instanceof DateTimeInterface) {
                        return $date->format('Y-m-d H:i:s');
                    }
                }

                if ($digits_only !== '' && strlen($digits_only) >= 13 && $numeric !== null) {
                    $timestamp = intval(round($numeric / 1000));
                    $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
                    return $date->format('Y-m-d H:i:s');
                }

                if ($digits_only !== '' && strlen($digits_only) >= 10 && $numeric !== null) {
                    $timestamp = intval(round($numeric));
                    $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
                    return $date->format('Y-m-d H:i:s');
                }

                $timestamp = strtotime($raw);
                if ($timestamp !== false) {
                    $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
                    return $date->format('Y-m-d H:i:s');
                }
            } catch (Exception $exception) {
                return '';
            }

            return '';
        }

        public static function bulk_extract_row_timestamp($row_data)
        {
            if (!is_array($row_data)) {
                return '';
            }

            $timestamp_columns = array(
                'Timestamp',
                'timestamp',
                'Data',
                'data',
                'Date',
                'date',
                'Published',
                'published',
                'Publication Date',
                'publication_date',
            );

            foreach ($timestamp_columns as $column_name) {
                $value = self::bulk_find_row_value($row_data, $column_name);
                if ($value === '') {
                    continue;
                }

                $normalized = self::bulk_parse_timestamp_value($value);
                if ($normalized !== '') {
                    return $normalized;
                }
            }

            return '';
        }



        public static function bulk_row_matches_filters($row_data, $filters)
        {
            if (empty($filters) || !is_array($filters)) {
                return true;
            }

            foreach ($filters as $filter) {
                if (!is_array($filter)) {
                    continue;
                }

                $column = isset($filter['column']) ? $filter['column'] : '';
                $operator = isset($filter['operator']) ? strtolower(trim($filter['operator'])) : 'contains';
                $expected = isset($filter['value']) ? (string) $filter['value'] : '';
                $value = self::bulk_find_row_value($row_data, $column);

                if ($column === '' && $operator !== 'empty' && $operator !== 'not_empty') {
                    return false;
                }

                $value_normalized = strtolower(trim((string) $value));
                $expected_normalized = strtolower(trim((string) $expected));

                switch ($operator) {
                    case 'equals':
                    case '=':
                    case 'eq':
                        if ($value_normalized !== $expected_normalized) {
                            return false;
                        }
                        break;
                    case 'not_equals':
                    case '!=':
                    case '<>':
                    case 'ne':
                        if ($value_normalized === $expected_normalized) {
                            return false;
                        }
                        break;
                    case 'greater':
                    case '>':
                    case 'gt':
                        if (floatval(str_replace(',', '.', $value)) <= floatval(str_replace(',', '.', $expected))) {
                            return false;
                        }
                        break;
                    case 'greater_or_equal':
                    case '>=':
                    case 'gte':
                        if (floatval(str_replace(',', '.', $value)) < floatval(str_replace(',', '.', $expected))) {
                            return false;
                        }
                        break;
                    case 'less':
                    case '<':
                    case 'lt':
                        if (floatval(str_replace(',', '.', $value)) >= floatval(str_replace(',', '.', $expected))) {
                            return false;
                        }
                        break;
                    case 'less_or_equal':
                    case '<=':
                    case 'lte':
                        if (floatval(str_replace(',', '.', $value)) > floatval(str_replace(',', '.', $expected))) {
                            return false;
                        }
                        break;
                    case 'empty':
                        if ($value_normalized !== '') {
                            return false;
                        }
                        break;
                    case 'not_empty':
                        if ($value_normalized === '') {
                            return false;
                        }
                        break;
                    case 'contains':
                    default:
                        if ($expected_normalized !== '' && mb_strpos($value_normalized, $expected_normalized) === false) {
                            return false;
                        }
                        break;
                }
            }

            return true;
        }

        public static function bulk_get_list_counts($list_id)
        {
            global $wpdb;
            $tables = self::bulk_tables();

            self::bulk_recover_stale_keyword_rows($list_id);
            self::bulk_prune_keyword_list_rows($list_id);

            $counts = array(
                'total_rows' => 0,
                'generated_rows' => 0,
                'pending_rows' => 0,
                'processing_rows' => 0,
                'invalid_rows' => 0,
                'failed_rows' => 0,
                'blocked_rows' => 0,
                'other_rows' => 0,
            );

            $counts['total_rows'] = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['rows']} WHERE list_id = %d", $list_id)));
            $counts['generated_rows'] = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['rows']} WHERE list_id = %d AND row_status = 'generated'", $list_id)));
            $counts['pending_rows'] = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['rows']} WHERE list_id = %d AND row_status = 'pending'", $list_id)));
            $counts['processing_rows'] = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['rows']} WHERE list_id = %d AND row_status = 'processing'", $list_id)));
            $counts['invalid_rows'] = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['rows']} WHERE list_id = %d AND row_status = 'invalid_slug'", $list_id)));
            $counts['failed_rows'] = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['rows']} WHERE list_id = %d AND row_status = 'failed'", $list_id)));
            $counts['blocked_rows'] = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['rows']} WHERE list_id = %d AND row_status = 'blocked'", $list_id)));
            $counts['other_rows'] = max(0, $counts['total_rows'] - $counts['generated_rows'] - $counts['pending_rows'] - $counts['processing_rows'] - $counts['invalid_rows'] - $counts['failed_rows'] - $counts['blocked_rows']);

            return $counts;
        }

        public static function bulk_recover_stale_keyword_rows($list_id, $stale_minutes = 15)
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $list_id = intval($list_id);
            $stale_minutes = max(5, intval($stale_minutes));
            if ($list_id <= 0) {
                return 0;
            }

            $cutoff = wp_date('Y-m-d H:i:s', time() - ($stale_minutes * MINUTE_IN_SECONDS), wp_timezone());
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$tables['rows']} SET row_status = 'failed', error_message = %s, updated_at = %s WHERE list_id = %d AND row_status = 'processing' AND post_id = 0 AND updated_at < %s",
                'Processamento interrompido antes da conclusao. O item pode ser tentado novamente.',
                current_time('mysql'),
                $list_id,
                $cutoff
            ));

            return false === $updated ? 0 : intval($updated);
        }

        public static function bulk_prune_keyword_list_rows($list_id)
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id FROM {$tables['rows']} WHERE list_id = %d AND row_status IN ('invalid_slug', 'duplicate')",
                intval($list_id)
            ));

            if (empty($rows)) {
                return 0;
            }

            $removed = 0;
            foreach ($rows as $row) {
                $wpdb->delete($tables['rows'], array('id' => intval($row->id)), array('%d'));
                $removed++;
            }

            return $removed;
        }

        public static function bulk_refresh_list_counts($list_id)
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $counts = self::bulk_get_list_counts($list_id);

            $wpdb->update(
                $tables['lists'],
                array(
                    'total_rows' => $counts['total_rows'],
                    'generated_rows' => $counts['generated_rows'],
                    'pending_rows' => $counts['pending_rows'],
                    'invalid_rows' => $counts['invalid_rows'],
                    'failed_rows' => $counts['failed_rows'],
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => $list_id),
                array('%d', '%d', '%d', '%d', '%d', '%s'),
                array('%d')
            );

            return $counts;
        }

        public static function bulk_get_pending_rows_batch($list_id, $limit = 50, $offset = 0)
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $limit = max(1, intval($limit));
            $offset = max(0, intval($offset));

            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$tables['rows']} WHERE list_id = %d AND row_status = 'pending' ORDER BY `row_number` ASC LIMIT %d OFFSET %d",
                $list_id,
                $limit,
                $offset
            ));
        }

        public static function bulk_find_next_keyword_row($list_id, $filters = array(), $source_context_filters = array())
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $scan_offset = 0;
            $scan_limit = 250;
            $list = null;
            $use_source_context_filters = !empty($source_context_filters) && is_array($source_context_filters);
            $use_global_filters = class_exists('Content_Rank_Global_Filters');
            if ($use_source_context_filters || $use_global_filters) {
                $list = self::get_keyword_list($list_id);
                if (!$list) {
                    return null;
                }
            }

            while (true) {
                $pending_rows = self::bulk_get_pending_rows_batch($list_id, $scan_limit, $scan_offset);
                if (empty($pending_rows)) {
                    return null;
                }

                foreach ($pending_rows as $row) {
                    $row_data = json_decode($row->row_data, true);
                    if (!is_array($row_data)) {
                        continue;
                    }

                    if (intval($row->slug_is_valid) !== 1) {
                        $wpdb->update(
                            $tables['rows'],
                            array(
                                'row_status' => 'invalid_slug',
                                'updated_at' => current_time('mysql'),
                            ),
                            array('id' => intval($row->id)),
                            array('%s', '%s'),
                            array('%d')
                        );
                        continue;
                    }

                    if (!self::bulk_row_matches_filters($row_data, $filters)) {
                        continue;
                    }

                    if ($use_global_filters) {
                        $temp_item = self::build_keyword_list_item_from_row($list, $row, false, '', '', '', $source_context_filters);
                        if (Content_Rank_Global_Filters::item_is_blocked($temp_item)) {
                            continue;
                        }
                    }

                    if ($use_source_context_filters) {
                        $temp_item = self::build_keyword_list_item_from_row($list, $row, false, '', '', '', $source_context_filters);
                        if (!Content_Rank_Generator_Helper::source_context_item_matches_filters($temp_item, $source_context_filters)) {
                            continue;
                        }
                    }

                    return $row;
                }

                $scan_offset += $scan_limit;
            }
        }

        public static function bulk_count_matching_keyword_rows($list_id, $filters = array(), $source_context_filters = array())
        {
            $scan_offset = 0;
            $scan_limit = 250;
            $available = 0;
            $list = null;
            $use_source_context_filters = !empty($source_context_filters) && is_array($source_context_filters);
            $use_global_filters = class_exists('Content_Rank_Global_Filters');
            if ($use_source_context_filters || $use_global_filters) {
                $list = self::get_keyword_list($list_id);
                if (!$list) {
                    return 0;
                }
            }

            while (true) {
                $pending_rows = self::bulk_get_pending_rows_batch($list_id, $scan_limit, $scan_offset);
                if (empty($pending_rows)) {
                    break;
                }

                foreach ($pending_rows as $row) {
                    if (intval($row->slug_is_valid) !== 1) {
                        continue;
                    }

                    $row_data = json_decode($row->row_data, true);
                    if (!is_array($row_data)) {
                        continue;
                    }

                    if (!self::bulk_row_matches_filters($row_data, $filters)) {
                        continue;
                    }

                    if ($use_global_filters) {
                        $temp_item = self::build_keyword_list_item_from_row($list, $row, false, '', '', '', $source_context_filters);
                        if (Content_Rank_Global_Filters::item_is_blocked($temp_item)) {
                            continue;
                        }
                    }

                    if ($use_source_context_filters) {
                        $temp_item = self::build_keyword_list_item_from_row($list, $row, false, '', '', '', $source_context_filters);
                        if (!Content_Rank_Generator_Helper::source_context_item_matches_filters($temp_item, $source_context_filters)) {
                            continue;
                        }
                    }

                    $available++;
                }

                $scan_offset += $scan_limit;
            }

            return $available;
        }

        public static function bulk_build_manual_generator($list, $settings = array())
        {
            $settings = is_array($settings) ? $settings : array();

            $category_ids = isset($settings['category_ids']) ? $settings['category_ids'] : array();
            if (!is_array($category_ids)) {
                $category_ids = self::parse_list_field($category_ids);
            }

            $tags_default = isset($settings['tags_default']) ? $settings['tags_default'] : array();
            if (!is_array($tags_default)) {
                $tags_default = self::parse_list_field($tags_default);
            }

            $custom_taxonomies = isset($settings['custom_taxonomies']) ? $settings['custom_taxonomies'] : '';
            if (is_array($custom_taxonomies)) {
                $custom_taxonomies = wp_json_encode($custom_taxonomies);
            } else {
                $custom_taxonomies = wp_json_encode(self::parse_key_value_lines($custom_taxonomies));
            }

            $custom_meta = isset($settings['custom_meta']) ? $settings['custom_meta'] : '';
            if (is_array($custom_meta)) {
                $custom_meta = wp_json_encode($custom_meta);
            } else {
                $custom_meta = wp_json_encode(self::parse_key_value_lines($custom_meta));
            }

            $model = isset($settings['model']) ? sanitize_text_field($settings['model']) : '';
            if ($model === '') {
                $model = self::get_settings()['default_model'];
            }

            $temperature = isset($settings['temperature']) ? floatval($settings['temperature']) : floatval(self::get_settings()['default_temperature']);
            $temperature = max(0.0, min(2.0, $temperature));

            $max_tokens = isset($settings['max_tokens']) ? max(256, intval($settings['max_tokens'])) : intval(self::get_settings()['default_max_tokens']);

            $post_status = isset($settings['post_status']) ? sanitize_key($settings['post_status']) : 'draft';
            if (!in_array($post_status, array('draft', 'publish', 'pending', 'private', 'future'), true)) {
                $post_status = 'draft';
            }

            $post_type = isset($settings['post_type']) ? sanitize_key($settings['post_type']) : 'post';
            if (!post_type_exists($post_type)) {
                $post_type = 'post';
            }

            $keyword_list_mode = !empty($settings['keyword_list_mode']) ? sanitize_key((string) $settings['keyword_list_mode']) : 'keywords';
            $image_source_mode = !empty($settings['image_source_mode'])
                ? sanitize_key((string) $settings['image_source_mode'])
                : self::get_default_image_source_mode('keyword_list', $keyword_list_mode);

            return array(
                'id' => 0,
                'name' => !empty($list['list_name']) ? $list['list_name'] : 'Lista manual',
                'feed_url' => '',
                'source_type' => 'keyword_list',
                'list_id' => intval($list['id']),
                'keyword_list_mode' => $keyword_list_mode,
                'status' => 'active',
                'post_type' => $post_type,
                'post_status' => $post_status,
                'author_id' => self::normalize_content_author_id(isset($settings['author_id']) ? intval($settings['author_id']) : 0),
                'category_ids' => wp_json_encode(array_values(array_filter(array_map('intval', $category_ids)))),
                'tags_default' => wp_json_encode(array_values(array_filter(array_map('sanitize_text_field', $tags_default)))),
                'custom_taxonomies' => $custom_taxonomies,
                'custom_meta' => $custom_meta,
                'filters_json' => '',
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => $max_tokens,
                'posts_per_run' => 1,
                'schedule_type' => 'interval',
                'interval_minutes' => 180,
                'jitter_minutes' => 30,
                'daily_start' => '',
                'daily_end' => '',
                'image_source_mode' => $image_source_mode,
                'pexels_enabled' => 1,
                'pexels_query' => !empty($settings['pexels_query']) ? sanitize_text_field($settings['pexels_query']) : self::get_default_pexels_query(),
                'source_video_enabled' => !empty($settings['source_video_enabled']) ? 1 : 0,
                'source_content_images_enabled' => isset($settings['source_content_images_enabled']) ? (!empty($settings['source_content_images_enabled']) ? 1 : 0) : 1,
                'source_content_links_enabled' => isset($settings['source_content_links_enabled']) ? (!empty($settings['source_content_links_enabled']) ? 1 : 0) : 1,
                'video_selector_class' => !empty($settings['video_selector_class']) ? sanitize_text_field($settings['video_selector_class']) : '',
                'image_selector_class' => !empty($settings['image_selector_class']) ? sanitize_text_field($settings['image_selector_class']) : '',
                'link_selector_class' => !empty($settings['link_selector_class']) ? sanitize_text_field($settings['link_selector_class']) : '',
                'content_image_size' => !empty($settings['content_image_size']) ? self::normalize_image_display_size($settings['content_image_size']) : 'medium',
                'content_image_interval_words' => !empty($settings['content_image_interval_words']) ? max(100, min(5000, intval($settings['content_image_interval_words']))) : 500,
                'source_context_filters_json' => wp_json_encode(array(
                    'exclude_phrases' => Content_Rank_Generator_Helper::parse_source_context_filter_phrases(!empty($settings['source_context_exclude_phrases']) ? sanitize_textarea_field($settings['source_context_exclude_phrases']) : ''),
                    'rating_label' => !empty($settings['source_context_rating_label']) ? sanitize_text_field($settings['source_context_rating_label']) : 'IMDb',
                    'min_rating' => isset($settings['source_context_min_rating']) ? max(0, floatval(str_replace(',', '.', sanitize_text_field((string) $settings['source_context_min_rating'])))) : 0,
                    'keep_unrated' => !empty($settings['source_context_keep_unrated']) ? 1 : 0,
                )),
                'seo_enabled' => !empty($settings['seo_enabled']) ? 1 : 0,
                'generation_language' => !empty($settings['generation_language']) ? Content_Rank_Generator::normalize_generation_language_value($settings['generation_language']) : Content_Rank_Generator::get_default_generation_language(),
                'prompt_model_key' => '',
                'prompt_models_json' => wp_json_encode(self::get_default_prompt_models(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'prompt_models_initialized' => 0,
                'prompt_models_migrated_from_generator_id' => 0,
                'content_prompt_template' => '',
                'related_posts_enabled' => !empty($settings['related_posts_enabled']) ? 1 : 0,
                'related_posts_position' => !empty($settings['related_posts_position']) ? sanitize_key($settings['related_posts_position']) : 'end',
                'related_posts_interval' => !empty($settings['related_posts_interval']) ? max(1, intval($settings['related_posts_interval'])) : 4,
                'related_posts_min_h2' => !empty($settings['related_posts_min_h2']) ? max(0, intval($settings['related_posts_min_h2'])) : 1,
                'related_posts_links_per_block' => !empty($settings['related_posts_links_per_block']) ? max(1, intval($settings['related_posts_links_per_block'])) : 2,
                'related_posts_same_category_only' => !empty($settings['related_posts_same_category_only']) ? 1 : 0,
                'related_posts_allow_fallback' => !empty($settings['related_posts_allow_fallback']) ? 1 : 0,
                'related_posts_style' => !empty($settings['related_posts_style']) ? sanitize_key($settings['related_posts_style']) : 'list',
                'related_posts_phrases' => !empty($settings['related_posts_phrases']) ? sanitize_textarea_field($settings['related_posts_phrases']) : '',
                'source_link_phrases' => !empty($settings['source_link_phrases']) ? sanitize_textarea_field($settings['source_link_phrases']) : self::get_default_source_link_cta_phrases(),
                'prompt_template' => '',
                'content_prompt_template' => '',
            );
        }

        public static function bulk_rebuild_keyword_list_rows($list_id, $column_map)
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$tables['rows']} WHERE list_id = %d ORDER BY `row_number` ASC",
                intval($list_id)
            ));

            if (empty($rows)) {
                return array(
                    'updated' => 0,
                    'invalid' => 0,
                    'duplicate' => 0,
                );
            }

            $seen_canonical_urls = array();
            $seen_final_slugs = array();
            $updated = 0;
            $invalid = 0;
            $duplicate = 0;

            foreach ($rows as $row) {
                $current_status = isset($row->row_status) ? (string) $row->row_status : '';
                if (in_array($current_status, array('generated', 'failed', 'processing'), true)) {
                    continue;
                }

                $row_data = json_decode($row->row_data, true);
                if (!is_array($row_data)) {
                    $row_data = array();
                }

                $resolved = Content_Rank_Generator_Helper::bulk_resolve_keyword_row($row_data, $column_map);
                $row_status = $resolved['row_status'];
                $error_message = $resolved['error_message'];
                $canonical_source_url = $resolved['canonical_source_url'];
                $slug_key = $resolved['slug_key'];

                if ($row_status === 'pending') {
                    if ($canonical_source_url !== '' && isset($seen_canonical_urls[$canonical_source_url])) {
                        $row_status = 'duplicate';
                        $error_message = 'Linha duplicada por URL';
                        $duplicate++;
                    } elseif ($slug_key !== '' && isset($seen_final_slugs[$slug_key])) {
                        $row_status = 'duplicate';
                        $error_message = 'Linha duplicada por slug';
                        $duplicate++;
                    }
                }

                if ($row_status === 'invalid_slug') {
                    $invalid++;
                }

                if ($row_status !== 'pending') {
                    $wpdb->delete($tables['rows'], array('id' => intval($row->id)), array('%d'));
                    continue;
                }

                if ($canonical_source_url !== '') {
                    $seen_canonical_urls[$canonical_source_url] = true;
                }
                if ($slug_key !== '') {
                    $seen_final_slugs[$slug_key] = true;
                }

                $wpdb->update(
                    $tables['rows'],
                    array(
                        'keyword' => $resolved['keyword'],
                        'source_title' => $resolved['source_title'],
                        'source_url' => $resolved['source_url'],
                        'final_slug' => $resolved['final_slug'],
                        'slug_extension' => $resolved['slug_extension'],
                        'slug_is_valid' => intval($resolved['slug_is_valid']),
                        'row_status' => $row_status,
                        'error_message' => $error_message,
                        'updated_at' => current_time('mysql'),
                    ),
                    array('id' => intval($row->id)),
                    array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'),
                    array('%d')
                );

                $updated++;
            }

            return array(
                'updated' => $updated,
                'invalid' => $invalid,
                'duplicate' => $duplicate,
            );
        }

        public static function parse_list_field($value)
        {
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            $value = trim((string) $value);
            if ($value === '') {
                return array();
            }
            $parts = preg_split('/[,\n\r;]+/', $value);
            $parts = array_filter(array_map('trim', $parts));
            return array_values(array_unique($parts));
        }

        public static function parse_key_value_lines($value)
        {
            $result = array();
            $lines = preg_split('/\r\n|\n|\r/', (string) $value);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '=') === false) {
                    continue;
                }
                list($key, $raw) = array_map('trim', explode('=', $line, 2));
                if ($key !== '') {
                    $result[$key] = $raw;
                }
            }
            return $result;
        }

        public static function normalize_generator_payload($raw)
        {
            $settings = self::get_settings();
            $payload = array();

            $payload['name'] = isset($raw['name']) ? sanitize_text_field(wp_unslash($raw['name'])) : '';
            $payload['source_type'] = isset($raw['source_type']) ? sanitize_key($raw['source_type']) : 'rss';
            if (!in_array($payload['source_type'], array('rss', 'spreadsheet', 'keyword_list'), true)) {
                $payload['source_type'] = 'rss';
            }
            $payload['generation_mode'] = isset($raw['generation_mode']) ? self::normalize_generation_mode(sanitize_key(wp_unslash($raw['generation_mode']))) : self::get_default_generation_mode();
            $payload['source_post_id'] = isset($raw['source_post_id']) ? max(0, intval($raw['source_post_id'])) : 0;
            $payload['feed_url'] = isset($raw['feed_url']) ? esc_url_raw(wp_unslash($raw['feed_url'])) : '';
            $payload['list_id'] = isset($raw['list_id']) ? intval($raw['list_id']) : 0;
            if ($payload['source_type'] === 'keyword_list' && $payload['list_id'] > 0) {
                $linked_list = self::get_keyword_list($payload['list_id']);
                if ($linked_list && sanitize_key((string) $linked_list['file_type']) !== 'keyword_list') {
                    $payload['source_type'] = 'spreadsheet';
                }
            }
            $payload['status'] = isset($raw['status']) ? sanitize_key($raw['status']) : 'active';
            $payload['post_type'] = isset($raw['post_type']) ? sanitize_key($raw['post_type']) : 'post';
            $payload['post_status'] = isset($raw['post_status']) ? sanitize_key($raw['post_status']) : 'draft';
            $payload['author_id'] = self::normalize_content_author_id(isset($raw['author_id']) ? intval($raw['author_id']) : 0);
            $payload['category_ids'] = wp_json_encode(self::parse_list_field(isset($raw['category_ids']) ? $raw['category_ids'] : ''));
            $payload['default_category_id'] = isset($raw['default_category_id']) ? max(0, intval($raw['default_category_id'])) : 0;
            $payload['tags_default'] = wp_json_encode(self::parse_list_field(isset($raw['tags_default']) ? $raw['tags_default'] : ''));
            $payload['custom_taxonomies'] = wp_json_encode(self::parse_key_value_lines(isset($raw['custom_taxonomies']) ? wp_unslash($raw['custom_taxonomies']) : ''));
            $payload['custom_meta'] = wp_json_encode(self::parse_key_value_lines(isset($raw['custom_meta']) ? wp_unslash($raw['custom_meta']) : ''));
            $internal_links_raw = isset($raw['internal_links_json']) ? wp_unslash($raw['internal_links_json']) : '';
            if (is_array($internal_links_raw)) {
                $internal_links_raw = wp_json_encode($internal_links_raw);
            }
            $payload['internal_links_json'] = wp_json_encode(Content_Rank_Generator_Helper::parse_internal_link_rules($internal_links_raw));
            $filters_raw = isset($raw['filters_json']) ? wp_unslash($raw['filters_json']) : '';
            if (is_array($filters_raw)) {
                $filters_raw = wp_json_encode($filters_raw);
            }
            $payload['filters_json'] = trim((string) $filters_raw);
            $payload['model'] = $settings['default_model'];
            $payload['temperature'] = floatval($settings['default_temperature']);
            $payload['max_tokens'] = intval($settings['default_max_tokens']);
            $payload['content_length_class'] = self::get_default_content_length_class();
            $payload['posts_per_run'] = isset($raw['posts_per_run']) ? max(1, intval($raw['posts_per_run'])) : 1;
            $payload['schedule_type'] = isset($raw['schedule_type']) ? sanitize_key($raw['schedule_type']) : 'interval';
            $payload['interval_minutes'] = isset($raw['interval_minutes']) ? max(1, intval($raw['interval_minutes'])) : 180;
            $payload['jitter_minutes'] = isset($raw['jitter_minutes']) ? max(0, intval($raw['jitter_minutes'])) : 30;
            $payload['daily_start'] = isset($raw['daily_start']) ? sanitize_text_field(wp_unslash($raw['daily_start'])) : '';
            $payload['daily_end'] = isset($raw['daily_end']) ? sanitize_text_field(wp_unslash($raw['daily_end'])) : '';
            $payload['keyword_list_mode'] = isset($raw['keyword_list_mode']) ? sanitize_key(wp_unslash($raw['keyword_list_mode'])) : self::get_default_keyword_list_mode();
            if (!self::source_type_uses_keyword_list($payload['source_type'])) {
                $payload['keyword_list_mode'] = self::get_default_keyword_list_mode();
            }
            if (!in_array($payload['keyword_list_mode'], array('keywords', 'url_reference'), true)) {
                $payload['keyword_list_mode'] = self::get_default_keyword_list_mode();
            }
            if ($payload['source_type'] === 'keyword_list') {
                $payload['keyword_list_mode'] = 'keywords';
            }
            $payload['tavily_enabled'] = ($payload['source_type'] === 'keyword_list' && !empty($raw['tavily_enabled'])) ? 1 : 0;
            $payload['tmdb_title_translation_enabled'] = !empty($raw['tmdb_title_translation_enabled']) ? 1 : 0;
            $payload['tmdb_thumbnail_bg_color'] = self::normalize_hex_color(isset($raw['tmdb_thumbnail_bg_color']) ? wp_unslash($raw['tmdb_thumbnail_bg_color']) : '#c91414');
            if ($payload['generation_mode'] === 'satellite') {
                $payload['source_post_id'] = 0;
            }
            $legacy_pexels_enabled = isset($raw['pexels_enabled']) ? !empty($raw['pexels_enabled']) : null;
            $payload['image_source_mode'] = isset($raw['image_source_mode']) ? sanitize_key(wp_unslash($raw['image_source_mode'])) : '';
            $payload['image_source_mode'] = self::normalize_image_source_mode($payload['source_type'], $payload['image_source_mode'], $legacy_pexels_enabled, $payload['keyword_list_mode']);
            $payload['pexels_enabled'] = self::image_source_mode_uses_pexels($payload['image_source_mode']) ? 1 : 0;
            $payload['pexels_query'] = !empty($settings['pexels_query']) ? sanitize_text_field($settings['pexels_query']) : self::get_default_pexels_query();
            $payload['source_video_enabled'] = !empty($raw['source_video_enabled']) ? 1 : 0;
            $payload['source_content_images_enabled'] = isset($raw['source_content_images_enabled'])
                ? (!empty($raw['source_content_images_enabled']) ? 1 : 0)
                : (isset($raw['source_content_media_enabled']) ? (!empty($raw['source_content_media_enabled']) ? 1 : 0) : 1);
            $payload['source_content_links_enabled'] = isset($raw['source_content_links_enabled'])
                ? (!empty($raw['source_content_links_enabled']) ? 1 : 0)
                : (isset($raw['source_content_media_enabled']) ? (!empty($raw['source_content_media_enabled']) ? 1 : 0) : 1);
            $payload['video_selector_class'] = isset($raw['video_selector_class']) ? sanitize_text_field(wp_unslash($raw['video_selector_class'])) : '';
            $payload['image_selector_class'] = isset($raw['image_selector_class']) ? sanitize_text_field(wp_unslash($raw['image_selector_class'])) : '';
            $payload['link_selector_class'] = isset($raw['link_selector_class']) ? sanitize_text_field(wp_unslash($raw['link_selector_class'])) : '';
            $payload['content_selector'] = isset($raw['content_selector']) ? sanitize_text_field(wp_unslash($raw['content_selector'])) : '';
            $payload['content_image_size'] = isset($raw['content_image_size']) ? self::normalize_image_display_size(sanitize_key(wp_unslash($raw['content_image_size']))) : 'medium';
            $payload['content_image_interval_words'] = isset($raw['content_image_interval_words'])
                ? max(100, min(5000, intval($raw['content_image_interval_words'])))
                : 500;
            $payload['random_bolds_enabled'] = !empty($raw['random_bolds_enabled']) ? 1 : 0;
            $payload['seo_enabled'] = 1;
            $payload['generation_language'] = isset($raw['generation_language']) ? self::normalize_generation_language_value(sanitize_text_field(wp_unslash($raw['generation_language']))) : self::get_default_generation_language();
            $payload['prompt_template'] = isset($raw['prompt_template']) ? wp_kses_post(wp_unslash($raw['prompt_template'])) : '';
            $payload['prompt_template'] = self::normalize_prompt_template_for_source_type($payload['source_type'], $payload['prompt_template'], $payload['keyword_list_mode']);
            $payload['content_prompt_template'] = isset($raw['content_prompt_template']) ? wp_kses_post(wp_unslash($raw['content_prompt_template'])) : '';
            if (trim($payload['content_prompt_template']) === '') {
                $payload['content_prompt_template'] = self::get_default_content_prompt_template_visible();
            }
            $payload['prompt_model_key'] = isset($raw['prompt_model_key']) ? sanitize_key(wp_unslash($raw['prompt_model_key'])) : '';
            $available_prompt_models = self::get_prompt_models();
            $available_prompt_model_keys = array();
            foreach ($available_prompt_models as $prompt_model) {
                if (!empty($prompt_model['key'])) {
                    $available_prompt_model_keys[] = self::normalize_prompt_model_key((string) $prompt_model['key']);
                }
            }
            if ($payload['prompt_model_key'] !== '' && !in_array(self::normalize_prompt_model_key($payload['prompt_model_key']), $available_prompt_model_keys, true)) {
                $payload['prompt_model_key'] = '';
            } else {
                $payload['prompt_model_key'] = self::normalize_prompt_model_key($payload['prompt_model_key']);
            }
            $payload['prompt_models_json'] = '';
            $payload['outline_model_key'] = isset($raw['outline_model_key']) ? sanitize_key(wp_unslash($raw['outline_model_key'])) : self::get_default_outline_model_key();
            $available_outline_models = self::get_outline_models();
            $available_outline_model_keys = array();
            foreach ($available_outline_models as $outline_model) {
                if (!empty($outline_model['key'])) {
                    $available_outline_model_keys[] = (string) $outline_model['key'];
                }
            }
            if ($payload['outline_model_key'] === '' || (!empty($available_outline_model_keys) && !in_array($payload['outline_model_key'], $available_outline_model_keys, true))) {
                $payload['outline_model_key'] = self::get_default_outline_model_key();
            }
            $payload['related_posts_enabled'] = !empty($raw['related_posts_enabled']) ? 1 : 0;
            $payload['related_posts_position'] = isset($raw['related_posts_position']) ? sanitize_key(wp_unslash($raw['related_posts_position'])) : 'end';
            if (!in_array($payload['related_posts_position'], array('end', 'paragraphs', 'words'), true)) {
                $payload['related_posts_position'] = 'end';
            }
            $payload['related_posts_interval'] = isset($raw['related_posts_interval']) ? max(1, intval($raw['related_posts_interval'])) : 4;
            $payload['related_posts_min_h2'] = isset($raw['related_posts_min_h2']) ? max(0, intval($raw['related_posts_min_h2'])) : 1;
            $payload['related_posts_links_per_block'] = isset($raw['related_posts_links_per_block']) ? max(1, intval($raw['related_posts_links_per_block'])) : 2;
            $payload['related_posts_same_category_only'] = !empty($raw['related_posts_same_category_only']) ? 1 : 0;
            $payload['related_posts_allow_fallback'] = !empty($raw['related_posts_allow_fallback']) ? 1 : 0;
            $payload['related_posts_style'] = isset($raw['related_posts_style']) ? sanitize_key(wp_unslash($raw['related_posts_style'])) : 'list';
            if (!in_array($payload['related_posts_style'], array('inline', 'list', 'cards'), true)) {
                $payload['related_posts_style'] = 'list';
            }
            $payload['related_posts_phrases'] = isset($raw['related_posts_phrases']) ? sanitize_textarea_field(wp_unslash($raw['related_posts_phrases'])) : '';
            $payload['internal_links_count'] = isset($raw['internal_links_count']) ? max(0, intval($raw['internal_links_count'])) : 0;
            $payload['source_link_phrases'] = isset($raw['source_link_phrases']) ? sanitize_textarea_field(wp_unslash($raw['source_link_phrases'])) : '';
            $source_context_exclude_phrases = isset($raw['source_context_exclude_phrases']) ? sanitize_textarea_field(wp_unslash($raw['source_context_exclude_phrases'])) : '';
            $source_context_rating_label = !empty($settings['source_context_rating_label']) ? sanitize_text_field($settings['source_context_rating_label']) : 'IMDb';
            $source_context_min_rating = isset($settings['source_context_min_rating']) ? floatval(str_replace(',', '.', sanitize_text_field((string) $settings['source_context_min_rating']))) : 0;
            $source_context_keep_unrated = !empty($settings['source_context_keep_unrated']) ? 1 : 0;
            $payload['source_context_filters_json'] = wp_json_encode(array(
                'exclude_phrases' => Content_Rank_Generator_Helper::parse_source_context_filter_phrases($source_context_exclude_phrases),
                'rating_label' => $source_context_rating_label !== '' ? $source_context_rating_label : 'IMDb',
                'min_rating' => max(0, $source_context_min_rating),
                'keep_unrated' => $source_context_keep_unrated ? 1 : 0,
            ));
            if ($payload['name'] === '') {
                return new WP_Error('content_rank_invalid_generator', 'Nome do gerador é obrigatório.');
            }
            if ($payload['generation_mode'] !== 'satellite' && self::source_type_uses_keyword_list($payload['source_type']) && $payload['list_id'] <= 0) {
                return new WP_Error('content_rank_invalid_generator', 'Selecione uma lista de palavras-chave.');
            }
            if ($payload['generation_mode'] !== 'satellite' && !self::source_type_uses_keyword_list($payload['source_type']) && $payload['feed_url'] === '') {
                return new WP_Error('content_rank_invalid_generator', 'URL do feed é obrigatória para geradores RSS.');
            }
            if (trim((string) $payload['prompt_template']) === '') {
                $payload['prompt_template'] = self::get_default_prompt_template();
            }
            if (!in_array($payload['schedule_type'], array('interval', 'daily_random'), true)) {
                $payload['schedule_type'] = 'interval';
            }
            if (!in_array($payload['status'], array('active', 'inactive'), true)) {
                $payload['status'] = 'active';
            }
            return $payload;
        }

        public static function insert_run_log($generator_id, $status, $message, $context = array(), $post_id = null, $item_guid = null, $item_permalink = null)
        {
            global $wpdb;
            $wpdb->insert(
                self::$table_runs,
                array(
                    'generator_id' => intval($generator_id),
                    'item_guid' => $item_guid,
                    'item_permalink' => $item_permalink,
                    'post_id' => $post_id ? intval($post_id) : null,
                    'status' => sanitize_key($status),
                    'message' => sanitize_text_field($message),
                    'request_json' => !empty($context['request']) ? wp_json_encode($context['request']) : null,
                    'response_json' => !empty($context['response']) ? wp_json_encode($context['response']) : null,
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
            );
        }

        public static function insert_import_log($list_id, $row_number, $level, $code, $message, $row_data = array(), $context = array())
        {
            global $wpdb;
            $wpdb->insert(
                self::$table_import_logs,
                array(
                    'list_id' => intval($list_id),
                    'row_number' => max(0, intval($row_number)),
                    'level' => sanitize_key($level),
                    'code' => sanitize_key($code),
                    'message' => sanitize_text_field($message),
                    'row_data' => !empty($row_data) ? wp_json_encode($row_data) : null,
                    'context_json' => !empty($context) ? wp_json_encode($context) : null,
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        }

        public static function get_recent_import_logs($limit = 20, $list_id = 0)
        {
            global $wpdb;
            $limit = max(1, intval($limit));
            $list_id = max(0, intval($list_id));
            if ($list_id > 0) {
                return $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM " . self::$table_import_logs . " WHERE list_id = %d ORDER BY created_at DESC LIMIT %d",
                    $list_id,
                    $limit
                ), ARRAY_A);
            }

            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM " . self::$table_import_logs . " ORDER BY created_at DESC LIMIT %d",
                $limit
            ), ARRAY_A);
        }

        public static function get_default_prompt_template()
        {
            return "Você é um editor SEO. Sua tarefa é gerar apenas os elementos editoriais do artigo em {{generation_language}}.\n"
                . "Retorne somente JSON válido com estas chaves: title, slug, excerpt, tags, pexels_tags, meta_description, focus_keyword.\n"
                . "Não gere content_html nesta etapa. O corpo do artigo será criado em uma segunda chamada interna.\n"
                . "Use a fonte apenas como base factual. Não invente fatos, não copie frases e não use Markdown.\n"
                . "O título deve ser natural, fiel aos fatos e adequado ao idioma final.\n"
                . "A slug deve ser curta, limpa e coerente com o título.\n"
                . "A meta description deve ser objetiva e curta.\n"
                . "As tags devem ser geradas pela IA com base no conteúdo e ter no máximo 4 termos.\n"
                . "Pexels_tags deve ser um array com no máximo 4 termos visuais, concretos e específicos, sem palavras genéricas.\n"
                . "Se houver {{selected_tags}}, use-as apenas como referência opcional, nao como limite.\n"
                . "Se a lista estiver vazia, ainda assim retorne tags coerentes com o conteúdo.\n"
                . "Regras:\n"
                . "- Foque em title, slug, excerpt, tags, meta description e focus keyword.\n"
                . "- Não escreva o corpo do artigo nesta resposta.\n"
                . "- Mantenha o tom factual e direto.\n"
                . "- Se a fonte for pobre, simplifique em vez de inventar.\n"
                . "- Se a pauta for entretenimento, mantenha nomes próprios, obras e entidades reais quando existirem no contexto.\n"
                . "- Se houver tags sugeridas no contexto, use-as como apoio, mas priorize tags geradas pela IA a partir do conteúdo.";
        }

        public static function get_default_keyword_prompt_template()
        {
            return "Você é um editor de conteúdo especializado em criar artigos originais a partir de planilhas e palavras-chave.\n"
                . "Escreva o texto final em {{generation_language}}.\n"
                . "Retorne somente JSON válido com estas chaves: title, slug, excerpt, tags, pexels_tags, meta_description, focus_keyword.\n"
                . "Não gere content_html nesta etapa. O corpo do artigo será criado em uma segunda chamada interna.\n"
                . "Use a keyword, a URL de origem e os dados da linha apenas como base factual.\n"
                . "O título deve ser natural, fiel aos fatos e adequado ao idioma final.\n"
                . "A slug final deve permanecer exatamente igual a {{final_slug}}.\n"
                . "A meta description deve ser curta e objetiva.\n"
                . "As tags devem ser geradas pela IA com base no conteúdo e ter no máximo 4 termos.\n"
                . "Pexels_tags deve ser um array com no máximo 4 termos visuais, concretos e específicos, sem palavras genéricas.\n"
                . "Se houver título na planilha, use-o como base principal; se não houver, crie um título forte e natural a partir da keyword.\n"
                . "Keyword: {{keyword}}\n"
                . "Slug final: {{final_slug}}\n"
                . "Dados da linha: {{row_data}}\n"
                . "Regras:\n"
                . "- Preserve os fatos, mas reescreva do zero.\n"
                . "- Não invente fatos fora da keyword, da URL e dos dados da linha.\n"
                . "- Não use Markdown; use apenas JSON.\n"
                . "- Se a pauta for entretenimento, mantenha nomes próprios e entidades reais quando existirem no contexto.\n"
                . "- Gere tags apenas a partir do conteúdo e da keyword.";
        }

        public static function prompt_template_looks_like_keyword_default($prompt_template)
        {
            $prompt_template = (string) $prompt_template;
            return strpos($prompt_template, 'Você é um editor de conteúdo especializado em criar artigos originais a partir de planilhas e palavras-chave.') !== false;
        }

        public static function get_default_content_prompt_template()
        {
            return "Você é um redator editorial focado exclusivamente em escrever o corpo do artigo."
                . "Escreva em {{generation_language}}. \n"
                . "Objetivo:\n"
                . "- Escrever um artigo com cara de texto humano, natural, completo e fiel aos fatos.\n"
                . "- Abra com um lead comportamental que conecte o leitor ao tema de forma imediata.\n"
                . "- Use 2 a 3 parágrafos curtos na introdução, sem frases genéricas.\n"
                . "- Use a estrutura editorial indicada pelo outline interno e pelo modelo selecionado.\n"
                . "- Garanta no minimo 3 H2 no corpo do texto, mesmo em noticias curtas.\n"
                . "- Se houver seções, mantenha a ordem definida pelo esboço interno; não reordene, não agrupe e não pule itens.\n"
                . "- Depois de cada bloco principal, escreva 2 a 3 parágrafos curtos, com enredo factual e motivo real para o leitor se interessar.\n"
                . "- Não insira imagens, links ou chamadas externas no HTML; o backend faz essa etapa depois.\n"
                . "- A conclusão deve usar um único H2 específico e informativo, sem a palavra conclusão, diretamente ligado ao tema. Não use desafios ao leitor, chamadas genéricas ou frases como 'você está pronto' e 'o próximo passo'.\n"
                . "- Mantenha o foco no título já definido e desenvolva o texto ao redor dele.\n"
                . "- Use HTML simples apenas no content_html.\n"
                . "- Não repita os mesmos argumentos e não invente informações.\n"
                . "- Se a fonte for pobre, encurte em vez de encher linguiça.\n"
                . "- Se a pauta for entretenimento, escreva de forma concreta, visual e direta."
                . "Retorne apenas JSON válido com a chave content_html.\n"
                . "Não gere title, slug, tags ou metadados nesta etapa.\n"
                . "Use o título já definido: {{generated_title}}.\n"
                . "Use o focus keyword já definido: {{generated_focus_keyword}}.\n"
                . "Use a meta description já definida: {{generated_meta_description}}.\n"
                . "Use a fonte apenas como base factual.\n"
                . "Resumo da fonte: {{source_excerpt}}\n"
                . "Keyword: {{keyword}}\n"
                . "Slug final: {{generated_slug}}\n";
        }

        public static function get_default_content_prompt_template_visible()
        {
            return "Você é um redator editorial focado exclusivamente em escrever o corpo do artigo.\n"
                . "Escreva um texto final natural, completo e fiel aos fatos.\n"
                . "Retorne apenas JSON válido com a chave content_html.\n"
                . "Não gere title, slug, tags ou metadados nesta etapa.\n"
                . "Use apenas HTML simples no content_html.\n"
                . "Objetivo:\n"
                . "- Abra com um lead comportamental que conecte o leitor ao tema de forma imediata.\n"
                . "- Use 2 a 3 parágrafos curtos na introdução, sem frases genéricas.\n"
                . "- Use a estrutura editorial indicada pelo outline interno e pelo modelo selecionado.\n"
                . "- Escreva no formato piramide invertida, com os fatos mais importantes no início.\n"
                . "- Garanta no minimo 3 H2 no corpo do texto, mesmo em noticias curtas.\n"
                . "- Se houver seções, mantenha a ordem definida pelo esboço interno; não reordene, não agrupe e não pule itens.\n"
                . "- Depois de cada bloco principal, escreva 2 a 3 parágrafos curtos, com enredo factual e motivo real para o leitor se interessar.\n"
                . "- Não insira imagens, links ou chamadas externas no HTML; o backend faz essa etapa depois.\n"
                . "- A conclusão deve usar um único H2 específico e informativo, sem a palavra conclusão, diretamente ligado ao tema. Não use desafios ao leitor, chamadas genéricas ou frases como 'você está pronto' e 'o próximo passo'.\n"
                . "- Escreva com tom humano, sem soar mecânico.\n"
                . "- O texto deve ter no mínimo 500 e no máximo 1200 palavras. Nunca ultrapasse 1200 palavras.\n"
                . "- Use parágrafos curtos e ajuste a estrutura conforme a densidade do tema e o outline interno.\n"
                . "- Avance com fatos novos em cada bloco e evite repetição de ideias.\n"
                . "- Não use Markdown.\n"
                . "- Não invente informações.";
        }

        public static function content_prompt_template_looks_like_legacy_default($prompt_template)
        {
            $prompt_template = (string) $prompt_template;
            return strpos($prompt_template, 'Você é um redator editorial focado exclusivamente em escrever o corpo do artigo.') !== false
                && strpos($prompt_template, 'Use o título já definido:') !== false
                && strpos($prompt_template, 'Use o focus keyword já definido:') !== false
                && strpos($prompt_template, 'Retorne apenas JSON válido com a chave content_html.') !== false
                && (
                    strpos($prompt_template, 'Use parágrafos curtos e 2 a 4 H2 para quebrar o texto.') !== false
                    || strpos($prompt_template, 'Use parágrafos curtos, 2 a 4 H2, e avance com fatos novos em cada bloco.') !== false
                    || strpos($prompt_template, 'Use parágrafos curtos e ajuste o número de H2 conforme a densidade do tema e o outline interno.') !== false
                );
        }

        public static function prompt_template_looks_like_rss_default($prompt_template)
        {
            $prompt_template = (string) $prompt_template;
            return strpos($prompt_template, 'Você é um editor jornalístico especializado em reescrever conteúdo de RSS.') !== false
                || strpos($prompt_template, 'Voc? ? um jornalista de portal focado em SEO e no estilo GEO') !== false
                || strpos($prompt_template, '[DIRETRIZES DE ESCRITA E ESTILO (GEO)]') !== false;
        }

        public static function normalize_prompt_template_for_source_type($source_type, $prompt_template, $keyword_list_mode = 'keywords')
        {
            $prompt_template = trim((string) $prompt_template);

            if ($prompt_template === '') {
                return (self::source_type_uses_keyword_list($source_type) && $keyword_list_mode !== 'url_reference')
                    ? self::get_default_keyword_prompt_template()
                    : self::get_default_prompt_template();
            }

            return $prompt_template;
        }

        public static function get_generator_selected_tags($generator)
        {
            $tags = array();
            if (!empty($generator['tags_default'])) {
                $decoded = json_decode((string) $generator['tags_default'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $tag) {
                        $tag = sanitize_text_field($tag);
                        if ($tag !== '') {
                            $tags[] = $tag;
                        }
                    }
                }
            }

            $tags = array_values(array_unique($tags));
            return $tags;
        }

        public static function normalize_tag_key($tag)
        {
            $tag = sanitize_text_field((string) $tag);
            $tag = trim(preg_replace('/\s+/u', ' ', $tag));
            return mb_strtolower($tag, 'UTF-8');
        }

        public static function filter_tags_against_selected_pool($generated_tags, $selected_tags, $max = 6)
        {
            $generated_tags = is_array($generated_tags) ? $generated_tags : array();
            $selected_tags = is_array($selected_tags) ? $selected_tags : array();

            $generated_map = array();
            foreach ($generated_tags as $tag) {
                $tag = sanitize_text_field($tag);
                $key = self::normalize_tag_key($tag);
                if ($tag !== '' && $key !== '') {
                    $generated_map[$key] = $tag;
                }
            }

            $selected_map = array();
            foreach ($selected_tags as $tag) {
                $tag = sanitize_text_field($tag);
                $key = self::normalize_tag_key($tag);
                if ($tag !== '' && $key !== '') {
                    $selected_map[$key] = $tag;
                }
            }

            if (!empty($selected_map)) {
                $filtered = array();
                foreach ($generated_map as $key => $tag) {
                    if (isset($selected_map[$key])) {
                        $filtered[] = $selected_map[$key];
                    }
                }

                if (empty($filtered)) {
                    $filtered = array_slice(array_values($selected_map), 0, max(1, min(3, count($selected_map))));
                }

                return array_slice(array_values(array_unique($filtered)), 0, max(1, intval($max)));
            }

            return array_slice(array_values(array_unique(array_values($generated_map))), 0, max(1, intval($max)));
        }

        public static function filter_pexels_tags($tags, $max = 4)
        {
            $tags = is_array($tags) ? $tags : array();
            $filtered = array();
            $generic_terms = array(
                'action',
                'ação',
                'cinema',
                'filme',
                'filmes',
                'trailer',
                'trailers',
                'serie',
                'series',
                'série',
                'luta',
                'drama',
                'aventura',
                'comédia',
                'terror',
                'horror',
                'suspense',
                'esporte',
                'esportes',
                'nostalgia',
                'anos 1990',
                'anos 2000',
                '1990',
                '2000',
                'people',
                'personagem',
            );

            foreach ($tags as $tag) {
                $tag = sanitize_text_field($tag);
                $tag = trim(preg_replace('/\s+/u', ' ', $tag));
                if ($tag === '') {
                    continue;
                }

                $key = self::normalize_tag_key($tag);
                if ($key === '') {
                    continue;
                }

                if (preg_match('/^(?:19|20)\d{2}(?:s)?$/', $key)) {
                    continue;
                }
                if (preg_match('/\b(?:19|20)\d{2}\b/', $key)) {
                    continue;
                }
                if (in_array($key, $generic_terms, true)) {
                    continue;
                }

                $filtered[$key] = $tag;
            }

            return array_slice(array_values($filtered), 0, max(1, intval($max)));
        }

        public static function request_openai_json($generator, $prompt, $context = array())
        {
            $context = is_array($context) ? $context : array();
            $log_parts = array();
            $log_parts[] = 'stage=' . (isset($context['stage']) ? sanitize_key((string) $context['stage']) : 'unknown');
            $log_parts[] = 'source_type=' . (isset($context['source_type']) ? sanitize_key((string) $context['source_type']) : 'unknown');
            if (!empty($context['item_guid'])) {
                $log_parts[] = 'item_guid=' . (string) $context['item_guid'];
            }
            if (!empty($context['item_title'])) {
                $log_parts[] = 'item_title=' . sanitize_text_field((string) $context['item_title']);
            }
            if (isset($context['attempt'])) {
                $log_parts[] = 'attempt=' . intval($context['attempt']);
            }
            if (isset($context['minimum_words'])) {
                $log_parts[] = 'minimum_words=' . intval($context['minimum_words']);
            }
            if (isset($context['current_words'])) {
                $log_parts[] = 'current_words=' . intval($context['current_words']);
            }
            if (isset($context['excerpt_length'])) {
                $log_parts[] = 'excerpt_length=' . intval($context['excerpt_length']);
            }
            if (isset($context['content_length'])) {
                $log_parts[] = 'content_length=' . intval($context['content_length']);
            }
            if (!empty($context['source_context_enriched'])) {
                $log_parts[] = 'source_context_enriched=1';
            }
            $settings = self::get_settings();
            $api_key = trim((string) $settings['openai_api_key']);
            if ($api_key === '') {
                return new WP_Error('content_rank_missing_openai_key', 'A chave da API da OpenAI não esta configurada.');
            }

            $generation_language = !empty($generator['generation_language'])
                ? self::normalize_generation_language_value($generator['generation_language'])
                : self::get_default_generation_language();
            $language_instruction = 'IDIOMA OBRIGATORIO: gere toda a resposta exclusivamente em ' . $generation_language . '. '
                . 'Mantenha em outro idioma somente nomes proprios, marcas, obras ou termos que precisem permanecer assim.';
            if (empty($context['skip_language_instruction'])) {
                $prompt = $language_instruction . "\n\n" . trim((string) $prompt) . "\n\n" . $language_instruction;
            } else {
                $prompt = trim((string) $prompt);
            }

            $model = trim((string) $settings['default_model']);
            $temperature = max(0.0, min(2.0, floatval($settings['default_temperature'])));
            $max_tokens = max(256, intval($settings['default_max_tokens']));
            $use_responses_api = self::should_use_responses_api($model);
            $prompt_cache_retention = '24h';
            $response_schema = (isset($context['response_schema']) && is_array($context['response_schema'])) ? $context['response_schema'] : array();
            $response_schema_name = !empty($context['response_schema_name']) ? sanitize_key((string) $context['response_schema_name']) : 'content_rank_response';
            $response_schema_description = !empty($context['response_schema_description']) ? sanitize_text_field((string) $context['response_schema_description']) : '';

            $body = array('model' => $model);
            if ($use_responses_api) {
                if (!empty($context['previous_response_id'])) {
                    $body['previous_response_id'] = sanitize_text_field((string) $context['previous_response_id']);
                }
                $body['input'] = array(
                    array(
                        'role' => 'system',
                        'content' => 'Você é um editor jornalístico especializado. Retorne apenas JSON válido.'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt,
                    ),
                );
                $body['max_output_tokens'] = $max_tokens;
                $body['text'] = array(
                    'format' => !empty($response_schema) ? array(
                        'type' => 'json_schema',
                        'name' => $response_schema_name,
                        'schema' => $response_schema,
                        'strict' => true,
                    ) : array(
                        'type' => 'json_object',
                    ),
                );
                if ($response_schema_description !== '') {
                    $body['text']['format']['description'] = $response_schema_description;
                }
                $body['prompt_cache_retention'] = $prompt_cache_retention;
                if (strpos(strtolower($model), 'gpt-5') === 0) {
                    $body['reasoning'] = array(
                        'effort' => 'low',
                    );
                }
            } else {
                $body['messages'] = array(
                    array(
                        'role' => 'system',
                        'content' => 'Você é um editor jornalístico especializado. Retorne apenas JSON válido.'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt,
                    ),
                );
                $body['temperature'] = $temperature;
                $body['max_completion_tokens'] = $max_tokens;
                $body['response_format'] = !empty($response_schema) ? array(
                    'type' => 'json_schema',
                    'json_schema' => array(
                        'name' => $response_schema_name,
                        'schema' => $response_schema,
                        'strict' => true,
                    ),
                ) : array(
                    'type' => 'json_object',
                );
                if ($response_schema_description !== '' && !empty($response_schema)) {
                    $body['response_format']['json_schema']['description'] = $response_schema_description;
                }
                $body['prompt_cache_retention'] = $prompt_cache_retention;
            }

            error_log("prompt: " . $prompt);
            $response = wp_remote_post($use_responses_api ? 'https://api.openai.com/v1/responses' : 'https://api.openai.com/v1/chat/completions', array(
                'timeout' => 240,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode($body),
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $data = json_decode($response_body, true);

            if ($code !== 200) {
                $message = isset($data['error']['message']) ? $data['error']['message'] : 'Erro desconhecido da OpenAI';
                if ($use_responses_api) {
                    self::$last_openai_response_id = '';
                }
                return new WP_Error('content_rank_openai_error', $message);
            }

            $text = '';
            if ($use_responses_api) {
                self::$last_openai_response_id = !empty($data['id']) ? sanitize_text_field((string) $data['id']) : '';
                if (!empty($data['output_text'])) {
                    $text = trim((string) $data['output_text']);
                } elseif (!empty($data['output']) && is_array($data['output'])) {
                    foreach ($data['output'] as $output_item) {
                        if (!is_array($output_item) || empty($output_item['content']) || !is_array($output_item['content'])) {
                            continue;
                        }
                        foreach ($output_item['content'] as $content_item) {
                            if (!is_array($content_item) || empty($content_item['type'])) {
                                continue;
                            }
                            if ($content_item['type'] === 'output_text' && isset($content_item['text'])) {
                                $text .= (string) $content_item['text'];
                            }
                        }
                    }
                    $text = trim($text);
                }
            } elseif (isset($data['choices'][0]['message']['content'])) {
                self::$last_openai_response_id = '';
                $text = trim((string) $data['choices'][0]['message']['content']);
            }

            error_log("response: " . print_r($text, true));
            return self::parse_ai_json($text, $context);
        }

        public static function should_use_responses_api($model = '')
        {
            $model = strtolower(trim((string) $model));
            if ($model === '') {
                return false;
            }

            return strpos($model, 'gpt-5') === 0;
        }

        protected static function normalize_semantic_title_text($text)
        {
            $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            $text = preg_replace('/^\s*\d{1,3}\s*[\.\)\-:\/]*\s*/u', '', $text);
            $text = preg_replace('/\s*\((?:19|20)\d{2}(?:\s*[\-â€“—]\s*(?:19|20)\d{2})?\)\s*$/u', '', $text);
            $text = remove_accents($text);
            $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
            $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
            $text = trim(preg_replace('/\s+/u', ' ', $text));

            return $text;
        }

        protected static function normalize_semantic_title_token_set($text)
        {
            $text = self::normalize_semantic_title_text($text);
            if ($text === '') {
                return array();
            }

            $stopwords = array(
                'a', 'o', 'as', 'os', 'um', 'uma', 'uns', 'umas',
                'de', 'da', 'do', 'das', 'dos',
                'e', 'em', 'no', 'na', 'nos', 'nas',
                'por', 'para', 'com', 'sem', 'sobre', 'entre',
                'que', 'se', 'ao', 'aos', 'à', 'às',
                'the', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'with', 'from', 'by',
            );

            $tokens = array_values(array_filter(preg_split('/\s+/u', $text)));
            if (empty($tokens)) {
                return array();
            }

            $filtered = array();
            foreach ($tokens as $token) {
                $token = trim((string) $token);
                if ($token === '' || mb_strlen($token, 'UTF-8') <= 2) {
                    continue;
                }
                if (in_array($token, $stopwords, true)) {
                    continue;
                }
                $filtered[] = $token;
            }

            return array_values(array_unique($filtered));
        }

        public static function calculate_semantic_title_fallback_score($needle, $haystack)
        {
            $needle = self::normalize_semantic_title_text($needle);
            $haystack = self::normalize_semantic_title_text($haystack);
            if ($needle === '' || $haystack === '') {
                return 0.0;
            }

            if ($needle === $haystack) {
                return 1.0;
            }

            $score = 0.0;
            if (strpos($haystack, $needle) !== false || strpos($needle, $haystack) !== false) {
                $score += 0.45;
            }

            $similarity = 0.0;
            similar_text($needle, $haystack, $similarity);
            $score += min(0.4, max(0.0, floatval($similarity) / 100.0));

            $needle_tokens = self::normalize_semantic_title_token_set($needle);
            $haystack_tokens = self::normalize_semantic_title_token_set($haystack);
            if (!empty($needle_tokens) && !empty($haystack_tokens)) {
                $common_tokens = array_intersect($needle_tokens, $haystack_tokens);
                $token_union = count(array_unique(array_merge($needle_tokens, $haystack_tokens)));
                $token_jaccard = $token_union > 0 ? count($common_tokens) / $token_union : 0.0;
                $score += min(0.2, $token_jaccard * 0.2);

                $needle_first = reset($needle_tokens);
                $haystack_first = reset($haystack_tokens);
                if ($needle_first !== false && $haystack_first !== false && $needle_first === $haystack_first) {
                    $score += 0.05;
                }
            }

            return min(1.0, $score);
        }

        protected static function normalize_embedding_vector($embedding)
        {
            if (!is_array($embedding) || empty($embedding)) {
                return array();
            }

            $vector = array();
            $norm = 0.0;
            foreach ($embedding as $value) {
                $float_value = floatval($value);
                $vector[] = $float_value;
                $norm += $float_value * $float_value;
            }

            if ($norm <= 0) {
                return array();
            }

            $norm = sqrt($norm);
            foreach ($vector as $index => $value) {
                $vector[$index] = $value / $norm;
            }

            return $vector;
        }

        public static function cosine_similarity_between_vectors($left, $right)
        {
            if (!is_array($left) || !is_array($right) || empty($left) || empty($right)) {
                return 0.0;
            }

            $count = min(count($left), count($right));
            if ($count <= 0) {
                return 0.0;
            }

            $dot = 0.0;
            $left_norm = 0.0;
            $right_norm = 0.0;
            for ($i = 0; $i < $count; $i++) {
                $left_value = floatval($left[$i]);
                $right_value = floatval($right[$i]);
                $dot += $left_value * $right_value;
                $left_norm += $left_value * $left_value;
                $right_norm += $right_value * $right_value;
            }

            if ($left_norm <= 0 || $right_norm <= 0) {
                return 0.0;
            }

            return max(0.0, min(1.0, $dot / (sqrt($left_norm) * sqrt($right_norm))));
        }

        public static function request_openai_embedding($text, $model = 'text-embedding-3-small')
        {
            $settings = self::get_settings();
            $api_key = trim((string) $settings['openai_api_key']);
            if ($api_key === '') {
                return new WP_Error('content_rank_missing_openai_key', 'A chave da API da OpenAI não esta configurada.');
            }

            $text = trim((string) $text);
            if ($text === '') {
                return new WP_Error('content_rank_empty_embedding_input', 'Não foi possível gerar embedding para texto vazio.');
            }

            $model = trim((string) $model);
            if ($model === '') {
                $model = 'text-embedding-3-small';
            }

            $response = wp_remote_post('https://api.openai.com/v1/embeddings', array(
                'timeout' => 120,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode(array(
                    'model' => $model,
                    'input' => $text,
                )),
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $data = json_decode($response_body, true);

            if ($code !== 200) {
                $message = isset($data['error']['message']) ? $data['error']['message'] : 'Erro desconhecido ao gerar embedding';
                return new WP_Error('content_rank_openai_embedding_error', $message);
            }

            if (empty($data['data'][0]['embedding']) || !is_array($data['data'][0]['embedding'])) {
                return new WP_Error('content_rank_invalid_embedding_json', 'A resposta de embeddings da OpenAI não veio no formato esperado.');
            }

            $embedding = array_map('floatval', $data['data'][0]['embedding']);
            $normalized_embedding = self::normalize_embedding_vector($embedding);
            if (empty($normalized_embedding)) {
                return new WP_Error('content_rank_empty_embedding_vector', 'Não foi possível normalizar o embedding gerado.');
            }

            return array(
                'model' => $model,
                'embedding' => $normalized_embedding,
                'raw_embedding' => $embedding,
                'dimensions' => count($normalized_embedding),
            );
        }

        public static function request_openai_embeddings_batch($texts, $model = 'text-embedding-3-small')
        {
            $settings = self::get_settings();
            $api_key = trim((string) $settings['openai_api_key']);
            if ($api_key === '') {
                return new WP_Error('content_rank_missing_openai_key', 'A chave da API da OpenAI não esta configurada.');
            }

            $model = trim((string) $model);
            if ($model === '') {
                $model = 'text-embedding-3-small';
            }

            $normalized_texts = array();
            foreach ((array) $texts as $text) {
                $text = trim((string) $text);
                if ($text !== '') {
                    $normalized_texts[] = $text;
                }
            }

            if (empty($normalized_texts)) {
                return new WP_Error('content_rank_empty_embedding_input', 'Não foi possível gerar embedding para texto vazio.');
            }

            $response = wp_remote_post('https://api.openai.com/v1/embeddings', array(
                'timeout' => 120,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode(array(
                    'model' => $model,
                    'input' => $normalized_texts,
                )),
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $data = json_decode($response_body, true);

            if ($code !== 200) {
                $message = isset($data['error']['message']) ? $data['error']['message'] : 'Erro desconhecido ao gerar embeddings';
                return new WP_Error('content_rank_openai_embedding_error', $message);
            }

            if (empty($data['data']) || !is_array($data['data'])) {
                return new WP_Error('content_rank_invalid_embedding_json', 'A resposta de embeddings da OpenAI não veio no formato esperado.');
            }

            $embeddings = array();
            $raw_embeddings = array();
            foreach ($data['data'] as $entry) {
                if (!is_array($entry) || !isset($entry['index']) || !isset($entry['embedding']) || !is_array($entry['embedding'])) {
                    continue;
                }

                $index = intval($entry['index']);
                $embedding = array_map('floatval', $entry['embedding']);
                $normalized_embedding = self::normalize_embedding_vector($embedding);
                if (empty($normalized_embedding)) {
                    continue;
                }

                $embeddings[$index] = $normalized_embedding;
                $raw_embeddings[$index] = $embedding;
            }

            if (empty($embeddings)) {
                return new WP_Error('content_rank_empty_embedding_vector', 'Não foi possível normalizar o embedding gerado.');
            }

            ksort($embeddings);
            ksort($raw_embeddings);

            return array(
                'model' => $model,
                'embeddings' => $embeddings,
                'raw_embeddings' => $raw_embeddings,
                'dimensions' => !empty($embeddings) ? count(reset($embeddings)) : 0,
            );
        }

        protected static function get_semantic_duplicate_candidates($limit = 300)
        {
            global $wpdb;
            $limit = max(25, intval($limit));

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, post_id, item_title FROM " . self::$table_items . " WHERE post_id > 0 AND item_title <> '' ORDER BY created_at DESC LIMIT %d",
                $limit
            ), ARRAY_A);

            return is_array($rows) ? $rows : array();
        }

        protected static function upsert_item_title_embedding_row($row_id, $embedding_data)
        {
            global $wpdb;
            $row_id = intval($row_id);
            if ($row_id <= 0 || !is_array($embedding_data) || empty($embedding_data['embedding'])) {
                return 0;
            }

            $update = array(
                'title_embedding_model' => !empty($embedding_data['model']) ? sanitize_text_field((string) $embedding_data['model']) : '',
                'title_embedding_json' => wp_json_encode(array_values(array_map('floatval', (array) $embedding_data['embedding']))),
            );

            return $wpdb->update(
                self::$table_items,
                $update,
                array('id' => $row_id),
                array('%s', '%s'),
                array('%d')
            );
        }

        public static function find_semantic_duplicate_for_title($title, $generator = array(), $context = array())
        {
            $title = trim((string) $title);
            $settings = self::get_settings();
            $enabled = !empty($settings['semantic_dedup_enabled']);
            $threshold = isset($settings['semantic_dedup_threshold']) ? max(0.0, min(1.0, floatval($settings['semantic_dedup_threshold']))) : 0.88;
            $lookback = isset($settings['semantic_dedup_lookback']) ? max(25, intval($settings['semantic_dedup_lookback'])) : 300;
            $result = array(
                'post_id' => 0,
                'score' => 0.0,
                'method' => 'text',
                'matched_title' => '',
                'matched_item_id' => 0,
                'embedding_model' => '',
                'embedding_json' => '',
                'embedding_raw' => array(),
                'normalized_title' => self::normalize_semantic_title_text($title),
            );

            if ($title === '') {
                return $result;
            }

            $candidates = self::get_semantic_duplicate_candidates($lookback);
            if (empty($candidates)) {
                return $result;
            }

            $normalized_title = $result['normalized_title'];
            if ($normalized_title !== '') {
                foreach ($candidates as $candidate) {
                    $candidate_title = isset($candidate['item_title']) ? (string) $candidate['item_title'] : '';
                    if ($candidate_title === '') {
                        continue;
                    }

                    if (self::normalize_semantic_title_text($candidate_title) === $normalized_title) {
                        $post_id = !empty($candidate['post_id']) ? intval($candidate['post_id']) : 0;
                        if ($post_id > 0 && get_post($post_id)) {
                            $result['post_id'] = $post_id;
                            $result['score'] = 1.0;
                            $result['method'] = 'exact';
                            $result['matched_title'] = $candidate_title;
                            $result['matched_item_id'] = !empty($candidate['id']) ? intval($candidate['id']) : 0;
                            return $result;
                        }
                    }
                }
            }

            if (!$enabled) {
                return $result;
            }

            $best_score = 0.0;
            $best_candidate = null;
            foreach ($candidates as $candidate) {
                $candidate_title = isset($candidate['item_title']) ? trim((string) $candidate['item_title']) : '';
                if ($candidate_title === '') {
                    continue;
                }

                $candidate_score = self::calculate_semantic_title_fallback_score($title, $candidate_title);
                $candidate_method = 'text';

                if ($candidate_score > $best_score) {
                    $best_score = $candidate_score;
                    $best_candidate = array(
                        'row_id' => !empty($candidate['id']) ? intval($candidate['id']) : 0,
                        'post_id' => !empty($candidate['post_id']) ? intval($candidate['post_id']) : 0,
                        'title' => $candidate_title,
                        'score' => $candidate_score,
                        'method' => $candidate_method,
                    );
                }
            }

            $effective_threshold = $threshold;
            if (is_array($best_candidate) && !empty($best_candidate['method']) && $best_candidate['method'] === 'text') {
                $effective_threshold = min(max($effective_threshold, 0.78), 0.84);
            }

            if (is_array($best_candidate) && !empty($best_candidate['post_id']) && $best_score >= $effective_threshold && get_post(intval($best_candidate['post_id']))) {
                $result['post_id'] = intval($best_candidate['post_id']);
                $result['score'] = floatval($best_score);
                $result['method'] = !empty($best_candidate['method']) ? (string) $best_candidate['method'] : 'text';
                $result['matched_title'] = !empty($best_candidate['title']) ? (string) $best_candidate['title'] : '';
                $result['matched_item_id'] = !empty($best_candidate['row_id']) ? intval($best_candidate['row_id']) : 0;
            }

            return $result;
        }

        public static function parse_ai_json($text, $context = array())
        {
            $text = trim((string) $text);
            // JSON mode can still return a BOM or raw control characters in text values.
            $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);

            if (function_exists('wp_check_invalid_utf8')) {
                $text = wp_check_invalid_utf8($text, true);
            } elseif (function_exists('iconv')) {
                $text = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            }

            // Escape raw control characters only while inside JSON strings.
            $normalized_json = '';
            $inside_string = false;
            $escaped = false;
            $text_length = strlen($text);
            for ($index = 0; $index < $text_length; $index++) {
                $character = $text[$index];
                $ord = ord($character);

                if ($inside_string) {
                    if ($escaped) {
                        $normalized_json .= $character;
                        $escaped = false;
                        continue;
                    }
                    if ($character === '\\') {
                        $normalized_json .= $character;
                        $escaped = true;
                        continue;
                    }
                    if ($character === '"') {
                        $normalized_json .= $character;
                        $inside_string = false;
                        continue;
                    }
                    if ($ord < 32 || $ord === 127) {
                        if ($ord === 9) {
                            $normalized_json .= '\\t';
                        } elseif ($ord === 10) {
                            $normalized_json .= '\\n';
                        } elseif ($ord === 13) {
                            $normalized_json .= '\\r';
                        } else {
                            $normalized_json .= sprintf('\\u%04x', $ord);
                        }
                        continue;
                    }
                } elseif ($character === '"') {
                    $inside_string = true;
                }

                $normalized_json .= $character;
            }
            $text = $normalized_json;

            $data = json_decode($text, true);
            if (!is_array($data) && json_last_error() === JSON_ERROR_CTRL_CHAR) {
                // Some models still emit literal control bytes despite JSON
                // mode. Retry with those bytes replaced by spaces so the
                // staged pipeline can handle the response instead of failing
                // before the content stage is persisted.
                $retry_text = preg_replace('/[\\x00-\\x1F\\x7F]/', ' ', $text);
                if (is_string($retry_text)) {
                    $data = json_decode($retry_text, true);
                    if (is_array($data)) {
                        return self::normalize_generated_article($data, $context);
                    }
                    $text = $retry_text;
                }
            }
            if (!is_array($data)) {
                $first_brace = strpos($text, '{');
                $last_brace = strrpos($text, '}');
                if ($first_brace !== false && $last_brace !== false && $last_brace > $first_brace) {
                    $maybe_json = trim(substr($text, $first_brace, $last_brace - $first_brace + 1));
                    $data = json_decode($maybe_json, true);
                    if (is_array($data)) {
                        return self::normalize_generated_article($data, $context);
                    }
                }
            }

            // Planning responses occasionally contain only a trailing comma.
            // Repair that narrow case without changing arbitrary model output.
            if (!is_array($data) && !empty($context['stage']) && $context['stage'] === 'content_plan') {
                $repaired_text = preg_replace('/,\s*([}\]])/u', '$1', $text);
                if (is_string($repaired_text) && $repaired_text !== $text) {
                    $data = json_decode($repaired_text, true);
                    if (is_array($data)) {
                        return self::normalize_generated_article($data, $context);
                    }
                }
            }

            if (is_array($data)) {
                return self::normalize_generated_article($data, $context);
            }

            // Some content responses contain unescaped quotes inside HTML
            // attributes. Recover only the required content field instead of
            // accepting malformed metadata or planning responses.
            if (!empty($context['stage']) && $context['stage'] === 'content') {
                $content_html = self::extract_malformed_content_html($text);
                if ($content_html !== '') {
                    return self::normalize_generated_article(array(
                        'content_html' => $content_html,
                    ), $context);
                }

                if (preg_match('/^\s*(?:<p\b|<article\b|<h[1-6]\b)/i', $text)) {
                    return self::normalize_generated_article(array(
                        'content_html' => $text,
                    ), $context);
                }
            }

            $json_error_message = function_exists('json_last_error_msg') ? json_last_error_msg() : 'erro desconhecido';

            return new WP_Error(
                'content_rank_invalid_ai_json',
                'A resposta da OpenAI nao veio em JSON valido: ' . $json_error_message
            );
        }

        protected static function extract_malformed_content_html($text)
        {
            $text = trim((string) $text);
            $key_position = stripos($text, '"content_html"');
            if ($key_position === false) {
                return '';
            }

            $colon_position = strpos($text, ':', $key_position);
            if ($colon_position === false) {
                return '';
            }

            $value_start = strpos($text, '"', $colon_position + 1);
            if ($value_start === false) {
                return '';
            }

            $value_end = false;
            $text_length = strlen($text);
            for ($index = $value_start + 1; $index < $text_length; $index++) {
                if ($text[$index] !== '"' || ($index > 0 && $text[$index - 1] === '\\')) {
                    continue;
                }

                $next_index = $index + 1;
                while ($next_index < $text_length && ctype_space($text[$next_index])) {
                    $next_index++;
                }
                if ($next_index < $text_length && $text[$next_index] === '}') {
                    $value_end = $index;
                    break;
                }
            }

            if ($value_end === false || $value_end <= $value_start) {
                return '';
            }

            $raw_value = substr($text, $value_start + 1, $value_end - $value_start - 1);
            $normalized_value = '';
            $escaped = false;
            $raw_length = strlen($raw_value);
            for ($index = 0; $index < $raw_length; $index++) {
                $character = $raw_value[$index];
                $ord = ord($character);

                if ($escaped) {
                    $normalized_value .= $character;
                    $escaped = false;
                    continue;
                }
                if ($character === '\\') {
                    $normalized_value .= $character;
                    $escaped = true;
                    continue;
                }
                if ($character === '"') {
                    $normalized_value .= '\\"';
                    continue;
                }
                if ($ord < 32 || $ord === 127) {
                    if ($ord === 9) {
                        $normalized_value .= '\\t';
                    } elseif ($ord === 10) {
                        $normalized_value .= '\\n';
                    } elseif ($ord === 13) {
                        $normalized_value .= '\\r';
                    } else {
                        $normalized_value .= sprintf('\\u%04x', $ord);
                    }
                    continue;
                }
                $normalized_value .= $character;
            }

            $decoded_value = json_decode('"' . $normalized_value . '"', true);
            return is_string($decoded_value) ? trim($decoded_value) : '';
        }

        public static function normalize_generated_article($data, $context = array())
        {
            $allow_missing_content_html = !empty($context['allow_missing_content_html']);
            $preserve_extra_fields = !empty($context['preserve_extra_fields']);
            $article = array();
            $article['title'] = isset($data['title']) ? sanitize_text_field($data['title']) : (isset($data['título']) ? sanitize_text_field($data['título']) : '');
            $article['slug'] = isset($data['slug']) ? sanitize_title($data['slug']) : '';
            $article['excerpt'] = isset($data['excerpt']) ? sanitize_textarea_field($data['excerpt']) : (isset($data['resumo']) ? sanitize_textarea_field($data['resumo']) : '');
            $article['content_html'] = isset($data['content_html']) ? wp_kses_post($data['content_html']) : (isset($data['conteúdo_html']) ? wp_kses_post($data['conteúdo_html']) : '');
            $article['meta_description'] = isset($data['meta_description']) ? sanitize_text_field($data['meta_description']) : (isset($data['meta_descricao']) ? sanitize_text_field($data['meta_descricao']) : '');
            $article['focus_keyword'] = isset($data['focus_keyword']) ? sanitize_text_field($data['focus_keyword']) : (isset($data['palavra_chave_foco']) ? sanitize_text_field($data['palavra_chave_foco']) : '');
            $article['tags'] = array();
            $article['pexels_tags'] = array();

            if (isset($data['tags'])) {
                $tags_source = is_array($data['tags']) ? $data['tags'] : self::parse_list_field($data['tags']);
                foreach ($tags_source as $tag) {
                    $tag = sanitize_text_field($tag);
                    if ($tag !== '') {
                        $article['tags'][] = $tag;
                    }
                }
            }

            if (isset($data['pexels_tags'])) {
                $pexels_tags_source = is_array($data['pexels_tags']) ? $data['pexels_tags'] : self::parse_list_field($data['pexels_tags']);
                foreach ($pexels_tags_source as $tag) {
                    $tag = sanitize_text_field($tag);
                    if ($tag !== '') {
                        $article['pexels_tags'][] = $tag;
                    }
                }
            } elseif (isset($data['image_tags'])) {
                $image_tags_source = is_array($data['image_tags']) ? $data['image_tags'] : self::parse_list_field($data['image_tags']);
                foreach ($image_tags_source as $tag) {
                    $tag = sanitize_text_field($tag);
                    if ($tag !== '') {
                        $article['pexels_tags'][] = $tag;
                    }
                }
            }

            if ($article['title'] === '') {
                $article['title'] = 'Artigo sem título';
            }
            if ($article['slug'] === '') {
                $article['slug'] = sanitize_title($article['title']);
            }
            if (!$allow_missing_content_html && $article['excerpt'] === '') {
                $article['excerpt'] = wp_trim_words(wp_strip_all_tags($article['content_html']), 24);
            }
            if (!$allow_missing_content_html && $article['content_html'] === '') {
                $article['content_html'] = '<p>' . esc_html($article['excerpt']) . '</p>';
            }
            if (!empty($article['pexels_tags'])) {
                $article['pexels_tags'] = self::filter_pexels_tags($article['pexels_tags'], 4);
            }

            if ($preserve_extra_fields && is_array($data)) {
                foreach ($data as $key => $value) {
                    if (array_key_exists($key, $article)) {
                        continue;
                    }
                    if (is_scalar($value) || is_array($value) || $value === null) {
                        $article[$key] = $value;
                    }
                }
            }

            $source_title = !empty($data['source_title']) ? (string) $data['source_title'] : '';
            $article['title'] = Content_Rank_Generator_Helper::normalize_generated_title($article['title'], $source_title);

            return $article;
        }



        public static function create_gutenberg_block($block_name, $inner_html, $attrs = array())
        {
            return array(
                'blockName' => trim((string) $block_name),
                'attrs' => is_array($attrs) ? $attrs : array(),
                'innerBlocks' => array(),
                'innerContent' => array((string) $inner_html),
            );
        }

        public static function normalize_embed_target_url($video_url)
        {
            $video_url = esc_url_raw(trim((string) $video_url));
            if ($video_url === '') {
                return '';
            }

            $parts = wp_parse_url($video_url);
            if (!is_array($parts) || empty($parts['host'])) {
                return $video_url;
            }

            $host = strtolower((string) $parts['host']);
            $path = isset($parts['path']) ? (string) $parts['path'] : '';
            $query = isset($parts['query']) ? (string) $parts['query'] : '';
            parse_str($query, $query_args);

            if (strpos($host, 'youtu.be') !== false) {
                $video_id = trim(ltrim($path, '/'));
                if ($video_id !== '') {
                    return 'https://www.youtube.com/watch?v=' . rawurlencode($video_id);
                }
            }

            if (strpos($host, 'youtube.com') !== false) {
                if (preg_match('~^/embed/([^/?#&]+)~i', $path, $matches)) {
                    return 'https://www.youtube.com/watch?v=' . rawurlencode($matches[1]);
                }
                if (!empty($query_args['v'])) {
                    return 'https://www.youtube.com/watch?v=' . rawurlencode((string) $query_args['v']);
                }
                if (preg_match('~[?&]v=([^&#]+)~i', $video_url, $matches)) {
                    return 'https://www.youtube.com/watch?v=' . rawurlencode($matches[1]);
                }
            }

            if (strpos($host, 'player.vimeo.com') !== false) {
                if (preg_match('~^/video/([0-9]+)~', $path, $matches)) {
                    return 'https://vimeo.com/' . rawurlencode($matches[1]);
                }
            }

            if (strpos($host, 'vimeo.com') !== false) {
                if (preg_match('~^/([0-9]+)~', $path, $matches)) {
                    return 'https://vimeo.com/' . rawurlencode($matches[1]);
                }
            }

            if (strpos($host, 'dailymotion.com') !== false) {
                if (preg_match('~^/embed/video/([^/?#&]+)~i', $path, $matches)) {
                    return 'https://www.dailymotion.com/video/' . rawurlencode($matches[1]);
                }
                if (preg_match('~^/video/([^/?#&]+)~i', $path, $matches)) {
                    return 'https://www.dailymotion.com/video/' . rawurlencode($matches[1]);
                }
            }

            if (strpos($host, 'tiktok.com') !== false) {
                return $video_url;
            }

            if (strpos($host, 'streamable.com') !== false) {
                return $video_url;
            }

            return $video_url;
        }

        public static function build_manual_video_figure_html($embed_html, $video_url = '')
        {
            $embed_html = trim((string) $embed_html);
            $video_url = esc_url_raw(trim((string) $video_url));

            if ($embed_html === '' && $video_url === '') {
                return '';
            }

            $provider_slug = $video_url !== '' ? self::detect_video_provider_slug($video_url) : 'video';
            $class_names = array(
                'wp-block-embed',
                'is-type-video',
                'is-provider-' . $provider_slug,
                'wp-block-embed-' . $provider_slug,
                'wp-embed-aspect-16-9',
                'wp-has-aspect-ratio',
            );

            $figure  = '<figure class="' . esc_attr(implode(' ', array_unique($class_names))) . '">';
            $figure .= '<div class="wp-block-embed__wrapper">' . "\n";
            $figure .= esc_html(self::normalize_embed_target_url($video_url)) . "\n";
            $figure .= '</div>';
            $figure .= '</figure>';

            return $figure;
        }

        public static function dom_node_outer_html($node)
        {
            if (!is_object($node) || empty($node->ownerDocument)) {
                return '';
            }

            $html = $node->ownerDocument->saveHTML($node);
            return is_string($html) ? trim($html) : '';
        }

        public static function append_gutenberg_blocks_from_dom_node($node, array &$blocks)
        {
            if (!is_object($node)) {
                return;
            }

            if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
                $text = trim(preg_replace('/\s+/', ' ', html_entity_decode((string) $node->nodeValue, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'))));
                if ($text !== '') {
                    $blocks[] = self::create_gutenberg_block('core/paragraph', '<p>' . esc_html($text) . '</p>');
                }
                return;
            }

            if ($node->nodeType !== XML_ELEMENT_NODE) {
                return;
            }

            $tag = strtolower((string) $node->nodeName);
            $outer_html = self::dom_node_outer_html($node);
            if ($outer_html === '') {
                return;
            }

            // Product review cards are complete HTML components. Keep each
            // card as one valid HTML block instead of splitting its wrappers
            // into unrelated Gutenberg blocks.
            $class_name = $node instanceof DOMElement ? (string) $node->getAttribute('class') : '';
            if (preg_match('/(?:^|\s)rc-(?:container|card)(?:\s|$)/i', $class_name)) {
                $blocks[] = self::create_gutenberg_block('core/html', $outer_html);
                return;
            }

            if (preg_match('/^h([1-6])$/', $tag, $matches)) {
                $blocks[] = self::create_gutenberg_block('core/heading', $outer_html, array('level' => intval($matches[1])));
                return;
            }

            switch ($tag) {
                case 'p':
                    $blocks[] = self::create_gutenberg_block('core/paragraph', $outer_html);
                    return;

                case 'table':
                    if (stripos($outer_html, '<figure') === false) {
                        $outer_html = '<figure class="wp-block-table">' . $outer_html . '</figure>';
                    }
                    $blocks[] = self::create_gutenberg_block('core/table', $outer_html);
                    return;

                case 'thead':
                case 'tbody':
                case 'tfoot':
                case 'tr':
                case 'th':
                case 'td':
                case 'caption':
                    return;

                case 'ul':
                case 'ol':
                    $blocks[] = self::create_gutenberg_block('core/list', $outer_html);
                    return;

                case 'blockquote':
                    $blocks[] = self::create_gutenberg_block('core/quote', $outer_html);
                    return;

                case 'pre':
                    $blocks[] = self::create_gutenberg_block('core/code', $outer_html);
                    return;

                case 'hr':
                    $blocks[] = self::create_gutenberg_block('core/separator', '<hr />');
                    return;

                case 'figure':
                case 'iframe':
                    $video_url = self::extract_video_url_from_embed_html($outer_html, '');
                    if ($video_url !== '') {
                        $blocks[] = self::build_gutenberg_embed_block_from_html($outer_html, $video_url);
                        return;
                    }
                    $blocks[] = self::create_gutenberg_block('core/html', $outer_html);
                    return;

                case 'img':
                    $blocks[] = self::create_gutenberg_block('core/html', $outer_html);
                    return;

                case 'script':
                case 'style':
                    return;
            }

            $child_blocks_before = count($blocks);
            foreach ($node->childNodes as $child) {
                self::append_gutenberg_blocks_from_dom_node($child, $blocks);
            }

            if (count($blocks) === $child_blocks_before) {
                $text = trim(wp_strip_all_tags($outer_html));
                if ($text !== '') {
                    $blocks[] = self::create_gutenberg_block('core/paragraph', '<p>' . esc_html($text) . '</p>');
                } else {
                    $blocks[] = self::create_gutenberg_block('core/html', $outer_html);
                }
            }
        }

        public static function build_gutenberg_embed_block_from_html($embed_html, $video_url = '')
        {
            $embed_html = trim((string) $embed_html);
            $video_url = esc_url_raw(trim((string) $video_url));
            if ($embed_html === '' && $video_url === '') {
                return array();
            }

            $manual_html = self::build_manual_video_figure_html($embed_html, $video_url);
            $block_html = $manual_html !== '' ? $manual_html : $embed_html;

            return self::create_gutenberg_block('core/embed', $block_html, array(
                'url' => self::normalize_embed_target_url($video_url),
                'type' => 'video',
                'providerNameSlug' => self::detect_video_provider_slug($video_url),
                'responsive' => true,
            ));
        }

        public static function convert_html_fragment_to_gutenberg_blocks($html, $prepend_embed_html = '', $prepend_embed_url = '')
        {
            $html = trim((string) $html);
            $blocks = array();

            if ($prepend_embed_html !== '' || $prepend_embed_url !== '') {
                $embed_block = self::build_gutenberg_embed_block_from_html($prepend_embed_html, $prepend_embed_url);
                if (!empty($embed_block)) {
                    $blocks[] = $embed_block;
                }
            }

            if ($html === '') {
                return !empty($blocks) ? serialize_blocks($blocks) : '';
            }

            if (strpos($html, '<!-- wp:') !== false) {
                return !empty($blocks) ? serialize_blocks($blocks) . "\n\n" . $html : $html;
            }

            $previous_libxml_state = libxml_use_internal_errors(true);
            $dom = new DOMDocument('1.0', 'UTF-8');
            $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><div id="content-rank-gutenberg-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            libxml_use_internal_errors($previous_libxml_state);

            if ($loaded) {
                $root = $dom->getElementById('content-rank-gutenberg-root');
                if (!$root && $dom->getElementsByTagName('div')->length > 0) {
                    $root = $dom->getElementsByTagName('div')->item(0);
                }
                if ($root) {
                    foreach ($root->childNodes as $child) {
                        self::append_gutenberg_blocks_from_dom_node($child, $blocks);
                    }
                }
            }

            if (empty($blocks)) {
                $plain_text = trim(wp_strip_all_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'))));
                if ($plain_text !== '') {
                    $blocks[] = self::create_gutenberg_block('core/paragraph', '<p>' . esc_html($plain_text) . '</p>');
                } else {
                    $blocks[] = self::create_gutenberg_block('core/html', $html);
                }
            }

            return !empty($blocks) ? serialize_blocks($blocks) : '';
        }

        public static function clean_source_text($text)
        {
            $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
            $text = wp_strip_all_tags($text);
            $text = preg_replace('/\s+/', ' ', $text);
            return trim($text);
        }

        public static function extract_page_title_from_html($html)
        {
            $html = (string) $html;
            if ($html === '') {
                return '';
            }

            if (preg_match('/<meta[^>]+(?:property|name|itemprop)=["\'](?:og:title|twitter:title)["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
                return self::clean_source_text(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')));
            }

            if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $matches)) {
                return self::clean_source_text($matches[1]);
            }

            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
                return self::clean_source_text($matches[1]);
            }

            return '';
        }

        public static function extract_page_content_from_html($html, $content_selector = '')
        {
            $html = (string) $html;
            if ($html === '') {
                return '';
            }

            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $loaded = @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            if (!$loaded) {
                return '';
            }

            $content_selector = trim((string) $content_selector);
            if ($content_selector !== '' && class_exists('Content_Rank_Generator_Helper')) {
                $selected_content = Content_Rank_Generator_Helper::extract_text_from_html_using_selector($html, $content_selector);
                if ($selected_content !== '') {
                    return $selected_content;
                }
            }

            $xpath = new DOMXPath($dom);
            $selectors = array(
                '//article',
                '//*[@role="main"]',
                '//main',
                '//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " content ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " article-body ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " story-body ")]',
                '//*[@id="content"]',
                '//*[@id="main"]',
            );

            $best = '';
            foreach ($selectors as $selector) {
                $nodes = $xpath->query($selector);
                if (!$nodes || $nodes->length === 0) {
                    continue;
                }

                $candidate = '';
                for ($i = 0; $i < min(2, $nodes->length); $i++) {
                    $node = $nodes->item($i);
                    if ($node) {
                        $candidate .= ' ' . $node->textContent;
                    }
                }

                $candidate = self::clean_source_text($candidate);
                if (strlen($candidate) > strlen($best)) {
                    $best = $candidate;
                }
            }

            if ($best === '') {
                $paragraphs = $xpath->query('//p');
                if ($paragraphs && $paragraphs->length > 0) {
                    $parts = array();
                    for ($i = 0; $i < min(30, $paragraphs->length); $i++) {
                        $paragraph = $paragraphs->item($i);
                        if (!$paragraph) {
                            continue;
                        }
                        $text = self::clean_source_text($paragraph->textContent);
                        if ($text !== '') {
                            $parts[] = $text;
                        }
                    }
                    $best = trim(implode("\n\n", $parts));
                }
            }

            if ($best === '') {
                return '';
            }

            if (strlen($best) > 8000) {
                $best = substr($best, 0, 8000);
            }

            return $best;
        }

        public static function extract_page_context($url, $video_selector_class = '', $image_selector_class = '', $link_selector_class = '', $source_context_filters = array(), $content_selector = '')
        {
            $url = esc_url_raw(trim((string) $url));
            if ($url === '') {
                return array(
                    'title' => '',
                    'content' => '',
                    'excerpt' => '',
                    'outline' => array(),
                    'outline_sections' => array(),
                    'outline_text' => '',
                    'video_sections' => array(),
                );
            }

            $html_result = Content_Rank_Generator_Helper::fetch_source_page_html_result($url, 5, 'page_context');
            if (is_array($html_result) && !empty($html_result['status_code']) && in_array(intval($html_result['status_code']), array(402, 403), true)) {
                return new WP_Error('content_rank_source_blocked', !empty($html_result['error_message']) ? (string) $html_result['error_message'] : 'A fonte retornou um status HTTP bloqueado.');
            }
            $html = is_array($html_result) && isset($html_result['html']) ? (string) $html_result['html'] : '';
            $resolved_url = is_array($html_result) && !empty($html_result['resolved_url']) ? (string) $html_result['resolved_url'] : $url;
            if ($html === '') {
                return array(
                    'title' => '',
                    'html' => '',
                    'content' => '',
                    'content_html' => '',
                    'excerpt' => '',
                    'outline' => array(),
                    'outline_sections' => array(),
                    'outline_text' => '',
                    'video_sections' => array(),
                );
            }
            $title = self::extract_page_title_from_html($html);
            $content = self::extract_page_content_from_html($html, $content_selector);
            $content_html = Content_Rank_Generator_Helper::extract_html_from_html_with_fallbacks($html, $content_selector);
            $excerpt = $content !== '' ? wp_trim_words($content, 24) : '';
            $video_sections = Content_Rank_Generator_Helper::extract_video_sections_from_raw_source_html($html, $resolved_url, $content_selector);
            $outline = Content_Rank_Generator_Helper::extract_page_outline_from_html($html, $resolved_url, 50, 10, 5, $image_selector_class, $link_selector_class, $content_selector);
            $page_context = array(
                'title' => $title,
                'html' => $html,
                'content' => $content,
                'content_html' => $content_html,
                'excerpt' => $excerpt,
                'outline' => $outline,
                'outline_sections' => $outline,
                'outline_text' => Content_Rank_Generator_Helper::format_page_outline_for_prompt($outline),
                'video_sections' => $video_sections,
                'source_url' => $resolved_url,
            );
            $page_context = Content_Rank_Generator_Helper::apply_source_context_filters_to_page_context($page_context, $source_context_filters);
            $outline = !empty($page_context['outline']) && is_array($page_context['outline']) ? $page_context['outline'] : array();
            $outline_text = !empty($page_context['outline_text']) ? (string) $page_context['outline_text'] : '';

            return $page_context;
        }

        public static function rss_item_needs_page_context($item)
        {
            $title = isset($item['title']) ? trim((string) $item['title']) : '';
            $excerpt = isset($item['excerpt']) ? trim((string) $item['excerpt']) : '';
            $content = isset($item['content']) ? trim((string) $item['content']) : '';
            $combined = trim(preg_replace('/\s+/', ' ', $title . ' ' . $excerpt . ' ' . $content));

            if ($combined === '') {
                return true;
            }

            $excerpt_length = strlen($excerpt);
            $content_length = strlen($content);
            $combined_length = strlen($combined);

            if ($content_length < 220 && $excerpt_length < 180) {
                return true;
            }

            if ($content !== '' && $excerpt !== '' && $content === $excerpt) {
                return true;
            }

            if ($combined_length < 420) {
                return true;
            }

            return false;
        }

        public static function merge_sparse_source_text($base, $addition)
        {
            $base = trim((string) $base);
            $addition = trim((string) $addition);

            if ($base === '') {
                return $addition;
            }
            if ($addition === '') {
                return $base;
            }
            if (stripos($addition, $base) !== false) {
                return $addition;
            }
            if (stripos($base, $addition) !== false) {
                return $base;
            }

            return trim($base . "\n\n" . $addition);
        }

        public static function maybe_enrich_rss_item_context($generator, $item)
        {
            $should_use_source_page_context = self::generator_uses_source_page_context($generator);
            if (!$should_use_source_page_context) {
                return $item;
            }

            if (!empty($item['source_context_enriched']) && (!empty($item['source_page_content_html']) || !empty($item['source_page_content']) || !empty($item['source_page_html']))) {
                return $item;
            }

            $permalink = !empty($item['permalink']) ? trim((string) $item['permalink']) : '';
            if ($permalink === '') {
                return $item;
            }

            $video_selector_class = !empty($generator['video_selector_class']) ? $generator['video_selector_class'] : '';
            $image_selector_class = !empty($generator['image_selector_class']) ? $generator['image_selector_class'] : '';
            $link_selector_class = !empty($generator['link_selector_class']) ? $generator['link_selector_class'] : '';
            $content_selector = !empty($generator['content_selector']) ? $generator['content_selector'] : '';
            $page_context = self::extract_page_context($permalink, $video_selector_class, $image_selector_class, $link_selector_class, self::get_generator_source_context_filters($generator), $content_selector);
            if (is_wp_error($page_context)) {
                return $page_context;
            }
            if (empty($page_context) || (!empty($page_context['title']) === false && !empty($page_context['content']) === false && !empty($page_context['excerpt']) === false && !empty($page_context['outline_text']) === false)) {
                return $item;
            }

            $original_excerpt = isset($item['excerpt']) ? (string) $item['excerpt'] : '';
            $original_content = isset($item['content']) ? (string) $item['content'] : '';
            $page_title = !empty($page_context['title']) ? trim((string) $page_context['title']) : '';
            $page_excerpt = !empty($page_context['excerpt']) ? trim((string) $page_context['excerpt']) : '';
            $page_content = !empty($page_context['content']) ? trim((string) $page_context['content']) : '';
            $page_content_html = !empty($page_context['content_html']) ? trim((string) $page_context['content_html']) : '';
            $page_html = !empty($page_context['html']) ? trim((string) $page_context['html']) : '';
            $page_outline_text = !empty($page_context['outline_text']) ? trim((string) $page_context['outline_text']) : '';

            if ($page_title !== '' && (empty($item['title']) || strlen(trim((string) $item['title'])) < 45)) {
                $item['title'] = $page_title;
            }

            if ($page_excerpt !== '') {
                $item['excerpt'] = self::merge_sparse_source_text($original_excerpt, $page_excerpt);
            }

            if ($page_content !== '') {
                $item['content'] = self::merge_sparse_source_text($original_content, $page_content);
            }

            $item['source_page_title'] = $page_title;
            $item['source_page_excerpt'] = $page_excerpt;
            $item['source_page_content'] = $page_content;
            $item['source_page_content_html'] = $page_content_html !== '' ? $page_content_html : $page_html;
            $item['source_page_html'] = $page_html;
            $item['source_page_outline'] = $page_outline_text;
            if (!empty($page_context['outline']) && is_array($page_context['outline'])) {
                $item['source_page_outline_sections'] = $page_context['outline'];
            }
            if (!empty($page_context['video_sections']) && is_array($page_context['video_sections'])) {
                $item['source_page_video_sections'] = $page_context['video_sections'];
            }
            $item['source_context_enriched'] = 1;

            return $item;
        }

        public static function url_looks_like_image($url)
        {
            return (bool) preg_match('/\.(jpe?g|png|gif|webp|avif|bmp)(?:$|[?#])/i', (string) $url);
        }

        public static function url_looks_like_video($url)
        {
            return (bool) preg_match('/\.(mp4|m4v|mov|webm|ogv|m3u8)(?:$|[?#])/i', (string) $url);
        }

        public static function is_probably_bad_featured_image_url($url, $context = '')
        {
            $url = trim((string) $url);
            if ($url === '') {
                return true;
            }

            if (preg_match('/\.svg(?:$|[?#])/i', $url)) {
                return true;
            }

            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            $basename = strtolower((string) pathinfo($path, PATHINFO_FILENAME));
            $haystack = strtolower($url . ' ' . (string) $context . ' ' . $basename);
            $bad_terms = array(
                'logo',
                'site-logo',
                'brand',
                'icon',
                'avatar',
                'sprite',
                'placeholder',
                'default',
                'favicon',
                'wordmark',
                'masthead',
                'header-logo',
                'footer-logo',
                'watermark',
                'badge',
            );

            foreach ($bad_terms as $bad_term) {
                if ($bad_term !== '' && strpos($haystack, $bad_term) !== false) {
                    return true;
                }
            }

            return false;
        }

        public static function is_video_embed_url($url)
        {
            $url = (string) $url;
            if (self::url_looks_like_video($url)) {
                return true;
            }
            return (bool) preg_match('~(youtube\.com|youtu\.be|vimeo\.com|dailymotion\.com|tiktok\.com|streamable\.com)~i', $url);
        }

        public static function resolve_url_against_base($url, $base_url = '')
        {
            $url = trim((string) $url);
            $base_url = trim((string) $base_url);
            if ($url === '') {
                return '';
            }
            if (preg_match('~^https?://~i', $url)) {
                return esc_url_raw($url);
            }
            if (strpos($url, '//') === 0) {
                $scheme = wp_parse_url($base_url, PHP_URL_SCHEME);
                if (!$scheme) {
                    $scheme = 'https';
                }
                return esc_url_raw($scheme . ':' . $url);
            }
            if ($base_url === '') {
                return esc_url_raw($url);
            }

            $parts = wp_parse_url($base_url);
            if (empty($parts['host'])) {
                return esc_url_raw($url);
            }

            $scheme = !empty($parts['scheme']) ? $parts['scheme'] : 'https';
            $host = $parts['host'];
            $port = !empty($parts['port']) ? ':' . $parts['port'] : '';

            if (substr($url, 0, 1) === '/') {
                return esc_url_raw($scheme . '://' . $host . $port . $url);
            }

            $path = !empty($parts['path']) ? $parts['path'] : '/';
            $directory = preg_replace('~/[^/]*$~', '/', $path);

            return esc_url_raw($scheme . '://' . $host . $port . $directory . $url);
        }

        public static function resolve_google_alerts_redirect_url($url)
        {
            $url = trim((string) $url);
            if ($url === '') {
                return '';
            }

            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
            $parts = wp_parse_url($url);
            if (empty($parts['host']) || empty($parts['path'])) {
                return esc_url_raw($url);
            }

            $host = strtolower(preg_replace('/^www\./i', '', (string) $parts['host']));
            if ($host !== 'google.com' && $host !== 'googleusercontent.com') {
                return esc_url_raw($url);
            }

            if (rtrim((string) $parts['path'], '/') !== '/url') {
                return esc_url_raw($url);
            }

            $query = array();
            if (!empty($parts['query'])) {
                parse_str((string) $parts['query'], $query);
            }

            foreach (array('url', 'q', 'u') as $key) {
                if (empty($query[$key])) {
                    continue;
                }
                $candidate = trim((string) $query[$key]);
                if ($candidate === '') {
                    continue;
                }
                if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
                    continue;
                }

                return esc_url_raw(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')));
            }

            return esc_url_raw($url);
        }



        public static function extract_media_from_enclosures($item, $allow_video = true)
        {
            $media = array(
                'image_url' => '',
                'video_url' => '',
                'video_embed_html' => '',
                'video_source' => '',
            );

            $enclosures = array();
            if (is_object($item)) {
                if (method_exists($item, 'get_enclosures')) {
                    $enclosures = (array) $item->get_enclosures();
                } elseif (method_exists($item, 'get_enclosure')) {
                    $enclosure = $item->get_enclosure();
                    if ($enclosure) {
                        $enclosures = array($enclosure);
                    }
                }
            } elseif (is_array($item)) {
                if (!empty($item['enclosures']) && is_array($item['enclosures'])) {
                    $enclosures = $item['enclosures'];
                } elseif (!empty($item['enclosure'])) {
                    $enclosure = $item['enclosure'];
                    $enclosures = is_array($enclosure) ? $enclosure : array($enclosure);
                }
            }

            foreach ($enclosures as $enclosure) {
                if (!is_object($enclosure)) {
                    continue;
                }

                $type = '';
                if (method_exists($enclosure, 'get_type')) {
                    $type = strtolower(trim((string) $enclosure->get_type()));
                }
                if ($type === '' && method_exists($enclosure, 'get_real_type')) {
                    $type = strtolower(trim((string) $enclosure->get_real_type()));
                }

                $link = '';
                if (method_exists($enclosure, 'get_link')) {
                    $link = esc_url_raw(trim((string) $enclosure->get_link()));
                }

                $thumbnail = '';
                if (method_exists($enclosure, 'get_thumbnails')) {
                    $thumbnails = (array) $enclosure->get_thumbnails();
                    if (!empty($thumbnails)) {
                        $thumbnail = esc_url_raw(trim((string) reset($thumbnails)));
                    }
                }
                if ($thumbnail === '' && method_exists($enclosure, 'get_thumbnail')) {
                    $thumbnail = esc_url_raw(trim((string) $enclosure->get_thumbnail()));
                }

                if ($media['image_url'] === '') {
                    if ($thumbnail !== '') {
                        $media['image_url'] = $thumbnail;
                    } elseif ($link !== '' && (self::url_looks_like_image($link) || strpos($type, 'image/') === 0)) {
                        $media['image_url'] = $link;
                    }
                }

                if ($allow_video && $media['video_url'] === '' && $link !== '' && (self::url_looks_like_video($link) || strpos($type, 'video/') === 0 || self::is_video_embed_url($link))) {
                    $media['video_url'] = $link;
                }

                if ($media['image_url'] !== '' && $media['video_url'] !== '') {
                    break;
                }
            }

            return $media;
        }

        public static function extract_html_attributes($html_tag)
        {
            $attributes = array();
            $html_tag = (string) $html_tag;
            if ($html_tag === '' || !preg_match('/^<\s*[\w:-]+\b([^>]*)>/i', $html_tag, $matches)) {
                return $attributes;
            }

            $attr_string = $matches[1];
            if (!preg_match_all('/([\w:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $attr_string, $attr_matches, PREG_SET_ORDER)) {
                return $attributes;
            }

            foreach ($attr_matches as $attr_match) {
                $name = strtolower($attr_match[1]);
                $value = '';
                if (isset($attr_match[2]) && $attr_match[2] !== '') {
                    $value = $attr_match[2];
                } elseif (isset($attr_match[3]) && $attr_match[3] !== '') {
                    $value = $attr_match[3];
                } elseif (isset($attr_match[4]) && $attr_match[4] !== '') {
                    $value = $attr_match[4];
                }
                $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
            }

            return $attributes;
        }

        public static function html_class_has_video_marker($class_value)
        {
            $class_value = strtolower(trim((string) $class_value));
            if ($class_value === '') {
                return false;
            }
            return (bool) preg_match('/(?:^|\s)(gallery-image-video|gallery-video|oembed|video|player|youtube|vimeo|dailymotion|tiktok)(?:\s|$)/i', $class_value);
        }

        public static function normalize_video_selector_class_tokens($selector_class)
        {
            $selector_class = trim((string) $selector_class);
            if ($selector_class === '') {
                return array();
            }

            $selector_class = str_replace(array("\r", "\n", "\t", ','), ' ', $selector_class);
            $selector_class = preg_replace('/\s+/', ' ', $selector_class);
            if ($selector_class === '') {
                return array();
            }

            $tokens = array();
            foreach (explode(' ', $selector_class) as $token) {
                $token = strtolower(sanitize_html_class(trim($token)));
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }

            return array_values(array_unique($tokens));
        }

        public static function extract_dom_node_attributes($node)
        {
            $attributes = array();
            if (!is_object($node) || !property_exists($node, 'attributes') || empty($node->attributes)) {
                return $attributes;
            }

            foreach ($node->attributes as $attribute) {
                if (!is_object($attribute) || !isset($attribute->name)) {
                    continue;
                }

                $name = strtolower((string) $attribute->name);
                if ($name === '') {
                    continue;
                }

                $value = isset($attribute->value) ? (string) $attribute->value : '';
                $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
            }

            return $attributes;
        }

        public static function extract_video_candidate_from_html($html, $base_url = '', $video_selector_class = '')
        {
            $result = array(
                'video_url' => '',
                'video_embed_html' => '',
                'video_source' => '',
                'video_class' => '',
                'video_attr' => '',
                'video_tag' => '',
            );

            $html = (string) $html;
            if ($html === '') {
                return $result;
            }

            // Keep the argument for backwards compatibility, but never limit
            // video discovery to a user-provided CSS class.
            $video_selector_class = '';

            if ($video_selector_class !== '') {
                $tokens = self::normalize_video_selector_class_tokens($video_selector_class);
                if (empty($tokens) || !class_exists('DOMDocument') || !class_exists('DOMXPath')) {
                    return $result;
                }

                $previous_libxml = libxml_use_internal_errors(true);
                $dom = new DOMDocument();
                $dom_html = $html;
                $dom_html = '<?xml encoding="utf-8" ?>' . $dom_html;

                $loaded = $dom->loadHTML($dom_html, LIBXML_NOWARNING | LIBXML_NOERROR);
                libxml_clear_errors();
                libxml_use_internal_errors($previous_libxml);

                if ($loaded) {
                    $xpath = new DOMXPath($dom);
                    $class_expr = "translate(concat(' ', normalize-space(@class), ' '), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')";
                    $conditions = array();
                    foreach ($tokens as $token) {
                        $conditions[] = "contains(" . $class_expr . ", ' " . strtolower($token) . " ')";
                    }

                    $query = '//*[' . implode(' and ', $conditions) . ']';
                    $wrappers = $xpath->query($query);
                    if ($wrappers && $wrappers->length > 0) {
                        foreach ($wrappers as $wrapper) {
                            $iframe_nodes = $xpath->query('.//iframe', $wrapper);
                            if (!$iframe_nodes || $iframe_nodes->length === 0) {
                                continue;
                            }

                            $iframe_node = $iframe_nodes->item(0);
                            $iframe_attrs = self::extract_dom_node_attributes($iframe_node);
                            $candidate_url = '';
                            $candidate_attr = '';
                            foreach (array('src', 'data-src', 'data-oembed-url', 'data-lazy-src', 'data-video-url', 'data-url') as $attr_name) {
                                if (!empty($iframe_attrs[$attr_name])) {
                                    $candidate_url = (string) $iframe_attrs[$attr_name];
                                    $candidate_attr = $attr_name;
                                    break;
                                }
                            }

                            if ($candidate_url === '') {
                                continue;
                            }

                            $resolved_url = self::resolve_url_against_base($candidate_url, $base_url);
                            if ($resolved_url === '') {
                                continue;
                            }

                            $wrapper_attrs = self::extract_dom_node_attributes($wrapper);
                            $result['video_url'] = $resolved_url;
                            $result['video_source'] = 'selector_class_match';
                            $result['video_class'] = !empty($wrapper_attrs['class']) ? $wrapper_attrs['class'] : implode(' ', $tokens);
                            $result['video_attr'] = $candidate_attr;
                            $result['video_tag'] = 'iframe';

                            return $result;
                        }
                    }
                }

                return $result;
            }

            $wrapper_matches = array();
            if (preg_match_all('/<div\b(?=[^>]*class=["\'][^"\']*(?:slide-key|image-holder|gallery-image-holder|credit-image-wrap|lead-image-holder)[^"\']*["\'])[^>]*>.*?<iframe\b[^>]*src=["\']([^"\']+)["\'][^>]*>.*?<\/iframe>.*?<\/div>/is', $html, $wrapper_matches, PREG_SET_ORDER) && !empty($wrapper_matches)) {
                foreach ($wrapper_matches as $wrapper_match) {
                    $wrapper_html = isset($wrapper_match[0]) ? (string) $wrapper_match[0] : '';
                    $candidate_url = isset($wrapper_match[1]) ? (string) $wrapper_match[1] : '';
                    $resolved_url = $candidate_url !== '' ? self::resolve_url_against_base($candidate_url, $base_url) : '';
                    if ($resolved_url !== '' && self::is_video_embed_url($resolved_url)) {
                        $result['video_url'] = $resolved_url;
                        $result['video_source'] = 'wrapper_iframe_match';
                        $result['video_class'] = 'slide-key/image-holder wrapper';
                        $result['video_attr'] = 'src';
                        $result['video_tag'] = 'div';

                        return $result;
                    }
                }
            }

            $iframe_matches = array();
            if (preg_match_all('/<iframe\b[^>]*>.*?<\/iframe>/is', $html, $iframe_matches) && !empty($iframe_matches[0])) {
                foreach ($iframe_matches[0] as $iframe_html) {
                    $attrs = self::extract_html_attributes($iframe_html);
                    $class_value = isset($attrs['class']) ? (string) $attrs['class'] : '';
                    $candidate_url = '';
                    $candidate_attr = '';
                    foreach (array('src', 'data-src', 'data-oembed-url', 'data-lazy-src', 'data-video-url', 'data-url') as $attr_name) {
                        if (!empty($attrs[$attr_name])) {
                            $candidate_url = (string) $attrs[$attr_name];
                            $candidate_attr = $attr_name;
                            break;
                        }
                    }

                    $resolved_url = $candidate_url !== '' ? self::resolve_url_against_base($candidate_url, $base_url) : '';
                    $class_matches = self::html_class_has_video_marker($class_value);
                    $url_matches = $resolved_url !== '' && self::is_video_embed_url($resolved_url);

                    if ($class_matches || $url_matches) {
                        $result['video_url'] = $url_matches ? $resolved_url : $result['video_url'];
                        $result['video_source'] = $class_matches ? 'iframe_class_match' : 'iframe_url_match';
                        $result['video_class'] = $class_value;
                        $result['video_attr'] = $candidate_attr;
                        $result['video_tag'] = 'iframe';

                        return $result;
                    }
                }
            }

            $video_matches = array();
            if (preg_match_all('/<(video|source)\b[^>]*>.*?<\/\1>/is', $html, $video_matches) && !empty($video_matches[0])) {
                foreach ($video_matches[0] as $video_html) {
                    $attrs = self::extract_html_attributes($video_html);
                    $candidate_url = '';
                    $candidate_attr = '';
                    foreach (array('src', 'data-src', 'data-video-url', 'data-url', 'data-lazy-src') as $attr_name) {
                        if (!empty($attrs[$attr_name])) {
                            $candidate_url = (string) $attrs[$attr_name];
                            $candidate_attr = $attr_name;
                            break;
                        }
                    }

                    $resolved_url = $candidate_url !== '' ? self::resolve_url_against_base($candidate_url, $base_url) : '';
                    if ($resolved_url !== '' && self::is_video_embed_url($resolved_url)) {
                        $result['video_url'] = $resolved_url;
                        $result['video_embed_html'] = $video_html;
                        $result['video_source'] = 'video_tag_match';
                        $result['video_attr'] = $candidate_attr;
                        $result['video_tag'] = strpos($video_html, '<source') !== false ? 'source' : 'video';

                        return $result;
                    }
                }
            }

            $source_matches = array();
            if (preg_match_all('/<source\b[^>]*>/i', $html, $source_matches) && !empty($source_matches[0])) {
                foreach ($source_matches[0] as $source_html) {
                    $attrs = self::extract_html_attributes($source_html);
                    $candidate_url = '';
                    $candidate_attr = '';
                    foreach (array('src', 'data-src', 'data-video-url', 'data-url', 'data-lazy-src') as $attr_name) {
                        if (!empty($attrs[$attr_name])) {
                            $candidate_url = (string) $attrs[$attr_name];
                            $candidate_attr = $attr_name;
                            break;
                        }
                    }

                    $resolved_url = $candidate_url !== '' ? self::resolve_url_against_base($candidate_url, $base_url) : '';
                    if ($resolved_url !== '' && self::is_video_embed_url($resolved_url)) {
                        $result['video_url'] = $resolved_url;
                        $result['video_source'] = 'source_tag_match';
                        $result['video_attr'] = $candidate_attr;
                        $result['video_tag'] = 'source';

                        return $result;
                    }
                }
            }

            if (preg_match('~(https?://[^"\'<>\s]+(?:youtube\.com/embed/|youtu\.be/|player\.vimeo\.com/video/|vimeo\.com/|dailymotion\.com/embed/video/|tiktok\.com/embed/|streamable\.com/[^"\'<>\s]+))~i', $html, $matches)) {
                $candidate_url = self::resolve_url_against_base($matches[1], $base_url);
                if ($candidate_url !== '') {
                    $result['video_url'] = $candidate_url;
                    $result['video_source'] = 'url_scan_match';

                    return $result;
                }
            }

            return $result;
        }



        public static function resolve_item_media($item, $permalink, $excerpt_html, $content_html, $video_selector_class = '', $image_selector_class = '', $link_selector_class = '')
        {
            // Video enclosures are always eligible; the selector is no longer
            // used to decide whether a page contains a video.
            $allow_video_enclosures = true;
            $page_media = array(
                'image_url' => '',
                'image_source' => '',
                'image_class' => '',
                'image_attr' => '',
                'image_tag' => '',
                'link_url' => '',
                'link_text' => '',
                'link_source' => '',
                'video_url' => '',
                'video_embed_html' => '',
                'video_source' => '',
            );

            $candidates = array();
            if (!empty($content_html)) {
                $candidate = Content_Rank_Generator_Helper::extract_media_from_html($content_html, $permalink, $video_selector_class, $image_selector_class, $link_selector_class, false);
                $featured_image = Content_Rank_Generator_Helper::extract_featured_image_from_html($content_html, $permalink);
                foreach (array('image_url', 'image_source', 'image_class', 'image_attr', 'image_tag') as $image_key) {
                    $candidate[$image_key] = !empty($featured_image[$image_key]) ? $featured_image[$image_key] : '';
                }
                $candidates[] = $candidate;
            }
            if (!empty($excerpt_html)) {
                $candidate = Content_Rank_Generator_Helper::extract_media_from_html($excerpt_html, $permalink, $video_selector_class, $image_selector_class, $link_selector_class, false);
                $featured_image = Content_Rank_Generator_Helper::extract_featured_image_from_html($excerpt_html, $permalink);
                foreach (array('image_url', 'image_source', 'image_class', 'image_attr', 'image_tag') as $image_key) {
                    $candidate[$image_key] = !empty($featured_image[$image_key]) ? $featured_image[$image_key] : '';
                }
                $candidates[] = $candidate;
            }

            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                foreach (array('image_url', 'image_source', 'image_class', 'image_attr', 'image_tag', 'link_url', 'link_text', 'link_source', 'video_url', 'video_embed_html', 'video_source') as $key) {
                    if (empty($page_media[$key]) && !empty($candidate[$key])) {
                        $page_media[$key] = $candidate[$key];
                    }
                }
            }

            if (!empty($permalink) && (empty($page_media['image_url']) || empty($page_media['video_url']) || empty($page_media['link_url']))) {
                $source_page_media = Content_Rank_Generator_Helper::extract_media_from_source_page($permalink, $video_selector_class, $image_selector_class, $link_selector_class, false);
                if (is_array($source_page_media)) {
                    foreach (array('image_url', 'image_source', 'image_class', 'image_attr', 'image_tag', 'link_url', 'link_text', 'link_source', 'video_url', 'video_embed_html', 'video_source') as $key) {
                        if (empty($page_media[$key]) && !empty($source_page_media[$key])) {
                            $page_media[$key] = $source_page_media[$key];
                        }
                    }
                }
            }

            $media = $page_media;
            $enclosure_media = self::extract_media_from_enclosures($item, $allow_video_enclosures);

            if ((!isset($media['image_url']) || $media['image_url'] === '') && !empty($enclosure_media['image_url'])) {
                if (self::is_probably_bad_featured_image_url($enclosure_media['image_url'], $permalink)) {
                    self::log_image_debug('image_candidate_rejected', array(
                        'stage' => 'enclosure',
                        'permalink' => $permalink,
                        'image_url' => $enclosure_media['image_url'],
                    ));
                } else {
                    $media['image_url'] = $enclosure_media['image_url'];
                    if (empty($media['image_source'])) {
                        $media['image_source'] = 'enclosure_fallback';
                    }
                    if (empty($media['image_tag'])) {
                        $media['image_tag'] = 'enclosure';
                    }
                }
            }

            if ($media['video_url'] === '' && !empty($enclosure_media['video_url'])) {
                $media['video_url'] = $enclosure_media['video_url'];
            }
            if (empty($media['video_source']) && !empty($enclosure_media['video_url'])) {
                $media['video_source'] = 'enclosure_fallback';
            }

            return $media;
        }

        public static function hydrate_rss_item_for_generation($generator, $item)
        {
            return $item;
        }

        public static function resolve_item_media_for_generation($generator, $item)
        {
            $permalink = !empty($item['permalink']) ? trim((string) $item['permalink']) : '';
            if ($permalink === '') {
                return $item;
            }

            $video_selector_class = !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '';
            $image_selector_class = !empty($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '';
            $link_selector_class = !empty($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '';
            $excerpt_html = isset($item['excerpt_html']) ? (string) $item['excerpt_html'] : (isset($item['excerpt']) ? (string) $item['excerpt'] : '');
            $content_html = isset($item['content_html']) ? (string) $item['content_html'] : (isset($item['content']) ? (string) $item['content'] : '');
            $media = self::resolve_item_media($item, $permalink, $excerpt_html, $content_html, $video_selector_class, $image_selector_class, $link_selector_class);

            if (!empty($media['image_url'])) {
                $item['source_image_url'] = $media['image_url'];
            }
            if (!empty($media['link_url'])) {
                $item['source_link_url'] = $media['link_url'];
            }
            if (!empty($media['link_text'])) {
                $item['source_link_text'] = $media['link_text'];
            }
            if (!empty($media['video_url'])) {
                $item['source_video_url'] = $media['video_url'];
            }
            if (!empty($media['video_embed_html'])) {
                $item['source_video_embed_html'] = $media['video_embed_html'];
            }
            if (!empty($media['video_source'])) {
                $item['source_video_source'] = $media['video_source'];
            }
            if (!empty($image_selector_class)) {
                $item['source_image_selector_class'] = $image_selector_class;
            }
            if (!empty($link_selector_class)) {
                $item['source_link_selector_class'] = $link_selector_class;
            }

            if (empty($item['source_page_video_sections']) && !empty($generator['source_video_enabled']) && $permalink !== '') {
                $source_page_html = !empty($item['source_page_html'])
                    ? (string) $item['source_page_html']
                    : Content_Rank_Generator_Helper::fetch_source_page_html($permalink, 5, 'source_video_sections');
                if ($source_page_html !== '') {
                    $item['source_page_html'] = $source_page_html;
                }
            }
            if (empty($item['source_page_video_sections']) && !empty($item['source_page_html'])) {
                $item['source_page_video_sections'] = Content_Rank_Generator_Helper::extract_video_sections_from_raw_source_html(
                    $item['source_page_html'],
                    $permalink,
                    !empty($generator['content_selector']) ? $generator['content_selector'] : ''
                );
            }

            return $item;
        }

        public static function extract_video_url_from_embed_html($html, $base_url = '')
        {
            $html = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
            if ($html === '') {
                return '';
            }

            if (preg_match('/<iframe\b[^>]*(?:src|data-src|data-oembed-url|data-lazy-src|data-video-url|data-url)=["\']([^"\']+)["\']/i', $html, $matches)) {
                return self::normalize_embed_target_url(self::resolve_url_against_base(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $base_url));
            }

            if (preg_match('~(https?://(?:www\.)?youtube\.com/watch\?(?:[^"\'<>\s]|&amp;|&)+|https?://youtu\.be/[^"\'<>\s]+|https?://(?:www\.)?vimeo\.com/[0-9]+|https?://player\.vimeo\.com/video/[0-9]+|https?://(?:www\.)?dailymotion\.com/(?:video|embed/video)/[^"\'<>\s]+|https?://(?:www\.)?tiktok\.com/@[^"\'<>\s]+/video/\d+|https?://(?:www\.)?streamable\.com/[^"\'<>\s]+)~i', $html, $matches)) {
                return self::normalize_embed_target_url(self::resolve_url_against_base(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $base_url));
            }

            if (preg_match('~(https?://[^"\'<>\s]+(?:youtube\.com/embed/|youtu\.be/|player\.vimeo\.com/video/|vimeo\.com/|dailymotion\.com/embed/video/|tiktok\.com/embed/|streamable\.com/[^"\'<>\s]+))~i', $html, $matches)) {
                return self::normalize_embed_target_url(self::resolve_url_against_base($matches[1], $base_url));
            }

            return '';
        }

        public static function detect_video_provider_slug($video_url)
        {
            $video_url = (string) $video_url;
            if (preg_match('~(youtube\.com|youtu\.be)~i', $video_url)) {
                return 'youtube';
            }
            if (preg_match('~vimeo\.com~i', $video_url)) {
                return 'vimeo';
            }
            if (preg_match('~dailymotion\.com~i', $video_url)) {
                return 'dailymotion';
            }
            if (preg_match('~tiktok\.com~i', $video_url)) {
                return 'tiktok';
            }
            if (preg_match('~streamable\.com~i', $video_url)) {
                return 'streamable';
            }
            return 'video';
        }

        public static function wrap_video_embed_block_html($video_url, $embed_html, &$debug = null)
        {
            $video_url = esc_url_raw(trim((string) $video_url));
            $embed_html = trim((string) $embed_html);
            $provider = self::detect_video_provider_slug($video_url);
            $debug = array(
                'mode' => 'wp_embed_block',
                'source' => '',
                'provider' => $provider,
                'embed_length' => 0,
            );

            if ($embed_html === '') {
                return '';
            }

            $classes = array(
                'wp-block-embed',
                'is-type-video',
                'is-provider-' . $provider,
                'wp-block-embed-' . $provider,
                'wp-embed-aspect-16-9',
                'wp-has-aspect-ratio',
            );
            $wrapped = '<figure class="' . esc_attr(implode(' ', $classes)) . '"><div class="wp-block-embed__wrapper">' . $embed_html . '</div></figure>';
            $debug['embed_length'] = strlen($wrapped);

            return $wrapped;
        }

        public static function build_video_embed_html($video_url, &$debug = null)
        {
            $video_url = esc_url_raw(trim((string) $video_url));
            $debug = array(
                'mode' => 'empty',
                'source' => '',
                'provider' => self::detect_video_provider_slug($video_url),
                'embed_length' => 0,
            );
            if ($video_url === '') {
                return '';
            }

            $embed = wp_oembed_get($video_url);
            if (is_string($embed) && trim($embed) !== '') {
                $debug['source'] = 'wp_oembed_get';
                return self::wrap_video_embed_block_html($video_url, $embed, $debug);
            }

            if (self::url_looks_like_video($video_url)) {
                if (!function_exists('wp_video_shortcode')) {
                    require_once ABSPATH . WPINC . '/media.php';
                }
                $shortcode = wp_video_shortcode(array('src' => $video_url));
                if (is_string($shortcode) && trim($shortcode) !== '') {
                    $debug['source'] = 'wp_video_shortcode';
                    return self::wrap_video_embed_block_html($video_url, $shortcode, $debug);
                }
                $debug['source'] = 'shortcode_video';
                $debug['mode'] = 'video_url_fallback';
                return '<figure class="wp-block-embed is-type-video is-provider-video wp-block-embed-video wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper"><a href="' . esc_url($video_url) . '">' . esc_html($video_url) . '</a></div></figure>';
            }

            if (self::is_video_embed_url($video_url)) {
                $embed = wp_oembed_get($video_url);
                if (is_string($embed) && trim($embed) !== '') {
                    $debug['source'] = 'wp_oembed_get';
                    return self::wrap_video_embed_block_html($video_url, $embed, $debug);
                }
                $debug['source'] = 'embed_url_fallback';
                $debug['mode'] = 'embed_url_fallback';
                return '<figure class="wp-block-embed is-type-video is-provider-video wp-block-embed-video wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper"><a href="' . esc_url($video_url) . '">' . esc_html($video_url) . '</a></div></figure>';
            }

            return '';
        }

        public static function log_image_debug($stage, $context = array())
        {
            return null;
        }

        public static function build_featured_image_filename($title, $image_url, $source_label = 'source')
        {
            $base_title = sanitize_file_name(sanitize_title((string) $title));
            if ($base_title === '') {
                $base_title = 'featured-image';
            }

            $source_label = sanitize_key((string) $source_label);
            if ($source_label !== '') {
                $base_title .= '-' . $source_label;
            }

            $path = (string) wp_parse_url((string) $image_url, PHP_URL_PATH);
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp');
            if (!in_array($extension, $allowed_extensions, true)) {
                $extension = 'jpg';
            }

            return $base_title . '.' . $extension;
        }

        public static function build_featured_image_alt_text($title, $source_label = 'source', $query = '', $credit = '')
        {
            $alt = trim(wp_strip_all_tags((string) $title));
            if ($alt === '') {
                $alt = trim((string) $query);
            }
            if ($alt === '') {
                $alt = 'Imagem destacada';
            }

            return $alt;
        }

        public static function normalize_image_display_size($size)
        {
            $size = sanitize_key((string) $size);
            $allowed = array('thumbnail', 'medium', 'medium_large', 'large', 'full');
            if (!in_array($size, $allowed, true)) {
                return 'medium';
            }

            return $size;
        }

        public static function is_valid_featured_image_dimensions($width, $height)
        {
            $width = absint($width);
            $height = absint($height);
            if ($width < self::FEATURED_IMAGE_MIN_WIDTH || $height < self::FEATURED_IMAGE_MIN_HEIGHT) {
                return false;
            }

            $ratio = $height > 0 ? ($width / $height) : 0;
            return $ratio > 0
                && abs($ratio - self::FEATURED_IMAGE_TARGET_RATIO) <= self::FEATURED_IMAGE_RATIO_TOLERANCE;
        }

        /**
         * Resolve the content image size for the active generator.
         * List-backed generators do not expose the RSS size control, so use
         * a readable default instead of the database column default.
         */
        public static function get_content_image_size_for_generator($generator)
        {
            $generator = is_array($generator) ? $generator : array();
            $source_type = !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss';
            $generation_mode = !empty($generator['generation_mode']) ? sanitize_key((string) $generator['generation_mode']) : '';
            if ($generation_mode === 'satellite' || self::source_type_uses_keyword_list($source_type)) {
                return 'medium_large';
            }

            return !empty($generator['content_image_size'])
                ? self::normalize_image_display_size($generator['content_image_size'])
                : 'medium';
        }

        public static function download_image_attachment_from_url($post_id, $image_url, $title, $source_label = 'source', $query = '', $credit = '')
        {
            $post_id = intval($post_id);
            $image_url = esc_url_raw(trim((string) $image_url));
            if ($image_url === '') {
                self::log_image_debug('skip_empty_url', array(
                    'post_id' => $post_id,
                    'source_label' => sanitize_key($source_label),
                    'title' => $title,
                ));
                return false;
            }

            if ($source_label === 'source' && self::is_probably_bad_featured_image_url($image_url, $title)) {
                self::log_image_debug('skip_bad_source_image', array(
                    'post_id' => $post_id,
                    'source_label' => sanitize_key($source_label),
                    'image_url' => $image_url,
                    'title' => $title,
                ));
                return false;
            }

            self::log_image_debug('start_download', array(
                'post_id' => $post_id,
                'source_label' => sanitize_key($source_label),
                'image_url' => $image_url,
                'query' => $query,
                'credit' => $credit,
            ));

            if (!function_exists('download_url')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            if (!function_exists('media_handle_sideload')) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }
            if (!function_exists('wp_generate_attachment_metadata')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            $file_name = self::build_featured_image_filename($title, $image_url, $source_label);
            $alt_text = self::build_featured_image_alt_text($title, $source_label, $query, $credit);
            self::log_image_debug('prepared_attachment', array(
                'post_id' => $post_id,
                'source_label' => sanitize_key($source_label),
                'image_url' => $image_url,
                'file_name' => $file_name,
                'alt_text' => $alt_text,
            ));

            $tmp = download_url($image_url);
            if (is_wp_error($tmp)) {
                self::log_image_debug('download_failed', array(
                    'post_id' => $post_id,
                    'source_label' => sanitize_key($source_label),
                    'image_url' => $image_url,
                    'error' => $tmp->get_error_message(),
                ));
                return false;
            }

            if (sanitize_key((string) $source_label) === 'pexels') {
                $dimensions = @getimagesize($tmp);
                $width = is_array($dimensions) && isset($dimensions[0]) ? absint($dimensions[0]) : 0;
                $height = is_array($dimensions) && isset($dimensions[1]) ? absint($dimensions[1]) : 0;
                if (!self::is_valid_featured_image_dimensions($width, $height)) {
                    self::log_image_debug('pexels_thumbnail_rejected_dimensions', array(
                        'post_id' => $post_id,
                        'image_url' => $image_url,
                        'width' => $width,
                        'height' => $height,
                        'min_width' => self::FEATURED_IMAGE_MIN_WIDTH,
                        'min_height' => self::FEATURED_IMAGE_MIN_HEIGHT,
                    ));
                    wp_delete_file($tmp);
                    return false;
                }
            }

            $file_array = array(
                'name' => $file_name,
                'tmp_name' => $tmp,
            );

            $previous_error_handler = set_error_handler(function ($errno, $errstr, $errfile, $errline) {
                if (($errno === E_WARNING || $errno === E_NOTICE) && stripos((string) $errstr, 'exif_read_data(') !== false) {
                    return true;
                }
                if (($errno === E_WARNING || $errno === E_NOTICE) && stripos((string) $errstr, 'Incorrect APP1 Exif Identifier Code') !== false) {
                    return true;
                }
                return false;
            });
            $attachment_id = media_handle_sideload($file_array, $post_id, $title);
            restore_error_handler();
            if (!empty($tmp) && file_exists($tmp)) {
                wp_delete_file($tmp);
            }

            if (is_wp_error($attachment_id)) {
                self::log_image_debug('sideload_failed', array(
                    'post_id' => $post_id,
                    'source_label' => sanitize_key($source_label),
                    'image_url' => $image_url,
                    'error' => $attachment_id->get_error_message(),
                ));
                return false;
            }

            $attachment_update = wp_update_post(array(
                'ID' => $attachment_id,
                'post_title' => $title,
                'post_excerpt' => $alt_text,
            ), true);
            if (is_wp_error($attachment_update)) {
                self::log_image_debug('attachment_update_failed', array(
                    'post_id' => $post_id,
                    'source_label' => sanitize_key($source_label),
                    'attachment_id' => intval($attachment_id),
                    'error' => $attachment_update->get_error_message(),
                ));
            }

            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
            if ($post_id > 0) {
                wp_update_post(array(
                    'ID' => $attachment_id,
                    'post_parent' => $post_id,
                ));
            }

            self::log_image_debug('attachment_ready', array(
                'post_id' => $post_id,
                'source_label' => sanitize_key($source_label),
                'image_url' => $image_url,
                'attachment_id' => intval($attachment_id),
                'alt_text' => $alt_text,
                'file_name' => $file_name,
            ));

            return $attachment_id;
        }

        public static function build_attachment_image_figure_html($attachment_id, $size = 'medium', $alt_text = '', $align = 'alignleft')
        {
            $attachment_id = intval($attachment_id);
            if ($attachment_id <= 0) {
                return '';
            }

            $size = self::normalize_image_display_size($size);
            $alt_text = trim((string) $alt_text);
            if ($alt_text === '') {
                $alt_text = trim((string) get_the_title($attachment_id));
            }
            if ($alt_text === '') {
                $alt_text = 'Imagem';
            }

            $figure_class_names = array(
                'wp-block-image',
                'size-' . sanitize_html_class($size),
            );

            $img_class_names = array(
                'wp-image-' . $attachment_id,
            );

            $image_html = wp_get_attachment_image($attachment_id, $size, false, array(
                'alt' => $alt_text,
                'class' => implode(' ', array_values(array_filter(array_unique($img_class_names)))),
            ));
            if ($image_html === '') {
                return '';
            }

            return '<figure class="' . esc_attr(implode(' ', array_values(array_filter(array_unique($figure_class_names))))) . '">' . $image_html . '</figure>';
        }

        public static function download_and_set_featured_image_from_url($post_id, $image_url, $title, $source_label = 'source', $query = '', $credit = '')
        {
            $attachment_id = self::download_image_attachment_from_url($post_id, $image_url, $title, $source_label, $query, $credit);
            if (!$attachment_id) {
                return false;
            }

            $alt_text = self::build_featured_image_alt_text($title, $source_label, $query, $credit);
            $thumbnail_set = set_post_thumbnail($post_id, $attachment_id);
            self::log_image_debug('thumbnail_result', array(
                'post_id' => intval($post_id),
                'source_label' => sanitize_key($source_label),
                'image_url' => $image_url,
                'attachment_id' => intval($attachment_id),
                'thumbnail_set' => $thumbnail_set ? 1 : 0,
                'alt_text' => $alt_text,
            ));
            update_post_meta($post_id, '_content_rank_featured_image_source', sanitize_key($source_label));
            update_post_meta($post_id, '_content_rank_featured_image_url', $image_url);
            update_post_meta($post_id, '_content_rank_featured_image_alt', $alt_text);
            if ($query !== '') {
                update_post_meta($post_id, '_content_rank_featured_image_query', sanitize_text_field($query));
            }
            if ($credit !== '') {
                update_post_meta($post_id, '_content_rank_pexels_credit', sanitize_text_field($credit));
            }

            return $attachment_id;
        }

        public static function apply_featured_image_attachment($post_id, $attachment_id, $title, $source_label = 'source', $query = '', $credit = '', $image_url = '')
        {
            $post_id = intval($post_id);
            $attachment_id = intval($attachment_id);
            if ($post_id <= 0 || $attachment_id <= 0) {
                return false;
            }

            $alt_text = self::build_featured_image_alt_text($title, $source_label, $query, $credit);
            $thumbnail_set = set_post_thumbnail($post_id, $attachment_id);
            self::log_image_debug('thumbnail_result', array(
                'post_id' => $post_id,
                'source_label' => sanitize_key($source_label),
                'image_url' => $image_url,
                'attachment_id' => $attachment_id,
                'thumbnail_set' => $thumbnail_set ? 1 : 0,
                'alt_text' => $alt_text,
            ));

            update_post_meta($post_id, '_content_rank_featured_image_source', sanitize_key($source_label));
            if ($image_url !== '') {
                update_post_meta($post_id, '_content_rank_featured_image_url', esc_url_raw($image_url));
            }
            update_post_meta($post_id, '_content_rank_featured_image_alt', $alt_text);
            if ($query !== '') {
                update_post_meta($post_id, '_content_rank_featured_image_query', sanitize_text_field($query));
            }
            if ($credit !== '') {
                update_post_meta($post_id, '_content_rank_pexels_credit', sanitize_text_field($credit));
            }

            $attachment_update = wp_update_post(array(
                'ID' => $attachment_id,
                'post_title' => $title,
                'post_excerpt' => $alt_text,
            ), true);
            if (is_wp_error($attachment_update)) {
                self::log_image_debug('attachment_update_failed', array(
                    'post_id' => $post_id,
                    'source_label' => sanitize_key($source_label),
                    'attachment_id' => $attachment_id,
                    'error' => $attachment_update->get_error_message(),
                ));
            }

            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
            wp_update_post(array(
                'ID' => $attachment_id,
                'post_parent' => $post_id,
            ));

            return $attachment_id;
        }

        public static function create_placeholder_image_attachment($post_id, $title, $source_label = 'fallback', $query = '', $credit = '')
        {
            $post_id = intval($post_id);
            if ($post_id <= 0) {
                return false;
            }

            if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
                return self::create_svg_placeholder_image_attachment($post_id, $title, $source_label, $query, $credit);
            }

            if (!function_exists('wp_tempnam')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            if (!function_exists('media_handle_sideload')) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }
            if (!function_exists('wp_generate_attachment_metadata')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            $seed = md5((string) $title . '|' . (string) $source_label . '|' . get_bloginfo('name'));
            $red = hexdec(substr($seed, 0, 2));
            $green = hexdec(substr($seed, 2, 2));
            $blue = hexdec(substr($seed, 4, 2));

            $width = 1200;
            $height = 630;
            $image = imagecreatetruecolor($width, $height);
            if (!$image) {
                return false;
            }

            $bg = imagecolorallocate($image, $red, $green, $blue);
            $accent = imagecolorallocate($image, max(0, 255 - $red), max(0, 255 - $green), max(0, 255 - $blue));
            $text = imagecolorallocate($image, 255, 255, 255);
            $shadow = imagecolorallocatealpha($image, 0, 0, 0, 70);

            imagefilledrectangle($image, 0, 0, $width, $height, $bg);
            for ($i = 0; $i < 8; $i++) {
                $x1 = intval(($width / 8) * $i);
                $x2 = min($width, $x1 + intval($width / 10));
                imagefilledrectangle($image, $x1, 0, $x2, $height, $accent);
            }
            imagefilledellipse($image, intval($width * 0.8), intval($height * 0.22), 260, 260, imagecolorallocatealpha($image, 255, 255, 255, 110));
            imagefilledellipse($image, intval($width * 0.2), intval($height * 0.75), 340, 340, imagecolorallocatealpha($image, 255, 255, 255, 118));
            imagefilledrectangle($image, 0, intval($height * 0.72), $width, $height, imagecolorallocatealpha($image, 0, 0, 0, 95));

            $headline = trim(wp_strip_all_tags((string) $title));
            if ($headline === '') {
                $headline = 'Content Rank';
            }
            $headline = wp_trim_words($headline, 10, '...');
            $headline_lines = explode("\n", wordwrap($headline, 28, "\n", true));
            $y = 64;
            foreach (array_slice($headline_lines, 0, 3) as $headline_line) {
                imagestring($image, 5, 62, $y + 2, $headline_line, $shadow);
                imagestring($image, 5, 60, $y, $headline_line, $text);
                $y += 36;
            }

            $subtitle = 'Imagem criada automaticamente';
            imagestring($image, 3, 60, 214, $subtitle, $text);

            $file_name = self::build_featured_image_filename($headline, 'placeholder.png', $source_label);
            $temp_file = wp_tempnam($file_name);
            if ($temp_file === false || $temp_file === '') {
                imagedestroy($image);
                return self::create_svg_placeholder_image_attachment($post_id, $title, $source_label, $query, $credit);
            }

            $saved = imagepng($image, $temp_file);
            imagedestroy($image);
            if (!$saved) {
                if (file_exists($temp_file)) {
                    wp_delete_file($temp_file);
                }
                return self::create_svg_placeholder_image_attachment($post_id, $title, $source_label, $query, $credit);
            }

            $attachment_id = media_handle_sideload(array(
                'name' => $file_name,
                'tmp_name' => $temp_file,
            ), $post_id, $headline);

            if (is_wp_error($attachment_id)) {
                if (file_exists($temp_file)) {
                    wp_delete_file($temp_file);
                }
                return self::create_svg_placeholder_image_attachment($post_id, $title, $source_label, $query, $credit);
            }

            $attachment_url = wp_get_attachment_url($attachment_id);
            return self::apply_featured_image_attachment($post_id, $attachment_id, $headline, $source_label, $query, $credit, $attachment_url ? $attachment_url : '');
        }

        private static function create_svg_placeholder_image_attachment($post_id, $title, $source_label = 'fallback', $query = '', $credit = '')
        {
            if (!function_exists('wp_upload_bits')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            $headline = trim(wp_strip_all_tags((string) $title));
            if ($headline === '') {
                $headline = 'Content Rank';
            }
            $headline = wp_trim_words($headline, 12, '...');

            $seed = md5((string) $headline . '|' . (string) $source_label . '|' . get_bloginfo('name'));
            $background = '#' . substr($seed, 0, 6);
            $accent = '#' . substr($seed, 6, 6);
            $svg_headline = htmlspecialchars($headline, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $svg = '<?xml version="1.0" encoding="UTF-8"?>'
                . '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630">'
                . '<rect width="1200" height="630" fill="' . $background . '"/>'
                . '<path d="M0 0h1200v630H0z" fill="' . $accent . '" opacity=".28"/>'
                . '<circle cx="1010" cy="120" r="190" fill="#ffffff" opacity=".16"/>'
                . '<circle cx="160" cy="570" r="250" fill="#ffffff" opacity=".12"/>'
                . '<rect y="440" width="1200" height="190" fill="#000000" opacity=".34"/>'
                . '<text x="70" y="150" fill="#ffffff" font-family="Arial, sans-serif" font-size="42" font-weight="700">Content Rank</text>'
                . '<text x="70" y="525" fill="#ffffff" font-family="Arial, sans-serif" font-size="30">' . $svg_headline . '</text>'
                . '</svg>';

            $file_name = 'content-rank-fallback-' . substr($seed, 0, 12) . '.svg';
            $upload = wp_upload_bits($file_name, null, $svg);
            if (!empty($upload['error']) || empty($upload['file'])) {
                return false;
            }

            $attachment_id = wp_insert_attachment(array(
                'post_mime_type' => 'image/svg+xml',
                'post_title' => $headline,
                'post_content' => '',
                'post_status' => 'inherit',
            ), $upload['file'], $post_id, true);

            if (is_wp_error($attachment_id) || intval($attachment_id) <= 0) {
                wp_delete_file($upload['file']);
                return false;
            }

            update_post_meta($attachment_id, '_wp_attachment_metadata', array(
                'width' => 1200,
                'height' => 630,
                'file' => wp_basename($upload['file']),
                'sizes' => array(),
            ));

            return self::apply_featured_image_attachment(
                $post_id,
                $attachment_id,
                $headline,
                $source_label,
                $query,
                $credit,
                !empty($upload['url']) ? $upload['url'] : ''
            );
        }

        public static function get_rss_items($feed_url, $limit = 10, $include_media = true, $video_selector_class = '', $image_selector_class = '', $link_selector_class = '')
        {
            if (!function_exists('fetch_feed')) {
                require_once ABSPATH . WPINC . '/feed.php';
            }

            $feed_url = esc_url_raw(trim((string) $feed_url));
            if ($feed_url === '') {
                return array();
            }
            if (class_exists('Content_Rank_Global_Filters') && Content_Rank_Global_Filters::url_is_blocked($feed_url)) {
                return new WP_Error('content_rank_source_blacklisted', 'A fonte do feed esta na blacklist global.');
            }

            $cache_hash = md5($feed_url);
            delete_site_transient('feed_' . $cache_hash);
            delete_site_transient('feed_mod_' . $cache_hash);

            $cache_lifetime_filter = static function ($lifetime, $url) use ($feed_url) {
                if ((string) $url === $feed_url) {
                    return 5 * MINUTE_IN_SECONDS;
                }

                return $lifetime;
            };

            add_filter('wp_feed_cache_transient_lifetime', $cache_lifetime_filter, 999, 2);
            try {
                $feed = fetch_feed($feed_url);
            } finally {
                remove_filter('wp_feed_cache_transient_lifetime', $cache_lifetime_filter, 999);
            }
            if (is_wp_error($feed)) {
                $feed_error_data = $feed->get_error_data();
                $feed_status_code = is_array($feed_error_data) && isset($feed_error_data['status_code']) ? intval($feed_error_data['status_code']) : 0;
                if ($feed_status_code === 0 && is_array($feed_error_data) && isset($feed_error_data['response']['code'])) {
                    $feed_status_code = intval($feed_error_data['response']['code']);
                }
                if ($feed_status_code > 0 && class_exists('Content_Rank_Global_Filters')) {
                    Content_Rank_Global_Filters::add_source_from_http_status($feed_url, $feed_status_code);
                }
                return $feed;
            }

            $limit = max(1, intval($limit));
            $items = $feed->get_items(0, $limit);
            $results = array();

            foreach ($items as $item) {
                $guid = method_exists($item, 'get_id') ? (string) $item->get_id() : '';
                if ($guid === '') {
                    $guid = method_exists($item, 'get_permalink') ? (string) $item->get_permalink() : '';
                }
                $guid = self::resolve_google_alerts_redirect_url($guid);
                if ($guid === '') {
                    $guid = md5((string) $item->get_title() . '|' . (string) $item->get_date('c'));
                }

                $excerpt_html = method_exists($item, 'get_description') ? (string) $item->get_description() : '';
                $content_html = method_exists($item, 'get_content') ? (string) $item->get_content() : '';
                $permalink = method_exists($item, 'get_permalink') ? (string) $item->get_permalink() : '';
                $permalink = self::resolve_google_alerts_redirect_url($permalink);
                $feed_title = method_exists($feed, 'get_title') ? (string) $feed->get_title() : '';
                $date_raw = '';
                if (method_exists($item, 'get_date')) {
                    $date_raw = trim((string) $item->get_date('r'));
                }
                if ($date_raw === '' && method_exists($item, 'get_timestamp')) {
                    $timestamp = intval($item->get_timestamp());
                    if ($timestamp > 0) {
                        $date_raw = gmdate('r', $timestamp);
                    }
                }
                $date_label = $date_raw;
                if (method_exists($item, 'get_timestamp')) {
                    $timestamp = intval($item->get_timestamp());
                    if ($timestamp > 0) {
                        $date_label = wp_date('d/m/Y H:i', $timestamp, function_exists('wp_timezone') ? wp_timezone() : null);
                    }
                } elseif ($date_raw !== '') {
                    $timestamp = strtotime($date_raw);
                    if ($timestamp !== false) {
                        $date_label = wp_date('d/m/Y H:i', $timestamp, function_exists('wp_timezone') ? wp_timezone() : null);
                    }
                }
                $categories = array();
                if (method_exists($item, 'get_categories')) {
                    $item_categories = $item->get_categories();
                    if (is_array($item_categories)) {
                        foreach ($item_categories as $cat) {
                            if (is_object($cat) && method_exists($cat, 'get_label')) {
                                $label = (string) $cat->get_label();
                                if ($label !== '') {
                                    $categories[] = $label;
                                }
                            }
                        }
                    }
                }

                $media = $include_media ? self::resolve_item_media($item, $permalink, $excerpt_html, $content_html, $video_selector_class, $image_selector_class, $link_selector_class) : array();

                $results[] = array(
                    'guid' => $guid,
                    'title' => (string) $item->get_title(),
                    'permalink' => $permalink,
                    'excerpt_html' => $excerpt_html,
                    'content_html' => $content_html,
                    'excerpt' => self::clean_source_text($excerpt_html),
                    'content' => self::clean_source_text($content_html),
                    'feed_title' => $feed_title,
                    'date' => $date_raw !== '' ? $date_raw : (string) $item->get_date('c'),
                    'date_label' => $date_label,
                    'categories' => array_values(array_unique($categories)),
                    'source_image_url' => isset($media['image_url']) ? $media['image_url'] : '',
                    'source_link_url' => isset($media['link_url']) ? $media['link_url'] : '',
                    'source_link_text' => isset($media['link_text']) ? $media['link_text'] : '',
                    'source_video_url' => isset($media['video_url']) ? $media['video_url'] : '',
                    'source_video_embed_html' => isset($media['video_embed_html']) ? $media['video_embed_html'] : '',
                    'source_video_source' => isset($media['video_source']) ? $media['video_source'] : '',
                );
            }

            return $results;
        }

        public static function get_rss_items_for_generator($generator, $limit = 10, $include_media = false, $video_selector_class = '', $image_selector_class = '', $link_selector_class = '', $source_context_filters = array())
        {
            $feed_url = !empty($generator['feed_url']) ? (string) $generator['feed_url'] : '';
            if ($feed_url === '') {
                return array();
            }

            $limit = max(1, intval($limit));
            $fetch_limit = min(500, max(200, $limit * 10));
            $items = self::get_rss_items($feed_url, $fetch_limit, $include_media, $video_selector_class, $image_selector_class, $link_selector_class);
            if (is_wp_error($items)) {
                return $items;
            }

            $available_items = array();
            $generator_id = !empty($generator['id']) ? intval($generator['id']) : 0;
            foreach ($items as $item) {
                if ($generator_id > 0 && self::is_item_processed($generator_id, $item['guid'])) {
                    continue;
                }
                if (class_exists('Content_Rank_Global_Filters') && Content_Rank_Global_Filters::item_is_blocked($item)) {
                    continue;
                }
                if (!empty($source_context_filters) && !Content_Rank_Generator_Helper::source_context_item_matches_filters($item, $source_context_filters)) {
                    continue;
                }
                $available_items[] = $item;
                if (count($available_items) >= $limit) {
                    break;
                }
            }

            return $available_items;
        }

        public static function bulk_row_data_summary($row_data)
        {
            if (!is_array($row_data)) {
                return '';
            }

            $preferred = array();
            $fallback = array();
            foreach ($row_data as $label => $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }

                $line = $label . ': ' . $value;
                $normalized_label = remove_accents((string) $label);
                if (preg_match('/(keyword|palavra.?chave|url|slug|title|título|content|conteu|excerpt|descricao|meta|tags?|author|autor)/i', $normalized_label)) {
                    $preferred[] = $line;
                } else {
                    $fallback[] = $line;
                }
            }

            $parts = !empty($preferred) ? $preferred : array_slice($fallback, 0, 5);
            $summary = implode("\n", $parts);
            if (strlen($summary) > 6000) {
                $summary = substr($summary, 0, 6000);
            }

            return $summary;
        }

        public static function build_keyword_list_item_from_row($list, $row, $include_media = true, $video_selector_class = '', $image_selector_class = '', $link_selector_class = '', $source_context_filters = array(), $content_selector = '')
        {
            $row_data = array();
            if (is_object($row) && isset($row->row_data)) {
                $row_data = json_decode($row->row_data, true);
            } elseif (is_array($row) && isset($row['row_data'])) {
                $row_data = json_decode($row['row_data'], true);
            }
            if (!is_array($row_data)) {
                $row_data = array();
            }

            $row_id = is_object($row) ? intval($row->id) : intval(isset($row['id']) ? $row['id'] : 0);
            $keyword = is_object($row) ? (string) $row->keyword : (string) (isset($row['keyword']) ? $row['keyword'] : '');
            $source_title = is_object($row) ? (string) $row->source_title : (string) (isset($row['source_title']) ? $row['source_title'] : '');
            $source_url = is_object($row) ? (string) $row->source_url : (string) (isset($row['source_url']) ? $row['source_url'] : '');
            $final_slug = is_object($row) ? (string) $row->final_slug : (string) (isset($row['final_slug']) ? $row['final_slug'] : '');
            $row_timestamp = self::bulk_extract_row_timestamp($row_data);
            $list_id = is_array($list) ? intval(isset($list['id']) ? $list['id'] : 0) : 0;
            $is_manual_keyword_list = is_array($list) && sanitize_key((string) (isset($list['file_type']) ? $list['file_type'] : '')) === 'keyword_list';
            $duplicate_count = $is_manual_keyword_list ? self::get_keyword_list_keyword_count($list_id, $keyword) : 0;

            $item = array(
                'guid' => 'listrow:' . $row_id,
                'title' => $source_title !== '' ? $source_title : $keyword,
                'keyword' => $keyword,
                'source_title' => $source_title,
                'source_url' => $source_url,
                'final_slug' => $final_slug,
                'row_data' => $row_data,
                'feed_title' => is_array($list) && !empty($list['list_name']) ? $list['list_name'] : '',
                'permalink' => $source_url,
                'excerpt' => '',
                'content' => '',
                'date' => $row_timestamp,
                'categories' => array(),
                'source_image_url' => '',
                'source_link_url' => '',
                'source_link_text' => '',
                'source_video_url' => '',
                'source_image_selector_class' => $image_selector_class,
                'source_link_selector_class' => $link_selector_class,
                'keyword_list_id' => $list_id,
                'keyword_list_row_id' => $row_id,
                'keyword_list_duplicate_count' => $duplicate_count,
                'keyword_list_repeated_intent' => $is_manual_keyword_list && $duplicate_count > 1 ? 1 : 0,
            );

            if ($include_media && $source_url !== '') {
                $page_context = self::extract_page_context($source_url, $video_selector_class, $image_selector_class, $link_selector_class, $source_context_filters, $content_selector);
                if ($item['title'] === '' && !empty($page_context['title'])) {
                    $item['title'] = $page_context['title'];
                }
                if (!empty($page_context['title'])) {
                    $item['source_page_title'] = $page_context['title'];
                }
                if (!empty($page_context['html'])) {
                    $item['source_page_html'] = $page_context['html'];
                }
                if (!empty($page_context['excerpt'])) {
                    $item['excerpt'] = $page_context['excerpt'];
                    $item['source_page_excerpt'] = $page_context['excerpt'];
                }
                if (!empty($page_context['content'])) {
                    $item['content'] = $page_context['content'];
                    $item['source_page_content'] = $page_context['content'];
                }
                if (!empty($page_context['content_html'])) {
                    $item['source_page_content_html'] = $page_context['content_html'];
                } elseif (!empty($page_context['html'])) {
                    $item['source_page_content_html'] = $page_context['html'];
                }
                if (!empty($page_context['outline_text'])) {
                    $item['source_page_outline'] = $page_context['outline_text'];
                }
                if (!empty($page_context['outline']) && is_array($page_context['outline'])) {
                    $item['source_page_outline_sections'] = $page_context['outline'];
                }
                if (!empty($page_context['video_sections']) && is_array($page_context['video_sections'])) {
                    $item['source_page_video_sections'] = $page_context['video_sections'];
                }
                if (!empty($page_context['excerpt']) || !empty($page_context['content']) || !empty($page_context['title']) || !empty($page_context['outline_text'])) {
                    $item['source_context_enriched'] = 1;
                }

                $media = Content_Rank_Generator_Helper::extract_media_from_source_page($source_url, $video_selector_class, $image_selector_class, $link_selector_class);
                if (!empty($media['image_url'])) {
                    $item['source_image_url'] = $media['image_url'];
                }
                if (!empty($media['link_url'])) {
                    $item['source_link_url'] = $media['link_url'];
                }
                if (!empty($media['link_text'])) {
                    $item['source_link_text'] = $media['link_text'];
                }
                if (!empty($media['video_url'])) {
                    $item['source_video_url'] = $media['video_url'];
                }
            }

            if (empty($item['source_page_video_sections']) && !empty($item['source_page_html'])) {
                $item['source_page_video_sections'] = Content_Rank_Generator_Helper::extract_video_sections_from_raw_source_html(
                    $item['source_page_html'],
                    $source_url,
                    $content_selector
                );
            }

            if ($item['excerpt'] === '') {
                $item['excerpt'] = !empty($item['source_title']) ? $item['source_title'] : self::bulk_row_data_summary($row_data);
            }
            if ($item['content'] === '') {
                $item['content'] = self::bulk_row_data_summary($row_data);
            }

            return $item;
        }

        public static function get_keyword_list_keyword_count($list_id, $keyword)
        {
            global $wpdb;

            $list_id = intval($list_id);
            $keyword = trim((string) $keyword);
            if ($list_id <= 0 || $keyword === '') {
                return 0;
            }

            $tables = self::bulk_tables();
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables['rows']} WHERE list_id = %d AND LOWER(keyword) = LOWER(%s)",
                $list_id,
                $keyword
            ));
        }

        public static function get_generated_keyword_post_titles($list_id, $keyword, $exclude_row_id = 0, $limit = 25)
        {
            global $wpdb;

            $list_id = intval($list_id);
            $keyword = trim((string) $keyword);
            $exclude_row_id = max(0, intval($exclude_row_id));
            $limit = max(1, min(50, intval($limit)));
            if ($list_id <= 0 || $keyword === '') {
                return array();
            }

            $tables = self::bulk_tables();
            $posts_table = $wpdb->posts;
            $exclude_sql = $exclude_row_id > 0 ? $wpdb->prepare(' AND r.id <> %d', $exclude_row_id) : '';
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT p.post_title
                 FROM {$tables['rows']} r
                 INNER JOIN {$posts_table} p ON p.ID = r.post_id
                 WHERE r.list_id = %d
                   AND r.row_status = 'generated'
                   AND r.post_id > 0
                   AND p.post_status <> 'trash'
                   AND LOWER(r.keyword) = LOWER(%s)" . $exclude_sql . "
                 ORDER BY p.post_date DESC, p.ID DESC
                 LIMIT %d",
                $list_id,
                $keyword,
                $limit
            ));

            $titles = array();
            foreach ($rows as $title) {
                $title = trim(wp_strip_all_tags((string) $title));
                if ($title !== '') {
                    $titles[] = $title;
                }
            }

            return $titles;
        }

        public static function get_keyword_list_items($list_id, $limit = 10, $include_media = false, $video_selector_class = '')
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $list = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['lists']} WHERE id = %d", intval($list_id)), ARRAY_A);
            if (!$list) {
                return new WP_Error('content_rank_keyword_list_missing', 'Lista não encontrada');
            }

            $limit = max(1, intval($limit));
            $fetch_limit = min(500, max($limit, $limit * 10));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$tables['rows']} WHERE list_id = %d AND row_status = 'pending' ORDER BY `row_number` ASC LIMIT %d",
                intval($list_id),
                $fetch_limit
            ));

            $results = array();
            foreach ($rows as $row) {
                $item = self::build_keyword_list_item_from_row($list, $row, $include_media, $video_selector_class);
                if (class_exists('Content_Rank_Global_Filters') && Content_Rank_Global_Filters::item_is_blocked($item)) {
                    continue;
                }
                $results[] = $item;
                if (count($results) >= $limit) {
                    break;
                }
            }

            return $results;
        }

        public static function get_keyword_list_items_for_generator($generator_id, $list_id, $limit = 10, $include_media = false, $video_selector_class = '', $image_selector_class = '', $link_selector_class = '', $source_context_filters = array())
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $list = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['lists']} WHERE id = %d", intval($list_id)), ARRAY_A);
            if (!$list) {
                return new WP_Error('content_rank_keyword_list_missing', 'Lista não encontrada');
            }

            $generator_record = $generator_id > 0 ? self::get_generator($generator_id) : array();
            $content_selector = !empty($generator_record['content_selector']) ? sanitize_text_field((string) $generator_record['content_selector']) : '';
            $limit = max(1, intval($limit));
            $generator_id = intval($generator_id);
            $fetch_limit = min(500, max($limit, $limit * 10));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT r.* FROM {$tables['rows']} r
                 WHERE r.list_id = %d
                   AND r.row_status = 'pending'
                   AND NOT EXISTS (
                       SELECT 1 FROM " . self::$table_items . " i
                       WHERE i.generator_id = %d
                         AND i.item_guid = CONCAT('listrow:', r.id)
                   )
                 ORDER BY r.`row_number` ASC
                 LIMIT %d",
                intval($list_id),
                $generator_id,
                $fetch_limit
            ));

            $results = array();
            foreach ($rows as $row) {
                $item = self::build_keyword_list_item_from_row($list, $row, $include_media, $video_selector_class, $image_selector_class, $link_selector_class, $source_context_filters, $content_selector);
                if (class_exists('Content_Rank_Global_Filters') && Content_Rank_Global_Filters::item_is_blocked($item)) {
                    continue;
                }
                if (!empty($source_context_filters) && !Content_Rank_Generator_Helper::source_context_item_matches_filters($item, $source_context_filters)) {
                    continue;
                }
                $results[] = $item;
                if (count($results) >= $limit) {
                    break;
                }
            }

            return $results;
        }

        public static function get_keyword_list_row_by_guid($list_id, $item_guid)
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $item_guid = trim((string) $item_guid);
            if ($item_guid === '') {
                return null;
            }

            if (strpos($item_guid, 'listrow:') === 0) {
                $row_id = intval(substr($item_guid, 8));
                if ($row_id > 0) {
                    return $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$tables['rows']} WHERE list_id = %d AND id = %d LIMIT 1",
                        intval($list_id),
                        $row_id
                    ));
                }
            }

            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$tables['rows']} WHERE list_id = %d AND final_slug = %s LIMIT 1",
                intval($list_id),
                $item_guid
            ));
        }

        public static function update_keyword_list_row_status_from_item($list_id, $item_guid, $status, $post_id = 0, $error_message = '')
        {
            global $wpdb;
            $list_id = intval($list_id);
            $item_guid = trim((string) $item_guid);
            $status = sanitize_key((string) $status);
            $post_id = max(0, intval($post_id));

            if ($list_id <= 0 || strpos($item_guid, 'listrow:') !== 0 || !in_array($status, array('processing', 'generated', 'failed'), true)) {
                return false;
            }

            $row_id = intval(substr($item_guid, 8));
            if ($row_id <= 0) {
                return false;
            }

            $tables = self::bulk_tables();
            if ($post_id <= 0 && $status === 'failed') {
                $post_id = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$tables['rows']} WHERE id = %d AND list_id = %d LIMIT 1",
                    $row_id,
                    $list_id
                )));
            }
            $data = array(
                'row_status' => $status,
                'post_id' => $post_id,
                'error_message' => sanitize_text_field((string) $error_message),
                'updated_at' => current_time('mysql'),
            );
            $formats = array('%s', '%d', '%s', '%s');
            if ($status === 'generated') {
                $data['processed_at'] = current_time('mysql');
                $formats[] = '%s';
            }

            return false !== $wpdb->update(
                $tables['rows'],
                $data,
                array('id' => $row_id, 'list_id' => $list_id),
                $formats,
                array('%d', '%d')
            );
        }



        public function rest_get_generator_items(WP_REST_Request $request)
        {
            $generator_id = intval($request->get_param('id'));
            $limit = isset($request['limit']) ? intval($request['limit']) : 20;
            $limit = max(1, min(40, $limit));
            $generator = self::get_generator($generator_id);

            if (!$generator) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Gerador não encontrado.',
                ), 404);
            }

            if (!empty($generator['generation_mode']) && self::normalize_generation_mode((string) $generator['generation_mode']) === 'satellite') {
                $items = self::get_satellite_source_posts_for_generator($generator, $limit);
                $available_items = array();
                foreach ($items as $item) {
                    if (self::is_item_processed($generator_id, $item['guid'])) {
                        continue;
                    }
                    $available_items[] = $item;
                }

                return rest_ensure_response(array(
                    'success' => true,
                    'generator' => array(
                        'id' => intval($generator['id']),
                        'name' => $generator['name'],
                        'generation_mode' => 'satellite',
                        'post_type' => !empty($generator['post_type']) ? $generator['post_type'] : 'post',
                        'post_status' => !empty($generator['post_status']) ? $generator['post_status'] : 'draft',
                    ),
                    'available_count' => count($available_items),
                    'fetched_count' => count($items),
                    'items' => $available_items,
                ));
            }

            if (!empty($generator['source_type']) && self::source_type_uses_keyword_list($generator['source_type'])) {
                if (empty($generator['list_id'])) {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Este gerador não possui uma lista vinculada.',
                    ), 400);
                }

                $items = self::get_keyword_list_items_for_generator(
                    $generator_id,
                    intval($generator['list_id']),
                    $limit,
                    false,
                    !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '',
                    !empty($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '',
                    !empty($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '',
                    self::get_generator_source_context_filters($generator)
                );
                if (is_wp_error($items)) {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => $items->get_error_message(),
                    ), 400);
                }

                $available_items = array();
                foreach ($items as $item) {
                    if (self::is_item_processed($generator_id, $item['guid'])) {
                        continue;
                    }
                    $available_items[] = $item;
                }

                $list = self::get_keyword_list(intval($generator['list_id']));
                $list_counts = self::bulk_get_list_counts(intval($generator['list_id']));

                return rest_ensure_response(array(
                    'success' => true,
                    'generator' => array(
                        'id' => intval($generator['id']),
                        'name' => $generator['name'],
                        'source_type' => !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'keyword_list',
                        'list_id' => intval($generator['list_id']),
                        'keyword_list_mode' => !empty($generator['keyword_list_mode']) ? $generator['keyword_list_mode'] : self::get_default_keyword_list_mode(),
                        'list_name' => $list ? $list['list_name'] : '',
                    ),
                    'available_count' => count($available_items),
                    'fetched_count' => count($items),
                    'list_counts' => $list_counts,
                    'items' => $available_items,
                ));
            }

            $items = self::get_rss_items_for_generator(
                $generator,
                $limit,
                false,
                !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '',
                !empty($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '',
                !empty($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '',
                self::get_generator_source_context_filters($generator)
            );
            if (is_wp_error($items)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => $items->get_error_message(),
                ), 400);
            }

            $available_items = array();
            foreach ($items as $item) {
                if (self::is_item_processed($generator_id, $item['guid'])) {
                    continue;
                }
                $available_items[] = array(
                    'guid' => $item['guid'],
                    'title' => $item['title'],
                    'permalink' => $item['permalink'],
                    'excerpt' => $item['excerpt'],
                    'content' => $item['content'],
                    'feed_title' => isset($item['feed_title']) ? (string) $item['feed_title'] : '',
                    'date' => $item['date'],
                    'categories' => $item['categories'],
                    'source_image_url' => !empty($item['source_image_url']) ? $item['source_image_url'] : '',
                    'source_video_url' => !empty($item['source_video_url']) ? $item['source_video_url'] : '',
                );
            }

            return rest_ensure_response(array(
                'success' => true,
                'generator' => array(
                    'id' => intval($generator['id']),
                    'name' => $generator['name'],
                    'feed_url' => $generator['feed_url'],
                    'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
                    'keyword_list_mode' => !empty($generator['keyword_list_mode']) ? $generator['keyword_list_mode'] : self::get_default_keyword_list_mode(),
                ),
                'available_count' => count($available_items),
                'fetched_count' => count($items),
                'items' => $available_items,
            ));
        }




        public static function rest_get_keyword_list(WP_REST_Request $request)
        {
            global $wpdb;
            $list_id = intval($request->get_param('id'));
            if (!$list_id) {
                return new WP_Error('content_rank_keyword_list_invalid', 'Lista invalida', array('status' => 400));
            }

            $tables = self::bulk_tables();
            $list = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['lists']} WHERE id = %d", $list_id), ARRAY_A);
            if (!$list) {
                return new WP_Error('content_rank_keyword_list_missing', 'Lista não encontrada', array('status' => 404));
            }

            self::bulk_refresh_list_counts($list_id);

            $preview_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$tables['rows']} WHERE list_id = %d ORDER BY `row_number` ASC LIMIT 50",
                $list_id
            ));

            $rows = array();
            foreach ($preview_rows as $row) {
                $row_data = json_decode($row->row_data, true);
                if (!is_array($row_data)) {
                    $row_data = array();
                }

                $rows[] = array(
                    'id' => intval($row->id),
                    'row_number' => intval($row->row_number),
                    'row_data' => $row_data,
                    'keyword' => $row->keyword,
                    'source_title' => isset($row->source_title) ? $row->source_title : '',
                    'source_url' => $row->source_url,
                    'final_slug' => $row->final_slug,
                    'slug_extension' => $row->slug_extension,
                    'slug_is_valid' => intval($row->slug_is_valid),
                    'row_status' => $row->row_status,
                    'post_id' => !empty($row->post_id) ? intval($row->post_id) : 0,
                    'error_message' => $row->error_message,
                    'processed_at' => $row->processed_at,
                );
            }

            $counts = self::bulk_get_list_counts($list_id);

            return rest_ensure_response(array(
                'success' => true,
                'list' => array(
                    'id' => intval($list['id']),
                    'list_name' => $list['list_name'],
                    'original_filename' => $list['original_filename'],
                    'file_type' => $list['file_type'],
                    'headers' => !empty($list['headers_json']) ? json_decode($list['headers_json'], true) : array(),
                    'column_map' => !empty($list['column_map_json']) ? json_decode($list['column_map_json'], true) : array(),
                    'counts' => $counts,
                    'status' => $list['status'],
                    'created_at' => $list['created_at'],
                    'updated_at' => $list['updated_at'],
                ),
                'rows' => $rows,
            ));
        }



        public function rest_update_keyword_list_columns(WP_REST_Request $request)
        {
            global $wpdb;
            $list_id = intval($request->get_param('id'));
            if (!$list_id) {
                return new WP_Error('content_rank_keyword_list_invalid', 'Lista invalida', array('status' => 400));
            }

            $payload = $request->get_json_params();
            if (!is_array($payload)) {
                $payload = array();
            }

            $column_map = isset($payload['column_map']) ? $payload['column_map'] : array();
            if (is_string($column_map) && trim($column_map) !== '') {
                $decoded = json_decode(wp_unslash($column_map), true);
                if (is_array($decoded)) {
                    $column_map = $decoded;
                }
            }
            if (!is_array($column_map)) {
                $column_map = array();
            }

            $wpdb->update(
                self::$table_lists,
                array(
                    'column_map_json' => wp_json_encode($column_map),
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => $list_id),
                array('%s', '%s'),
                array('%d')
            );

            $rebuild = self::bulk_rebuild_keyword_list_rows($list_id, $column_map);
            self::bulk_refresh_list_counts($list_id);

            return rest_ensure_response(array(
                'success' => true,
                'message' => 'Mapa de colunas atualizado com sucesso',
                'column_map' => $column_map,
                'rebuild' => $rebuild,
            ));
        }



        public function rest_delete_keyword_list(WP_REST_Request $request)
        {
            global $wpdb;
            $list_id = intval($request->get_param('id'));
            if (!$list_id) {
                return new WP_Error('content_rank_keyword_list_invalid', 'Lista invalida', array('status' => 400));
            }

            $tables = self::bulk_tables();
            $list = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['lists']} WHERE id = %d", $list_id));
            if (!$list) {
                return new WP_Error('content_rank_keyword_list_missing', 'Lista não encontrada', array('status' => 404));
            }

            $wpdb->delete($tables['rows'], array('list_id' => $list_id), array('%d'));
            $wpdb->delete($tables['lists'], array('id' => $list_id), array('%d'));

            if (!empty($list->file_path) && file_exists($list->file_path)) {
                wp_delete_file($list->file_path);
            }

            return rest_ensure_response(array(
                'success' => true,
                'message' => 'Lista removida com sucesso',
            ));
        }

        public static function run_generator_item($generator, $item_guid)
        {
            $item_guid = trim((string) $item_guid);
            if ($item_guid === '') {
                return new WP_Error('content_rank_missing_item', 'Nenhum item foi selecionado.');
            }

            $selected_item = null;
            if (!empty($generator['source_type']) && self::source_type_uses_keyword_list($generator['source_type'])) {
                if (empty($generator['list_id'])) {
                    return new WP_Error('content_rank_missing_list', 'Este gerador não possui uma lista vinculada.');
                }

                $row = self::get_keyword_list_row_by_guid(intval($generator['list_id']), $item_guid);
                if (!$row) {
                    return new WP_Error('content_rank_item_not_found', 'O item selecionado não foi encontrado na lista.');
                }
                $selected_item = self::build_keyword_list_item_from_row(
                    self::get_keyword_list(intval($generator['list_id'])),
                    $row,
                    false,
                    !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '',
                    !empty($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '',
                    !empty($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '',
                    self::get_generator_source_context_filters($generator),
                    !empty($generator['content_selector']) ? sanitize_text_field((string) $generator['content_selector']) : ''
                );
                if (!Content_Rank_Generator_Helper::source_context_item_matches_filters($selected_item, self::get_generator_source_context_filters($generator))) {
                    return new WP_Error('content_rank_item_filtered', 'O item selecionado foi bloqueado pelos filtros da fonte.');
                }
            } else {
                $items = self::get_rss_items_for_generator(
                    $generator,
                    max(30, intval($generator['posts_per_run']) * 5),
                    false,
                    !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '',
                    !empty($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '',
                    !empty($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '',
                    self::get_generator_source_context_filters($generator)
                );
                if (is_wp_error($items)) {
                    return $items;
                }

                foreach ($items as $item) {
                    if (isset($item['guid']) && (string) $item['guid'] === $item_guid) {
                        $selected_item = $item;
                        break;
                    }
                }

                if (!$selected_item) {
                    return new WP_Error('content_rank_item_not_found', 'O item selecionado não foi encontrado no feed.');
                }
            }

            $processed_record = self::get_item_processed_record($generator['id'], $selected_item['guid']);
            if ($processed_record) {
                $processed_post_id = !empty($processed_record['post_id']) ? intval($processed_record['post_id']) : 0;
                if ($processed_post_id > 0 && get_post($processed_post_id)) {
                    $post_view_link = self::get_post_view_link($processed_post_id);
                    $post_edit_link = self::get_post_edit_link($processed_post_id);

                    return array(
                        'post_id' => $processed_post_id,
                        'item_guid' => $selected_item['guid'],
                        'item_title' => $selected_item['title'],
                        'view_link' => $post_view_link ? $post_view_link : '',
                        'edit_link' => $post_edit_link ? $post_edit_link : '',
                        'permalink' => $post_view_link ? $post_view_link : '',
                        'reused_existing_post' => 1,
                    );
                }
                return new WP_Error('content_rank_item_locked', 'Esse item j? est? em processamento.');
            }

            if (!self::claim_item_processing_slot($generator['id'], $selected_item)) {
                return new WP_Error('content_rank_item_locked', 'Esse item j? est? em processamento.');
            }

            $result = self::queue_staged_generation($generator, $selected_item);
            if (is_wp_error($result)) {
                self::mark_item_failed($generator['id'], $selected_item, $result->get_error_code(), $result->get_error_message());
                if (!empty($generator['list_id']) && !empty($selected_item['guid'])) {
                    self::update_keyword_list_row_status_from_item(
                        intval($generator['list_id']),
                        $selected_item['guid'],
                        'failed',
                        0,
                        $result->get_error_message()
                    );
                }
                return $result;
            }

            if (is_array($result) && !empty($result['queued'])) {
                $result['view_link'] = self::get_post_view_link(intval($result['post_id']));
                $result['edit_link'] = self::get_post_edit_link(intval($result['post_id']));
                $result['permalink'] = !empty($result['view_link']) ? $result['view_link'] : '';
                self::update_next_run_after_attempt($generator);
                return $result;
            }

            if (!empty($generator['list_id']) && !empty($selected_item['guid'])) {
                self::update_keyword_list_row_status_from_item(
                    intval($generator['list_id']),
                    $selected_item['guid'],
                    'generated',
                    intval($result)
                );
            }

            self::update_next_run_after_attempt($generator);
            $post_view_link = self::get_post_view_link(intval($result));
            $post_edit_link = self::get_post_edit_link(intval($result));

            return array(
                'post_id' => intval($result),
                'item_guid' => $selected_item['guid'],
                'item_title' => $selected_item['title'],
                'view_link' => $post_view_link ? $post_view_link : '',
                'edit_link' => $post_edit_link ? $post_edit_link : '',
                'permalink' => $post_view_link ? $post_view_link : '',
            );
        }

        public static function is_item_processed($generator_id, $guid)
        {
            $record = self::get_item_processed_record($generator_id, $guid);
            if (!$record) {
                return false;
            }

            $item_status = !empty($record['item_status']) ? sanitize_key((string) $record['item_status']) : '';
            if ($item_status === 'processing' || $item_status === 'processed' || $item_status === 'failed') {
                return true;
            }

            $post_id = !empty($record['post_id']) ? intval($record['post_id']) : 0;
            if ($post_id <= 0) {
                return true;
            }

            if (!get_post($post_id)) {
                self::delete_item_processed_by_guid($generator_id, $guid);
                return false;
            }

            return true;
        }

        public static function get_item_processed_record($generator_id, $guid)
        {
            global $wpdb;
            $generator_id = intval($generator_id);
            $guid = trim((string) $guid);
            if ($generator_id <= 0 || $guid === '') {
                return null;
            }

            $record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . self::$table_items . " WHERE generator_id = %d AND item_guid = %s LIMIT 1",
                $generator_id,
                $guid
            ), ARRAY_A);

            return is_array($record) ? $record : null;
        }

        public static function mark_item_processed($generator_id, $item, $post_id)
        {
            global $wpdb;
            $generator_id = intval($generator_id);
            $item_guid = isset($item['guid']) ? (string) $item['guid'] : '';
            $item_permalink = isset($item['permalink']) ? (string) $item['permalink'] : '';
            $item_title = isset($item['title']) ? (string) $item['title'] : '';
            $item_hash = md5($item_guid . '|' . $item_permalink);
            $title_embedding_json = '';
            if (!empty($item['title_embedding_json'])) {
                if (is_array($item['title_embedding_json'])) {
                    $title_embedding_json = wp_json_encode(array_values(array_map('floatval', $item['title_embedding_json'])));
                } else {
                    $title_embedding_json = trim((string) $item['title_embedding_json']);
                }
            }
            $title_embedding_model = !empty($item['title_embedding_model']) ? sanitize_text_field((string) $item['title_embedding_model']) : '';
            $semantic_duplicate_post_id = !empty($item['semantic_duplicate_post_id']) ? intval($item['semantic_duplicate_post_id']) : 0;
            $semantic_duplicate_score = isset($item['semantic_duplicate_score']) ? max(0.0, min(1.0, floatval($item['semantic_duplicate_score']))) : 0.0;
            $semantic_duplicate_method = !empty($item['semantic_duplicate_method']) ? sanitize_key((string) $item['semantic_duplicate_method']) : '';

            if ($generator_id <= 0 || $item_guid === '') {
                return 0;
            }

            return $wpdb->replace(
                self::$table_items,
                array(
                    'generator_id' => $generator_id,
                    'item_guid' => $item_guid,
                    'item_permalink' => $item_permalink,
                    'item_title' => $item_title,
                    'post_id' => intval($post_id),
                    'item_status' => 'processed',
                    'item_hash' => $item_hash,
                    'title_embedding_model' => $title_embedding_model,
                    'title_embedding_json' => $title_embedding_json,
                    'semantic_duplicate_post_id' => $semantic_duplicate_post_id,
                    'semantic_duplicate_score' => $semantic_duplicate_score,
                    'semantic_duplicate_method' => $semantic_duplicate_method,
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%s')
            );
        }

        public static function mark_item_failed($generator_id, $item, $error_code = '', $error_message = '')
        {
            global $wpdb;
            $generator_id = intval($generator_id);
            $item_guid = isset($item['guid']) ? (string) $item['guid'] : '';
            $item_permalink = isset($item['permalink']) ? (string) $item['permalink'] : '';
            $item_title = isset($item['title']) ? (string) $item['title'] : '';
            $item_hash = md5($item_guid . '|' . $item_permalink);

            if ($generator_id <= 0 || $item_guid === '') {
                return 0;
            }

            // Preserve the draft created before a later pipeline stage failed.
            $existing_post_id = intval($wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM " . self::$table_items . " WHERE generator_id = %d AND item_guid = %s LIMIT 1",
                $generator_id,
                $item_guid
            )));

            return $wpdb->replace(
                self::$table_items,
                array(
                    'generator_id' => $generator_id,
                    'item_guid' => $item_guid,
                    'item_permalink' => $item_permalink,
                    'item_title' => $item_title,
                    'post_id' => $existing_post_id,
                    'item_status' => 'failed',
                    'item_hash' => $item_hash,
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
            );
        }

        /**
         * Persist the placeholder post as soon as the staged pipeline creates it.
         */
        public static function link_processing_item_to_post($generator_id, $item, $post_id)
        {
            global $wpdb;
            $generator_id = intval($generator_id);
            $item_guid = isset($item['guid']) ? trim((string) $item['guid']) : '';
            $post_id = intval($post_id);

            if ($generator_id <= 0 || $item_guid === '' || $post_id <= 0) {
                return false;
            }

            return false !== $wpdb->update(
                self::$table_items,
                array(
                    'post_id' => $post_id,
                    'item_status' => 'processing',
                ),
                array(
                    'generator_id' => $generator_id,
                    'item_guid' => $item_guid,
                ),
                array('%d', '%s'),
                array('%d', '%s')
            );
        }

        public static function claim_item_processing_slot($generator_id, $item)
        {
            global $wpdb;
            $generator_id = intval($generator_id);
            $item_guid = isset($item['guid']) ? trim((string) $item['guid']) : '';
            $item_permalink = isset($item['permalink']) ? (string) $item['permalink'] : '';
            $item_title = isset($item['title']) ? (string) $item['title'] : '';
            $item_hash = md5($item_guid . '|' . $item_permalink);

            if ($generator_id <= 0 || $item_guid === '') {
                return false;
            }

            $result = $wpdb->insert(
                self::$table_items,
                array(
                    'generator_id' => $generator_id,
                    'item_guid' => $item_guid,
                    'item_permalink' => $item_permalink,
                    'item_title' => $item_title,
                    'post_id' => 0,
                    'item_status' => 'processing',
                    'item_hash' => $item_hash,
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
            );

            if ($result !== false) {
                return true;
            }

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM " . self::$table_items . " WHERE generator_id = %d AND item_guid = %s LIMIT 1",
                $generator_id,
                $item_guid
            ));

            return empty($existing);
        }

        public static function delete_item_processed_by_post_id($post_id)
        {
            global $wpdb;
            $post_id = intval($post_id);
            if ($post_id <= 0) {
                return 0;
            }

            return $wpdb->delete(self::$table_items, array('post_id' => $post_id), array('%d'));
        }

        public static function delete_item_processed_by_guid($generator_id, $item_guid)
        {
            global $wpdb;
            $generator_id = intval($generator_id);
            $item_guid = trim((string) $item_guid);
            if ($generator_id <= 0 || $item_guid === '') {
                return 0;
            }

            return $wpdb->delete(
                self::$table_items,
                array(
                    'generator_id' => $generator_id,
                    'item_guid' => $item_guid,
                ),
                array('%d', '%s')
            );
        }

        public function handle_generated_post_deleted($post_id)
        {
            $post_id = intval($post_id);
            if ($post_id <= 0) {
                return;
            }

            if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
                return;
            }

            $generator_id = intval(get_post_meta($post_id, '_content_rank_generator_id', true));
            if ($generator_id <= 0) {
                return;
            }

            self::delete_item_processed_by_post_id($post_id);
        }

        public static function parse_time_to_seconds($time_string)
        {
            if (!preg_match('/^(\d{2}):(\d{2})$/', (string) $time_string, $matches)) {
                return 0;
            }
            $hours = max(0, min(23, intval($matches[1])));
            $minutes = max(0, min(59, intval($matches[2])));
            return ($hours * HOUR_IN_SECONDS) + ($minutes * MINUTE_IN_SECONDS);
        }

        public static function format_timestamp_for_db($timestamp)
        {
            return wp_date('Y-m-d H:i:s', $timestamp, wp_timezone());
        }

        public static function parse_db_timestamp_to_timestamp($value)
        {
            $value = trim((string) $value);
            if ($value === '') {
                return 0;
            }

            $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
            $datetime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $timezone);
            if ($datetime instanceof DateTimeImmutable) {
                return $datetime->getTimestamp();
            }

            $fallback = strtotime($value);
            return $fallback !== false ? intval($fallback) : 0;
        }

        public static function normalize_timestamp_to_daily_window($generator, $timestamp)
        {
            $generator = is_array($generator) ? $generator : array();
            $timestamp = intval($timestamp);
            if ($timestamp <= 0) {
                return $timestamp;
            }

            $window = self::get_generator_daily_window($generator, $timestamp);
            $window_start = !empty($window[0]) ? intval($window[0]) : 0;
            $window_end = !empty($window[1]) ? intval($window[1]) : 0;
            if ($window_start <= 0 || $window_end <= 0) {
                return $timestamp;
            }

            if ($timestamp < $window_start) {
                return $window_start;
            }

            if ($timestamp > $window_end) {
                $next_window = self::get_generator_daily_window($generator, $timestamp + DAY_IN_SECONDS);
                if (!empty($next_window[0])) {
                    return intval($next_window[0]);
                }
                return $window_start;
            }

            return $timestamp;
        }

        public static function schedule_next_run_for_generator($generator, $base_timestamp = null, $initial_run = false)
        {
            $base_timestamp = $base_timestamp ? intval($base_timestamp) : time();
            $schedule_type = isset($generator['schedule_type']) ? $generator['schedule_type'] : 'interval';
            $window = self::get_generator_daily_window($generator, $base_timestamp);
            $window_start = !empty($window[0]) ? intval($window[0]) : 0;
            $window_end = !empty($window[1]) ? intval($window[1]) : 0;

            if ($schedule_type === 'daily_random') {
                if ($window_start <= 0 || $window_end <= 0) {
                    $delay_minutes = max(1, intval(isset($generator['interval_minutes']) ? $generator['interval_minutes'] : 180));
                    $next_timestamp = $base_timestamp + ($delay_minutes * MINUTE_IN_SECONDS);
                    return self::format_timestamp_for_db($next_timestamp);
                }

                if ($initial_run || $base_timestamp <= $window_start) {
                    $lead_window_minutes = max(5, min(30, intval(isset($generator['jitter_minutes']) ? $generator['jitter_minutes'] : 30)));
                    $start_reference = $window_start;
                    $lower = $start_reference;
                    $upper = min($window_end, $start_reference + ($lead_window_minutes * MINUTE_IN_SECONDS));
                    if ($upper > $lower) {
                        $lower += MINUTE_IN_SECONDS;
                    }
                    if ($upper < $lower) {
                        $upper = $lower;
                    }
                    $next_timestamp = function_exists('wp_rand') ? wp_rand($lower, $upper) : random_int($lower, $upper);
                    return self::format_timestamp_for_db($next_timestamp);
                }

                if ($base_timestamp >= $window_end) {
                    $next_window = self::get_generator_daily_window($generator, $base_timestamp + DAY_IN_SECONDS);
                    $window_start = !empty($next_window[0]) ? intval($next_window[0]) : $window_start;
                    $window_end = !empty($next_window[1]) ? intval($next_window[1]) : $window_end;
                    $lower = $window_start;
                } else {
                    $lower = max($window_start, $base_timestamp + (5 * MINUTE_IN_SECONDS));
                }

                if ($lower >= $window_end) {
                    $next_window = self::get_generator_daily_window($generator, $base_timestamp + DAY_IN_SECONDS);
                    $window_start = !empty($next_window[0]) ? intval($next_window[0]) : $window_start;
                    $window_end = !empty($next_window[1]) ? intval($next_window[1]) : $window_end;
                    $lower = $window_start;
                }

                $next_timestamp = function_exists('wp_rand') ? wp_rand($lower, $window_end) : random_int($lower, $window_end);
                return self::format_timestamp_for_db($next_timestamp);
            }

            $interval = max(1, intval($generator['interval_minutes']));
            $jitter = max(0, intval($generator['jitter_minutes']));
            if ($initial_run && $window_start > 0 && $window_end > 0) {
                $lead_window_minutes = max(5, min(30, $jitter > 0 ? $jitter : 30));
                $lower = max($window_start, $base_timestamp);
                if ($base_timestamp <= $window_start) {
                    $lower = $window_start;
                }
                $upper = min($window_end, $lower + ($lead_window_minutes * MINUTE_IN_SECONDS));
                if ($upper > $lower) {
                    $lower += MINUTE_IN_SECONDS;
                }
                if ($upper < $lower) {
                    $upper = $lower;
                }
                $next_timestamp = function_exists('wp_rand') ? wp_rand($lower, $upper) : random_int($lower, $upper);
                return self::format_timestamp_for_db($next_timestamp);
            }
            $delay_minutes = $interval;
            if ($jitter > 0) {
                $delay_minutes += function_exists('wp_rand') ? wp_rand(1, $jitter) : random_int(1, $jitter);
            }
            $next_timestamp = $base_timestamp + ($delay_minutes * MINUTE_IN_SECONDS);
            $next_timestamp = self::normalize_timestamp_to_daily_window($generator, $next_timestamp);
            return self::format_timestamp_for_db($next_timestamp);
        }

        public static function update_generator_schedule($generator_id)
        {
            $generator = self::get_generator($generator_id);
            if (!$generator) {
                return;
            }

            global $wpdb;
            $next_run_at = null;
            if ($generator['status'] === 'active') {
                $initial_run = empty($generator['last_run_at']);
                $next_run_at = self::schedule_next_run_for_generator($generator, null, $initial_run);
            }

            $wpdb->update(
                self::$table_generators,
                array(
                    'next_run_at' => $next_run_at,
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => intval($generator_id)),
                array('%s', '%s'),
                array('%d')
            );
        }

        public static function seed_next_run_for_active_generators()
        {
            $generators = self::get_generators(500);
            foreach ($generators as $generator) {
                if ($generator['status'] !== 'active') {
                    continue;
                }
                if (empty($generator['next_run_at'])) {
                    self::update_generator_schedule($generator['id']);
                }
            }
        }

        public static function normalize_active_generator_next_runs()
        {
            global $wpdb;
            $generators = self::get_generators(500);
            $now = time();

            foreach ($generators as $generator) {
                if (empty($generator['status']) || $generator['status'] !== 'active') {
                    continue;
                }

                $current_next_run = !empty($generator['next_run_at']) ? self::parse_db_timestamp_to_timestamp((string) $generator['next_run_at']) : 0;
                $current_normalized = $current_next_run > 0 ? self::normalize_timestamp_to_daily_window($generator, $current_next_run) : 0;
                // Keep an overdue run available for process_due_generators(). Only
                // normalize future timestamps or move missed windows to tomorrow.
                if ($current_next_run > 0 && $current_next_run <= $now) {
                    $window = self::get_generator_daily_window($generator, $now);
                    $window_start = !empty($window[0]) ? intval($window[0]) : 0;
                    $window_end = !empty($window[1]) ? intval($window[1]) : 0;
                    if ($window_start > 0 && $window_end > 0 && $now > $window_end) {
                        $next_window = self::get_generator_daily_window($generator, $now + DAY_IN_SECONDS);
                        $next_run_at = !empty($next_window[0])
                            ? self::format_timestamp_for_db(intval($next_window[0]))
                            : $generator['next_run_at'];
                        $wpdb->update(
                            self::$table_generators,
                            array(
                                'next_run_at' => $next_run_at,
                                'updated_at' => current_time('mysql'),
                            ),
                            array('id' => intval($generator['id'])),
                            array('%s', '%s'),
                            array('%d')
                        );
                    }
                    continue;
                }

                $needs_reschedule = $current_next_run <= 0 || $current_normalized !== $current_next_run;
                if (!$needs_reschedule) {
                    continue;
                }

                $next_run_at = self::schedule_next_run_for_generator($generator, $now, empty($generator['last_run_at']));
                $wpdb->update(
                    self::$table_generators,
                    array(
                        'next_run_at' => $next_run_at,
                        'updated_at' => current_time('mysql'),
                    ),
                    array('id' => intval($generator['id'])),
                    array('%s', '%s'),
                    array('%d')
                );
            }
        }

        public static function build_post_data($generator, $article, $item = array())
        {
            $post_type = post_type_exists($generator['post_type']) ? $generator['post_type'] : 'post';
            $post_status = in_array($generator['post_status'], array('publish', 'draft', 'pending', 'private', 'future'), true) ? $generator['post_status'] : 'draft';
            $author_id = self::normalize_content_author_id(isset($generator['author_id']) ? intval($generator['author_id']) : 0);
            if ($author_id <= 0) {
                $author_id = get_current_user_id();
            }
            $generation_mode = !empty($generator['generation_mode']) ? self::normalize_generation_mode((string) $generator['generation_mode']) : self::get_default_generation_mode();
            $article_title_raw = isset($article['title']) ? trim((string) $article['title']) : '';
            $article_content_raw = isset($article['content_html']) ? trim((string) $article['content_html']) : '';
            $must_force_draft = ($article_title_raw === '' || $article_content_raw === '');

            $forced_slug = '';
            if (!empty($item['final_slug'])) {
                $forced_slug = trim((string) $item['final_slug']);
            }

            $post_title = $article_title_raw;
            if ($post_title === '' && !empty($item['source_title'])) {
                $post_title = trim((string) $item['source_title']);
            }
            if ($post_title === '' && !empty($item['keyword'])) {
                $post_title = sanitize_text_field($item['keyword']);
            }
            $post_title = Content_Rank_Generator_Helper::normalize_generated_title($post_title, !empty($item['source_title']) ? $item['source_title'] : '');
            if ($post_title === '' || $post_title === 'Artigo sem título' || $must_force_draft) {
                $post_status = 'draft';
            } elseif ($generation_mode === 'satellite') {
                $post_status = 'future';
            }
            $post_slug = $forced_slug !== '' ? $forced_slug : (!empty($article['slug']) ? $article['slug'] : sanitize_title($post_title));

            $default_tags = json_decode((string) $generator['tags_default'], true);
            if (!is_array($default_tags) || empty($default_tags)) {
                $article['tags'] = array();
            }

            $post_data = array(
                'post_type' => $post_type,
                'post_status' => $post_status,
                'post_author' => $author_id,
                'post_title' => $post_title,
                'post_name' => $post_slug,
                'post_content' => $article_content_raw,
            );

            $post_excerpt = '';
            if (!empty($article['excerpt'])) {
                $post_excerpt = trim((string) $article['excerpt']);
            } elseif (!empty($article['meta_description'])) {
                $post_excerpt = trim((string) $article['meta_description']);
            } elseif (!empty($article['content_html'])) {
                $post_excerpt = wp_trim_words(wp_strip_all_tags((string) $article['content_html']), 28);
            }
            $post_data['post_excerpt'] = $post_excerpt;

            if (!empty($item['date'])) {
                $post_date = self::bulk_parse_timestamp_value($item['date']);
                if ($generation_mode === 'satellite' && $post_status !== 'future') {
                    $post_status = 'future';
                }
                if ($post_date === '' && $post_status === 'future') {
                    $post_date = self::format_timestamp_for_db(time() + HOUR_IN_SECONDS);
                }
                if ($post_date !== '') {
                    $post_data['post_date'] = $post_date;
                    $post_data['post_date_gmt'] = get_gmt_from_date($post_date);
                    $post_data['post_modified'] = $post_date;
                    $post_data['post_modified_gmt'] = get_gmt_from_date($post_date);
                }
            } elseif ($post_status === 'future') {
                $post_date = self::format_timestamp_for_db(time() + HOUR_IN_SECONDS);
                $post_data['post_date'] = $post_date;
                $post_data['post_date_gmt'] = get_gmt_from_date($post_date);
                $post_data['post_modified'] = $post_date;
                $post_data['post_modified_gmt'] = get_gmt_from_date($post_date);
            }

            return $post_data;
        }

        public static function create_source_access_denied_draft($generator, $item, $reason = '')
        {
            $generator = is_array($generator) ? $generator : array();
            $item = is_array($item) ? $item : array();
            $reason = trim((string) $reason);
            if ($reason === '') {
                $reason = 'A fonte retornou 403 e o conteúdo não pôde ser acessado.';
            }

            $permalink = !empty($item['permalink']) ? esc_url_raw(trim((string) $item['permalink'])) : '';
            $source_title = !empty($item['source_title']) ? sanitize_text_field((string) $item['source_title']) : '';
            $post_title = 'Erro, sem acesso';
            $post_content_parts = array(
                '<p><strong>' . esc_html($post_title) . '</strong></p>',
                '<p>' . esc_html($reason) . '</p>',
            );

            if ($source_title !== '') {
                $post_content_parts[] = '<p>Fonte original: ' . esc_html($source_title) . '</p>';
            }

            $post_data = array(
                'post_type' => !empty($generator['post_type']) && post_type_exists($generator['post_type']) ? $generator['post_type'] : 'post',
                'post_status' => 'draft',
                'post_author' => self::normalize_content_author_id(!empty($generator['author_id']) ? intval($generator['author_id']) : get_current_user_id()),
                'post_title' => $post_title,
                'post_content' => implode("\n", $post_content_parts),
                'post_excerpt' => $reason,
                'post_date' => current_time('mysql'),
            );

            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                return $post_id;
            }

            if (!empty($generator['id'])) {
                self::mark_item_processed(intval($generator['id']), $item, intval($post_id));
            }
            self::insert_run_log(
                !empty($generator['id']) ? intval($generator['id']) : 0,
                'warning',
                $reason,
                array(
                    'request' => array(
                        'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                        'permalink' => $permalink,
                    ),
                    'response' => array(
                        'post_id' => intval($post_id),
                        'post_status' => 'draft',
                        'title' => $post_title,
                    ),
                ),
                intval($post_id),
                !empty($item['guid']) ? $item['guid'] : '',
                $permalink
            );

            return intval($post_id);
        }

        /**
         * A post that failed after insertion must never remain publishable.
         */
        public static function force_generated_post_draft($post_id, $reason = '')
        {
            $post_id = intval($post_id);
            if ($post_id <= 0) {
                return false;
            }

            $result = wp_update_post(array(
                'ID' => $post_id,
                'post_status' => 'draft',
            ), true);

            if (!is_wp_error($result) && trim((string) $reason) !== '') {
                update_post_meta(
                    $post_id,
                    '_content_rank_generation_error',
                    sanitize_textarea_field((string) $reason)
                );
            }

            return !is_wp_error($result);
        }

        public static function apply_taxonomies_and_meta($post_id, $generator, $article, $item)
        {
            $categories = json_decode((string) $generator['category_ids'], true);
            $default_category_id = !empty($generator['default_category_id']) ? intval($generator['default_category_id']) : 0;
            if (is_array($categories) && !empty($categories) && taxonomy_exists('category')) {
                $category_ids = array_values(array_filter(array_map('intval', $categories)));
                if (!empty($category_ids)) {
                    $category_result = wp_set_object_terms($post_id, $category_ids, 'category', false);
                    if (is_wp_error($category_result)) {
                        return $category_result;
                    }
                    if ($default_category_id <= 0 || !in_array($default_category_id, $category_ids, true)) {
                        $default_category_id = intval($category_ids[0]);
                    }
                    if ($default_category_id > 0 && in_array($default_category_id, $category_ids, true)) {
                        update_post_meta($post_id, '_yoast_wpseo_primary_category', $default_category_id);
                        update_post_meta($post_id, 'rank_math_primary_category', $default_category_id);
                    }
                }
            }

            $default_tags = json_decode((string) $generator['tags_default'], true);
            $default_tags = is_array($default_tags) ? array_values(array_filter(array_map('sanitize_text_field', $default_tags))) : array();
            $tags = array();
            if (!empty($article['tags']) && is_array($article['tags'])) {
                $tags = array_values(array_unique(array_filter(array_map('sanitize_text_field', $article['tags']))));
            }
            if (empty($tags) && !empty($default_tags)) {
                $tags = array_values(array_unique(array_filter(array_map('sanitize_text_field', $default_tags))));
            }
            if (!empty($tags)) {
                $tags = array_slice($tags, 0, 4);
            }
            if (!empty($tags) && taxonomy_exists('post_tag') && is_object_in_taxonomy(get_post_type($post_id), 'post_tag')) {
                $tag_result = wp_set_post_terms($post_id, $tags, 'post_tag', false);
                if (is_wp_error($tag_result)) {
                    return $tag_result;
                }
            }

            $taxonomies = json_decode((string) $generator['custom_taxonomies'], true);
            if (is_array($taxonomies)) {
                foreach ($taxonomies as $taxonomy => $terms_csv) {
                    $taxonomy = sanitize_key($taxonomy);
                    if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                        continue;
                    }
                    $terms = self::parse_list_field($terms_csv);
                    if (!empty($terms)) {
                        $taxonomy_result = wp_set_object_terms($post_id, $terms, $taxonomy, false);
                        if (is_wp_error($taxonomy_result)) {
                            return $taxonomy_result;
                        }
                    }
                }
            }

            $custom_meta = json_decode((string) $generator['custom_meta'], true);
            if (is_array($custom_meta)) {
                foreach ($custom_meta as $meta_key => $meta_value) {
                    $meta_key = sanitize_key($meta_key);
                    if ($meta_key !== '') {
                        update_post_meta($post_id, $meta_key, sanitize_text_field($meta_value));
                    }
                }
            }

            update_post_meta($post_id, '_content_rank_source_feed_url', $generator['feed_url']);
            update_post_meta($post_id, '_content_rank_source_item_guid', $item['guid']);
            update_post_meta($post_id, '_content_rank_source_item_permalink', $item['permalink']);
            update_post_meta($post_id, '_content_rank_source_item_title', $item['title']);
            if (!empty($item['keyword'])) {
                update_post_meta($post_id, '_content_rank_source_keyword', sanitize_text_field($item['keyword']));
            }
            if (!empty($item['source_title'])) {
                update_post_meta($post_id, '_content_rank_source_title', sanitize_text_field($item['source_title']));
            }
            if (!empty($item['source_url'])) {
                update_post_meta($post_id, '_content_rank_source_url', esc_url_raw($item['source_url']));
            }
            if (!empty($item['final_slug'])) {
                update_post_meta($post_id, '_content_rank_source_final_slug', sanitize_title($item['final_slug']));
            }
            if (!empty($generator['list_id'])) {
                update_post_meta($post_id, '_content_rank_source_list_id', intval($generator['list_id']));
            }
            if (!empty($generator['source_type'])) {
                update_post_meta($post_id, '_content_rank_source_type', sanitize_key($generator['source_type']));
            }
            if (!empty($generator['generation_mode'])) {
                update_post_meta($post_id, '_content_rank_generation_mode', self::normalize_generation_mode((string) $generator['generation_mode']));
            }
            if (!empty($item['content_plan_pillar_post_id'])) {
                update_post_meta($post_id, 'content_plan_pillar_post_id', intval($item['content_plan_pillar_post_id']));
                update_post_meta($post_id, 'content_plan_satellite_index', intval(isset($item['content_plan_satellite_index']) ? $item['content_plan_satellite_index'] : 0));
                update_post_meta($post_id, 'content_plan_satellite_anchor_phrase', !empty($item['content_plan_satellite_anchor_phrase']) ? sanitize_text_field((string) $item['content_plan_satellite_anchor_phrase']) : '');
                update_post_meta($post_id, 'content_plan_link_status', !empty($item['content_plan_satellite_anchor_phrase']) ? 'pending_publish' : 'no_exact_anchor');
            }
            if (!empty($item['guid']) && strpos((string) $item['guid'], 'post:') === 0) {
                $source_post_id = intval(substr((string) $item['guid'], 5));
                if ($source_post_id > 0) {
                    update_post_meta($post_id, '_content_rank_source_post_id', $source_post_id);
                }
            }
            if (!empty($item['source_image_url'])) {
                update_post_meta($post_id, '_content_rank_source_image_url', esc_url_raw($item['source_image_url']));
            }
            if (!empty($item['source_link_url'])) {
                update_post_meta($post_id, '_content_rank_source_link_url', esc_url_raw($item['source_link_url']));
            }
            if (!empty($item['source_link_text'])) {
                update_post_meta($post_id, '_content_rank_source_link_text', sanitize_text_field($item['source_link_text']));
            }
            if (!empty($item['source_image_selector_class'])) {
                update_post_meta($post_id, '_content_rank_source_image_selector_class', sanitize_text_field($item['source_image_selector_class']));
            }
            if (!empty($item['source_link_selector_class'])) {
                update_post_meta($post_id, '_content_rank_source_link_selector_class', sanitize_text_field($item['source_link_selector_class']));
            }
            if (!empty($generator['content_selector'])) {
                update_post_meta($post_id, '_content_rank_content_selector', sanitize_text_field($generator['content_selector']));
            }
            if (!empty($generator['content_image_size'])) {
                update_post_meta($post_id, '_content_rank_content_image_size', self::get_content_image_size_for_generator($generator));
            }
            if (!empty($item['source_page_title'])) {
                update_post_meta($post_id, '_content_rank_source_page_title', sanitize_text_field($item['source_page_title']));
            }
            if (!empty($item['source_page_excerpt'])) {
                update_post_meta($post_id, '_content_rank_source_page_excerpt', sanitize_text_field($item['source_page_excerpt']));
            }
            if (!empty($item['source_page_content_html'])) {
                update_post_meta($post_id, '_content_rank_source_page_content_html', wp_slash((string) $item['source_page_content_html']));
            }
            if (!empty($item['source_page_html'])) {
                update_post_meta($post_id, '_content_rank_source_page_html', wp_slash((string) $item['source_page_html']));
            }
            if (!empty($item['source_page_content'])) {
                update_post_meta($post_id, '_content_rank_source_page_content', wp_strip_all_tags($item['source_page_content']));
            }
            if (!empty($item['source_page_outline'])) {
                update_post_meta($post_id, '_content_rank_source_page_outline', sanitize_textarea_field($item['source_page_outline']));
            }
            if (!empty($item['source_page_outline_sections']) && is_array($item['source_page_outline_sections'])) {
                update_post_meta($post_id, '_content_rank_source_page_outline_sections', wp_json_encode($item['source_page_outline_sections'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if (!empty($item['outline_text'])) {
                update_post_meta($post_id, '_content_rank_outline_text', sanitize_textarea_field($item['outline_text']));
            }
            if (!empty($item['outline_sections']) && is_array($item['outline_sections'])) {
                update_post_meta($post_id, '_content_rank_outline_sections', wp_json_encode($item['outline_sections'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if (!empty($item['content_type'])) {
                update_post_meta($post_id, '_content_rank_outline_content_type', sanitize_key($item['content_type']));
            }
            if (!empty($item['funnel_level'])) {
                update_post_meta($post_id, '_content_rank_outline_funnel_level', sanitize_key($item['funnel_level']));
            }
            if (!empty($item['target_words'])) {
                update_post_meta($post_id, '_content_rank_outline_target_words', intval($item['target_words']));
            }
            if (!empty($item['source_video_url'])) {
                update_post_meta($post_id, '_content_rank_source_video_url', esc_url_raw($item['source_video_url']));
            }
            if (!empty($item['source_video_embed_html'])) {
                update_post_meta($post_id, '_content_rank_source_video_embed_html', $item['source_video_embed_html']);
            }
            if (!empty($item['source_video_source'])) {
                update_post_meta($post_id, '_content_rank_source_video_source', sanitize_text_field($item['source_video_source']));
            }
            if (!empty($item['source_page_video_sections']) && is_array($item['source_page_video_sections'])) {
                update_post_meta($post_id, '_content_rank_source_page_video_sections', wp_json_encode($item['source_page_video_sections'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if (isset($item['outline_target_h2_min'])) {
                update_post_meta($post_id, '_content_rank_outline_target_h2_min', intval($item['outline_target_h2_min']));
            }
            if (isset($item['outline_target_h2_max'])) {
                update_post_meta($post_id, '_content_rank_outline_target_h2_max', intval($item['outline_target_h2_max']));
            }
            if (isset($item['outline_target_h2_count'])) {
                update_post_meta($post_id, '_content_rank_outline_target_h2_count', intval($item['outline_target_h2_count']));
            }
            if (!empty($item['outline_block_quantities']) && is_array($item['outline_block_quantities'])) {
                update_post_meta($post_id, '_content_rank_outline_block_quantities', wp_json_encode($item['outline_block_quantities'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if (!empty($item['date'])) {
                update_post_meta($post_id, '_content_rank_source_timestamp', sanitize_text_field($item['date']));
            }
            update_post_meta($post_id, '_content_rank_generator_id', intval($generator['id']));

            self::sync_seo_meta($post_id, $generator, $article);

            return true;
        }

        public static function sync_seo_meta($post_id, $generator, $article)
        {
            $post = get_post($post_id);
            $seo_title = !empty($article['title']) ? trim((string) $article['title']) : '';
            if ($seo_title === '' && $post instanceof WP_Post) {
                $seo_title = trim((string) get_the_title($post));
            }
            if ($seo_title === '' && !empty($article['source_title'])) {
                $seo_title = trim((string) $article['source_title']);
            }
            if ($seo_title === '' && !empty($article['title'])) {
                $seo_title = trim((string) $article['title']);
            }

            $meta_description = !empty($article['meta_description']) ? trim((string) $article['meta_description']) : '';
            if ($meta_description === '' && !empty($article['excerpt'])) {
                $meta_description = wp_trim_words(wp_strip_all_tags((string) $article['excerpt']), 28);
            }
            if ($meta_description === '' && $post instanceof WP_Post && !empty($post->post_excerpt)) {
                $meta_description = wp_trim_words(wp_strip_all_tags((string) $post->post_excerpt), 28);
            }
            if ($meta_description === '' && !empty($article['content_html'])) {
                $meta_description = wp_trim_words(wp_strip_all_tags((string) $article['content_html']), 28);
            }
            if ($meta_description === '' && $post instanceof WP_Post && !empty($post->post_content)) {
                $meta_description = wp_trim_words(wp_strip_all_tags((string) $post->post_content), 28);
            }

            $focus_keyword = !empty($article['focus_keyword']) ? trim((string) $article['focus_keyword']) : '';
            if ($focus_keyword === '' && !empty($article['source_title'])) {
                $focus_keyword = trim((string) $article['source_title']);
            }
            if ($focus_keyword === '' && $post instanceof WP_Post) {
                $focus_keyword = trim((string) get_the_title($post));
            }
            if ($focus_keyword === '') {
                $focus_keyword = $seo_title;
            }

            update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
            update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_keyword);
            update_post_meta($post_id, '_yoast_wpseo_focuskw_text_input', $focus_keyword);

            update_post_meta($post_id, 'rank_math_title', $seo_title);
            update_post_meta($post_id, 'rank_math_description', $meta_description);
            update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);

            update_post_meta($post_id, '_wds_title', $seo_title);
            update_post_meta($post_id, '_wds_metadesc', $meta_description);
            update_post_meta($post_id, '_wds_focus_keyword', $focus_keyword);

            update_post_meta($post_id, '_aioseo_title', $seo_title);
            update_post_meta($post_id, '_aioseo_description', $meta_description);
            update_post_meta($post_id, '_aioseo_focus_keyword', $focus_keyword);
        }

        public static function build_pexels_query($generator, $item, $article)
        {
            $query = trim((string) $generator['pexels_query']);
            if ($query === '' || $query === '{title}' || $query === '{{title}}') {
                $query = self::get_default_pexels_query();
            }

            $pexels_tags = array();
            if (!empty($article['pexels_tags']) && is_array($article['pexels_tags'])) {
                $pexels_tags = $article['pexels_tags'];
            } elseif (!empty($article['tags']) && is_array($article['tags'])) {
                $pexels_tags = $article['tags'];
            }
            $pexels_tags = self::filter_pexels_tags(array_values(array_unique(array_filter(array_map('sanitize_text_field', $pexels_tags)))), 4);
            $selected_tags = self::get_generator_selected_tags($generator);
            if (empty($pexels_tags) && !empty($selected_tags)) {
                $pexels_tags = self::filter_pexels_tags($selected_tags, 4);
            }
            if (empty($pexels_tags)) {
                $fallback_terms = array();
                if (!empty($article['focus_keyword'])) {
                    $fallback_terms[] = $article['focus_keyword'];
                }
                if (!empty($article['title'])) {
                    $fallback_terms[] = $article['title'];
                }
                $fallback_terms = self::filter_pexels_tags($fallback_terms, 4);
                if (!empty($fallback_terms)) {
                    $pexels_tags = $fallback_terms;
                }
            }
            $pexels_search_terms = trim(implode(' ', array_slice($pexels_tags, 0, 4)));

            $query = strtr($query, array(
                '{{title}}' => $article['title'],
                '{{source_title}}' => $item['title'],
                '{{feed_title}}' => isset($item['feed_title']) ? (string) $item['feed_title'] : '',
                '{{focus_keyword}}' => $article['focus_keyword'],
                '{{pexels_tags}}' => $pexels_search_terms,
                '{{pexels_tags_csv}}' => implode(', ', array_slice($pexels_tags, 0, 4)),
            ));
            $query = str_replace(array(',', ';'), ' ', $query);
            $query = preg_replace('/\s+/', ' ', $query);
            $query = trim($query);

            if ($query === '') {
                $query = $pexels_search_terms;
            }

            return $query;
        }

        public static function download_and_set_featured_image_from_pexels($post_id, $generator, $item, $article, $required = false)
        {
            $settings = self::get_settings();
            $api_key = trim((string) $settings['pexels_api_key']);
            if ($api_key === '' || empty($generator['pexels_enabled'])) {
                self::log_image_debug('pexels_skipped', array(
                    'post_id' => intval($post_id),
                    'required' => $required ? 1 : 0,
                    'pexels_enabled' => !empty($generator['pexels_enabled']) ? 1 : 0,
                    'has_api_key' => $api_key !== '' ? 1 : 0,
                    'keyword' => !empty($item['keyword']) ? $item['keyword'] : '',
                ));
                if ($required) {
                    return new WP_Error('content_rank_pexels_required', 'A chave da API do Pexels precisa estar configurada para geradores de planilha.');
                }
                return false;
            }

            $query = self::build_pexels_query($generator, $item, $article);
            if ($query === '') {
                self::log_image_debug('pexels_empty_query', array(
                    'post_id' => intval($post_id),
                    'required' => $required ? 1 : 0,
                    'keyword' => !empty($item['keyword']) ? $item['keyword'] : '',
                    'title' => !empty($article['title']) ? $article['title'] : '',
                ));
                return false;
            }

            self::log_image_debug('pexels_start', array(
                'post_id' => intval($post_id),
                'query' => $query,
                'required' => $required ? 1 : 0,
                'keyword' => !empty($item['keyword']) ? $item['keyword'] : '',
            ));

            $url = add_query_arg(array(
                'query' => $query,
                // Receive a pool of photos so repeated searches do not always use the first result.
                'per_page' => 20,
                'orientation' => 'landscape',
            ), 'https://api.pexels.com/v1/search');

            $response = wp_remote_get($url, array(
                'timeout' => 60,
                'headers' => array(
                    'Authorization' => $api_key,
                ),
            ));

            if (is_wp_error($response)) {
                self::log_image_debug('pexels_request_failed', array(
                    'post_id' => intval($post_id),
                    'query' => $query,
                    'error' => $response->get_error_message(),
                ));
                return false;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                self::log_image_debug('pexels_bad_status', array(
                    'post_id' => intval($post_id),
                    'query' => $query,
                    'status_code' => $code,
                ));
                return false;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($data['photos']) || !is_array($data['photos'])) {
                self::log_image_debug('pexels_no_photos', array(
                    'post_id' => intval($post_id),
                    'query' => $query,
                ));
                return false;
            }

            $photos = array_values(array_filter($data['photos'], static function ($photo) {
                if (!is_array($photo) || empty($photo['src']) || !is_array($photo['src'])) {
                    return false;
                }

                $width = !empty($photo['width']) ? absint($photo['width']) : 0;
                $height = !empty($photo['height']) ? absint($photo['height']) : 0;
                return Content_Rank_Generator::is_valid_featured_image_dimensions($width, $height)
                    && (!empty($photo['src']['original']) || !empty($photo['src']['large2x']) || !empty($photo['src']['large']));
            }));

            if (empty($photos)) {
                self::log_image_debug('pexels_no_valid_photos', array(
                    'post_id' => intval($post_id),
                    'query' => $query,
                    'photo_count' => count($data['photos']),
                ));
                return false;
            }

            $photo_count = count($photos);
            $start_index = $photo_count > 1
                ? (function_exists('wp_rand') ? wp_rand(0, $photo_count - 1) : random_int(0, $photo_count - 1))
                : 0;
            for ($attempt = 0; $attempt < $photo_count; $attempt++) {
                $photo_index = ($start_index + $attempt) % $photo_count;
                $photo = $photos[$photo_index];
                $image_url = !empty($photo['src']['original'])
                    ? $photo['src']['original']
                    : (!empty($photo['src']['large2x']) ? $photo['src']['large2x'] : $photo['src']['large']);
                if ($image_url === '') {
                    continue;
                }

                self::log_image_debug('pexels_image_selected', array(
                    'post_id' => intval($post_id),
                    'query' => $query,
                    'image_url' => $image_url,
                    'photo_index' => $photo_index,
                    'photo_count' => $photo_count,
                    'width' => !empty($photo['width']) ? absint($photo['width']) : 0,
                    'height' => !empty($photo['height']) ? absint($photo['height']) : 0,
                    'photographer' => !empty($photo['photographer']) ? $photo['photographer'] : '',
                ));

                $attachment_id = self::download_and_set_featured_image_from_url(
                    $post_id,
                    $image_url,
                    $article['title'],
                    'pexels',
                    $query,
                    !empty($photo['photographer']) ? sanitize_text_field($photo['photographer']) : ''
                );
                if (!is_wp_error($attachment_id) && intval($attachment_id) > 0) {
                    return intval($attachment_id);
                }
            }

            self::log_image_debug('pexels_all_candidates_rejected', array(
                'post_id' => intval($post_id),
                'query' => $query,
                'photo_count' => $photo_count,
            ));
            return false;
        }

        public static function build_dalle_prompt($generator, $item, $article)
        {
            $parts = array();

            if (!empty($article['title'])) {
                $parts[] = 'Artigo: ' . trim((string) $article['title']);
            }
            if (!empty($item['keyword'])) {
                $parts[] = 'Keyword: ' . trim((string) $item['keyword']);
            }
            if (!empty($article['focus_keyword'])) {
                $parts[] = 'Foco: ' . trim((string) $article['focus_keyword']);
            }

            $context = implode('. ', array_filter($parts));
            if ($context === '') {
                $context = 'news article illustration';
            }

            return trim(
                'Create a clean editorial featured image for a WordPress article. ' .
                    $context . '. ' .
                    'Photorealistic, high quality, horizontal composition, no text, no watermark, no logo.'
            );
        }

        public static function download_and_set_featured_image_from_dalle($post_id, $generator, $item, $article, $required = false)
        {
            $settings = self::get_settings();
            $api_key = trim((string) $settings['openai_api_key']);
            if ($api_key === '') {
                self::log_image_debug('dalle_skipped_no_api_key', array(
                    'post_id' => intval($post_id),
                    'required' => $required ? 1 : 0,
                    'keyword' => !empty($item['keyword']) ? $item['keyword'] : '',
                    'title' => !empty($article['title']) ? $article['title'] : '',
                ));
                if ($required) {
                    return new WP_Error('content_rank_dalle_required', 'A chave da API da OpenAI precisa estar configurada para usar Dall-e.');
                }
                return false;
            }

            $prompt = self::build_dalle_prompt($generator, $item, $article);
            if ($prompt === '') {
                self::log_image_debug('dalle_empty_prompt', array(
                    'post_id' => intval($post_id),
                    'keyword' => !empty($item['keyword']) ? $item['keyword'] : '',
                ));
                return false;
            }

            self::log_image_debug('dalle_start', array(
                'post_id' => intval($post_id),
                'keyword' => !empty($item['keyword']) ? $item['keyword'] : '',
                'title' => !empty($article['title']) ? $article['title'] : '',
            ));

            $response = wp_remote_post('https://api.openai.com/v1/images/generations', array(
                'timeout' => 120,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode(array(
                    'model' => 'dall-e-3',
                    'prompt' => $prompt,
                    'size' => '1024x1024',
                    'n' => 1,
                    'response_format' => 'url',
                )),
            ));

            if (is_wp_error($response)) {
                self::log_image_debug('dalle_request_failed', array(
                    'post_id' => intval($post_id),
                    'error' => $response->get_error_message(),
                ));
                return false;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                self::log_image_debug('dalle_bad_status', array(
                    'post_id' => intval($post_id),
                    'status_code' => $code,
                ));
                return false;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            $image_url = '';
            if (!empty($data['data'][0]['url'])) {
                $image_url = esc_url_raw(trim((string) $data['data'][0]['url']));
            } elseif (!empty($data['data'][0]['b64_json'])) {
                self::log_image_debug('dalle_b64_received', array(
                    'post_id' => intval($post_id),
                    'length' => strlen((string) $data['data'][0]['b64_json']),
                ));
                $tmp = wp_upload_bits('dalle-' . time() . '.png', null, base64_decode((string) $data['data'][0]['b64_json']));
                if (!empty($tmp['error'])) {
                    self::log_image_debug('dalle_write_failed', array(
                        'post_id' => intval($post_id),
                        'error' => $tmp['error'],
                    ));
                    return false;
                }
                $image_url = !empty($tmp['url']) ? esc_url_raw($tmp['url']) : '';
            }

            if ($image_url === '') {
                self::log_image_debug('dalle_no_image_url', array(
                    'post_id' => intval($post_id),
                ));
                return false;
            }

            self::log_image_debug('dalle_image_selected', array(
                'post_id' => intval($post_id),
                'image_url' => $image_url,
            ));

            return self::download_and_set_featured_image_from_url(
                $post_id,
                $image_url,
                $article['title'],
                'dalle',
                $prompt,
                ''
            );
        }

        public function handle_async_staged_generation()
        {
            $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
            $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
            $stored_token = $post_id > 0 ? get_transient('content_rank_staged_generation_token_' . $post_id) : false;

            if ($post_id <= 0 || !$stored_token || $token === '' || !hash_equals((string) $stored_token, $token)) {
                status_header(403);
                wp_die('Invalid staged generation token.');
            }

            delete_transient('content_rank_staged_generation_token_' . $post_id);
            self::process_staged_generation($post_id);
            wp_die('ok');
        }

        public function resume_pending_staged_generations()
        {
            if (!current_user_can('manage_options')) {
                return;
            }

            $pending_posts = get_posts(array(
                'post_type' => 'any',
                'post_status' => 'any',
                'posts_per_page' => 3,
                'fields' => 'ids',
                'meta_query' => array(
                    array(
                        'key' => self::GENERATION_PIPELINE_META,
                        'compare' => 'EXISTS',
                    ),
                ),
            ));

            foreach ($pending_posts as $post_id) {
                self::dispatch_staged_generation_async($post_id);
            }
        }

        public function handle_staged_generation_status()
        {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Acesso negado.'), 403);
            }

            check_ajax_referer('content_rank_staged_generation_status', 'nonce');
            $post_ids = isset($_POST['post_ids']) && is_array($_POST['post_ids']) ? array_map('absint', wp_unslash($_POST['post_ids'])) : array();
            $post_ids = array_values(array_filter(array_unique($post_ids)));
            if (empty($post_ids)) {
                $post_ids = get_posts(array(
                    'post_type' => 'any',
                    'post_status' => 'any',
                    'posts_per_page' => 5,
                    'fields' => 'ids',
                    'orderby' => 'modified',
                    'order' => 'DESC',
                    'meta_query' => array(
                        array(
                            'key' => self::GENERATION_PIPELINE_META,
                            'compare' => 'EXISTS',
                        ),
                    ),
                ));
            }
            $items = array();
            $stage_labels = array(
                'planning' => 'Planejamento',
                'seo' => 'SEO',
                'content_outline' => 'Esboço',
                'content' => 'Conteúdo',
            );

            foreach ($post_ids as $post_id) {
                $state = get_post_meta($post_id, self::GENERATION_PIPELINE_META, true);
                $status = (string) get_post_meta($post_id, '_content_rank_generation_pipeline_status', true);
                $error_message = (string) get_post_meta($post_id, '_content_rank_generation_pipeline_error', true);
                $stage = is_array($state) && !empty($state['stage']) ? sanitize_key((string) $state['stage']) : '';
                $items[] = array(
                    'post_id' => $post_id,
                    'title' => get_the_title($post_id),
                    'stage' => $stage,
                    'stage_label' => isset($stage_labels[$stage]) ? $stage_labels[$stage] : '',
                    'status' => $stage !== '' ? 'processing' : ($status === 'failed' ? 'failed' : ($status === 'completed' ? 'completed' : 'idle')),
                    'error_message' => $error_message,
                    'edit_url' => self::get_post_edit_link($post_id),
                );
            }

            wp_send_json_success(array('items' => $items));
        }

        public function render_staged_generation_toast()
        {
            if (!current_user_can('manage_options')) {
                return;
            }

            $pending_posts = get_posts(array(
                'post_type' => 'any',
                'post_status' => 'any',
                'posts_per_page' => 5,
                'fields' => 'ids',
                'meta_query' => array(
                    array(
                        'key' => self::GENERATION_PIPELINE_META,
                        'compare' => 'EXISTS',
                    ),
                ),
            ));
            $initial_items = array();
            foreach ($pending_posts as $post_id) {
                $state = get_post_meta($post_id, self::GENERATION_PIPELINE_META, true);
                $stage = is_array($state) && !empty($state['stage']) ? sanitize_key((string) $state['stage']) : 'planning';
                $pipeline_status = (string) get_post_meta($post_id, '_content_rank_generation_pipeline_status', true);
                $initial_items[] = array(
                    'post_id' => intval($post_id),
                    'title' => get_the_title($post_id),
                    'stage' => $stage,
                    'status' => $pipeline_status === 'failed' ? 'failed' : 'processing',
                    'error_message' => (string) get_post_meta($post_id, '_content_rank_generation_pipeline_error', true),
                    'edit_url' => self::get_post_edit_link($post_id),
                );
            }

            $status_url = admin_url('admin-ajax.php');
            $nonce = wp_create_nonce('content_rank_staged_generation_status');
            ?>
            <div id="content-rank-staged-generation-toast" class="content-rank-staged-generation-toast" role="status" aria-live="polite" style="<?php echo empty($initial_items) ? 'display:none;' : ''; ?>">
                <button type="button" class="content-rank-staged-generation-toast__close" aria-label="Fechar">&times;</button>
                <div class="content-rank-staged-generation-toast__eyebrow">Content Rank</div>
                <strong class="content-rank-staged-generation-toast__title">Geração em andamento</strong>
                <div class="content-rank-staged-generation-toast__post"></div>
                <div class="content-rank-staged-generation-toast__stage"></div>
                <div class="content-rank-staged-generation-toast__track"><span></span></div>
                <div class="content-rank-staged-generation-toast__steps">
                    <span data-stage="planning">Planejamento</span>
                    <span data-stage="seo">SEO</span>
                    <span data-stage="content_outline">Esboço</span>
                    <span data-stage="content">Conteúdo</span>
                </div>
                <a class="content-rank-staged-generation-toast__link" href="#" target="_blank" rel="noopener">Abrir post</a>
            </div>
            <style>
                .content-rank-staged-generation-toast{position:fixed;z-index:100000;right:24px;bottom:24px;width:min(360px,calc(100vw - 32px));padding:18px 20px;border:1px solid #dbe3f0;border-radius:16px;background:#fff;color:#17213a;box-shadow:0 16px 45px rgba(15,23,42,.2);font-size:13px;line-height:1.45}
                .content-rank-staged-generation-toast__close{position:absolute;top:8px;right:10px;border:0;background:transparent;color:#64748b;font-size:22px;line-height:1;cursor:pointer}
                .content-rank-staged-generation-toast__eyebrow{margin-bottom:4px;color:#4f46e5;font-size:10px;font-weight:700;letter-spacing:.16em;text-transform:uppercase}
                .content-rank-staged-generation-toast__title{display:block;padding-right:18px;font-size:15px}
                .content-rank-staged-generation-toast__post{margin-top:6px;overflow:hidden;color:#475569;text-overflow:ellipsis;white-space:nowrap}
                .content-rank-staged-generation-toast__stage{margin-top:12px;color:#4f46e5;font-weight:600}
                .content-rank-staged-generation-toast__track{height:6px;margin-top:10px;overflow:hidden;border-radius:999px;background:#e2e8f0}
                .content-rank-staged-generation-toast__track span{display:block;width:35%;height:100%;border-radius:999px;background:#4f46e5;animation:content-rank-staged-generation-loading 1.25s ease-in-out infinite}
                .content-rank-staged-generation-toast__steps{display:flex;justify-content:space-between;gap:5px;margin-top:8px;color:#94a3b8;font-size:10px}
                .content-rank-staged-generation-toast__steps span.is-active{color:#4f46e5;font-weight:700}
                .content-rank-staged-generation-toast__link{display:inline-block;margin-top:12px;color:#4338ca;text-decoration:none}
                .content-rank-staged-generation-toast.is-success{border-color:#86efac;background:#f0fdf4}
                .content-rank-staged-generation-toast.is-error{border-color:#fca5a5;background:#fef2f2}
                .content-rank-staged-generation-toast.is-success .content-rank-staged-generation-toast__track span{width:100%!important;transform:none!important;animation:none!important}
                @keyframes content-rank-staged-generation-loading{0%,100%{transform:translateX(-110%)}50%{transform:translateX(300%)}}
            </style>
            <script>
                (function () {
                    var toast = document.getElementById('content-rank-staged-generation-toast');
                    if (!toast) return;
                    var items = <?php echo wp_json_encode($initial_items); ?>;
                    var postIds = items.map(function (item) { return String(item.post_id); });
                    var stageOrder = ['planning', 'seo', 'content_outline', 'content'];
                    var stageNames = { planning: 'Planejamento', seo: 'SEO', content_outline: 'Esboço', content: 'Conteúdo' };
                    var timer;
                    var dismissed = false;
                    var toastKey = '';
                    var toastStoragePrefix = 'content_rank_generation_toast_closed_';
                    var closeButton = toast.querySelector('.content-rank-staged-generation-toast__close');
                    var titleNode = toast.querySelector('.content-rank-staged-generation-toast__title');
                    var postNode = toast.querySelector('.content-rank-staged-generation-toast__post');
                    var stageNode = toast.querySelector('.content-rank-staged-generation-toast__stage');
                    var trackNode = toast.querySelector('.content-rank-staged-generation-toast__track span');
                    var linkNode = toast.querySelector('.content-rank-staged-generation-toast__link');

                    function getToastKey(ids) {
                        var normalized = Array.isArray(ids) ? ids.map(function (id) { return String(id); }).filter(Boolean).sort() : [];
                        return toastStoragePrefix + (normalized.length ? normalized.join('_') : 'unknown');
                    }

                    function setToastContext(ids, resetDismissal) {
                        var nextKey = getToastKey(ids);
                        if (resetDismissal) {
                            dismissed = false;
                            try {
                                window.sessionStorage.removeItem(nextKey);
                            } catch (error) {
                                // Storage can be unavailable in private browsing modes.
                            }
                        } else {
                            try {
                                dismissed = window.sessionStorage.getItem(nextKey) === '1';
                            } catch (error) {
                                dismissed = false;
                            }
                        }
                        toastKey = nextKey;
                    }

                    function render(item) {
                        var stageIndex = stageOrder.indexOf(item.stage);
                        var status = item.status || 'processing';
                        toast.classList.toggle('is-success', status === 'completed');
                        toast.classList.toggle('is-error', status === 'failed');
                        titleNode.textContent = status === 'completed' ? 'Geração concluída' : (status === 'failed' ? 'Geração interrompida' : 'Geração em andamento');
                        postNode.textContent = item.title || ('Post #' + item.post_id);
                        stageNode.textContent = status === 'completed' ? 'Todas as etapas foram concluídas.' : (status === 'failed' ? ('Erro: ' + (item.error_message || 'A geração falhou.')) : ('Etapa atual: ' + (item.stage_label || stageNames[item.stage] || 'Processando')));
                        trackNode.style.width = status === 'processing' ? '35%' : '100%';
                        trackNode.style.animationPlayState = status === 'processing' ? 'running' : 'paused';
                        toast.querySelectorAll('[data-stage]').forEach(function (node, index) {
                            node.classList.toggle('is-active', status === 'completed' || index <= stageIndex);
                        });
                        linkNode.href = item.edit_url || '#';
                        linkNode.style.display = status === 'completed' && item.edit_url ? 'inline-block' : 'none';
                    }

                    function finish(item, status) {
                        item = item || {};
                        item.status = status;
                        render(item);
                    }

                    function showPollingError(message) {
                        toast.classList.remove('is-success');
                        toast.classList.add('is-error');
                        titleNode.textContent = 'Geração interrompida';
                        stageNode.textContent = 'Erro ao consultar o progresso: ' + (message || 'resposta inválida do servidor.');
                        trackNode.style.width = '100%';
                        trackNode.style.animationPlayState = 'paused';
                        linkNode.style.display = 'none';
                    }

                    function showGenerationToast(nextPostIds, fallbackTitle) {
                        nextPostIds = Array.isArray(nextPostIds) ? nextPostIds.map(function (id) { return String(id); }).filter(Boolean) : [];
                        if (nextPostIds.length) {
                            postIds = nextPostIds;
                            items = postIds.map(function (postId) {
                                return {
                                    post_id: parseInt(postId, 10) || 0,
                                    title: fallbackTitle || 'Geração iniciada',
                                    stage: 'planning',
                                    stage_label: 'Planejamento',
                                    status: 'processing',
                                    error_message: '',
                                    edit_url: ''
                                };
                            });
                        } else if (!items.length) {
                            items = [{
                                post_id: 0,
                                title: fallbackTitle || 'Geração iniciada',
                                stage: 'planning',
                                stage_label: 'Planejamento',
                                status: 'processing',
                                error_message: '',
                                edit_url: ''
                            }];
                        }
                        setToastContext(postIds, true);
                        toast.style.display = 'block';
                        render(items[0]);
                        poll();
                        if (!timer) {
                            timer = window.setInterval(poll, 4000);
                        }
                    }

                    window.ContentRankGenerationToast = window.ContentRankGenerationToast || {};
                    window.ContentRankGenerationToast.start = showGenerationToast;

                    function poll() {
                        if (dismissed) {
                            return;
                        }
                        var body = new URLSearchParams();
                        body.append('action', 'content_rank_get_staged_generation_status');
                        body.append('nonce', <?php echo wp_json_encode($nonce); ?>);
                        postIds.forEach(function (id) { body.append('post_ids[]', id); });
                        fetch(<?php echo wp_json_encode($status_url); ?>, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
                            .then(function (response) {
                                if (!response.ok) {
                                    throw new Error('HTTP ' + response.status);
                                }
                                return response.text().then(function (text) {
                                    try {
                                        return JSON.parse(text);
                                    } catch (error) {
                                        throw new Error('resposta inválida do servidor');
                                    }
                                });
                            })
                            .then(function (payload) {
                                if (!payload || !payload.success) {
                                    throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'resposta inválida do servidor');
                                }
                                if (!payload.data || !Array.isArray(payload.data.items)) {
                                    throw new Error('progresso não encontrado');
                                }
                                if (!payload.data.items.length) return;
                                items = payload.data.items;
                                postIds = items.map(function (item) { return String(item.post_id); });
                                if (dismissed) return;
                                toast.style.display = 'block';
                                var current = items.find(function (item) { return item.status === 'processing'; });
                                if (current) {
                                    render(current);
                                    return;
                                }
                                var failed = items.find(function (item) { return item.status === 'failed'; });
                                finish(failed || items[0], failed ? 'failed' : 'completed');
                                window.clearInterval(timer);
                                timer = null;
                            })
                            .catch(function (error) {
                                if (dismissed) return;
                                showPollingError(error && error.message ? error.message : 'falha de comunicação com o servidor');
                                window.clearInterval(timer);
                                timer = null;
                            });
                    }

                    closeButton.addEventListener('click', function () {
                        dismissed = true;
                        try {
                            window.sessionStorage.setItem(toastKey || getToastKey(postIds), '1');
                        } catch (error) {
                            // Storage can be unavailable in private browsing modes.
                        }
                        window.clearInterval(timer);
                        timer = null;
                        toast.style.display = 'none';
                    });
                    setToastContext(postIds, false);
                    if (items.length && !dismissed) {
                        render(items[0]);
                        poll();
                        timer = window.setInterval(poll, 4000);
                    }
                }());
            </script>
            <?php
        }

        public static function dispatch_staged_generation_async($post_id)
        {
            $post_id = absint($post_id);
            if ($post_id <= 0) {
                return false;
            }

            $token = wp_generate_password(40, false, false);
            set_transient('content_rank_staged_generation_token_' . $post_id, $token, 10 * MINUTE_IN_SECONDS);
            $response = wp_remote_post(admin_url('admin-ajax.php'), array(
                // A requisicao nao bloqueia, mas precisa de tempo suficiente
                // para concluir o loopback antes de o cron de fallback assumir.
                'timeout' => 1.0,
                'blocking' => false,
                'redirection' => 0,
                'body' => array(
                    'action' => 'content_rank_process_staged_generation',
                    'post_id' => $post_id,
                    'token' => $token,
                ),
            ));

            if (is_wp_error($response)) {
                error_log('[content-rank] staged generation async dispatch failed: ' . $response->get_error_message());
                return false;
            }

            return true;
        }

        public static function schedule_staged_generation_stage($post_id, $delay = 10)
        {
            $post_id = intval($post_id);
            if ($post_id <= 0) {
                return false;
            }

            $delay = max(1, intval($delay));
            if (!wp_next_scheduled(self::STAGED_GENERATION_HOOK, array($post_id))) {
                wp_schedule_single_event(time() + $delay, self::STAGED_GENERATION_HOOK, array($post_id));
            }

            if (function_exists('spawn_cron')) {
                spawn_cron(time());
            }

            // Keep the cron event as a fallback, but do not depend on WP-Cron
            // being enabled for staged generation to advance.
            self::dispatch_staged_generation_async($post_id);

            return true;
        }

        public static function queue_staged_generation($generator, $item, $existing_post_id = 0)
        {
            $generator = is_array($generator) ? $generator : array();
            $existing_post_id = intval($existing_post_id);
            if ($existing_post_id > 0 && !get_post($existing_post_id)) {
                return new WP_Error('content_rank_generation_post_missing', 'Post da regeneracao nao encontrado.');
            }
            $original_item = is_array($item) ? $item : array();
            $item = self::maybe_enrich_rss_item_context($generator, $item);
            if (is_wp_error($item)) {
                if ($item->get_error_code() === 'content_rank_source_forbidden') {
                    return self::create_source_access_denied_draft($generator, $original_item, $item->get_error_message());
                }
                return $item;
            }
            if (self::generator_uses_source_page_context($generator)) {
                $item = self::resolve_item_media_for_generation($generator, $item);
                if (is_wp_error($item)) {
                    if ($item->get_error_code() === 'content_rank_source_forbidden') {
                        return self::create_source_access_denied_draft($generator, $original_item, $item->get_error_message());
                    }
                    return $item;
                }
            }

            if ($existing_post_id <= 0) {
                $semantic_title = !empty($item['source_title'])
                    ? trim((string) $item['source_title'])
                    : (!empty($item['title']) ? trim((string) $item['title']) : '');
                $allow_repeated_keyword_intent = !empty($item['keyword_list_repeated_intent']);
                if (!$allow_repeated_keyword_intent) {
                    $semantic_duplicate = self::find_semantic_duplicate_for_title($semantic_title, $generator, array(
                        'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                        'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : '',
                    ));
                    if (is_array($semantic_duplicate) && !empty($semantic_duplicate['post_id'])) {
                        $duplicate_post_id = intval($semantic_duplicate['post_id']);
                        self::mark_item_processed($generator['id'], $item, $duplicate_post_id);
                        return $duplicate_post_id;
                    }
                }
            }

            $placeholder_title = !empty($item['source_title'])
                ? trim((string) $item['source_title'])
                : (!empty($item['keyword']) ? sanitize_text_field((string) $item['keyword']) : 'Geracao em andamento');
            if ($existing_post_id > 0) {
                $post_id = $existing_post_id;
            } else {
                $post_type = !empty($generator['post_type']) && post_type_exists($generator['post_type']) ? $generator['post_type'] : 'post';
                $author_id = self::normalize_content_author_id(!empty($generator['author_id']) ? intval($generator['author_id']) : 0);
                if ($author_id <= 0) {
                    $author_id = get_current_user_id();
                }
                $post_id = wp_insert_post(array(
                    'post_type' => $post_type,
                    'post_status' => 'draft',
                    'post_author' => $author_id,
                    'post_title' => Content_Rank_Generator_Helper::normalize_generated_title($placeholder_title, $placeholder_title),
                    'post_name' => !empty($item['final_slug']) ? sanitize_title((string) $item['final_slug']) : sanitize_title($placeholder_title),
                    'post_content' => '',
                ), true);
                if (is_wp_error($post_id)) {
                    return $post_id;
                }

                $article_placeholder = array(
                    'title' => $placeholder_title,
                    'content_html' => '',
                    'excerpt' => '',
                    'tags' => array(),
                );
                $metadata_result = self::apply_taxonomies_and_meta($post_id, $generator, $article_placeholder, $item);
                if (is_wp_error($metadata_result)) {
                    self::force_generated_post_draft($post_id, $metadata_result->get_error_message());
                    return $metadata_result;
                }
            }

            // Link the reserved item before the asynchronous AI stages start.
            self::link_processing_item_to_post($generator['id'], $item, $post_id);
            if (!empty($generator['list_id']) && !empty($item['guid'])) {
                self::update_keyword_list_row_status_from_item(
                    intval($generator['list_id']),
                    $item['guid'],
                    'processing',
                    $post_id
                );
            }

            // Keep the exact generation context available for regeneration.
            // The snapshot is temporary and expires after five days.
            if (class_exists('Content_Rank_Generated_Posts')) {
                Content_Rank_Generated_Posts::save_regeneration_snapshot_for_pipeline($post_id, $generator, $item);
            }

            delete_post_meta($post_id, '_content_rank_generation_pipeline_error');
            update_post_meta($post_id, self::GENERATION_PIPELINE_META, array(
                'stage' => 'planning',
                'generator_id' => intval($generator['id']),
                'generator' => intval($generator['id']) <= 0 ? $generator : array(),
                'item' => $item,
                'outline_context' => array(),
                'seo_article' => array(),
                'post_id' => intval($post_id),
                'existing_post_id' => $existing_post_id,
                'started_at' => current_time('mysql'),
            ));
            update_post_meta($post_id, '_content_rank_generation_pipeline_status', 'planning');
            self::insert_run_log($generator['id'], 'info', 'Pipeline de geracao enfileirada', array(
                'request' => array(
                    'post_id' => intval($post_id),
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                    'stage' => 'planning',
                ),
            ), $post_id, !empty($item['guid']) ? $item['guid'] : '', !empty($item['permalink']) ? $item['permalink'] : '');
            self::schedule_staged_generation_stage($post_id, 1);

            return array(
                'queued' => 1,
                'post_id' => intval($post_id),
                'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                'item_title' => !empty($item['title']) ? $item['title'] : $placeholder_title,
            );
        }

        public function process_staged_generation($post_id)
        {
            $post_id = intval($post_id);
            if ($post_id <= 0 || self::$staged_generation_request_processed) {
                if ($post_id > 0 && self::$staged_generation_request_processed) {
                    self::schedule_staged_generation_stage($post_id, 60);
                }
                return;
            }
            self::$staged_generation_request_processed = true;

            $state = get_post_meta($post_id, self::GENERATION_PIPELINE_META, true);
            $has_virtual_generator = is_array($state) && !empty($state['generator']) && is_array($state['generator']);
            if (!is_array($state) || empty($state['stage']) || (empty($state['generator_id']) && !$has_virtual_generator)) {
                return;
            }

            $generator = $has_virtual_generator
                ? $state['generator']
                : self::get_generator(intval($state['generator_id']));
            $item = !empty($state['item']) && is_array($state['item']) ? $state['item'] : array();
            if (!$generator || empty($item)) {
                self::fail_staged_generation($post_id, $state, new WP_Error('content_rank_generation_pipeline_state_invalid', 'Estado da geracao nao encontrado.'));
                return;
            }

            $lock_key = 'content_rank_staged_generation_lock_' . $post_id;
            if (get_transient($lock_key)) {
                return;
            }
            set_transient($lock_key, 1, 10 * MINUTE_IN_SECONDS);

            try {
                $stage = sanitize_key((string) $state['stage']);
                if ($stage === 'planning') {
                    $result = Content_Rank_Generator_Helper::prepare_generation_planning($generator, $item);
                    if (is_wp_error($result)) {
                        throw new RuntimeException($result->get_error_message());
                    }
                    $state['item'] = !empty($result['item']) && is_array($result['item']) ? $result['item'] : $item;
                    $state['outline_context'] = !empty($result['outline_context']) && is_array($result['outline_context']) ? $result['outline_context'] : array();
                    if (!empty($item['review_products_prompt'])) {
                        // Reviews always use the review prompt model, even if
                        // the generic source analysis sees article-like HTML.
                        $state['outline_context']['content_type'] = 'review';
                        $state['outline_context']['recommended_prompt_model_key'] = 'review';
                        $state['outline_context']['recommended_outline_model_key'] = 'guide_long';
                        $state['outline_context']['outline_model_key'] = 'guide_long';
                    }
                    $state['stage'] = 'seo';
                } elseif ($stage === 'seo') {
                    $result = Content_Rank_Generator_Helper::generate_seo_article_stage(
                        $generator,
                        $item,
                        !empty($state['outline_context']) && is_array($state['outline_context']) ? $state['outline_context'] : array()
                    );
                    if (is_wp_error($result)) {
                        throw new RuntimeException($result->get_error_message());
                    }

                    $state['seo_article'] = !empty($result['seo_article']) && is_array($result['seo_article']) ? $result['seo_article'] : array();
                    $state['outline_context'] = !empty($result['outline_context']) && is_array($result['outline_context']) ? $result['outline_context'] : array();
                    $state['stage'] = 'content_outline';
                } elseif ($stage === 'content_outline') {
                    $result = Content_Rank_Generator_Helper::generate_content_outline_context(
                        $generator,
                        $item,
                        !empty($state['seo_article']) && is_array($state['seo_article']) ? $state['seo_article'] : array(),
                        !empty($state['outline_context']) && is_array($state['outline_context']) ? $state['outline_context'] : array()
                    );
                    if (is_wp_error($result)) {
                        throw new RuntimeException($result->get_error_message());
                    }
                    $state['outline_context'] = $result;
                    if (!empty($item['review_products_prompt'])) {
                        // Do not let the generic outline response change the
                        // fixed review model used by the next content stage.
                        $state['outline_context']['content_type'] = 'review';
                        $state['outline_context']['recommended_prompt_model_key'] = 'review';
                        $state['outline_context']['recommended_outline_model_key'] = 'guide_long';
                        $state['outline_context']['outline_model_key'] = 'guide_long';
                    }
                    $state['stage'] = 'content';
                } elseif ($stage === 'content') {
                    $seo_article = !empty($state['seo_article']) && is_array($state['seo_article']) ? $state['seo_article'] : array();
                    $outline_context = !empty($state['outline_context']) && is_array($state['outline_context']) ? $state['outline_context'] : array();
                    $content_article = Content_Rank_Generator_Helper::generate_content_article_stage($generator, $item, $seo_article, $outline_context);
                    if (is_wp_error($content_article)) {
                        throw new RuntimeException($content_article->get_error_message());
                    }
                    $seo_article['content_html'] = !empty($content_article['content_html']) ? $content_article['content_html'] : '';
                    $review_products = !empty($item['review_products']) && is_array($item['review_products'])
                        ? $item['review_products']
                        : json_decode((string) get_post_meta($post_id, '_content_rank_review_products_json', true), true);
                    if (is_array($review_products) && !empty($review_products)) {
                        $seo_article['content_html'] = Content_Rank_Review_Builder::replace_product_placeholders_for_pipeline(
                            $seo_article['content_html'],
                            $review_products,
                            $post_id
                        );
                    }
                    if (!empty($outline_context)) {
                        $seo_article['outline_context'] = $outline_context;
                        $seo_article['outline_text'] = !empty($outline_context['outline_text']) ? $outline_context['outline_text'] : '';
                        $seo_article['outline_sections'] = !empty($outline_context['outline_sections']) ? $outline_context['outline_sections'] : array();
                        $seo_article['outline_target_h2_min'] = !empty($outline_context['outline_target_h2_min']) ? intval($outline_context['outline_target_h2_min']) : 0;
                        $seo_article['outline_target_h2_max'] = !empty($outline_context['outline_target_h2_max']) ? intval($outline_context['outline_target_h2_max']) : 0;
                        $seo_article['outline_target_h2_count'] = !empty($outline_context['outline_target_h2_count']) ? intval($outline_context['outline_target_h2_count']) : 0;
                        $seo_article['outline_block_quantities'] = !empty($outline_context['outline_block_quantities']) ? $outline_context['outline_block_quantities'] : array();
                    }

                    $result = self::create_post_from_generator_item($generator, $item, $seo_article, $post_id);
                    if (is_wp_error($result)) {
                        throw new RuntimeException($result->get_error_message());
                    }

                    if (!empty($generator['list_id']) && !empty($item['guid'])) {
                        self::update_keyword_list_row_status_from_item(intval($generator['list_id']), $item['guid'], 'generated', intval($post_id));
                    }
                    delete_post_meta($post_id, self::GENERATION_PIPELINE_META);
                    delete_post_meta($post_id, '_content_rank_generation_pipeline_error');
                    update_post_meta($post_id, '_content_rank_generation_pipeline_status', 'completed');
                    self::insert_run_log($generator['id'], 'success', 'Pipeline de geracao concluida', array(
                        'request' => array('post_id' => $post_id, 'stage' => 'content'),
                        'response' => array('post_id' => $post_id),
                    ), $post_id, !empty($item['guid']) ? $item['guid'] : '', !empty($item['permalink']) ? $item['permalink'] : '');
                    return;
                } else {
                    throw new RuntimeException('Etapa de geracao desconhecida.');
                }

                update_post_meta($post_id, self::GENERATION_PIPELINE_META, $state);
                update_post_meta($post_id, '_content_rank_generation_pipeline_status', sanitize_key((string) $state['stage']));
                self::insert_run_log($generator['id'], 'info', 'Etapa da pipeline concluida', array(
                    'request' => array('post_id' => $post_id, 'stage' => $stage),
                    'response' => array('next_stage' => $state['stage']),
                ), $post_id, !empty($item['guid']) ? $item['guid'] : '', !empty($item['permalink']) ? $item['permalink'] : '');
                self::schedule_staged_generation_stage($post_id, 1);
            } catch (Throwable $error) {
                self::fail_staged_generation($post_id, $state, $error);
            } finally {
                delete_transient($lock_key);
            }
        }

        public static function fail_staged_generation($post_id, $state, $error)
        {
            $post_id = intval($post_id);
            $state = is_array($state) ? $state : array();
            $message = $error instanceof Throwable ? trim((string) $error->getMessage()) : trim((string) $error);
            if ($message === '') {
                $message = 'Falha durante a geracao do conteudo.';
            }
            update_post_meta($post_id, '_content_rank_generation_pipeline_error', $message);
            $generator_id = !empty($state['generator_id']) ? intval($state['generator_id']) : 0;
            $item = !empty($state['item']) && is_array($state['item']) ? $state['item'] : array();
            self::force_generated_post_draft($post_id, $message);
            if ($generator_id > 0 && !empty($item)) {
                self::mark_item_failed($generator_id, $item, 'content_rank_generation_pipeline_failed', $message);
                $generator = self::get_generator($generator_id);
                if (!empty($generator['list_id']) && !empty($item['guid'])) {
                    self::update_keyword_list_row_status_from_item(intval($generator['list_id']), $item['guid'], 'failed', 0, $message);
                }
                self::insert_run_log($generator_id, 'error', $message, array(
                    'request' => array('post_id' => $post_id, 'stage' => !empty($state['stage']) ? $state['stage'] : ''),
                    'response' => array('post_status' => 'draft'),
                ), $post_id, !empty($item['guid']) ? $item['guid'] : '', !empty($item['permalink']) ? $item['permalink'] : '');
            }
            delete_post_meta($post_id, self::GENERATION_PIPELINE_META);
            update_post_meta($post_id, '_content_rank_generation_pipeline_status', 'failed');
        }

        public static function create_post_from_generator_item($generator, $item, $prepared_article = null, $existing_post_id = 0)
        {
            $has_prepared_article = is_array($prepared_article);
            $existing_post_id = intval($existing_post_id);
            $original_item = is_array($item) ? $item : array();
            $item = self::maybe_enrich_rss_item_context($generator, $item);
            if (is_wp_error($item)) {
                if ($item->get_error_code() === 'content_rank_source_forbidden') {
                    return self::create_source_access_denied_draft($generator, $original_item, $item->get_error_message());
                }
                return $item;
            }
            $use_source_page_context = self::generator_uses_source_page_context($generator);
            if ($use_source_page_context && !$has_prepared_article) {
                $item = self::resolve_item_media_for_generation($generator, $item);
                if (is_wp_error($item)) {
                    if ($item->get_error_code() === 'content_rank_source_forbidden') {
                        return self::create_source_access_denied_draft($generator, $original_item, $item->get_error_message());
                    }
                    return $item;
                }
            }

            if ($has_prepared_article) {
                $article = $prepared_article;
            } else {
                $semantic_title = '';
                if (!empty($item['source_title'])) {
                    $semantic_title = trim((string) $item['source_title']);
                } elseif (!empty($item['title'])) {
                    $semantic_title = trim((string) $item['title']);
                }

                $allow_repeated_keyword_intent = !empty($item['keyword_list_repeated_intent']);
                $semantic_duplicate = !$allow_repeated_keyword_intent
                    ? self::find_semantic_duplicate_for_title($semantic_title, $generator, array(
                        'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                        'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : '',
                    ))
                    : null;
                if (is_array($semantic_duplicate) && !empty($semantic_duplicate['post_id'])) {
                    $duplicate_post_id = intval($semantic_duplicate['post_id']);
                    $duplicate_score = isset($semantic_duplicate['score']) ? floatval($semantic_duplicate['score']) : 0.0;
                    $duplicate_method = !empty($semantic_duplicate['method']) ? (string) $semantic_duplicate['method'] : 'text';
                    $item['semantic_duplicate_post_id'] = $duplicate_post_id;
                    $item['semantic_duplicate_score'] = $duplicate_score;
                    $item['semantic_duplicate_method'] = $duplicate_method;

                    self::insert_run_log($generator['id'], 'info', 'Item semantico reaproveitado', array(
                        'request' => array(
                            'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                            'semantic_title' => $semantic_title,
                        ),
                        'response' => array(
                            'post_id' => $duplicate_post_id,
                            'matched_title' => !empty($semantic_duplicate['matched_title']) ? $semantic_duplicate['matched_title'] : '',
                            'score' => $duplicate_score,
                            'method' => $duplicate_method,
                        ),
                    ), $duplicate_post_id, !empty($item['guid']) ? $item['guid'] : '', !empty($item['permalink']) ? $item['permalink'] : '');

                    self::mark_item_processed($generator['id'], $item, $duplicate_post_id);
                    return $duplicate_post_id;
                }

                $article = Content_Rank_Generator_Helper::call_openai($generator, $item);
                if (is_wp_error($article)) {
                    return $article;
                }
            }

            $generated_content_type = !empty($article['outline_context']['content_type'])
                ? (string) $article['outline_context']['content_type']
                : (!empty($item['content_type']) ? (string) $item['content_type'] : '');
            $article['content_html'] = Content_Rank_Generator_Helper::normalize_generated_list_markup(
                isset($article['content_html']) ? $article['content_html'] : '',
                $generated_content_type
            );

            // The AI must not publish invented image URLs. RSS images are
            // inserted later by the PHP source-media pipeline.
            $source_type_for_images = !empty($generator['source_type'])
                ? sanitize_key((string) $generator['source_type'])
                : 'rss';
            if ($source_type_for_images === 'rss' && !empty($article['content_html'])) {
                $article['content_html'] = (string) preg_replace('/<img\b[^>]*>/i', '', (string) $article['content_html']);
            }

            // Reviews must resolve product placeholders before Gutenberg
            // parses the HTML. Otherwise missing cards are appended after the
            // article and the editor can mark the raw card as invalid.
            $review_products = !empty($item['review_products']) && is_array($item['review_products'])
                ? $item['review_products']
                : json_decode((string) get_post_meta($existing_post_id, '_content_rank_review_products_json', true), true);
            if (is_array($review_products) && !empty($review_products)) {
                $article['content_html'] = Content_Rank_Review_Builder::replace_product_placeholders_for_pipeline(
                    $article['content_html'],
                    $review_products,
                    $existing_post_id
                );
            }

            if (!empty($generator['tmdb_title_translation_enabled']) && class_exists('Content_Rank_TMDB')) {
                $article = Content_Rank_TMDB::localize_article_movie_titles($generator, $item, $article);
            }

            if (!empty($article['content_html']) && !empty($generator['random_bolds_enabled'])) {
                $focus_keyword = !empty($article['focus_keyword'])
                    ? (string) $article['focus_keyword']
                    : (!empty($article['palavra_chave_foco']) ? (string) $article['palavra_chave_foco'] : '');
                $article['content_html'] = Content_Rank_Generator_Helper::apply_humanized_bold_markup_to_content(
                    $article['content_html'],
                    2,
                    4,
                    $focus_keyword
                );
            }

            $title_outline_count = Content_Rank_Generator_Helper::extract_outline_target_h2_count_from_title(
                !empty($article['title']) ? $article['title'] : '',
                !empty($item['source_title']) ? $item['source_title'] : (!empty($item['title']) ? $item['title'] : '')
            );
            if ($title_outline_count > 0) {
                $item['title_outline_count_hint'] = $title_outline_count;
            }

            if (!empty($article['outline_context']) && is_array($article['outline_context'])) {
                $item = array_merge($item, $article['outline_context']);
            }

            foreach (array('outline_text', 'outline_sections', 'outline_target_h2_min', 'outline_target_h2_max', 'outline_target_h2_count', 'outline_block_quantities') as $outline_key) {
                if (isset($article[$outline_key])) {
                    $item[$outline_key] = $article[$outline_key];
                }
            }

            $is_keyword_list = !empty($generator['source_type']) && self::source_type_uses_keyword_list($generator['source_type']);
            $treat_like_rss = self::generator_uses_source_page_context($generator);

            if (!$use_source_page_context) {
                $item = self::resolve_item_media_for_generation($generator, $item);
            }

            if (!empty($item['final_slug'])) {
                $article['slug'] = sanitize_title($item['final_slug']);
            }
            if (!empty($item['keyword']) && empty($article['focus_keyword'])) {
                $article['focus_keyword'] = sanitize_text_field($item['keyword']);
            }

            $article['content_html'] = Content_Rank_Generator_Helper::apply_internal_links_to_content(
                isset($article['content_html']) ? $article['content_html'] : '',
                $generator,
                array(
                    'post_id' => 0,
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                )
            );

            $use_source_video = !empty($generator['source_video_enabled']);
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
            $has_section_videos = false;
            if ($use_source_video) {
                foreach ($source_video_sections as $source_video_section) {
                    if (is_array($source_video_section) && !empty($source_video_section['videos']) && is_array($source_video_section['videos'])) {
                        $has_section_videos = true;
                        break;
                    }
                }
            }
            if ($treat_like_rss && $use_source_video) {
                if (!$has_section_videos) {
                    $source_video_embed_html = !empty($item['source_video_embed_html']) ? trim((string) $item['source_video_embed_html']) : '';
                    $source_video_url = !empty($item['source_video_url']) ? esc_url_raw(trim((string) $item['source_video_url'])) : '';
                }
            }

            $article['content_html'] = self::convert_html_fragment_to_gutenberg_blocks(
                isset($article['content_html']) ? $article['content_html'] : '',
                $source_video_embed_html,
                $source_video_url
            );

            if ($has_section_videos) {
                $article['content_html'] = Content_Rank_Generator_Helper::inject_source_video_sections_into_content(
                    $article['content_html'],
                    $source_video_sections
                );
            }

            $article['content_html'] = Content_Rank_Generator_Helper::ensure_content_starts_with_paragraph_html($article['content_html']);
            $article['content_html'] = Content_Rank_Generator_Helper::remove_unmatched_trailing_quotes_from_html($article['content_html']);

            $post_data = self::build_post_data($generator, $article, $item);
            if ($existing_post_id > 0) {
                $post_data['ID'] = $existing_post_id;
                $post_id = wp_update_post($post_data, true);
            } else {
                $post_id = wp_insert_post($post_data, true);
            }
            if (is_wp_error($post_id)) {
                return $post_id;
            }

            try {
            $content_media_sections = !empty($item['source_page_outline_sections']) && is_array($item['source_page_outline_sections'])
                ? $item['source_page_outline_sections']
                : array();
            $use_interval_content_images = self::generator_uses_interval_content_images($generator);
            if ($use_interval_content_images) {
                $content_media_sections = self::resolve_content_image_sections_for_generation($item, $generator, $article);
            }
            if (!empty($content_media_sections) && is_array($content_media_sections)) {
                $content_image_size = self::get_content_image_size_for_generator($generator);
                $use_source_content_images = self::generator_uses_source_content_images($generator);
                $use_source_content_links = self::generator_uses_source_content_links($generator);
                $existing_image_map = array();
                if (!empty($article['content_html'])) {
                    $existing_image_map = Content_Rank_Generator_Helper::extract_outline_section_image_map_from_content($article['content_html']);
                }
                if ($use_interval_content_images) {
                    $article['content_html'] = Content_Rank_Generator_Helper::inject_content_images_by_word_interval(
                            $article['content_html'],
                            $content_media_sections,
                            $post_id,
                            $content_image_size,
                            !empty($generator['content_image_interval_words']) ? intval($generator['content_image_interval_words']) : 500,
                            array(!empty($item['source_image_url']) ? trim((string) $item['source_image_url']) : '')
                        );
                } else {
                    $article['content_html'] = Content_Rank_Generator_Helper::inject_outline_section_media_into_content(
                        $article['content_html'],
                        $content_media_sections,
                        $post_id,
                        $content_image_size,
                        !empty($generator['source_link_phrases']) ? $generator['source_link_phrases'] : '',
                        $use_source_content_images,
                        false,
                        $generator,
                        array(
                            'post_id' => intval($post_id),
                            'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                            'generated_title' => !empty($article['title']) ? (string) $article['title'] : '',
                            'outline_target_h2_count_hint' => !empty($item['title_outline_count_hint']) ? intval($item['title_outline_count_hint']) : 0,
                            'source_image_url' => !empty($item['source_image_url']) ? trim((string) $item['source_image_url']) : '',
                        ),
                        $existing_image_map,
                        array(),
                        array(!empty($item['source_image_url']) ? trim((string) $item['source_image_url']) : '')
                    );
                }
                $article['content_html'] = Content_Rank_Generator_Helper::ensure_content_starts_with_paragraph_html($article['content_html']);
                if ($article['content_html'] !== '') {
                    $update_content = wp_update_post(array(
                        'ID' => $post_id,
                        'post_content' => $article['content_html'],
                    ), true);
                    if (is_wp_error($update_content)) {
                        self::insert_run_log($generator['id'], 'warning', $update_content->get_error_message(), array(
                            'request' => array(
                                'post_id' => $post_id,
                                'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                            ),
                        ), $post_id, $item['guid'], $item['permalink']);
                        self::force_generated_post_draft($post_id, $update_content->get_error_message());
                        return $update_content;
                    }
                }
            }

            $taxonomy_result = self::apply_taxonomies_and_meta($post_id, $generator, $article, $item);
            if (is_wp_error($taxonomy_result)) {
                self::force_generated_post_draft($post_id, $taxonomy_result->get_error_message());
                return $taxonomy_result;
            }

            $thumbnail_result = Content_Rank_Thumbnail_Helper::set_featured_image(
                $post_id,
                $generator,
                $item,
                $article,
                false
            );
            if (is_wp_error($thumbnail_result)) {
                self::force_generated_post_draft($post_id, $thumbnail_result->get_error_message());
                return $thumbnail_result;
            }

            $featured_image_id = intval(get_post_thumbnail_id($post_id));
            if ($featured_image_id <= 0) {
                $fallback_title = !empty($article['title']) ? (string) $article['title'] : (!empty($item['source_title']) ? (string) $item['source_title'] : (!empty($item['title']) ? (string) $item['title'] : ''));
                $featured_image_id = intval(Content_Rank_Generator::create_placeholder_image_attachment(
                    $post_id,
                    $fallback_title,
                    'fallback',
                    !empty($item['keyword']) ? $item['keyword'] : '',
                    ''
                ));
            }

            if ($featured_image_id <= 0) {
                $thumbnail_error = new WP_Error(
                    'content_rank_generation_thumbnail_missing',
                    'A geração falhou porque não foi possível definir a imagem destacada.'
                );
                self::force_generated_post_draft($post_id, $thumbnail_error->get_error_message());
                return $thumbnail_error;
            }

            if ($treat_like_rss && $use_source_video) {
                self::insert_run_log($generator['id'], 'info', 'Checagem de vídeo da fonte', array(
                    'request' => array(
                        'post_id' => $post_id,
                        'item_guid' => $item['guid'],
                    ),
                    'response' => array(
                        'source_video_url' => !empty($item['source_video_url']) ? $item['source_video_url'] : '',
                        'has_source_video' => !empty($item['source_video_url']) ? 1 : 0,
                        'video_selector_class' => !empty($generator['video_selector_class']) ? $generator['video_selector_class'] : '',
                        'permalink' => !empty($item['permalink']) ? $item['permalink'] : '',
                    ),
                ), $post_id, $item['guid'], $item['permalink']);
            }
            self::mark_item_processed($generator['id'], $item, $post_id);
            self::insert_run_log($generator['id'], 'success', 'Post criado', array(
                'request' => array('item' => $item['guid']),
                'response' => array('post_id' => $post_id, 'title' => $article['title']),
            ), $post_id, $item['guid'], $item['permalink']);

            return $post_id;
            } catch (Throwable $error) {
                $message = trim((string) $error->getMessage());
                if ($message === '') {
                    $message = 'Erro inesperado durante o pós-processamento da geração.';
                }
                self::force_generated_post_draft($post_id, $message);
                self::insert_run_log($generator['id'], 'error', $message, array(
                    'request' => array(
                        'post_id' => $post_id,
                        'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                    ),
                    'response' => array(
                        'post_status' => 'draft',
                    ),
                ), $post_id, !empty($item['guid']) ? $item['guid'] : '', !empty($item['permalink']) ? $item['permalink'] : '');
                return new WP_Error('content_rank_generation_post_processing_failed', $message);
            }
        }

        public function cron_tick()
        {
            if (get_transient('content_rank_cron_lock')) {
                return;
            }
            set_transient('content_rank_cron_lock', 1, 4 * MINUTE_IN_SECONDS);

            self::process_pending_jobs();

            delete_transient('content_rank_cron_lock');
        }

        public static function process_due_generators($only_id = 0)
        {
            global $wpdb;

            $now = current_time('mysql');
            $sql = "SELECT * FROM " . self::$table_generators . " WHERE status = 'active'";
            $params = array();
            if ($only_id > 0) {
                $sql .= " AND id = %d";
                $params[] = intval($only_id);
            } else {
                $sql .= " AND (next_run_at IS NULL OR next_run_at <= %s)";
                $params[] = $now;
            }
            // One generator per cron tick prevents several long AI requests from
            // being started back-to-back in the same request.
            $sql .= " ORDER BY COALESCE(next_run_at, created_at) ASC LIMIT 1";

            $generators = !empty($params) ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

            foreach ($generators as $generator) {
                self::run_generator($generator, false);
            }
        }

        public static function process_due_generated_future_posts($limit = 25)
        {
            global $wpdb;

            $limit = max(1, intval($limit));
            $now_gmt = gmdate('Y-m-d H:i:s');
            $sql = $wpdb->prepare(
                "SELECT DISTINCT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
                 WHERE p.post_status = 'future'
                   AND p.post_date_gmt <> '0000-00-00 00:00:00'
                   AND p.post_date_gmt <= %s
                 ORDER BY p.post_date_gmt ASC
                 LIMIT %d",
                '_content_rank_generator_id',
                $now_gmt,
                $limit
            );

            $post_ids = $wpdb->get_col($sql);
            if (empty($post_ids)) {
                return;
            }

            foreach ($post_ids as $post_id) {
                $post_id = intval($post_id);
                if ($post_id <= 0) {
                    continue;
                }

                $post = get_post($post_id);
                if (!$post || $post->post_status !== 'future') {
                    continue;
                }

                wp_publish_post($post_id);
            }
        }

        public static function run_keyword_list_generator($generator, $manual = false)
        {
            global $wpdb;
            $list_id = !empty($generator['list_id']) ? intval($generator['list_id']) : 0;
            if ($list_id <= 0) {
                self::insert_run_log($generator['id'], 'error', 'Gerador sem lista vinculada', array(
                    'request' => array('manual' => $manual),
                ));
                self::update_next_run_after_attempt($generator);
                return new WP_Error('content_rank_missing_list', 'Este gerador não possui uma lista vinculada.');
            }

            $list = self::get_keyword_list($list_id);
            if (!$list) {
                self::insert_run_log($generator['id'], 'error', 'Lista vinculada não encontrada', array(
                    'request' => array('manual' => $manual, 'list_id' => $list_id),
                ));
                self::update_next_run_after_attempt($generator);
                return new WP_Error('content_rank_missing_list', 'Lista vinculada não encontrada.');
            }

            $filters = array();
            if (!empty($generator['filters_json'])) {
                $decoded_filters = json_decode((string) $generator['filters_json'], true);
                if (is_array($decoded_filters)) {
                    $filters = $decoded_filters;
                }
            }
            $source_context_filters = self::get_generator_source_context_filters($generator);

            $created = 0;
            $skipped = 0;
            $failed = 0;
            $limit = $manual ? max(1, intval($generator['posts_per_run'])) : 1;
            $scan_offset = 0;
            $scan_limit = 250;
            $tables = self::bulk_tables();
            $video_selector_class = !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '';

            while ($created < $limit) {
                $pending_rows = self::bulk_get_pending_rows_batch($list_id, $scan_limit, $scan_offset);
                if (empty($pending_rows)) {
                    break;
                }

                foreach ($pending_rows as $row) {
                    if ($created >= $limit) {
                        break 2;
                    }

                    $row_data = json_decode($row->row_data, true);
                    if (!is_array($row_data)) {
                        continue;
                    }

                    if (intval($row->slug_is_valid) !== 1) {
                        $wpdb->update(
                            $tables['rows'],
                            array(
                                'row_status' => 'invalid_slug',
                                'updated_at' => current_time('mysql'),
                            ),
                            array('id' => intval($row->id)),
                            array('%s', '%s'),
                            array('%d')
                        );
                        continue;
                    }

                    if (!self::bulk_row_matches_filters($row_data, $filters)) {
                        $skipped++;
                        continue;
                    }

                    $selected_item = self::build_keyword_list_item_from_row(
                        $list,
                        $row,
                        false,
                        $video_selector_class,
                        !empty($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '',
                        !empty($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '',
                        $source_context_filters,
                        !empty($generator['content_selector']) ? sanitize_text_field((string) $generator['content_selector']) : ''
                    );
                    if (class_exists('Content_Rank_Global_Filters') && Content_Rank_Global_Filters::item_is_blocked($selected_item)) {
                        $skipped++;
                        $wpdb->update(
                            $tables['rows'],
                            array(
                                'row_status' => 'blocked',
                                'error_message' => 'Fonte ou termo bloqueado pela blacklist global.',
                                'updated_at' => current_time('mysql'),
                            ),
                            array('id' => intval($row->id)),
                            array('%s', '%s', '%s'),
                            array('%d')
                        );
                        continue;
                    }
                    if (!empty($source_context_filters) && !Content_Rank_Generator_Helper::source_context_item_matches_filters($selected_item, $source_context_filters)) {
                        $skipped++;
                        continue;
                    }
                    if (self::is_item_processed($generator['id'], $selected_item['guid'])) {
                        $skipped++;
                        continue;
                    }

                    $wpdb->update(
                        $tables['rows'],
                        array(
                            'row_status' => 'processing',
                            'updated_at' => current_time('mysql'),
                        ),
                        array('id' => intval($row->id)),
                        array('%s', '%s'),
                        array('%d')
                    );
                    if (!self::claim_item_processing_slot($generator['id'], $selected_item)) {
                        $skipped++;
                        continue;
                    }

                $result = self::queue_staged_generation($generator, $selected_item);
                if (is_wp_error($result)) {
                    self::mark_item_failed($generator['id'], $selected_item, $result->get_error_code(), $result->get_error_message());
                    $failed++;
                    $wpdb->update(
                        $tables['rows'],
                            array(
                                'row_status' => 'failed',
                                'error_message' => $result->get_error_message(),
                                'updated_at' => current_time('mysql'),
                            ),
                            array('id' => intval($row->id)),
                            array('%s', '%s', '%s'),
                            array('%d')
                        );
                        self::insert_run_log($generator['id'], 'error', $result->get_error_message(), array(
                            'request' => array('row_id' => intval($row->id), 'list_id' => $list_id),
                        ), null, $selected_item['guid'], $selected_item['permalink']);
                        continue;
                    }

                    if (is_array($result) && !empty($result['queued'])) {
                        $created++;
                        continue;
                    }

                    $wpdb->update(
                        $tables['rows'],
                        array(
                            'row_status' => 'generated',
                            'post_id' => intval($result),
                            'error_message' => '',
                            'updated_at' => current_time('mysql'),
                            'processed_at' => current_time('mysql'),
                        ),
                        array('id' => intval($row->id)),
                        array('%s', '%d', '%s', '%s', '%s'),
                        array('%d')
                    );

                    $created++;
                }

                $scan_offset += $scan_limit;
            }

            $message = sprintf('Criados %d post(s), ignorados %d item(s), falharam %d item(s).', $created, $skipped, $failed);
            self::insert_run_log($generator['id'], 'success', $message, array(
                'request' => array('manual' => $manual, 'source_type' => !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'keyword_list', 'list_id' => $list_id, 'filters' => $filters),
                'response' => array('created' => $created, 'skipped' => $skipped, 'failed' => $failed),
            ));

            self::bulk_refresh_list_counts($list_id);
            self::update_next_run_after_attempt($generator);

            return array(
                'created' => $created,
                'skipped' => $skipped,
                'failed' => $failed,
            );
        }

        public static function build_satellite_source_item_from_post($post, $generator = array())
        {
            if (!$post instanceof WP_Post) {
                return array();
            }

            $post_content = (string) get_post_field('post_content', $post->ID);
            $post_excerpt = (string) get_post_field('post_excerpt', $post->ID);
            if ($post_excerpt === '') {
                $post_excerpt = wp_trim_words(wp_strip_all_tags($post_content), 28);
            }

            $categories = array();
            $post_categories = get_the_category($post->ID);
            if (!empty($post_categories) && is_array($post_categories)) {
                foreach ($post_categories as $category) {
                    if (!empty($category->name)) {
                        $categories[] = (string) $category->name;
                    }
                }
            }

            $tags = array();
            $post_tags = get_the_tags($post->ID);
            if (!empty($post_tags) && is_array($post_tags)) {
                foreach ($post_tags as $tag) {
                    if (!empty($tag->name)) {
                        $tags[] = (string) $tag->name;
                    }
                }
            }

            $featured_image_url = '';
            if (has_post_thumbnail($post->ID)) {
                $featured_image_url = (string) get_the_post_thumbnail_url($post->ID, 'full');
            }

            $source_title = get_the_title($post);
            $permalink = get_permalink($post);
            $source_page_content_html = $post_content;
            $source_page_content = wp_strip_all_tags($post_content);

            return array(
                'id' => intval($post->ID),
                'guid' => 'post:' . intval($post->ID),
                'title' => $source_title,
                'source_title' => $source_title,
                'source_url' => $permalink,
                'permalink' => $permalink,
                'feed_title' => get_bloginfo('name'),
                'date' => !empty($post->post_date) ? $post->post_date : current_time('mysql'),
                'excerpt' => $post_excerpt,
                'content' => $source_page_content,
                'content_html' => $source_page_content_html,
                'source_page_title' => $source_title,
                'source_page_excerpt' => $post_excerpt,
                'source_page_content' => $source_page_content,
                'source_page_content_html' => $source_page_content_html,
                'source_page_html' => $source_page_content_html,
                'source_page_outline' => '',
                'source_context_enriched' => 1,
                'source_image_url' => $featured_image_url,
                'source_link_url' => $permalink,
                'source_link_text' => $source_title,
                'source_video_url' => '',
                'source_video_embed_html' => '',
                'source_video_source' => '',
                'post_type' => get_post_type($post),
                'categories' => $categories,
                'tags' => $tags,
                'generation_mode' => !empty($generator['generation_mode']) ? self::normalize_generation_mode((string) $generator['generation_mode']) : self::get_default_generation_mode(),
                'source_type' => !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss',
            );
        }

        public static function get_satellite_source_posts_for_generator($generator, $limit = 10)
        {
            $generator = is_array($generator) ? $generator : array();
            $limit = max(1, intval($limit));
            $post_type = !empty($generator['post_type']) && post_type_exists($generator['post_type']) ? $generator['post_type'] : 'post';
            $recent_cutoff = wp_date('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS), function_exists('wp_timezone') ? wp_timezone() : null);
            $candidate_queries = array(
                array(
                    'post_type' => $post_type,
                    'post_status' => array('publish'),
                    'posts_per_page' => max(50, $limit * 10),
                    'orderby' => 'rand',
                    'no_found_rows' => true,
                    'date_query' => array(
                        array(
                            'after' => $recent_cutoff,
                            'inclusive' => true,
                        ),
                    ),
                ),
                array(
                    'post_type' => $post_type,
                    'post_status' => array('publish'),
                    'posts_per_page' => max(50, $limit * 10),
                    'orderby' => 'rand',
                    'no_found_rows' => true,
                ),
            );

            $posts = array();
            $seen_ids = array();
            foreach ($candidate_queries as $query_args) {
                $queried_posts = get_posts($query_args);
                if (empty($queried_posts) || !is_array($queried_posts)) {
                    continue;
                }

                foreach ($queried_posts as $post) {
                    if (!$post instanceof WP_Post) {
                        continue;
                    }

                    $post_id = intval($post->ID);
                    if ($post_id <= 0 || isset($seen_ids[$post_id])) {
                        continue;
                    }

                    $seen_ids[$post_id] = true;
                    $posts[] = $post;
                    if (count($posts) >= max(20, $limit * 10)) {
                        break 2;
                    }
                }
            }

            if (empty($posts)) {
                return array();
            }

            $source_context_filters = self::get_generator_source_context_filters($generator);
            $items = array();
            foreach ($posts as $post) {
                if (!$post instanceof WP_Post) {
                    continue;
                }

                $item = self::build_satellite_source_item_from_post($post, $generator);
                if (empty($item['guid'])) {
                    continue;
                }

                if (!empty($source_context_filters) && !Content_Rank_Generator_Helper::source_context_item_matches_filters($item, $source_context_filters)) {
                    continue;
                }

                if (self::is_item_processed($generator['id'], $item['guid'])) {
                    continue;
                }

                $items[] = $item;
                if (count($items) >= $limit) {
                    break;
                }
            }

            return $items;
        }

        public static function run_satellite_generator($generator, $manual = false)
        {
            $limit = $manual ? max(1, intval($generator['posts_per_run'])) : 1;
            $items = self::get_satellite_source_posts_for_generator($generator, max(10, $limit * 5));
            if (empty($items)) {
                self::insert_run_log($generator['id'], 'warning', 'Nenhum post satelite disponivel para processar', array(
                    'request' => array(
                        'manual' => $manual ? 1 : 0,
                        'generation_mode' => 'satellite',
                        'post_type' => !empty($generator['post_type']) ? $generator['post_type'] : 'post',
                    ),
                ));
                self::update_next_run_after_attempt($generator);
                return array(
                    'created' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                );
            }

            $created = 0;
            $skipped = 0;
            $failed = 0;
            $selected_items = array_slice($items, 0, $limit);
            $total_count = count($selected_items);
            $schedule_base_timestamp = time();
            $schedule_window = self::get_generator_daily_window($generator, $schedule_base_timestamp);
            $schedule_window_start = !empty($schedule_window[0]) ? intval($schedule_window[0]) : 0;
            $schedule_window_end = !empty($schedule_window[1]) ? intval($schedule_window[1]) : 0;
            $schedule_jitter = max(0, intval(isset($generator['jitter_minutes']) ? $generator['jitter_minutes'] : 0));

            // Use one randomized base for the whole batch instead of assigning
            // a different random start to every satellite item.
            if ($schedule_window_start > 0 && $schedule_window_end > 0 && $schedule_jitter > 0) {
                if ($schedule_base_timestamp < $schedule_window_start) {
                    $schedule_upper = min($schedule_window_end, $schedule_window_start + ($schedule_jitter * MINUTE_IN_SECONDS));
                    $schedule_base_timestamp = function_exists('wp_rand')
                        ? wp_rand($schedule_window_start, $schedule_upper)
                        : random_int($schedule_window_start, $schedule_upper);
                } elseif ($schedule_base_timestamp > $schedule_window_end) {
                    $next_window = self::get_generator_daily_window($generator, $schedule_base_timestamp + DAY_IN_SECONDS);
                    $next_start = !empty($next_window[0]) ? intval($next_window[0]) : 0;
                    $next_end = !empty($next_window[1]) ? intval($next_window[1]) : 0;
                    if ($next_start > 0 && $next_end > 0) {
                        $schedule_upper = min($next_end, $next_start + ($schedule_jitter * MINUTE_IN_SECONDS));
                        $schedule_base_timestamp = function_exists('wp_rand')
                            ? wp_rand($next_start, $schedule_upper)
                            : random_int($next_start, $schedule_upper);
                    }
                }
            }

            foreach ($selected_items as $index => $item) {
                $scheduled_date = self::build_satellite_schedule_datetime($generator, $index + 1, $total_count, $schedule_base_timestamp);
                if (!empty($scheduled_date)) {
                    $item['date'] = $scheduled_date;
                }

                if (self::is_item_processed($generator['id'], $item['guid'])) {
                    $skipped++;
                    continue;
                }

                if (!self::claim_item_processing_slot($generator['id'], $item)) {
                    $skipped++;
                    continue;
                }

                $result = self::queue_staged_generation($generator, $item);
                if (is_wp_error($result)) {
                    self::mark_item_failed($generator['id'], $item, $result->get_error_code(), $result->get_error_message());
                    $failed++;
                    self::insert_run_log($generator['id'], 'error', $result->get_error_message(), array(
                        'request' => array(
                            'item_guid' => $item['guid'],
                            'generation_mode' => 'satellite',
                        ),
                    ), null, $item['guid'], $item['permalink']);
                    continue;
                }

                if (is_array($result) && !empty($result['queued'])) {
                    $created++;
                    continue;
                }

                $created++;
            }

            self::insert_run_log($generator['id'], 'success', 'Posts satelite criados', array(
                'request' => array(
                    'manual' => $manual ? 1 : 0,
                    'generation_mode' => 'satellite',
                ),
                'response' => array(
                    'created' => $created,
                    'skipped' => $skipped,
                    'failed' => $failed,
                ),
            ));

            self::update_next_run_after_attempt($generator);
            return array(
                'created' => $created,
                'skipped' => $skipped,
                'failed' => $failed,
            );
        }

        public static function run_generator($generator, $manual = false)
        {
            if (!empty($generator['generation_mode']) && self::normalize_generation_mode((string) $generator['generation_mode']) === 'satellite') {
                return self::run_satellite_generator($generator, $manual);
            }
            if (!empty($generator['source_type']) && self::source_type_uses_keyword_list($generator['source_type'])) {
                return self::run_keyword_list_generator($generator, $manual);
            }

            $items = self::get_rss_items_for_generator(
                $generator,
                max(10, intval($generator['posts_per_run']) * 5),
                false,
                !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '',
                !empty($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '',
                !empty($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '',
                self::get_generator_source_context_filters($generator)
            );
            if (is_wp_error($items)) {
                self::insert_run_log($generator['id'], 'error', $items->get_error_message(), array(
                    'request' => array('feed_url' => $generator['feed_url']),
                ));
                self::update_next_run_after_attempt($generator);
                return $items;
            }

            $created = 0;
            $skipped = 0;
            $failed = 0;
            $limit = $manual ? max(1, intval($generator['posts_per_run'])) : 1;

            foreach ($items as $item) {
                if ($created >= $limit) {
                    break;
                }

                if (self::is_item_processed($generator['id'], $item['guid'])) {
                    $skipped++;
                    continue;
                }

                if (!self::claim_item_processing_slot($generator['id'], $item)) {
                    $skipped++;
                    continue;
                }

                $result = self::queue_staged_generation($generator, $item);
                if (is_wp_error($result)) {
                    self::mark_item_failed($generator['id'], $item, $result->get_error_code(), $result->get_error_message());
                    $failed++;
                    self::insert_run_log($generator['id'], 'error', $result->get_error_message(), array(
                        'request' => array('guid' => $item['guid']),
                    ), null, $item['guid'], $item['permalink']);
                    continue;
                }

                if (is_array($result) && !empty($result['queued'])) {
                    $created++;
                    continue;
                }

                $created++;
            }

            $message = sprintf('Criados %d post(s), ignorados %d item(s) já processados, falharam %d item(s).', $created, $skipped, $failed);
            self::insert_run_log($generator['id'], 'success', $message, array(
                'request' => array('manual' => $manual),
                'response' => array('created' => $created, 'skipped' => $skipped, 'failed' => $failed),
            ));

            self::update_next_run_after_attempt($generator);
            return array(
                'created' => $created,
                'skipped' => $skipped,
                'failed' => $failed,
            );
        }

        public static function update_next_run_after_attempt($generator)
        {
            global $wpdb;
            $next_run_at = self::schedule_next_run_for_generator($generator);
            $wpdb->update(
                self::$table_generators,
                array(
                    'last_run_at' => current_time('mysql'),
                    'next_run_at' => $generator['status'] === 'active' ? $next_run_at : null,
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => intval($generator['id'])),
                array('%s', '%s', '%s'),
                array('%d')
            );
        }

        public static function save_generator($raw)
        {
            global $wpdb;
            $payload = self::normalize_generator_payload($raw);
            if (is_wp_error($payload)) {
                return $payload;
            }

            $generator_id = isset($raw['generator_id']) ? intval($raw['generator_id']) : 0;
            $now = current_time('mysql');

            $data = array(
                'name' => $payload['name'],
                'feed_url' => $payload['feed_url'],
                'source_type' => $payload['source_type'],
                'generation_mode' => $payload['generation_mode'],
                'source_post_id' => $payload['source_post_id'],
                'list_id' => $payload['list_id'],
                'keyword_list_mode' => $payload['keyword_list_mode'],
                'tavily_enabled' => $payload['tavily_enabled'],
                'tmdb_title_translation_enabled' => $payload['tmdb_title_translation_enabled'],
                'tmdb_thumbnail_bg_color' => $payload['tmdb_thumbnail_bg_color'],
                'status' => $payload['status'],
                'post_type' => $payload['post_type'],
                'post_status' => $payload['post_status'],
                'author_id' => $payload['author_id'],
                'category_ids' => $payload['category_ids'],
                'default_category_id' => $payload['default_category_id'],
                'tags_default' => $payload['tags_default'],
                'custom_taxonomies' => $payload['custom_taxonomies'],
                'custom_meta' => $payload['custom_meta'],
                'internal_links_json' => $payload['internal_links_json'],
                'filters_json' => $payload['filters_json'],
                'model' => $payload['model'],
                'temperature' => $payload['temperature'],
                'max_tokens' => $payload['max_tokens'],
                'content_length_class' => $payload['content_length_class'],
                'posts_per_run' => $payload['posts_per_run'],
                'schedule_type' => $payload['schedule_type'],
                'interval_minutes' => $payload['interval_minutes'],
                'jitter_minutes' => $payload['jitter_minutes'],
                'daily_start' => $payload['daily_start'],
                'daily_end' => $payload['daily_end'],
                'image_source_mode' => $payload['image_source_mode'],
                'pexels_enabled' => $payload['pexels_enabled'],
                'pexels_query' => $payload['pexels_query'],
                'source_video_enabled' => $payload['source_video_enabled'],
                'source_content_images_enabled' => $payload['source_content_images_enabled'],
                'source_content_links_enabled' => $payload['source_content_links_enabled'],
                'video_selector_class' => $payload['video_selector_class'],
                'image_selector_class' => $payload['image_selector_class'],
                'link_selector_class' => $payload['link_selector_class'],
                'content_selector' => $payload['content_selector'],
                'content_image_size' => $payload['content_image_size'],
                'content_image_interval_words' => $payload['content_image_interval_words'],
                'random_bolds_enabled' => $payload['random_bolds_enabled'],
                'source_link_phrases' => $payload['source_link_phrases'],
                'source_context_filters_json' => $payload['source_context_filters_json'],
                'prompt_model_key' => $payload['prompt_model_key'],
                'prompt_models_json' => '',
                'outline_model_key' => $payload['outline_model_key'],
                'seo_enabled' => $payload['seo_enabled'],
                'generation_language' => $payload['generation_language'],
                'prompt_template' => $payload['prompt_template'],
                'content_prompt_template' => $payload['content_prompt_template'],
                'related_posts_enabled' => $payload['related_posts_enabled'],
                'related_posts_position' => $payload['related_posts_position'],
                'related_posts_interval' => $payload['related_posts_interval'],
                'related_posts_min_h2' => $payload['related_posts_min_h2'],
                'related_posts_links_per_block' => $payload['related_posts_links_per_block'],
                'related_posts_same_category_only' => $payload['related_posts_same_category_only'],
                'related_posts_allow_fallback' => $payload['related_posts_allow_fallback'],
                'related_posts_style' => $payload['related_posts_style'],
                'related_posts_phrases' => $payload['related_posts_phrases'],
                'internal_links_count' => $payload['internal_links_count'],
                'updated_at' => $now,
            );

            if ($generator_id > 0) {
                $wpdb->update(
                    self::$table_generators,
                    $data,
                    array('id' => $generator_id)
                );
                self::update_generator_schedule($generator_id);
                return $generator_id;
            }

            $data['created_at'] = $now;
            $data['next_run_at'] = null;
            $wpdb->insert(self::$table_generators, $data);

            $generator_id = intval($wpdb->insert_id);
            self::update_generator_schedule($generator_id);
            return $generator_id;
        }

        public static function delete_generator($id)
        {
            global $wpdb;
            $id = intval($id);
            $wpdb->delete(self::$table_items, array('generator_id' => $id), array('%d'));
            $wpdb->delete(self::$table_runs, array('generator_id' => $id), array('%d'));
            $wpdb->delete(self::$table_generators, array('id' => $id), array('%d'));
        }

        public static function duplicate_generator($id)
        {
            $generator = self::get_generator($id);
            if (!$generator) {
                return new WP_Error('content_rank_missing_generator', 'Gerador não encontrado.');
            }

            // A lista selecionada e uma fonte compartilhada. Duplicar o gerador
            // nao deve criar uma nova lista nem reiniciar suas linhas.
            $duplicated_list_id = !empty($generator['list_id']) ? intval($generator['list_id']) : 0;

            $duplicated = array(
                'name' => $generator['name'] . ' copy',
                'feed_url' => $generator['feed_url'],
                'source_type' => $generator['source_type'],
                'generation_mode' => isset($generator['generation_mode']) ? $generator['generation_mode'] : self::get_default_generation_mode(),
                'source_post_id' => isset($generator['source_post_id']) ? intval($generator['source_post_id']) : 0,
                'list_id' => $duplicated_list_id,
                'keyword_list_mode' => isset($generator['keyword_list_mode']) ? $generator['keyword_list_mode'] : self::get_default_keyword_list_mode(),
                'tavily_enabled' => !empty($generator['tavily_enabled']) ? 1 : 0,
                'tmdb_title_translation_enabled' => !empty($generator['tmdb_title_translation_enabled']) ? 1 : 0,
                'tmdb_thumbnail_bg_color' => isset($generator['tmdb_thumbnail_bg_color']) ? self::normalize_hex_color($generator['tmdb_thumbnail_bg_color']) : '#c91414',
                'status' => $generator['status'],
                'post_type' => $generator['post_type'],
                'post_status' => $generator['post_status'],
                'author_id' => $generator['author_id'],
                'category_ids' => implode(',', (array) json_decode((string) $generator['category_ids'], true)),
                'default_category_id' => isset($generator['default_category_id']) ? intval($generator['default_category_id']) : 0,
                'tags_default' => implode(',', (array) json_decode((string) $generator['tags_default'], true)),
                'custom_taxonomies' => self::array_to_key_value_lines(json_decode((string) $generator['custom_taxonomies'], true)),
                'custom_meta' => self::array_to_key_value_lines(json_decode((string) $generator['custom_meta'], true)),
                'internal_links_json' => isset($generator['internal_links_json']) ? $generator['internal_links_json'] : '',
                'filters_json' => $generator['filters_json'],
                'model' => $generator['model'],
                'temperature' => $generator['temperature'],
                'max_tokens' => $generator['max_tokens'],
                'content_length_class' => isset($generator['content_length_class']) ? $generator['content_length_class'] : self::get_default_content_length_class(),
                'posts_per_run' => $generator['posts_per_run'],
                'schedule_type' => $generator['schedule_type'],
                'interval_minutes' => $generator['interval_minutes'],
                'jitter_minutes' => $generator['jitter_minutes'],
                'daily_start' => $generator['daily_start'],
                'daily_end' => $generator['daily_end'],
                'image_source_mode' => isset($generator['image_source_mode']) ? $generator['image_source_mode'] : '',
                'pexels_enabled' => $generator['pexels_enabled'],
                'pexels_query' => $generator['pexels_query'],
                'source_video_enabled' => $generator['source_video_enabled'],
                'source_content_images_enabled' => isset($generator['source_content_images_enabled']) ? $generator['source_content_images_enabled'] : 1,
                'source_content_links_enabled' => isset($generator['source_content_links_enabled']) ? $generator['source_content_links_enabled'] : 1,
                'video_selector_class' => isset($generator['video_selector_class']) ? $generator['video_selector_class'] : '',
                'image_selector_class' => isset($generator['image_selector_class']) ? $generator['image_selector_class'] : '',
                'link_selector_class' => isset($generator['link_selector_class']) ? $generator['link_selector_class'] : '',
                'content_selector' => isset($generator['content_selector']) ? $generator['content_selector'] : '',
                'content_image_size' => isset($generator['content_image_size']) ? $generator['content_image_size'] : 'medium',
                'content_image_interval_words' => isset($generator['content_image_interval_words']) ? max(100, min(5000, intval($generator['content_image_interval_words']))) : 500,
                'random_bolds_enabled' => isset($generator['random_bolds_enabled']) ? intval($generator['random_bolds_enabled']) : 0,
                'source_link_phrases' => isset($generator['source_link_phrases']) ? $generator['source_link_phrases'] : '',
                'source_context_exclude_phrases' => isset($generator['source_context_exclude_phrases']) ? $generator['source_context_exclude_phrases'] : '',
                'source_context_rating_label' => isset($generator['source_context_rating_label']) ? $generator['source_context_rating_label'] : 'IMDb',
                'source_context_min_rating' => isset($generator['source_context_min_rating']) ? $generator['source_context_min_rating'] : '0',
                'source_context_keep_unrated' => isset($generator['source_context_keep_unrated']) ? $generator['source_context_keep_unrated'] : '0',
                'seo_enabled' => $generator['seo_enabled'],
                'generation_language' => $generator['generation_language'],
                'prompt_model_key' => isset($generator['prompt_model_key']) ? $generator['prompt_model_key'] : '',
                'prompt_template' => $generator['prompt_template'],
                'content_prompt_template' => isset($generator['content_prompt_template']) ? $generator['content_prompt_template'] : '',
                'prompt_models_json' => '',
                'outline_model_key' => isset($generator['outline_model_key']) ? $generator['outline_model_key'] : self::get_default_outline_model_key(),
                'related_posts_enabled' => isset($generator['related_posts_enabled']) ? $generator['related_posts_enabled'] : 0,
                'related_posts_position' => isset($generator['related_posts_position']) ? $generator['related_posts_position'] : 'end',
                'related_posts_interval' => isset($generator['related_posts_interval']) ? $generator['related_posts_interval'] : 4,
                'related_posts_min_h2' => isset($generator['related_posts_min_h2']) ? $generator['related_posts_min_h2'] : 1,
                'related_posts_links_per_block' => isset($generator['related_posts_links_per_block']) ? $generator['related_posts_links_per_block'] : 2,
                'related_posts_same_category_only' => isset($generator['related_posts_same_category_only']) ? $generator['related_posts_same_category_only'] : 1,
                'related_posts_allow_fallback' => isset($generator['related_posts_allow_fallback']) ? $generator['related_posts_allow_fallback'] : 1,
                'related_posts_style' => isset($generator['related_posts_style']) ? $generator['related_posts_style'] : 'list',
                'related_posts_phrases' => isset($generator['related_posts_phrases']) ? $generator['related_posts_phrases'] : '',
                'internal_links_count' => isset($generator['internal_links_count']) ? intval($generator['internal_links_count']) : 0,
            );

            return self::save_generator($duplicated);
        }

        public static function duplicate_keyword_list($list_id, $list_name = '')
        {
            global $wpdb;

            $list_id = intval($list_id);
            if ($list_id <= 0) {
                return new WP_Error('content_rank_keyword_list_invalid', 'Lista inválida.');
            }

            $source_list = self::get_keyword_list($list_id);
            if (!$source_list) {
                return new WP_Error('content_rank_keyword_list_missing', 'Lista não encontrada.');
            }

            $tables = self::bulk_tables();
            $now = current_time('mysql');
            $new_list_name = trim((string) $list_name);
            if ($new_list_name === '') {
                $new_list_name = !empty($source_list['list_name']) ? $source_list['list_name'] . ' copy' : 'Lista copy';
            }

            $inserted = $wpdb->insert(
                self::$table_lists,
                array(
                    'list_name' => sanitize_text_field($new_list_name),
                    'original_filename' => !empty($source_list['original_filename']) ? $source_list['original_filename'] : '',
                    'file_path' => isset($source_list['file_path']) ? $source_list['file_path'] : null,
                    'file_type' => !empty($source_list['file_type']) ? $source_list['file_type'] : '',
                    'headers_json' => !empty($source_list['headers_json']) ? $source_list['headers_json'] : null,
                    'column_map_json' => !empty($source_list['column_map_json']) ? $source_list['column_map_json'] : null,
                    'total_rows' => 0,
                    'generated_rows' => 0,
                    'pending_rows' => 0,
                    'invalid_rows' => 0,
                    'failed_rows' => 0,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s')
            );

            if ($inserted === false || empty($wpdb->insert_id)) {
                return new WP_Error('content_rank_keyword_list_duplicate_failed', 'Não foi possivel duplicar a lista.');
            }

            $new_list_id = intval($wpdb->insert_id);
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$tables['rows']} WHERE list_id = %d ORDER BY `row_number` ASC",
                $list_id
            ), ARRAY_A);

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                unset($row['id']);
                $row['list_id'] = $new_list_id;
                $row['row_status'] = 'pending';
                $row['post_id'] = null;
                $row['error_message'] = null;
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
                $row['processed_at'] = null;

                $wpdb->insert(
                    $tables['rows'],
                    $row,
                    array(
                        '%d',
                        '%d',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%d',
                        '%s',
                        '%d',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                    )
                );
            }

            self::bulk_refresh_list_counts($new_list_id);

            return $new_list_id;
        }

        public static function build_generator_export_payload($generator)
        {
            if (!is_array($generator)) {
                return array();
            }

            $export = $generator;
            unset(
                $export['id'],
                $export['created_at'],
                $export['updated_at'],
                $export['next_run_at'],
                $export['last_run_at'],
                $export['last_error_at']
            );

            $category_ids = array();
            if (isset($generator['category_ids'])) {
                $decoded_category_ids = json_decode((string) $generator['category_ids'], true);
                $category_ids = is_array($decoded_category_ids) ? $decoded_category_ids : self::parse_list_field($generator['category_ids']);
            }
            $tags_default = array();
            if (isset($generator['tags_default'])) {
                $decoded_tags = json_decode((string) $generator['tags_default'], true);
                $tags_default = is_array($decoded_tags) ? $decoded_tags : self::parse_list_field($generator['tags_default']);
            }
            $custom_taxonomies = array();
            if (isset($generator['custom_taxonomies'])) {
                $decoded_custom_taxonomies = json_decode((string) $generator['custom_taxonomies'], true);
                $custom_taxonomies = is_array($decoded_custom_taxonomies) ? $decoded_custom_taxonomies : array();
            }
            $custom_meta = array();
            if (isset($generator['custom_meta'])) {
                $decoded_custom_meta = json_decode((string) $generator['custom_meta'], true);
                $custom_meta = is_array($decoded_custom_meta) ? $decoded_custom_meta : array();
            }

            $export['category_ids'] = implode(',', array_filter(array_map('strval', (array) $category_ids)));
            $export['tags_default'] = implode(',', array_filter(array_map('strval', (array) $tags_default)));
            $export['custom_taxonomies'] = self::array_to_key_value_lines($custom_taxonomies);
            $export['custom_meta'] = self::array_to_key_value_lines($custom_meta);

            return $export;
        }

        public static function export_generator($id)
        {
            $generator = self::get_generator($id);
            if (!$generator) {
                return new WP_Error('content_rank_missing_generator', 'Gerador não encontrado.');
            }

            return array(
                'schema_version' => 1,
                'exported_at' => current_time('mysql'),
                'generator' => self::build_generator_export_payload($generator),
            );
        }

        public static function import_generators_from_payload($payload)
        {
            if (!is_array($payload)) {
                return new WP_Error('content_rank_invalid_import_payload', 'Payload inválido.');
            }

            $generators = array();
            if (!empty($payload['generators']) && is_array($payload['generators'])) {
                $generators = $payload['generators'];
            } elseif (!empty($payload['generator']) && is_array($payload['generator'])) {
                $generators = array($payload['generator']);
            } elseif (isset($payload['name']) || isset($payload['feed_url']) || isset($payload['source_type'])) {
                $generators = array($payload);
            }

            if (empty($generators)) {
                return new WP_Error('content_rank_invalid_import_payload', 'Nenhum gerador encontrado no arquivo.');
            }

            $imported = 0;
            $errors = array();
            foreach ($generators as $generator_data) {
                if (!is_array($generator_data)) {
                    continue;
                }
                unset(
                    $generator_data['id'],
                    $generator_data['created_at'],
                    $generator_data['updated_at'],
                    $generator_data['next_run_at'],
                    $generator_data['last_run_at'],
                    $generator_data['last_error_at'],
                    $generator_data['schema_version'],
                    $generator_data['exported_at']
                );
                $generator_data['generator_id'] = 0;

                $saved = self::save_generator($generator_data);
                if (is_wp_error($saved)) {
                    $errors[] = $saved->get_error_message();
                    continue;
                }
                $imported++;
            }

            return array(
                'imported' => $imported,
                'errors' => $errors,
            );
        }

        public function handle_save_settings()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }
            check_admin_referer('content_rank_save_settings', 'content_rank_settings_nonce');
            $settings = self::sanitize_settings($_POST);
            update_option(self::OPTION_KEY, $settings, false);
            self::redirect_with_notice('Configurações globais salvas com sucesso.', 'success', array(
                'page' => 'content-rank-global-settings',
            ));
        }

        public function handle_save_generator()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }
            check_admin_referer('content_rank_save_generator', 'content_rank_generator_nonce');
            $saved = self::save_generator($_POST);
            if (is_wp_error($saved)) {
                self::redirect_with_notice($saved->get_error_message(), 'error');
            }
            $generator_name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
            self::redirect_with_notice(sprintf('Gerador %s salvo com sucesso.', $generator_name));
        }

        public function handle_delete_generator()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }
            check_admin_referer('content_rank_delete_generator', 'content_rank_delete_nonce');
            $id = isset($_POST['generator_id']) ? intval($_POST['generator_id']) : 0;
            if ($id > 0) {
                self::delete_generator($id);
            }
            self::redirect_with_notice('Gerador excluido com sucesso.');
        }

        public function handle_duplicate_generator()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }
            check_admin_referer('content_rank_duplicate_generator', 'content_rank_duplicate_nonce');
            $id = isset($_POST['generator_id']) ? intval($_POST['generator_id']) : 0;
            $result = $id > 0 ? self::duplicate_generator($id) : new WP_Error('content_rank_invalid_generator', 'Gerador inválido.');
            if (is_wp_error($result)) {
                self::redirect_with_notice($result->get_error_message(), 'error');
            }
            self::redirect_with_notice('Gerador duplicado com sucesso.');
        }

        public function handle_export_generator()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }
            check_admin_referer('content_rank_export_generator', 'content_rank_export_generator_nonce');

            $id = isset($_POST['generator_id']) ? intval($_POST['generator_id']) : 0;
            $result = $id > 0 ? self::export_generator($id) : new WP_Error('content_rank_invalid_generator', 'Gerador inválido.');
            if (is_wp_error($result)) {
                self::redirect_with_notice($result->get_error_message(), 'error');
            }

            $generator = self::get_generator($id);
            $file_name = !empty($generator['name']) ? sanitize_file_name($generator['name']) : 'generator';
            $file_name = sanitize_file_name($file_name . '-export.json');
            if ($file_name === '-export.json') {
                $file_name = 'generator-export.json';
            }

            if (function_exists('ob_get_length') && ob_get_length()) {
                @ob_clean();
            }
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $file_name . '"');
            echo wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            exit;
        }

        public function handle_export_generators()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }
            check_admin_referer('content_rank_export_generators', 'content_rank_export_generators_nonce');

            $generators = self::get_generators(1000);
            $payload = array(
                'schema_version' => 1,
                'exported_at' => current_time('mysql'),
                'generators' => array(),
            );
            foreach ($generators as $generator) {
                $payload['generators'][] = self::build_generator_export_payload($generator);
            }

            if (function_exists('ob_get_length') && ob_get_length()) {
                @ob_clean();
            }
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="content-ranks-export.json"');
            echo wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            exit;
        }

        public function handle_import_generators()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }
            check_admin_referer('content_rank_import_generator', 'content_rank_import_generator_nonce');

            if (empty($_FILES['generator_json_file']) || !is_array($_FILES['generator_json_file'])) {
                self::redirect_with_notice('Envie um arquivo JSON.', 'error');
            }

            $uploaded_file = $_FILES['generator_json_file'];
            if (!empty($uploaded_file['error'])) {
                self::redirect_with_notice('Falha no upload do arquivo JSON.', 'error');
            }
            if (empty($uploaded_file['tmp_name']) || !is_uploaded_file($uploaded_file['tmp_name'])) {
                self::redirect_with_notice('Arquivo JSON inválido.', 'error');
            }

            $json = '';
            if (function_exists('file_get_contents')) {
                $json = (string) file_get_contents($uploaded_file['tmp_name']);
            } elseif (function_exists('wp_read_file')) {
                $json = (string) wp_read_file($uploaded_file['tmp_name']);
            }
            $json = trim($json);

            if ($json === '') {
                self::redirect_with_notice('O arquivo JSON está vazio.', 'error');
            }

            $decoded = json_decode($json, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                self::redirect_with_notice('JSON inválido.', 'error');
            }

            $result = self::import_generators_from_payload($decoded);
            if (is_wp_error($result)) {
                self::redirect_with_notice($result->get_error_message(), 'error');
            }

            $message = sprintf('%d gerador(es) importado(s) com sucesso.', intval($result['imported']));
            if (!empty($result['errors'])) {
                $message .= ' Alguns itens falharam: ' . implode(' | ', array_slice(array_map('sanitize_text_field', (array) $result['errors']), 0, 3));
            }

            self::redirect_with_notice($message);
        }

        public function handle_run_generator()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }
            check_admin_referer('content_rank_run_generator', 'content_rank_run_nonce');
            $id = isset($_POST['generator_id']) ? intval($_POST['generator_id']) : 0;
            $generator = $id > 0 ? self::get_generator($id) : null;
            if (!$generator) {
                self::redirect_with_notice('Gerador não encontrado.', 'error');
            }
            $item_guid = isset($_POST['item_guid']) ? sanitize_text_field(wp_unslash($_POST['item_guid'])) : '';
            if ($item_guid !== '') {
                $result = self::run_generator_item($generator, $item_guid);
                if (is_wp_error($result)) {
                    self::redirect_with_notice($result->get_error_message(), 'error');
                }
                $item_link = !empty($result['view_link']) ? $result['view_link'] : (!empty($result['permalink']) ? $result['permalink'] : (!empty($result['edit_link']) ? $result['edit_link'] : ''));
                self::redirect_with_notice('Item gerado com sucesso.', 'success', array(
                    'content_rank_notice_link' => $item_link,
                ));
            }

            $result = self::run_generator($generator, true);
            if (is_wp_error($result)) {
                self::redirect_with_notice($result->get_error_message(), 'error');
            }
            self::redirect_with_notice(sprintf('Execução do gerador concluída. Criados %d, ignorados %d, falharam %d.', $result['created'], $result['skipped'], $result['failed']));
        }

        public static function array_to_key_value_lines($data)
        {
            if (!is_array($data)) {
                return '';
            }
            $lines = array();
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $value = implode(',', $value);
                }
                $lines[] = $key . '=' . $value;
            }
            return implode("\n", $lines);
        }

        public static function shorten_log_value($value, $limit = 120)
        {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }
            $limit = max(20, intval($limit));
            if (function_exists('mb_strimwidth')) {
                return mb_strimwidth($value, 0, $limit, '...');
            }
            return strlen($value) > $limit ? substr($value, 0, $limit - 3) . '...' : $value;
        }

        public static function format_run_log_summary($row)
        {
            $parts = array();
            $payloads = array();

            foreach (array('request_json', 'response_json') as $field) {
                if (empty($row[$field])) {
                    continue;
                }
                $decoded = json_decode((string) $row[$field], true);
                if (!is_array($decoded)) {
                    continue;
                }
                foreach (array('request', 'response') as $section) {
                    if (!empty($decoded[$section]) && is_array($decoded[$section])) {
                        $payloads[] = $decoded[$section];
                    }
                }
            }

            foreach ($payloads as $payload) {
                foreach (array('post_id', 'item_guid', 'item_permalink', 'source_video_url', 'source_video_source', 'video_selector_class', 'has_source_video', 'has_raw_embed_html', 'embed_mode', 'embed_source', 'provider', 'current_length', 'updated_length') as $key) {
                    if (!isset($payload[$key]) || $payload[$key] === '' || $payload[$key] === null) {
                        continue;
                    }
                    $value = is_bool($payload[$key]) ? ($payload[$key] ? '1' : '0') : (string) $payload[$key];
                    if ($key === 'source_video_url' || strlen($value) > 100) {
                        $value = self::shorten_log_value($value, 100);
                    }
                    $parts[] = $key . '=' . $value;
                }
            }

            if (!empty($row['post_id'])) {
                $parts[] = 'post_id=' . intval($row['post_id']);
            }
            if (!empty($row['item_guid'])) {
                $parts[] = 'item_guid=' . self::shorten_log_value($row['item_guid'], 60);
            }
            if (!empty($row['item_permalink'])) {
                $parts[] = 'item_permalink=' . self::shorten_log_value($row['item_permalink'], 100);
            }

            $parts = array_values(array_unique(array_filter($parts)));
            if (empty($parts)) {
                return '';
            }
            return implode(' · ', array_slice($parts, 0, 4));
        }

        public static function get_recent_runs($limit = 30)
        {
            global $wpdb;
            $limit = max(1, intval($limit));
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::$table_runs . " ORDER BY created_at DESC LIMIT %d", $limit), ARRAY_A);
        }
    }
}

// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_fopen

register_activation_hook(__FILE__, array('Content_Rank_Generator', 'activate'));
register_deactivation_hook(__FILE__, array('Content_Rank_Generator', 'deactivate'));
add_action('plugins_loaded', function () {
    Content_Rank_Generator::instance()->boot();
});
