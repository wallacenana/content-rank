<?php

if (!defined('ABSPATH')) {
    exit;
}

class Content_Rank_Generator_REST
{
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REST endpoints are capability-protected via permission_callback and handle uploads through $_FILES.
    public function register_rest_routes()
    {
        register_rest_route('content-rank/v1', '/keyword-lists/preview', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_preview_keyword_list'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('content-rank/v1', '/keyword-lists', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'rest_get_keyword_lists'),
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'rest_upload_keyword_list'),
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ),
        ));

        register_rest_route('content-rank/v1', '/keyword-lists/manual', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_create_manual_keyword_list'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('content-rank/v1', '/keyword-lists/(?P<id>\d+)/manual', array(
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => array($this, 'rest_update_manual_keyword_list'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('content-rank/v1', '/keyword-lists/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array('Content_Rank_Generator', 'rest_get_keyword_list'),
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ),
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array(Content_Rank_Generator::instance(), 'rest_delete_keyword_list'),
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ),
        ));

        register_rest_route('content-rank/v1', '/keyword-lists/(?P<id>\d+)/columns', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(Content_Rank_Generator::instance(), 'rest_update_keyword_list_columns'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('content-rank/v1', '/keyword-lists/(?P<id>\d+)/generate', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_generate_keyword_list_item'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('content-rank/v1', '/generators/(?P<id>\d+)/items', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(Content_Rank_Generator::instance(), 'rest_get_generator_items'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => array(
                'id' => array(
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && intval($param) > 0;
                    },
                ),
                'limit' => array(
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && intval($param) > 0;
                    },
                ),
            ),
        ));

        register_rest_route('content-rank/v1', '/generators/(?P<id>\d+)/generate', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_generate_generator_item'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));
    }

    public function rest_preview_keyword_list(WP_REST_Request $request)
    {
        if (empty($_FILES['file'])) {
            return new WP_Error('content_rank_keyword_file_missing', 'Arquivo nao enviado', array('status' => 400));
        }

        $file = $_FILES['file'];
        if (!empty($file['error'])) {
            return new WP_Error('content_rank_keyword_upload_error', 'Nao foi possivel ler o arquivo enviado', array('status' => 400));
        }

        $tmp_path = !empty($file['tmp_name']) ? $file['tmp_name'] : '';
        if ($tmp_path === '' || !file_exists($tmp_path)) {
            return new WP_Error('content_rank_keyword_upload_error', 'Arquivo temporario nao encontrado', array('status' => 400));
        }

        try {
            $parsed = Content_Rank_Generator_Helper::bulk_parse_spreadsheet_file($tmp_path);
        } catch (Exception $e) {
            return new WP_Error('content_rank_keyword_parse_error', $e->getMessage(), array('status' => 400));
        }

        $headers = $parsed['headers'];
        $rows = $parsed['rows'];
        $column_map = Content_Rank_Generator::bulk_detect_column_map($headers);
        $preview_rows = array();
        $preview_limit = min(25, count($rows));

        for ($index = 0; $index < $preview_limit; $index++) {
            $preview_rows[] = Content_Rank_Generator::bulk_row_to_assoc($headers, $rows[$index]);
        }

        return rest_ensure_response(array(
            'success' => true,
            'file' => array(
                'name' => !empty($file['name']) ? sanitize_file_name(wp_unslash($file['name'])) : '',
                'size' => !empty($file['size']) ? intval($file['size']) : 0,
                'type' => !empty($file['type']) ? sanitize_text_field($file['type']) : '',
            ),
            'headers' => $headers,
            'rows' => $preview_rows,
            'row_count' => count($rows),
            'detected_column_map' => $column_map,
        ));
    }
    public function rest_upload_keyword_list(WP_REST_Request $request)
    {
        global $wpdb;

        if (empty($_FILES['file'])) {
            return new WP_Error('content_rank_keyword_file_missing', 'Arquivo nao enviado', array('status' => 400));
        }

        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $upload = wp_handle_upload($_FILES['file'], array(
            'test_form' => false,
            'mimes' => array(
                'csv' => 'text/csv',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ),
        ));

        if (!empty($upload['error'])) {
            return new WP_Error('content_rank_keyword_upload_error', $upload['error'], array('status' => 400));
        }

        $file_path = $upload['file'];
        $original_filename = !empty($_FILES['file']['name']) ? sanitize_file_name(wp_unslash($_FILES['file']['name'])) : basename($file_path);
        $list_name = sanitize_text_field($request->get_param('list_name'));
        if (empty($list_name)) {
            $list_name = pathinfo($original_filename, PATHINFO_FILENAME);
        }

        try {
            $parsed = Content_Rank_Generator_Helper::bulk_parse_spreadsheet_file($file_path);
        } catch (Exception $e) {
            return new WP_Error('content_rank_keyword_parse_error', $e->getMessage(), array('status' => 400));
        }

        $headers = $parsed['headers'];
        $rows = $parsed['rows'];
        $column_map = array();
        $column_map_raw = $request->get_param('column_map');
        if (is_string($column_map_raw) && trim($column_map_raw) !== '') {
            $decoded_map = json_decode(wp_unslash($column_map_raw), true);
            if (is_array($decoded_map)) {
                $column_map = $decoded_map;
            }
        } elseif (is_array($column_map_raw)) {
            $column_map = $column_map_raw;
        }
        if (empty($column_map)) {
            $column_map = Content_Rank_Generator::bulk_detect_column_map($headers);
        }

        if (empty($column_map['keyword_column'])) {
            return new WP_Error('content_rank_keyword_missing_column', 'Selecione uma coluna de palavra-chave.', array('status' => 400));
        }
        $wpdb->insert(
            Content_Rank_Generator::$table_lists,
            array(
                'list_name' => $list_name,
                'original_filename' => $original_filename,
                'file_path' => $file_path,
                'file_type' => strtolower(pathinfo($original_filename, PATHINFO_EXTENSION)),
                'headers_json' => wp_json_encode($headers),
                'column_map_json' => wp_json_encode($column_map),
                'total_rows' => 0,
                'generated_rows' => 0,
                'pending_rows' => 0,
                'invalid_rows' => 0,
                'failed_rows' => 0,
                'status' => 'active',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s')
        );

        $list_id = intval($wpdb->insert_id);
        if (!$list_id) {
            return new WP_Error('content_rank_keyword_insert_error', 'Nao foi possivel criar a lista', array('status' => 500));
        }

        $inserted_rows = 0;
        $invalid_rows = 0;
        $duplicate_rows = 0;
        $import_logs = array();
        $import_log_limit = 40;
        $row_number = 1;
        $seen_canonical_urls = array();
        $seen_final_slugs = array();

        foreach ($rows as $row_values) {
            $row_data = Content_Rank_Generator::bulk_row_to_assoc($headers, $row_values);
            if (empty(array_filter($row_data, function ($value) {
                return $value !== '';
            }))) {
                $row_number++;
                continue;
            }

            $resolved = Content_Rank_Generator_Helper::bulk_resolve_keyword_row($row_data, $column_map);
            $keyword = $resolved['keyword'];
            $source_title = $resolved['source_title'];
            $source_url_candidate = $resolved['source_url_candidate'];
            $slug_info_valid = !empty($resolved['slug_is_valid']);
            $canonical_source_url = $resolved['canonical_source_url'];
            $final_slug_key = $resolved['slug_key'];

            if ($canonical_source_url !== '' && isset($seen_canonical_urls[$canonical_source_url])) {
                $duplicate_rows++;
                $log_entry = array(
                    'row_number' => $row_number,
                    'level' => 'warning',
                    'code' => 'duplicate_url',
                    'message' => 'Linha duplicada por URL',
                    'keyword' => $keyword,
                    'source_url' => $source_url_candidate,
                    'final_slug' => $resolved['final_slug'],
                );
                Content_Rank_Generator::insert_import_log($list_id, $row_number, $log_entry['level'], $log_entry['code'], $log_entry['message'], $row_data, array(
                    'keyword' => $keyword,
                    'source_url' => $source_url_candidate,
                    'final_slug' => $resolved['final_slug'],
                ));
                if (count($import_logs) < $import_log_limit) {
                    $import_logs[] = $log_entry;
                }
                $row_number++;
                continue;
            }
            if ($final_slug_key !== '' && isset($seen_final_slugs[$final_slug_key])) {
                $duplicate_rows++;
                $log_entry = array(
                    'row_number' => $row_number,
                    'level' => 'warning',
                    'code' => 'duplicate_slug',
                    'message' => 'Linha duplicada por slug',
                    'keyword' => $keyword,
                    'source_url' => $source_url_candidate,
                    'final_slug' => $resolved['final_slug'],
                );
                Content_Rank_Generator::insert_import_log($list_id, $row_number, $log_entry['level'], $log_entry['code'], $log_entry['message'], $row_data, array(
                    'keyword' => $keyword,
                    'source_url' => $source_url_candidate,
                    'final_slug' => $resolved['final_slug'],
                ));
                if (count($import_logs) < $import_log_limit) {
                    $import_logs[] = $log_entry;
                }
                $row_number++;
                continue;
            }

            if ($resolved['row_status'] !== 'pending') {
                $invalid_rows++;
                $log_code = 'invalid_slug';
                $log_message = !empty($resolved['error_message']) ? $resolved['error_message'] : 'Linha invalida';
                if ($keyword === '') {
                    $log_code = 'invalid_keyword';
                    $log_message = 'Keyword vazia';
                } elseif (!empty($resolved['slug_extension'])) {
                    $log_code = 'invalid_slug_extension';
                } elseif ($source_url_candidate === '') {
                    $log_code = 'missing_url';
                    $log_message = 'URL ou slug nao informado';
                }
                $log_entry = array(
                    'row_number' => $row_number,
                    'level' => 'error',
                    'code' => $log_code,
                    'message' => $log_message,
                    'keyword' => $keyword,
                    'source_url' => $source_url_candidate,
                    'final_slug' => $resolved['final_slug'],
                );
                Content_Rank_Generator::insert_import_log($list_id, $row_number, $log_entry['level'], $log_entry['code'], $log_entry['message'], $row_data, array(
                    'keyword' => $keyword,
                    'source_url' => $source_url_candidate,
                    'final_slug' => $resolved['final_slug'],
                    'slug_extension' => $resolved['slug_extension'],
                ));
                if (count($import_logs) < $import_log_limit) {
                    $import_logs[] = $log_entry;
                }
                $row_number++;
                continue;
            }

            $wpdb->insert(
                Content_Rank_Generator::$table_list_rows,
                array(
                    'list_id' => $list_id,
                    'row_number' => $row_number,
                    'row_data' => wp_json_encode($row_data),
                    'keyword' => $keyword,
                    'source_title' => $source_title,
                    'source_url' => $resolved['source_url'] ?: $source_url_candidate,
                    'final_slug' => $resolved['final_slug'],
                    'slug_extension' => $resolved['slug_extension'],
                    'slug_is_valid' => $slug_info_valid ? 1 : 0,
                    'row_status' => $resolved['row_status'],
                    'post_id' => null,
                    'error_message' => $resolved['error_message'],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                    'processed_at' => null,
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s')
            );

            $inserted_rows++;
            if ($canonical_source_url !== '') {
                $seen_canonical_urls[$canonical_source_url] = true;
            }
            if ($final_slug_key !== '') {
                $seen_final_slugs[$final_slug_key] = true;
            }
            $row_number++;
        }

        if ($inserted_rows <= 0) {
            $wpdb->delete(Content_Rank_Generator::$table_lists, array('id' => $list_id), array('%d'));
            if (!empty($file_path) && file_exists($file_path)) {
                wp_delete_file($file_path);
            }

            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Nenhuma linha valida para importar.',
                'invalid_rows' => $invalid_rows,
                'duplicate_rows' => $duplicate_rows,
                'logs' => $import_logs,
            ), 400);
        }

        Content_Rank_Generator::bulk_refresh_list_counts($list_id);
        $list = Content_Rank_Generator::get_keyword_list($list_id);
        $counts = Content_Rank_Generator::bulk_get_list_counts($list_id);

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Lista criada com sucesso',
            'list' => array(
                'id' => $list_id,
                'list_name' => $list_name,
                'original_filename' => $original_filename,
                'headers' => $headers,
                'column_map' => $column_map,
                'counts' => $counts,
                'inserted_rows' => $inserted_rows,
                'invalid_rows' => $invalid_rows,
                'duplicate_rows' => $duplicate_rows,
                'logs' => $import_logs,
            ),
        ));
    }

    public function rest_create_manual_keyword_list(WP_REST_Request $request)
    {
        global $wpdb;

        $list_name = sanitize_text_field($request->get_param('list_name'));
        $keywords_raw = $request->get_param('keywords');
        if (is_array($keywords_raw)) {
            $keywords_raw = implode("\n", $keywords_raw);
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) wp_unslash($keywords_raw));
        $keywords = array();
        foreach ($lines as $line) {
            $keyword = sanitize_text_field(trim((string) $line));
            if ($keyword === '') {
                continue;
            }
            // Repeated keywords are intentional: each row can target a different
            // search intent and must remain an independent generation.
            $keywords[] = $keyword;
        }

        if ($list_name === '') {
            return new WP_Error('content_rank_keyword_list_name_missing', 'Informe o nome da lista.', array('status' => 400));
        }
        if (empty($keywords)) {
            return new WP_Error('content_rank_keyword_list_empty', 'Informe ao menos uma palavra-chave.', array('status' => 400));
        }

        $now = current_time('mysql');
        $inserted = $wpdb->insert(
            Content_Rank_Generator::$table_lists,
            array(
                'list_name' => $list_name,
                'original_filename' => 'Cadastro manual',
                'file_path' => null,
                'file_type' => 'keyword_list',
                'headers_json' => wp_json_encode(array('keyword')),
                'column_map_json' => wp_json_encode(array(
                    'keyword_column' => 'keyword',
                    'source_title_column' => '',
                    'source_url_column' => '',
                    'slug_column' => '',
                    'content_column' => '',
                    'tags_column' => '',
                )),
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
            return new WP_Error('content_rank_keyword_list_insert_failed', 'Nao foi possivel criar a lista.', array('status' => 500));
        }

        $list_id = intval($wpdb->insert_id);
        foreach ($keywords as $index => $keyword) {
            $wpdb->insert(
                Content_Rank_Generator::$table_list_rows,
                array(
                    'list_id' => $list_id,
                    'row_number' => $index + 1,
                    'row_data' => wp_json_encode(array('keyword' => $keyword)),
                    'keyword' => $keyword,
                    'source_title' => '',
                    'source_url' => '',
                    'final_slug' => '',
                    'slug_extension' => '',
                    'slug_is_valid' => 1,
                    'row_status' => 'pending',
                    'post_id' => null,
                    'error_message' => '',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'processed_at' => null,
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s')
            );
        }

        Content_Rank_Generator::bulk_refresh_list_counts($list_id);
        $list = Content_Rank_Generator::get_keyword_list($list_id);

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Keyword list criada com sucesso.',
            'list' => $list,
            'counts' => Content_Rank_Generator::bulk_get_list_counts($list_id),
        ));
    }

    public function rest_update_manual_keyword_list(WP_REST_Request $request)
    {
        global $wpdb;

        $list_id = intval($request->get_param('id'));
        $list = Content_Rank_Generator::get_keyword_list($list_id);
        if (!$list || sanitize_key((string) $list['file_type']) !== 'keyword_list') {
            return new WP_Error('content_rank_manual_keyword_list_missing', 'Keyword list nao encontrada.', array('status' => 404));
        }

        $list_name = sanitize_text_field($request->get_param('list_name'));
        $keywords_raw = $request->get_param('keywords');
        if (is_array($keywords_raw)) {
            $keywords_raw = implode("\n", $keywords_raw);
        }

        $keywords = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) wp_unslash($keywords_raw)) as $line) {
            $keyword = sanitize_text_field(trim((string) $line));
            if ($keyword === '') {
                continue;
            }
            // Do not collapse duplicates. A repeated keyword is a new editorial
            // brief, not an accidental duplicate row.
            $keywords[] = $keyword;
        }

        if ($list_name === '') {
            return new WP_Error('content_rank_manual_keyword_list_invalid', 'Informe o nome da lista.', array('status' => 400));
        }

        $tables = Content_Rank_Generator::bulk_tables();
        $has_existing_rows = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tables['rows']} WHERE list_id = %d",
            $list_id
        )) > 0;

        if (empty($keywords) && !$has_existing_rows) {
            return new WP_Error('content_rank_manual_keyword_list_empty', 'Informe ao menos uma keyword.', array('status' => 400));
        }

        $now = current_time('mysql');
        $wpdb->update(
            Content_Rank_Generator::$table_lists,
            array('list_name' => $list_name, 'updated_at' => $now),
            array('id' => $list_id),
            array('%s', '%s'),
            array('%d')
        );
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$tables['rows']} WHERE list_id = %d AND row_status <> 'generated'",
            $list_id
        ));

        $next_row_number = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(row_number), 0) FROM {$tables['rows']} WHERE list_id = %d",
            $list_id
        ));
        foreach ($keywords as $index => $keyword) {
            $next_row_number++;
            $wpdb->insert(
                Content_Rank_Generator::$table_list_rows,
                array(
                    'list_id' => $list_id,
                    'row_number' => $next_row_number,
                    'row_data' => wp_json_encode(array('keyword' => $keyword)),
                    'keyword' => $keyword,
                    'source_title' => '',
                    'source_url' => '',
                    'final_slug' => '',
                    'slug_extension' => '',
                    'slug_is_valid' => 1,
                    'row_status' => 'pending',
                    'post_id' => null,
                    'error_message' => '',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'processed_at' => null,
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s')
            );
        }

        Content_Rank_Generator::bulk_refresh_list_counts($list_id);
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Keyword list atualizada com sucesso.',
            'list' => Content_Rank_Generator::get_keyword_list($list_id),
            'counts' => Content_Rank_Generator::bulk_get_list_counts($list_id),
        ));
    }

    public function rest_get_keyword_lists(WP_REST_Request $request)
    {
        $lists = Content_Rank_Generator::get_keyword_lists(200);
        foreach ($lists as &$list) {
            $list['counts'] = Content_Rank_Generator::bulk_get_list_counts(intval($list['id']));
        }
        unset($list);

        return rest_ensure_response(array(
            'success' => true,
            'items' => $lists,
        ));
    }

    public function rest_generate_keyword_list_item(WP_REST_Request $request)
    {
        global $wpdb;

        $list_id = intval($request->get_param('id'));
        if (!$list_id) {
            return new WP_Error('content_rank_keyword_list_invalid', 'Lista invalida', array('status' => 400));
        }

        $tables = Content_Rank_Generator::bulk_tables();
        $list = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $tables['lists'] . ' WHERE id = %d', $list_id), ARRAY_A);
        if (!$list) {
            return new WP_Error('content_rank_keyword_list_missing', 'Lista nao encontrada', array('status' => 404));
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $payload = array();
        }

        $filters = isset($payload['filters']) ? $payload['filters'] : array();
        if (is_string($filters) && trim($filters) !== '') {
            $decoded_filters = json_decode(wp_unslash($filters), true);
            if (is_array($decoded_filters)) {
                $filters = $decoded_filters;
            }
        }
        if (!is_array($filters)) {
            $filters = array();
        }

        $settings = isset($payload['settings']) ? $payload['settings'] : array();
        if (is_string($settings) && trim($settings) !== '') {
            $decoded_settings = json_decode(wp_unslash($settings), true);
            if (is_array($decoded_settings)) {
                $settings = $decoded_settings;
            }
        }
        if (!is_array($settings)) {
            $settings = array();
        }

        $temp_generator = Content_Rank_Generator::bulk_build_manual_generator($list, $settings);
        if (!Content_Rank_Generator::generator_uses_keyword_list_url_reference_mode($temp_generator)) {
            $temp_generator['image_source_mode'] = 'pexels';
        }
        $temp_generator = Content_Rank_Generator::prepare_generator_record($temp_generator);
        $source_context_filters = Content_Rank_Generator::get_generator_source_context_filters($temp_generator);

        $preview_mode = !empty($payload['preview']);
        if ($preview_mode) {
            $available_count = Content_Rank_Generator::bulk_count_matching_keyword_rows($list_id, $filters, $source_context_filters);
            $counts = Content_Rank_Generator::bulk_get_list_counts($list_id);

            return rest_ensure_response(array(
                'success' => true,
                'preview' => true,
                'available_count' => $available_count,
                'counts' => $counts,
                'filters' => $filters,
            ));
        }

        $selected_row = Content_Rank_Generator::bulk_find_next_keyword_row($list_id, $filters, $source_context_filters);
        if (!$selected_row) {
            $counts = Content_Rank_Generator::bulk_refresh_list_counts($list_id);
            return rest_ensure_response(array(
                'success' => true,
                'done' => true,
                'message' => 'Nao ha mais itens elegiveis para gerar',
                'counts' => $counts,
            ));
        }

        $wpdb->update(
            $tables['rows'],
            array(
                'row_status' => 'processing',
                'updated_at' => current_time('mysql'),
            ),
            array('id' => intval($selected_row->id)),
            array('%s', '%s'),
            array('%d')
        );

        $selected_item = Content_Rank_Generator::build_keyword_list_item_from_row(
            $list,
            $selected_row,
            false,
            !empty($temp_generator['video_selector_class']) ? sanitize_text_field((string) $temp_generator['video_selector_class']) : '',
            !empty($temp_generator['image_selector_class']) ? sanitize_text_field((string) $temp_generator['image_selector_class']) : '',
            !empty($temp_generator['link_selector_class']) ? sanitize_text_field((string) $temp_generator['link_selector_class']) : ''
        );

        // Webhooks should be able to acknowledge the request immediately and
        // let the staged worker perform the slow external calls in the
        // background. The existing synchronous behavior remains the default
        // for the admin panel and older integrations.
        $async_requested = !empty($payload['async']) || !empty($payload['queue']);
        if ($async_requested) {
            $queued_result = Content_Rank_Generator::queue_staged_generation($temp_generator, $selected_item);
            if (is_wp_error($queued_result)) {
                $wpdb->update(
                    $tables['rows'],
                    array(
                        'row_status' => 'failed',
                        'error_message' => $queued_result->get_error_message(),
                        'updated_at' => current_time('mysql'),
                    ),
                    array('id' => intval($selected_row->id)),
                    array('%s', '%s', '%s'),
                    array('%d')
                );

                return new WP_REST_Response(array(
                    'success' => false,
                    'queued' => false,
                    'message' => $queued_result->get_error_message(),
                    'code' => $queued_result->get_error_code(),
                ), 400);
            }

            $queued_post_id = is_array($queued_result) && !empty($queued_result['post_id'])
                ? intval($queued_result['post_id'])
                : intval($queued_result);
            $wpdb->update(
                $tables['rows'],
                array(
                    'row_status' => 'processing',
                    'post_id' => $queued_post_id,
                    'error_message' => '',
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => intval($selected_row->id)),
                array('%s', '%d', '%s', '%s'),
                array('%d')
            );

            return new WP_REST_Response(array(
                'success' => true,
                'queued' => true,
                'done' => false,
                'post_id' => $queued_post_id,
                'item_guid' => $selected_item['guid'],
                'item_title' => !empty($selected_item['source_title']) ? $selected_item['source_title'] : $selected_item['title'],
                'message' => 'Item enfileirado para geracao em segundo plano.',
            ), 202);
        }

        if (!Content_Rank_Generator::claim_item_processing_slot($temp_generator['id'], $selected_item)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Item já estava em processamento.',
            ), 409);
        }

        try {
            $result = Content_Rank_Generator::create_post_from_generator_item($temp_generator, $selected_item);
            if (is_wp_error($result)) {
                Content_Rank_Generator::mark_item_failed($temp_generator['id'], $selected_item, $result->get_error_code(), $result->get_error_message());
                $wpdb->update(
                    $tables['rows'],
                    array(
                        'row_status' => 'failed',
                        'error_message' => $result->get_error_message(),
                        'updated_at' => current_time('mysql'),
                    ),
                    array('id' => intval($selected_row->id)),
                    array('%s', '%s', '%s'),
                    array('%d')
                );

                Content_Rank_Generator::insert_run_log(0, 'error', $result->get_error_message(), array(
                    'request' => array('list_id' => $list_id, 'row_id' => intval($selected_row->id), 'filters' => $filters, 'settings' => $settings),
                ), null, $selected_item['guid'], $selected_item['permalink']);

                $counts = Content_Rank_Generator::bulk_refresh_list_counts($list_id);
                return rest_ensure_response(array(
                    'success' => false,
                    'done' => false,
                    'message' => $result->get_error_message(),
                    'counts' => $counts,
                ));
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
                array('id' => intval($selected_row->id)),
                array('%s', '%d', '%s', '%s', '%s'),
                array('%d')
            );

            $counts = Content_Rank_Generator::bulk_refresh_list_counts($list_id);
            $post_view_link = Content_Rank_Generator::get_post_view_link(intval($result));
            $post_edit_link = Content_Rank_Generator::get_post_edit_link(intval($result));

            return rest_ensure_response(array(
                'success' => true,
                'done' => false,
                'result' => array(
                    'success' => true,
                    'post_id' => intval($result),
                    'title' => isset($selected_item['source_title']) && $selected_item['source_title'] !== '' ? $selected_item['source_title'] : $selected_item['title'],
                    'final_slug' => isset($selected_item['final_slug']) ? $selected_item['final_slug'] : '',
                    'guid' => $selected_item['guid'],
                    'view_link' => $post_view_link ? $post_view_link : '',
                    'permalink' => $post_view_link ? $post_view_link : '',
                    'edit_link' => $post_edit_link ? $post_edit_link : '',
                ),
                'counts' => $counts,
            ));
        } catch (Exception $e) {
            $wpdb->update(
                $tables['rows'],
                array(
                    'row_status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => intval($selected_row->id)),
                array('%s', '%s', '%s'),
                array('%d')
            );

            Content_Rank_Generator::insert_run_log(0, 'error', $e->getMessage(), array(
                'request' => array('list_id' => $list_id, 'row_id' => intval($selected_row->id), 'filters' => $filters, 'settings' => $settings),
            ), null, $selected_item['guid'], $selected_item['permalink']);

            $counts = Content_Rank_Generator::bulk_refresh_list_counts($list_id);

            return rest_ensure_response(array(
                'success' => false,
                'done' => false,
                'message' => $e->getMessage(),
                'counts' => $counts,
            ));
        }
    }

    public function rest_generate_generator_item(WP_REST_Request $request)
    {
        $generator_id = intval($request->get_param('id'));
        if (!$generator_id) {
            return new WP_Error('content_rank_generator_invalid', 'Gerador invalido', array('status' => 400));
        }

        $generator = Content_Rank_Generator::get_generator($generator_id);
        if (!$generator) {
            return new WP_Error('content_rank_generator_missing', 'Gerador nao encontrado', array('status' => 404));
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $payload = array();
        }

        $item_guid = '';
        if (isset($payload['item_guid'])) {
            $item_guid = sanitize_text_field(wp_unslash((string) $payload['item_guid']));
        } elseif ($request->get_param('item_guid') !== null) {
            $item_guid = sanitize_text_field(wp_unslash((string) $request->get_param('item_guid')));
        }

        if ($item_guid === '') {
            return new WP_Error('content_rank_item_missing', 'Nenhum item foi selecionado.', array('status' => 400));
        }

        $result = Content_Rank_Generator::run_generator_item($generator, $item_guid);
        if (is_wp_error($result)) {
            $status = 400;
            $error_data = $result->get_error_data();
            if (is_array($error_data) && !empty($error_data['status'])) {
                $status = intval($error_data['status']);
            }

            return new WP_REST_Response(array(
                'success' => false,
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code(),
            ), $status);
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Item gerado com sucesso.',
            'post_id' => intval($result['post_id']),
            'item_guid' => isset($result['item_guid']) ? $result['item_guid'] : $item_guid,
            'item_title' => isset($result['item_title']) ? $result['item_title'] : '',
            'view_link' => isset($result['view_link']) ? $result['view_link'] : '',
            'edit_link' => isset($result['edit_link']) ? $result['edit_link'] : '',
            'permalink' => isset($result['permalink']) ? $result['permalink'] : '',
        ));
    }
}
