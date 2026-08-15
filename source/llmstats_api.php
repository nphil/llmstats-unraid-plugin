<?php
require_once '/usr/local/emhttp/plugins/dynamix/include/Helpers.php';
require_once '/usr/local/emhttp/plugins/llmstats/llmstats_common.php';

function llmstats_http_multi($requests, $connect_timeout = 2, $timeout = 5)
{
    $results = [];
    foreach ($requests as $key => $request) {
        $results[$key] = ['code' => 0, 'body' => '', 'errno' => 0, 'error' => ''];
    }

    if (empty($requests) || !function_exists('curl_multi_init')) {
        return $results;
    }

    $multi = curl_multi_init();
    $handles = [];

    foreach ($requests as $key => $request) {
        $url = is_array($request) ? (string)($request['url'] ?? '') : (string)$request;
        if ($url === '') {
            continue;
        }

        $handle = curl_init();
        $headers = ['Accept: application/json'];
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connect_timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'llmstats-unraid-plugin'
        ]);

        if (is_array($request) && ($request['method'] ?? 'GET') === 'POST') {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, (string)($request['body'] ?? ''));
        }
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

        curl_multi_add_handle($multi, $handle);
        $handles[$key] = $handle;
    }

    $active = null;
    do {
        $status = curl_multi_exec($multi, $active);
        if ($active && curl_multi_select($multi, 0.2) === -1) {
            // select() can fail spuriously; back off briefly instead of spinning.
            usleep(10000);
        }
    } while ($active && $status === CURLM_OK);

    foreach ($handles as $key => $handle) {
        $body = curl_multi_getcontent($handle);
        $results[$key] = [
            'code' => (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'body' => is_string($body) ? $body : '',
            'errno' => (int)curl_errno($handle),
            'error' => (string)curl_error($handle)
        ];
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);

    return $results;
}

function llmstats_http_json($result)
{
    if (!is_array($result) || ($result['code'] ?? 0) < 200 || ($result['code'] ?? 0) >= 300) {
        return null;
    }

    return llmstats_json_body($result);
}

function llmstats_json_body($result)
{
    if (!is_array($result)) {
        return null;
    }

    $decoded = json_decode((string)($result['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : null;
}

function llmstats_server_probe_requests($server)
{
    $base = rtrim((string)$server['url'], '/');
    $type = $server['type'];
    $requests = [];

    if ($type === 'auto' || $type === 'ollama') {
        $requests['ollama_version'] = $base . '/api/version';
        $requests['ollama_tags'] = $base . '/api/tags';
        $requests['ollama_ps'] = $base . '/api/ps';
    }
    if ($type === 'auto' || $type === 'llama-server') {
        $requests['llama_health'] = $base . '/health';
        $requests['llama_props'] = $base . '/props';
        $requests['llama_v1_models'] = $base . '/v1/models';
        $requests['llama_models'] = $base . '/models';
        $requests['llama_slots'] = $base . '/slots';
    }
    if ($type === 'auto' || $type === 'llama-swap') {
        $requests['swap_version'] = $base . '/api/version';
        $requests['swap_models'] = $base . '/models';
        $requests['swap_running'] = $base . '/running';
        $requests['swap_stats'] = $base . '/api/metrics/stats';
        $requests['swap_perf'] = $base . '/api/performance';
    }

    return $requests;
}

function llmstats_detect_server_type($results)
{
    // llama-swap must be checked before Ollama: it serves its own /api/version
    // whose JSON also carries a "version" key, which would satisfy the Ollama
    // probe and strand detection on a backend with no /api/tags. llama-swap's
    // version JSON carries commit/build_date (Ollama's does not) and /running
    // is unique to llama-swap.
    $swap_version = llmstats_http_json($results['swap_version'] ?? null);
    if (is_array($swap_version) && isset($swap_version['version'])
        && (isset($swap_version['commit']) || isset($swap_version['build_date'])
            || (($results['swap_running']['code'] ?? 0) === 200))) {
        return 'llama-swap';
    }

    // Real Ollama always serves /api/tags; requiring it prevents any other
    // server with a {"version": ...} endpoint from being misdetected.
    $version = llmstats_http_json($results['ollama_version'] ?? null);
    if (is_array($version) && isset($version['version']) && (($results['ollama_tags']['code'] ?? 0) === 200)) {
        return 'ollama';
    }

    if ((($results['llama_health']['code'] ?? 0) === 200) || (($results['llama_props']['code'] ?? 0) === 200)) {
        return 'llama-server';
    }

    return '';
}

function llmstats_type_label($type)
{
    if ($type === 'ollama') {
        return 'Ollama';
    }
    if ($type === 'llama-server') {
        return 'llama-server';
    }
    if ($type === 'llama-swap') {
        return 'llama-swap';
    }

    return 'Unknown';
}

function llmstats_classify_failure($results, $keys)
{
    $has_timeout = false;
    $http_code = 0;

    foreach ($keys as $key) {
        $result = $results[$key] ?? null;
        if (!is_array($result)) {
            continue;
        }
        if (($result['errno'] ?? 0) === 28) {
            $has_timeout = true;
        }
        $code = (int)($result['code'] ?? 0);
        if ($code >= 400 && ($http_code === 0 || $code === 401 || $code === 403)) {
            $http_code = $code;
        }
    }

    if ($http_code === 401 || $http_code === 403) {
        return ['status' => 'auth', 'label' => 'Auth error', 'error' => 'HTTP ' . $http_code];
    }
    if ($http_code >= 400) {
        return ['status' => 'error', 'label' => 'Error', 'error' => 'HTTP ' . $http_code];
    }
    if ($has_timeout) {
        return ['status' => 'timeout', 'label' => 'Timeout', 'error' => 'Timeout'];
    }

    return ['status' => 'offline', 'label' => 'Offline', 'error' => 'No connection'];
}

function llmstats_format_expires_at($expires_at)
{
    $expires_at = (string)$expires_at;
    if ($expires_at === '') {
        return 'Unknown';
    }

    $timestamp = strtotime($expires_at);
    if ($timestamp === false || $timestamp < 946684800) {
        return 'Never';
    }
    if ($timestamp > time() + 5 * 365 * 86400) {
        return 'Never';
    }

    // Include the date once the expiry is no longer today; a bare clock time
    // would be misleading for long keep-alive values.
    if (date('Y-m-d', $timestamp) !== date('Y-m-d')) {
        return date('M j H:i', $timestamp);
    }

    return date('H:i:s', $timestamp);
}

function llmstats_new_model_entry($name, $type_label)
{
    return [
        'name' => (string)$name,
        'loaded' => false,
        'state' => 'available',
        'stateLabel' => 'Available',
        'sub' => 'available',
        'canUnload' => false,
        'canLoad' => false,
        'loadUnavailableReason' => 'Loading is not supported for this server mode.',
        'props' => [
            'server' => $type_label,
            'quant' => 'Unavailable',
            'memory' => 'Unavailable',
            'unload' => 'Not loaded',
            'state' => 'Available'
        ]
    ];
}

function llmstats_set_model_state(&$model, $state_key)
{
    $states = [
        'loaded' => ['state' => 'loaded', 'label' => 'Loaded', 'sub' => 'loaded'],
        'idle' => ['state' => 'loaded', 'label' => 'Idle', 'sub' => 'loaded · idle'],
        'busy' => ['state' => 'busy', 'label' => 'Busy', 'sub' => 'loaded · busy'],
        'sleeping' => ['state' => 'sleeping', 'label' => 'Sleeping', 'sub' => 'sleeping'],
        'loading' => ['state' => 'loading', 'label' => 'Loading', 'sub' => 'loading'],
        'failed' => ['state' => 'failed', 'label' => 'Failed', 'sub' => 'failed']
    ];
    $entry = $states[$state_key];

    $model['state'] = $entry['state'];
    $model['stateLabel'] = $entry['label'];
    $model['sub'] = $entry['sub'];
    $model['props']['state'] = $entry['label'];
}

function llmstats_mark_model_loaded(&$model, $can_unload, $unload_value)
{
    $model['loaded'] = true;
    $model['canUnload'] = $can_unload;
    $model['loadUnavailableReason'] = 'Model is already loaded.';
    $model['props']['unload'] = $unload_value;
}

function llmstats_ollama_quant($entry, $name)
{
    $quant = (string)($entry['details']['quantization_level'] ?? '');
    return $quant !== '' ? $quant : llmstats_parse_quant_from_name($name);
}

function llmstats_apply_ollama_running(&$model, $run)
{
    llmstats_set_model_state($model, 'loaded');
    llmstats_mark_model_loaded($model, true, llmstats_format_expires_at($run['expires_at'] ?? ''));

    $memory = llmstats_format_bytes($run['size'] ?? 0);
    if ($memory === '') {
        $memory = llmstats_format_bytes($run['size_vram'] ?? 0);
    }
    if ($memory !== '') {
        $model['props']['memory'] = $memory;
    }
}

function llmstats_build_ollama_models($results)
{
    $tags = llmstats_http_json($results['ollama_tags'] ?? null);
    $ps = llmstats_http_json($results['ollama_ps'] ?? null);

    $running = [];
    if (is_array($ps['models'] ?? null)) {
        foreach ($ps['models'] as $entry) {
            $name = (string)($entry['name'] ?? ($entry['model'] ?? ''));
            if ($name !== '') {
                $running[$name] = $entry;
            }
        }
    }

    $models = [];
    $seen = [];
    $available = is_array($tags['models'] ?? null) ? $tags['models'] : [];

    foreach ($available as $entry) {
        $name = (string)($entry['name'] ?? ($entry['model'] ?? ''));
        if ($name === '' || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;

        $model = llmstats_new_model_entry($name, 'Ollama');

        $quant = llmstats_ollama_quant($entry, $name);
        if ($quant !== '') {
            $model['props']['quant'] = $quant;
        }

        if (isset($running[$name])) {
            llmstats_apply_ollama_running($model, $running[$name]);
        } else {
            $model['canLoad'] = true;
            $model['loadUnavailableReason'] = '';
        }

        $models[] = $model;
    }

    foreach ($running as $name => $run) {
        if (isset($seen[$name])) {
            continue;
        }
        $model = llmstats_new_model_entry($name, 'Ollama');
        $quant = llmstats_ollama_quant($run, $name);
        if ($quant !== '') {
            $model['props']['quant'] = $quant;
        }
        llmstats_apply_ollama_running($model, $run);
        $models[] = $model;
    }

    return $models;
}

function llmstats_llama_model_id($entry)
{
    if (!is_array($entry)) {
        return '';
    }

    foreach (['id', 'model', 'name', 'path'] as $key) {
        if (isset($entry[$key]) && is_string($entry[$key]) && $entry[$key] !== '') {
            return $entry[$key];
        }
    }

    return '';
}

function llmstats_llama_model_state($entry)
{
    $status = $entry['status'] ?? ($entry['state'] ?? null);
    if (is_array($status)) {
        $failed = !empty($status['failed']);
        $status = strtolower((string)($status['value'] ?? ($status['state'] ?? ($status['status'] ?? ''))));
        if ($status === 'unloaded') {
            return 'available';
        }
        if ($failed) {
            return 'failed';
        }
    }
    $status = strtolower((string)$status);

    // Status values reported by llama-server router mode /models responses.
    if (in_array($status, ['loaded', 'ready', 'running'], true)) {
        return 'loaded';
    }
    if (in_array($status, ['sleeping', 'standby'], true)) {
        return 'sleeping';
    }
    if (in_array($status, ['loading', 'starting', 'spawning'], true)) {
        return 'loading';
    }
    if (in_array($status, ['failed', 'error'], true)) {
        return 'failed';
    }

    return 'available';
}

function llmstats_llama_entry_has_model_path($entry)
{
    if (!is_array($entry)) {
        return false;
    }

    foreach (['model', 'path', 'model_path', 'filename'] as $key) {
        if (isset($entry[$key]) && is_string($entry[$key]) && $entry[$key] !== '') {
            return true;
        }
    }

    $status = $entry['status'] ?? null;
    if (is_array($status)) {
        $args = $status['args'] ?? null;
        if (is_array($args)) {
            foreach ($args as $index => $arg) {
                if ($arg === '--model' && isset($args[$index + 1]) && (string)$args[$index + 1] !== '') {
                    return true;
                }
            }
        }

        $preset = (string)($status['preset'] ?? '');
        if ($preset !== '' && preg_match('/^model\s*=/m', $preset) === 1) {
            return true;
        }
    }

    return false;
}

function llmstats_llama_entry_is_placeholder($entry, $id)
{
    return strtolower((string)$id) === 'default' && !llmstats_llama_entry_has_model_path($entry);
}

function llmstats_llama_entry_has_state($entry)
{
    return is_array($entry) && (isset($entry['status']) || isset($entry['state']));
}

function llmstats_llama_entry_list($json)
{
    if (!is_array($json)) {
        return [];
    }
    if (is_array($json['data'] ?? null)) {
        return $json['data'];
    }
    if (is_array($json['models'] ?? null)) {
        return $json['models'];
    }

    return llmstats_array_is_list($json) ? $json : [];
}

function llmstats_llama_is_router($json)
{
    if (!is_array($json)) {
        return false;
    }

    // Single-model builds may alias /models to the OpenAI-style /v1/models
    // list, including a top-level models array. Treat it as router mode only
    // when the entries expose per-model load state.
    foreach (llmstats_llama_entry_list($json) as $entry) {
        if (llmstats_llama_entry_has_state($entry)) {
            return true;
        }
    }

    return false;
}

function llmstats_build_llama_models($results, $is_router, $slots_busy, $slots_total)
{
    $v1 = llmstats_http_json($results['llama_v1_models'] ?? null);
    $router = $is_router ? llmstats_http_json($results['llama_models'] ?? null) : null;

    $states = [];
    foreach (llmstats_llama_entry_list($v1) as $entry) {
        $id = llmstats_llama_model_id($entry);
        if ($id !== '' && !llmstats_llama_entry_is_placeholder($entry, $id)) {
            $states[$id] = $is_router ? 'available' : 'loaded';
        }
    }
    foreach (llmstats_llama_entry_list($router) as $entry) {
        $id = llmstats_llama_model_id($entry);
        if ($id !== '' && !llmstats_llama_entry_is_placeholder($entry, $id)) {
            $states[$id] = llmstats_llama_model_state($entry);
        }
    }

    $loaded_count = 0;
    foreach ($states as $state) {
        if ($state === 'loaded' || $state === 'sleeping') {
            $loaded_count++;
        }
    }

    $models = [];
    foreach ($states as $id => $state) {
        $model = llmstats_new_model_entry($id, 'llama-server');

        if ($state === 'loaded' || $state === 'sleeping') {
            llmstats_mark_model_loaded($model, $is_router, $is_router ? 'Can unload' : 'Unavailable');

            // Slot activity can only be mapped to a model when one model is loaded.
            $knows_activity = $slots_total > 0 && $loaded_count === 1;
            if ($state === 'sleeping') {
                llmstats_set_model_state($model, 'sleeping');
            } elseif ($knows_activity && $slots_busy > 0) {
                llmstats_set_model_state($model, 'busy');
            } elseif ($knows_activity) {
                llmstats_set_model_state($model, 'idle');
            } else {
                llmstats_set_model_state($model, 'loaded');
            }
        } elseif ($state === 'loading') {
            llmstats_set_model_state($model, 'loading');
            $model['loadUnavailableReason'] = 'Model is already loading.';
            $model['props']['unload'] = 'Unavailable';
        } elseif ($state === 'failed') {
            llmstats_set_model_state($model, 'failed');
            $model['canLoad'] = $is_router;
            $model['loadUnavailableReason'] = $is_router ? '' : 'Loading requires llama-server router mode.';
        } elseif ($is_router) {
            $model['canLoad'] = true;
            $model['loadUnavailableReason'] = '';
        } else {
            $model['loadUnavailableReason'] = 'Loading requires llama-server router mode.';
        }

        $models[] = $model;
    }

    return $models;
}

function llmstats_swap_format_ttl($ttl)
{
    $ttl = is_numeric($ttl) ? (int)$ttl : -1;
    if ($ttl === 0) {
        return 'Resident';
    }
    if ($ttl < 0) {
        return '';
    }
    if ($ttl >= 3600 && $ttl % 3600 === 0) {
        return 'TTL ' . ($ttl / 3600) . 'h';
    }
    if ($ttl >= 60) {
        return 'TTL ' . (int)round($ttl / 60) . 'm';
    }

    return 'TTL ' . $ttl . 's';
}

function llmstats_swap_quant($entry, $run)
{
    // Best source: the model file named in the running process command line.
    $cmd = is_array($run) ? (string)($run['cmd'] ?? '') : '';
    if ($cmd !== '' && preg_match('/-m(?:odel)?\s+(\S+\.(?:gguf|bin))/i', $cmd, $matches) === 1) {
        $quant = llmstats_parse_quant_from_name(basename($matches[1]));
        if ($quant !== '') {
            return $quant;
        }
    }

    // The description convention on this config carries the quant (e.g.
    // "MoE UD-Q4_K_XL + vision mmproj"); fall back to the model id last.
    foreach (['description', 'name', 'id'] as $key) {
        $quant = llmstats_parse_quant_from_name((string)($entry[$key] ?? ''));
        if ($quant !== '') {
            return $quant;
        }
    }

    return '';
}

function llmstats_swap_gpu_summary($results)
{
    $perf = llmstats_http_json($results['swap_perf'] ?? null);
    $gpus = null;
    foreach (['gpu_stats', 'gpus', 'gpu'] as $key) {
        if (is_array($perf[$key] ?? null)) {
            $gpus = llmstats_array_is_list($perf[$key]) ? $perf[$key] : [$perf[$key]];
            break;
        }
    }
    if ($gpus === null) {
        return '';
    }

    // gpu_stats is a rolling time series (one sample per poll, same GPU);
    // keep only the newest sample per GPU id, then sum across distinct GPUs.
    $latest = [];
    foreach ($gpus as $gpu) {
        if (!is_array($gpu)) {
            continue;
        }
        $gpu_id = (string)($gpu['uuid'] ?? ($gpu['id'] ?? '0'));
        $latest[$gpu_id] = $gpu;
    }

    $used = 0.0;
    $total = 0.0;
    foreach ($latest as $gpu) {
        foreach (['mem_used_mb', 'memory_used_mb', 'mem_used'] as $key) {
            if (is_numeric($gpu[$key] ?? null)) {
                $used += (float)$gpu[$key];
                break;
            }
        }
        foreach (['mem_total_mb', 'memory_total_mb', 'mem_total'] as $key) {
            if (is_numeric($gpu[$key] ?? null)) {
                $total += (float)$gpu[$key];
                break;
            }
        }
    }
    if ($total <= 0) {
        return '';
    }

    return round($used / 1024, 1) . '/' . round($total / 1024, 1) . ' GB';
}

function llmstats_swap_throughput_summary($results)
{
    $stats = llmstats_http_json($results['swap_stats'] ?? null);
    $p50 = $stats['gen_histogram']['p50'] ?? null;
    if (!is_numeric($p50) || (float)$p50 <= 0) {
        return '';
    }

    return round((float)$p50, 1) . ' tok/s';
}

function llmstats_build_llamaswap_models($results)
{
    $listing = llmstats_http_json($results['swap_models'] ?? null);
    $running_json = llmstats_http_json($results['swap_running'] ?? null);

    $running = [];
    if (is_array($running_json['running'] ?? null)) {
        foreach ($running_json['running'] as $entry) {
            $id = (string)($entry['model'] ?? '');
            if ($id !== '') {
                $running[$id] = $entry;
            }
        }
    }

    $models = [];
    foreach (llmstats_llama_entry_list($listing) as $entry) {
        $id = llmstats_llama_model_id($entry);
        if ($id === '') {
            continue;
        }

        $model = llmstats_new_model_entry($id, 'llama-swap');
        $run = $running[$id] ?? null;

        $quant = llmstats_swap_quant($entry, $run);
        if ($quant !== '') {
            $model['props']['quant'] = $quant;
        }

        $process_state = is_array($run) ? strtolower((string)($run['state'] ?? '')) : '';
        $listed_state = llmstats_llama_model_state($entry);

        if ($process_state === 'starting') {
            llmstats_set_model_state($model, 'loading');
            $model['loadUnavailableReason'] = 'Model is already loading.';
        } elseif ($process_state === 'ready' || $listed_state === 'loaded') {
            llmstats_set_model_state($model, 'loaded');
            $ttl = llmstats_swap_format_ttl(is_array($run) ? ($run['ttl'] ?? -1) : -1);
            llmstats_mark_model_loaded($model, true, $ttl !== '' ? $ttl : 'Can unload');
            if ($ttl !== '') {
                // Residency/TTL is the llama-swap analogue of Ollama's keep-alive.
                $model['props']['ttl'] = $ttl;
            }
        } elseif ($listed_state === 'failed') {
            llmstats_set_model_state($model, 'failed');
            $model['canLoad'] = true;
            $model['loadUnavailableReason'] = '';
        } else {
            // Unloaded: llama-swap loads on demand, so a load action is always
            // available (it pins the model via /upstream and waits for health).
            $model['canLoad'] = true;
            $model['loadUnavailableReason'] = '';
        }

        $models[] = $model;
    }

    return $models;
}

function llmstats_parse_llama_slots($results)
{
    $slots = llmstats_http_json($results['llama_slots'] ?? null);
    if (is_array($slots['slots'] ?? null)) {
        $slots = $slots['slots'];
    } elseif (is_array($slots['data'] ?? null)) {
        $slots = $slots['data'];
    }
    if (!llmstats_array_is_list($slots)) {
        return ['total' => 0, 'busy' => 0];
    }

    $total = 0;
    $busy = 0;
    foreach ($slots as $slot) {
        if (!is_array($slot)) {
            continue;
        }
        $total++;
        $state = strtolower((string)($slot['state'] ?? ($slot['status'] ?? '')));
        // llama.cpp reports id_task = -1 for idle slots, so a plain truthiness
        // check would count idle slots as busy.
        $task_id = $slot['id_task'] ?? null;
        $has_task = is_numeric($task_id) && (int)$task_id >= 0;
        if (!empty($slot['is_processing']) || $has_task || in_array($state, ['busy', 'processing', 'running'], true)) {
            $busy++;
        }
    }

    return ['total' => $total, 'busy' => $busy];
}

function llmstats_sort_models($models)
{
    usort($models, function($left, $right) {
        $left_loaded = !empty($left['loaded']) ? 1 : 0;
        $right_loaded = !empty($right['loaded']) ? 1 : 0;
        if ($left_loaded !== $right_loaded) {
            return $right_loaded - $left_loaded;
        }

        return strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
    });

    return $models;
}

function llmstats_assemble_server_state($server, $results, $retry_seconds)
{
    $state = [
        'id' => $server['id'],
        'name' => $server['name'],
        'url' => $server['url'],
        'fields' => $server['fields'],
        'status' => 'offline',
        'statusLabel' => 'Offline',
        'dot' => 'offline',
        'type' => '',
        'typeLabel' => 'Unknown',
        'typeSource' => $server['type'] === 'auto' ? 'unknown' : 'manual',
        'error' => '',
        'summary' => [],
        'models' => [],
        'modelCount' => 0,
        'loadedCount' => 0,
        'supportsModelActions' => false,
        'chipMeta' => 'offline'
    ];

    $detected = llmstats_detect_server_type($results);
    $type = $server['type'] !== 'auto' ? $server['type'] : $detected;
    if ($server['type'] === 'auto' && $detected !== '') {
        $state['typeSource'] = 'auto';
    }
    $state['type'] = $type;
    $state['typeLabel'] = llmstats_type_label($type);

    $online = false;
    if ($type === 'ollama') {
        $online = (($results['ollama_version']['code'] ?? 0) === 200) || (($results['ollama_tags']['code'] ?? 0) === 200);
    } elseif ($type === 'llama-server') {
        $online = (($results['llama_health']['code'] ?? 0) === 200)
            || (($results['llama_props']['code'] ?? 0) === 200)
            || (($results['llama_v1_models']['code'] ?? 0) === 200);
    } elseif ($type === 'llama-swap') {
        $online = (($results['swap_version']['code'] ?? 0) === 200)
            || (($results['swap_models']['code'] ?? 0) === 200);
    }

    if (!$online) {
        $keys = array_keys(llmstats_server_probe_requests($server));
        $failure = llmstats_classify_failure($results, $keys);
        $state['status'] = $failure['status'];
        $state['statusLabel'] = $failure['label'];
        $state['error'] = $failure['error'];
        if ($server['type'] === 'auto' && $detected === '' && $failure['status'] === 'error') {
            $state['error'] = 'Detection failed';
        }
        $state['summary'] = [
            ['label' => 'Status', 'value' => $state['statusLabel']],
            ['label' => 'Type', 'value' => $state['typeLabel']],
            ['label' => 'Error', 'value' => $state['error']],
            ['label' => 'Retry', 'value' => $retry_seconds . ' sec']
        ];
        return $state;
    }

    $state['status'] = 'online';
    $state['statusLabel'] = 'Online';
    $state['dot'] = 'online';

    if ($type === 'ollama') {
        $state['supportsModelActions'] = true;
        $state['models'] = llmstats_build_ollama_models($results);
    } elseif ($type === 'llama-swap') {
        $state['supportsModelActions'] = true;
        $state['models'] = llmstats_build_llamaswap_models($results);
    } else {
        $slots = llmstats_parse_llama_slots($results);
        $is_router = llmstats_llama_is_router(llmstats_http_json($results['llama_models'] ?? null));
        $state['supportsModelActions'] = $is_router;
        $state['models'] = llmstats_build_llama_models($results, $is_router, $slots['busy'], $slots['total']);
    }
    $state['models'] = llmstats_sort_models($state['models']);

    $loaded = 0;
    $busy = 0;
    foreach ($state['models'] as $model) {
        if (!empty($model['loaded'])) {
            $loaded++;
        }
        if (($model['state'] ?? '') === 'busy') {
            $busy++;
        }
    }
    $state['modelCount'] = count($state['models']);
    $state['loadedCount'] = $loaded;
    if ($busy > 0) {
        $state['dot'] = 'busy';
    }
    $state['chipMeta'] = $loaded . ' loaded / ' . $state['modelCount'] . ' models';

    if ($type === 'ollama') {
        $state['summary'] = [
            ['label' => 'Status', 'value' => 'Online'],
            ['label' => 'Type', 'value' => 'Ollama'],
            ['label' => 'Models', 'value' => $state['modelCount'] . ' available'],
            ['label' => 'Loaded', 'value' => $loaded . ' loaded']
        ];
    } elseif ($type === 'llama-swap') {
        $swap_version = llmstats_http_json($results['swap_version'] ?? null);
        $version_text = 'llama-swap';
        if (is_string($swap_version['version'] ?? null) && $swap_version['version'] !== '') {
            $version_text .= ' ' . $swap_version['version'];
        }

        // The fourth cell carries what Ollama never offered: live GPU memory
        // and the median generation speed across recent requests.
        $gpu = llmstats_swap_gpu_summary($results);
        $throughput = llmstats_swap_throughput_summary($results);
        $extra_label = 'GPU';
        $extra_value = $gpu;
        if ($gpu !== '' && $throughput !== '') {
            $extra_value = $gpu . ' · ' . $throughput;
        } elseif ($gpu === '' && $throughput !== '') {
            $extra_label = 'Speed';
            $extra_value = $throughput;
        } elseif ($gpu === '') {
            $extra_value = 'Unavailable';
        }

        $state['summary'] = [
            ['label' => 'Status', 'value' => 'Online'],
            ['label' => 'Type', 'value' => $version_text],
            ['label' => 'Models', 'value' => $loaded . ' loaded / ' . $state['modelCount']],
            ['label' => $extra_label, 'value' => $extra_value]
        ];
    } else {
        $state['summary'] = [
            ['label' => 'Status', 'value' => 'Online'],
            ['label' => 'Type', 'value' => 'llama-server'],
            ['label' => 'Models', 'value' => $loaded . ' loaded / ' . $state['modelCount']],
            ['label' => 'Mode', 'value' => $is_router ? 'Router' : 'Single']
        ];
        if ($slots['total'] > 0) {
            $state['summary'][2] = [
                'label' => 'Slots',
                'value' => $slots['busy'] . ' busy / ' . ($slots['total'] - $slots['busy']) . ' idle'
            ];
        }
    }

    return $state;
}

function llmstats_collect_server_states($servers, $retry_seconds)
{
    $requests = [];
    foreach ($servers as $server) {
        foreach (llmstats_server_probe_requests($server) as $key => $url) {
            $requests[$server['id'] . '|' . $key] = $url;
        }
    }

    $results = llmstats_http_multi($requests);

    $states = [];
    foreach ($servers as $server) {
        $server_results = [];
        $prefix = $server['id'] . '|';
        foreach ($results as $key => $result) {
            if (strpos($key, $prefix) === 0) {
                $server_results[substr($key, strlen($prefix))] = $result;
            }
        }
        $states[] = llmstats_assemble_server_state($server, $server_results, $retry_seconds);
    }

    return $states;
}

function llmstats_resolve_server_type($server)
{
    if ($server['type'] !== 'auto') {
        return $server['type'];
    }

    $base = rtrim($server['url'], '/');
    $results = llmstats_http_multi([
        'ollama_version' => $base . '/api/version',
        'ollama_tags' => $base . '/api/tags',
        'swap_version' => $base . '/api/version',
        'swap_running' => $base . '/running',
        'llama_health' => $base . '/health',
        'llama_props' => $base . '/props'
    ]);

    return llmstats_detect_server_type($results);
}

function llmstats_unload_requests($server, $type, $models)
{
    $base = rtrim($server['url'], '/');
    $requests = [];

    foreach ($models as $index => $model) {
        if ($type === 'ollama') {
            $requests['unload' . $index] = [
                'url' => $base . '/api/generate',
                'method' => 'POST',
                'body' => json_encode(['model' => $model, 'keep_alive' => 0])
            ];
        } elseif ($type === 'llama-swap') {
            $requests['unload' . $index] = [
                'url' => $base . '/api/models/unload/' . rawurlencode($model),
                'method' => 'POST',
                'body' => ''
            ];
        } else {
            $requests['unload' . $index] = [
                'url' => $base . '/models/unload',
                'method' => 'POST',
                'body' => json_encode(['model' => $model])
            ];
        }
    }

    return $requests;
}

function llmstats_load_request($server, $type, $model)
{
    $base = rtrim($server['url'], '/');

    if ($type === 'ollama') {
        return [
            'url' => $base . '/api/generate',
            'method' => 'POST',
            'body' => json_encode(['model' => $model, 'prompt' => '', 'stream' => false])
        ];
    }

    if ($type === 'llama-swap') {
        // llama-swap loads on demand: /upstream pins the model, triggers the
        // swap, and answers once the underlying server's health check passes.
        return [
            'url' => $base . '/upstream/' . rawurlencode($model) . '/health',
            'method' => 'GET'
        ];
    }

    return [
        'url' => $base . '/models/load',
        'method' => 'POST',
        'body' => json_encode(['model' => $model])
    ];
}

function llmstats_action_error($result, $type, $verb)
{
    $code = (int)($result['code'] ?? 0);

    if ($code >= 200 && $code < 300) {
        $body = llmstats_json_body($result);
        if (is_array($body) && isset($body['success']) && $body['success'] === false) {
            $message = (string)($body['error']['message'] ?? ($body['message'] ?? ''));
            return $message !== '' ? $message : 'Server rejected ' . $verb;
        }

        return '';
    }

    if ($code > 0) {
        $error = 'HTTP ' . $code;
        $body = llmstats_json_body($result);
        if (is_array($body)) {
            $message = (string)($body['error']['message'] ?? ($body['message'] ?? ''));
            if ($message !== '') {
                $error .= ': ' . $message;
            }
        }
        if ($type === 'llama-server' && $code === 404) {
            $error .= ' (' . $verb . ' requires llama-server router mode)';
        }
        return $error;
    }

    $detail = trim((string)($result['error'] ?? ''));
    if (($result['errno'] ?? 0) === 28) {
        return $detail !== '' ? 'Timeout: ' . $detail : 'Timeout';
    }

    return $detail !== '' ? $detail : 'No connection';
}

function llmstats_run_load($server, $model)
{
    $type = llmstats_resolve_server_type($server);
    if (!in_array($type, ['ollama', 'llama-server', 'llama-swap'], true)) {
        return ['ok' => false, 'error' => 'Server type could not be determined.'];
    }

    // llama-swap loads can take a while (a 17GB model reads ~35s from disk).
    $results = llmstats_http_multi(['load' => llmstats_load_request($server, $type, $model)], 3, $type === 'llama-swap' ? 120 : 60);
    $error = llmstats_action_error($results['load'] ?? [], $type, 'load');
    if ($error === '') {
        return ['ok' => true, 'loaded' => 1];
    }

    return ['ok' => false, 'error' => 'Load failed: ' . $error];
}

function llmstats_run_unload($server, $models)
{
    $type = llmstats_resolve_server_type($server);
    if (!in_array($type, ['ollama', 'llama-server', 'llama-swap'], true)) {
        return ['ok' => false, 'error' => 'Server type could not be determined.'];
    }

    $results = llmstats_http_multi(llmstats_unload_requests($server, $type, $models), 3, 20);

    $unloaded = 0;
    $errors = [];
    foreach ($results as $result) {
        $error = llmstats_action_error($result, $type, 'unload');
        if ($error === '') {
            $unloaded++;
        } else {
            $errors[] = $error;
        }
    }

    if ($unloaded === count($models)) {
        return ['ok' => true, 'unloaded' => $unloaded];
    }

    return [
        'ok' => false,
        'unloaded' => $unloaded,
        'error' => 'Unload failed: ' . implode(', ', array_unique($errors))
    ];
}

function llmstats_loaded_model_names($server)
{
    $results = llmstats_http_multi(llmstats_server_probe_requests($server));
    $state = llmstats_assemble_server_state($server, $results, 0);

    $names = [];
    foreach ($state['models'] as $model) {
        if (!empty($model['loaded']) && !empty($model['canUnload'])) {
            $names[] = $model['name'];
        }
    }

    return $names;
}

function llmstats_post_model_name()
{
    $model = preg_replace('/[\x00-\x1F\x7F]/', '', trim((string)($_POST['model'] ?? '')));
    if (!is_string($model) || $model === '' || strlen($model) > 512) {
        return '';
    }

    return $model;
}

function llmstats_validate_csrf()
{
    // Unraid's local_prepend.php already validates csrf_token on every POST
    // and then unsets it from $_POST, so the token is normally gone by the
    // time this script runs; $_REQUEST still holds a copy.
    $token = (string)($_POST['csrf_token'] ?? ($_POST['csrfToken'] ?? ($_REQUEST['csrf_token'] ?? '')));
    $expected = llmstats_csrf_token();

    if ($token !== '' && $expected !== '') {
        return hash_equals($expected, $token);
    }

    // No token visible (consumed upstream or never issued): require a strict
    // same-origin AJAX request instead.
    return llmstats_is_same_origin_ajax();
}

function llmstats_json_response($data, $http_status = 200)
{
    http_response_code($http_status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

    $json = json_encode($data, llmstats_json_options());
    echo $json !== false ? $json : '{}';
    exit;
}

function llmstats_find_server($servers, $id)
{
    foreach ($servers as $server) {
        if ($server['id'] === $id) {
            return $server;
        }
    }

    return null;
}

$cfg = llmstats_read_cfg('llmstats');
$servers = llmstats_parse_servers($cfg['SERVERS'] ?? '');
$refresh_interval = llmstats_clamp_refresh_interval($cfg['REFRESH_INTERVAL'] ?? 10);

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($method === 'GET' && $action === 'get_server_states') {
    llmstats_json_response([
        'servers' => llmstats_collect_server_states($servers, $refresh_interval),
        'confirmUnload' => ($cfg['CONFIRM_UNLOAD'] ?? '1') === '1',
        'generatedAt' => date('H:i:s')
    ]);
}

if ($method === 'GET' && $action === 'test_server') {
    // Probing arbitrary URLs is an SSRF primitive; only allow same-origin AJAX.
    if (!llmstats_is_same_origin_ajax()) {
        llmstats_json_response(['ok' => false, 'error' => 'Invalid request origin.'], 403);
    }

    $url = llmstats_sanitize_server_url($_GET['url'] ?? '');
    if ($url === '') {
        llmstats_json_response(['ok' => false, 'error' => 'Invalid server URL. Use http:// or https://.']);
    }

    $type = (string)($_GET['type'] ?? 'auto');
    if (!in_array($type, llmstats_server_types(), true)) {
        $type = 'auto';
    }

    $probe = [
        'id' => 'test',
        'name' => 'Test',
        'url' => $url,
        'type' => $type,
        'fields' => llmstats_default_model_fields()
    ];
    $results = llmstats_http_multi(llmstats_server_probe_requests($probe));
    $state = llmstats_assemble_server_state($probe, $results, $refresh_interval);

    llmstats_json_response([
        'ok' => $state['status'] === 'online',
        'status' => $state['status'],
        'statusLabel' => $state['statusLabel'],
        'type' => $state['type'],
        'typeLabel' => $state['typeLabel'],
        'typeSource' => $state['typeSource'],
        'error' => $state['error'],
        'modelCount' => $state['modelCount'],
        'loadedCount' => $state['loadedCount'],
        'supportsModelActions' => $state['supportsModelActions']
    ]);
}

if ($method === 'POST' && ($action === 'load_model' || $action === 'unload_model' || $action === 'unload_all')) {
    if (!llmstats_validate_csrf()) {
        llmstats_json_response(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    $server_id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['server'] ?? ''));
    $server = llmstats_find_server($servers, $server_id);
    if ($server === null) {
        llmstats_json_response(['ok' => false, 'error' => 'Unknown server.'], 400);
    }

    if ($action === 'unload_all') {
        $models = llmstats_loaded_model_names($server);
        if (empty($models)) {
            llmstats_json_response(['ok' => true, 'unloaded' => 0]);
        }
        llmstats_json_response(llmstats_run_unload($server, $models));
    }

    $model = llmstats_post_model_name();
    if ($model === '') {
        llmstats_json_response(['ok' => false, 'error' => 'Invalid model name.'], 400);
    }

    if ($action === 'load_model') {
        llmstats_json_response(llmstats_run_load($server, $model));
    }

    llmstats_json_response(llmstats_run_unload($server, [$model]));
}

llmstats_json_response(['error' => 'Invalid action'], 400);
