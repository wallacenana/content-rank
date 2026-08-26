<?php

if (!defined('ABSPATH')) {
    exit;
}

class Content_Rank_Generator_Helper
{
    public static function fetch_source_page_html_result($url, $cache_ttl = 5, $log_prefix = 'page_context')
    {
        $url = esc_url_raw(trim((string) $url));
        if ($url === '') {
            return array(
                'html' => '',
                'requested_url' => '',
                'resolved_url' => '',
                'status_code' => 0,
                'blocked' => false,
                'error_code' => '',
                'error_message' => '',
            );
        }

        $requested_url = $url;
        if (class_exists('Content_Rank_Generator') && method_exists('Content_Rank_Generator', 'resolve_google_alerts_redirect_url')) {
            $resolved_url = Content_Rank_Generator::resolve_google_alerts_redirect_url($url);
            if (!empty($resolved_url)) {
                $url = esc_url_raw(trim((string) $resolved_url));
            }
        }

        $cache_ttl = max(1, intval($cache_ttl));
        $cache_key = 'content_rank_source_html_' . md5($url);
        $day_cache_key = 'content_rank_source_html_day_' . md5($url);
        $blocked_key = 'content_rank_source_html_blocked_' . md5($url);

        $blocked_until = get_transient($blocked_key);
        if (!empty($blocked_until) && intval($blocked_until) > time()) {
            return array(
                'html' => '',
                'requested_url' => $requested_url,
                'resolved_url' => $url,
                'status_code' => 403,
                'blocked' => true,
                'error_code' => 'content_rank_source_forbidden',
                'error_message' => 'A fonte retornou 403 e o acesso ficou bloqueado temporariamente.',
            );
        }

        $cached_day_html = get_transient($day_cache_key);
        if (is_string($cached_day_html) && $cached_day_html !== '') {
            return array(
                'html' => $cached_day_html,
                'requested_url' => $requested_url,
                'resolved_url' => $url,
                'status_code' => 200,
                'blocked' => false,
                'error_code' => '',
                'error_message' => '',
            );
        }

        $cached_html = get_transient($cache_key);
        if (is_string($cached_html) && $cached_html !== '') {
            return array(
                'html' => $cached_html,
                'requested_url' => $requested_url,
                'resolved_url' => $url,
                'status_code' => 200,
                'blocked' => false,
                'error_code' => '',
                'error_message' => '',
            );
        }

        $request_args = array(
            'timeout' => 25,
            'redirection' => 4,
            'httpversion' => '1.1',
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'Upgrade-Insecure-Requests' => '1',
                'Referer' => $url,
            ),
        );

        $response = wp_remote_get($url, $request_args);
        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        if ($code === 403) {
            $request_args['headers']['User-Agent'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';
            $request_args['headers']['Accept-Encoding'] = 'identity';
            $response = wp_remote_get($url, $request_args);
            $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        }

        if (is_wp_error($response)) {
            return array(
                'html' => '',
                'requested_url' => $requested_url,
                'resolved_url' => $url,
                'status_code' => 0,
                'blocked' => false,
                'error_code' => $response->get_error_code(),
                'error_message' => $response->get_error_message(),
            );
        }

        if ($code < 200 || $code >= 300) {
            if (in_array($code, array(402, 403), true) && class_exists('Content_Rank_Global_Filters')) {
                Content_Rank_Global_Filters::add_source_from_http_status($url, $code);
            }
            if ($code === 403) {
                set_transient($blocked_key, time() + 300, 300);
            }
            return array(
                'html' => '',
                'requested_url' => $requested_url,
                'resolved_url' => $url,
                'status_code' => $code,
                'blocked' => in_array($code, array(402, 403), true),
                'error_code' => in_array($code, array(402, 403), true) ? 'content_rank_source_blocked' : 'content_rank_source_http_error',
                'error_message' => in_array($code, array(402, 403), true) ? 'A fonte retornou HTTP ' . $code . ' e foi adicionada a blacklist global.' : 'A fonte retornou um status HTTP inesperado.',
            );
        }

        $html = (string) wp_remote_retrieve_body($response);
        if ($html === '') {
            return array(
                'html' => '',
                'requested_url' => $requested_url,
                'resolved_url' => $url,
                'status_code' => $code,
                'blocked' => false,
                'error_code' => 'content_rank_source_empty',
                'error_message' => 'A fonte respondeu sem conteÃƒÂºdo.',
            );
        }

        set_transient($cache_key, $html, $cache_ttl);
        set_transient($day_cache_key, $html, DAY_IN_SECONDS);

        return array(
            'html' => $html,
            'requested_url' => $requested_url,
            'resolved_url' => $url,
            'status_code' => $code,
            'blocked' => false,
            'error_code' => '',
            'error_message' => '',
        );
    }

    public static function fetch_source_page_html($url, $cache_ttl = 5, $log_prefix = 'page_context')
    {
        $result = self::fetch_source_page_html_result($url, $cache_ttl, $log_prefix);
        return is_array($result) && isset($result['html']) ? (string) $result['html'] : '';
    }

    public static function extract_video_from_raw_source_html($html, $base_url = '')
    {
        $result = array(
            'video_url' => '',
            'video_embed_html' => '',
            'video_source' => '',
        );
        $html = (string) $html;
        if ($html === '') {
            return $result;
        }

        $candidate = Content_Rank_Generator::extract_video_candidate_from_html($html, $base_url, '');
        if (is_array($candidate)) {
            foreach (array('video_url', 'video_embed_html', 'video_source') as $key) {
                if (!empty($candidate[$key])) {
                    $result[$key] = $candidate[$key];
                }
            }
        }

        if ($result['video_url'] === '') {
            foreach (array('og:video', 'og:video:url', 'twitter:player:stream') as $key) {
                if (!preg_match('/<meta[^>]+(?:property|name|itemprop)=["\']' . preg_quote($key, '/') . '["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
                    continue;
                }
                $candidate_url = self::resolve_url_against_base(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $base_url);
                if ($candidate_url !== '' && Content_Rank_Generator::is_video_embed_url($candidate_url)) {
                    $result['video_url'] = $candidate_url;
                    $result['video_source'] = $key;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Extract videos in the source order, associating each one with its H2.
     * The section index is intentionally independent from the generated title.
     */
    public static function extract_video_sections_from_raw_source_html($html, $base_url = '', $content_selector = '')
    {
        $html = (string) $html;
        if ($html === '' || !class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            return array();
        }

        $source_html = $html;
        $content_selector = trim((string) $content_selector);
        if ($content_selector !== '') {
            $selected_html = self::extract_html_from_html_with_fallbacks($html, $content_selector);
            if ($selected_html !== '') {
                $source_html = $selected_html;
            }
        } else {
            $source_html = self::strip_source_page_noise_from_html($html);
        }

        if (trim($source_html) === '') {
            return array();
        }

        $previous_libxml_state = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8">' . $source_html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_libxml_state);
        if (!$loaded) {
            return array();
        }

        $xpath = new DOMXPath($dom);
        $h2_nodes = $xpath->query('//h2');
        $heading_tag = ($h2_nodes && $h2_nodes->length > 0) ? 'h2' : 'h3';
        $sections = array();
        $current_section_index = -1;
        $seen_video_urls = array();
        $nodes = $xpath->query('//*');
        if (!$nodes) {
            return array();
        }

        $video_attributes = array(
            'src',
            'data-src',
            'data-lazy-src',
            'data-video-url',
            'data-video',
            'data-embed-url',
            'data-oembed-url',
            'data-url',
            'data-player-url',
            'data-youtube-url',
        );

        foreach ($nodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }

            $tag = strtolower((string) $node->nodeName);
            if ($tag === $heading_tag) {
                $title = self::clean_source_text($node->textContent);
                if ($title === '' || self::is_auxiliary_outline_heading_text_v2($title)) {
                    $current_section_index = -1;
                    continue;
                }

                $current_section_index = count($sections);
                $sections[] = array(
                    'section_index' => $current_section_index,
                    'heading_title' => $title,
                    'heading_level' => intval(substr($heading_tag, 1)),
                    'videos' => array(),
                );
                continue;
            }

            if ($current_section_index < 0 || !isset($sections[$current_section_index])) {
                continue;
            }

            if ($tag === 'source' && $node->parentNode instanceof DOMElement && strtolower((string) $node->parentNode->nodeName) === 'video') {
                continue;
            }

            $candidate_tags = array('iframe', 'video', 'embed', 'object', 'source');
            $has_video_attribute = false;
            foreach ($video_attributes as $attribute) {
                if ($node->hasAttribute($attribute)) {
                    $has_video_attribute = true;
                    break;
                }
            }
            if (!in_array($tag, $candidate_tags, true) && !$has_video_attribute) {
                continue;
            }

            $candidate_url = '';
            foreach ($video_attributes as $attribute) {
                if ($node->hasAttribute($attribute)) {
                    $candidate_url = trim((string) $node->getAttribute($attribute));
                    if ($candidate_url !== '') {
                        break;
                    }
                }
            }

            $outer_html = is_object($node->ownerDocument) ? trim((string) $node->ownerDocument->saveHTML($node)) : '';
            if ($candidate_url === '' && $outer_html !== '' && method_exists('Content_Rank_Generator', 'extract_video_url_from_embed_html')) {
                $candidate_url = Content_Rank_Generator::extract_video_url_from_embed_html($outer_html, $base_url);
            }

            $candidate_url = html_entity_decode($candidate_url, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
            $resolved_url = self::resolve_url_against_base($candidate_url, $base_url);
            if ($resolved_url === '' || !Content_Rank_Generator::is_video_embed_url($resolved_url)) {
                continue;
            }

            $normalized_url = strtolower(rtrim($resolved_url, '/'));
            if (isset($seen_video_urls[$normalized_url])) {
                continue;
            }
            $seen_video_urls[$normalized_url] = true;

            $host = strtolower((string) wp_parse_url($resolved_url, PHP_URL_HOST));
            $source = (strpos($host, 'youtube.') !== false || $host === 'youtu.be') ? 'youtube' : 'video';
            $sections[$current_section_index]['videos'][] = array(
                'video_url' => esc_url_raw($resolved_url),
                'video_embed_html' => $outer_html,
                'video_source' => $source,
            );
        }

        return $sections;
    }

    public static function inject_source_video_sections_into_content($content, $video_sections)
    {
        $content = (string) $content;
        if ($content === '' || !is_array($video_sections) || empty($video_sections) || !function_exists('parse_blocks') || !function_exists('serialize_blocks')) {
            return $content;
        }

        $blocks = parse_blocks($content);
        if (!is_array($blocks) || empty($blocks)) {
            return $content;
        }

        $section_map = array();
        foreach (array_values($video_sections) as $section_index => $section) {
            if (!is_array($section) || empty($section['videos']) || !is_array($section['videos'])) {
                continue;
            }
            $section_key = isset($section['section_index']) ? intval($section['section_index']) : $section_index;
            $section_map[$section_key] = $section;
        }
        if (empty($section_map)) {
            return $content;
        }

        $serialized_content = $content;
        $output_blocks = array();
        $current_h2_index = -1;

        foreach ($blocks as $block) {
            if (is_array($block) && isset($block['blockName']) && $block['blockName'] === 'core/heading') {
                $level = !empty($block['attrs']['level']) ? intval($block['attrs']['level']) : 2;
                if ($level === 2) {
                    if ($current_h2_index >= 0 && isset($section_map[$current_h2_index])) {
                        $output_blocks = array_merge($output_blocks, self::build_source_video_blocks_for_section($section_map[$current_h2_index], $serialized_content));
                    }
                    $current_h2_index++;
                }
            }

            $output_blocks[] = $block;
        }

        if ($current_h2_index >= 0 && isset($section_map[$current_h2_index])) {
            $output_blocks = array_merge($output_blocks, self::build_source_video_blocks_for_section($section_map[$current_h2_index], $serialized_content));
        }

        return serialize_blocks($output_blocks);
    }

    private static function build_source_video_blocks_for_section($section, $serialized_content)
    {
        $blocks = array();
        if (!is_array($section) || empty($section['videos']) || !is_array($section['videos'])) {
            return $blocks;
        }

        foreach ($section['videos'] as $video) {
            if (!is_array($video)) {
                continue;
            }
            $video_url = !empty($video['video_url']) ? esc_url_raw((string) $video['video_url']) : '';
            if ($video_url === '' || strpos($serialized_content, $video_url) !== false) {
                continue;
            }
            $embed_html = !empty($video['video_embed_html']) ? (string) $video['video_embed_html'] : '';
            $block = Content_Rank_Generator::build_gutenberg_embed_block_from_html($embed_html, $video_url);
            if (!empty($block)) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    public static function resolve_url_against_base($url, $base_url = '')
    {
        if (class_exists('Content_Rank_Generator') && method_exists('Content_Rank_Generator', 'resolve_url_against_base')) {
            return Content_Rank_Generator::resolve_url_against_base($url, $base_url);
        }

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

    public static function clean_source_text($text)
    {
        if (class_exists('Content_Rank_Generator') && method_exists('Content_Rank_Generator', 'clean_source_text')) {
            return Content_Rank_Generator::clean_source_text($text);
        }

        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    public static function strip_source_page_noise_from_html($html, $content_selector = '')
    {
        $html = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previous_state = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8"><div id="content-rank-source-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_state);

        if (!$loaded) {
            $patterns = array(
                '~<!--.*?-->~is',
                '~<head\b[^>]*>.*?</head>~is',
                '~<script\b[^>]*>.*?</script>~is',
                '~<style\b[^>]*>.*?</style>~is',
                '~<header\b[^>]*>.*?</header>~is',
                '~<footer\b[^>]*>.*?</footer>~is',
                '~<nav\b[^>]*>.*?</nav>~is',
                '~<aside\b[^>]*>.*?</aside>~is',
                '~<meta\b[^>]*>~is',
                '~<link\b[^>]*>~is',
            );

            return trim(preg_replace($patterns, '', $html));
        }

        $xpath = new DOMXPath($dom);
        $remove_queries = array(
            '//comment()',
            '//head',
            '//script',
            '//style',
            '//header',
            '//footer',
            '//nav',
            '//aside',
            '//meta',
            '//link',
        );
        foreach ($remove_queries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes || $nodes->length === 0) {
                continue;
            }

            $to_remove = array();
            foreach ($nodes as $node) {
                if ($node instanceof DOMNode) {
                    $to_remove[] = $node;
                }
            }

            foreach (array_reverse($to_remove) as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $root = $dom->getElementById('content-rank-source-root');
        if (!$root) {
            return trim($dom->saveHTML());
        }

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    public static function normalize_prompt_context_html($html, $content_selector = '')
    {
        $html = self::strip_source_page_noise_from_html($html, $content_selector);
        $html = preg_replace('~\s+~u', ' ', (string) $html);
        $html = trim((string) $html);
        return $html;
    }

    public static function limit_prompt_html_chars($html, $max_chars = 6000)
    {
        $html = trim((string) $html);
        $max_chars = max(500, intval($max_chars));
        if ($html === '' || strlen($html) <= $max_chars) {
            return $html;
        }

        if (function_exists('mb_substr')) {
            return trim((string) mb_substr($html, 0, $max_chars, 'UTF-8'));
        }

        return trim((string) substr($html, 0, $max_chars));
    }

    public static function is_intro_heading_text($text)
    {
        $text = self::normalize_plain_text((string) $text);
        $text = function_exists('remove_accents') ? remove_accents($text) : $text;
        $text = strtolower(trim((string) $text));

        return $text === 'introducao' || strpos($text, 'introducao:') === 0;
    }

    public static function ensure_content_starts_with_paragraph_html($content)
    {
        $content = trim((string) $content);
        if ($content === '') {
            return '';
        }

        if (strpos($content, '<!-- wp:') !== false && function_exists('parse_blocks') && function_exists('serialize_blocks')) {
            $blocks = parse_blocks($content);
            if (empty($blocks)) {
                return $content;
            }

            foreach ($blocks as $index => $block) {
                if (empty($block['blockName']) || $block['blockName'] !== 'core/heading') {
                    continue;
                }

                $heading_html = isset($block['innerHTML']) ? (string) $block['innerHTML'] : '';
                if (self::is_intro_heading_text(wp_strip_all_tags($heading_html))) {
                    unset($blocks[$index]);
                }
            }
            $blocks = array_values($blocks);

            $paragraph_index = -1;
            foreach ($blocks as $index => $block) {
                if (!empty($block['blockName']) && $block['blockName'] === 'core/paragraph') {
                    $paragraph_index = intval($index);
                    break;
                }
            }

            if ($paragraph_index <= 0) {
                return $content;
            }

            foreach (range(0, $paragraph_index - 1) as $index) {
                if (empty($blocks[$index]) || empty($blocks[$index]['blockName']) || $blocks[$index]['blockName'] !== 'core/heading') {
                    continue;
                }

                $level = 2;
                if (isset($blocks[$index]['attrs']['level'])) {
                    $level = intval($blocks[$index]['attrs']['level']);
                }
                if ($level >= 1 && $level <= 6) {
                    unset($blocks[$index]);
                }
            }

            $blocks = array_values($blocks);

            return trim(serialize_blocks($blocks));
        }

        $previous_state = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8"><div id="content-rank-content-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_state);

        if (!$loaded) {
            return $content;
        }

        $root = $dom->getElementById('content-rank-content-root');
        if (!$root || !$root->hasChildNodes()) {
            return '';
        }

        $paragraph_node = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower((string) $child->nodeName) === 'p') {
                $paragraph_node = $child;
                break;
            }
        }

        if (!$paragraph_node) {
            $output = '';
            foreach ($root->childNodes as $child) {
                $output .= $dom->saveHTML($child);
            }

            return trim($output);
        }

        $child_nodes = array();
        foreach ($root->childNodes as $child) {
            $child_nodes[] = $child;
        }

        foreach ($child_nodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower((string) $child->nodeName), array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true)) {
                if (self::is_intro_heading_text($child->textContent)) {
                    $root->removeChild($child);
                    continue;
                }
            }

            if ($child === $paragraph_node) {
                break;
            }

            if ($child instanceof DOMElement && in_array(strtolower((string) $child->nodeName), array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true)) {
                $root->removeChild($child);
            }
        }

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * Converts a numbered run written inside one paragraph into semantic HTML.
     * This is intentionally limited to the list model; prose in news and
     * articles must not be rewritten based on incidental numbers.
     */
    public static function normalize_generated_list_markup($content, $content_type = '')
    {
        $content = trim((string) $content);
        $content_type = sanitize_key((string) $content_type);
        if ($content === '' || !in_array($content_type, array('lista', 'list', 'list_article'), true)) {
            return $content;
        }

        if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            return $content;
        }

        $previous_state = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8"><div id="content-rank-list-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_state);
        if (!$loaded) {
            return $content;
        }

        $root = $dom->getElementById('content-rank-list-root');
        if (!$root) {
            return $content;
        }

        $xpath = new DOMXPath($dom);
        $paragraphs = $xpath->query('.//p[not(ancestor::ol) and not(ancestor::ul)]', $root);
        if (!$paragraphs || $paragraphs->length === 0) {
            return $content;
        }

        $paragraph_nodes = array();
        for ($index = 0; $index < $paragraphs->length; $index++) {
            $paragraph_nodes[] = $paragraphs->item($index);
        }

        foreach ($paragraph_nodes as $paragraph) {
            if (!$paragraph) {
                continue;
            }

            $text = trim((string) $paragraph->textContent);
            if ($text === '' || preg_match('/<\s*(?:img|a|code)\b/i', $dom->saveHTML($paragraph))) {
                continue;
            }

            preg_match_all('/(?<![\p{L}\d])(\d{1,2})\s*[.)]\s+/u', $text, $matches, PREG_OFFSET_CAPTURE);
            if (empty($matches[1]) || count($matches[1]) < 2) {
                continue;
            }

            $numbers = array_map(static function ($match) {
                return isset($match[0]) ? intval($match[0]) : 0;
            }, $matches[1]);
            if (empty($numbers) || intval($numbers[0]) !== 1) {
                continue;
            }

            $is_sequential = true;
            foreach ($numbers as $number_index => $number) {
                if ($number !== ($number_index + 1)) {
                    $is_sequential = false;
                    break;
                }
            }
            if (!$is_sequential) {
                continue;
            }

            $first_offset = intval($matches[1][0][1]);
            $prefix = trim(substr($text, 0, $first_offset));
            $items = array();
            foreach ($matches[1] as $marker_index => $marker) {
                $marker_offset = intval($marker[1]);
                $marker_length = strlen((string) $matches[0][$marker_index][0]);
                $item_start = $marker_offset + $marker_length;
                $next_offset = isset($matches[1][$marker_index + 1][1])
                    ? intval($matches[1][$marker_index + 1][1])
                    : strlen($text);
                $item_text = trim(substr($text, $item_start, $next_offset - $item_start));
                if ($item_text !== '') {
                    $items[] = $item_text;
                }
            }

            if (count($items) < 2) {
                continue;
            }

            $replacement_html = $prefix !== '' ? '<p>' . esc_html($prefix) . '</p>' : '';
            $replacement_html .= '<ol>';
            foreach ($items as $item_text) {
                $replacement_html .= '<li>' . esc_html($item_text) . '</li>';
            }
            $replacement_html .= '</ol>';

            $fragment = $dom->createDocumentFragment();
            if (!$fragment->appendXML($replacement_html) || !$paragraph->parentNode) {
                continue;
            }
            $paragraph->parentNode->replaceChild($fragment, $paragraph);
        }

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output) !== '' ? trim($output) : $content;
    }

    public static function apply_humanized_bold_markup_to_content($content, $min_bolds = 2, $max_bolds = 4, $focus_keyword = '')
    {
        return self::apply_humanized_bold_markup_per_paragraph($content, $min_bolds, $max_bolds, $focus_keyword);

        $content = trim((string) $content);
        if ($content === '') {
            return '';
        }

        $min_bolds = max(1, intval($min_bolds));
        $max_bolds = max($min_bolds, intval($max_bolds));

        if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            return $content;
        }

        $previous_state = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8"><div id="content-rank-bold-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_state);

        if (!$loaded) {
            return $content;
        }

        $root = $dom->getElementById('content-rank-bold-root');
        if (!$root) {
            return $content;
        }

        $xpath = new DOMXPath($dom);
        $nodes_query = './/p//text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::strong) and not(ancestor::em) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//li//text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::strong) and not(ancestor::em) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//blockquote//text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::strong) and not(ancestor::em) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//td//text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::strong) and not(ancestor::em) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//th//text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::strong) and not(ancestor::em) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//figcaption//text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::strong) and not(ancestor::em) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//summary//text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::strong) and not(ancestor::em) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]';
        $nodes = $xpath->query($nodes_query, $root);
        if (!$nodes || $nodes->length === 0) {
            return $content;
        }

        $candidate_nodes = array();
        for ($i = 0; $i < $nodes->length; $i++) {
            $node = $nodes->item($i);
            if ($node) {
                $candidate_nodes[] = $node;
            }
        }

        if (empty($candidate_nodes)) {
            return $content;
        }

        $available_bolds = min($max_bolds, count($candidate_nodes));
        if ($available_bolds < 1) {
            return $content;
        }

        $minimum_bolds = min($min_bolds, $available_bolds);
        if ($minimum_bolds >= $available_bolds) {
            $target_bolds = $available_bolds;
        } else {
            $target_bolds = wp_rand($minimum_bolds, $available_bolds);
        }
        $target_bolds = max(1, min($target_bolds, $available_bolds));

        $candidate_indexes = array_keys($candidate_nodes);
        shuffle($candidate_indexes);

        $applied = 0;
        foreach ($candidate_indexes as $candidate_index) {
            if ($applied >= $target_bolds) {
                break;
            }

            if (!isset($candidate_nodes[$candidate_index])) {
                continue;
            }

            $node = $candidate_nodes[$candidate_index];
            if (!$node || !property_exists($node, 'nodeValue')) {
                continue;
            }

            $node_text = (string) $node->nodeValue;
            $replacement = self::build_humanized_bold_markup_for_text($node_text);
            if (empty($replacement) || empty($replacement['html'])) {
                continue;
            }

            $fragment = $dom->createDocumentFragment();
            if (!$fragment->appendXML($replacement['html'])) {
                continue;
            }

            if ($node->parentNode) {
                $node->parentNode->replaceChild($fragment, $node);
                $applied++;
            }
        }

        if ($applied <= 0) {
            return $content;
        }

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    protected static function apply_humanized_bold_markup_per_paragraph($content, $min_bolds = 2, $max_bolds = 4, $focus_keyword = '')
    {
        $content = trim((string) $content);
        if ($content === '' || !class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            return $content;
        }

        $previous_state = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8"><div id="content-rank-bold-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_state);
        if (!$loaded) {
            return $content;
        }

        $root = $dom->getElementById('content-rank-bold-root');
        if (!$root) {
            return $content;
        }

        $xpath = new DOMXPath($dom);
        $blocks = $xpath->query('.//p | .//li | .//blockquote | .//td | .//th | .//figcaption | .//summary', $root);
        if (!$blocks || $blocks->length === 0) {
            return $content;
        }

        $text_query = './/text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::strong) and not(ancestor::em) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]';
        $candidate_blocks = array();
        for ($block_index = 0; $block_index < $blocks->length; $block_index++) {
            $block = $blocks->item($block_index);
            if (!$block || $xpath->query('.//strong', $block)->length > 0) {
                continue;
            }

            $text_nodes = $xpath->query($text_query, $block);
            if (!$text_nodes || $text_nodes->length === 0) {
                continue;
            }

            $has_usable_text = false;
            for ($node_index = 0; $node_index < $text_nodes->length; $node_index++) {
                $node = $text_nodes->item($node_index);
                if ($node && str_word_count(wp_strip_all_tags((string) $node->nodeValue), 0, '0123456789') >= 5) {
                    $has_usable_text = true;
                    break;
                }
            }
            if ($has_usable_text) {
                $candidate_blocks[] = $block;
            }
        }

        $focus_keyword = trim((string) $focus_keyword);
        $focus_block = null;
        $focus_applied = false;
        if ($focus_keyword !== '') {
            for ($block_index = 0; $block_index < $blocks->length; $block_index++) {
                $block = $blocks->item($block_index);
                if (!$block || $xpath->query('.//strong', $block)->length > 0) {
                    continue;
                }

                $text_nodes = $xpath->query($text_query, $block);
                if (!$text_nodes || $text_nodes->length === 0) {
                    continue;
                }

                for ($node_index = 0; $node_index < $text_nodes->length; $node_index++) {
                    $node = $text_nodes->item($node_index);
                    if (!$node || trim((string) $node->nodeValue) === '') {
                        continue;
                    }

                    $replacement = self::build_focus_keyword_bold_markup_for_text(
                        (string) $node->nodeValue,
                        $focus_keyword
                    );
                    if (empty($replacement['html'])) {
                        continue;
                    }

                    $fragment = $dom->createDocumentFragment();
                    if (!$fragment->appendXML($replacement['html']) || !$node->parentNode) {
                        continue;
                    }

                    $node->parentNode->replaceChild($fragment, $node);
                    $focus_block = $block;
                    $focus_applied = true;
                    break 2;
                }
            }
        }

        if (empty($candidate_blocks) && !$focus_applied) {
            return $content;
        }

        $min_bolds = max(1, intval($min_bolds));
        $max_bolds = max($min_bolds, intval($max_bolds));
        $block_count = count($candidate_blocks);
        $target_bolds = (int) ceil($block_count / 4);
        $target_bolds = max($min_bolds, min($max_bolds, $target_bolds));
        $target_bolds = min($target_bolds, $block_count);

        // Do not place highlights in adjacent paragraphs; the spacing makes
        // the emphasis read like editorial guidance instead of decoration.
        $block_indexes = $block_count > 0 ? range(0, $block_count - 1) : array();
        shuffle($block_indexes);
        $selected_block_indexes = array();
        $focus_candidate_index = $focus_block !== null ? array_search($focus_block, $candidate_blocks, true) : false;
        if ($focus_candidate_index !== false) {
            $selected_block_indexes[] = intval($focus_candidate_index);
        }
        foreach ($block_indexes as $block_index) {
            if (count($selected_block_indexes) >= $target_bolds) {
                break;
            }

            if (in_array($block_index, $selected_block_indexes, true)) {
                continue;
            }

            $has_adjacent_selection = false;
            foreach ($selected_block_indexes as $selected_index) {
                if (abs($selected_index - $block_index) <= 1) {
                    $has_adjacent_selection = true;
                    break;
                }
            }
            if (!$has_adjacent_selection) {
                $selected_block_indexes[] = $block_index;
            }
        }
        sort($selected_block_indexes);

        $applied = $focus_applied ? 1 : 0;
        foreach ($selected_block_indexes as $selected_block_index) {
            if ($applied >= $target_bolds) {
                break;
            }

            if ($focus_candidate_index !== false && $selected_block_index === intval($focus_candidate_index)) {
                continue;
            }

            $block = $candidate_blocks[$selected_block_index];

            $text_nodes = $xpath->query($text_query, $block);
            if (!$text_nodes || $text_nodes->length === 0) {
                continue;
            }

            $candidate_nodes = array();
            for ($node_index = 0; $node_index < $text_nodes->length; $node_index++) {
                $node = $text_nodes->item($node_index);
                if ($node && trim((string) $node->nodeValue) !== '') {
                    $candidate_nodes[] = $node;
                }
            }
            shuffle($candidate_nodes);

            $applied_in_block = false;
            foreach ($candidate_nodes as $node) {
                $replacement = self::build_humanized_bold_markup_for_text_v2((string) $node->nodeValue);
                if (empty($replacement['html'])) {
                    continue;
                }

                $fragment = $dom->createDocumentFragment();
                if (!$fragment->appendXML($replacement['html'])) {
                    continue;
                }

                if ($node->parentNode) {
                    $node->parentNode->replaceChild($fragment, $node);
                    $applied_in_block = true;
                    break;
                }
            }

            if ($applied_in_block) {
                $applied++;
            }
        }

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        // Keep word boundaries readable even when an existing text node was
        // already split by a previous bold operation.
        $output = preg_replace('/([\p{L}\p{N}])(<strong\b[^>]*>)/u', '$1 $2', $output);
        $output = preg_replace('/(<\/strong>)([\p{L}\p{N}])/u', '$1 $2', $output);

        return trim($output) !== '' ? trim($output) : $content;
    }

    protected static function build_focus_keyword_bold_markup_for_text($text, $focus_keyword)
    {
        $text = (string) $text;
        $focus_keyword = trim((string) $focus_keyword);
        if ($text === '' || $focus_keyword === '') {
            return array();
        }

        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($focus_keyword, '/') . '(?![\p{L}\p{N}])/iu';
        if (!preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE)) {
            return array();
        }

        $offset = isset($match[0][1]) ? intval($match[0][1]) : -1;
        $matched_text = isset($match[0][0]) ? (string) $match[0][0] : '';
        if ($offset < 0 || $matched_text === '') {
            return array();
        }

        $prefix = substr($text, 0, $offset);
        $suffix = substr($text, $offset + strlen($matched_text));

        return array(
            'html' => esc_html($prefix) . '<strong>' . esc_html($matched_text) . '</strong>' . esc_html($suffix),
            'phrase' => $matched_text,
        );
    }

    protected static function build_humanized_bold_markup_for_text_v2($text)
    {
        $raw_text = (string) $text;
        $leading_whitespace = '';
        $trailing_whitespace = '';
        if (preg_match('/^\s+/u', $raw_text, $whitespace_match)) {
            $leading_whitespace = (string) $whitespace_match[0];
        }
        if (preg_match('/\s+$/u', $raw_text, $whitespace_match)) {
            $trailing_whitespace = (string) $whitespace_match[0];
        }
        $text = trim($raw_text);
        if ($text === '' || !preg_match_all('/\b[\p{L}\p{N}][\p{L}\p{N}\x{2019}\x{0027}\-]*\b/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return array();
        }

        $words = array();
        foreach ($matches[0] as $match) {
            $word = isset($match[0]) ? (string) $match[0] : '';
            if ($word === '') {
                continue;
            }

            $normalized = function_exists('remove_accents') ? remove_accents($word) : $word;
            $normalized = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
            $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized);
            $words[] = array(
                'text' => $word,
                'offset' => isset($match[1]) ? intval($match[1]) : 0,
                'length' => strlen($word),
                'normalized' => (string) $normalized,
            );
        }

        if (empty($words)) {
            return array();
        }

        $stopwords = array(
            'a',
            'o',
            'os',
            'as',
            'e',
            'de',
            'da',
            'do',
            'das',
            'dos',
            'em',
            'no',
            'na',
            'nos',
            'nas',
            'um',
            'uma',
            'uns',
            'umas',
            'para',
            'por',
            'com',
            'sem',
            'sobre',
            'entre',
            'que',
            'se',
            'ao',
            'aos',
            'ate',
            'e',
            'eu',
            'tu',
            'ele',
            'ela',
            'eles',
            'elas',
            'esse',
            'essa',
            'esses',
            'essas',
            'este',
            'esta',
            'estes',
            'estas',
            'isso',
            'isto',
            'aquilo',
            'como',
            'assim',
            'disso',
            'desse',
            'dessa',
            'desses',
            'dessas',
            'deste',
            'desta',
            'destes',
            'destas',
            'daquele',
            'daquela',
            'daqueles',
            'daquelas',
            'ser',
            'ter',
            'vai',
            'foi',
            'sao',
            'estao',
            'era',
            'eram',
            'tem',
            'tinha',
            'havia',
            'seu',
            'sua',
            'seus',
            'suas',
            'meu',
            'minha',
            'meus',
            'minhas',
            'ja',
            'nao',
            'muito',
            'muita',
            'muitos',
            'muitas',
            'mais',
            'the',
            'and',
            'or',
            'to',
            'of',
            'in',
            'on',
            'at',
            'for',
            'with',
            'from',
            'by',
        );
        // Rank complete concepts instead of choosing a random word sequence.
        $candidate_phrases = array();
        foreach (array(4, 3, 2, 1) as $size) {
            if (count($words) < $size) {
                continue;
            }

            for ($start = 0; $start <= count($words) - $size; $start++) {
                $slice = array_slice($words, $start, $size);
                $first_word = $slice[0];
                $last_word = $slice[count($slice) - 1];
                $first_normalized = (string) $first_word['normalized'];
                $last_normalized = (string) $last_word['normalized'];
                $last_length = function_exists('mb_strlen') ? mb_strlen($last_normalized, 'UTF-8') : strlen($last_normalized);

                // Do not bold the first word or leave a sentence hanging on a filler word.
                if (intval($first_word['offset']) <= strlen($leading_whitespace)) {
                    continue;
                }
                if (in_array($first_normalized, $stopwords, true) || strlen($first_normalized) < 3) {
                    continue;
                }
                if ($last_length < 3 || in_array($last_normalized, $stopwords, true)) {
                    continue;
                }

                $meaningful_count = 0;
                $stopword_count = 0;
                $longest_word = 0;
                $has_entity = false;
                foreach ($slice as $word_item) {
                    $normalized_word = (string) $word_item['normalized'];
                    $word_length = function_exists('mb_strlen') ? mb_strlen($normalized_word, 'UTF-8') : strlen($normalized_word);
                    if (in_array($normalized_word, $stopwords, true)) {
                        $stopword_count++;
                        continue;
                    }
                    if ($word_length >= 4) {
                        $meaningful_count++;
                    }
                    $longest_word = max($longest_word, $word_length);
                    if (preg_match('/^[\p{Lu}\d]/u', (string) $word_item['text'])) {
                        $has_entity = true;
                    }
                }

                if ($meaningful_count < 1 || $stopword_count >= $size) {
                    continue;
                }

                // Keep a short article with the concept instead of leaving
                // an isolated "a" or "o" immediately before the bold.
                $phrase_slice = $slice;
                if ($start > 0) {
                    $previous_word = $words[$start - 1];
                    if (in_array((string) $previous_word['normalized'], array('a', 'o', 'as', 'os', 'um', 'uma'), true)
                        && intval($previous_word['offset']) > strlen($leading_whitespace)) {
                        array_unshift($phrase_slice, $previous_word);
                    }
                }

                $start_offset = intval($phrase_slice[0]['offset']);
                $last_slice_index = count($slice) - 1;
                $end_offset = intval($slice[$last_slice_index]['offset']) + intval($slice[$last_slice_index]['length']);
                $middle = substr($text, $start_offset, $end_offset - $start_offset);
                if ($middle === false || trim($middle) === '') {
                    continue;
                }
                // A highlight must stay inside one sentence. Do not allow a
                // phrase to join the end of one sentence to the next one.
                if (preg_match('/[.!?;]/u', $middle)) {
                    continue;
                }

                $score = ($meaningful_count * 10) + min(12, $longest_word) + ($has_entity ? 5 : 0);
                $score -= ($stopword_count * 3);
                if ($size === 2) {
                    $score += 3;
                } elseif ($size === 3) {
                    $score += 2;
                }

                $candidate_phrases[] = array(
                    'score' => $score,
                    'start_offset' => $start_offset,
                    'end_offset' => $end_offset,
                    'middle' => $middle,
                    'phrase' => trim(implode(' ', array_column($phrase_slice, 'text'))),
                );
            }
        }

        if (empty($candidate_phrases)) {
            return array();
        }

        usort($candidate_phrases, static function ($left, $right) {
            return intval($right['score']) <=> intval($left['score']);
        });
        $best_candidates = array_slice($candidate_phrases, 0, min(3, count($candidate_phrases)));
        $selected = $best_candidates[function_exists('wp_rand') ? wp_rand(0, count($best_candidates) - 1) : 0];
        $prefix = substr($text, 0, intval($selected['start_offset']));
        $suffix = substr($text, intval($selected['end_offset']));

        return array(
            'html' => esc_html($leading_whitespace . $prefix) . '<strong>' . esc_html($selected['middle']) . '</strong>' . esc_html($suffix . $trailing_whitespace),
            'phrase' => $selected['phrase'],
        );
    }

    protected static function build_humanized_bold_markup_for_text($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return array();
        }

        if (!preg_match_all('/\b[\p{L}\p{N}][\p{L}\p{N}\'’\-]*\b/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return array();
        }

        if (empty($matches[0]) || !is_array($matches[0])) {
            return array();
        }

        $words = array();
        foreach ($matches[0] as $match) {
            if (!is_array($match) || !isset($match[0])) {
                continue;
            }

            $word = (string) $match[0];
            $offset = isset($match[1]) ? intval($match[1]) : 0;
            $length = strlen($word);
            if ($word === '' || $length <= 0) {
                continue;
            }

            $words[] = array(
                'text' => $word,
                'offset' => $offset,
                'length' => $length,
                'normalized' => function_exists('remove_accents') ? strtolower(remove_accents($word)) : strtolower($word),
            );
        }

        if (empty($words)) {
            return array();
        }

        $size_order = array(1, 2, 3);
        shuffle($size_order);

        $stopwords = array(
            'a',
            'o',
            'os',
            'as',
            'e',
            'de',
            'da',
            'do',
            'das',
            'dos',
            'em',
            'no',
            'na',
            'nos',
            'nas',
            'um',
            'uma',
            'uns',
            'umas',
            'para',
            'por',
            'com',
            'sem',
            'sobre',
            'entre',
            'que',
            'se',
            'ao',
            'aos',
            'às',
            'as',
            'este',
            'esta',
            'esse',
            'essa',
            'isso',
            'aquilo',
            'lhe',
            'lhes',
            'te',
            'me',
            'tu',
            'eu',
            'nós',
            'voce',
            'você',
            'eles',
            'elas',
            'ser',
            'ter',
            'ir',
            'the',
            'and',
            'or',
            'to',
            'of',
            'in',
            'on',
            'at',
            'for',
            'with',
            'from',
            'by',
        );

        foreach ($size_order as $size) {
            if (count($words) < $size) {
                continue;
            }

            $start_positions = range(0, count($words) - $size);
            shuffle($start_positions);

            foreach ($start_positions as $start) {
                $slice = array_slice($words, $start, $size);
                if (empty($slice)) {
                    continue;
                }

                $phrase_words = array();
                $has_meaningful_word = false;
                foreach ($slice as $word_item) {
                    $phrase_words[] = $word_item['text'];
                    $normalized = isset($word_item['normalized']) ? (string) $word_item['normalized'] : '';
                    if ($normalized !== '' && !in_array($normalized, $stopwords, true) && strlen($normalized) >= 4) {
                        $has_meaningful_word = true;
                    }
                }

                $phrase = trim(implode(' ', $phrase_words));
                if ($phrase === '') {
                    continue;
                }

                $phrase_length = function_exists('mb_strlen') ? mb_strlen($phrase, 'UTF-8') : strlen($phrase);
                if ($size === 1) {
                    $single_normalized = isset($slice[0]['normalized']) ? (string) $slice[0]['normalized'] : '';
                    if ($single_normalized === '' || in_array($single_normalized, $stopwords, true) || $phrase_length < 4) {
                        continue;
                    }
                } elseif (!$has_meaningful_word || $phrase_length < 6) {
                    continue;
                }

                $start_offset = isset($slice[0]['offset']) ? intval($slice[0]['offset']) : 0;
                $last_index = count($slice) - 1;
                $end_offset = isset($slice[$last_index]['offset'], $slice[$last_index]['length']) ? intval($slice[$last_index]['offset']) + intval($slice[$last_index]['length']) : $start_offset;
                if ($end_offset <= $start_offset) {
                    continue;
                }

                $prefix = substr($text, 0, $start_offset);
                $middle = substr($text, $start_offset, $end_offset - $start_offset);
                $suffix = substr($text, $end_offset);

                if ($middle === false || $middle === '') {
                    continue;
                }

                return array(
                    'html' => esc_html($prefix) . '<strong>' . esc_html($middle) . '</strong>' . esc_html($suffix),
                    'phrase' => $phrase,
                );
            }
        }

        return array();
    }

    public static function html_contains_image_markup($html)
    {
        $html = trim((string) $html);
        if ($html === '') {
            return false;
        }

        return (bool) preg_match('~<img\b|wp-block-image|wp-image-\d+|<figure\b[^>]*class=["\'][^"\']*\bwp-block-image\b~i', $html);
    }

    public static function inject_image_after_first_paragraph_html($content, $attachment_id, $image_size = 'medium', $alt_text = '')
    {
        $content = trim((string) $content);
        $attachment_id = intval($attachment_id);
        if ($content === '' || $attachment_id <= 0) {
            return $content;
        }

        if (self::html_contains_image_markup($content)) {
            return $content;
        }

        if (!class_exists('Content_Rank_Generator')) {
            return $content;
        }

        $image_html = Content_Rank_Generator::build_attachment_image_figure_html($attachment_id, $image_size, $alt_text, 'alignnone');
        if ($image_html === '') {
            return $content;
        }

        if (strpos($content, '<!-- wp:') !== false && function_exists('parse_blocks') && function_exists('serialize_blocks')) {
            $blocks = parse_blocks($content);
            if (!empty($blocks) && is_array($blocks)) {
                $insert_index = 0;
                foreach ($blocks as $index => $block) {
                    if (!empty($block['blockName']) && $block['blockName'] === 'core/paragraph') {
                        $insert_index = intval($index) + 1;
                        break;
                    }
                }

                $image_block = array(
                    'blockName' => 'core/html',
                    'attrs' => array(),
                    'innerBlocks' => array(),
                    'innerHTML' => $image_html,
                    'innerContent' => array($image_html),
                );
                array_splice($blocks, $insert_index, 0, array($image_block));
                return trim(serialize_blocks($blocks));
            }
        }

        $paragraph_pos = stripos($content, '</p>');
        if ($paragraph_pos !== false) {
            $paragraph_end = $paragraph_pos + 4;
            return trim(substr($content, 0, $paragraph_end) . "\n" . $image_html . "\n" . substr($content, $paragraph_end));
        }

        return trim($image_html . "\n" . $content);
    }

    public static function normalize_plain_text($text)
    {
        return self::clean_source_text($text);
    }

    public static function limit_plain_text_words($text, $max_words = 120)
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

    public static function bulk_parse_spreadsheet_file($file_path)
    {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            throw new Exception('Biblioteca de planilhas nao carregada');
        }

        $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if ($file_extension === 'xlsx' && !class_exists('ZipArchive')) {
            throw new Exception('Arquivos XLSX exigem a extensao PHP zip (ZipArchive) habilitada no servidor');
        }

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file_path);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        if ($reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Csv && method_exists($reader, 'setDelimiter')) {
            $reader->setDelimiter(Content_Rank_Generator::bulk_detect_csv_delimiter($file_path));
        }

        $spreadsheet = $reader->load($file_path);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet_rows = $sheet->toArray('', true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $normalized_rows = array();
        foreach ($sheet_rows as $raw_row) {
            $clean_row = array_map(array('Content_Rank_Generator', 'bulk_sanitize_cell'), is_array($raw_row) ? $raw_row : array());
            $has_value = false;
            foreach ($clean_row as $cell) {
                if ($cell !== '') {
                    $has_value = true;
                    break;
                }
            }

            if ($has_value) {
                $normalized_rows[] = $clean_row;
            }
        }

        if (empty($normalized_rows)) {
            throw new Exception('A planilha esta vazia');
        }

        $header_row = array_shift($normalized_rows);
        $max_columns = count($header_row);
        foreach ($normalized_rows as $row_values) {
            $max_columns = max($max_columns, count($row_values));
        }

        $used_columns = array();
        for ($index = 0; $index < $max_columns; $index++) {
            $header_value = isset($header_row[$index]) ? trim((string) $header_row[$index]) : '';
            $has_content = ($header_value !== '');

            if (!$has_content) {
                foreach ($normalized_rows as $row_values) {
                    if (isset($row_values[$index]) && trim((string) $row_values[$index]) !== '') {
                        $has_content = true;
                        break;
                    }
                }
            }

            if ($has_content) {
                $used_columns[] = $index;
            }
        }

        $headers = array();
        foreach ($used_columns as $index) {
            $header_value = isset($header_row[$index]) ? trim((string) $header_row[$index]) : '';
            if ($header_value === '') {
                $header_value = 'Column ' . ($index + 1);
            }
            $headers[] = Content_Rank_Generator::bulk_make_unique_header($header_value, $headers);
        }

        $rows = array();
        foreach ($normalized_rows as $row_values) {
            $filtered_row = array();
            foreach ($used_columns as $index) {
                $filtered_row[] = isset($row_values[$index]) ? $row_values[$index] : '';
            }
            $rows[] = $filtered_row;
        }

        if (empty($headers)) {
            throw new Exception('Nao foi possivel identificar o cabecalho da planilha');
        }

        return array(
            'headers' => $headers,
            'rows' => $rows,
        );
    }


    public static function bulk_resolve_keyword_row($row_data, $column_map)
    {
        $keyword_column = isset($column_map['keyword_column']) ? $column_map['keyword_column'] : '';
        $source_title_column = isset($column_map['source_title_column']) ? $column_map['source_title_column'] : '';
        $source_url_column = isset($column_map['source_url_column']) ? $column_map['source_url_column'] : '';
        $slug_column = isset($column_map['slug_column']) ? $column_map['slug_column'] : '';

        $keyword = Content_Rank_Generator::bulk_find_row_value($row_data, $keyword_column);
        $source_title = Content_Rank_Generator::bulk_find_row_value($row_data, $source_title_column);
        $source_url_candidate = Content_Rank_Generator::bulk_find_row_value($row_data, $source_url_column);
        $slug_candidate = Content_Rank_Generator::bulk_find_row_value($row_data, $slug_column);

        if ($slug_candidate === '' && $source_url_candidate !== '') {
            $slug_candidate = $source_url_candidate;
        }

        $slug_info = Content_Rank_Generator::bulk_resolve_slug_info($slug_candidate);
        $canonical_source_url = Content_Rank_Generator::bulk_normalize_url_for_dedupe($source_url_candidate);
        $source_url = !empty($slug_info['source_url']) ? $slug_info['source_url'] : $source_url_candidate;
        $has_source_reference = ($slug_candidate !== '' || $source_url_candidate !== '');
        $error_message = '';
        $row_status = 'pending';
        $slug_is_valid = 1;

        if ($keyword === '') {
            $row_status = 'invalid_slug';
            $slug_is_valid = 0;
            $error_message = 'Keyword vazia';
        } elseif ($has_source_reference && empty($slug_info['valid'])) {
            $row_status = 'invalid_slug';
            $slug_is_valid = 0;
            $error_message = !empty($slug_info['extension'])
                ? 'Slug final com extensao bloqueada: ' . $slug_info['extension']
                : 'Nao foi possivel extrair slug final';
        }

        return array(
            'keyword' => $keyword,
            'source_title' => $source_title,
            'source_url' => $source_url,
            'source_url_candidate' => $source_url_candidate,
            'final_slug' => !empty($slug_info['slug']) ? $slug_info['slug'] : '',
            'slug_extension' => !empty($slug_info['extension']) ? $slug_info['extension'] : '',
            'slug_is_valid' => $slug_is_valid,
            'row_status' => $row_status,
            'error_message' => $error_message,
            'canonical_source_url' => $canonical_source_url,
            'slug_key' => !empty($slug_info['valid']) ? sanitize_title($slug_info['slug']) : '',
        );
    }

    public static function build_xpath_class_condition($selector_class)
    {
        $tokens = self::normalize_selector_class_tokens($selector_class);
        if (empty($tokens)) {
            return '';
        }

        $parts = array();
        foreach ($tokens as $token) {
            $parts[] = 'contains(concat(" ", normalize-space(@class), " "), " ' . $token . ' ")';
        }

        return implode(' and ', $parts);
    }

    public static function build_xpath_content_selector_queries($selector)
    {
        $selector = trim(preg_replace('/\s+/', ' ', (string) $selector));
        if ($selector === '') {
            return array();
        }

        $parts = preg_split('/\s*,\s*/', $selector);
        if (empty($parts)) {
            $parts = array($selector);
        }

        $queries = array();
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }

            if ($part[0] === '#') {
                $id_value = trim(substr($part, 1));
                $id_value = preg_replace('/[\"\']+/', '', $id_value);
                if ($id_value !== '') {
                    $queries[] = '//*[@id="' . $id_value . '"]';
                }
                continue;
            }

            if ($part[0] === '.') {
                $class_value = trim(substr($part, 1));
                $class_value = preg_replace('/[\"\']+/', '', $class_value);
                if ($class_value !== '') {
                    $class_condition = self::build_xpath_class_condition($class_value);
                    if ($class_condition !== '') {
                        $queries[] = '//*[' . $class_condition . ']';
                    }
                }
                continue;
            }

            $raw_value = preg_replace('/[\"\']+/', '', $part);
            if ($raw_value === '') {
                continue;
            }

            $queries[] = '//*[@id="' . $raw_value . '"]';
            $class_condition = self::build_xpath_class_condition($raw_value);
            if ($class_condition !== '') {
                $queries[] = '//*[' . $class_condition . ']';
            }
        }

        return array_values(array_unique($queries));
    }

    public static function extract_text_from_html_using_selector($html, $selector)
    {
        $html = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        $selector = trim((string) $selector);
        if ($html === '' || $selector === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!@$dom->loadHTML('<?xml encoding="UTF-8">' . $html)) {
            return '';
        }

        $xpath = new DOMXPath($dom);
        $queries = self::build_xpath_content_selector_queries($selector);
        if (empty($queries)) {
            return '';
        }

        $best = '';
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
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
            if ($candidate !== '' && strlen($candidate) > strlen($best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    public static function extract_html_from_html_using_selector($html, $selector)
    {
        $html = self::strip_source_page_noise_from_html($html, $selector);
        $selector = trim((string) $selector);
        if ($html === '' || $selector === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!@$dom->loadHTML('<?xml encoding="UTF-8">' . $html)) {
            return '';
        }

        $xpath = new DOMXPath($dom);
        $queries = self::build_xpath_content_selector_queries($selector);
        if (empty($queries)) {
            return '';
        }

        return self::extract_best_html_from_xpath_queries($dom, $xpath, $queries);
    }

    private static function extract_best_html_from_xpath_queries($dom, $xpath, array $queries)
    {
        $best_html = '';
        $best_length = 0;

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes || $nodes->length === 0) {
                continue;
            }

            for ($i = 0; $i < min(2, $nodes->length); $i++) {
                $node = $nodes->item($i);
                if (!($node instanceof DOMNode)) {
                    continue;
                }

                $candidate = $dom->saveHTML($node);
                $candidate = is_string($candidate) ? trim($candidate) : '';
                if ($candidate === '') {
                    continue;
                }

                $candidate_length = strlen($candidate);
                if ($candidate_length > $best_length) {
                    $best_length = $candidate_length;
                    $best_html = $candidate;
                }
            }
        }

        return $best_html;
    }

    public static function extract_html_from_html_with_fallbacks($html, $selector = '')
    {
        $html = self::strip_source_page_noise_from_html($html, $selector);
        if ($html === '') {
            return '';
        }

        $selector = trim((string) $selector);
        if ($selector !== '') {
            $selected_html = self::extract_html_from_html_using_selector($html, $selector);
            if ($selected_html !== '') {
                return $selected_html;
            }
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!@$dom->loadHTML('<?xml encoding="UTF-8">' . $html)) {
            return '';
        }

        $xpath = new DOMXPath($dom);
        $priority_query_groups = array(
            array(
                '//article//*[contains(concat(" ", normalize-space(@class), " "), " article__body ")]',
                '//article//*[@itemprop="articleBody"]',
                '//article//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
                '//article//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
                '//article//*[contains(concat(" ", normalize-space(@class), " "), " content ")]',
                '//article//*[contains(concat(" ", normalize-space(@class), " "), " article-body ")]',
                '//article//*[contains(concat(" ", normalize-space(@class), " "), " story-body ")]',
            ),
            array(
                '//main//*[contains(concat(" ", normalize-space(@class), " "), " article__body ")]',
                '//main//*[@itemprop="articleBody"]',
                '//main//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
                '//main//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
                '//main//*[contains(concat(" ", normalize-space(@class), " "), " content ")]',
                '//main//*[contains(concat(" ", normalize-space(@class), " "), " article-body ")]',
                '//main//*[contains(concat(" ", normalize-space(@class), " "), " story-body ")]',
            ),
            array(
                '//body//*[contains(concat(" ", normalize-space(@class), " "), " article__body ")]',
                '//body//*[@itemprop="articleBody"]',
                '//body//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
                '//body//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
                '//body//*[contains(concat(" ", normalize-space(@class), " "), " content ")]',
                '//body//*[contains(concat(" ", normalize-space(@class), " "), " article-body ")]',
                '//body//*[contains(concat(" ", normalize-space(@class), " "), " story-body ")]',
            ),
            array('//article', '//main', '//body'),
            array('//*[@role="main"]'),
            array('//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]'),
            array('//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]'),
            array('//*[contains(concat(" ", normalize-space(@class), " "), " content ")]'),
            array('//*[contains(concat(" ", normalize-space(@class), " "), " article-body ")]'),
            array('//*[contains(concat(" ", normalize-space(@class), " "), " story-body ")]'),
            array('//*[@id="content"]'),
            array('//*[@id="main"]'),
        );

        foreach ($priority_query_groups as $queries) {
            $selected_html = self::extract_best_html_from_xpath_queries($dom, $xpath, $queries);
            if ($selected_html !== '') {
                return $selected_html;
            }
        }

        return $html;
    }

    public static function extract_selector_media_candidate_from_html($html, $base_url = '', $selector_class = '', $kind = 'image')
    {
        $result = array(
            'image_url' => '',
            'image_source' => '',
            'image_class' => '',
            'image_attr' => '',
            'image_tag' => '',
            'link_url' => '',
            'link_text' => '',
            'link_source' => '',
        );

        $selector_class = trim((string) $selector_class);
        $kind = sanitize_key((string) $kind);
        if ($selector_class === '' || $html === '' || !in_array($kind, array('image', 'link'), true)) {
            return $result;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!@$dom->loadHTML('<?xml encoding="UTF-8">' . $html)) {
            return $result;
        }

        $xpath = new DOMXPath($dom);
        $condition = self::build_xpath_class_condition($selector_class);
        if ($condition === '') {
            return $result;
        }

        $nodes = $xpath->query('//*[' . $condition . ']');
        if (!$nodes || $nodes->length === 0) {
            return $result;
        }

        foreach ($nodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }

            $links = array();
            $images = array();
            $seen_links = array();
            $seen_images = array();
            self::collect_page_outline_media_from_node($node, $base_url, $links, $images, $seen_links, $seen_images, 1, 1, '', '', true, true);

            if ($kind === 'image' && !empty($images) && !empty($images[0]['url'])) {
                $candidate = array(
                    'image_url' => !empty($images[0]['url']) ? $images[0]['url'] : '',
                    'image_source' => 'selector:' . $selector_class,
                    'image_class' => $selector_class,
                    'image_attr' => !empty($images[0]['attr']) ? $images[0]['attr'] : '',
                    'image_tag' => !empty($images[0]['source']) ? $images[0]['source'] : '',
                );
                return $candidate;
            }

            if ($kind === 'link' && !empty($links) && !empty($links[0]['url'])) {
                $candidate = array(
                    'link_url' => !empty($links[0]['url']) ? $links[0]['url'] : '',
                    'link_text' => !empty($links[0]['text']) ? $links[0]['text'] : '',
                    'link_source' => 'selector:' . $selector_class,
                );
                return $candidate;
            }
        }

        return $result;
    }

    public static function extract_featured_image_from_html($html, $base_url = '')
    {
        $result = array(
            'image_url' => '',
            'image_source' => '',
            'image_class' => '',
            'image_attr' => 'content',
            'image_tag' => 'meta',
        );

        $html = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        if (trim($html) === '') {
            return $result;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!@$dom->loadHTML('<?xml encoding="UTF-8">' . $html)) {
            return $result;
        }

        $allowed_keys = array(
            'og:image',
            'og:image:url',
            'twitter:image',
            'twitter:image:src',
        );
        $meta_nodes = $dom->getElementsByTagName('meta');
        foreach ($meta_nodes as $meta_node) {
            if (!($meta_node instanceof DOMElement) || !$meta_node->hasAttribute('content')) {
                continue;
            }

            $meta_key = '';
            foreach (array('property', 'name', 'itemprop') as $attribute) {
                if ($meta_node->hasAttribute($attribute)) {
                    $meta_key = strtolower(trim((string) $meta_node->getAttribute($attribute)));
                    break;
                }
            }
            if (!in_array($meta_key, $allowed_keys, true)) {
                continue;
            }

            $candidate = Content_Rank_Generator::resolve_url_against_base(
                trim((string) $meta_node->getAttribute('content')),
                $base_url
            );
            if ($candidate === '' || Content_Rank_Generator::is_probably_bad_featured_image_url($candidate, $base_url)) {
                continue;
            }

            $result['image_url'] = $candidate;
            $result['image_source'] = $meta_key;
            return $result;
        }

        return $result;
    }

    public static function extract_media_from_html($html, $base_url = '', $video_selector_class = '', $image_selector_class = '', $link_selector_class = '', $prefer_selector_image = true)
    {
        $html = self::strip_source_page_noise_from_html($html);
        $media = array(
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

        if ($html === '') {
            return $media;
        }

        $image_selector_class = trim((string) $image_selector_class);
        $prefer_selector_image = !empty($prefer_selector_image);
        if ($image_selector_class !== '' && $prefer_selector_image) {
            $selector_candidate = self::extract_selector_media_candidate_from_html($html, $base_url, $image_selector_class, 'image');
            if (!empty($selector_candidate['image_url'])) {
                $media['image_url'] = !empty($selector_candidate['image_url']) ? $selector_candidate['image_url'] : '';
                $media['image_source'] = !empty($selector_candidate['image_source']) ? $selector_candidate['image_source'] : 'selector:' . $image_selector_class;
                $media['image_class'] = !empty($selector_candidate['image_class']) ? $selector_candidate['image_class'] : $image_selector_class;
                $media['image_attr'] = !empty($selector_candidate['image_attr']) ? $selector_candidate['image_attr'] : '';
                $media['image_tag'] = !empty($selector_candidate['image_tag']) ? $selector_candidate['image_tag'] : 'selector';
            }
        }

        if ($media['image_url'] === '') {
            foreach (array('og:image', 'og:image:url', 'twitter:image', 'twitter:image:src', 'thumbnailUrl', 'image') as $key) {
                if ($media['image_url'] !== '') {
                    break;
                }
                if (!preg_match_all('/<meta[^>]+(?:property|name|itemprop)=["\']' . preg_quote($key, '/') . '["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
                    continue;
                }

                foreach ((array) $matches[1] as $candidate_url) {
                    $candidate = Content_Rank_Generator::resolve_url_against_base(html_entity_decode($candidate_url, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $base_url);
                    if ($candidate === '' || Content_Rank_Generator::is_probably_bad_featured_image_url($candidate, $base_url)) {
                        continue;
                    }
                    $media['image_url'] = $candidate;
                    $media['image_source'] = $key;
                    $media['image_tag'] = 'meta';
                    $media['image_attr'] = 'content';
                    break 2;
                }
            }

            if ($media['image_url'] === '' && $image_selector_class !== '') {
                $selector_candidate = self::extract_selector_media_candidate_from_html($html, $base_url, $image_selector_class, 'image');
                if (!empty($selector_candidate['image_url'])) {
                    $media['image_url'] = !empty($selector_candidate['image_url']) ? $selector_candidate['image_url'] : '';
                    $media['image_source'] = !empty($selector_candidate['image_source']) ? $selector_candidate['image_source'] : 'selector:' . $image_selector_class;
                    $media['image_class'] = !empty($selector_candidate['image_class']) ? $selector_candidate['image_class'] : $image_selector_class;
                    $media['image_attr'] = !empty($selector_candidate['image_attr']) ? $selector_candidate['image_attr'] : '';
                    $media['image_tag'] = !empty($selector_candidate['image_tag']) ? $selector_candidate['image_tag'] : 'selector';
                }
            }

            if ($media['image_url'] === '') {
                $image_attributes = array('data-img-url', 'data-src', 'data-lazy-src', 'data-original', 'data-url', 'data-full', 'data-large');
                foreach ($image_attributes as $attribute) {
                    if (!preg_match_all('/<' . '[^>]+\\b' . preg_quote($attribute, '/') . '=["\']([^"\']+)["\']/i', $html, $matches)) {
                        continue;
                    }

                    foreach ((array) $matches[1] as $candidate_url) {
                        $candidate_url = trim((string) $candidate_url);
                        if ($candidate_url === '') {
                            continue;
                        }
                        $candidate_url = Content_Rank_Generator::resolve_url_against_base(html_entity_decode($candidate_url, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $base_url);
                        if ($candidate_url === '' || Content_Rank_Generator::is_probably_bad_featured_image_url($candidate_url, $base_url)) {
                            continue;
                        }
                        $media['image_url'] = $candidate_url;
                        $media['image_source'] = $attribute;
                        $media['image_tag'] = 'attr';
                        $media['image_attr'] = $attribute;
                        break 2;
                    }
                }
            }

            if ($media['image_url'] === '') {
                foreach (array('srcset', 'data-srcset') as $attribute) {
                    if (!preg_match_all('/<' . '[^>]+\\b' . preg_quote($attribute, '/') . '=["\']([^"\']+)["\']/i', $html, $matches)) {
                        continue;
                    }

                    foreach ((array) $matches[1] as $candidate_set) {
                        $candidate_url = self::pick_best_srcset_url((string) $candidate_set);
                        $candidate_url = Content_Rank_Generator::resolve_url_against_base(html_entity_decode($candidate_url, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $base_url);
                        if ($candidate_url === '' || Content_Rank_Generator::is_probably_bad_featured_image_url($candidate_url, $base_url)) {
                            continue;
                        }
                        $media['image_url'] = $candidate_url;
                        $media['image_source'] = $attribute;
                        $media['image_tag'] = 'source';
                        $media['image_attr'] = $attribute;
                        break 2;
                    }
                }
            }

            if ($media['image_url'] === '') {
                if (preg_match_all('/<img\b[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
                    foreach ((array) $matches[1] as $candidate_url) {
                        $candidate_url = Content_Rank_Generator::resolve_url_against_base(html_entity_decode($candidate_url, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $base_url);
                        if ($candidate_url === '' || Content_Rank_Generator::is_probably_bad_featured_image_url($candidate_url, $base_url)) {
                            continue;
                        }
                        $media['image_url'] = $candidate_url;
                        $media['image_source'] = 'img_tag_match';
                        $media['image_tag'] = 'img';
                        $media['image_attr'] = 'src';
                        break;
                    }
                }
            }
        }

        if ($link_selector_class !== '') {
            $link_candidate = self::extract_selector_media_candidate_from_html($html, $base_url, $link_selector_class, 'link');
            if (!empty($link_candidate['link_url'])) {
                $media['link_url'] = $link_candidate['link_url'];
                $media['link_text'] = !empty($link_candidate['link_text']) ? $link_candidate['link_text'] : '';
                $media['link_source'] = !empty($link_candidate['link_source']) ? $link_candidate['link_source'] : '';
            }
        }

        if ($media['link_url'] === '') {
            $link_candidate = self::extract_primary_external_link_from_html($html, $base_url);
            if (!empty($link_candidate['link_url'])) {
                $media['link_url'] = $link_candidate['link_url'];
                $media['link_text'] = !empty($link_candidate['link_text']) ? $link_candidate['link_text'] : '';
                $media['link_source'] = !empty($link_candidate['link_source']) ? $link_candidate['link_source'] : '';
            }
        }

        // Video capture is automatic: use the first valid video on the page.
        $video_candidate = Content_Rank_Generator::extract_video_candidate_from_html($html, $base_url, '');
        if (!empty($video_candidate['video_url'])) {
            $media['video_url'] = $video_candidate['video_url'];
        }
        if (!empty($video_candidate['video_embed_html'])) {
            $media['video_embed_html'] = $video_candidate['video_embed_html'];
        }
        if (!empty($video_candidate['video_source'])) {
            $media['video_source'] = $video_candidate['video_source'];
        }

        return $media;
    }

    public static function extract_media_from_source_page($url,  $video_selector_class = '', $image_selector_class = '', $link_selector_class = '', $prefer_selector_image = true)
    {
        $empty_media = array(
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

        $url = esc_url_raw(trim((string) $url));
        if ($url === '') {
            return $empty_media;
        }

        $raw_html = self::fetch_source_page_html($url, 5, 'source_page_media');
        $raw_video = self::extract_video_from_raw_source_html($raw_html, $url);
        $featured_image = self::extract_featured_image_from_html($raw_html, $url);
        $html = self::strip_source_page_noise_from_html($raw_html);
        if ($html === '') {
            return wp_parse_args($raw_video, $empty_media);
        }

        $media = self::extract_media_from_html($html, $url, $video_selector_class, $image_selector_class, $link_selector_class, $prefer_selector_image);
        foreach (array('image_url', 'image_source', 'image_class', 'image_attr', 'image_tag') as $image_key) {
            $media[$image_key] = !empty($featured_image[$image_key]) ? $featured_image[$image_key] : '';
        }
        foreach (array('video_url', 'video_embed_html', 'video_source') as $video_key) {
            if (!empty($raw_video[$video_key])) {
                $media[$video_key] = $raw_video[$video_key];
            }
        }
        if ($media['video_url'] === '') {
            foreach (array('og:video', 'og:video:url', 'twitter:player:stream') as $key) {
                if (preg_match('/<meta[^>]+(?:property|name|itemprop)=["\']' . preg_quote($key, '/') . '["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
                    $candidate = Content_Rank_Generator::resolve_url_against_base(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $url);
                    if ($candidate !== '') {
                        $media['video_url'] = $candidate;
                        break;
                    }
                }
            }
        }

        $media = wp_parse_args($media, $empty_media);
        return $media;
    }

    public static function normalize_selector_class_tokens($selector_class)
    {
        $selector_class = trim(preg_replace('/\s+/', ' ', (string) $selector_class));
        if ($selector_class === '') {
            return array();
        }

        $tokens = preg_split('/\s+/', $selector_class);
        if (empty($tokens)) {
            return array();
        }

        $clean_tokens = array();
        foreach ($tokens as $token) {
            $token = sanitize_html_class(trim((string) $token));
            if ($token !== '') {
                $clean_tokens[] = $token;
            }
        }

        return array_values(array_unique($clean_tokens));
    }

    public static function node_matches_class_selector($node, $selector_class)
    {
        if (!($node instanceof DOMElement)) {
            return false;
        }

        $selector_tokens = self::normalize_selector_class_tokens($selector_class);
        if (empty($selector_tokens)) {
            return false;
        }

        if (!$node->hasAttribute('class')) {
            return false;
        }

        $node_tokens = preg_split('/\s+/', trim((string) $node->getAttribute('class')));
        if (empty($node_tokens)) {
            return false;
        }

        $normalized_node_tokens = array();
        foreach ($node_tokens as $token) {
            $token = sanitize_html_class(trim((string) $token));
            if ($token !== '') {
                $normalized_node_tokens[] = $token;
            }
        }

        if (empty($normalized_node_tokens)) {
            return false;
        }

        foreach ($selector_tokens as $selector_token) {
            if (!in_array($selector_token, $normalized_node_tokens, true)) {
                return false;
            }
        }

        return true;
    }

    public static function extract_page_outline_from_html($html, $base_url = '', $max_sections = 6, $max_links_per_section = 5, $max_images_per_section = 3, $image_selector_class = '', $link_selector_class = '', $content_selector = '')
    {
        $html = self::strip_source_page_noise_from_html($html, $content_selector);
        if ($html === '') {
            return array();
        }

        $content_selector = trim((string) $content_selector);
        if ($content_selector !== '') {
            $selected_html = self::extract_html_from_html_with_fallbacks($html, $content_selector);
            if ($selected_html !== '') {
                $html = $selected_html;
            }
        }

        $max_sections = max(0, intval($max_sections));
        $max_links_per_section = max(0, intval($max_links_per_section));
        $max_images_per_section = max(0, intval($max_images_per_section));
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!@$dom->loadHTML('<?xml encoding="UTF-8">' . $html)) {
            return array();
        }

        $xpath = new DOMXPath($dom);
        $h2_nodes = $xpath->query('//h2');
        $h3_nodes = $xpath->query('//h3');
        $h2_count = ($h2_nodes && $h2_nodes->length > 0) ? $h2_nodes->length : 0;
        $h3_count = ($h3_nodes && $h3_nodes->length > 0) ? $h3_nodes->length : 0;
        if ($h2_count === 0 && $h3_count === 0) {
            return array();
        }

        // H2 is the stable section boundary used by the generated article.
        // Use H3 only when the source has no H2 at all.
        $heading_nodes = $h2_count > 0 ? $h2_nodes : $h3_nodes;

        $outline = array();
        for ($i = 0; $i < $heading_nodes->length && ($max_sections === 0 || count($outline) < $max_sections); $i++) {
            $heading = $heading_nodes->item($i);
            if (!($heading instanceof DOMElement)) {
                continue;
            }

            $title = self::clean_source_text($heading->textContent);
            if ($title === '' || self::is_auxiliary_outline_heading_text_v2($title)) {
                continue;
            }

            $heading_tag = strtolower((string) $heading->nodeName);
            $heading_level = in_array($heading_tag, array('h2', 'h3'), true) ? intval(substr($heading_tag, 1)) : 2;
            $section_links = array();
            $section_images = array();
            $seen_links = array();
            $seen_images = array();
            $section_text_parts = array();

            $cursor = $heading->nextSibling;
            while ($cursor) {
                if ($cursor->nodeType === XML_ELEMENT_NODE) {
                    $tag = strtolower((string) $cursor->nodeName);
                    if ($tag === 'h1' || $tag === 'h2' || $tag === 'h3') {
                        $cursor_level = intval(substr($tag, 1));
                        if ($cursor_level <= $heading_level) {
                            break;
                        }
                    }
                    $cursor_text = self::clean_source_text($cursor->textContent);
                    if ($cursor_text !== '') {
                        $section_text_parts[] = $cursor_text;
                    }
                    self::collect_page_outline_media_from_node(
                        $cursor,
                        $base_url,
                        $section_links,
                        $section_images,
                        $seen_links,
                        $seen_images,
                        $max_links_per_section,
                        $max_images_per_section,
                        $image_selector_class,
                        $link_selector_class
                    );
                    if (($max_links_per_section > 0 && count($section_links) >= $max_links_per_section) && ($max_images_per_section > 0 && count($section_images) >= $max_images_per_section)) {
                        break;
                    }
                }

                $cursor = $cursor->nextSibling;
            }

            $outline_item = array(
                'h2' => $title,
                'heading_level' => $heading_level,
                'text' => trim(mb_substr(implode(' ', array_slice($section_text_parts, 0, 4)), 0, 600)),
                'image_selector_class' => $image_selector_class,
                'link_selector_class' => $link_selector_class,
                'links' => $section_links,
                'images' => $section_images,
            );
            $outline[] = $outline_item;
        }

        return $outline;
    }

    /**
     * Build an image-only source context for interval-based content media.
     * This intentionally ignores headings and image title matching.
     */
    public static function extract_source_content_image_sections_from_html($html, $base_url = '', $content_selector = '', $max_images = 50)
    {
        $html = trim((string) $html);
        if ($html === '' || !class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            return array();
        }

        $content_selector = trim((string) $content_selector);
        if ($content_selector !== '') {
            $selected_html = self::extract_html_from_html_with_fallbacks($html, $content_selector);
        } else {
            $selected_html = self::extract_html_from_html_with_fallbacks($html, '');
        }
        if ($selected_html === '') {
            $selected_html = self::strip_source_page_noise_from_html($html);
        }
        if ($selected_html === '') {
            return array();
        }

        $max_images = max(1, min(100, intval($max_images)));
        $previous_libxml_state = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8">' . $selected_html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_libxml_state);
        if (!$loaded) {
            return array();
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*');
        if (!$nodes) {
            return array();
        }

        $images = array();
        $seen_images = array();
        foreach ($nodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }

            $links = array();
            $node_images = array();
            $seen_links = array();
            self::collect_page_outline_media_from_node(
                $node,
                $base_url,
                $links,
                $node_images,
                $seen_links,
                $seen_images,
                0,
                $max_images,
                '',
                ''
            );
            foreach ($node_images as $image) {
                if (!is_array($image) || empty($image['url'])) {
                    continue;
                }
                $images[] = $image;
                if (count($images) >= $max_images) {
                    break 2;
                }
            }
        }

        if (empty($images)) {
            return array();
        }

        return array(
            array(
                'h2' => '',
                'heading_level' => 2,
                'images' => $images,
            ),
        );
    }

    /**
     * Return source images for keyword-list and spreadsheet content.
     * Stored outline data is preferred; raw source HTML is the fallback.
     */
    public static function resolve_content_image_sections_for_item($item, $generator = array())
    {
        $item = is_array($item) ? $item : array();
        $generator = is_array($generator) ? $generator : array();
        $sections = !empty($item['source_page_outline_sections']) && is_array($item['source_page_outline_sections'])
            ? $item['source_page_outline_sections']
            : array();

        $content_selector = !empty($generator['content_selector'])
            ? sanitize_text_field((string) $generator['content_selector'])
            : '';
        $base_url = !empty($item['permalink'])
            ? trim((string) $item['permalink'])
            : (!empty($item['source_url']) ? trim((string) $item['source_url']) : '');

        $resolved_sections = $sections;
        $seen_image_urls = array();
        foreach ($resolved_sections as $section) {
            if (empty($section['images']) || !is_array($section['images'])) {
                continue;
            }
            foreach ($section['images'] as $image) {
                if (!empty($image['url'])) {
                    $image_key = self::normalize_image_url_for_comparison($image['url']);
                    if ($image_key !== '') {
                        $seen_image_urls[$image_key] = true;
                    }
                }
            }
        }

        foreach (array('source_page_content_html', 'source_page_html') as $source_key) {
            if (empty($item[$source_key])) {
                continue;
            }

            $fallback_sections = self::extract_source_content_image_sections_from_html(
                (string) $item[$source_key],
                $base_url,
                $source_key === 'source_page_content_html' ? '' : $content_selector,
                50
            );
            if (!empty($fallback_sections)) {
                foreach ($fallback_sections as $fallback_section) {
                    if (empty($fallback_section['images']) || !is_array($fallback_section['images'])) {
                        continue;
                    }
                    foreach ($fallback_section['images'] as $image) {
                        if (empty($image['url'])) {
                            continue;
                        }
                        $image_key = self::normalize_image_url_for_comparison($image['url']);
                        if ($image_key === '' || isset($seen_image_urls[$image_key])) {
                            continue;
                        }
                        $seen_image_urls[$image_key] = true;
                        $resolved_sections[] = array(
                            'h2' => '',
                            'heading_level' => 2,
                            'images' => array($image),
                        );
                    }
                }
            }
        }

        return $resolved_sections;
    }

    public static function collect_page_outline_media_from_node($node, $base_url, array &$links, array &$images, array &$seen_links, array &$seen_images, $max_links = 5, $max_images = 3, $image_selector_class = '', $link_selector_class = '', $image_scope_active = false, $link_scope_active = false)
    {
        if (!($node instanceof DOMElement)) {
            return;
        }

        $tag = strtolower((string) $node->nodeName);
        if (in_array($tag, array('script', 'style', 'noscript'), true)) {
            return;
        }

        $matches_image_selector = $image_selector_class !== '' && self::node_matches_class_selector($node, $image_selector_class);
        $matches_link_selector = $link_selector_class !== '' && self::node_matches_class_selector($node, $link_selector_class);
        $image_scope_active = $image_scope_active || $matches_image_selector;
        $link_scope_active = $link_scope_active || $matches_link_selector;

        if ($max_links > 0 && count($links) < $max_links && $node->hasAttribute('href')) {
            if ($link_selector_class === '' || $link_scope_active || $matches_link_selector) {
                $href = html_entity_decode(trim((string) $node->getAttribute('href')), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
                if ($href !== '' && !preg_match('~^(javascript:|mailto:|tel:|#)~i', $href)) {
                    $resolved = self::resolve_url_against_base($href, $base_url);
                    if ($resolved !== '' && preg_match('~^https?://~i', $resolved) && !isset($seen_links[$resolved])) {
                        $seen_links[$resolved] = true;
                        $links[] = array(
                            'text' => self::clean_source_text($node->textContent),
                            'url' => $resolved,
                            'source' => $tag,
                        );
                    }
                }
            }
        }

        if ($max_images > 0 && count($images) < $max_images && ($image_selector_class === '' || $image_scope_active || $matches_image_selector)) {
            $image_url = '';
            $image_attr = '';
            $candidate_attrs = array('data-img-url', 'data-src', 'data-lazy-src', 'data-original', 'data-url', 'data-full', 'data-large', 'src', 'srcset', 'data-srcset');
            foreach ($candidate_attrs as $attribute) {
                if (!$node->hasAttribute($attribute)) {
                    continue;
                }

                $value = trim((string) $node->getAttribute($attribute));
                if ($value === '') {
                    continue;
                }

                if ($attribute === 'srcset' || $attribute === 'data-srcset') {
                    $value = self::pick_best_srcset_url($value);
                }

                $candidate = self::resolve_url_against_base(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $base_url);
                if ($candidate === '' || Content_Rank_Generator::is_probably_bad_featured_image_url($candidate, $base_url)) {
                    continue;
                }

                $image_url = $candidate;
                $image_attr = $attribute;
                break;
            }

            if ($image_url !== '' && !isset($seen_images[$image_url])) {
                $seen_images[$image_url] = true;
                $images[] = array(
                    'url' => $image_url,
                    'attr' => $image_attr,
                    'source' => $tag,
                );
            }
        }

        foreach ($node->childNodes as $child) {
            if (($max_links > 0 && count($links) >= $max_links) && ($max_images > 0 && count($images) >= $max_images)) {
                break;
            }
            self::collect_page_outline_media_from_node(
                $child,
                $base_url,
                $links,
                $images,
                $seen_links,
                $seen_images,
                $max_links,
                $max_images,
                $image_selector_class,
                $link_selector_class,
                $image_scope_active,
                $link_scope_active
            );
        }
    }

    public static function format_page_outline_for_prompt($outline)
    {
        if (!is_array($outline) || empty($outline)) {
            return '';
        }

        $lines = array();
        foreach ($outline as $section_index => $section) {
            if (!is_array($section)) {
                continue;
            }

            $title = isset($section['h2']) ? self::clean_source_text($section['h2']) : '';
            if ($title === '') {
                continue;
            }

            $heading_level = isset($section['heading_level']) ? intval($section['heading_level']) : 2;
            $heading_label = 'H' . ($heading_level > 0 ? $heading_level : 2);
            $lines[] = $heading_label . ' ' . ($section_index + 1) . ': ' . $title;

            $section_text = isset($section['text']) ? self::clean_source_text($section['text']) : '';
            if ($section_text !== '') {
                $lines[] = 'Texto: ' . wp_trim_words($section_text, 30);
            }

            $image_selector_class = isset($section['image_selector_class']) ? self::clean_source_text($section['image_selector_class']) : '';

            $images = isset($section['images']) && is_array($section['images']) ? $section['images'] : array();
            if (!empty($images)) {
                $image_parts = array();
                foreach ($images as $image) {
                    if (!is_array($image) || empty($image['url'])) {
                        continue;
                    }
                    $image_parts[] = trim((string) $image['url']);
                }
                if (!empty($image_parts)) {
                    $label = $image_selector_class !== '' ? 'Imagens da classe ' . $image_selector_class : 'Imagens neste H2';
                    $lines[] = $label . ': ' . implode(' | ', array_slice($image_parts, 0, 10));
                }
            }

            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    public static function parse_source_link_cta_phrases($phrases)
    {
        if (is_array($phrases)) {
            $phrases = implode("\n", $phrases);
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) $phrases);
        $items = array();
        foreach ((array) $lines as $line) {
            $line = sanitize_text_field(trim((string) $line));
            if ($line !== '') {
                $items[] = $line;
            }
        }

        $items = array_values(array_unique($items));
        if (empty($items) && class_exists('Content_Rank_Generator')) {
            $items = preg_split('/\r\n|\r|\n/', Content_Rank_Generator::get_default_source_link_cta_phrases());
            $items = array_values(array_filter(array_map('sanitize_text_field', (array) $items)));
        }

        return $items;
    }

    public static function parse_source_context_filter_phrases($phrases)
    {
        if (is_array($phrases)) {
            $phrases = implode("\n", $phrases);
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) $phrases);
        $items = array();
        foreach ((array) $lines as $line) {
            $line = sanitize_text_field(trim((string) $line));
            if ($line !== '') {
                $items[] = $line;
            }
        }

        return array_values(array_unique($items));
    }

    public static function normalize_source_context_filters($filters)
    {
        if (is_string($filters)) {
            $filters = trim($filters);
            if ($filters === '') {
                $filters = array();
            } else {
                $decoded = json_decode($filters, true);
                $filters = is_array($decoded) ? $decoded : array();
            }
        }

        if (!is_array($filters)) {
            $filters = array();
        }

        $exclude_phrases = array();
        if (!empty($filters['exclude_phrases'])) {
            $exclude_phrases = self::parse_source_context_filter_phrases($filters['exclude_phrases']);
        } elseif (!empty($filters['exclude'])) {
            $exclude_phrases = self::parse_source_context_filter_phrases($filters['exclude']);
        }

        $rating_label = '';
        if (isset($filters['rating_label'])) {
            $rating_label = sanitize_text_field((string) $filters['rating_label']);
        }
        if ($rating_label === '') {
            $rating_label = 'IMDb';
        }

        $min_rating = 0.0;
        if (isset($filters['min_rating']) && $filters['min_rating'] !== '') {
            $min_rating = floatval(str_replace(',', '.', (string) $filters['min_rating']));
        }

        $keep_unrated = !empty($filters['keep_unrated']) ? 1 : 0;

        return array(
            'exclude_phrases' => $exclude_phrases,
            'rating_label' => $rating_label,
            'min_rating' => max(0, $min_rating),
            'keep_unrated' => $keep_unrated,
        );
    }

    public static function extract_source_context_rating_from_text($text, $rating_label = '')
    {
        $text = self::clean_source_text($text);
        if ($text === '') {
            return null;
        }

        $rating_label = trim((string) $rating_label);
        if ($rating_label !== '') {
            $pattern = '~' . preg_quote($rating_label, '~') . '\s*(?:[:\-\Ã¢â‚¬â€œ]?\s*)?(\d{1,2}(?:[.,]\d+)?)\s*(?:/\s*10)?~i';
            if (preg_match($pattern, $text, $matches) && isset($matches[1])) {
                return floatval(str_replace(',', '.', $matches[1]));
            }
        }

        if (preg_match('~(\d{1,2}(?:[.,]\d+)?)\s*/\s*10~i', $text, $matches) && isset($matches[1])) {
            return floatval(str_replace(',', '.', $matches[1]));
        }

        return null;
    }

    protected static function source_context_section_haystack($section)
    {
        if (!is_array($section)) {
            return '';
        }

        $parts = array();
        if (!empty($section['h2'])) {
            $parts[] = self::clean_source_text($section['h2']);
        }
        if (!empty($section['text'])) {
            $parts[] = self::clean_source_text($section['text']);
        }

        if (!empty($section['links']) && is_array($section['links'])) {
            foreach ($section['links'] as $link) {
                if (!is_array($link) || empty($link['url'])) {
                    continue;
                }
                $link_text = !empty($link['text']) ? self::clean_source_text($link['text']) : '';
                $link_url = trim((string) $link['url']);
                if ($link_text !== '') {
                    $parts[] = $link_text;
                }
                if ($link_url !== '') {
                    $parts[] = $link_url;
                }
            }
        }

        if (!empty($section['images']) && is_array($section['images'])) {
            foreach ($section['images'] as $image) {
                if (!is_array($image) || empty($image['url'])) {
                    continue;
                }
                $parts[] = trim((string) $image['url']);
            }
        }

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts)))), 'UTF-8');
    }

    public static function source_context_section_matches_filters($section, $filters)
    {
        $section = is_array($section) ? $section : array();
        $filters = self::normalize_source_context_filters($filters);

        $haystack = self::source_context_section_haystack($section);
        if ($haystack === '') {
            return true;
        }

        foreach ($filters['exclude_phrases'] as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase === '') {
                continue;
            }
            if (mb_stripos($haystack, mb_strtolower($phrase, 'UTF-8'), 0, 'UTF-8') !== false) {
                return false;
            }
        }

        $min_rating = isset($filters['min_rating']) ? floatval($filters['min_rating']) : 0.0;
        if ($min_rating > 0) {
            $rating_label = isset($filters['rating_label']) ? (string) $filters['rating_label'] : '';
            $rating_text = trim((string) (isset($section['h2']) ? $section['h2'] : '') . ' ' . (isset($section['text']) ? $section['text'] : ''));
            $rating = self::extract_source_context_rating_from_text($rating_text, $rating_label);
            if ($rating === null) {
                if (empty($filters['keep_unrated'])) {
                    return false;
                }

                $confidence_text = self::clean_source_text($rating_text);
                $confidence_length = function_exists('mb_strlen') ? mb_strlen($confidence_text, 'UTF-8') : strlen($confidence_text);
                $word_count = 0;
                if ($confidence_text !== '') {
                    $words = preg_split('/\s+/u', $confidence_text);
                    if (is_array($words)) {
                        foreach ($words as $word) {
                            if (trim((string) $word) !== '') {
                                $word_count++;
                            }
                        }
                    }
                }

                if ($word_count <= 3 || $confidence_length < 80) {
                    return false;
                }

                return true;
            }
            if ($rating < $min_rating) {
                return false;
            }
        }

        return true;
    }

    public static function source_context_item_matches_filters($item, $filters)
    {
        $item = is_array($item) ? $item : array();
        $filters = self::normalize_source_context_filters($filters);

        $headline_parts = array();
        foreach (array('source_title', 'title', 'keyword', 'feed_title') as $key) {
            if (!empty($item[$key])) {
                $headline_parts[] = self::clean_source_text($item[$key]);
            }
        }

        $text_parts = array();
        foreach (array('source_page_excerpt', 'source_page_content', 'excerpt', 'content', 'source_link_text') as $key) {
            if (!empty($item[$key])) {
                $text_parts[] = self::clean_source_text($item[$key]);
            }
        }

        if (!empty($item['row_data'])) {
            if (is_array($item['row_data']) || is_object($item['row_data'])) {
                $encoded_row_data = wp_json_encode($item['row_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded_row_data !== false && $encoded_row_data !== '') {
                    $text_parts[] = self::clean_source_text($encoded_row_data);
                }
            } else {
                $text_parts[] = self::clean_source_text($item['row_data']);
            }
        }

        $section = array(
            'h2' => !empty($headline_parts) ? implode(' | ', array_filter($headline_parts)) : '',
            'text' => !empty($text_parts) ? implode("\n\n", array_filter($text_parts)) : '',
            'links' => array(),
            'images' => array(),
        );

        foreach (array('source_link_url', 'source_url', 'permalink') as $key) {
            if (empty($item[$key])) {
                continue;
            }
            $section['links'][] = array(
                'url' => trim((string) $item[$key]),
                'text' => !empty($item['source_link_text']) ? self::clean_source_text($item['source_link_text']) : '',
            );
        }

        if (!empty($item['source_image_url'])) {
            $section['images'][] = array(
                'url' => trim((string) $item['source_image_url']),
            );
        }

        return self::source_context_section_matches_filters($section, $filters);
    }

    public static function filter_source_outline_sections($outline, $filters)
    {
        if (!is_array($outline) || empty($outline)) {
            return array();
        }

        $filters = self::normalize_source_context_filters($filters);
        if (empty($filters['exclude_phrases']) && empty($filters['min_rating'])) {
            return $outline;
        }

        $filtered = array();
        foreach ($outline as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (self::source_context_section_matches_filters($section, $filters)) {
                $filtered[] = $section;
            }
        }

        return $filtered;
    }

    public static function filter_source_page_content($content, $filters)
    {
        $content = trim((string) $content);
        if ($content === '') {
            return '';
        }

        $filters = self::normalize_source_context_filters($filters);
        if (empty($filters['exclude_phrases']) && empty($filters['min_rating'])) {
            return $content;
        }

        $chunks = preg_split('/\n{2,}/', $content);
        if (empty($chunks)) {
            return $content;
        }

        $filtered = array();
        foreach ((array) $chunks as $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk === '') {
                continue;
            }

            $chunk_section = array(
                'h2' => '',
                'text' => $chunk,
                'links' => array(),
                'images' => array(),
            );
            if (self::source_context_section_matches_filters($chunk_section, $filters)) {
                $filtered[] = $chunk;
            }
        }

        if (empty($filtered)) {
            return $content;
        }

        return trim(implode("\n\n", $filtered));
    }

    public static function apply_source_context_filters_to_page_context($page_context, $filters)
    {
        if (!is_array($page_context) || empty($page_context)) {
            return $page_context;
        }

        $filters = self::normalize_source_context_filters($filters);
        if (empty($filters['exclude_phrases']) && empty($filters['min_rating'])) {
            return $page_context;
        }

        if (!empty($page_context['outline']) && is_array($page_context['outline'])) {
            $filtered_outline = self::filter_source_outline_sections($page_context['outline'], $filters);
            $page_context['outline'] = $filtered_outline;
            $page_context['outline_sections'] = $filtered_outline;
            $page_context['outline_text'] = self::format_page_outline_for_prompt($filtered_outline);
        }

        if (!empty($page_context['content'])) {
            $filtered_content = self::filter_source_page_content($page_context['content'], $filters);
            $page_context['content'] = $filtered_content;
            $page_context['excerpt'] = $filtered_content !== '' ? wp_trim_words($filtered_content, 24) : '';
        }

        return $page_context;
    }

    public static function normalize_tavily_image_candidates($context)
    {
        $context = is_array($context) ? $context : array();
        $candidates = array();
        $seen = array();

        $append_candidate = function ($candidate, $source_label = '') use (&$candidates, &$seen) {
            if (is_string($candidate)) {
                $candidate = array('url' => $candidate);
            }
            if (!is_array($candidate)) {
                return;
            }

            $url = '';
            foreach (array('url', 'image_url', 'source_url', 'thumbnail_url', 'src', 'link') as $key) {
                if (!empty($candidate[$key])) {
                    $url = trim((string) $candidate[$key]);
                    break;
                }
            }
            $url = esc_url_raw($url);
            if ($url === '' || !Content_Rank_Generator::url_looks_like_image($url)) {
                return;
            }
            if (isset($seen[$url])) {
                return;
            }

            $seen[$url] = true;
            $description = '';
            foreach (array('description', 'alt', 'text', 'summary', 'content') as $key) {
                if (!empty($candidate[$key])) {
                    $description = self::normalize_plain_text((string) $candidate[$key]);
                    if ($description !== '') {
                        break;
                    }
                }
            }

            $candidates[] = array(
                'url' => $url,
                'title' => !empty($candidate['title']) ? self::normalize_plain_text((string) $candidate['title']) : '',
                'description' => $description,
                'score' => isset($candidate['score']) ? floatval($candidate['score']) : (isset($candidate['relevance_score']) ? floatval($candidate['relevance_score']) : 0),
                'source' => $source_label !== '' ? sanitize_key((string) $source_label) : (!empty($candidate['source']) ? sanitize_key((string) $candidate['source']) : ''),
            );
        };

        $append_candidates = function ($list, $source_label = '') use (&$append_candidate) {
            if (!is_array($list)) {
                return;
            }
            foreach ($list as $candidate) {
                $append_candidate($candidate, $source_label);
            }
        };

        if (!empty($context['images']) && is_array($context['images'])) {
            $append_candidates($context['images'], 'tavily');
        }

        if (!empty($context['results']) && is_array($context['results'])) {
            foreach ($context['results'] as $result) {
                if (!is_array($result)) {
                    continue;
                }
                if (!empty($result['images']) && is_array($result['images'])) {
                    $append_candidates($result['images'], 'tavily_result');
                }
            }
        }

        $looks_like_candidate_list = !empty($context) && array_keys($context) === range(0, count($context) - 1);
        if ($looks_like_candidate_list) {
            $append_candidates($context, 'tavily');
        }

        return $candidates;
    }

    public static function fetch_tavily_search_context($query, $max_results = 3, $include_answer = true, $include_images = true, $enabled_override = null)
    {
        $query = self::normalize_plain_text($query);
        if ($query === '') {
            return array();
        }

        if ($enabled_override === false) {
            return array();
        }

        $settings = class_exists('Content_Rank_Generator') ? Content_Rank_Generator::get_settings() : array();
        if (empty($settings['tavily_enabled'])) {
            return array();
        }

        $api_key = !empty($settings['tavily_api_key']) ? trim((string) $settings['tavily_api_key']) : '';
        if ($api_key === '') {
            return array();
        }

        $search_depth = !empty($settings['tavily_search_depth']) ? sanitize_key((string) $settings['tavily_search_depth']) : 'basic';
        if (!in_array($search_depth, array('basic', 'advanced'), true)) {
            $search_depth = 'basic';
        }

        $payload = array(
            'api_key' => $api_key,
            'query' => $query,
            'search_depth' => $search_depth,
            'max_results' => max(1, min(10, intval($max_results))),
            'include_answer' => !empty($include_answer),
            'include_images' => !empty($include_images),
            'include_image_descriptions' => !empty($include_images),
            'include_raw_content' => false,
        );

        $response = wp_remote_post('https://api.tavily.com/search', array(
            'timeout' => 40,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));

        if (is_wp_error($response)) {
            return array();
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            return array();
        }

        $raw = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($raw) || empty($raw)) {
            return array();
        }

        $results = array();
        if (!empty($raw['results']) && is_array($raw['results'])) {
            foreach ($raw['results'] as $result) {
                if (!is_array($result)) {
                    continue;
                }

                $normalized_result = array(
                    'title' => !empty($result['title']) ? self::normalize_plain_text((string) $result['title']) : '',
                    'url' => !empty($result['url']) ? esc_url_raw((string) $result['url']) : '',
                    'content' => !empty($result['content']) ? self::normalize_plain_text((string) $result['content']) : '',
                    'score' => isset($result['score']) ? floatval($result['score']) : 0.0,
                );
                if (!empty($result['images']) && is_array($result['images'])) {
                    $normalized_result['images'] = self::normalize_tavily_image_candidates(array('images' => $result['images']));
                }
                $results[] = $normalized_result;
            }
        }

        $context = array(
            'query' => $query,
            'answer' => !empty($raw['answer']) ? self::normalize_plain_text((string) $raw['answer']) : '',
            'results' => $results,
        );
        if (!empty($include_images)) {
            $context['images'] = self::normalize_tavily_image_candidates($raw);
        }

        return $context;
    }

    public static function format_tavily_context_for_prompt($context, $max_chars = 6000)
    {
        if (!is_array($context) || empty($context)) {
            return '';
        }

        $encoded = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            return '';
        }

        return self::limit_prompt_html_chars($encoded, max(500, intval($max_chars)));
    }

    public static function get_generator_editorial_context($generator)
    {
        $generator = is_array($generator) ? $generator : array();
        $name = !empty($generator['name'])
            ? self::normalize_plain_text((string) $generator['name'])
            : '';
        $category_ids = isset($generator['category_ids']) ? $generator['category_ids'] : array();
        if (is_string($category_ids)) {
            $decoded_category_ids = json_decode($category_ids, true);
            $category_ids = is_array($decoded_category_ids) ? $decoded_category_ids : array();
        }
        if (!is_array($category_ids)) {
            $category_ids = array();
        }
        if (!empty($generator['default_category_id'])) {
            $category_ids[] = intval($generator['default_category_id']);
        }
        $category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids))));

        $category_names = array();
        foreach ($category_ids as $category_id) {
            if (!function_exists('get_term')) {
                break;
            }
            $term = get_term($category_id, 'category');
            if (is_wp_error($term) || empty($term->name)) {
                continue;
            }
            $category_names[] = self::normalize_plain_text((string) $term->name);
        }
        $category_names = array_values(array_unique(array_filter($category_names)));

        return array(
            'name' => $name,
            'categories' => $category_names,
            'category_text' => implode(', ', $category_names),
        );
    }

    protected static function pick_random_text_variant($items, $fallback = '')
    {
        $items = array_values(array_filter(array_map('trim', (array) $items)));
        if (!empty($items)) {
            $random_key = array_rand($items);
            return (string) $items[$random_key];
        }

        return trim((string) $fallback);
    }

    public static function build_outline_section_image_html($section, $post_id = 0, $image_size = 'medium', $existing_image_map = array(), $section_index = -1, $fallback_image_candidates = array(), $excluded_image_urls = array())
    {
        if (!is_array($section)) {
            return '';
        }

        $section_title = isset($section['h2']) ? self::clean_source_text($section['h2']) : '';
        $post_id = intval($post_id);
        $image_size = Content_Rank_Generator::normalize_image_display_size($image_size);
        $existing_image_map = is_array($existing_image_map) ? $existing_image_map : array();
        $excluded_image_urls = is_array($excluded_image_urls) ? $excluded_image_urls : array();
        $excluded_lookup = array();
        foreach ($excluded_image_urls as $excluded_url) {
            $excluded_key = self::normalize_image_url_for_comparison($excluded_url);
            if ($excluded_key !== '') {
                $excluded_lookup[$excluded_key] = true;
            }
        }
        $existing_attachment_id = self::find_existing_outline_section_image_attachment_id_by_index($section_index, $existing_image_map);

        if ($existing_attachment_id > 0) {
            $existing_attachment_url = wp_get_attachment_url($existing_attachment_id);
            $existing_attachment_key = self::normalize_image_url_for_comparison($existing_attachment_url);
            if ($existing_attachment_key !== '' && isset($excluded_lookup[$existing_attachment_key])) {
                $existing_attachment_id = 0;
            }
        }

        if ($existing_attachment_id > 0) {
            $image_html = Content_Rank_Generator::build_attachment_image_figure_html($existing_attachment_id, $image_size, $section_title, 'alignnone');
            if ($image_html !== '') {
                return $image_html;
            }
        }

        $images = isset($section['images']) && is_array($section['images']) ? array_values($section['images']) : array();
        if (empty($images) && !empty($fallback_image_candidates) && is_array($fallback_image_candidates)) {
            $fallback_image_candidates = array_values(array_filter($fallback_image_candidates, function ($candidate) {
                if (is_string($candidate)) {
                    $candidate = array('url' => $candidate);
                }
                return is_array($candidate) && !empty($candidate['url']);
            }));
            if (!empty($excluded_lookup)) {
                $fallback_image_candidates = array_values(array_filter($fallback_image_candidates, function ($candidate) use ($excluded_lookup) {
                    $candidate_url = '';
                    if (is_array($candidate) && !empty($candidate['url'])) {
                        $candidate_url = trim((string) $candidate['url']);
                    }
                    $candidate_key = self::normalize_image_url_for_comparison($candidate_url);
                    return $candidate_key !== '' && !isset($excluded_lookup[$candidate_key]);
                }));
            }
            if (!empty($fallback_image_candidates)) {
                if ($section_index >= 0 && count($fallback_image_candidates) > 1) {
                    $offset = $section_index % count($fallback_image_candidates);
                    if ($offset > 0) {
                        $fallback_image_candidates = array_merge(
                            array_slice($fallback_image_candidates, $offset),
                            array_slice($fallback_image_candidates, 0, $offset)
                        );
                    }
                }
                $images = $fallback_image_candidates;
            }
        }
        if (!empty($excluded_lookup) && !empty($images)) {
            $images = array_values(array_filter($images, function ($image) use ($excluded_lookup) {
                if (!is_array($image) || empty($image['url'])) {
                    return false;
                }
                $image_url = trim((string) $image['url']);
                $image_key = self::normalize_image_url_for_comparison($image_url);
                return $image_key !== '' && !isset($excluded_lookup[$image_key]);
            }));
        }
        if (!empty($images)) {
            foreach ($images as $image_index => $image) {
                if (!is_array($image) || empty($image['url'])) {
                    continue;
                }
                $image_url = trim((string) $image['url']);
                if ($image_url === '') {
                    continue;
                }

                $alt_text = $section_title !== '' ? $section_title : 'Imagem relacionada';
                $attachment_id = Content_Rank_Generator::download_image_attachment_from_url($post_id, $image_url, $alt_text, 'content');
                if ($attachment_id > 0) {
                    return Content_Rank_Generator::build_attachment_image_figure_html($attachment_id, $image_size, $alt_text, 'alignnone');
                }
            }
        }

        return '';
    }

    protected static function normalize_image_url_for_comparison($url)
    {
        $url = html_entity_decode(trim((string) $url), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return strtolower(rtrim($url, '#'));
        }

        $host = strtolower((string) $parts['host']);
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        return $host . '/' . ltrim($path, '/');
    }

    public static function outline_section_has_existing_image($section, $existing_image_map = array(), $section_index = -1)
    {
        if (!is_array($section)) {
            return false;
        }

        $existing_image_map = is_array($existing_image_map) ? $existing_image_map : array();
        if (empty($existing_image_map)) {
            return false;
        }

        if ($section_index < 0) {
            return false;
        }

        $existing_attachment_id = self::find_existing_outline_section_image_attachment_id_by_index($section_index, $existing_image_map);
        if ($existing_attachment_id > 0) {
            return true;
        }

        return false;
    }

    public static function extract_first_outline_section_image_url($outline_sections)
    {
        if (!is_array($outline_sections) || empty($outline_sections)) {
            return '';
        }

        foreach ($outline_sections as $section) {
            if (!is_array($section) || empty($section['images']) || !is_array($section['images'])) {
                continue;
            }

            foreach ($section['images'] as $image) {
                if (!is_array($image) || empty($image['url'])) {
                    continue;
                }

                $image_url = trim((string) $image['url']);
                if ($image_url !== '' && !Content_Rank_Generator::is_probably_bad_featured_image_url($image_url, 'outline')) {
                    return $image_url;
                }
            }
        }

        return '';
    }

    public static function build_outline_section_link_html($section, $link_phrases = array())
    {
        if (!is_array($section)) {
            return '';
        }

        $section_title = isset($section['h2']) ? self::clean_source_text($section['h2']) : '';
        $links = isset($section['links']) && is_array($section['links']) ? array_values($section['links']) : array();
        if (empty($links)) {
            return '';
        }

        foreach ($links as $link_index => $link) {
            if (!is_array($link) || empty($link['url'])) {
                continue;
            }
            $link_url = trim((string) $link['url']);
            if ($link_url === '') {
                continue;
            }

            $link_text_options = self::parse_source_link_cta_phrases($link_phrases);
        $link_text = self::pick_random_text_variant($link_text_options, $section_title !== '' ? $section_title : __('Leia mais', 'content-rank'));

            return '<p class="content-rank-source-link"><a href="' . esc_url($link_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($link_text) . '</a></p>';
        }

        return '';
    }

    protected static function normalize_outline_section_match_text($text)
    {
        $text = self::normalize_prompt_context_text($text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/^\s*\d{1,3}\s*[\.\)\-:\/]*\s*/u', '', $text);
        $text = preg_replace('/\s*\((?:19|20)\d{2}(?:\s*[\-Ã¢â‚¬â€œ]\s*(?:19|20)\d{2})?\)\s*$/u', '', $text);
        $text = remove_accents($text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }

    protected static function score_outline_section_title_match($needle, $haystack)
    {
        $needle = self::normalize_outline_section_match_text($needle);
        $haystack = self::normalize_outline_section_match_text($haystack);
        if ($needle === '' || $haystack === '') {
            return 0;
        }

        if ($needle === $haystack) {
            return 100;
        }

        $score = 0;
        if (mb_stripos($haystack, $needle, 0, 'UTF-8') !== false || mb_stripos($needle, $haystack, 0, 'UTF-8') !== false) {
            $score += 40;
        }

        $similarity = 0;
        similar_text($needle, $haystack, $similarity);
        $score += min(45, (int) round($similarity / 2));

        $needle_tokens = array_values(array_filter(preg_split('/\s+/', $needle)));
        $haystack_tokens = array_values(array_filter(preg_split('/\s+/', $haystack)));
        if (!empty($needle_tokens) && !empty($haystack_tokens)) {
            $common_tokens = array_intersect($needle_tokens, $haystack_tokens);
            $score += min(15, count($common_tokens) * 5);
        }

        return min(100, $score);
    }

    protected static function normalize_outline_title_match_text($text)
    {
        $text = self::extract_outline_core_title_text($text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/^\s*\d{1,3}\s*[\.\)\-:\/]*\s*/u', '', $text);
        $text = preg_replace('/\s*\((?=[^)]*(?:netflix|dispon|available|country|countries|pais|paÃƒÂ­s))[^)]*\)\s*$/iu', '', $text);
        $text = preg_replace('/\s*[\-Ã¢â‚¬â€œÃ¢â‚¬â€]\s*(?=[^\-Ã¢â‚¬â€œÃ¢â‚¬â€]*?(?:netflix|dispon|available|country|countries|pais|paÃƒÂ­s)).*$/iu', '', $text);
        $text = preg_replace('/\s*\([^)]*(?:19|20)\d{2}[^)]*\)\s*$/u', '', $text);
        $text = preg_replace('/\s*-\s*(?:19|20)\d{2}\s*$/u', '', $text);
        $text = preg_replace('/\s*Ã¢â‚¬â€œ\s*(?:19|20)\d{2}\s*$/u', '', $text);
        $text = remove_accents($text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }

    protected static function extract_outline_core_title_text($text)
    {
        $original_text = self::normalize_prompt_context_text($text);
        if ($original_text === '') {
            return '';
        }

        $text = $original_text;

        if (preg_match('/[\'"](.+?)[\'"]/u', $text, $matches)) {
            $text = trim((string) $matches[1]);
        } elseif (preg_match('/^\s*\d{1,3}\s*[\.\)\-:\/]*\s*(.+?)\s*(?:\(|$)/u', $text, $matches)) {
            $text = trim((string) $matches[1]);
        }

        if ($text === '') {
            $text = $original_text;
        }

        $text = preg_replace('/^\s*\d{1,3}\s*[\.\)\-:\/]*\s*/u', '', $text);
        $text = preg_replace('/\s*\([^)]*\)\s*$/u', '', $text);
        $text = trim((string) $text);

        if ($text === '') {
            return $original_text;
        }

        return $text;
    }

    protected static function score_outline_title_match($needle, $haystack)
    {
        $needle = self::normalize_outline_title_match_text($needle);
        $haystack = self::normalize_outline_title_match_text($haystack);
        if ($needle === '' || $haystack === '') {
            return 0;
        }

        if ($needle === $haystack) {
            return 100;
        }

        $score = 0;
        if (mb_stripos($haystack, $needle, 0, 'UTF-8') !== false || mb_stripos($needle, $haystack, 0, 'UTF-8') !== false) {
            $score += 55;
        }

        $similarity = 0;
        similar_text($needle, $haystack, $similarity);
        $score += min(30, (int) round($similarity / 2));

        $needle_tokens = array_values(array_filter(preg_split('/\s+/', $needle)));
        $haystack_tokens = array_values(array_filter(preg_split('/\s+/', $haystack)));
        if (!empty($needle_tokens) && !empty($haystack_tokens)) {
            $common_tokens = array_intersect($needle_tokens, $haystack_tokens);
            $score += min(20, count($common_tokens) * 6);
        }

        return min(100, $score);
    }

    protected static function build_outline_section_semantic_text($section)
    {
        if (!is_array($section)) {
            return '';
        }

        $parts = array();
        if (!empty($section['h2'])) {
            $parts[] = self::clean_source_text($section['h2']);
        } elseif (!empty($section['title'])) {
            $parts[] = self::clean_source_text($section['title']);
        }
        if (!empty($section['text'])) {
            $parts[] = self::clean_source_text($section['text']);
        }

        $semantic_text = trim(implode(' ', array_filter($parts)));
        if ($semantic_text === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($semantic_text, 0, 700, 'UTF-8');
        }

        return substr($semantic_text, 0, 700);
    }

    protected static function find_best_outline_section_semantic_match($title, $outline_sections, $exclude_indexes = array())
    {
        if (!class_exists('Content_Rank_Generator')) {
            return null;
        }

        $title = self::normalize_prompt_context_text($title);
        if ($title === '') {
            return null;
        }

        $settings = Content_Rank_Generator::get_settings();
        if (empty($settings['semantic_dedup_enabled'])) {
            return null;
        }

        $threshold = isset($settings['semantic_dedup_threshold']) ? max(0.0, min(0.82, floatval($settings['semantic_dedup_threshold']))) : 0.72;

        $exclude_lookup = array();
        foreach ((array) $exclude_indexes as $exclude_index) {
            $exclude_lookup[intval($exclude_index)] = true;
        }

        $candidates = array();
        foreach (array_values((array) $outline_sections) as $index => $section) {
            if (isset($exclude_lookup[$index]) || !is_array($section)) {
                continue;
            }

            $semantic_text = self::build_outline_section_semantic_text($section);
            if ($semantic_text === '') {
                continue;
            }

            $candidates[] = array(
                'index' => $index,
                'section' => $section,
                'semantic_text' => $semantic_text,
            );
        }

        if (empty($candidates)) {
            return null;
        }

        $best_score = 0.0;
        $best_candidate = null;

        foreach ($candidates as $candidate) {
            $semantic_score = Content_Rank_Generator::calculate_semantic_title_fallback_score($title, $candidate['semantic_text']);
            if ($semantic_score > $best_score) {
                $best_score = $semantic_score;
                $best_candidate = $candidate;
            }
        }

        if ($best_candidate === null || $best_score < $threshold) {
            return null;
        }

        return array(
            'index' => intval($best_candidate['index']),
            'score' => $best_score,
            'section' => $best_candidate['section'],
            'mode' => 'text',
        );
    }

    protected static function find_existing_outline_section_image_attachment_id_by_index($section_index, $existing_image_map = array())
    {
        if (!class_exists('Content_Rank_Generator')) {
            return 0;
        }

        $section_index = intval($section_index);
        if ($section_index < 0 || !is_array($existing_image_map) || empty($existing_image_map)) {
            return 0;
        }

        if (isset($existing_image_map[$section_index]) && intval($existing_image_map[$section_index]) > 0) {
            return intval($existing_image_map[$section_index]);
        }

        $string_key = (string) $section_index;
        if (isset($existing_image_map[$string_key]) && intval($existing_image_map[$string_key]) > 0) {
            return intval($existing_image_map[$string_key]);
        }

        return 0;
    }

    protected static function build_outline_section_match_candidates($outline_sections, $exclude_indexes = array())
    {
        $candidates = array();
        if (!is_array($outline_sections) || empty($outline_sections)) {
            return $candidates;
        }

        $exclude_lookup = array();
        foreach ((array) $exclude_indexes as $exclude_index) {
            $exclude_lookup[intval($exclude_index)] = true;
        }

        foreach (array_values($outline_sections) as $index => $section) {
            if (isset($exclude_lookup[$index])) {
                continue;
            }
            if (!is_array($section)) {
                continue;
            }

            $candidate_title = '';
            if (!empty($section['h2'])) {
                $candidate_title = self::clean_source_text($section['h2']);
            } elseif (!empty($section['title'])) {
                $candidate_title = self::clean_source_text($section['title']);
            }

            $candidate_text = self::source_context_section_haystack($section);
            if ($candidate_title === '' && $candidate_text === '') {
                continue;
            }

            $candidates[] = array(
                'index' => $index,
                'title' => $candidate_title,
                'text' => $candidate_text,
            );
        }

        return $candidates;
    }

    protected static function choose_outline_section_match_via_ai($title, $outline_sections, $exclude_indexes = array(), $generator = array(), $context = array())
    {
        if (!class_exists('Content_Rank_Generator')) {
            return null;
        }

        $candidates = self::build_outline_section_match_candidates($outline_sections, $exclude_indexes);
        if (empty($candidates)) {
            return null;
        }

        $prompt_lines = array(
            'Voce deve escolher a melhor secao de origem para um titulo editorial gerado.',
            'O titulo pode ter numeracao, anos, intervalos de anos e frases extras de disponibilidade.',
            'Ignore ruido editorial. Encontre o melhor mapeamento sem exigir igualdade exata.',
            'Retorne apenas JSON valido com: matched_index, confidence, reason.',
            'Se nao houver correspondencia confiavel, use matched_index = -1.',
            'Titulo gerado: ' . self::normalize_prompt_context_text($title),
            'Candidatos:'
        );

        foreach ($candidates as $candidate) {
            $snippet = isset($candidate['text']) ? (string) $candidate['text'] : '';
            if (function_exists('mb_substr')) {
                $snippet = mb_substr($snippet, 0, 240, 'UTF-8');
            } else {
                $snippet = substr($snippet, 0, 240);
            }
            $prompt_lines[] = '- index=' . intval($candidate['index']) . ' | title=' . self::normalize_prompt_context_text($candidate['title']) . ' | text=' . self::normalize_prompt_context_text($snippet);
        }

        $prompt = implode("\n", $prompt_lines);
        $response = Content_Rank_Generator::request_openai_json($generator, $prompt, array(
            'stage' => 'outline_media_match',
            'post_id' => !empty($context['post_id']) ? intval($context['post_id']) : 0,
            'item_guid' => !empty($context['item_guid']) ? (string) $context['item_guid'] : '',
            'allow_missing_content_html' => 1,
            'preserve_extra_fields' => 1,
        ));

        if (is_wp_error($response) || !is_array($response)) {
            return null;
        }

        $matched_index = isset($response['matched_index']) ? intval($response['matched_index']) : (isset($response['index']) ? intval($response['index']) : -1);
        if ($matched_index < 0) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (intval($candidate['index']) !== $matched_index) {
                continue;
            }

            return array(
                'index' => $matched_index,
                'score' => isset($response['confidence']) ? intval($response['confidence']) : 80,
                'section' => $outline_sections[$matched_index],
                'mode' => 'ai',
            );
        }

        return null;
    }

    protected static function extract_block_heading_text($block)
    {
        if (!is_array($block)) {
            return '';
        }

        $text = '';
        if (!empty($block['innerHTML'])) {
            $text = self::clean_source_text($block['innerHTML']);
        }
        if ($text === '' && !empty($block['innerContent']) && is_array($block['innerContent'])) {
            $text = self::clean_source_text(implode('', array_map('strval', $block['innerContent'])));
        }

        return self::normalize_prompt_context_text($text);
    }

    protected static function find_best_outline_section_match($outline_sections, $title, $exclude_indexes = array(), $generator = array(), $context = array())
    {
        if (!is_array($outline_sections) || empty($outline_sections)) {
            return array(
                'index' => -1,
                'score' => 0,
                'section' => null,
            );
        }

        $raw_title = self::normalize_prompt_context_text($title);
        $title = self::normalize_outline_title_match_text($title);
        if ($title === '') {
            return array(
                'index' => -1,
                'score' => 0,
                'section' => null,
            );
        }

        foreach (array_values($outline_sections) as $exact_index => $exact_section) {
            if (!is_array($exact_section)) {
                continue;
            }

            $exact_parts = array();
            if (!empty($exact_section['h2'])) {
                $exact_parts[] = self::clean_source_text($exact_section['h2']);
            }
            if (!empty($exact_section['title'])) {
                $exact_parts[] = self::clean_source_text($exact_section['title']);
            }

            $exact_candidate_title = self::normalize_outline_title_match_text(implode(' ', array_filter($exact_parts)));
            if ($exact_candidate_title !== '' && $exact_candidate_title === $title) {
                return array(
                    'index' => $exact_index,
                    'score' => 100,
                    'section' => $exact_section,
                    'mode' => 'exact',
                );
            }
        }

        $exclude_lookup = array();
        foreach ((array) $exclude_indexes as $exclude_index) {
            $exclude_lookup[intval($exclude_index)] = true;
        }

        $best = array(
            'index' => -1,
            'score' => 0,
            'section' => null,
        );

        foreach (array_values($outline_sections) as $index => $section) {
            if (isset($exclude_lookup[$index])) {
                continue;
            }
            if (!is_array($section)) {
                continue;
            }

            $candidate_parts = array();
            if (!empty($section['h2'])) {
                $candidate_parts[] = self::clean_source_text($section['h2']);
            }
            if (!empty($section['title'])) {
                $candidate_parts[] = self::clean_source_text($section['title']);
            }

            $candidate_title = self::normalize_outline_title_match_text(implode(' ', array_filter($candidate_parts)));
            $candidate_haystack = self::source_context_section_haystack($section);
            if ($candidate_title === '' && $candidate_haystack === '') {
                continue;
            }

            $score = 0;
            if ($candidate_title !== '') {
                if ($title === $candidate_title) {
                    $score = 100;
                } elseif (mb_stripos($candidate_title, $title, 0, 'UTF-8') !== false || mb_stripos($title, $candidate_title, 0, 'UTF-8') !== false) {
                    $score = 95;
                }
            }
            if ($score > $best['score']) {
                $best = array(
                    'index' => $index,
                    'score' => $score,
                    'section' => $section,
                );
            }
        }
        return $best;
    }

    protected static function find_next_unused_outline_section_index($outline_sections, $exclude_indexes = array())
    {
        if (!is_array($outline_sections) || empty($outline_sections)) {
            return -1;
        }

        $exclude_lookup = array();
        foreach ((array) $exclude_indexes as $exclude_index) {
            $exclude_lookup[intval($exclude_index)] = true;
        }

        foreach (array_values($outline_sections) as $index => $section) {
            if (isset($exclude_lookup[$index])) {
                continue;
            }
            if (!is_array($section)) {
                continue;
            }
            return $index;
        }

        return -1;
    }

    public static function build_outline_section_media_html($section, $post_id = 0, $image_size = 'medium', $link_phrases = array(), $use_images = true, $use_links = true, $existing_image_map = array(), $section_index = -1, $excluded_image_urls = array())
    {
        $html_parts = array();
        $section_title = is_array($section) && !empty($section['h2']) ? self::clean_source_text($section['h2']) : '';
        if (!empty($use_images)) {
            $image_html = self::build_outline_section_image_html($section, $post_id, $image_size, $existing_image_map, $section_index, array(), $excluded_image_urls);
            if ($image_html !== '') {
                $html_parts[] = $image_html;
            }
        }

        if (!empty($use_links)) {
            $link_html = self::build_outline_section_link_html($section, $link_phrases);
            if ($link_html !== '') {
                $html_parts[] = $link_html;
            }
        }

        return trim(implode("\n", $html_parts));
    }

    /**
     * Distribute source images through keyword-list and spreadsheet content.
     * This deliberately uses content position, never heading-title matching.
     */
    public static function inject_content_images_by_word_interval($content, $outline_sections, $post_id = 0, $image_size = 'medium', $interval_words = 500, $excluded_image_urls = array())
    {
        $content = trim((string) $content);
        if ($content === '' || !is_array($outline_sections) || empty($outline_sections) || !function_exists('parse_blocks') || !function_exists('serialize_blocks')) {
            return $content;
        }

        $interval_words = max(100, min(5000, intval($interval_words)));
        $blocks = parse_blocks($content);
        if (empty($blocks) || !is_array($blocks)) {
            return $content;
        }

        $candidates = array();
        foreach ($outline_sections as $section) {
            if (!is_array($section) || empty($section['images']) || !is_array($section['images'])) {
                continue;
            }
            foreach ($section['images'] as $image) {
                if (!is_array($image) || empty($image['url'])) {
                    continue;
                }
                $candidate = $section;
                $candidate['images'] = array($image);
                $candidates[] = $candidate;
            }
        }
        if (empty($candidates)) {
            return $content;
        }

        $excluded_image_urls = is_array($excluded_image_urls) ? $excluded_image_urls : array();
        $existing_image_count = 0;
        foreach ($blocks as $block) {
            $block_html = is_array($block) && isset($block['innerHTML']) ? (string) $block['innerHTML'] : '';
            if ($block_html !== '') {
                $existing_image_count += preg_match_all('/<img\b/i', $block_html);
                if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $block_html, $matches)) {
                    foreach ($matches[1] as $existing_url) {
                        $excluded_image_urls[] = html_entity_decode((string) $existing_url, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
                    }
                }
            }
        }

        $word_count = 0;
        foreach ($blocks as $block) {
            $block_html = is_array($block) && isset($block['innerHTML']) ? (string) $block['innerHTML'] : '';
            $plain_text = trim(wp_strip_all_tags($block_html));
            if ($plain_text !== '') {
                $word_count += preg_match_all('/\S+/u', $plain_text);
            }
        }
        $target_image_count = max(1, (int) floor($word_count / $interval_words));
        $missing_image_count = max(0, $target_image_count - $existing_image_count);
        if ($missing_image_count <= 0) {
            return $content;
        }

        $result_blocks = array();
        $running_words = 0;
        $next_threshold = $interval_words;
        $candidate_index = 0;
        $inserted_count = 0;
        $last_eligible_result_index = -1;
        $eligible_blocks = array('core/paragraph', 'core/list', 'core/quote', 'core/html', 'core/preformatted', 'core/pullquote', 'core/verse', 'core/table');

        foreach ($blocks as $block) {
            $result_blocks[] = $block;
            $block_html = is_array($block) && isset($block['innerHTML']) ? (string) $block['innerHTML'] : '';
            $plain_text = trim(wp_strip_all_tags($block_html));
            if ($plain_text !== '') {
                $running_words += preg_match_all('/\S+/u', $plain_text);
            }

            $block_name = is_array($block) && !empty($block['blockName']) ? (string) $block['blockName'] : '';
            if (!in_array($block_name, $eligible_blocks, true)) {
                continue;
            }
            $last_eligible_result_index = count($result_blocks) - 1;
            if ($inserted_count >= $missing_image_count || $running_words < $next_threshold) {
                continue;
            }

            while ($candidate_index < count($candidates) && $inserted_count < $missing_image_count) {
                $candidate = $candidates[$candidate_index];
                $candidate_index++;
                $candidate_url = !empty($candidate['images'][0]['url']) ? trim((string) $candidate['images'][0]['url']) : '';
                $candidate_key = self::normalize_image_url_for_comparison($candidate_url);
                if ($candidate_key === '') {
                    continue;
                }

                $image_html = self::build_outline_section_image_html(
                    $candidate,
                    $post_id,
                    $image_size,
                    array(),
                    -1,
                    array(),
                    $excluded_image_urls
                );
                if ($image_html === '') {
                    continue;
                }

                $result_blocks[] = array(
                    'blockName' => 'core/html',
                    'attrs' => array(),
                    'innerBlocks' => array(),
                    'innerContent' => array($image_html),
                );
                $excluded_image_urls[] = $candidate_url;
                $inserted_count++;
                $next_threshold += $interval_words;
                break;
            }
        }

        // Short articles may need one image before reaching the first interval.
        // Place the pending image after the last text block instead of returning
        // the article without media.
        while ($inserted_count < $missing_image_count && $last_eligible_result_index >= 0 && $candidate_index < count($candidates)) {
            $candidate = $candidates[$candidate_index];
            $candidate_index++;
            $candidate_url = !empty($candidate['images'][0]['url']) ? trim((string) $candidate['images'][0]['url']) : '';
            if (self::normalize_image_url_for_comparison($candidate_url) === '') {
                continue;
            }

            $image_html = self::build_outline_section_image_html(
                $candidate,
                $post_id,
                $image_size,
                array(),
                -1,
                array(),
                $excluded_image_urls
            );
            if ($image_html === '') {
                continue;
            }

            array_splice($result_blocks, $last_eligible_result_index + 1, 0, array(array(
                'blockName' => 'core/html',
                'attrs' => array(),
                'innerBlocks' => array(),
                'innerContent' => array($image_html),
            )));
            $last_eligible_result_index++;
            $excluded_image_urls[] = $candidate_url;
            $inserted_count++;
        }

        return $inserted_count > 0 ? serialize_blocks($result_blocks) : $content;
    }

    public static function inject_outline_section_media_into_content($content, $outline_sections, $post_id = 0, $image_size = 'medium', $link_phrases = array(), $use_images = true, $use_links = true, $generator = array(), $context = array(), $existing_image_map = array(), $fallback_image_candidates = array(), $excluded_image_urls = array())
    {
        $content = trim((string) $content);
        if ($content === '') {
            return $content;
        }

        $use_images = !empty($use_images);
        $use_links = !empty($use_links);
        if (!$use_images && !$use_links) {
            return $content;
        }

        if (!is_array($outline_sections) || empty($outline_sections) || !function_exists('parse_blocks') || !function_exists('serialize_blocks')) {
            return $content;
        }

        $outline_sections = array_values(array_filter($outline_sections, function ($section) {
            return is_array($section);
        }));
        if (empty($outline_sections)) {
            return $content;
        }

        $generated_title = is_array($context) && !empty($context['generated_title'])
            ? (string) $context['generated_title']
            : '';
        $maximum_image_count = self::extract_content_image_limit_from_title($generated_title);

        $excluded_image_urls = is_array($excluded_image_urls) ? $excluded_image_urls : array();
        $excluded_lookup = array();
        foreach ($excluded_image_urls as $excluded_url) {
            $excluded_url = trim((string) $excluded_url);
            if ($excluded_url !== '') {
                $excluded_lookup[$excluded_url] = true;
            }
        }

        $blocks = parse_blocks($content);
        if (empty($blocks) || !is_array($blocks)) {
            return $content;
        }

        $result_blocks = array();
        $section_index = -1;
        $pending_link_html = '';
        $inserted_any = false;

        foreach ($blocks as $block_index => $block) {
            $block_name = is_array($block) && !empty($block['blockName']) ? (string) $block['blockName'] : '';
            $is_heading_level_2 = false;
            if ($block_name === 'core/heading') {
                $level = 2;
                if (is_array($block) && isset($block['attrs']['level'])) {
                    $level = intval($block['attrs']['level']);
                }
                $is_heading_level_2 = ($level === 2);
            }

            if ($is_heading_level_2) {
                if ($pending_link_html !== '') {
                    $result_blocks[] = array(
                        'blockName' => 'core/html',
                        'attrs' => array(),
                        'innerBlocks' => array(),
                        'innerContent' => array($pending_link_html),
                    );
                    $inserted_any = true;
                    $pending_link_html = '';
                }

                $result_blocks[] = $block;
                $section_index++;
                $matched_index = $section_index;
                $matched_section = (isset($outline_sections[$matched_index]) && is_array($outline_sections[$matched_index]))
                    ? $outline_sections[$matched_index]
                    : null;

                if ($matched_section !== null) {
                    $section_has_markup_image = self::outline_section_contains_image_markup($blocks, $block_index);
                    $section_accepts_image = $maximum_image_count <= 0 || $matched_index < $maximum_image_count;
                    if ($use_images && $section_accepts_image && $is_heading_level_2 && !$section_has_markup_image && !self::outline_section_has_existing_image($matched_section, $existing_image_map, $matched_index)) {
                        $section_image_html = self::build_outline_section_image_html($matched_section, $post_id, $image_size, $existing_image_map, $matched_index, $fallback_image_candidates, array_keys($excluded_lookup));
                        if ($section_image_html !== '') {
                            $result_blocks[] = array(
                                'blockName' => 'core/html',
                                'attrs' => array(),
                                'innerBlocks' => array(),
                                'innerContent' => array($section_image_html),
                            );
                            $inserted_any = true;
                        }
                    }
                    $pending_link_html = $use_links ? self::build_outline_section_link_html($matched_section, $link_phrases) : '';
                }
                continue;
            }

            $result_blocks[] = $block;
        }

        if ($pending_link_html !== '') {
            $result_blocks[] = array(
                'blockName' => 'core/html',
                'attrs' => array(),
                'innerBlocks' => array(),
                'innerContent' => array($pending_link_html),
            );
            $inserted_any = true;
        }

        return $inserted_any ? serialize_blocks($result_blocks) : $content;
    }

    public static function outline_section_contains_image_markup($blocks, $start_index)
    {
        $blocks = is_array($blocks) ? array_values($blocks) : array();
        $start_index = max(0, intval($start_index));

        if (empty($blocks) || !isset($blocks[$start_index])) {
            return false;
        }

        for ($index = $start_index + 1; $index < count($blocks); $index++) {
            $block = $blocks[$index];
            if (!is_array($block)) {
                continue;
            }

            $block_name = !empty($block['blockName']) ? (string) $block['blockName'] : '';
            if ($block_name === 'core/heading') {
                $level = 2;
                if (isset($block['attrs']['level'])) {
                    $level = intval($block['attrs']['level']);
                }
                if ($level === 2 || $level === 3) {
                    break;
                }
            }

            if (self::block_contains_image_markup($block)) {
                return true;
            }
        }

        return false;
    }

    public static function block_contains_image_markup($block)
    {
        if (!is_array($block)) {
            return false;
        }

        $block_name = !empty($block['blockName']) ? (string) $block['blockName'] : '';
        if (in_array($block_name, array('core/image', 'core/gallery', 'core/media-text', 'core/cover'), true)) {
            return true;
        }

        $html = '';
        if (!empty($block['innerHTML'])) {
            $html = (string) $block['innerHTML'];
        } elseif (!empty($block['innerContent']) && is_array($block['innerContent'])) {
            $html = implode('', array_map('strval', $block['innerContent']));
        }

        if ($html === '') {
            return false;
        }

        return (bool) preg_match('~<img\b|wp-block-image|wp-image-\d+|<figure\b[^>]*class=["\'][^"\']*\bwp-block-image\b~i', $html);
    }

    public static function extract_outline_section_image_map_from_content($content)
    {
        $content = trim((string) $content);
        if ($content === '' || !function_exists('parse_blocks')) {
            return array();
        }

        $blocks = parse_blocks($content);
        if (empty($blocks) || !is_array($blocks)) {
            return array();
        }

        $map = array();
        $current_section_index = -1;

        foreach ($blocks as $block) {
            $block_name = is_array($block) && !empty($block['blockName']) ? (string) $block['blockName'] : '';
            if ($block_name === 'core/heading') {
                $level = 2;
                if (is_array($block) && isset($block['attrs']['level'])) {
                    $level = intval($block['attrs']['level']);
                }
                if ($level === 2 || $level === 3) {
                    $current_section_index++;
                } else {
                    $current_section_index = -1;
                }
                continue;
            }

            if ($current_section_index < 0) {
                continue;
            }

            $html = '';
            if (is_array($block) && !empty($block['innerHTML'])) {
                $html = (string) $block['innerHTML'];
            } elseif (is_array($block) && !empty($block['innerContent']) && is_array($block['innerContent'])) {
                $html = implode('', array_map('strval', $block['innerContent']));
            }

            if ($html === '') {
                continue;
            }

            if (!preg_match('/wp-image-(\d+)/', $html, $matches)) {
                continue;
            }

            $attachment_id = intval($matches[1]);
            if ($attachment_id > 0 && !isset($map[$current_section_index])) {
                $map[$current_section_index] = $attachment_id;
            }
        }

        return $map;
    }

    public static function pick_best_srcset_url($srcset)
    {
        $srcset = trim((string) $srcset);
        if ($srcset === '') {
            return '';
        }

        $candidates = array();
        $entries = array_map('trim', explode(',', $srcset));
        foreach ($entries as $entry) {
            if ($entry === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $entry);
            if (empty($parts[0])) {
                continue;
            }

            $candidate_url = trim((string) $parts[0]);
            $weight = count($candidates);
            if (!empty($parts[1]) && preg_match('/(\d+)(w|x)/i', $parts[1], $matches)) {
                $weight = intval($matches[1]);
                if (strtolower($matches[2]) === 'x') {
                    $weight *= 1000;
                }
            }

            $candidates[] = array(
                'url' => $candidate_url,
                'weight' => $weight,
            );
        }

        if (empty($candidates)) {
            return '';
        }

        usort($candidates, function ($a, $b) {
            $a_weight = isset($a['weight']) ? intval($a['weight']) : 0;
            $b_weight = isset($b['weight']) ? intval($b['weight']) : 0;
            if ($a_weight === $b_weight) {
                return 0;
            }
            return ($a_weight < $b_weight) ? -1 : 1;
        });

        $best = end($candidates);
        return !empty($best['url']) ? (string) $best['url'] : '';
    }

    public static function extract_primary_external_link_from_html($html, $base_url = '')
    {
        $result = array(
            'link_url' => '',
            'link_text' => '',
            'link_source' => '',
        );

        $html = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        if ($html === '') {
            return $result;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        if (!$loaded) {
            return $result;
        }

        $xpath = new DOMXPath($dom);
        $selectors = array(
            '//article//a[@href]',
            '//*[@role="main"]//a[@href]',
            '//main//a[@href]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]//a[@href]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]//a[@href]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " content ")]//a[@href]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " article-body ")]//a[@href]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " story-body ")]//a[@href]',
            '//a[@href]',
        );

        $base_host = '';
        if ($base_url !== '') {
            $base_host = strtolower((string) wp_parse_url($base_url, PHP_URL_HOST));
        }

        $best = array(
            'score' => -1000,
            'link_url' => '',
            'link_text' => '',
            'link_source' => '',
        );
        $seen = array();

        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if (!$nodes || $nodes->length === 0) {
                continue;
            }

            foreach ($nodes as $node) {
                if (!($node instanceof DOMElement)) {
                    continue;
                }

                if (!$node->hasAttribute('href')) {
                    continue;
                }

                $href = html_entity_decode(trim((string) $node->getAttribute('href')), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
                if ($href === '' || preg_match('~^(javascript:|mailto:|tel:|#)~i', $href)) {
                    continue;
                }

                $resolved = self::resolve_url_against_base($href, $base_url);
                if ($resolved === '' || !preg_match('~^https?://~i', $resolved)) {
                    continue;
                }

                if (isset($seen[$resolved])) {
                    continue;
                }
                $seen[$resolved] = true;

                $resolved_host = strtolower((string) wp_parse_url($resolved, PHP_URL_HOST));
                if ($base_host !== '' && $resolved_host !== '' && $resolved_host === $base_host) {
                    continue;
                }

                $text = self::clean_source_text($node->textContent);
                $class = strtolower(trim((string) $node->getAttribute('class')));
                $rel = strtolower(trim((string) $node->getAttribute('rel')));
                $target = strtolower(trim((string) $node->getAttribute('target')));
                $blob = $class . ' ' . $rel . ' ' . $target . ' ' . $resolved . ' ' . $text;

                $score = 0;
                if ($resolved_host !== '' && $base_host !== '' && $resolved_host !== $base_host) {
                    $score += 20;
                }
                if ($target === '_blank') {
                    $score += 10;
                }
                if ($rel !== '' && (strpos($rel, 'noopener') !== false || strpos($rel, 'noreferrer') !== false)) {
                    $score += 5;
                }
                if (preg_match('/affiliate|affiliate-single|cta|button|watch|stream|where-to-watch|where to watch|play|rent|buy|external|single-link|link/i', $blob)) {
                    $score += 35;
                }
                if (preg_match('/watch|assistir|ver|stream|play|rent|buy|onde assistir|onde ver|read more|view more|go to/i', $text)) {
                    $score += 30;
                }
                if (preg_match('/netflix|amazon|prime|hulu|disney|max|apple|paramount|peacock|youtube|vimeo/i', $resolved)) {
                    $score += 15;
                }
                if ($text === '') {
                    $score -= 5;
                }

                if ($score > $best['score']) {
                    $best = array(
                        'score' => $score,
                        'link_url' => $resolved,
                        'link_text' => $text,
                        'link_source' => 'content_anchor',
                    );
                }
            }
        }

        if (!empty($best['link_url'])) {
            $result['link_url'] = $best['link_url'];
            $result['link_text'] = $best['link_text'];
            $result['link_source'] = $best['link_source'];
        }

        return $result;
    }

    public static function normalize_generated_title($title, $source_title = '')
    {
        $title = sanitize_text_field(trim((string) $title));
        if ($title === '') {
            return '';
        }

        $title = preg_replace('/\s+/u', ' ', $title);
        // Remove a numeracao de lista que a IA pode colocar antes de um titulo quantificado.
        $title = preg_replace('/^\s*\d{1,2}\s*[\.\)]\s+(?=\d{1,3}\s)/u', '', $title);

        return trim($title);
    }

    public static function extract_content_image_limit_from_title($title)
    {
        $title = self::normalize_prompt_context_text($title);
        if ($title === '') {
            return 0;
        }

        $normalized = mb_strtolower(remove_accents($title), 'UTF-8');
        if (!preg_match_all('/\b(\d{1,4})\b/u', $normalized, $matches, PREG_OFFSET_CAPTURE)) {
            return 0;
        }

        $months = '(?:jan(?:eiro|uary)?|fev(?:ereiro)?|feb(?:ruary)?|mar(?:co|ch)?|abr(?:il)?|apr(?:il)?|mai(?:o)?|may|jun(?:ho|e)?|jul(?:ho|y)?|ago(?:sto)?|aug(?:ust)?|set(?:embro)?|sep(?:tember)?|out(?:ubro)?|oct(?:ober)?|nov(?:embro|ember)?|dez(?:embro)?|dec(?:ember)?)';

        foreach ($matches[1] as $match) {
            $raw_number = (string) $match[0];
            $number = intval($raw_number);
            $offset = isset($match[1]) ? intval($match[1]) : 0;
            if ($number <= 0 || $number > 100) {
                continue;
            }

            $after_number = substr($normalized, $offset + strlen($raw_number), 24);
            if (preg_match('/^\s*(?:a|o|ª|º)\b/u', $after_number)) {
                continue;
            }

            $window_start = max(0, $offset - 32);
            $window = substr($normalized, $window_start, 72);
            if (
                preg_match('/\b\d{1,2}[\/.-]\d{1,2}(?:[\/.-]\d{2,4})?\b/u', $window)
                || preg_match('/\b\d{1,2}\s+de\s+' . $months . '\b/u', $window)
                || preg_match('/\b' . $months . '\s+\d{1,2}\b/u', $window)
            ) {
                continue;
            }

            if (
                preg_match('/\b(?:temporada|season|volume|vol|parte|part)\s*' . preg_quote($raw_number, '/') . '\b/u', $window)
                || preg_match('/\b' . preg_quote($raw_number, '/') . '\s*(?:a|o|ª|º)?\s*(?:temporada|season)\b/u', $window)
            ) {
                continue;
            }

            return $number;
        }

        return 0;
    }

    public static function extract_outline_target_h2_count_from_title($title, $reference_title = '')
    {
        $candidates = array(
            self::normalize_prompt_context_text($title),
            self::normalize_prompt_context_text($reference_title),
        );

        $count_keywords = '(?:top|best|melhor(?:es)?|maior(?:es)?|pior(?:es)?|filme(?:s)?|movie(?:s)?|serie(?:s)?|s?erie(?:s)?|show(?:s)?|drama(?:s)?|thriller(?:s)?|romance(?:s)?|comedia(?:s)?|aventura(?:s)?|episodio(?:s)?|livro(?:s)?|book(?:s)?|coisa(?:s)?|motivo(?:s)?|dica(?:s)?|opcao(?:oes)?|item(?:s)?|personagem(?:ns)?|produto(?:s)?|lugar(?:es)?|maneira(?:s)?|forma(?:s)?|documentario(?:s)?|anime(?:s)?|terror|horror|ranking|lista|things|ways|reasons|facts|tips|tricks|ideas|examples|trailer(?:s)?)';

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $normalized = mb_strtolower(remove_accents($candidate), 'UTF-8');
            if ($normalized === '') {
                continue;
            }

            if (!preg_match_all('/\b(\d{1,3})\b/u', $normalized, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[1] as $match) {
                $count = intval($match[0]);
                if ($count <= 0 || $count > 100) {
                    continue;
                }

                $offset = isset($match[1]) ? intval($match[1]) : 0;
                $window_start = max(0, $offset - 48);
                $window = substr($normalized, $window_start, 96);
                if ($window === '') {
                    continue;
                }

                if (preg_match('/' . $count_keywords . '/u', $window)) {
                    return $count;
                }
            }
        }

        return 0;
    }

    public static function normalize_prompt_context_text($value)
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        $value = wp_strip_all_tags($value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }

    protected static function is_auxiliary_outline_heading_text($text)
    {
        $text = self::normalize_prompt_context_text($text);
        if ($text === '') {
            return true;
        }

        $text = strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')));
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        $patterns = array(
            '/^\s*(leia\s+tamb[eé]m|leia\s+mais|voc[eê]\s+tamb[eé]m\s+pode\s+gostar|talvez\s+voc[eê]\s+goste|tamb[eé]m\s+sobre|veja\s+tamb[eé]m|confira\s+tamb[eé]m|mais\s+sobre|relacionados?|related\s+posts?|related\s+articles?|you\s+may\s+also\s+like|read\s+also|read\s+more|also\s+read|continue\s+reading|more\s+from)\b/i',
            '/\b(leia\s+tamb[eé]m|leia\s+mais|voc[eê]\s+tamb[eé]m\s+pode\s+gostar|talvez\s+voc[eê]\s+goste|veja\s+tamb[eé]m|confira\s+tamb[eé]m|related\s+posts?|related\s+articles?|read\s+also|read\s+more|also\s+read|continue\s+reading|more\s+from)\b/i',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    protected static function is_auxiliary_outline_heading_text_v2($text)
    {
        return self::is_auxiliary_outline_heading_text_v3($text);

        $text = self::normalize_prompt_context_text($text);
        if ($text === '') {
            return true;
        }

        $normalized = strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')));
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);

        if (stripos($normalized, 'leia mais') !== false) {
            return true;
        }

        $patterns = array(
            '/^(?:\d+\s*[\.\)\-:]\s*)?(?:leia\s+tamb[eé]m|leia\s+mais|voc[eê]\s+tamb[eé]m\s+pode\s+gostar|talvez\s+voc[eê]\s+goste|tamb[eé]m\s+sobre|veja\s+tamb[eé]m|confira\s+tamb[eé]m|mais\s+sobre|relacionados?|related\s+posts?|related\s+articles?|you\s+may\s+also\s+like|read\s+also|read\s+more|also\s+read|continue\s+reading|more\s+from)\b/i',
            '/\b(?:leia\s+tamb[eé]m|leia\s+mais|voc[eê]\s+tamb[eé]m\s+pode\s+gostar|talvez\s+voc[eê]\s+goste|tamb[eé]m\s+sobre|veja\s+tamb[eé]m|confira\s+tamb[eé]m|mais\s+sobre|relacionados?|related\s+posts?|related\s+articles?|you\s+may\s+also\s+like|read\s+also|read\s+more|also\s+read|continue\s+reading|more\s+from)\b/i',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect related-content headings before they reach any AI prompt.
     * The comparison is accent-insensitive so UTF-8 and mojibake variants match.
     */
    protected static function is_auxiliary_outline_heading_text_v3($text)
    {
        $text = self::normalize_prompt_context_text($text);
        if ($text === '') {
            return true;
        }

        $normalized = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        if (function_exists('remove_accents')) {
            $normalized = remove_accents($normalized);
        } elseif (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if ($converted !== false) {
                $normalized = $converted;
            }
        }

        // Tolerate headings damaged by an earlier charset conversion as well.
        $normalized = strtr($normalized, array(
            'Ã¡' => 'a',
            'Ã¢' => 'a',
            'Ã£' => 'a',
            'Ã¤' => 'a',
            'Ã©' => 'e',
            'Ãª' => 'e',
            'Ã«' => 'e',
            'Ã­' => 'i',
            'Ã®' => 'i',
            'Ã¯' => 'i',
            'Ã³' => 'o',
            'Ã´' => 'o',
            'Ãµ' => 'o',
            'Ã¶' => 'o',
            'Ãº' => 'u',
            'Ã»' => 'u',
            'Ã¼' => 'u',
            'Ã§' => 'c',
        ));
        $normalized = strtolower($normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized));

        $patterns = array(
            '/\bleia\s+(?:mais|tambem)\b/',
            '/\btambem\s+sobre\b/',
            '/\b(?:voce|voces)\s+tambem\s+pode(?:m)?\s+gostar\b/',
            '/\btalvez\s+voce\s+goste\b/',
            '/\b(?:veja|confira)\s+tambem\b/',
            '/\b(?:mais|artigos?|posts?|conteudos?|links?)\s+relacionad(?:o|os|a|as)\b/',
            '/\b(?:recomendado|recomendados|sugerido|sugeridos)\s+para\s+voce\b/',
            '/\b(?:related|recommended|read)\s+(?:posts?|articles?|more)\b/',
            '/\b(?:you\s+may\s+also\s+like|continue\s+reading|more\s+from)\b/',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    public static function strip_generated_image_markup_from_html($html)
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('~<!--\s*wp:image\b.*?<!--\s*/wp:image\s*-->~is', '', $html);
        $html = preg_replace('~<figure\b[^>]*class=["\'][^"\']*\bwp-block-image\b[^"\']*["\'][^>]*>.*?</figure>~is', '', $html);
        $html = preg_replace('~<picture\b[^>]*>.*?</picture>~is', '', $html);
        $html = preg_replace('~<img\b[^>]*>~is', '', $html);
        $html = preg_replace('~<p[^>]*>\s*</p>~is', '', $html);
        $html = preg_replace('~\n{3,}~', "\n\n", $html);

        return trim($html);
    }

    public static function remove_unmatched_trailing_quotes_from_html($html)
    {
        $html = (string) $html;
        if ($html === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '~(<(?:p|li|blockquote|h[1-6])\b[^>]*>)(.*?)(</(?:p|li|blockquote|h[1-6])>)~isu',
            static function ($matches) {
                $inner_html = isset($matches[2]) ? (string) $matches[2] : '';
                $plain_text = trim(wp_strip_all_tags(html_entity_decode($inner_html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'))));
                if ($plain_text === '') {
                    return $matches[0];
                }

                $last_character = function_exists('mb_substr')
                    ? mb_substr($plain_text, -1, 1, 'UTF-8')
                    : substr($plain_text, -1);
                $opening_character = array(
                    '”' => '“',
                    '’' => '‘',
                );
                $is_unmatched = false;

                if ($last_character === '"') {
                    $is_unmatched = (substr_count($plain_text, '"') % 2) === 1;
                } elseif (isset($opening_character[$last_character])) {
                    $opening_position = function_exists('mb_strpos')
                        ? mb_strpos($plain_text, $opening_character[$last_character], 0, 'UTF-8')
                        : strpos($plain_text, $opening_character[$last_character]);
                    $is_unmatched = $opening_position === false;
                }

                if (!$is_unmatched) {
                    return $matches[0];
                }

                $inner_html = (string) preg_replace('/(["”’])(\s*)$/u', '$2', $inner_html, 1);
                return $matches[1] . $inner_html . $matches[3];
            },
            $html
        );
    }

    public static function build_source_context_block($generator, $item, $options = array())
    {
        $options = is_array($options) ? $options : array();
        $include_html = !array_key_exists('include_html', $options) || !empty($options['include_html']);
        $include_outline = !array_key_exists('include_outline', $options) || !empty($options['include_outline']);

        $lines = array('## DADOS DA FONTE');

        $source_title = '';
        foreach (array('source_title', 'title', 'keyword', 'item_title', 'feed_title', 'source_page_title') as $candidate_key) {
            if (!empty($item[$candidate_key])) {
                $source_title = self::normalize_plain_text((string) $item[$candidate_key]);
                break;
            }
        }
        $source_url = isset($item['source_url']) ? trim((string) $item['source_url']) : '';
        if ($source_url === '') {
            $source_url = isset($item['permalink']) ? trim((string) $item['permalink']) : '';
        }
        $source_site_name = '';
        if ($source_url !== '') {
            $parts = wp_parse_url($source_url);
            if (!empty($parts['host'])) {
                $source_site_name = preg_replace('/^www\./i', '', (string) $parts['host']);
            }
        }
        $source_excerpt = '';
        if (!empty($item['source_page_excerpt'])) {
            $source_excerpt = self::normalize_plain_text((string) $item['source_page_excerpt']);
        } elseif (!empty($item['excerpt'])) {
            $source_excerpt = self::normalize_plain_text((string) $item['excerpt']);
        }
        $source_page_content_html = $include_html && isset($item['source_page_content_html'])
            ? self::limit_prompt_html_chars(self::normalize_prompt_context_html((string) $item['source_page_content_html']), 6000)
            : '';
        $source_type = !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss';
        $generator_editorial_context = self::get_generator_editorial_context($generator);
        $tavily_context_text = '';
        if ($source_type === 'keyword_list' && !empty($item['tavily_context']) && is_array($item['tavily_context'])) {
            $tavily_context_text = self::format_tavily_context_for_prompt($item['tavily_context']);
        }
        $row_data = isset($item['row_data']) && is_array($item['row_data'])
            ? wp_json_encode($item['row_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';
        $generation_language = !empty($generator['generation_language'])
            ? Content_Rank_Generator::normalize_generation_language_value($generator['generation_language'])
            : Content_Rank_Generator::get_default_generation_language();

        if ($source_title !== '') {
            $lines[] = 'Titulo da fonte: ' . $source_title;
        }
        if ($source_type === 'keyword_list') {
            $lines[] = 'Nome do gerador: ' . (!empty($generator_editorial_context['name']) ? $generator_editorial_context['name'] : '[sem nome definido]');
            $lines[] = 'Categoria editorial: ' . (!empty($generator_editorial_context['category_text']) ? $generator_editorial_context['category_text'] : '[sem categoria definida]');
        }
        if (!empty($item['existing_keyword_post_titles']) && is_array($item['existing_keyword_post_titles'])) {
            $lines[] = 'Posts ja gerados para esta mesma keyword:';
            foreach ($item['existing_keyword_post_titles'] as $existing_title) {
                $existing_title = trim(wp_strip_all_tags((string) $existing_title));
                if ($existing_title !== '') {
                    $lines[] = '- ' . $existing_title;
                }
            }
            $lines[] = 'Nao repita a mesma intencao de busca, promessa ou angulo desses posts. Escolha uma abordagem editorial diferente.';
        }
        if ($source_site_name !== '') {
            $lines[] = 'Site de referencia: ' . $source_site_name;
        }

        if ($source_excerpt !== '') {
            $lines[] = 'Resumo da fonte: ' . self::limit_plain_text_words($source_excerpt, 100);
        }
        if ($source_page_content_html !== '') {
            $lines[] = 'Conteudo em HTML limpo da pagina de origem: ' . $source_page_content_html;
        }
        if ($tavily_context_text !== '') {
            $lines[] = 'Pesquisa factual auxiliar do Tavily: ' . $tavily_context_text;
        }
        if (is_string($row_data) && $row_data !== '') {
            $lines[] = 'Dados completos da linha de origem: ' . $row_data;
        }
        if ($generation_language !== '') {
            $lines[] = 'Idioma final: ' . $generation_language;
        }

        return implode("\n", $lines);
    }

    public static function build_source_outline_titles_for_prompt(&$item, $max_items = 0, $generator = array())
    {
        static $translation_cache = array();
        $item = is_array($item) ? $item : array();
        if (!empty($item['tmdb_movies']) && is_array($item['tmdb_movies'])) {
            $localized_titles = array();
            foreach ($item['tmdb_movies'] as $movie) {
                if (!is_array($movie) || empty($movie['title'])) {
                    continue;
                }
                $localized_titles[] = sprintf(
                    '%02d. %s%s',
                    count($localized_titles) + 1,
                    self::normalize_prompt_context_text((string) $movie['title']),
                    !empty($movie['year']) ? ' (' . sanitize_text_field((string) $movie['year']) . ')' : ''
                );
            }
            if (!empty($localized_titles)) {
                error_log('[Content Rank][tmdb-outline] reutilizando ' . count($localized_titles) . ' titulos localizados');
                return implode("\n", $localized_titles);
            }
        }

        $raw_titles = self::build_raw_source_outline_titles_for_prompt($item, $max_items);
        if ($raw_titles !== '' && class_exists('Content_Rank_TMDB')) {
            $language = !empty($generator['generation_language']) ? (string) $generator['generation_language'] : 'pt-BR';
            $cache_key = md5((string) ($item['guid'] ?? '') . '|' . $language . '|' . $raw_titles);
            if (isset($translation_cache[$cache_key])) {
                if (!empty($translation_cache[$cache_key]['movies'])) {
                    $item['tmdb_movies'] = $translation_cache[$cache_key]['movies'];
                }
                error_log('[Content Rank][tmdb-outline] usando cache da traducao');
                return (string) $translation_cache[$cache_key]['titles'];
            }
            error_log('[Content Rank][tmdb-outline] traduzindo titulos extraidos dos H2');
            $translated_titles = Content_Rank_TMDB::translate_source_outline_titles($generator, $item, $raw_titles);
            $translation_cache[$cache_key] = array(
                'titles' => $translated_titles,
                'movies' => !empty($item['tmdb_movies']) && is_array($item['tmdb_movies']) ? $item['tmdb_movies'] : array(),
            );
            return $translated_titles;
        }
        return $raw_titles;
    }

    public static function build_raw_source_outline_titles_for_prompt($item, $max_items = 0)
    {
        $item = is_array($item) ? $item : array();
        // Keep the complete source outline. The title prompt decides how many
        // items the final article should use.
        $max_items = 0;

        $titles = array();

        $source_html = '';
        // The filtered content may be intentionally shortened. Use the full
        // source HTML first so every H2 item remains available to TMDB.
        foreach (array('source_page_html', 'source_page_content_html', 'content_html') as $candidate_key) {
            if (!empty($item[$candidate_key])) {
                $source_html = (string) $item[$candidate_key];
                break;
            }
        }

        if ($source_html !== '') {
            $outline_from_html = self::extract_page_outline_from_html($source_html, '', 0, 0, 0, '', '', '');
            if (is_array($outline_from_html) && !empty($outline_from_html)) {
                foreach ($outline_from_html as $section) {
                    if (!is_array($section)) {
                        continue;
                    }

                    $title = '';
                    if (!empty($section['h2'])) {
                        $title = self::normalize_prompt_context_text($section['h2']);
                    } elseif (!empty($section['title'])) {
                        $title = self::normalize_prompt_context_text($section['title']);
                    }

                    if ($title !== '') {
                        if (self::is_auxiliary_outline_heading_text_v2($title)) {
                            continue;
                        }
                        $titles[] = $title;
                    }
                }
            }
        }

        if ($source_html === '' && empty($titles) && !empty($item['source_page_outline_sections']) && is_array($item['source_page_outline_sections'])) {
            foreach ($item['source_page_outline_sections'] as $section) {
                if (!is_array($section)) {
                    continue;
                }

                $title = '';
                if (!empty($section['h2'])) {
                    $title = self::normalize_prompt_context_text($section['h2']);
                } elseif (!empty($section['title'])) {
                    $title = self::normalize_prompt_context_text($section['title']);
                }

                if ($title !== '') {
                    if (self::is_auxiliary_outline_heading_text_v2($title)) {
                        continue;
                    }
                    $titles[] = $title;
                }

            }
        }

        if ($source_html === '' && empty($titles) && !empty($item['source_page_outline'])) {
            $outline_text = (string) $item['source_page_outline'];
            $lines = preg_split('/\R/u', $outline_text);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim(wp_strip_all_tags((string) $line));
                    if ($line === '') {
                        continue;
                    }

                    if (preg_match('/^H\d+\s+\d+\s*:\s*(.+)$/i', $line, $matches)) {
                        $title = trim((string) $matches[1]);
                        if ($title !== '') {
                            if (self::is_auxiliary_outline_heading_text_v2($title)) {
                                continue;
                            }
                            $titles[] = self::normalize_prompt_context_text($title);
                        }
                    } elseif (preg_match('/^\d+\s*[\.\)\-:]\s*(.+)$/u', $line, $matches)) {
                        $title = trim((string) $matches[1]);
                        if ($title !== '') {
                            if (self::is_auxiliary_outline_heading_text_v2($title)) {
                                continue;
                            }
                            $titles[] = self::normalize_prompt_context_text($title);
                        }
                    }

                }
            }
        }

        if (!empty($titles)) {
            $titles = array_values(array_filter($titles, function ($title) {
                $title = self::normalize_prompt_context_text($title);
                if ($title === '') {
                    return false;
                }
                $plain_title = preg_replace('/^\d+\s*[\.\)\-:]\s*/u', '', $title);
                return !self::is_auxiliary_outline_heading_text_v2($plain_title);
            }));
        }

        if (empty($titles)) {
            return '';
        }

        error_log('[Content Rank][tmdb-outline] titulos brutos extraidos: ' . count($titles));

        $formatted = array();
        foreach ($titles as $index => $title) {
            $formatted[] = sprintf('%02d. %s', $index + 1, $title);
        }

        return implode("\n", $formatted);
    }

    public static function build_outline_context_base($generator)
    {
        $generator = is_array($generator) ? $generator : array();
        $content_length_class = !empty($generator['content_length_class']) ? Content_Rank_Generator::normalize_content_length_class($generator['content_length_class']) : Content_Rank_Generator::get_default_content_length_class();
        $content_length_range = Content_Rank_Generator::get_content_length_range($content_length_class);
        $outline_model = Content_Rank_Generator::get_generator_outline_model($generator);
        $outline_model_hint_key = !empty($generator['outline_model_key']) ? sanitize_key((string) $generator['outline_model_key']) : '';
        $outline_model_text = Content_Rank_Generator::format_outline_model_for_prompt($outline_model, array(
            'content_length_class' => $content_length_class,
            'outline_target_h2_min' => !empty($outline_model['target_h2_min']) ? intval($outline_model['target_h2_min']) : 0,
            'outline_target_h2_max' => !empty($outline_model['target_h2_max']) ? intval($outline_model['target_h2_max']) : 0,
            'outline_target_h2_count' => !empty($outline_model['target_h2_count']) ? intval($outline_model['target_h2_count']) : 0,
        ));

        return array(
            'content_length_class' => $content_length_class,
            'content_length_range' => $content_length_range,
            'outline_model' => $outline_model,
            'outline_model_key' => !empty($outline_model['key']) ? (string) $outline_model['key'] : '',
            'outline_model_name' => !empty($outline_model['name']) ? (string) $outline_model['name'] : '',
            'outline_model_text' => $outline_model_text,
            'outline_model_hint_key' => $outline_model_hint_key,
            'outline_target_h2_min' => !empty($outline_model['target_h2_min']) ? intval($outline_model['target_h2_min']) : 0,
            'outline_target_h2_max' => !empty($outline_model['target_h2_max']) ? intval($outline_model['target_h2_max']) : 0,
            'outline_target_h2_count' => !empty($outline_model['target_h2_count']) ? intval($outline_model['target_h2_count']) : 0,
        );
    }
    public static function infer_outline_model_key_from_source_context($generator, $item, $seo_article = array(), $outline_context = array())
    {
        $generator = is_array($generator) ? $generator : array();
        $item = is_array($item) ? $item : array();
        $seo_article = is_array($seo_article) ? $seo_article : array();
        $outline_context = is_array($outline_context) ? $outline_context : array();

        $source_bits = array();
        foreach (array('source_page_content_html', 'source_page_html', 'source_page_content', 'content_html', 'content', 'source_page_excerpt', 'excerpt', 'source_page_outline', 'source_page_outline_sections') as $key) {
            if (!empty($item[$key])) {
                $source_bits[] = is_string($item[$key]) ? (string) $item[$key] : wp_json_encode($item[$key]);
            }
        }

        // Model inference must not trigger external TMDB requests. Localization
        // starts only when the actual outline prompt is being built.
        $source_page_outline_titles = self::build_raw_source_outline_titles_for_prompt($item, 0);
        if ($source_page_outline_titles !== '') {
            $source_bits[] = $source_page_outline_titles;
        }
        if (!empty($seo_article['excerpt'])) {
            $source_bits[] = (string) $seo_article['excerpt'];
        }

        $source_blob = strtolower(implode("\n\n", $source_bits));
        $source_text = self::normalize_prompt_context_text($source_blob);
        $combined = strtolower(trim($source_text));
        $content_length = strlen($source_text);

        $source_title = '';
        foreach (array('source_title', 'source_page_title', 'title', 'keyword', 'item_title', 'feed_title') as $candidate_key) {
            if (!empty($item[$candidate_key])) {
                $source_title = self::normalize_prompt_context_text($item[$candidate_key]);
                break;
            }
        }
        $normalized_source_title = strtolower(self::normalize_prompt_context_text($source_title));
        if (function_exists('remove_accents')) {
            $normalized_source_title = strtolower(remove_accents($normalized_source_title));
        }

        $has_guide_markers = (bool) preg_match('/\b(?:como|guia|tutorial|passo a passo|dicas|aprenda|entenda|saiba|por que|melhor(es)?|manual)\b/i', $combined);
        $has_news_markers = (bool) preg_match('/\b(?:revela|anuncia|confirma|chega|estreia|lan[a?]a|pol[e?]mica|esc[a?]ndalo|investiga[c?][a?]o|morte|pris[a?]o|denuncia|caso|trailer|nova?\s+temporada|this\s+week|coming\s+soon|what(?:[\'â€™]s)\s+coming|coming\s+to\s+netflix|new\s+on\s+netflix)\b/ui', $combined);

        // Headlines describing a current event are a strong news signal.
        $has_news_markers = (bool) preg_match('/\b(?:ganha|recebe|revela|revelou|anuncia|anunciou|confirma|confirmou|chega|estreia|lanca|lancamento|disponibiliza|disponivel|inicia|comeca|retorna|deixa|morte|morre|prisao|denuncia|acordo|assina|demissao|demitido|trailer|nova?\s+temporada|data\s+de\s+lancamento|this\s+week|coming\s+soon|what(?:[\'’]s)?\s+coming|coming\s+to\s+netflix|new\s+on\s+netflix)\b/ui', $normalized_source_title);

        $outline_title_count = 0;
        if ($source_page_outline_titles !== '') {
            $outline_title_lines = preg_split('/\R/u', $source_page_outline_titles);
            if (is_array($outline_title_lines)) {
                foreach ($outline_title_lines as $outline_title_line) {
                    if (trim((string) $outline_title_line) !== '') {
                        $outline_title_count++;
                    }
                }
            }
        }

        // build_source_outline_titles_for_prompt() adds its own 01., 02. labels,
        // so only count numbering that was actually present in source headings.
        $has_numbered_headings = (bool) preg_match('/<h[2-4]\b[^>]*>\s*\d{1,2}\s*[.)\-:]/i', $source_blob);
        $has_list_title_marker = (bool) preg_match('/(?:^|\s)(?:top\s+\d+|\d{1,2}\s+(?:filmes?|series?|animes?|livros?|jogos?|personagens?|itens?|cuidados?|dicas?|maneiras?|formas?|motivos?|opcoes?|classicos?|titulos?|produtos?|lugares?)|lista|ranking|melhores|best\s+\d+|selec(?:ao|cao)|recomendac(?:ao|ao))/ui', $normalized_source_title);
        $has_explicit_list_quantity = (bool) preg_match('/(?:^|\s)(?:top\s+)?\d{1,2}(?:\s+[\p{L}\d-]+){0,4}\s+(?:filmes?|series?|animes?|livros?|jogos?|personagens?|itens?|cuidados?|dicas?|maneiras?|formas?|motivos?|opcoes?|classicos?|titulos?|produtos?|lugares?|coisas?|ideias?|razoes?|reasons?|ways?|tips?|tricks?|examples?)(?:\b|\s)/ui', $normalized_source_title);
        $source_structure_blob = '';
        foreach (array('source_page_content_html', 'source_page_html', 'source_page_content', 'content_html', 'content') as $structure_key) {
            if (!empty($item[$structure_key])) {
                $source_structure_blob = is_string($item[$structure_key]) ? (string) $item[$structure_key] : wp_json_encode($item[$structure_key]);
                break;
            }
        }
        if ($source_structure_blob === '') {
            $source_structure_blob = $source_blob;
        }
        $has_parallel_list_markup = (bool) preg_match('/<li\b|<\/li>/i', $source_structure_blob)
            || (bool) preg_match('/^\s*(?:[-*]|\d+[.)])\s+/m', $source_structure_blob);
        $has_list_structure = ($outline_title_count >= 3 && ($has_list_title_marker || $has_numbered_headings || $has_parallel_list_markup))
            || ($has_parallel_list_markup && $outline_title_count >= 2)
            || ($has_list_title_marker && $outline_title_count >= 3);

        // An explicit quantity in the title defines a list. The source HTML
        // may be poorly structured or contain only an introductory paragraph.
        if ($has_explicit_list_quantity || $has_list_structure) {
            return 'list_article';
        }

        // Related-content lists inside an article are not enough to override a news headline.
        if ($has_news_markers && !$has_list_title_marker && !$has_numbered_headings) {
            return 'news_short';
        }

        if ($has_guide_markers) {
            return 'guide_long';
        }

        if ($has_news_markers) {
            return 'news_short';
        }

        return $content_length <= 2600 ? 'news_short' : 'guide_long';
    }

    public static function format_outline_analysis_for_prompt($outline_context)
    {
        $outline_context = is_array($outline_context) ? $outline_context : array();
        $lines = array();
        $content_type = !empty($outline_context['content_type']) ? Content_Rank_Generator::normalize_prompt_model_key((string) $outline_context['content_type']) : '';
        $funnel_level = !empty($outline_context['funnel_level']) ? sanitize_text_field((string) $outline_context['funnel_level']) : '';
        $primary_pain = !empty($outline_context['primary_pain']) ? sanitize_textarea_field((string) $outline_context['primary_pain']) : '';
        $focus_keyword = !empty($outline_context['focus_keyword']) ? sanitize_text_field((string) $outline_context['focus_keyword']) : '';
        $editorial_conflict = !empty($outline_context['editorial_conflict']) ? sanitize_textarea_field((string) $outline_context['editorial_conflict']) : '';
        $reader_transformation = !empty($outline_context['reader_transformation']) ? sanitize_textarea_field((string) $outline_context['reader_transformation']) : '';
        $main_promise = !empty($outline_context['main_promise']) ? sanitize_textarea_field((string) $outline_context['main_promise']) : '';
        $reader_intent = !empty($outline_context['reader_intent']) ? sanitize_textarea_field((string) $outline_context['reader_intent']) : '';
        $recommended_outline_model_key = !empty($outline_context['recommended_outline_model_key']) ? sanitize_key((string) $outline_context['recommended_outline_model_key']) : '';
        $recommended_prompt_model_key = !empty($outline_context['recommended_prompt_model_key']) ? sanitize_key((string) $outline_context['recommended_prompt_model_key']) : '';
        if ($content_type !== '') {
            $lines[] = 'Tipo de conteudo: ' . $content_type;
        }
        if ($funnel_level !== '') {
            $lines[] = 'Nivel de funil: ' . $funnel_level;
        }
        if ($primary_pain !== '') {
            $lines[] = 'Dor principal: ' . $primary_pain;
        }
        if ($focus_keyword !== '') {
            $lines[] = 'Keyword sugerida: ' . $focus_keyword;
        }
        if ($editorial_conflict !== '') {
            $lines[] = 'Conflito editorial: ' . $editorial_conflict;
        }
        if ($reader_transformation !== '') {
            $lines[] = 'Transformacao esperada do leitor: ' . $reader_transformation;
        }
        if ($main_promise !== '') {
            $lines[] = 'Promessa principal: ' . $main_promise;
        }
        if ($reader_intent !== '') {
            $lines[] = 'Intencao do leitor: ' . $reader_intent;
        }
        if ($recommended_outline_model_key !== '') {
            $lines[] = 'Modelo recomendado: ' . $recommended_outline_model_key;
        }
        if ($recommended_prompt_model_key !== '') {
            $lines[] = 'Modelo de prompt recomendado: ' . $recommended_prompt_model_key;
        }

        if (!empty($outline_context['outline_sections']) && is_array($outline_context['outline_sections'])) {
            $lines[] = 'Esboco editorial:';
            $index = 1;
            foreach ($outline_context['outline_sections'] as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $title = !empty($section['h2']) ? sanitize_text_field((string) $section['h2']) : (!empty($section['title']) ? sanitize_text_field((string) $section['title']) : '');
                $block_type = !empty($section['type']) ? sanitize_key((string) $section['type']) : '';
                $purpose = !empty($section['purpose']) ? sanitize_text_field((string) $section['purpose']) : '';
                $reader_question = !empty($section['reader_question'])
                    ? sanitize_text_field((string) $section['reader_question'])
                    : (!empty($section['semantic']) ? sanitize_text_field((string) $section['semantic']) : '');
                $new_information = !empty($section['new_information'])
                    ? sanitize_text_field((string) $section['new_information'])
                    : (!empty($section['notes']) ? sanitize_text_field((string) $section['notes']) : '');
                $transition = !empty($section['transition']) ? sanitize_text_field((string) $section['transition']) : '';
                if ($block_type === 'intro_without_h2') {
                    $title = 'Introducao';
                    $purpose = 'Comece diretamente com o lead em paragrafos, sem H2.';
                } elseif ($title === '') {
                    $title = 'Secao ' . $index;
                }
                $word_budget = isset($section['word_budget']) ? intval($section['word_budget']) : 0;
                $line = $index . '. ' . $title;
                if ($block_type !== '') {
                    $line .= ' [' . $block_type . ']';
                }
                if ($word_budget > 0) {
                    $line .= ' ~' . $word_budget . ' palavras';
                }
                if ($purpose !== '') {
                    $line .= ' - ' . $purpose;
                }
                $lines[] = $line;
                if ($reader_question !== '') {
                    $lines[] = '   Pergunta do leitor: ' . $reader_question;
                }
                if ($new_information !== '') {
                    $lines[] = '   Informacao nova: ' . $new_information;
                }
                if ($transition !== '') {
                    $lines[] = '   Transicao: ' . $transition;
                }
                $index++;
            }
        }

        if (!empty($outline_context['outline_notes'])) {
            $lines[] = 'Notas: ' . sanitize_textarea_field((string) $outline_context['outline_notes']);
        }

        return implode("\n", $lines);
    }

    public static function build_outline_analysis_prompt($generator, $item, $seo_article = array(), $outline_context = array())
    {
        $generator = is_array($generator) ? $generator : array();
        $item = is_array($item) ? $item : array();
        $seo_article = is_array($seo_article) ? $seo_article : array();
        $outline_context = is_array($outline_context) ? $outline_context : self::build_outline_context_base($generator);

        $source_type = !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss';
        $generation_language = !empty($generator['generation_language'])
            ? Content_Rank_Generator::normalize_generation_language_value($generator['generation_language'])
            : Content_Rank_Generator::get_default_generation_language();
        $is_keyword_only = $source_type === 'keyword_list'
            || ($source_type === 'spreadsheet' && !Content_Rank_Generator::generator_uses_keyword_list_url_reference_mode($generator));
        if ($is_keyword_only) {
            return self::build_keyword_outline_analysis_prompt($generator, $item, $outline_context);
        }

        $source_title = '';
        foreach (array('source_title', 'source_page_title', 'title', 'keyword', 'item_title', 'feed_title') as $candidate_key) {
            if (!empty($item[$candidate_key])) {
                $source_title = self::normalize_prompt_context_text($item[$candidate_key]);
                break;
            }
        }
        if ($source_title === '' && !empty($seo_article['title'])) {
            $source_title = self::normalize_prompt_context_text($seo_article['title']);
        }
        $source_content_html = '';
        foreach (array('source_page_content_html', 'content_html', 'source_page_html') as $candidate_key) {
            if (!empty($item[$candidate_key])) {
                // Planning must scan the complete list whenever possible.
                $source_content_html = self::limit_prompt_html_chars(self::normalize_prompt_context_html(preg_replace('/<title[^>]*>.*?<\/title>/is', '', (string) $item[$candidate_key])), 18000);
                break;
            }
        }
        $source_item_count = self::extract_outline_target_h2_count_from_title($source_title, '');
        if ($source_item_count <= 0) {
            $source_title_for_count = strtolower(self::normalize_prompt_context_text($source_title));
            if (function_exists('remove_accents')) {
                $source_title_for_count = strtolower(remove_accents($source_title_for_count));
            }
            if (preg_match('/\b(\d{1,2})\b.{0,50}\b(?:classicos?|jogos?|titulos?|itens?)\b/i', $source_title_for_count, $count_match)) {
                $source_item_count = intval($count_match[1]);
            }
        }
        $keyword = !empty($item['keyword'])
            ? self::normalize_prompt_context_text((string) $item['keyword'])
            : '';
        $existing_keyword_post_titles = !empty($item['existing_keyword_post_titles']) && is_array($item['existing_keyword_post_titles'])
            ? array_values(array_filter(array_map('strval', $item['existing_keyword_post_titles']), 'strlen'))
            : array();
        $available_prompt_models = Content_Rank_Generator::get_prompt_models($generator);
        $available_prompt_model_keys = array();
        $available_prompt_models_text = array();
        foreach ($available_prompt_models as $available_prompt_model) {
            if (!is_array($available_prompt_model)) {
                continue;
            }
            if (!empty($available_prompt_model['key'])) {
                $available_prompt_model_keys[] = (string) $available_prompt_model['key'];
            }
            $available_prompt_models_text[] = Content_Rank_Generator::format_prompt_model_for_prompt($available_prompt_model);
        }
        $available_prompt_models_text = implode("\n\n---\n\n", $available_prompt_models_text);
        $selected_prompt_model_key = !empty($generator['prompt_model_key'])
            ? Content_Rank_Generator::normalize_prompt_model_key((string) $generator['prompt_model_key'])
            : '';
        $selected_prompt_model = $selected_prompt_model_key !== ''
            ? Content_Rank_Generator::get_prompt_model($selected_prompt_model_key, $generator)
            : array();

        $compact_prompt = array(
            'Você é um classificador editorial interno.',
            'Idioma da resposta: ' . $generation_language . '.',
            'Analise o título, a keyword e o HTML completo da fonte. Ignore rodapé, sidebar, widgets e navegação.',
            'Retorne somente JSON valido com: content_type, funnel_level, primary_pain, focus_keyword, recommended_prompt_model_key. funnel_level deve ser top, mid ou bottom, nunca numero.',
            'Escolha lista para pautas numeradas ou com quantidade; notícia para acontecimento pontual; review para avaliação; comparativo para duas opções; tutorial para passo a passo; artigo para os demais temas evergreen.',
            'Siga primeiro o título e a intenção da pauta; use o HTML apenas para confirmar o contexto.',
            'A focus_keyword deve ser curta, natural e coerente com a pauta.',
            !empty($selected_prompt_model)
                ? 'Modelo fixado pelo gerador: ' . $selected_prompt_model_key . ' (' . (string) $selected_prompt_model['name'] . ').'
                : 'Escolha recommended_prompt_model_key entre os modelos disponiveis abaixo.',
            'Título da fonte: ' . ($source_title !== '' ? $source_title : '[sem título disponível]'),
            $source_item_count > 0 ? 'Quantidade indicada no título: ' . $source_item_count . '.' : '',
            'Keyword: ' . ($keyword !== '' ? $keyword : '[sem keyword]'),
            !empty($existing_keyword_post_titles)
                ? "Posts ja gerados para esta keyword:\n- " . implode("\n- ", $existing_keyword_post_titles) . "\nEscolha um angulo diferente."
                : '',
            'Modelos disponiveis:',
            !empty($selected_prompt_model) ? $selected_prompt_model_key : $available_prompt_models_text,
            'HTML completo da fonte:',
            $source_content_html !== '' ? $source_content_html : '[sem HTML de referencia]',
        );
        return implode("\n", array_values(array_filter($compact_prompt, 'strlen')));
    }

    private static function build_keyword_outline_analysis_prompt($generator, $item, $outline_context = array())
    {
        $generator = is_array($generator) ? $generator : array();
        $item = is_array($item) ? $item : array();
        $outline_context = is_array($outline_context) ? $outline_context : array();
        $generation_language = !empty($generator['generation_language'])
            ? Content_Rank_Generator::normalize_generation_language_value($generator['generation_language'])
            : Content_Rank_Generator::get_default_generation_language();

        $keyword = '';
        foreach (array('keyword', 'title', 'source_title', 'item_title') as $candidate_key) {
            if (!empty($item[$candidate_key])) {
                $keyword = self::normalize_prompt_context_text((string) $item[$candidate_key]);
                if ($keyword !== '') {
                    break;
                }
            }
        }

        $custom_prompt = !empty($generator['prompt_template'])
            ? self::normalize_prompt_context_text((string) $generator['prompt_template'])
            : '';
        if ($custom_prompt !== '') {
            $custom_prompt = self::limit_prompt_html_chars($custom_prompt, 1600);
        }

        $available_prompt_models = Content_Rank_Generator::get_prompt_models($generator);
        $available_models_text = array();
        foreach ($available_prompt_models as $available_prompt_model) {
            if (!is_array($available_prompt_model) || empty($available_prompt_model['key'])) {
                continue;
            }
            $model_key = sanitize_key((string) $available_prompt_model['key']);
            $description = !empty($available_prompt_model['description'])
                ? sanitize_text_field((string) $available_prompt_model['description'])
                : '';
            $available_models_text[] = '- key=' . $model_key . ($description !== '' ? ' | ' . $description : '');
        }

        $reference_html = '';
        foreach (array('source_page_content_html', 'source_page_html', 'content_html', 'content') as $reference_key) {
            if (!empty($item[$reference_key])) {
                $reference_html = self::limit_prompt_html_chars(
                    self::normalize_prompt_context_html((string) $item[$reference_key]),
                    12000
                );
                break;
            }
        }
        $tavily_text = !empty($item['tavily_context']) && is_array($item['tavily_context'])
            ? self::format_tavily_context_for_prompt($item['tavily_context'])
            : '';
        $generator_editorial_context = self::get_generator_editorial_context($generator);
        $generator_name = !empty($generator_editorial_context['name']) ? $generator_editorial_context['name'] : '[sem nome]';
        $generator_category = !empty($generator_editorial_context['category_text']) ? $generator_editorial_context['category_text'] : '[sem categoria]';
        $existing_keyword_post_titles = !empty($item['existing_keyword_post_titles']) && is_array($item['existing_keyword_post_titles'])
            ? array_values(array_filter(array_map('strval', $item['existing_keyword_post_titles']), 'strlen'))
            : array();

        $lines = array(
            'Voce e um planejador editorial para uma keyword, sem pagina de referencia.',
            'Idioma obrigatorio de toda a resposta: ' . $generation_language . '.',
            'Escreva todos os campos textuais exclusivamente nesse idioma. Use outro idioma somente em nomes proprios, termos da keyword e nas chaves tecnicas obrigatorias do JSON.',
            'Analise somente a intencao da keyword e escolha o modelo mais adequado.',
            'O objetivo principal e criar um conteudo com potencial para Google Discover.',
            'Evite estruturar o conteudo como um guia generico apenas porque a keyword e ampla.',
            'Antes de criar o outline, identifique qual e o principal conflito editorial da pauta. Todo o conteudo deve girar em torno desse conflito e evita-lo dispersar em assuntos secundarios.',
            'Escolha lista ou artigo quando a intencao da keyword for um guia ou conteudo aprofundado.',
            'Escolha review quando a keyword pedir avaliacao, melhor produto, vale a pena ou qual escolher.',
            'Escolha comparativo quando houver versus, vs, comparar ou duas opcoes claras.',
            'Escolha tutorial quando houver como fazer, passo a passo ou instrucoes.',
            'Escolha noticia somente quando a keyword indicar acontecimento, anuncio, estreia, lancamento ou atualizacao pontual.',
            'Retorne somente JSON valido com estas chaves: content_type, funnel_level, primary_pain, focus_keyword, recommended_prompt_model_key. funnel_level deve ser top, mid ou bottom.',
            'Use content_type e recommended_prompt_model_key somente com as chaves validas abaixo.',
            'Keyword da pauta: ' . ($keyword !== '' ? $keyword : '[sem keyword]'),
            'Nome do gerador: ' . $generator_name,
            'Categoria editorial: ' . $generator_category,
            'Use o nome do gerador e a categoria editorial para interpretar o contexto da keyword. Nao imponha um nicho diferente apenas porque a keyword e ampla.',
            !empty($existing_keyword_post_titles)
                ? "Posts ja gerados para esta mesma keyword:\n- " . implode("\n- ", $existing_keyword_post_titles) . "\nNao repita a mesma intencao de busca, promessa ou angulo; escolha uma intencao diferente."
                : '',
            $custom_prompt !== '' ? 'Prompt personalizado do gerador: ' . $custom_prompt : '',
            $tavily_text !== '' ? 'Pesquisa factual auxiliar do Tavily: ' . $tavily_text : '',
            $reference_html !== '' ? 'Conteudo HTML de referencia: ' . $reference_html : '',
            'Modelos disponiveis:',
            implode("\n", $available_models_text),
        );

        return implode("\n", array_values(array_filter($lines, 'strlen')));
    }

    public static function normalize_outline_analysis_context($analysis, $outline_context = array())
    {
        $outline_context = is_array($outline_context) ? $outline_context : array();
        $analysis = is_array($analysis) ? $analysis : array();

        $outline_context['content_type'] = !empty($analysis['content_type'])
            ? Content_Rank_Generator::normalize_prompt_model_key((string) $analysis['content_type'])
            : (!empty($outline_context['content_type'])
                ? Content_Rank_Generator::normalize_prompt_model_key((string) $outline_context['content_type'])
                : 'artigo');
        $raw_funnel_level = isset($analysis['funnel_level']) ? $analysis['funnel_level'] : (isset($outline_context['funnel_level']) ? $outline_context['funnel_level'] : 'mid');
        if (is_numeric($raw_funnel_level)) {
            $raw_funnel_level = array(1 => 'top', 2 => 'mid', 3 => 'bottom')[(int) $raw_funnel_level] ?? 'mid';
        }
        $raw_funnel_level = sanitize_key((string) $raw_funnel_level);
        $outline_context['funnel_level'] = in_array($raw_funnel_level, array('top', 'mid', 'bottom'), true) ? $raw_funnel_level : 'mid';
        $outline_context['primary_pain'] = !empty($analysis['primary_pain']) ? sanitize_textarea_field((string) $analysis['primary_pain']) : (!empty($outline_context['primary_pain']) ? sanitize_textarea_field((string) $outline_context['primary_pain']) : '');
        $focus_keyword = !empty($analysis['focus_keyword'])
            ? sanitize_text_field((string) $analysis['focus_keyword'])
            : (!empty($outline_context['focus_keyword']) ? sanitize_text_field((string) $outline_context['focus_keyword']) : '');
        $focus_keyword = preg_replace('/^\s*(?:melhores?|best)\s+/iu', '', (string) $focus_keyword);
        $outline_context['focus_keyword'] = trim((string) $focus_keyword);
        foreach (array('editorial_conflict', 'reader_transformation', 'main_promise', 'reader_intent') as $narrative_key) {
            $outline_context[$narrative_key] = !empty($analysis[$narrative_key])
                ? sanitize_textarea_field((string) $analysis[$narrative_key])
                : (!empty($outline_context[$narrative_key]) ? sanitize_textarea_field((string) $outline_context[$narrative_key]) : '');
        }
        $outline_context['recommended_outline_model_key'] = !empty($analysis['recommended_outline_model_key']) ? sanitize_key((string) $analysis['recommended_outline_model_key']) : (!empty($outline_context['recommended_outline_model_key']) ? sanitize_key((string) $outline_context['recommended_outline_model_key']) : '');
        $outline_context['recommended_prompt_model_key'] = !empty($analysis['recommended_prompt_model_key'])
            ? Content_Rank_Generator::normalize_prompt_model_key((string) $analysis['recommended_prompt_model_key'])
            : (!empty($outline_context['recommended_prompt_model_key'])
                ? Content_Rank_Generator::normalize_prompt_model_key((string) $outline_context['recommended_prompt_model_key'])
                : '');
        $outline_context['outline_notes'] = !empty($analysis['outline_notes']) ? sanitize_textarea_field((string) $analysis['outline_notes']) : '';
        $normalize_context_list = static function ($value) {
            if (is_string($value)) {
                $value = preg_split('/\R/u', $value);
            }
            if (!is_array($value)) {
                return array();
            }

            $normalized = array();
            foreach ($value as $entry) {
                if (is_array($entry)) {
                    foreach (array('term', 'question', 'angle', 'text', 'value') as $entry_key) {
                        if (isset($entry[$entry_key]) && is_scalar($entry[$entry_key])) {
                            $entry = $entry[$entry_key];
                            break;
                        }
                    }
                }
                if (!is_scalar($entry)) {
                    continue;
                }
                $entry = self::normalize_plain_text((string) $entry);
                $entry = preg_replace('/^(?:[-*]\s*|\d+[.)]\s*)/u', '', $entry);
                $entry = trim((string) $entry);
                if ($entry !== '') {
                    $normalized[] = $entry;
                }
            }

            return array_values(array_unique($normalized));
        };
        $sections = array();
        $raw_sections = array();
        if (!empty($analysis['outline_sections']) && is_array($analysis['outline_sections'])) {
            $raw_sections = $analysis['outline_sections'];
        } elseif (!empty($analysis['sections']) && is_array($analysis['sections'])) {
            $raw_sections = $analysis['sections'];
        } elseif (!empty($analysis['outline']) && is_array($analysis['outline'])) {
            $raw_sections = $analysis['outline'];
        }

        foreach ($raw_sections as $section_index => $section) {
            if (is_string($section)) {
                $section = array('title' => $section);
            }
            if (!is_array($section)) {
                continue;
            }

            $section_title = !empty($section['h2'])
                ? sanitize_text_field((string) $section['h2'])
                : (!empty($section['title'])
                    ? sanitize_text_field((string) $section['title'])
                    : (!empty($section['heading']) ? sanitize_text_field((string) $section['heading']) : ''));
            $section_semantic = !empty($section['semantic'])
                ? sanitize_text_field((string) $section['semantic'])
                : (!empty($section['purpose']) ? sanitize_text_field((string) $section['purpose']) : '');
            $section_reader_question = !empty($section['reader_question'])
                ? sanitize_text_field((string) $section['reader_question'])
                : $section_semantic;
            $section_purpose = !empty($section['purpose'])
                ? sanitize_text_field((string) $section['purpose'])
                : $section_semantic;
            $section_new_information = !empty($section['new_information'])
                ? sanitize_text_field((string) $section['new_information'])
                : (!empty($section['notes']) ? sanitize_text_field((string) $section['notes']) : '');
            $section_transition = !empty($section['transition'])
                ? sanitize_text_field((string) $section['transition'])
                : '';
            // Keep the outline useful as editorial direction without allowing
            // each section to become a second article inside the prompt.
            $section_semantic = self::limit_plain_text_words($section_semantic, 16);
            $section_reader_question = self::limit_plain_text_words($section_reader_question, 16);
            $section_purpose = self::limit_plain_text_words($section_purpose, 18);
            $section_new_information = self::limit_plain_text_words($section_new_information, 24);
            $section_transition = self::limit_plain_text_words($section_transition, 16);
            $section_type = !empty($section['type']) ? sanitize_key((string) $section['type']) : '';
            if ($section_type === '' && !empty($section['level'])) {
                $section_type = sanitize_key((string) $section['level']);
            }
            $section_type_aliases = array(
                'intro' => 'intro_without_h2',
                'introduction' => 'intro_without_h2',
                'introducao' => 'intro_without_h2',
                'intro_sem_h2' => 'intro_without_h2',
                'introducao_sem_h2' => 'intro_without_h2',
                'h2_principal' => 'h2',
                'heading' => 'h2',
                'section' => 'h2',
                'secao' => 'h2',
                'topic' => 'h2',
                'h3_principal' => 'h3',
                'conclusao' => 'conclusion',
                'final' => 'conclusion',
                'fechamento' => 'conclusion',
            );
            if (isset($section_type_aliases[$section_type])) {
                $section_type = $section_type_aliases[$section_type];
            }
            if ($section_type === '' || $section_type === 'paragraph' || $section_type === 'text') {
                if ($section_index === 0 && $section_title === '') {
                    $section_type = 'intro_without_h2';
                } elseif ($section_title !== '') {
                    $section_type = !empty($section['level']) && intval($section['level']) >= 3 ? 'h3' : 'h2';
                } else {
                    $section_type = 'paragraph';
                }
            }

            $sections[] = array(
                'type' => $section_type,
                'h2' => $section_title,
                'title' => $section_title,
                'semantic' => $section_semantic,
                'reader_question' => $section_reader_question,
                'purpose' => $section_purpose,
                'new_information' => $section_new_information,
                'transition' => $section_transition,
                'word_budget' => isset($section['word_budget']) ? intval($section['word_budget']) : 0,
                'notes' => !empty($section['notes']) ? sanitize_text_field((string) $section['notes']) : '',
            );
        }

        $normalized_sections = array();
        $intro_seen = false;
        foreach ($sections as $section) {
            $section_type = !empty($section['type']) ? sanitize_key((string) $section['type']) : '';
            $section_title = !empty($section['h2']) ? (string) $section['h2'] : '';
            if (in_array($section_type, array('intro', 'intro_with_title', 'intro_without_h2'), true)) {
                if ($intro_seen) {
                    continue;
                }
                $intro_seen = true;
                $section['type'] = 'intro_without_h2';
                $section['h2'] = '';
            } elseif (in_array($section_type, array('h1', 'h2', 'h3'), true) && self::is_intro_heading_text($section_title)) {
                continue;
            }
            $normalized_sections[] = $section;
        }
        $sections = $normalized_sections;

        if (!empty($sections) && $sections[0]['type'] !== 'intro_without_h2') {
            array_unshift($sections, array(
                'type' => 'intro_without_h2',
                'h2' => '',
                'title' => '',
                'semantic' => '',
                'reader_question' => 'Qual e a situacao, dor ou fato que trouxe o leitor ate este conteudo?',
                'purpose' => 'Comece diretamente com o lead em paragrafos, sem H2.',
                'new_information' => '',
                'transition' => '',
                'word_budget' => 0,
                'notes' => '',
            ));
        }

        $outline_context['outline_sections'] = $sections;
        $outline_context['outline_text'] = self::format_outline_analysis_for_prompt($outline_context);

        return $outline_context;
    }

    public static function build_outline_context_from_source($generator, $item, $seo_article = array(), $outline_context = array())
    {
        $outline_context = is_array($outline_context) && !empty($outline_context) ? $outline_context : self::build_outline_context_base($generator);
        $selected_prompt_model_key = !empty($generator['prompt_model_key'])
            ? Content_Rank_Generator::normalize_prompt_model_key((string) $generator['prompt_model_key'])
            : '';
        $selected_prompt_model = $selected_prompt_model_key !== ''
            ? Content_Rank_Generator::get_prompt_model($selected_prompt_model_key, $generator)
            : array();
        if (!empty($selected_prompt_model)) {
            $outline_context['selected_prompt_model_key'] = $selected_prompt_model_key;
            $outline_context['recommended_prompt_model_key'] = $selected_prompt_model_key;
            $outline_context['recommended_outline_model_key'] = !empty($selected_prompt_model['outline_model_key'])
                ? sanitize_key((string) $selected_prompt_model['outline_model_key'])
                : '';
        }
        $outline_model_hint_key = self::infer_outline_model_key_from_source_context($generator, $item, $seo_article, $outline_context);
        if (empty($outline_context['outline_model_hint_key'])) {
            $outline_context['outline_model_hint_key'] = $outline_model_hint_key;
        }
        $use_outline_ai = !isset($outline_context['force_outline_ai']) || !empty($outline_context['force_outline_ai']);
        if (!$use_outline_ai) {
            $outline_context['content_type'] = !empty($outline_model_hint_key)
                ? Content_Rank_Generator::normalize_prompt_model_key((string) $outline_model_hint_key)
                : 'artigo';
            $outline_context['funnel_level'] = $outline_model_hint_key === 'news_short' ? 'top' : 'mid';
            $outline_context['primary_pain'] = !empty($outline_context['primary_pain'])
                ? $outline_context['primary_pain']
                : (!empty($item['source_page_excerpt'])
                    ? self::limit_plain_text_words(self::normalize_plain_text((string) $item['source_page_excerpt']), 30)
                    : '');
            $outline_context['focus_keyword'] = !empty($outline_context['focus_keyword'])
                ? $outline_context['focus_keyword']
                : (!empty($item['keyword'])
                    ? self::normalize_plain_text((string) $item['keyword'])
                    : (!empty($item['source_title']) ? self::normalize_plain_text((string) $item['source_title']) : ''));
            $outline_context['recommended_outline_model_key'] = !empty($outline_model_hint_key)
                ? $outline_model_hint_key
                : (!empty($outline_context['outline_model_key'])
                    ? sanitize_key((string) $outline_context['outline_model_key'])
                    : (!empty($generator['outline_model_key'])
                        ? sanitize_key((string) $generator['outline_model_key'])
                        : 'list_article'));
            $outline_context['recommended_prompt_model_key'] = Content_Rank_Generator::get_prompt_model_key_for_content_type(
                $outline_context['recommended_outline_model_key'],
                $outline_context,
                $generator
            );

            $source_page_outline_titles = self::build_source_outline_titles_for_prompt($item, 0, $generator);
            $sections = array();
            if ($source_page_outline_titles !== '') {
                $outline_title_lines = preg_split('/\R/u', $source_page_outline_titles);
                if (is_array($outline_title_lines)) {
                    foreach ($outline_title_lines as $outline_title_line) {
                        $outline_title_line = trim((string) $outline_title_line);
                        if ($outline_title_line === '') {
                            continue;
                        }
                        $outline_title_line = preg_replace('/^\d+\.\s*/', '', $outline_title_line);
                        if ($outline_title_line === '') {
                            continue;
                        }
                        $sections[] = array(
                            'type' => 'h2',
                            'h2' => $outline_title_line,
                            'purpose' => '',
                            'word_budget' => 0,
                            'notes' => '',
                        );
                    }
                }
            }
            $outline_context['outline_sections'] = $sections;
            $outline_context = Content_Rank_Generator::apply_outline_model_context($generator, $outline_context);
            $outline_context['outline_text'] = self::format_outline_analysis_for_prompt($outline_context);
            return $outline_context;
        }

        $outline_prompt = self::build_outline_analysis_prompt($generator, $item, $seo_article, $outline_context);
        $outline_response = Content_Rank_Generator::request_openai_json($generator, $outline_prompt, array(
            'stage' => 'outline',
            'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
            'item_title' => !empty($item['source_title']) ? $item['source_title'] : '',
            'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
            'allow_missing_content_html' => 1,
            'preserve_extra_fields' => 1,
        ));

        if (is_wp_error($outline_response)) {
            $outline_context['outline_error'] = $outline_response->get_error_message();
        } else {
            $outline_context = self::normalize_outline_analysis_context($outline_response, $outline_context);

            // A manual model selection must not be replaced by the classifier response.
            if (!empty($selected_prompt_model)) {
                $outline_context['content_type'] = $selected_prompt_model_key;
                $outline_context['recommended_prompt_model_key'] = $selected_prompt_model_key;
                $outline_context['recommended_outline_model_key'] = !empty($selected_prompt_model['outline_model_key'])
                    ? sanitize_key((string) $selected_prompt_model['outline_model_key'])
                    : '';
            }

            // Preserve every source item named by a quantified headline. The AI
            // often summarizes the introduction and omits the remaining names.
            $source_title_for_count = '';
            foreach (array('source_title', 'source_page_title', 'title', 'keyword', 'item_title', 'feed_title') as $candidate_key) {
                if (!empty($item[$candidate_key])) {
                    $source_title_for_count = self::normalize_prompt_context_text($item[$candidate_key]);
                    break;
                }
            }
            $source_item_count = self::extract_outline_target_h2_count_from_title($source_title_for_count, '');
            if ($source_item_count <= 0) {
                $source_title_for_count = strtolower($source_title_for_count);
                if (function_exists('remove_accents')) {
                    $source_title_for_count = strtolower(remove_accents($source_title_for_count));
                }
                if (preg_match('/\b(\d{1,2})\b.{0,50}\b(?:classicos?|jogos?|titulos?|itens?)\b/i', $source_title_for_count, $count_match)) {
                    $source_item_count = intval($count_match[1]);
                }
            }
        }

        $prompt_models = Content_Rank_Generator::get_prompt_models($generator);
        $available_prompt_model_keys = array();
        foreach ($prompt_models as $prompt_model) {
            if (!empty($prompt_model['key'])) {
                $available_prompt_model_keys[] = (string) $prompt_model['key'];
            }
        }

        if (!empty($outline_context['recommended_prompt_model_key']) && !empty($available_prompt_model_keys) && !in_array($outline_context['recommended_prompt_model_key'], $available_prompt_model_keys, true)) {
            $outline_context['recommended_prompt_model_key'] = '';
        }

        if (!empty($outline_context['recommended_prompt_model_key'])) {
            $prompt_model = Content_Rank_Generator::get_prompt_model($outline_context['recommended_prompt_model_key'], $generator);
            if (!empty($prompt_model['outline_model_key'])) {
                $outline_context['recommended_outline_model_key'] = (string) $prompt_model['outline_model_key'];
            }
        } else {
            // A valid content_type from the planner must win over the
            // generator's legacy outline default (often list_article).
            $candidate_prompt_model_key = Content_Rank_Generator::get_prompt_model_key_for_content_type(
                !empty($outline_context['content_type']) ? $outline_context['content_type'] : '',
                $outline_context,
                $generator
            );
            if ($candidate_prompt_model_key !== '') {
                $prompt_model = Content_Rank_Generator::get_prompt_model($candidate_prompt_model_key, $generator);
                if (!empty($prompt_model)) {
                    $outline_context['recommended_prompt_model_key'] = $candidate_prompt_model_key;
                    if (!empty($prompt_model['outline_model_key'])) {
                        $outline_context['recommended_outline_model_key'] = (string) $prompt_model['outline_model_key'];
                    }
                }
            }

            if (empty($outline_context['recommended_prompt_model_key'])) {
                $candidate_outline_model_key = !empty($outline_context['recommended_outline_model_key'])
                    ? sanitize_key((string) $outline_context['recommended_outline_model_key'])
                    : $outline_model_hint_key;
                foreach ($prompt_models as $prompt_model) {
                    if (!is_array($prompt_model)) {
                        continue;
                    }
                    if (!empty($prompt_model['outline_model_key']) && $prompt_model['outline_model_key'] === $candidate_outline_model_key && !empty($prompt_model['key'])) {
                        $outline_context['recommended_prompt_model_key'] = Content_Rank_Generator::normalize_prompt_model_key((string) $prompt_model['key']);
                        break;
                    }
                }
            }
        }

        $outline_context = Content_Rank_Generator::apply_outline_model_context($generator, $outline_context);
        $outline_context['outline_text'] = self::format_outline_analysis_for_prompt($outline_context);
        return $outline_context;
    }
    public static function build_prompt($generator, $item, $outline_context = array())
    {
        $item = is_array($item) ? $item : array();
        $outline_context = is_array($outline_context) ? $outline_context : array();
        if (empty($item['existing_keyword_post_titles']) && !empty($outline_context['existing_keyword_post_titles'])) {
            $item['existing_keyword_post_titles'] = $outline_context['existing_keyword_post_titles'];
        }
        $prompt_model = Content_Rank_Generator::get_generator_prompt_model($generator, $outline_context);
        $template = !empty($prompt_model['seo_prompt_template']) ? trim((string) $prompt_model['seo_prompt_template']) : trim((string) $generator['prompt_template']);
        $source_type = isset($generator['source_type']) ? sanitize_key($generator['source_type']) : 'rss';
        $keyword_list_mode = isset($generator['keyword_list_mode']) ? sanitize_key($generator['keyword_list_mode']) : Content_Rank_Generator::get_default_keyword_list_mode();
        if ($template === '') {
            $template = Content_Rank_Generator::normalize_prompt_template_for_source_type($source_type, $template, $keyword_list_mode);
        }
        if ($template === '') {
            $template = ($source_type === 'keyword_list' && $keyword_list_mode !== 'url_reference') ? Content_Rank_Generator::get_default_keyword_prompt_template() : Content_Rank_Generator::get_default_prompt_template();
        }

        $row_data = isset($item['row_data']) && is_array($item['row_data']) ? wp_json_encode($item['row_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $source_title = '';
        $source_page_html_source = '';
        foreach (array('source_title', 'title', 'keyword', 'item_title', 'feed_title', 'source_page_title') as $candidate_key) {
            if (!empty($item[$candidate_key])) {
                $source_title = self::normalize_plain_text((string) $item[$candidate_key]);
                break;
            }
        }
        $source_url = isset($item['source_url']) ? $item['source_url'] : '';
        if ($source_url === '' && isset($item['permalink'])) {
            $source_url = $item['permalink'];
        }
        $source_site_name = '';
        if ($source_url !== '') {
            $parts = wp_parse_url($source_url);
            if (!empty($parts['host'])) {
                $source_site_name = preg_replace('/^www\./i', '', (string) $parts['host']);
            }
        }
        $source_page_html = '';
        foreach (array('source_page_content_html', 'source_page_html', 'content_html') as $candidate_key) {
            if (!empty($item[$candidate_key])) {
                $source_page_html_source = $candidate_key;
                $source_page_html = self::limit_prompt_html_chars(self::normalize_prompt_context_html(preg_replace('/<title[^>]*>.*?<\/title>/is', '', (string) $item[$candidate_key])), 6000);
                break;
            }
        }
        $source_excerpt_summary = '';
        if (!empty($item['source_page_excerpt'])) {
            $source_excerpt_summary = self::normalize_plain_text((string) $item['source_page_excerpt']);
        } elseif (!empty($item['excerpt'])) {
            $source_excerpt_summary = self::normalize_plain_text((string) $item['excerpt']);
        }
        $source_excerpt_summary = self::limit_plain_text_words($source_excerpt_summary, 100);

        $source_image_url = isset($item['source_image_url']) ? $item['source_image_url'] : '';
        $source_link_url = isset($item['source_link_url']) ? $item['source_link_url'] : '';
        $source_link_text = isset($item['source_link_text']) ? $item['source_link_text'] : '';
        $source_page_outline = isset($item['source_page_outline']) ? $item['source_page_outline'] : '';
        $image_selector_class = !empty($generator['image_selector_class']) ? $generator['image_selector_class'] : '';
        $link_selector_class = !empty($generator['link_selector_class']) ? $generator['link_selector_class'] : '';
        $final_slug = isset($item['final_slug']) ? $item['final_slug'] : '';
        $selected_tags = Content_Rank_Generator::get_generator_selected_tags($generator);
        $selected_tags_csv = !empty($selected_tags) ? implode(', ', $selected_tags) : '';
        $content_length_class = !empty($outline_context['content_length_class']) ? Content_Rank_Generator::normalize_content_length_class($outline_context['content_length_class']) : Content_Rank_Generator::get_default_content_length_class();
        $content_length_range = Content_Rank_Generator::get_content_length_range($content_length_class);
        $content_length_label = !empty($content_length_range['label']) ? $content_length_range['label'] : ucfirst($content_length_class);
        $content_length_min_words = isset($content_length_range['min_words']) ? intval($content_length_range['min_words']) : 0;
        $content_length_max_words = isset($content_length_range['max_words']) ? intval($content_length_range['max_words']) : 0;

        $replacements = array(
            '{{feed_title}}' => isset($item['feed_title']) ? (string) $item['feed_title'] : '',
            '{{source_title}}' => $source_title,
            '{{keyword}}' => isset($item['keyword']) ? $item['keyword'] : '',
            '{{source_url}}' => $source_url,
            '{{source_site_name}}' => $source_site_name,
            '{{source_permalink}}' => $item['permalink'],
            '{{source_image_url}}' => $source_image_url,
            '{{source_link_url}}' => $source_link_url,
            '{{source_link_text}}' => $source_link_text,
            '{{image_selector_class}}' => $image_selector_class,
            '{{link_selector_class}}' => $link_selector_class,
            '{{source_page_outline}}' => $source_page_outline,
            '{{source_excerpt}}' => $source_excerpt_summary,
            '{{source_content}}' => $source_excerpt_summary,
            '{{source_page_html}}' => $source_page_html,
            '{{final_slug}}' => $final_slug,
            '{{row_data}}' => $row_data,
            '{{site_name}}' => get_bloginfo('name'),
            '{{generator_name}}' => $generator['name'],
            '{{generation_language}}' => !empty($generator['generation_language']) ? Content_Rank_Generator::normalize_generation_language_value($generator['generation_language']) : Content_Rank_Generator::get_default_generation_language(),
            '{{selected_tags}}' => $selected_tags_csv,
            '{{content_length_class}}' => $content_length_class,
            '{{content_length_label}}' => $content_length_label,
            '{{content_length_min_words}}' => $content_length_min_words,
            '{{content_length_max_words}}' => $content_length_max_words,
            '{{prompt_model_name}}' => !empty($prompt_model['name']) ? $prompt_model['name'] : '',
            '{{prompt_model_key}}' => !empty($prompt_model['key']) ? $prompt_model['key'] : '',
            '{{prompt_model_outline_key}}' => !empty($prompt_model['outline_model_key']) ? $prompt_model['outline_model_key'] : '',
        );

        if (strpos($template, '{{selected_tags}}') !== false) {
            $template = preg_replace('/^.*\{\{selected_tags\}\}.*(?:\r?\n|$)/m', '', $template);
            $template = trim((string) $template);
        }

        // SEO needs the title and short source context, not the article body.
        // The planning and content stages still receive the filtered HTML.
        $source_context_block = self::build_source_context_block($generator, $item, array(
            'include_html' => false,
        ));
        $prompt = strtr($template, $replacements);
        $prompt .= "\n\nREGRAS DE TITULO E KEYWORD:\n";
        $prompt .= "- Em pautas de lista, o titulo pode indicar no maximo 10 itens, mas nunca assuma 10 por padrao. Escolha apenas a quantidade necessaria para a pauta e nunca preencha a lista ate o limite maximo sem motivo editorial.\n";
        $prompt .= "- Use no focus_keyword apenas os termos essenciais da pauta. Nao adicione 'melhor', 'melhores', 'best' ou superlativos que nao estejam no titulo ou na fonte.\n";
        $prompt .= "- A keyword e uma referencia semantica, nao uma frase que precise ser copiada literalmente. Reescreva-a quando necessario para uma frase natural, com artigos, preposicoes, genero e numero corretos em portugues.\n";
        $prompt .= "- Para a plataforma Netflix, use a forma natural 'na Netflix' ou 'da Netflix'. Nunca use 'no Netflix' e nao una 'filmes' a 'Netflix' sem preposicao quando isso prejudicar a concordancia. Exemplo valido: '10 filmes infantis na Netflix para a familia aproveitar'.\n";
        if (!empty($item['review_products_prompt'])) {
            $prompt .= "\n\nDADOS DOS PRODUTOS DA REVIEW:\n" . trim((string) $item['review_products_prompt']);
        }
        $prompt .= "\n\n";
        $prompt .= "\n\n" . Content_Rank_Generator::get_prompt_output_suffix();
        $prompt .= "\n\n";
        $prompt .= trim($source_context_block);

        $prompt_preview = preg_replace('/\s+/', ' ', wp_strip_all_tags($prompt));
        $prompt_preview = function_exists('mb_substr') ? mb_substr($prompt_preview, 0, 1400) : substr($prompt_preview, 0, 1400);

        return $prompt;
    }

    public static function build_content_outline_prompt($generator, $item, $seo_article, $outline_context = array())
    {
        $generator = is_array($generator) ? $generator : array();
        $item = is_array($item) ? $item : array();
        $seo_article = is_array($seo_article) ? $seo_article : array();
        $outline_context = is_array($outline_context) ? $outline_context : array();

        $source_title = '';
        foreach (array('source_title', 'source_page_title', 'title', 'keyword', 'item_title', 'feed_title') as $candidate_key) {
            if (!empty($item[$candidate_key])) {
                $source_title = self::normalize_prompt_context_text((string) $item[$candidate_key]);
                break;
            }
        }
        $generated_title = !empty($seo_article['title']) ? self::normalize_prompt_context_text((string) $seo_article['title']) : '';
        $keyword = !empty($seo_article['focus_keyword'])
            ? self::normalize_prompt_context_text((string) $seo_article['focus_keyword'])
            : (!empty($item['keyword']) ? self::normalize_prompt_context_text((string) $item['keyword']) : '');
        $content_type = !empty($outline_context['content_type'])
            ? Content_Rank_Generator::normalize_prompt_model_key((string) $outline_context['content_type'])
            : '';
        $funnel_level = !empty($outline_context['funnel_level'])
            ? sanitize_key((string) $outline_context['funnel_level'])
            : '';
        $primary_pain = !empty($outline_context['primary_pain'])
            ? self::normalize_prompt_context_text((string) $outline_context['primary_pain'])
            : '';
        $editorial_conflict = !empty($outline_context['editorial_conflict'])
            ? self::normalize_prompt_context_text((string) $outline_context['editorial_conflict'])
            : '';
        $reader_transformation = !empty($outline_context['reader_transformation'])
            ? self::normalize_prompt_context_text((string) $outline_context['reader_transformation'])
            : '';
        $main_promise = !empty($outline_context['main_promise'])
            ? self::normalize_prompt_context_text((string) $outline_context['main_promise'])
            : '';
        $reader_intent = !empty($outline_context['reader_intent'])
            ? self::normalize_prompt_context_text((string) $outline_context['reader_intent'])
            : '';
        $prompt_model_key = !empty($outline_context['recommended_prompt_model_key'])
            ? Content_Rank_Generator::normalize_prompt_model_key((string) $outline_context['recommended_prompt_model_key'])
            : '';
        $outline_model = !empty($outline_context['outline_model']) && is_array($outline_context['outline_model'])
            ? $outline_context['outline_model']
            : array();
        $outline_model_key = !empty($outline_context['outline_model_key'])
            ? sanitize_key((string) $outline_context['outline_model_key'])
            : (!empty($outline_model['key']) ? sanitize_key((string) $outline_model['key']) : '');
        $outline_model_name = !empty($outline_context['outline_model_name'])
            ? self::normalize_prompt_context_text((string) $outline_context['outline_model_name'])
            : (!empty($outline_model['name']) ? self::normalize_prompt_context_text((string) $outline_model['name']) : '');
        $outline_model_description = !empty($outline_model['description'])
            ? self::normalize_prompt_context_text((string) $outline_model['description'])
            : '';
        $source_type = !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss';
        $generation_language = !empty($generator['generation_language'])
            ? Content_Rank_Generator::normalize_generation_language_value($generator['generation_language'])
            : Content_Rank_Generator::get_default_generation_language();
        $is_keyword_only = $source_type === 'keyword_list'
            || ($source_type === 'spreadsheet' && !Content_Rank_Generator::generator_uses_keyword_list_url_reference_mode($generator));

        $source_html = '';
        foreach (array('source_page_content_html', 'source_page_html', 'content_html', 'content') as $source_key) {
            if (!empty($item[$source_key])) {
                $source_html = self::limit_prompt_html_chars(
                    self::normalize_prompt_context_html(preg_replace('/<title[^>]*>.*?<\/title>/is', '', (string) $item[$source_key])),
                    18000
                );
                break;
            }
        }
        // The editorial content-outline pass is disabled temporarily.
        $source_outline_titles = '';
        $review_products_prompt = !empty($item['review_products_prompt'])
            ? trim((string) $item['review_products_prompt'])
            : '';
        $tavily_text = !empty($item['tavily_context']) && is_array($item['tavily_context'])
            ? self::format_tavily_context_for_prompt($item['tavily_context'])
            : '';
        $generator_context = self::get_generator_editorial_context($generator);

        $is_list_outline = in_array($content_type, array('lista', 'list', 'list_article'), true)
            || in_array($prompt_model_key, array('lista', 'list', 'list_article'), true)
            || $outline_model_key === 'list_article';
        $outline_structure_key = $content_type;
        if ($outline_structure_key === '' && $is_list_outline) {
            $outline_structure_key = 'lista';
        }
        $outline_structure_rules = array(
            'COMECE DE UMA VEZ COM A INTRODUCAO: use type=intro_without_h2, title vazio e nenhum H2 para a introducao.',
            'A ultima secao deve ser a conclusao do conteudo e usar type=conclusion.',
        );
        if ($outline_structure_key === 'lista') {
            $outline_structure_rules[] = 'ESTRUTURA LISTA: intro_without_h2, um H2 para cada item da lista na ordem solicitada e conclusion.';
            $outline_structure_rules[] = 'No modelo lista, cada item deve ser type=h2. Nao use H3 para substituir os itens e nao crie secoes de artigo entre eles.';
            $outline_structure_rules[] = 'O desenvolvimento deve tratar somente dos itens prometidos pelo titulo e pelas informacoes da fonte.';
        } elseif ($outline_structure_key === 'noticia') {
            $outline_structure_rules[] = 'ESTRUTURA NOTICIA: intro_without_h2, no maximo 2 H2 de desenvolvimento e conclusion.';
            $outline_structure_rules[] = 'Priorize o fato principal, seus detalhes confirmados, contexto diretamente relacionado e desdobramentos. Nao transforme a noticia em guia, review ou artigo aprofundado.';
            $outline_structure_rules[] = 'Nao use H3 e nao crie listas de beneficios, erros, prazos ou sinais apenas para aumentar o tamanho.';
        } elseif ($outline_structure_key === 'artigo') {
            $outline_structure_rules[] = 'ESTRUTURA ARTIGO: intro_without_h2, ate 3 H2 de desenvolvimento e conclusion.';
            $outline_structure_rules[] = 'Use no maximo 3 H3, somente sob um H2 que precise organizar decisoes ou etapas relacionadas. Nao crie H3 para cada topico apenas para aumentar o tamanho.';
            $outline_structure_rules[] = 'Construa um fio condutor a partir de uma duvida, conflito ou expectativa principal do leitor. Cada secao deve aproximar a resposta e trazer informacao nova.';
        } else {
            $outline_structure_rules[] = 'ESTRUTURA EDITORIAL: desenvolvimento em H2 e H3 especificos e conclusion, como em um artigo.';
            $outline_structure_rules[] = 'Para este modelo, prefira titulos claros e informativos; use provocacao somente quando fizer sentido para o tema.';
        }

        $lines = array(
            "Você é um estrategista de conteúdo especializado em SEO editorial e Google Discover. Sua tarefa é gerar apenas a estrutura hierárquica (outline) de um conteúdo, sem escrever o texto completo.",
            "",
            "Antes de montar o outline, decida qual historia este artigo vai contar. Defina o conflito editorial, a transformacao esperada do leitor, a promessa principal e a intencao de busca. O artigo deve conduzir o leitor de uma situacao inicial ate uma resposta clara, nao apenas reunir topicos.",
            "Escreva tambem uma historia editorial concreta: o conflito deve explicar o que o leitor realmente quer resolver, e a transformacao deve dizer o que ele entendera ou conseguira fazer ao terminar. Nao aceite formulacoes genericas como 'o artigo explica o tema'.",
            "editorial_conflict deve caber em uma frase e descrever o conflito real da pauta. reader_transformation deve deixar claro o antes e o depois do leitor. main_promise deve corresponder ao que o titulo promete entregar.",
            "",
            "Regras para a estrutura:",
            'IDIOMA OBRIGATORIO DO OUTLINE: ' . $generation_language . '.',
            'Todos os titulos H2/H3, perguntas, propositos, informacoes novas, transicoes, conflitos e demais campos textuais devem ser escritos exclusivamente no idioma do gerador. Nao escreva o outline em ingles. Preserve em outro idioma somente nomes proprios, marcas, obras e termos que existam assim na fonte.',
            "- Use intro_without_h2 para a introducao. Nao crie um H2 chamado Introducao.",
            "- Crie somente os H2 necessarios para responder ao titulo gerado \"$generated_title\". Cada secao deve avancar a resposta com fatos novos.",
            "- Nenhuma secao pode existir apenas porque costuma aparecer em artigos. Cada secao deve responder uma duvida criada pela anterior, apresentar informacao nova e conduzir naturalmente a proxima.",
            "- Nao crie secoes para cobrir beneficios, erros, prazos, sinais, ferramentas ou objecoes por obrigacao. Inclua esses assuntos somente se forem essenciais para o conflito central e estiverem apoiados pela fonte.",
            "- Todos os H2 devem ser claros, específicos e informativos sobre o assunto que desenvolvem. Use curiosidade ou tensão somente quando forem naturais e sustentadas pelos fatos; nunca force um tom provocativo.",
            "- H3 nunca podem ser apenas rótulos ou substantivos soltos como 'Ritual matinal', 'Alimentação' ou 'Sono'. Escreva cada H3 como uma ideia editorial completa, com uma ação, decisão, consequência ou dúvida real do leitor.",
            "- Um H3 deve explicar por que aquele ponto importa ou o que o leitor deve fazer com ele. Prefira construções como 'Comece pelo hábito mais fácil de repetir' em vez de apenas nomear o assunto.",
            "- Mantenha os H3 específicos, naturais e sustentados pela fonte. Não force curiosidade, não use frases de marketing e não transforme cada H3 em uma promessa exagerada.",
            "- A ultima secao e a conclusao: use type=conclusion e um unico H2 com titulo provocativo, especifico e diretamente ligado ao tema. Nao crie uma secao de fechamento separada nem outra conclusao depois dela. O titulo pode gerar curiosidade, mas nao pode usar desafios genericos como 'voce esta pronto', 'aceite o desafio', 'o proximo passo' ou 'agora e com voce'.",
            "",
            "O conteúdo final deve ter no máximo 1200 palavras. O outline deve ser enxuto e não criar seções apenas para aumentar o tamanho.",
            "Mantenha o outline curto: cada pergunta, proposito, informacao nova e transicao deve ser uma frase breve com apenas uma ideia. Nao escreva explicacoes longas dentro do outline.",
            "Somente no modelo lista, entregue todos os itens prometidos pelo titulo. Nos demais modelos, nao transforme numeros ou detalhes secundarios em uma lista de secoes.",
            "Algum ou alguns h2, devem responder diretamente ao título \"$generated_title\", se promete habitos, fale de habitos, se promete cuidados, entregue cuidados, se promete erros, entregue erros e por ai vai, só entregue o que o título pede, é o mais importante e de preferencia, no segundo ou terceiro h2, se promete uma desgraça de passo a passo, entregue a desgraça do passo a passo",
            "Os títulos h2 e h3 devem ter no máximo 60 caracteres e sempre responderem a uma questão focada em SEO",
            "Não use dois pontos nos títulos",
            "Envie apenas o outline, sem comentários, sem explicações, sem markdown, incluindo todos os títulos H2 e H3.",
            'Retorne somente JSON valido com editorial_conflict, reader_transformation, main_promise, reader_intent e outline_sections.',
            'Cada item de outline_sections deve conter exatamente: type, title, reader_question, purpose, new_information, transition.',
            'Use type=h2 ou type=h3 para o desenvolvimento e type=conclusion somente para a ultima secao, com titulo H2 especifico.',
            'Nao crie duas secoes finais. A conclusao provocativa e o unico fechamento e deve ser o ultimo item de outline_sections.',
            'REGRAS ESPECIFICAS DO MODELO: estas regras prevalecem sobre qualquer regra estrutural generica acima:',
            implode("\n", $outline_structure_rules),
            $is_list_outline
                ? 'Modelo lista: siga o titulo gerado e crie uma secao para cada item mencionado nele. Preserve a ordem dos itens da fonte.'
                : 'Demais modelos: construa uma progressao fluida a partir da fonte e do titulo definido.',
            'Modelo: ' . ($outline_model_name !== '' ? $outline_model_name : ($outline_model_key !== '' ? $outline_model_key : '[nao definido]')),
            'Descricao do modelo: ' . ($outline_model_description !== '' ? $outline_model_description : '[sem descricao]'),
            'Categoria editorial: ' . (!empty($generator_context['category_text']) ? $generator_context['category_text'] : '[sem categoria]'),
            'Tipo de conteudo planejado: ' . ($content_type !== '' ? $content_type : '[nao definido]'),
            'Nivel de funil planejado: ' . ($funnel_level !== '' ? $funnel_level : '[nao definido]'),
            'Dor principal planejada: ' . ($primary_pain !== '' ? $primary_pain : '[nao definida]'),
            'Conflito editorial planejado: ' . ($editorial_conflict !== '' ? $editorial_conflict : '[defina a partir da dor principal e da fonte]'),
            'Transformacao esperada do leitor: ' . ($reader_transformation !== '' ? $reader_transformation : '[defina o antes e o depois do leitor]'),
            'Promessa principal: ' . ($main_promise !== '' ? $main_promise : '[defina o que o titulo promete entregar]'),
            'Intencao do leitor: ' . ($reader_intent !== '' ? $reader_intent : '[defina o que o leitor quer descobrir ou resolver]'),
            'Titulo da fonte ou keyword: ' . ($source_title !== '' ? $source_title : ($keyword !== '' ? $keyword : '[sem titulo ou keyword]')),
            'Titulo gerado (esse é o ponto central de tudo, é isso que deve ser respondido no conteúdo): ' . ($generated_title !== '' ? $generated_title : '[sem titulo gerado]'),
            'Keyword foco: ' . ($keyword !== '' ? $keyword : '[sem keyword]'),
            $source_outline_titles !== '' && $is_list_outline ? 'Itens da fonte, preserve a ordem:' . "\n" . $source_outline_titles : '',
            $tavily_text !== '' ? 'Informacoes adicionais coletadas pelo Tavily:' . "\n" . $tavily_text : '',
            $review_products_prompt !== '' ? 'Dados fixos dos produtos da review. Use os placeholders exatamente como informados:' . "\n" . $review_products_prompt : '',
            $review_products_prompt !== '' ? 'Review de produtos: organize uma secao para cada produto e indique no outline o placeholder correspondente. O redator deve usar {{prod1}}, {{prod2}} e assim por diante no ponto exato em que cada card deve aparecer.' : '',
            !$is_keyword_only && $source_html !== '' ? 'HTML filtrado da pagina de referencia:' . "\n" . $source_html : '',
        );

        return implode("\n", array_values(array_filter($lines, 'strlen')));
    }

    public static function generate_content_outline_context($generator, $item, $seo_article, $outline_context = array())
    {
        $outline_prompt = self::build_content_outline_prompt($generator, $item, $seo_article, $outline_context);
        $outline_response = Content_Rank_Generator::request_openai_json($generator, $outline_prompt, array(
            'stage' => 'content_outline',
            'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
            'item_title' => !empty($seo_article['title']) ? $seo_article['title'] : (!empty($item['source_title']) ? $item['source_title'] : ''),
            'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
            'allow_missing_content_html' => 1,
            'preserve_extra_fields' => 1,
            'previous_response_id' => !empty($outline_context['previous_response_id']) ? (string) $outline_context['previous_response_id'] : '',
            'response_schema_name' => 'content_rank_content_outline',
            'response_schema' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array(
                    'editorial_conflict' => array('type' => 'string'),
                    'reader_transformation' => array('type' => 'string'),
                    'main_promise' => array('type' => 'string'),
                    'reader_intent' => array('type' => 'string'),
                    'outline_sections' => array(
                        'type' => 'array',
                        'items' => array(
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => array(
                                'type' => array(
                                    'type' => 'string',
                                    'enum' => array('intro', 'intro_without_h2', 'h2', 'h3', 'conclusion'),
                                ),
                                'title' => array('type' => 'string'),
                                'reader_question' => array('type' => 'string'),
                                'purpose' => array('type' => 'string'),
                                'new_information' => array('type' => 'string'),
                                'transition' => array('type' => 'string'),
                            ),
                            'required' => array('type', 'title', 'reader_question', 'purpose', 'new_information', 'transition'),
                        ),
                    ),
                ),
                'required' => array('editorial_conflict', 'reader_transformation', 'main_promise', 'reader_intent', 'outline_sections'),
            ),
        ));
        if (is_wp_error($outline_response)) {
            return new WP_Error('content_rank_content_outline_failed', 'Falha ao gerar o esboco do conteudo: ' . $outline_response->get_error_message());
        }
        if (!is_array($outline_response)) {
            return new WP_Error('content_rank_content_outline_invalid', 'A IA nao retornou um esboco valido.');
        }
        $outline_response_id = !empty(Content_Rank_Generator::$last_openai_response_id)
            ? (string) Content_Rank_Generator::$last_openai_response_id
            : '';

        $outline_source_type = !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss';
        $is_keyword_only = $outline_source_type === 'keyword_list'
            || ($outline_source_type === 'spreadsheet' && !Content_Rank_Generator::generator_uses_keyword_list_url_reference_mode($generator));
        $result_context = self::normalize_outline_analysis_context($outline_response, $outline_context);
        if (empty($result_context['outline_sections']) || !is_array($result_context['outline_sections'])) {
            return new WP_Error('content_rank_content_outline_empty', 'A IA nao retornou secoes para o esboco do conteudo.');
        }

        // A IA pode interpretar "fecho provocativo" como uma seção própria e
        // ainda criar outra conclusão depois. O fechamento deve existir uma só vez.
        $outline_sections = array();
        $closing_sections = array();
        foreach ($result_context['outline_sections'] as $section) {
            if (!is_array($section)) {
                continue;
            }

            $section_type = sanitize_key(isset($section['type']) ? (string) $section['type'] : '');
            $section_title = '';
            if (!empty($section['title'])) {
                $section_title = (string) $section['title'];
            } elseif (!empty($section['h2'])) {
                $section_title = (string) $section['h2'];
            }
            $section_slug = sanitize_title($section_title);
            $is_closing_section = $section_type === 'conclusion'
                || (bool) preg_match('/(^|-)(fecho|fechamento|encerramento|conclusao|consideracoes-finais|proximo-passo)(-|$)/', $section_slug);

            if ($is_closing_section) {
                $closing_sections[] = $section;
                continue;
            }

            $outline_sections[] = $section;
        }

        if (!empty($closing_sections)) {
            $conclusion_section = end($closing_sections);
            $conclusion_section['type'] = 'conclusion';
            $outline_sections[] = $conclusion_section;
        }

        // Article and news outlines must stay compact. Do not let a model turn
        // every supporting detail into another heading and inflate the article.
        $normalized_outline_type = !empty($result_context['content_type'])
            ? Content_Rank_Generator::normalize_prompt_model_key((string) $result_context['content_type'])
            : '';
        $max_development_sections = $normalized_outline_type === 'artigo'
            ? 3
            : ($normalized_outline_type === 'noticia' ? 2 : 0);
        // Keep other non-list models compact as well. Reviews are excluded
        // because each product may require its own section.
        if ($max_development_sections === 0 && in_array($normalized_outline_type, array('faq', 'tutorial', 'comparativo'), true)) {
            $max_development_sections = 3;
        }
        if ($max_development_sections > 0) {
            $compact_sections = array();
            $compact_conclusion = array();
            $development_count = 0;
            foreach ($outline_sections as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $section_type = sanitize_key(isset($section['type']) ? (string) $section['type'] : '');
                if ($section_type === 'conclusion') {
                    $compact_conclusion = $section;
                    continue;
                }
                if ($section_type === 'intro' || $section_type === 'intro_without_h2') {
                    $compact_sections[] = $section;
                    continue;
                }
                if ($development_count < $max_development_sections) {
                    $compact_sections[] = $section;
                    $development_count++;
                }
            }
            if (!empty($compact_conclusion)) {
                $compact_sections[] = $compact_conclusion;
            }
            $outline_sections = $compact_sections;
        }
        $result_context['outline_sections'] = $outline_sections;

        $has_main_section = false;
        $has_conclusion = false;
        foreach ($result_context['outline_sections'] as $section) {
            if (!is_array($section)) {
                continue;
            }
            $type = sanitize_key(isset($section['type']) ? (string) $section['type'] : '');
            if (in_array($type, array('h2', 'h3'), true)) {
                $has_main_section = true;
            }
            if ($type === 'conclusion') {
                $has_conclusion = true;
            }
        }
        if (!$has_main_section) {
            return new WP_Error('content_rank_content_outline_incomplete', 'A IA retornou um esboco sem desenvolvimento ou conclusao.');
        }
        if (!$has_conclusion) {
            $result_context['outline_sections'][] = array(
                'type' => 'conclusion',
                'h2' => 'O que considerar a seguir',
                'purpose' => 'Retomar a resposta principal e indicar o proximo passo do leitor com base nos fatos apresentados.',
                'word_budget' => 0,
                'notes' => 'Conclusao adicionada pelo backend porque a resposta da IA nao trouxe uma secao final.',
            );
        }

        $result_context['content_outline_generated'] = 1;
        if ($outline_response_id !== '') {
            $result_context['outline_response_id'] = $outline_response_id;
        }
        $result_context['outline_text'] = self::format_outline_analysis_for_prompt($result_context);
        return $result_context;
    }

    public static function build_content_prompt($generator, $item, $seo_article = array(), $outline_context = array())
    {
        $prompt_model = Content_Rank_Generator::get_generator_prompt_model($generator, $outline_context);
        $visible_template = !empty($prompt_model['content_prompt_template']) ? trim((string) $prompt_model['content_prompt_template']) : (isset($generator['content_prompt_template']) ? trim((string) $generator['content_prompt_template']) : '');
        if ($visible_template === '') {
            $visible_template = Content_Rank_Generator::get_default_content_prompt_template_visible();
        }

        $source_title = isset($item['source_title']) ? $item['source_title'] : '';
        if ($source_title === '' && !empty($item['keyword'])) {
            $source_title = (string) $item['keyword'];
        }
        $source_url = isset($item['source_url']) ? $item['source_url'] : '';
        if ($source_url === '' && isset($item['permalink'])) {
            $source_url = $item['permalink'];
        }
        $source_site_name = '';
        if ($source_url !== '') {
            $parts = wp_parse_url($source_url);
            if (!empty($parts['host'])) {
                $source_site_name = preg_replace('/^www\./i', '', (string) $parts['host']);
            }
        }
        $source_page_html = '';
        foreach (array('source_page_content_html', 'source_page_html', 'content_html') as $candidate_key) {
            if (!empty($item[$candidate_key])) {
                $source_page_html = self::limit_prompt_html_chars(self::normalize_prompt_context_html(preg_replace('/<title[^>]*>.*?<\/title>/is', '', (string) $item[$candidate_key])), 6000);
                break;
            }
        }
        $source_outline_titles = self::build_source_outline_titles_for_prompt($item, 0, $generator);
        $selected_tags = Content_Rank_Generator::get_generator_selected_tags($generator);
        $selected_tags_csv = !empty($selected_tags) ? implode(', ', $selected_tags) : '';

        $generated_title = isset($seo_article['title']) ? $seo_article['title'] : '';
        $generated_slug = isset($seo_article['slug']) ? $seo_article['slug'] : '';
        $generated_excerpt = isset($seo_article['excerpt']) ? $seo_article['excerpt'] : '';
        $generated_focus_keyword = isset($seo_article['focus_keyword']) ? $seo_article['focus_keyword'] : '';
        $generated_meta_description = isset($seo_article['meta_description']) ? $seo_article['meta_description'] : '';
        $generated_title_outline_count = self::extract_outline_target_h2_count_from_title($generated_title, $source_title);
        $row_data = isset($item['row_data']) && is_array($item['row_data'])
            ? wp_json_encode($item['row_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';
        $outline_text = !empty($outline_context['outline_text']) ? (string) $outline_context['outline_text'] : '';
        $outline_model_text = !empty($outline_context['outline_model_text']) ? (string) $outline_context['outline_model_text'] : '';
        $outline_model_name = !empty($outline_context['outline_model_name']) ? (string) $outline_context['outline_model_name'] : '';
        $prompt_model_name = !empty($prompt_model['name']) ? (string) $prompt_model['name'] : '';
        $prompt_model_key = !empty($prompt_model['key']) ? (string) $prompt_model['key'] : '';
        $content_type = !empty($outline_context['content_type'])
            ? Content_Rank_Generator::normalize_prompt_model_key((string) $outline_context['content_type'])
            : '';
        $normalized_prompt_model_key = Content_Rank_Generator::normalize_prompt_model_key($prompt_model_key);
        $is_list_content = in_array($content_type, array('lista', 'list', 'list_article'), true)
            || in_array($normalized_prompt_model_key, array('lista', 'list', 'list_article'), true);
        $tavily_context_text = '';
        if (!empty($generator['source_type']) && sanitize_key((string) $generator['source_type']) === 'keyword_list' && !empty($item['tavily_context']) && is_array($item['tavily_context'])) {
            $tavily_context_text = self::format_tavily_context_for_prompt($item['tavily_context']);
        }
        $review_products_prompt = !empty($item['review_products_prompt'])
            ? trim((string) $item['review_products_prompt'])
            : '';
        $generator_editorial_context = self::get_generator_editorial_context($generator);

        $hidden_context = array(
            'Contexto interno:',
            'REESCRITA PRINCIPAL: reescreva o conteudo da fonte de forma original, clara e natural. Preserve os fatos, nomes, anos, ordem dos itens e informacoes importantes. Nao invente dados, nao resuma a ponto de remover itens e nao mencione que o texto foi reescrito.',
            'Titulo gerado: {{generated_title}}',
            'kw: {{generated_focus_keyword}}',
            'Site de referencia: {{source_site_name}}',
            'Slug final: {{generated_slug}}',
            'Modelo de prompt: {{prompt_model_name}}',
            'Idioma final: {{generation_language}}',
            'Conteudo HTML filtrado da fonte: {{source_content}}',
            'LIMITE ABSOLUTO: o content_html final deve ter no maximo 1200 palavras. Se faltar espaco, corte detalhes secundarios; nunca ultrapasse esse limite.',
        );
        if ($source_outline_titles !== '') {
            $hidden_context[] = 'TITULOS DOS ITENS DA FONTE, JA LOCALIZADOS PELO TMDB: ' . $source_outline_titles;
        }
        if ($generated_title_outline_count > 0) {
            $hidden_context[] = 'FIDELIDADE ABSOLUTA AO TITULO: o titulo promete exatamente ' . $generated_title_outline_count . ' itens. Desenvolva exatamente ' . $generated_title_outline_count . ' itens, nem um a mais. Nunca mencione, enumere, recomende ou crie no conteudo itens adicionais encontrados na fonte.';
        } else {
            $hidden_context[] = 'FIDELIDADE AO TITULO: siga exatamente o escopo e a quantidade prometidos pelo titulo. Se o titulo nao informar quantidade, nao transforme automaticamente todos os itens da fonte em uma lista.';
        }
        if (is_string($row_data) && $row_data !== '') {
            $hidden_context[] = 'Dados completos da linha de origem: {{row_data}}';
        }
        if (!empty($outline_context['content_type'])) {
            $hidden_context[] = 'Tipo de conteudo definido no planejamento: ' . sanitize_text_field((string) $outline_context['content_type']);
        }
        if (!empty($outline_context['funnel_level'])) {
            $hidden_context[] = 'Nivel de funil definido no planejamento: ' . sanitize_text_field((string) $outline_context['funnel_level']);
        }
        if (!empty($outline_context['primary_pain'])) {
            $hidden_context[] = 'Dor principal definida no planejamento: ' . sanitize_text_field((string) $outline_context['primary_pain']);
        }
        if (!empty($outline_context['existing_keyword_post_titles']) && is_array($outline_context['existing_keyword_post_titles'])) {
            $hidden_context[] = 'POSTS JA GERADOS PARA ESTA MESMA KEYWORD:';
            foreach ($outline_context['existing_keyword_post_titles'] as $existing_title) {
                $existing_title = trim(wp_strip_all_tags((string) $existing_title));
                if ($existing_title !== '') {
                    $hidden_context[] = '- ' . $existing_title;
                }
            }
            $hidden_context[] = 'O novo titulo precisa atender uma intencao de busca diferente e nao repetir a mesma promessa ou angulo.';
        }
        foreach (
            array(
                'editorial_conflict' => 'Conflito editorial que o artigo deve resolver',
                'reader_transformation' => 'Transformacao esperada do leitor ao final',
                'main_promise' => 'Promessa principal do titulo',
                'reader_intent' => 'Intencao principal do leitor',
            ) as $narrative_key => $narrative_label
        ) {
            if (!empty($outline_context[$narrative_key])) {
                $hidden_context[] = $narrative_label . ': ' . sanitize_textarea_field((string) $outline_context[$narrative_key]);
            }
        }
        $hidden_context[] = 'NARRATIVA OBRIGATORIA: cada bloco deve avancar o conflito editorial, responder a pergunta da secao anterior ou preparar a proxima. Nao crie secoes apenas para preencher estrutura.';
        if ($is_list_content) {
            $hidden_context[] = 'ESTRUTURA OBRIGATORIA DA LISTA: comece com 2 ou 3 paragrafos de introducao sem H2, desenvolva cada item prometido em um H2 na ordem do esboco e termine com a conclusao. Nao use um H2 chamado Introducao e nao transforme os itens em H3.';
            $hidden_context[] = 'LISTAS EM HTML: quando houver dois ou mais itens paralelos dentro de uma secao, use <ol><li>...</li></ol> para itens ordenados ou <ul><li>...</li></ul> quando a ordem nao importar. Nunca escreva varios itens numerados (1), 2), 3)...) dentro de um unico paragrafo.';
        } elseif ($content_type === 'noticia') {
            $hidden_context[] = 'ESTRUTURA OBRIGATORIA DA NOTICIA: comece com um lead forte e factual em paragrafos, sem H2 de introducao; use somente 2 ou 3 H2 no maximo para os detalhes diretamente ligados ao fato e finalize com a conclusao. Nao transforme a noticia em guia ou artigo generico.';
        } elseif ($content_type === 'artigo') {
            $hidden_context[] = 'ESTRUTURA OBRIGATORIA DO ARTIGO: comece com 2 ou 3 paragrafos de lead e contexto sem H2; desenvolva os assuntos especificos em H2 e H3 conforme o esboco e finalize com a conclusao.';
        } else {
            $hidden_context[] = 'ESTRUTURA OBRIGATORIA: a introducao deve ser feita em paragrafos sem H2; desenvolva os topicos do esboco em H2/H3 e finalize com a conclusao.';
        }
        $hidden_context[] = 'LEAD OBRIGATORIO: abra com a situacao, duvida, dor ou fato concreto que motivou a busca. Nao use "Bem-vindo", "este guia", "este artigo", "neste conteudo" ou introducoes genericas de marketing.';
        if ($outline_text !== '') {
            $hidden_context[] = 'ESBOCO EDITORIAL OBRIGATORIO, GERADO DEPOIS DO TITULO SEO:';
            $hidden_context[] = '{{outline_text}}';
            $hidden_context[] = 'Siga este esboco na ordem apresentada. A primeira secao e a introducao sem H2; desenvolva as secoes H2/H3 indicadas; termine pela secao de conclusao. Nao substitua o esboco por uma estrutura generica.';
        }
        if ($review_products_prompt !== '') {
            $hidden_context[] = 'DADOS FIXOS DOS PRODUTOS DA REVIEW:';
            $hidden_context[] = '{{review_products_prompt}}';
            $hidden_context[] = 'REVIEW COM CARDS: use TODOS os placeholders de produtos informados, exatamente uma vez cada e sempre na ordem {{prod1}}, {{prod2}}, {{prod3}}...; coloque cada placeholder sozinho em um bloco, no ponto em que o respectivo produto deve aparecer. Nao crie HTML de card, nao invente dados, nao omita produtos e nao troque a ordem.';
        }
        if ($tavily_context_text !== '') {
            $hidden_context[] = 'Pesquisa factual auxiliar do Tavily. Use apenas como apoio factual e nao invente informacoes fora dela:';
            $hidden_context[] = $tavily_context_text;
        }
        if (!empty($generator['source_type']) && sanitize_key((string) $generator['source_type']) === 'keyword_list') {
            $hidden_context[] = 'Nome do gerador: ' . (!empty($generator_editorial_context['name']) ? $generator_editorial_context['name'] : '[sem nome definido]');
            $hidden_context[] = 'Categoria editorial: ' . (!empty($generator_editorial_context['category_text']) ? $generator_editorial_context['category_text'] : '[sem categoria definida]');
        }

        $template = $visible_template . "

" . implode("
", $hidden_context);

        $replacements = array(
            '{{feed_title}}' => isset($item['feed_title']) ? (string) $item['feed_title'] : '',
            '{{source_title}}' => $source_title,
            '{{keyword}}' => isset($item['keyword']) ? $item['keyword'] : '',
            '{{source_url}}' => $source_url,
            '{{source_site_name}}' => $source_site_name,
            '{{source_permalink}}' => $item['permalink'],
            '{{source_page_title}}' => isset($item['source_page_title']) ? $item['source_page_title'] : '',
            '{{source_page_excerpt}}' => isset($item['source_page_excerpt']) ? $item['source_page_excerpt'] : '',
            '{{source_page_content}}' => isset($item['source_page_content']) ? $item['source_page_content'] : '',
            '{{source_page_html}}' => $source_page_html,
            '{{source_page_outline}}' => isset($item['source_page_outline']) ? $item['source_page_outline'] : '',
            '{{source_excerpt}}' => $item['excerpt'],
            '{{source_content}}' => $source_page_html !== '' ? $source_page_html : (!empty($item['source_page_content']) ? $item['source_page_content'] : $item['content']),
            '{{final_slug}}' => isset($item['final_slug']) ? $item['final_slug'] : '',
            '{{row_data}}' => isset($item['row_data']) && is_array($item['row_data']) ? wp_json_encode($item['row_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            '{{site_name}}' => get_bloginfo('name'),
            '{{generator_name}}' => $generator['name'],
            '{{generation_language}}' => !empty($generator['generation_language']) ? Content_Rank_Generator::normalize_generation_language_value($generator['generation_language']) : Content_Rank_Generator::get_default_generation_language(),
            '{{selected_tags}}' => $selected_tags_csv,
            '{{generated_title}}' => $generated_title,
            '{{generated_slug}}' => $generated_slug,
            '{{generated_excerpt}}' => $generated_excerpt,
            '{{generated_focus_keyword}}' => $generated_focus_keyword,
            '{{generated_meta_description}}' => $generated_meta_description,
            '{{generated_title_outline_count}}' => $generated_title_outline_count,
            '{{outline_model_name}}' => $outline_model_name,
            '{{prompt_model_name}}' => $prompt_model_name,
            '{{prompt_model_key}}' => $prompt_model_key,
            '{{outline_model_text}}' => $outline_model_text,
            '{{outline_text}}' => $outline_text,
            '{{review_products_prompt}}' => $review_products_prompt,
        );

        $prompt = strtr($template, $replacements);
        $prompt = Content_Rank_Generator::append_content_prompt_output_suffix($prompt);
        $prompt_preview = preg_replace('/\s+/', ' ', wp_strip_all_tags($prompt));
        $prompt_preview = function_exists('mb_substr') ? mb_substr($prompt_preview, 0, 1400) : substr($prompt_preview, 0, 1400);

        return $prompt;
    }

    public static function count_heading_level_in_html($html, $level = 2)
    {
        $html = (string) $html;
        $level = max(1, intval($level));
        if ($html === '') {
            return 0;
        }

        $pattern = '/<h' . $level . '\b[^>]*>/i';
        if (!preg_match_all($pattern, $html, $matches)) {
            return 0;
        }

        return count($matches[0]);
    }

    public static function prepare_generation_planning($generator, $item)
    {
        $generator_prompt_model_key = !empty($generator['prompt_model_key'])
            ? Content_Rank_Generator::normalize_prompt_model_key((string) $generator['prompt_model_key'])
            : '';
        $item_content_type = !empty($item['content_type'])
            ? Content_Rank_Generator::normalize_prompt_model_key((string) $item['content_type'])
            : '';
        $is_review_generation = $generator_prompt_model_key === 'review'
            || $item_content_type === 'review'
            || !empty($item['review_products_prompt']);

        // Product reviews have a fixed editorial model. Do not send their
        // product-card HTML to the generic classifier, which can misclassify
        // the review as a list or an article before the review prompt runs.
        if ($is_review_generation) {
            $outline_context = self::build_outline_context_base($generator);
            $focus_keyword = '';
            foreach (array('keyword', 'title', 'source_title', 'source_page_title', 'item_title') as $candidate_key) {
                if (!empty($item[$candidate_key])) {
                    $focus_keyword = self::normalize_prompt_context_text((string) $item[$candidate_key]);
                    if ($focus_keyword !== '') {
                        break;
                    }
                }
            }
            $outline_context['content_type'] = 'review';
            $outline_context['funnel_level'] = 'mid';
            $outline_context['primary_pain'] = 'Comparar os produtos e decidir qual atende melhor a necessidade do leitor.';
            $outline_context['focus_keyword'] = $focus_keyword;
            $outline_context['recommended_prompt_model_key'] = 'review';
            $outline_context['recommended_outline_model_key'] = 'guide_long';
            $outline_context['outline_model_key'] = 'guide_long';
            $outline_context['outline_text'] = self::format_outline_analysis_for_prompt($outline_context);

            return array(
                'item' => is_array($item) ? $item : array(),
                'outline_context' => $outline_context,
            );
        }

        $source_type = !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss';
        if ($source_type === 'keyword_list' && !empty($generator['list_id']) && !empty($item['keyword'])) {
            $keyword_list_row_id = !empty($item['keyword_list_row_id']) ? intval($item['keyword_list_row_id']) : 0;
            $existing_keyword_post_titles = Content_Rank_Generator::get_generated_keyword_post_titles(
                intval($generator['list_id']),
                (string) $item['keyword'],
                $keyword_list_row_id,
                25
            );
            if (!empty($existing_keyword_post_titles)) {
                $item['existing_keyword_post_titles'] = $existing_keyword_post_titles;
            }
        }
        if ($source_type === 'keyword_list' && !empty($generator['tavily_enabled'])) {
            $generator_editorial_context = self::get_generator_editorial_context($generator);
            $keyword_query = '';
            foreach (array('keyword', 'title', 'source_title', 'item_title') as $candidate_key) {
                if (!empty($item[$candidate_key])) {
                    $keyword_query = self::normalize_prompt_context_text((string) $item[$candidate_key]);
                    if ($keyword_query !== '') {
                        break;
                    }
                }
            }

            if ($keyword_query !== '') {
                if (!empty($generator_editorial_context['category_text'])) {
                    $keyword_query .= ' ' . $generator_editorial_context['category_text'];
                }
                $settings = Content_Rank_Generator::get_settings();
                $tavily_context = self::fetch_tavily_search_context(
                    $keyword_query,
                    !empty($settings['tavily_max_results']) ? intval($settings['tavily_max_results']) : 3,
                    !empty($settings['tavily_include_answer']),
                    false,
                    true
                );
                if (!empty($tavily_context) && is_array($tavily_context)) {
                    $item['tavily_context'] = $tavily_context;
                }
            }
        }

        // Planning remains active. It defines the content type, funnel and
        // SEO context; only the later editorial content-outline pass is off.
        $outline_base_context = self::build_outline_context_base($generator);
        $outline_context = self::build_outline_context_from_source($generator, $item, array(), $outline_base_context);
        if (is_wp_error($outline_context)) {
            return $outline_context;
        }

        if (!empty($item['existing_keyword_post_titles']) && is_array($item['existing_keyword_post_titles'])) {
            $outline_context['existing_keyword_post_titles'] = array_values($item['existing_keyword_post_titles']);
        }

        return array(
            'item' => $item,
            'outline_context' => is_array($outline_context) ? $outline_context : array(),
        );
    }

    public static function generate_seo_article_stage($generator, $item, $outline_context = array())
    {
        $item = is_array($item) ? $item : array();
        $outline_context = is_array($outline_context) ? $outline_context : array();
        $seo_prompt = self::build_prompt($generator, $item, $outline_context);
        $seo_article = Content_Rank_Generator::request_openai_json($generator, $seo_prompt, array(
            'stage' => 'seo',
            'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
            'item_title' => !empty($item['source_title']) ? $item['source_title'] : '',
            'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
            'excerpt_length' => !empty($item['excerpt']) ? strlen((string) $item['excerpt']) : 0,
            'content_length' => !empty($item['content']) ? strlen((string) $item['content']) : 0,
            'source_context_enriched' => !empty($item['source_context_enriched']) ? 1 : 0,
            'allow_missing_content_html' => 1,
        ));
        if (is_wp_error($seo_article)) {
            return $seo_article;
        }
        $seo_response_id = !empty(Content_Rank_Generator::$last_openai_response_id) ? Content_Rank_Generator::$last_openai_response_id : '';

        $generated_title_outline_count = self::extract_outline_target_h2_count_from_title(
            !empty($seo_article['title']) ? $seo_article['title'] : '',
            !empty($item['source_title']) ? $item['source_title'] : (!empty($item['title']) ? $item['title'] : '')
        );
        if ($generated_title_outline_count > 0) {
            $outline_context['force_exact_h2_count'] = 1;
            $outline_context['outline_target_h2_min'] = $generated_title_outline_count;
            $outline_context['outline_target_h2_max'] = $generated_title_outline_count;
            $outline_context['outline_target_h2_count'] = $generated_title_outline_count;
        }
        if (empty($seo_article['focus_keyword']) && !empty($outline_context['focus_keyword'])) {
            $seo_article['focus_keyword'] = $outline_context['focus_keyword'];
        } elseif (empty($seo_article['focus_keyword']) && !empty($item['keyword'])) {
            $seo_article['focus_keyword'] = sanitize_text_field((string) $item['keyword']);
        }
        if (empty($seo_article['meta_description'])) {
            if (!empty($seo_article['excerpt'])) {
                $seo_article['meta_description'] = wp_trim_words(wp_strip_all_tags((string) $seo_article['excerpt']), 28);
            } elseif (!empty($item['excerpt'])) {
                $seo_article['meta_description'] = wp_trim_words(wp_strip_all_tags((string) $item['excerpt']), 28);
            } elseif (!empty($outline_context['outline_notes'])) {
                $seo_article['meta_description'] = wp_trim_words(wp_strip_all_tags((string) $outline_context['outline_notes']), 28);
            }
        }
        if ($seo_response_id !== '') {
            $outline_context['previous_response_id'] = $seo_response_id;
        }
        return array(
            'seo_article' => $seo_article,
            'outline_context' => $outline_context,
        );
    }

    public static function generate_content_article_stage($generator, $item, $seo_article, $outline_context = array())
    {
        $item = is_array($item) ? $item : array();
        $seo_article = is_array($seo_article) ? $seo_article : array();
        $outline_context = is_array($outline_context) ? $outline_context : array();
        $content_prompt = self::build_content_prompt($generator, $item, $seo_article, $outline_context);
        $content_response_schema = array(
            'type' => 'object',
            'properties' => array(
                'content_html' => array(
                    'type' => 'string',
                ),
            ),
            'required' => array('content_html'),
            'additionalProperties' => false,
        );
        $content_previous_response_id = !empty($outline_context['outline_response_id'])
            ? (string) $outline_context['outline_response_id']
            : (!empty($outline_context['previous_response_id']) ? (string) $outline_context['previous_response_id'] : '');
        $content_article = Content_Rank_Generator::request_openai_json($generator, $content_prompt, array(
            'stage' => 'content',
            'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
            'item_title' => !empty($item['source_title']) ? $item['source_title'] : '',
            'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
            'excerpt_length' => !empty($item['excerpt']) ? strlen((string) $item['excerpt']) : 0,
            'content_length' => !empty($item['content']) ? strlen((string) $item['content']) : 0,
            'source_context_enriched' => !empty($item['source_context_enriched']) ? 1 : 0,
            'previous_response_id' => $content_previous_response_id,
            'response_schema' => $content_response_schema,
            'response_schema_name' => 'content_rank_content_html',
            'response_schema_description' => 'Retornar somente o HTML do conteudo gerado.',
        ));
        if (is_wp_error($content_article)) {
            return $content_article;
        }

        if (!is_array($content_article) || trim((string) ($content_article['content_html'] ?? '')) === '') {
            return new WP_Error(
                'content_rank_content_response_invalid',
                'A resposta da OpenAI nao trouxe content_html valido para a etapa de conteudo.'
            );
        }

        return $content_article;
    }

    public static function call_openai($generator, $item)
    {
        $item = is_array($item) ? $item : array();
        $planning = self::prepare_generation_planning($generator, $item);
        if (is_wp_error($planning)) {
            return $planning;
        }

        $item = !empty($planning['item']) && is_array($planning['item']) ? $planning['item'] : (is_array($item) ? $item : array());
        $outline_context = !empty($planning['outline_context']) && is_array($planning['outline_context']) ? $planning['outline_context'] : array();
        if (!empty($item['review_products_prompt'])) {
            // Reviews de produtos usam o modelo review mesmo que a analise
            // geral encontre uma estrutura parecida com artigo ou lista.
            $outline_context['content_type'] = 'review';
            $outline_context['recommended_prompt_model_key'] = 'review';
            $outline_context['recommended_outline_model_key'] = 'guide_long';
            $outline_context['outline_model_key'] = 'guide_long';
        }
        $seo_stage = self::generate_seo_article_stage($generator, $item, $outline_context);
        if (is_wp_error($seo_stage)) {
            return $seo_stage;
        }

        $seo_article = !empty($seo_stage['seo_article']) && is_array($seo_stage['seo_article']) ? $seo_stage['seo_article'] : array();
        $outline_context = !empty($seo_stage['outline_context']) && is_array($seo_stage['outline_context']) ? $seo_stage['outline_context'] : $outline_context;
        // Temporarily skip the second AI outline pass. The source already has
        // the editorial structure; the content stage should rewrite it directly.
        $outline_context['outline_text'] = '';
        $outline_context['outline_sections'] = array();
        $outline_context['outline_response_id'] = '';
        self::build_source_outline_titles_for_prompt($item, 0, $generator);
        $content_article = self::generate_content_article_stage($generator, $item, $seo_article, $outline_context);
        if (is_wp_error($content_article)) {
            return $content_article;
        }

        $seo_article['content_html'] = !empty($content_article['content_html']) ? $content_article['content_html'] : (isset($seo_article['content_html']) ? $seo_article['content_html'] : '');
        if (!empty($seo_article['content_html'])) {
            $seo_article['content_html'] = self::strip_generated_image_markup_from_html($seo_article['content_html']);
        }
        if (empty($seo_article['excerpt']) && !empty($content_article['excerpt'])) {
            $seo_article['excerpt'] = $content_article['excerpt'];
        }
        if (!empty($outline_context) && is_array($outline_context)) {
            $seo_article['outline_context'] = $outline_context;
            $seo_article['outline_text'] = !empty($outline_context['outline_text']) ? $outline_context['outline_text'] : '';
            $seo_article['outline_sections'] = !empty($outline_context['outline_sections']) ? $outline_context['outline_sections'] : array();
            $seo_article['outline_target_h2_min'] = !empty($outline_context['outline_target_h2_min']) ? intval($outline_context['outline_target_h2_min']) : 0;
            $seo_article['outline_target_h2_max'] = !empty($outline_context['outline_target_h2_max']) ? intval($outline_context['outline_target_h2_max']) : 0;
            $seo_article['outline_target_h2_count'] = !empty($outline_context['outline_target_h2_count']) ? intval($outline_context['outline_target_h2_count']) : 0;
            $seo_article['outline_block_quantities'] = !empty($outline_context['outline_block_quantities']) ? $outline_context['outline_block_quantities'] : array();
        }

        return $seo_article;
    }

    public static function parse_internal_link_rules($rules)
    {
        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }

        if (!is_array($rules) || empty($rules)) {
            return array();
        }

        $normalized = array();
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $phrase = '';
            foreach (array('phrase', 'word', 'keyword', 'anchor_text') as $candidate_key) {
                if (!empty($rule[$candidate_key])) {
                    $phrase = sanitize_text_field((string) $rule[$candidate_key]);
                    break;
                }
            }
            $phrase = trim($phrase);

            $url = '';
            foreach (array('url', 'link', 'target_url') as $candidate_key) {
                if (!empty($rule[$candidate_key])) {
                    $url = esc_url_raw(trim((string) $rule[$candidate_key]));
                    break;
                }
            }

            if ($phrase === '' || $url === '') {
                continue;
            }

            $normalized[] = array(
                'quantity' => max(1, intval(isset($rule['quantity']) ? $rule['quantity'] : 1)),
                'phrase' => $phrase,
                'url' => $url,
                'target_blank' => !empty($rule['target_blank']) ? 1 : 0,
                'nofollow' => !empty($rule['nofollow']) ? 1 : 0,
                'sponsored' => !empty($rule['sponsored']) ? 1 : 0,
                'ugc' => !empty($rule['ugc']) ? 1 : 0,
            );
        }

        return array_values($normalized);
    }

    protected static function build_internal_link_match_pattern($phrase)
    {
        $phrase = trim((string) $phrase);
        if ($phrase === '') {
            return '';
        }

        return '~(?<![\p{L}\p{N}])(' . preg_quote($phrase, '~') . ')(?![\p{L}\p{N}])~iu';
    }

    protected static function normalize_internal_link_text($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\s+/u', ' ', $text);
        if (!is_string($text)) {
            $text = trim((string) $text);
        }

        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    protected static function build_internal_link_attributes($rule)
    {
        $rule = is_array($rule) ? $rule : array();
        $attrs = array(
            'href' => !empty($rule['url']) ? esc_url($rule['url']) : '',
        );
        if (!empty($rule['target_blank'])) {
            $attrs['target'] = '_blank';
        }

        $rel = array();
        if (!empty($rule['nofollow'])) {
            $rel[] = 'nofollow';
        }
        if (!empty($rule['sponsored'])) {
            $rel[] = 'sponsored';
        }
        if (!empty($rule['ugc'])) {
            $rel[] = 'ugc';
        }
        if (!empty($rule['target_blank'])) {
            $rel[] = 'noopener';
            $rel[] = 'noreferrer';
        }

        $rel = array_values(array_unique(array_filter($rel)));
        if (!empty($rel)) {
            $attrs['rel'] = implode(' ', $rel);
        }

        return $attrs;
    }

    protected static function apply_internal_link_rules_to_dom($dom, $xpath, $root, $rules, &$applied_count, $remaining_total_links = null, $skip_leading_paragraphs = 0)
    {
        $rules = is_array($rules) ? $rules : array();
        $skip_leading_paragraphs = max(0, intval($skip_leading_paragraphs));
        if (empty($rules) || !$xpath || !$root) {
            return;
        }

        $paragraph_filter = $skip_leading_paragraphs > 0
            ? '[count(preceding::p) >= ' . $skip_leading_paragraphs . ']'
            : '';
        $body_exclusion = ' and not(ancestor-or-self::a) and not(ancestor-or-self::h1) and not(ancestor-or-self::h2) and not(ancestor-or-self::h3) and not(ancestor-or-self::h4) and not(ancestor-or-self::h5) and not(ancestor-or-self::h6) and not(ancestor-or-self::script) and not(ancestor-or-self::style) and not(ancestor-or-self::pre) and not(ancestor-or-self::code)';
        $text_nodes_query = './/p' . $paragraph_filter . '//text()[normalize-space(.) != ""' . $body_exclusion . ']'
            . ' | .//li//text()[normalize-space(.) != ""' . $body_exclusion . ']'
            . ' | .//blockquote//text()[normalize-space(.) != ""' . $body_exclusion . ']'
            . ' | .//td//text()[normalize-space(.) != ""' . $body_exclusion . ']'
            . ' | .//th//text()[normalize-space(.) != ""' . $body_exclusion . ']'
            . ' | .//figcaption//text()[normalize-space(.) != ""' . $body_exclusion . ']'
            . ' | .//summary//text()[normalize-space(.) != ""' . $body_exclusion . ']';
        $fallback_elements_query = './/p' . $paragraph_filter . '[not(ancestor-or-self::a) and not(ancestor-or-self::h1) and not(ancestor-or-self::h2) and not(ancestor-or-self::h3) and not(ancestor-or-self::h4) and not(ancestor-or-self::h5) and not(ancestor-or-self::h6) and not(ancestor-or-self::script) and not(ancestor-or-self::style) and not(ancestor-or-self::pre) and not(ancestor-or-self::code)]'
            . ' | .//li[not(ancestor-or-self::a) and not(ancestor-or-self::h1) and not(ancestor-or-self::h2) and not(ancestor-or-self::h3) and not(ancestor-or-self::h4) and not(ancestor-or-self::h5) and not(ancestor-or-self::h6) and not(ancestor-or-self::script) and not(ancestor-or-self::style) and not(ancestor-or-self::pre) and not(ancestor-or-self::code)]'
            . ' | .//blockquote[not(ancestor-or-self::a) and not(ancestor-or-self::h1) and not(ancestor-or-self::h2) and not(ancestor-or-self::h3) and not(ancestor-or-self::h4) and not(ancestor-or-self::h5) and not(ancestor-or-self::h6) and not(ancestor-or-self::script) and not(ancestor-or-self::style) and not(ancestor-or-self::pre) and not(ancestor-or-self::code)]'
            . ' | .//td[not(ancestor-or-self::a) and not(ancestor-or-self::h1) and not(ancestor-or-self::h2) and not(ancestor-or-self::h3) and not(ancestor-or-self::h4) and not(ancestor-or-self::h5) and not(ancestor-or-self::h6) and not(ancestor-or-self::script) and not(ancestor-or-self::style) and not(ancestor-or-self::pre) and not(ancestor-or-self::code)]'
            . ' | .//th[not(ancestor-or-self::a) and not(ancestor-or-self::h1) and not(ancestor-or-self::h2) and not(ancestor-or-self::h3) and not(ancestor-or-self::h4) and not(ancestor-or-self::h5) and not(ancestor-or-self::h6) and not(ancestor-or-self::script) and not(ancestor-or-self::style) and not(ancestor-or-self::pre) and not(ancestor-or-self::code)]'
            . ' | .//figcaption[not(ancestor-or-self::a) and not(ancestor-or-self::h1) and not(ancestor-or-self::h2) and not(ancestor-or-self::h3) and not(ancestor-or-self::h4) and not(ancestor-or-self::h5) and not(ancestor-or-self::h6) and not(ancestor-or-self::script) and not(ancestor-or-self::style) and not(ancestor-or-self::pre) and not(ancestor-or-self::code)]'
            . ' | .//summary[not(ancestor-or-self::a) and not(ancestor-or-self::h1) and not(ancestor-or-self::h2) and not(ancestor-or-self::h3) and not(ancestor-or-self::h4) and not(ancestor-or-self::h5) and not(ancestor-or-self::h6) and not(ancestor-or-self::script) and not(ancestor-or-self::style) and not(ancestor-or-self::pre) and not(ancestor-or-self::code)]';

        foreach ($rules as $rule) {
            if ($remaining_total_links !== null && $remaining_total_links <= 0) {
                break;
            }

            $remaining = isset($rule['quantity']) ? max(1, intval($rule['quantity'])) : 1;
            $phrase = isset($rule['phrase']) ? (string) $rule['phrase'] : '';
            $pattern = self::build_internal_link_match_pattern($phrase);

            if ($pattern === '') {
                continue;
            }

            $text_nodes = $xpath->query($text_nodes_query, $root);
            if (!$text_nodes || $text_nodes->length === 0) {
                continue;
            }

            $nodes = array();
            for ($i = 0; $i < $text_nodes->length; $i++) {
                $nodes[] = $text_nodes->item($i);
            }
            $nodes = array_reverse($nodes);

            foreach ($nodes as $node) {
                if ($remaining <= 0 || !is_object($node) || !property_exists($node, 'nodeValue')) {
                    continue;
                }

                $node_text = (string) $node->nodeValue;
                if ($node_text === '') {
                    continue;
                }

                if (!preg_match_all($pattern, $node_text, $match_data, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $matches = array();
                if (!empty($match_data[1]) && is_array($match_data[1])) {
                    foreach ($match_data[1] as $match_item) {
                        if (!is_array($match_item) || !isset($match_item[0])) {
                            continue;
                        }
                        $matches[] = array(
                            'index' => count($matches),
                            'text' => (string) $match_item[0],
                            'offset' => isset($match_item[1]) ? intval($match_item[1]) : 0,
                        );
                    }
                }

                if (empty($matches)) {
                    continue;
                }

                $node_replacements = min($remaining, count($matches));
                if ($remaining_total_links !== null) {
                    $node_replacements = min($node_replacements, $remaining_total_links);
                }
                if ($node_replacements <= 0) {
                    continue;
                }

                $selected_matches = array_slice($matches, -$node_replacements);
                $selected_matches = array_reverse($selected_matches);

                $cursor = strlen($node_text);
                $chunks = array();

                foreach ($selected_matches as $match) {
                    $match_text = isset($match['text']) ? (string) $match['text'] : '';
                    $match_offset = isset($match['offset']) ? intval($match['offset']) : 0;
                    $match_length = strlen($match_text);
                    if ($match_text === '' || $match_length <= 0) {
                        continue;
                    }

                    $suffix = substr($node_text, $match_offset + $match_length, $cursor - ($match_offset + $match_length));
                    if ($suffix !== '') {
                        $chunks[] = esc_html($suffix);
                    }

                    $attrs = self::build_internal_link_attributes($rule);
                    $attr_parts = array();
                    foreach ($attrs as $attr_name => $attr_value) {
                        if ($attr_value === '') {
                            continue;
                        }
                        $attr_parts[] = $attr_name . '="' . esc_attr($attr_value) . '"';
                    }

                    $chunks[] = '<a ' . implode(' ', $attr_parts) . '>' . esc_html($match_text) . '</a>';
                    $cursor = $match_offset;
                }

                $prefix = substr($node_text, 0, $cursor);
                if ($prefix !== '') {
                    $chunks[] = esc_html($prefix);
                }

                if (empty($chunks)) {
                    continue;
                }

                $chunks = array_reverse($chunks);
                $new_html = implode('', $chunks);

                $fragment = $dom->createDocumentFragment();
                if (!$fragment->appendXML($new_html)) {
                    continue;
                }

                $node->parentNode->replaceChild($fragment, $node);
                $applied_count += $node_replacements;
                $remaining -= $node_replacements;
                if ($remaining_total_links !== null) {
                    $remaining_total_links -= $node_replacements;
                    if ($remaining_total_links <= 0) {
                        break 2;
                    }
                }
            }

            if ($remaining <= 0) {
                continue;
            }

            $fallback_elements = $xpath->query($fallback_elements_query, $root);
            if (!$fallback_elements || $fallback_elements->length === 0) {
                continue;
            }

            $fallback_nodes = array();
            for ($i = 0; $i < $fallback_elements->length; $i++) {
                $fallback_nodes[] = $fallback_elements->item($i);
            }
            $fallback_nodes = array_reverse($fallback_nodes);

            foreach ($fallback_nodes as $element) {
                if ($remaining <= 0 || !is_object($element) || !property_exists($element, 'textContent')) {
                    continue;
                }

                $element_text = (string) $element->textContent;
                if ($element_text === '') {
                    continue;
                }

                $pattern = self::build_internal_link_match_pattern($phrase);
                if ($pattern === '') {
                    continue;
                }

                if (!preg_match($pattern, $element_text, $match_data, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $match_text = !empty($match_data[1][0]) ? (string) $match_data[1][0] : '';
                $match_offset = !empty($match_data[1][1]) ? intval($match_data[1][1]) : -1;
                if ($match_text === '' || $match_offset < 0) {
                    continue;
                }

                $match_length = strlen($match_text);
                if ($match_length <= 0) {
                    continue;
                }

                $prefix = substr($element_text, 0, $match_offset);
                $suffix = substr($element_text, $match_offset + $match_length);
                $attrs = self::build_internal_link_attributes($rule);
                $attr_parts = array();
                foreach ($attrs as $attr_name => $attr_value) {
                    if ($attr_value === '') {
                        continue;
                    }
                    $attr_parts[] = $attr_name . '="' . esc_attr($attr_value) . '"';
                }

                $new_html = '';
                if ($prefix !== '') {
                    $new_html .= esc_html($prefix);
                }
                $new_html .= '<a ' . implode(' ', $attr_parts) . '>' . esc_html($match_text) . '</a>';
                if ($suffix !== '') {
                    $new_html .= esc_html($suffix);
                }

                while ($element->firstChild) {
                    $element->removeChild($element->firstChild);
                }

                $fragment = $dom->createDocumentFragment();
                if (!$fragment->appendXML($new_html)) {
                    continue;
                }

                $element->appendChild($fragment);
                $applied_count++;
                $remaining--;
                if ($remaining_total_links !== null) {
                    $remaining_total_links--;
                    if ($remaining_total_links <= 0) {
                        break 2;
                    }
                }
            }
        }
    }

    public static function apply_internal_links_to_content($content, $generator, $context = array())
    {
        $content = (string) $content;
        $generator = is_array($generator) ? $generator : array();
        $post_id = !empty($context['post_id']) ? intval($context['post_id']) : 0;
        $raw_rules = isset($generator['internal_links_json']) ? $generator['internal_links_json'] : '';
        $rules = self::parse_internal_link_rules($raw_rules);
        $global_rules = array();
        if (class_exists('Content_Rank_Generator')) {
            $settings = Content_Rank_Generator::get_settings();
            if (!empty($settings['global_internal_links_json'])) {
                $global_rules = self::parse_internal_link_rules($settings['global_internal_links_json']);
            }
        }
        $max_total_links = isset($generator['internal_links_count']) ? max(0, intval($generator['internal_links_count'])) : 0;
        $skip_leading_paragraphs = !empty($context['skip_leading_paragraphs'])
            ? max(0, intval($context['skip_leading_paragraphs']))
            : 0;

        if ($content === '') {
            return $content;
        }

        if (empty($rules) && empty($global_rules)) {
            return $content;
        }

        if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            return $content;
        }

        $previous_libxml_state = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><div id="content-rank-internal-links-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_libxml_state);

        if (!$loaded) {
            return $content;
        }

        $xpath = new DOMXPath($dom);
        $root = $dom->getElementById('content-rank-internal-links-root');
        if (!$root) {
            return $content;
        }

        $applied_count = 0;

        if ($max_total_links > 0 && !empty($rules)) {
            self::apply_internal_link_rules_to_dom($dom, $xpath, $root, $rules, $applied_count, $max_total_links, $skip_leading_paragraphs);
        }
        self::apply_internal_link_rules_to_dom($dom, $xpath, $root, $global_rules, $applied_count, null, 0);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return $output !== '' ? $output : $content;
    }

    public static function inject_content_plan_links_into_content($content, $links, $plan_type = 'pillar', $intro_label = '')
    {
        $content = (string) $content;
        $links = is_array($links) ? $links : array();
        $plan_type = sanitize_key((string) $plan_type);
        $intro_label = sanitize_text_field((string) $intro_label);

        if ($content === '' || empty($links)) {
            return $content;
        }

        $rules = array();
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }

            $url = '';
            foreach (array('url', 'link', 'target_url') as $candidate_key) {
                if (!empty($link[$candidate_key])) {
                    $url = esc_url_raw(trim((string) $link[$candidate_key]));
                    break;
                }
            }

            $phrase = '';
            foreach (array('anchor_phrase', 'phrase', 'title', 'anchor_text') as $candidate_key) {
                if (!empty($link[$candidate_key])) {
                    $phrase = self::clean_source_text((string) $link[$candidate_key]);
                    break;
                }
            }

            if ($url === '' || $phrase === '') {
                continue;
            }

            $rules[] = array(
                'quantity' => 1,
                'phrase' => $phrase,
                'url' => $url,
                'target_blank' => 0,
                'nofollow' => 0,
            );
        }

        if (empty($rules)) {
            return $content;
        }

        $generator = array(
            'internal_links_json' => wp_json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        $context = array(
            'plan_type' => $plan_type,
            'intro_label' => $intro_label,
            'skip_leading_paragraphs' => 2,
        );

        return self::apply_internal_links_to_content($content, $generator, $context);
    }

    public static function normalize_related_posts_settings($generator)
    {
        $position = isset($generator['related_posts_position']) ? sanitize_key((string) $generator['related_posts_position']) : 'end';
        if (!in_array($position, array('end', 'paragraphs', 'words'), true)) {
            $position = 'end';
        }

        $style = isset($generator['related_posts_style']) ? sanitize_key((string) $generator['related_posts_style']) : 'list';
        if (!in_array($style, array('inline', 'list', 'cards'), true)) {
            $style = 'list';
        }

        return array(
            'enabled' => !empty($generator['related_posts_enabled']),
            'position' => $position,
            'interval' => max(1, intval(isset($generator['related_posts_interval']) ? $generator['related_posts_interval'] : 4)),
            'min_h2' => max(0, intval(isset($generator['related_posts_min_h2']) ? $generator['related_posts_min_h2'] : 1)),
            'links_per_block' => max(1, intval(isset($generator['related_posts_links_per_block']) ? $generator['related_posts_links_per_block'] : 2)),
            'same_category_only' => !empty($generator['related_posts_same_category_only']),
            'allow_fallback' => !empty($generator['related_posts_allow_fallback']),
            'style' => $style,
            'phrases' => self::parse_related_posts_phrases(isset($generator['related_posts_phrases']) ? $generator['related_posts_phrases'] : ''),
        );
    }

    public static function parse_related_posts_phrases($phrases)
    {
        if (is_array($phrases)) {
            $phrases = implode("\n", $phrases);
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) $phrases);
        $items = array();
        foreach ((array) $lines as $line) {
            $line = sanitize_text_field(trim((string) $line));
            if ($line !== '') {
                $items[] = $line;
            }
        }

        $items = array_values(array_unique($items));
        if (empty($items)) {
            $items = preg_split('/\r\n|\r|\n/', Content_Rank_Generator::get_default_related_posts_phrases());
            $items = array_values(array_filter(array_map('sanitize_text_field', (array) $items)));
        }

        return $items;
    }

    protected static function get_related_posts_block_html($post, $style)
    {
        if (!($post instanceof WP_Post)) {
            return '';
        }

        $title = trim((string) get_the_title($post));
        $url = esc_url(get_permalink($post));
        if ($title === '' || $url === '') {
            return '';
        }

        $excerpt = self::get_related_post_excerpt_text($post, 16);

        $title_html = '<a class="content-rank-related-posts__link" href="' . $url . '">' . esc_html($title) . '</a>';

        if ($style === 'cards') {
            $card_html = '<a class="content-rank-related-posts__card" href="' . $url . '">';
            $card_html .= '<span class="content-rank-related-posts__card-title">' . esc_html($title) . '</span>';
            if ($excerpt !== '') {
                $card_html .= '<span class="content-rank-related-posts__card-excerpt">' . esc_html($excerpt) . '</span>';
            }
            $card_html .= '</a>';
            return $card_html;
        }

        return $title_html;
    }

    protected static function get_related_post_excerpt_text($post, $word_limit = 16)
    {
        if (!($post instanceof WP_Post)) {
            return '';
        }

        $excerpt = trim((string) get_post_field('post_excerpt', $post->ID, 'raw'));
        if ($excerpt === '') {
            $content = trim((string) get_post_field('post_content', $post->ID, 'raw'));
            if ($content !== '') {
                $content = strip_shortcodes($content);
                $content = wp_strip_all_tags($content);
                $excerpt = trim($content);
            }
        }

        if ($excerpt === '') {
            return '';
        }

        if ($word_limit > 0) {
            $excerpt = wp_trim_words($excerpt, $word_limit);
        }

        return $excerpt;
    }

    public static function build_related_posts_markup($post_id, $generator, $related_posts = array())
    {
        $settings = self::normalize_related_posts_settings($generator);
        $related_posts = array_values(array_filter($related_posts, function ($post) {
            return $post instanceof WP_Post;
        }));
        if (empty($related_posts)) {
            return '';
        }

        $phrases = !empty($settings['phrases']) ? $settings['phrases'] : array('VocÃƒÂª tambÃƒÂ©m pode gostar de:');
        $phrase = $phrases[array_rand($phrases)];
        $style = $settings['style'];

        $html = '<div class="content-rank-related-posts content-rank-related-posts--' . esc_attr($style) . '">';
        $html .= '<div class="content-rank-related-posts__phrase"><strong class="content-rank-related-posts__phrase-text">' . esc_html($phrase) . '</strong></div>';

        if ($style === 'inline') {
            $links = array();
            foreach ($related_posts as $post) {
                $item_html = self::get_related_posts_block_html($post, $style);
                if ($item_html !== '') {
                    $links[] = $item_html;
                }
            }
            if (empty($links)) {
                return '';
            }
            $html .= '<div class="content-rank-related-posts__inline-links">' . implode('<span class="content-rank-related-posts__separator">Ã¢â‚¬Â¢</span>', $links) . '</div>';
        } elseif ($style === 'cards') {
            $cards = array();
            foreach ($related_posts as $post) {
                $card_html = self::get_related_posts_block_html($post, $style);
                if ($card_html !== '') {
                    $cards[] = $card_html;
                }
            }
            if (empty($cards)) {
                return '';
            }
            $html .= '<div class="content-rank-related-posts__cards">' . implode('', $cards) . '</div>';
        } else {
            $items = array();
            foreach ($related_posts as $post) {
                $item_html = self::get_related_posts_block_html($post, $style);
                if ($item_html !== '') {
                    $items[] = '<li class="content-rank-related-posts__item">' . $item_html . '</li>';
                }
            }
            if (empty($items)) {
                return '';
            }
            $html .= '<ul class="content-rank-related-posts__list">' . implode('', $items) . '</ul>';
        }

        $html .= '</div>';
        return $html;
    }

    protected static function extract_block_html($block)
    {
        if (!is_array($block)) {
            return '';
        }
        if (!empty($block['innerHTML'])) {
            return (string) $block['innerHTML'];
        }
        if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
            return implode('', $block['innerContent']);
        }
        if (function_exists('serialize_block')) {
            return (string) serialize_block($block);
        }
        return '';
    }

    protected static function collect_related_posts_insertion_indices($blocks, $settings)
    {
        $blocks = is_array($blocks) ? $blocks : array();
        $position = isset($settings['position']) ? $settings['position'] : 'end';
        $min_h2 = isset($settings['min_h2']) ? max(0, intval($settings['min_h2'])) : 1;
        $interval = isset($settings['interval']) ? max(1, intval($settings['interval'])) : 4;
        $indices = array();
        $last_paragraph_index = -1;

        if ($position === 'end') {
            if (!empty($blocks)) {
                $indices[] = count($blocks) - 1;
            }
            return $indices;
        }

        $paragraph_count = 0;
        $word_count = 0;
        $heading_count = 0;

        foreach ($blocks as $index => $block) {
            $block_name = is_array($block) && !empty($block['blockName']) ? (string) $block['blockName'] : '';
            if ($block_name === 'core/heading') {
                $level = 2;
                if (isset($block['attrs']['level'])) {
                    $level = intval($block['attrs']['level']);
                }
                if ($level === 2) {
                    $heading_count++;
                }
            }

            if ($heading_count < $min_h2) {
                continue;
            }

            if ($position === 'paragraphs' && $block_name === 'core/paragraph') {
                $paragraph_count++;
                $last_paragraph_index = $index;
                if ($paragraph_count > 0 && ($paragraph_count % $interval) === 0) {
                    $indices[] = $index;
                }
                continue;
            }

            if ($position === 'words') {
                $block_html = self::extract_block_html($block);
                $plain_text = trim(wp_strip_all_tags(html_entity_decode((string) $block_html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'))));
                if ($plain_text === '') {
                    continue;
                }
                $word_count += str_word_count($plain_text);
                if ($word_count >= $interval) {
                    $indices[] = $index;
                    $word_count = 0;
                }
            }
        }

        if ($position === 'paragraphs' && $last_paragraph_index >= 0) {
            $indices[] = $last_paragraph_index;
        }

        if (empty($indices) && !empty($blocks)) {
            $indices[] = count($blocks) - 1;
        }

        return array_values(array_unique(array_filter(array_map('intval', $indices), function ($value) use ($blocks) {
            return $value >= 0 && $value < count($blocks);
        })));
    }

    public static function get_related_posts_candidates($post_id, $generator, $needed_total = 0)
    {
        $post_id = intval($post_id);
        $needed_total = max(1, intval($needed_total));
        $settings = self::normalize_related_posts_settings($generator);
        $post_type = !empty($generator['post_type']) && post_type_exists($generator['post_type']) ? $generator['post_type'] : get_post_type($post_id);
        if (!$post_type) {
            $post_type = 'post';
        }

        $category_ids = array();
        if (taxonomy_exists('category')) {
            $terms = wp_get_post_terms($post_id, 'category', array('fields' => 'ids'));
            if (!is_wp_error($terms) && is_array($terms)) {
                $category_ids = array_values(array_filter(array_map('intval', $terms)));
            }
        }

        $collect = static function ($posts, array &$results, array &$seen, $post_id, $needed_total) {
            foreach ((array) $posts as $post) {
                if (!($post instanceof WP_Post)) {
                    continue;
                }
                $candidate_id = intval($post->ID);
                if ($candidate_id <= 0 || $candidate_id === $post_id || isset($seen[$candidate_id])) {
                    continue;
                }
                $seen[$candidate_id] = true;
                $results[] = $post;
                if (count($results) >= $needed_total) {
                    return true;
                }
            }
            return false;
        };

        $results = array();
        $seen = array();
        $query_limit = max(12, $needed_total * 4);

        if (!empty($category_ids)) {
            $same_category_args = array(
                'post_type' => $post_type,
                'post_status' => 'publish',
                'posts_per_page' => $query_limit,
                'orderby' => 'rand',
                'ignore_sticky_posts' => true,
                'no_found_rows' => true,
                'post__not_in' => array($post_id),
                'tax_query' => array(
                    array(
                        'taxonomy' => 'category',
                        'field' => 'term_id',
                        'terms' => $category_ids,
                        'operator' => 'IN',
                    ),
                ),
            );
            $same_category_posts = get_posts($same_category_args);
            if ($collect($same_category_posts, $results, $seen, $post_id, $needed_total)) {
                return array_slice($results, 0, $needed_total);
            }
        }

        if (!empty($results)) {
            if ($settings['same_category_only']) {
                return array_slice($results, 0, $needed_total);
            }
            if (count($results) >= $needed_total) {
                return array_slice($results, 0, $needed_total);
            }
        }

        if (!$settings['allow_fallback'] && empty($results)) {
            return array();
        }

        if ($settings['allow_fallback']) {
            $fallback_args = array(
                'post_type' => $post_type,
                'post_status' => 'publish',
                'posts_per_page' => $query_limit,
                'orderby' => 'rand',
                'ignore_sticky_posts' => true,
                'no_found_rows' => true,
                'post__not_in' => array($post_id),
            );
            $fallback_posts = get_posts($fallback_args);
            $collect($fallback_posts, $results, $seen, $post_id, $needed_total);
        }

        return array_slice($results, 0, $needed_total);
    }

    public static function inject_related_posts_into_content($content, $post_id, $generator)
    {
        $settings = self::normalize_related_posts_settings($generator);
        if (empty($settings['enabled'])) {
            return $content;
        }

        $content = trim((string) $content);
        if ($content === '' || strpos($content, 'content-rank-related-posts') !== false) {
            return $content;
        }

        if (!function_exists('parse_blocks') || !function_exists('serialize_blocks')) {
            return $content;
        }

        $blocks = parse_blocks($content);
        if (empty($blocks) || !is_array($blocks)) {
            return $content;
        }

        $insertion_indices = self::collect_related_posts_insertion_indices($blocks, $settings);
        if (empty($insertion_indices)) {
            return $content;
        }

        $related_posts = self::get_related_posts_candidates($post_id, $generator, count($insertion_indices) * $settings['links_per_block']);
        if (empty($related_posts)) {
            return $content;
        }

        $result_blocks = array();
        $candidate_offset = 0;
        $insertion_lookup = array_fill_keys($insertion_indices, true);

        foreach ($blocks as $index => $block) {
            $result_blocks[] = $block;
            if (!isset($insertion_lookup[$index])) {
                continue;
            }

            $slice = array_slice($related_posts, $candidate_offset, $settings['links_per_block']);
            if (empty($slice)) {
                continue;
            }

            $html = self::build_related_posts_markup($post_id, $generator, $slice);
            if ($html === '') {
                continue;
            }

            $result_blocks[] = array(
                'blockName' => 'core/html',
                'attrs' => array(),
                'innerBlocks' => array(),
                'innerContent' => array($html),
            );
            $candidate_offset += count($slice);
        }

        if ($candidate_offset === 0) {
            return $content;
        }

        return serialize_blocks($result_blocks);
    }
}
