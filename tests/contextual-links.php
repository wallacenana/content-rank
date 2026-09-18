<?php
/** Run: C:/xampp/php/php.exe tests/contextual-links.php (no database or API calls). */
if (PHP_SAPI !== 'cli') { exit; }
$test_log = tempnam(sys_get_temp_dir(), 'content-rank-trace-');
$previous_error_log = ini_set('error_log', $test_log);
register_shutdown_function(static function () use ($test_log, $previous_error_log) {
    ini_set('error_log', $previous_error_log);
    if (is_file($test_log)) { unlink($test_log); }
});
define('ABSPATH', dirname(__DIR__, 4) . '/');
require_once ABSPATH . 'wp-includes/formatting.php';
require_once ABSPATH . 'wp-includes/kses.php';

class WP_Post
{
    public $ID;
    public $post_title;
    public $post_content;
    public $post_status = 'publish';
    public $post_date = '2026-01-01 00:00:00';
    public $post_password = '';
    public $post_type = 'post';
    public function __construct($id, $title, $content = '')
    {
        $this->ID = $id;
        $this->post_title = $title;
        $this->post_content = $content;
    }
}
class WP_Error
{
    public $code;
    public function __construct($code, $message) { $this->code = $code; }
    public function get_error_message() { return $this->code; }
}
class Content_Rank_Generator
{
    public static $calls = 0;
    public static $response = array('links' => array());
    public static $response_queue = array();
    public static $last_context;
    public static $last_prompt;
    public static $on_request;
    public static function request_openai_json($generator, $prompt, $context)
    {
        self::$calls++;
        self::$last_context = $context;
        self::$last_prompt = $prompt;
        if (self::$on_request) { call_user_func(self::$on_request); }
        return self::$response_queue ? array_shift(self::$response_queue) : self::$response;
    }
}
function get_locale() { return 'pt_BR'; }
function wp_is_valid_utf8($text) { return mb_check_encoding($text, 'UTF-8'); }
function is_utf8_charset() { return true; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_field($field, $id) { return get_post($id)->$field; }
function get_the_title($id) { return get_post($id)->post_title ?? ''; }
function current_time($type) { return '2026-09-17 12:00:00'; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = stripslashes_deep($value); }
function delete_post_meta($id, $key) { unset($GLOBALS['meta'][$id][$key]); }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function home_url($path) { return ($GLOBALS['site_base'] ?? 'https://example.test') . $path; }
function get_permalink($id) { return home_url('/post-' . $id . '/'); }
function url_to_postid($url)
{
    $GLOBALS['resolved_urls'][] = $url;
    if (parse_url($url, PHP_URL_HOST) !== 'example.test') { return 0; }
    if (preg_match('~/post-(\d+)/?~', $url, $match)) { return intval($match[1]); }
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
    return intval($query['p'] ?? 0);
}
function wp_allowed_protocols() { return array('http', 'https'); }
function apply_filters($hook, $value) { return $value; }
function wp_load_alloptions() { return array(); }
function get_option($key, $default = false) { return $key === 'blog_charset' ? 'UTF-8' : $default; }
function wp_update_post($data, $error = false)
{
    if (!empty($GLOBALS['save_error'])) { return new WP_Error('save_failed', 'Save failed'); }
    $GLOBALS['posts'][$data['ID']]->post_content = stripslashes($data['post_content']);
    return $data['ID'];
}
function get_posts($args)
{
    $GLOBALS['queries'][] = $args;
    $found = array_filter($GLOBALS['posts'], static function ($post) use ($args) {
        return in_array($post->post_status, (array) $args['post_status'], true) && $post->post_password === '' && $post->post_type === 'post'
            && (!isset($args['meta_key']) || (string) get_post_meta($post->ID, $args['meta_key'], true) === $args['meta_value'])
            && !in_array($post->ID, $args['post__not_in'], true)
            && mb_stripos(remove_accents($post->post_title . ' ' . $post->post_content), $args['s']) !== false;
    });
    usort($found, static function ($left, $right) { return strcmp($right->post_date, $left->post_date) ?: ($right->ID <=> $left->ID); });
    return array_slice(array_values($found), 0, $args['posts_per_page']);
}
require_once dirname(__DIR__) . '/includes/contextual-links.php';
require_once dirname(__DIR__) . '/includes/link-suggestions.php';

$checks = 0;
function check($condition, $message)
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}
function selection($html, $links, $limit = 4)
{
    return array('content_hash' => hash('sha256', $html), 'requested_count' => $limit,
        'candidates' => array_map(static function ($post) { return array('id' => $post->ID, 'title' => $post->post_title); }, array_values($GLOBALS['posts'])),
        'suggestions' => $links);
}
function link_item($id = 1, $paragraph = 1, $anchor = 'os vingadores')
{
    return array('post_id' => $id, 'paragraph_id' => $paragraph, 'anchor' => $anchor);
}
$posts = array(1 => new WP_Post(1, 'Os Vingadores Doomsday'), 2 => new WP_Post(2, 'Ultron'), 3 => new WP_Post(3, 'Thanos'),
    4 => new WP_Post(4, 'Doomsday'), 5 => new WP_Post(5, 'Marvel'));
$meta = $queries = array();

// Exact replacement must retain serialized block comments, attributes, Unicode and entities.
foreach (array(
    '<!-- wp:paragraph {"x":"y"} --><p class="story">quando os vingadores lutaram &amp; venceram.</p><!-- /wp:paragraph -->',
    '<p>quando <strong>os vingadores</strong> lutaram.</p>',
    '<p>quando os <strong>vingadores</strong> lutaram.</p>',
    '<p>quando <strong>os</strong> vingadores lutaram.</p>',
    '<p>quando <strong><em>os</em></strong> vingadores lutaram.</p>',
    '<p data-note="x > y">quando os vingadores lutaram.</p>',
    '<p>quando os vingadores lutaram. Caminho C:\\temp\\arquivo</p>',
) as $html) {
    $result = Content_Rank_Contextual_Links::apply($html, selection($html, array(link_item())), 99);
    check($result['applied_count'] === 1, 'Valid formatted anchor was rejected: ' . $html);
    check(str_replace(array('<a href="https://example.test/post-1/">', '</a>'), '', $result['content_html']) === $html, 'Original markup changed');
    $dom = new DOMDocument();
    $dom->loadHTML($result['content_html']);
    check($dom->getElementsByTagName('a')->item(0)->textContent === 'os vingadores', 'Anchor includes extra words');
}
 $posts[6] = new WP_Post(6, 'Ação');
 $posts[7] = new WP_Post(7, 'Tom & Jerry');
foreach (array(array(6, 'ação'), array(7, 'Tom & Jerry'), array(1, 'Vingadores Doomsday')) as $item) {
    $target_id = $item[0];
    $anchor = $item[1];
    $html = '<p>' . htmlentities($anchor, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' continua.</p>';
    $result = Content_Rank_Contextual_Links::apply($html, selection($html, array(link_item($target_id, 1, $anchor))), 99);
    check($result['applied_count'] === 1, 'Unicode/entity anchor failed');
}

// Reject unsafe or ambiguous insertion; headings, comments, links and shortcodes stay untouched.
foreach (array(
    '<h2>os vingadores</h2>', '<pre><p>os vingadores</p></pre>',
    '<script>var t = "<p>os vingadores</p>";</script>', '<!-- <p>os vingadores</p> -->',
    '<p><a href="/outro/">os vingadores</a></p>', '<p>os vingadores [shortcode]</p>',
    '<p>os vingadores e os vingadores</p>', '<p>os vingadoresnovos</p>',
    '<p><strong>quando os</strong> vingadores lutaram.</p>',
) as $html) {
    $result = Content_Rank_Contextual_Links::apply($html, selection($html, array(link_item())), 99);
    check($result['content_html'] === $html && $result['applied_count'] === 0, 'Unsafe anchor accepted: ' . $html);
}
$html = '<p>os vingadores lutaram.</p>';
$plan = selection($html, array(link_item()));
check(Content_Rank_Contextual_Links::apply($html . 'changed', $plan, 99)['applied_count'] === 0, 'Stale content accepted');
check(Content_Rank_Contextual_Links::apply($html, $plan, 1)['applied_count'] === 0, 'Self link accepted');
$plan['candidates'] = array();
check(Content_Rank_Contextual_Links::apply($html, $plan, 99)['applied_count'] === 0, 'Unknown candidate accepted');
foreach (array('private', 'future', 'trash') as $status) {
    $posts[1]->post_status = $status;
    check(Content_Rank_Contextual_Links::apply($html, selection($html, array(link_item())), 99)['applied_count'] === 0, 'Nonpublic destination accepted');
}
$posts[1]->post_status = 'draft';
check(Content_Rank_Contextual_Links::apply($html, selection($html, array(link_item())), 99)['applied_count'] === 0, 'Draft destination accepted');
$posts[1]->post_status = 'publish';
$posts[1]->post_password = 'secret';
check(Content_Rank_Contextual_Links::apply($html, selection($html, array(link_item())), 99)['applied_count'] === 0, 'Protected destination accepted');
$posts[1]->post_password = '';
foreach (array('/post-1/', 'https://example.test/?p=1', 'https://example.test/post-1/#section') as $url) {
    $html = '<p>os vingadores lutaram.</p><p><a href="' . $url . '">Leia mais</a></p>';
    check(Content_Rank_Contextual_Links::apply($html, selection($html, array(link_item())), 99)['applied_count'] === 0, 'Duplicate destination accepted');
}
$html = '<p>os vingadores lutaram.</p><p>Ultron chegou.</p><p>Thanos apareceu.</p><p>Doomsday vem aí.</p><p>Marvel apresenta.</p>';
$links = array(link_item(), link_item(2, 2, 'Ultron'), link_item(3, 3, 'Thanos'), link_item(4, 4, 'Doomsday'), link_item(5, 5, 'Marvel'));
check(Content_Rank_Contextual_Links::apply($html, selection($html, $links, 25), 99)['applied_count'] === 4, 'Four-link cap failed');
check(Content_Rank_Contextual_Links::apply($html, selection($html, $links, 2), 99)['applied_count'] === 2, 'Requested cap failed');
$duplicates = array(link_item(), link_item(1, 2, 'Ultron'), link_item(2, 1, 'lutaram'));
check(Content_Rank_Contextual_Links::apply($html, selection($html, $duplicates), 99)['applied_count'] === 1, 'Duplicate post/paragraph accepted');
$site_base = 'https://example.test/alpha';
$html = '<p>os vingadores lutaram.</p><p><a href="/alpha/post-1/">Leia mais</a></p>';
$result = Content_Rank_Contextual_Links::apply($html, selection($html, array(link_item())), 99);
check($result['applied_count'] === 0 && end($resolved_urls) === 'https://example.test/alpha/post-1/', 'Subdirectory relative link resolution failed');
unset($site_base);
$html = '<p>os vingadores lutaram.</p>';
check(Content_Rank_Contextual_Links::apply($html, selection($html, array(link_item(1, 1, 'Os Vingadores'))), 99)['applied_count'] === 0, 'Nonliteral anchor accepted');
check(Content_Rank_Contextual_Links::apply('<p>um filme estreou.</p>', selection('<p>um filme estreou.</p>', array(link_item(1, 1, 'filme'))), 99)['applied_count'] === 0, 'Generic anchor accepted');

$posts[20] = new WP_Post(20, 'Outra notícia', 'os vingadores chegaram');
$ranked = Content_Rank_Contextual_Links::candidates(array('vingadores'), 99, '');
check($ranked[0]['id'] === 1, 'Title match did not outrank a newer body match');
check(count(array_filter($ranked, static function ($item) { return $item['id'] === 20; })) === 0, 'Body-only match became a candidate');
$split_ranked = Content_Rank_Contextual_Links::candidates(array('dooms'), 99, '');
check(count(array_filter($split_ranked, static function ($item) { return $item['id'] === 4; })) === 1, 'Split title term did not match Doomsday');
$posts[21] = new WP_Post(21, 'Spin-off de The Big Bang Theory ganha trailer', 'Neagley');
check(!Content_Rank_Contextual_Links::candidates(array('spin', 'off', 'trailer'), 99, ''), 'Generic spin-off/trailer term became a candidate');
unset($posts[21]);
$posts[22] = new WP_Post(22, 'O rosto por trás de Jim em Slow Horses na temporada 6');
$posts[22]->post_status = 'draft';
$draft_ranked = Content_Rank_Contextual_Links::candidates(array('slow horses'), 99, '');
check(count(array_filter($draft_ranked, static function ($item) { return $item['id'] === 22; })) === 0, 'Related draft offered as a candidate');
$posts[22]->post_status = 'publish';
$slow_html = '<p>O segredo de Jackson Lamb e revelado na estreia de Slow Horses.</p>';
$slow_plan = selection($slow_html, array(array(
    'post_id' => 22,
    'anchor' => 'o segredo de Jackson Lamb',
    'paragraph' => 'O segredo de Jackson Lamb e revelado na estreia de Slow Horses.',
)));
$slow_result = Content_Rank_Contextual_Links::apply($slow_html, $slow_plan, 99);
check($slow_result['applied_count'] === 0, 'Invalid AI anchor was replaced by a PHP fallback');
$specific_html = '<p>O segredo de Jackson Lamb e revelado na estreia de Jim em Slow Horses.</p>';
$specific_plan = selection($specific_html, array(array(
    'post_id' => 22,
    'anchor' => 'Jim em Slow Horses',
    'paragraph' => 'O segredo de Jackson Lamb e revelado na estreia de Jim em Slow Horses.',
)));
$specific_result = Content_Rank_Contextual_Links::apply($specific_html, $specific_plan, 99);
check($specific_result['applied_count'] === 1 && $specific_result['suggestions'][0]['anchor'] === 'Jim em Slow Horses', 'Specific title phrase was not linked');
$cast_html = '<p>Introducao da materia.</p><p>A temporada tambem apresenta novos personagens.</p>';
$cast_plan = selection($cast_html, array(array(
    'post_id' => 22,
    'operation' => 'insert_after',
    'paragraph_id' => 2,
    'paragraph' => 'A temporada tambem apresenta <a href="xxx">Jim, interpretado por Kyle Soller</a> em uma nova frente da historia.',
)));
$cast_plan['rewrite_contextual'] = 1;
$cast_result = Content_Rank_Contextual_Links::apply($cast_html, $cast_plan, 99);
check($cast_result['applied_count'] === 1 && strpos($cast_result['content_html'], 'Jim, interpretado por Kyle Soller') !== false, 'Descriptive cast anchor was rejected');
$season_html = '<p>Introducao da materia.</p><p>A temporada apresenta novos personagens.</p>';
$season_plan = selection($season_html, array(array(
    'post_id' => 22,
    'operation' => 'insert_after',
    'paragraph_id' => 2,
    'paragraph' => 'O elenco da temporada 6 também inclui Kyle Soller como Jim, antagonista em <a href="xxx">Slow Horses temporada 6</a>.',
)));
$season_plan['rewrite_contextual'] = 1;
$season_result = Content_Rank_Contextual_Links::apply($season_html, $season_plan, 99);
check($season_result['applied_count'] === 0, 'Broad season anchor was accepted for a character destination');
$actor_plan = $cast_plan;
$actor_plan['suggestions'][0]['paragraph'] = 'O elenco também inclui <a href="xxx">Kyle Soller como Jim</a>, antagonista em Slow Horses.';
$actor_result = Content_Rank_Contextual_Links::apply($cast_html, $actor_plan, 99);
check($actor_result['applied_count'] === 1, 'Actor-as-character anchor was rejected');
$bridge_plan = $cast_plan;
$bridge_plan['suggestions'][0]['paragraph'] = 'O elenco da <a href="xxx">temporada 6 também inclui Kyle Soller como Jim</a>, antagonista em Slow Horses.';
$bridge_result = Content_Rank_Contextual_Links::apply($cast_html, $bridge_plan, 99);
check($bridge_result['applied_count'] === 1, 'Full factual cast bridge anchor was rejected');
$rewrite_html = '<p>Introducao da materia.</p><p>O segredo de Jackson Lamb e revelado na estreia de Slow Horses.</p>';
$rewrite_plan = selection($rewrite_html, array(array(
    'post_id' => 22,
    'operation' => 'replace',
    'anchor' => 'Jim em Slow Horses',
    'paragraph_id' => 2,
    'paragraph' => 'O segredo de Jackson Lamb e revelado na estreia de Slow Horses.',
    'replacement' => 'O segredo de Jackson Lamb e revelado na estreia de Slow Horses. A temporada tambem apresenta Jim em Slow Horses, interpretado por Kyle Soller.',
)));
$rewrite_plan['rewrite_contextual'] = 1;
$rewrite_result = Content_Rank_Contextual_Links::apply($rewrite_html, $rewrite_plan, 99);
check($rewrite_result['applied_count'] === 1 && strpos($rewrite_result['content_html'], 'Jim em Slow Horses') !== false && strpos($rewrite_result['content_html'], '<a href="https://example.test/post-22/">Jim em Slow Horses</a>') !== false, 'Contextual replacement was not safely linked');
$markup_plan = selection($rewrite_html, array(array(
    'post_id' => 22,
    'operation' => 'replace',
    'paragraph_id' => 2,
    'paragraph' => 'O segredo de Jackson Lamb e revelado na estreia de <a href="xxx">Jim em Slow Horses</a>. A temporada tambem destaca o personagem.',
)));
$markup_plan['rewrite_contextual'] = 1;
$markup_result = Content_Rank_Contextual_Links::apply($rewrite_html, $markup_plan, 99);
check($markup_result['applied_count'] === 1 && strpos($markup_result['content_html'], '<a href="https://example.test/post-22/">Jim em Slow Horses</a>') !== false, 'Contextual paragraph markup was not linked');
$insert_html = '<p>Introducao da materia.</p><p>O contexto da serie sera explicado nesta parte.</p>';
$insert_plan = selection($insert_html, array(array(
    'post_id' => 22,
    'operation' => 'insert_after',
    'paragraph_id' => 2,
    'paragraph' => 'A nova materia tambem explica <a href="xxx">Jim em Slow Horses</a> com mais detalhes.',
)));
$insert_plan['rewrite_contextual'] = 1;
$insert_result = Content_Rank_Contextual_Links::apply($insert_html, $insert_plan, 99);
check($insert_result['applied_count'] === 1 && strpos($insert_result['content_html'], '<p>A nova materia tambem explica <a href="https://example.test/post-22/">Jim em Slow Horses</a> com mais detalhes.</p>') !== false, 'Contextual paragraph insertion was not linked');
$gutenberg_html = '<!-- wp:paragraph --><p>Introducao da materia.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>O contexto da serie sera explicado nesta parte.</p><!-- /wp:paragraph -->';
$gutenberg_plan = selection($gutenberg_html, array(array(
    'post_id' => 22,
    'operation' => 'insert_after',
    'paragraph_id' => 2,
    'paragraph' => 'A nova materia tambem explica <a href="xxx">Jim em Slow Horses</a> com mais detalhes.',
)));
$gutenberg_plan['rewrite_contextual'] = 1;
$gutenberg_result = Content_Rank_Contextual_Links::apply($gutenberg_html, $gutenberg_plan, 99);
check($gutenberg_result['applied_count'] === 1
    && strpos($gutenberg_result['content_html'], "</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>A nova materia tambem explica <a href=\"https://example.test/post-22/\">Jim em Slow Horses</a> com mais detalhes.</p>\n<!-- /wp:paragraph -->") !== false
    && substr_count($gutenberg_result['content_html'], '<!-- wp:paragraph -->') === 3,
    'Gutenberg contextual insertion was not saved as a sibling block');
$intro_plan = $insert_plan;
$intro_plan['suggestions'][0]['paragraph_id'] = 1;
check(Content_Rank_Contextual_Links::apply($insert_html, $intro_plan, 99)['applied_count'] === 0, 'Contextual link was inserted in the introduction');
$generic_rewrite_plan = $rewrite_plan;
$generic_rewrite_plan['suggestions'][0]['anchor'] = 'Slow Horses';
$generic_rewrite_plan['suggestions'][0]['replacement'] = 'Slow Horses vinha mantendo essa verdade oculta desde a primeira temporada.';
check(Content_Rank_Contextual_Links::apply($rewrite_html, $generic_rewrite_plan, 99)['applied_count'] === 0, 'Generic contextual rewrite was accepted');
unset($posts[22]);
unset($posts[20]);
$terms = Content_Rank_Contextual_Links::search_terms(array('focus_keyword' => 'Os Vingadores Doomsday', 'title' => 'Veja tudo sobre os melhores filmes'));
check(in_array('vingadores', $terms, true) && !in_array('sobre', $terms, true) && count($terms) <= 8, 'Search terms are not bounded or specific');
$editorial_terms = Content_Rank_Contextual_Links::search_terms(array('focus_keyword' => 'spin-off Neagley'));
check(in_array('neagley', $editorial_terms, true) && !in_array('spin', $editorial_terms, true) && !in_array('off', $editorial_terms, true), 'Editorial spin-off term was not filtered');
$season_terms = Content_Rank_Contextual_Links::search_terms(array('focus_keyword' => 'Slow Horses Season 6'));
check(in_array('slow', $season_terms, true) && !in_array('season', $season_terms, true), 'English season term was not filtered');

// Real planning service with fake transport: no candidates = no request, otherwise one call.
$before = Content_Rank_Generator::$calls;
$plan = Content_Rank_Contextual_Links::plan('<p>Assunto desconhecido.</p>', array(), 99, array('focus_keyword' => 'inexistente'));
check(!$plan['suggestions'] && Content_Rank_Generator::$calls === $before, 'No-candidate case called AI');
Content_Rank_Generator::$response = array('links' => array(
    array('post_id' => 1, 'anchor' => 'os vingadores', 'paragraph' => 'quando os vingadores lutaram.'),
    array('post_id' => 999, 'anchor' => 'lutaram', 'paragraph' => 'quando os vingadores lutaram.'),
    array('post_id' => 1),
));
$html = '<p>quando os vingadores lutaram.</p>';
$plan = Content_Rank_Contextual_Links::plan($html, array(), 99, array('focus_keyword' => 'os vingadores doomsday'));
check(Content_Rank_Generator::$calls === $before + 1 && count($plan['suggestions']) === 1, 'Plan did not validate one AI response');
check($plan['suggestions'][0]['paragraph'] === 'quando os vingadores lutaram.' && $plan['suggestions'][0]['anchor'] === 'os vingadores', 'Paragraph/anchor contract was not preserved');
check(Content_Rank_Generator::$last_context['stage'] === 'link_suggestions', 'Wrong analysis stage');
check(Content_Rank_Generator::$last_context['response_schema']['additionalProperties'] === false, 'Missing strict schema');
check(strpos(Content_Rank_Generator::$last_prompt, 'post_id, anchor, paragraph') !== false && strpos(Content_Rank_Generator::$last_prompt, '<p>') === false, 'Prompt contains raw HTML or wrong output shape');
check(count($queries) <= 32, 'Search queries unbounded');
Content_Rank_Generator::$response = array('links' => array());
check(!Content_Rank_Contextual_Links::plan($html, array(), 99, array('title' => 'Vingadores'))['suggestions'], 'Empty AI selection filled artificially');
Content_Rank_Generator::$response = new WP_Error('api_failure', 'API failed');
check(is_wp_error(Content_Rank_Contextual_Links::plan($html, array(), 99, array('title' => 'Vingadores'))), 'API error lost');

// Persistence: actual inserted counts, escaped content, stale edits and failed saves.
$posts[99] = new WP_Post(99, 'Vingadores', '<p>os vingadores lutaram. C:\\temp\\x</p>');
Content_Rank_Generator::$response = array('links' => array(link_item()));
$result = Content_Rank_Link_Suggestions::generate_and_apply_link_suggestions_to_post(99);
check(!is_wp_error($result) && $result['applied_count'] === 1, 'Automatic apply failed');
check(strpos($posts[99]->post_content, 'C:\\temp\\x') !== false, 'Save stripped backslashes');
check(get_post_meta(99, '_content_rank_link_suggestions_applied_count') === 1, 'Incorrect saved count');
$saved_content = $posts[99]->post_content;
$result = Content_Rank_Link_Suggestions::generate_and_apply_link_suggestions_to_post(99);
check($result['applied_count'] === 0 && $posts[99]->post_content === $saved_content, 'Second execution duplicated links');
$posts[99]->post_content = '<p>os vingadores lutaram.</p>';
Content_Rank_Generator::$on_request = static function () { $GLOBALS['posts'][99]->post_content .= '<p>Edição humana.</p>'; };
$result = Content_Rank_Link_Suggestions::generate_and_apply_link_suggestions_to_post(99);
check(is_wp_error($result) && strpos($posts[99]->post_content, 'Edição humana') !== false, 'Concurrent edit overwritten');
Content_Rank_Generator::$on_request = null;
$posts[99]->post_content = '<p>os vingadores lutaram.</p>';
$save_error = true;
$result = Content_Rank_Link_Suggestions::generate_and_apply_link_suggestions_to_post(99);
check(is_wp_error($result) && get_post_meta(99, '_content_rank_link_suggestions_applied_count') === '', 'Failed save marked as applied');
// Regression: the preview inside plan() must survive serialization and the second
// apply() performed by the automatic save path. Use the reported AI response.
$save_error = false;
$posts[6891] = new WP_Post(6891, 'O rosto por trás de Jim em Slow Horses na temporada 6', 'Jim é interpretado por Kyle Soller.');
$source = '<p>Jackson Lamb em Slow Horses temporada 6 tem seu maior segredo revelado.</p>'
    . '<p>Ao longo das cinco temporadas, a morte de Partner pairou sobre a narrativa.</p>'
    . '<p>Diana Taverner conta a verdade no funeral de David Cartwright.</p>'
    . '<p>A série havia mostrado a morte de Partner em flashback.</p>'
    . '<p>A divulgação por Taverner altera a dinâmica de poder.</p>'
    . '<p>Além disso, a narrativa liga a traição de Partner à possível tragédia pessoal de Lamb, já que em temporadas anteriores foi revelado que Lamb foi capturado e torturado pelos Stasi, e que sua companheira grávida foi morta. A série sugere que as ações de Partner podem ter contribuído para esse desfecho.</p>'
    . '<p>Com a revelação, a relação entre Lamb e Standish fica alterada.</p>'
    . '<p>A temporada seguirá com impactos profundos nas alianças.</p>';
$proposed = 'Além disso, o antagonista Jim, interpretado por Kyle Soller na temporada 6, adiciona uma nova camada de tensão à trama, complementando os conflitos pessoais de Lamb e a dinâmica do MI5. Saiba mais sobre <a href="xxx">O rosto por trás de Jim em Slow Horses na temporada 6</a>.';
foreach (array('insert_after', 'replace') as $operation) {
    $posts[6956] = new WP_Post(6956, 'Jackson Lamb em Slow Horses temporada 6', $source);
    $paragraph = $operation === 'insert_after' ? $proposed
        : Content_Rank_Contextual_Links::paragraphs($source)[6]['text'] . ' ' . $proposed;
    Content_Rank_Generator::$response = array('links' => array(array(
        'post_id' => 6891, 'operation' => $operation, 'paragraph_id' => 6, 'paragraph' => $paragraph,
    )));
    $result = Content_Rank_Link_Suggestions::generate_and_apply_link_suggestions_to_post(6956, array(), 4, '', '', array(), true);
    check(!is_wp_error($result) && $result['applied_count'] === 1, 'Contextual ' . $operation . ' lost between preview and save');
    $resolved = str_replace('href="xxx"', 'href="https://example.test/post-6891/"', $paragraph);
    $original = '<p>' . Content_Rank_Contextual_Links::paragraphs($source)[6]['text'] . '</p>';
    $expected = str_replace($original, ($operation === 'insert_after' ? $original . "\n" : '') . '<p>' . $resolved . '</p>', $source);
    check($posts[6956]->post_content === $expected, 'Saved content differs from the requested contextual edit');
    $stored = json_decode(get_post_meta(6956, '_content_rank_link_suggestions_json'), true);
    check(is_array($stored), 'Contextual plan was not serialized');
    check(Content_Rank_Contextual_Links::apply($source, $stored, 6956)['content_html'] === $expected, 'Stored contextual plan cannot be replayed');
    // Recover plans already stored by the faulty version, like post 6956.
    $legacy = $stored;
    $legacy['suggestions'][0]['paragraph'] = Content_Rank_Contextual_Links::paragraphs($source)[6]['text'];
    $legacy['suggestions'][0]['replacement'] = $paragraph;
    check(Content_Rank_Contextual_Links::apply($source, $legacy, 6956)['content_html'] === $expected, 'Legacy saved contextual plan lost its operation');
    check(Content_Rank_Contextual_Links::apply($expected, $stored, 6956)['applied_count'] === 0, 'Reapplying a saved contextual edit duplicated the link');
    check(get_post_meta(6956, '_content_rank_link_suggestions_applied_count') === 1, 'Contextual applied count not persisted');
}
// Editorial context must retain heading boundaries and readable destination text.
$sectioned = '<h2>Introdução</h2><p>A estreia revela um segredo.</p>'
    . '<h2>Elenco &amp; <em>personagens</em></h2><p>Kyle Soller interpreta Jim em Slow Horses.</p>'
    . '<h2>Fechamento: o que muda para Lamb</h2><p>A revelação afeta a relação com Standish.</p>';
$sections = Content_Rank_Contextual_Links::paragraphs($sectioned);
check($sections[2]['section'] === 'Elenco & personagens' && $sections[3]['section'] === 'Fechamento: o que muda para Lamb', 'Section context missing or mixed into paragraph text');
check($sections[2]['text'] === 'Kyle Soller interpreta Jim em Slow Horses.', 'Heading changed paragraph text or numbering');
$posts[6956]->post_content = $sectioned;
$posts[6891]->post_content = '<p>Jim é interpretado por Kyle Soller.</p><p>A temporada também apresenta Jane.</p>';
Content_Rank_Generator::$response = array('links' => array(array(
    'post_id' => 6891, 'operation' => 'replace', 'paragraph_id' => 2,
    'paragraph' => 'Kyle Soller interpreta <a href="xxx">Jim em Slow Horses</a>.',
)));
$result = Content_Rank_Link_Suggestions::generate_and_apply_link_suggestions_to_post(6956, array(), 4, '', '', array(), true);
check($result['applied_count'] === 1 && strpos($posts[6956]->post_content, '<h2>Fechamento: o que muda para Lamb</h2><p>A revelação afeta a relação com Standish.</p>') !== false, 'Body edit altered the closing section');
$prompt = Content_Rank_Generator::$last_prompt;
check(strpos($prompt, 'Título do artigo atual: "Jackson Lamb em Slow Horses temporada 6"') !== false
    && strpos($prompt, '"section":"Fechamento: o que muda para Lamb"') !== false, 'Article title and section context not delivered to the model');
check(strpos($prompt, 'Kyle Soller. A temporada') !== false && strpos($prompt, 'Kyle Soller.A temporada') === false, 'Candidate excerpt concatenated sentences across paragraphs');
check(strpos($prompt, 'paragraph_id é o parágrafo existente que deve ser reescrito') !== false
    && strpos($prompt, 'Prefira sempre operation=replace') !== false,
    'Contextual placement guidance was not sent to the model');
// The model can satisfy the JSON shape while omitting the required inline link.
// Repair once, then follow the real planning/persistence path without guessing an anchor.
$broken = array('post_id' => 6891, 'operation' => 'insert_after', 'paragraph_id' => 3,
    'paragraph' => 'O ator Kyle Soller, que interpreta o antagonista Jim na temporada 6, contribui para a tensão crescente entre os personagens, especialmente em cenas que envolvem a revelação sobre Lamb.');
$fixed = array('post_id' => 6891, 'operation' => 'insert_after', 'paragraph_id' => 3,
    'paragraph' => 'Na mesma temporada, Kyle Soller interpreta <a href="xxx">Jim em Slow Horses</a>.');
$posts[6965] = new WP_Post(6965, 'Segredo de Jackson Lamb em Slow Horses', $source);
$broken_plan = selection($source, array($broken));
$broken_plan['rewrite_contextual'] = 1;
$rejected = Content_Rank_Contextual_Links::apply($source, $broken_plan, 6965);
check($rejected['applied_count'] === 0 && $rejected['content_html'] === $source, 'PHP guessed a link for unmarked text');
check($rejected['rejections'][0]['reason'] === 'missing_link_markup', 'Missing-link rejection was not diagnosed');
$before = Content_Rank_Generator::$calls;
Content_Rank_Generator::$response_queue = array(array('links' => array($broken)), array('links' => array($fixed)));
$result = Content_Rank_Link_Suggestions::generate_and_apply_link_suggestions_to_post(6965, array(), 4, '', '', array(), true);
check(Content_Rank_Generator::$calls === $before + 2 && $result['applied_count'] === 1, 'Missing link was not repaired and saved');
check(strpos($posts[6965]->post_content, '<a href="https://example.test/post-6891/">Jim em Slow Horses</a>') !== false
    && strpos($posts[6965]->post_content, 'especialmente em cenas') === false, 'Uncorrected sentence saved instead of repaired proposal');
check(Content_Rank_Generator::$last_context['stage'] === 'link_suggestions'
    && strpos(Content_Rank_Generator::$last_prompt, 'missing_link_markup') !== false, 'Repair lost analysis stage or diagnostic');
check(get_post_meta(6965, '_content_rank_link_suggestions_applied_count') === 1, 'Repair count not persisted');

foreach (array('still_invalid', 'empty', 'api_error') as $failure) {
    $posts[6965]->post_content = $source;
    $second = $failure === 'still_invalid' ? array('links' => array($broken))
        : ($failure === 'empty' ? array('links' => array()) : new WP_Error('repair_failed', 'Repair failed'));
    Content_Rank_Generator::$response_queue = array(array('links' => array($broken)), $second);
    $before = Content_Rank_Generator::$calls;
    $result = Content_Rank_Link_Suggestions::generate_and_apply_link_suggestions_to_post(6965, array(), 4, '', '', array(), true);
    check(Content_Rank_Generator::$calls === $before + 2, 'Repair loop or missing retry: ' . $failure);
    check($result['applied_count'] === 0 && $posts[6965]->post_content === $source, 'Failed repair changed content: ' . $failure);
    $stored = json_decode(get_post_meta(6965, '_content_rank_link_suggestions_json'), true);
    if ($failure === 'still_invalid') {
        check($stored['rejections'][0]['reason'] === 'missing_link_markup', 'Final rejection not saved for inspection');
    }
}
// An empty initial selection is intentional, not a malformed response.
Content_Rank_Generator::$response = array('links' => array());
$before = Content_Rank_Generator::$calls;
$result = Content_Rank_Link_Suggestions::generate_and_apply_link_suggestions_to_post(6965, array(), 4, '', '', array(), true);
check(Content_Rank_Generator::$calls === $before + 1 && $result['applied_count'] === 0, 'Empty selection caused an unnecessary repair');

// Preserve valid edits when another suggestion fails its one repair attempt.
$posts[6892] = new WP_Post(6892, 'Gary Oldman como Jackson Lamb em Slow Horses');
$valid = array('post_id' => 6892, 'operation' => 'insert_after', 'paragraph_id' => 4,
    'paragraph' => 'Gary Oldman interpreta <a href="xxx">Jackson Lamb em Slow Horses</a>.');
Content_Rank_Generator::$response_queue = array(array('links' => array($valid, $broken)), new WP_Error('repair_failed', 'Repair failed'));
$result = Content_Rank_Link_Suggestions::generate_and_apply_link_suggestions_to_post(6965, array(), 4, '', '', array(), true);
check($result['applied_count'] === 1 && strpos($posts[6965]->post_content, 'https://example.test/post-6892/') !== false, 'Repair failure discarded an already valid link');
// Reproduce the user's rejected introduction without changing editorial rules.
$posts[6969] = new WP_Post(6969, 'Estreia da temporada 6 de Slow Horses revela segredo de Jackson Lamb', $source);
Content_Rank_Generator::$response = array('links' => array(array('post_id' => 6891, 'operation' => 'insert_after', 'paragraph_id' => 1,
    'paragraph' => 'Além disso, a estreia apresenta <a href="xxx">Kyle Soller como Jim, um antagonista implacável</a>, que adiciona tensão à trama envolvendo Lamb.',
)));
$before = Content_Rank_Generator::$calls;
$result = Content_Rank_Link_Suggestions::generate_and_apply_link_suggestions_to_post(6969, array(), 4, '', '', array(), true);
check($result['applied_count'] === 0 && $posts[6969]->post_content === $source, 'Tracing changed introduction protection');
check(Content_Rank_Generator::$calls === $before + 1, 'Tracing changed repair policy');
check($result['planning_rejections'][0]['reason'] === 'introduction_blocked', 'Planning rejection disappeared in save result');
check(strpos((string) file_get_contents($test_log), '[Content Rank][links-trace]') === false, 'Internal link diagnostics are still being logged');
$saved_posts = $posts;
$posts = array();
for ($i = 1; $i <= 110; $i++) {
    $posts[$i] = new WP_Post($i, 'Slow Horses noticia ' . $i);
    $posts[$i]->post_date = gmdate('Y-m-d H:i:s', strtotime('2025-01-01') + (111 - $i) * 86400);
}
$ranked = Content_Rank_Contextual_Links::candidates(array('slow horses'), 999, '');
check(array_column($ranked, 'id') === array(1, 2, 3, 4, 5), 'Candidates must be the five newest by publication date, not ID');
$meta[110]['_yoast_wpseo_is_cornerstone'] = '1';
$ranked = Content_Rank_Contextual_Links::candidates(array('slow horses'), 999, '');
check(array_column($ranked, 'id') === array(110, 1, 2, 3, 4), 'Old cornerstone outside recent query window lost priority');
$posts[110]->post_status = 'draft';
$posts[1]->post_status = 'future';
$ranked = Content_Rank_Contextual_Links::candidates(array('slow horses'), 999, '');
check(array_column($ranked, 'id') === array(2, 3, 4, 5, 6), 'Unpublished cornerstone or scheduled post became a candidate');
$posts = $saved_posts;
echo "OK: {$checks} checks; no database or network calls.\n";
