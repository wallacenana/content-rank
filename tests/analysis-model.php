<?php
/** Exercise the actual legacy generator class without booting WordPress or touching its database. */
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', dirname(__DIR__, 4) . '/');
require_once ABSPATH . 'wp-includes/formatting.php';

// Isolate the class from the plugin's top-level includes and activation hooks.
$tokens = token_get_all(file_get_contents(dirname(__DIR__) . '/content-rank.php'));
$class = '';
$capturing = false;
$depth = 0;
foreach ($tokens as $token) {
    if (!$capturing && is_array($token) && $token[0] === T_CLASS) {
        $capturing = true;
    }
    if (!$capturing) { continue; }
    $class .= is_array($token) ? $token[1] : $token;
    if ($token === '{' || (is_array($token) && in_array($token[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) { $depth++; }
    if ($token === '}' && --$depth === 0) { break; }
}
eval($class);
class WP_Error {}
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_option($name, $default = false) { return $name === 'content_rank_settings' ? $GLOBALS['settings'] : $default; }
function apply_filters($hook, $value) { return $value; }
function wp_json_encode($value) { return json_encode($value); }
function wp_is_valid_utf8($text) { return mb_check_encoding($text, 'UTF-8'); }
function is_utf8_charset($charset = null) { return true; }
function wp_remote_post($url, $args)
{
    $GLOBALS['request'] = array('url' => $url, 'args' => $args, 'body' => json_decode($args['body'], true));
    return new WP_Error(); // Stop before response normalization; no network traffic.
}
$settings = array('openai_api_key' => 'test-placeholder', 'default_model' => 'gpt-4.1', 'analysis_model' => 'gpt-4.1-mini');
$checks = 0;
function check($condition, $message)
{
    $GLOBALS['checks']++;
    if (!$condition) { throw new RuntimeException($message); }
}
foreach (array('outline', 'content_plan', 'link_suggestions', 'seo', 'content', 'content_outline', 'outline_media_match') as $stage) {
    Content_Rank_Generator::request_openai_json(array(), 'Test', array('stage' => $stage, 'skip_language_instruction' => 1));
    $analysis = in_array($stage, array('outline', 'content_plan', 'link_suggestions'), true);
    check($request['body']['model'] === ($analysis ? 'gpt-4.1-mini' : 'gpt-4.1'), 'Incorrect model for ' . $stage);
    check(isset($request['body']['prompt_cache_retention']) !== $analysis, 'Unsupported analysis cache setting');
    if ($stage === 'link_suggestions') {
        check($request['body']['max_completion_tokens'] === 1200, 'Link response budget is not bounded');
        check($request['args']['timeout'] === 60, 'Link request timeout is not bounded');
    }
}
$schema = array('type' => 'object', 'properties' => array(), 'required' => array(), 'additionalProperties' => false);
foreach (array('gpt-4.1-mini', 'gpt-5-mini') as $model) {
    $settings['analysis_model'] = $model;
    Content_Rank_Generator::request_openai_json(array(), 'Test', array('stage' => 'link_suggestions', 'skip_language_instruction' => 1, 'response_schema' => $schema));
    check($request['body']['model'] === $model, 'Analysis setting ignored');
    $format = $model === 'gpt-4.1-mini' ? $request['body']['response_format']['json_schema'] : $request['body']['text']['format'];
    check($format['schema'] === $schema && $format['strict'] === true, 'Structured output contract was lost');
}
$settings['analysis_model'] = 'gpt-4.1-mini';
$normalized = Content_Rank_Generator::sanitize_settings(array('analysis_model' => 'custom-analysis'));
check($normalized['analysis_model'] === 'custom-analysis', 'Settings not persisted');
$normalized = Content_Rank_Generator::sanitize_settings(array('analysis_model' => ''));
check($normalized['analysis_model'] === 'gpt-4.1-mini', 'Settings defaults failed');
echo "OK: {$checks} analysis-model checks; fake transport only.\n";
