<?php

function llmstats_default_cfg()
{
    $defaults = @parse_ini_file('/usr/local/emhttp/plugins/llmstats/default.cfg');

    return is_array($defaults) ? $defaults : [];
}

function llmstats_read_cfg($plugin_name = 'llmstats')
{
    $cfg = parse_plugin_cfg($plugin_name);

    return is_array($cfg) ? $cfg : [];
}

function llmstats_json_options()
{
    $options = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $options |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    return $options;
}

function llmstats_t($text)
{
    return function_exists('_') ? _($text) : $text;
}

function llmstats_csrf_token()
{
    global $var;

    if (is_array($var) && (string)($var['csrf_token'] ?? '') !== '') {
        return (string)$var['csrf_token'];
    }

    $emhttp_var = @parse_ini_file('/var/local/emhttp/var.ini');
    if (is_array($emhttp_var) && (string)($emhttp_var['csrf_token'] ?? '') !== '') {
        return (string)$emhttp_var['csrf_token'];
    }

    return '';
}

function llmstats_request_host($url)
{
    $host = parse_url((string)$url, PHP_URL_HOST);
    if (!is_string($host)) {
        return '';
    }

    // parse_url() keeps IPv6 brackets; strip them to match llmstats_header_host().
    return strtolower(trim($host, '[]'));
}

function llmstats_header_host($host)
{
    $host = strtolower(trim((string)$host));
    if ($host === '') {
        return '';
    }
    if ($host[0] === '[') {
        $end = strpos($host, ']');
        return $end === false ? $host : substr($host, 1, $end - 1);
    }

    return preg_replace('/:\d+$/', '', $host);
}

function llmstats_is_same_origin_ajax()
{
    $requested_with = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if ($requested_with !== 'xmlhttprequest') {
        return false;
    }

    $host = llmstats_header_host($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return false;
    }

    $origin = llmstats_request_host($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        return $origin === $host;
    }

    // Without an Origin header, require a matching Referer; accepting requests
    // with neither would let header-stripped cross-site requests through.
    $referer = llmstats_request_host($_SERVER['HTTP_REFERER'] ?? '');
    return $referer !== '' && $referer === $host;
}

function llmstats_theme_name()
{
    global $display;
    // Unraid's ThemeHelper resolves names like "black-sidebar" the same way.
    $theme = strtok((string)($display['theme'] ?? ''), '-');
    return is_string($theme) && $theme !== '' ? strtolower($theme) : 'black';
}

function llmstats_theme_is_light()
{
    return in_array(llmstats_theme_name(), ['white', 'azure'], true);
}

function llmstats_server_types()
{
    return ['auto', 'ollama', 'llama-server', 'llama-swap'];
}

function llmstats_model_fields()
{
    return [
        'quant' => 'Quantization',
        'memory' => 'Memory',
        'busy' => 'Busy/idle state',
        'actions' => 'Load/unload'
    ];
}

function llmstats_default_model_fields()
{
    return ['busy'];
}

function llmstats_normalize_model_fields($fields)
{
    if (!is_array($fields)) {
        return llmstats_default_model_fields();
    }

    $known = array_keys(llmstats_model_fields());
    $normalized = [];
    foreach ($known as $field) {
        if (in_array($field, $fields, true)) {
            $normalized[] = $field;
        }
    }

    return $normalized;
}

function llmstats_max_servers()
{
    return 16;
}

function llmstats_clamp_refresh_interval($value)
{
    $interval = is_numeric($value) ? (int)$value : 10;
    if ($interval < 1) {
        $interval = 1;
    } elseif ($interval > 86400) {
        $interval = 86400;
    }

    return $interval;
}

function llmstats_sanitize_server_url($url)
{
    $url = trim((string)$url);
    $url = preg_replace('/[\x00-\x1F\x7F\s]/', '', $url);
    if (!is_string($url) || $url === '' || strlen($url) > 2048) {
        return '';
    }

    if (preg_match('#^https?://[^/]+#i', $url) !== 1) {
        return '';
    }

    return rtrim($url, '/');
}

function llmstats_generate_server_id()
{
    return 'srv' . bin2hex(random_bytes(5));
}

function llmstats_normalize_server($server)
{
    if (!is_array($server)) {
        return null;
    }

    $url = llmstats_sanitize_server_url($server['url'] ?? '');
    if ($url === '') {
        return null;
    }

    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($server['id'] ?? ''));
    if (!is_string($id) || $id === '' || strlen($id) > 32) {
        $id = llmstats_generate_server_id();
    }

    $name = trim((string)($server['name'] ?? ''));
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
    if (!is_string($name) || $name === '') {
        $name = 'Server';
    }
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 64);
    } else {
        $name = substr($name, 0, 64);
    }

    $type = (string)($server['type'] ?? 'auto');
    if (!in_array($type, llmstats_server_types(), true)) {
        $type = 'auto';
    }

    return [
        'id' => $id,
        'name' => $name,
        'url' => $url,
        'type' => $type,
        'fields' => llmstats_normalize_model_fields($server['fields'] ?? null)
    ];
}

function llmstats_normalize_server_list($servers)
{
    if (!is_array($servers)) {
        return [];
    }

    $normalized = [];
    $seen_ids = [];
    foreach ($servers as $server) {
        $entry = llmstats_normalize_server($server);
        if ($entry === null) {
            continue;
        }
        if (isset($seen_ids[$entry['id']])) {
            // Deterministic suffix so a hand-edited config with duplicate ids
            // keeps stable ids across requests.
            $base = substr($entry['id'], 0, 26);
            $suffix = 2;
            while (isset($seen_ids[$base . '-' . $suffix])) {
                $suffix++;
            }
            $entry['id'] = $base . '-' . $suffix;
        }
        $seen_ids[$entry['id']] = true;
        $normalized[] = $entry;
        if (count($normalized) >= llmstats_max_servers()) {
            break;
        }
    }

    return $normalized;
}

function llmstats_parse_servers($raw_value)
{
    $raw_value = is_string($raw_value) ? trim($raw_value) : '';
    if ($raw_value === '' || strpos($raw_value, 'json:') !== 0) {
        return [];
    }

    $encoded = substr($raw_value, 5);
    if ($encoded === '') {
        return [];
    }

    $decoded_json = base64_decode($encoded, true);
    if (!is_string($decoded_json) || $decoded_json === '') {
        return [];
    }

    $decoded = json_decode($decoded_json, true);
    if (!is_array($decoded)) {
        return [];
    }

    return llmstats_normalize_server_list($decoded);
}

function llmstats_encode_servers($servers)
{
    $normalized = llmstats_normalize_server_list($servers);
    $json = json_encode($normalized);
    if (!is_string($json)) {
        $json = '[]';
    }

    return 'json:' . base64_encode($json);
}

function llmstats_array_is_list($value)
{
    // array_is_list() needs PHP 8.1; Unraid 6.9 ships older PHP.
    if (!is_array($value)) {
        return false;
    }
    if (function_exists('array_is_list')) {
        return array_is_list($value);
    }

    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function llmstats_format_bytes($bytes)
{
    $bytes = is_numeric($bytes) ? (float)$bytes : 0.0;
    if ($bytes <= 0) {
        return '';
    }

    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 1) . ' GB';
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 0) . ' MB';
    }

    return round($bytes / 1024, 0) . ' KB';
}

function llmstats_parse_quant_from_name($name)
{
    $name = (string)$name;
    if (preg_match('/\b(I?Q\d+(?:_[A-Z0-9]+)*)\b/i', $name, $matches) === 1) {
        return strtoupper($matches[1]);
    }
    if (preg_match('/\b(BF16|F16|F32|FP16|FP32)\b/i', $name, $matches) === 1) {
        return strtoupper($matches[1]);
    }

    return '';
}
