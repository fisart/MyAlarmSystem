<?php
declare(strict_types=1);

// Run once in the Symcon script editor with live FIFO disabled. No alarm/module calls.
// Only API capabilities and redacted thread metadata are returned, never script contents.
(static function (): void {
    $read = static function (callable $call): array {
        set_error_handler(static function (int $severity): bool {
            throw new ErrorException('Native inventory read failed.', 0, $severity);
        }, E_WARNING | E_NOTICE);
        try { return ['ok' => true, 'value' => $call()]; }
        catch (Throwable $e) { return ['ok' => false, 'error_type' => get_debug_type($e)]; }
        finally { restore_error_handler(); }
    };
    $report = [
        'schema' => 1, 'captured_at' => date(DATE_ATOM), 'php_version' => PHP_VERSION,
        'mode' => 'one-shot read-only execution metadata; no FIFO activation',
        'scope' => 'API/schema evidence only. No proof of Module 1 coverage, historical-call completion or safe guard reset.',
        'limits' => ['thread_details' => 8, 'fields_per_thread' => 32],
        'capabilities' => [], 'threads' => [],
    ];
    foreach (['IPS_GetScriptThreadList', 'IPS_GetScriptThread', 'IPS_ScriptThreadExists', 'IPS_GetScriptThreads'] as $name) {
        $report['capabilities'][$name] = ['php_callable' => function_exists($name)];
    }
    // IPS_GetScriptThreads is JSON-RPC-only in the official stubs; never invoke it here.
    if (function_exists('IPS_GetKernelVersion')) {
        $version = $read(static fn() => IPS_GetKernelVersion());
        if ($version['ok'] && is_string($version['value']) && preg_match('/^[0-9.]{1,32}$/D', $version['value'])) $report['symcon_version'] = $version['value'];
    }
    if (!function_exists('IPS_GetScriptThreadList')) {
        $report['inventory_status'] = 'thread listing unavailable';
    } else {
        $listing = $read(static fn() => IPS_GetScriptThreadList());
        if (!$listing['ok'] || !is_array($listing['value'])) {
            $report['inventory_status'] = 'thread listing failed or returned unsupported shape';
            $report['listing_error_type'] = $listing['error_type'] ?? get_debug_type($listing['value']);
        } else {
            $report['inventory_status'] = 'snapshot only; coverage unverified';
            $report['listed_entries'] = count($listing['value']);
            $report['details_truncated'] = count($listing['value']) > 8;
            $safeNumericFields = ['ThreadID', 'ThreadId', 'ID', 'ScriptID', 'InstanceID', 'TargetID', 'EventID', 'VariableID', 'StartTime', 'Timestamp', 'ExecutionTime', 'Running'];
            $visited = 0;
            foreach ($listing['value'] as $id) {
                if (++$visited > 8) break;
                if (!is_int($id) || $id < 0) {
                    $report['threads'][] = ['entry_type' => get_debug_type($id), 'status' => 'unsupported identifier shape'];
                    continue;
                }
                $row = ['thread_id' => $id];
                if (!function_exists('IPS_GetScriptThread')) {
                    $row['status'] = 'thread detail unavailable';
                } else {
                    $detail = $read(static fn() => IPS_GetScriptThread($id));
                    if (!$detail['ok'] || !is_array($detail['value'])) {
                        $row['status'] = 'detail unavailable; snapshot race or unsupported API';
                        $row['error_type'] = $detail['error_type'] ?? get_debug_type($detail['value']);
                    } else {
                        $row['status'] = 'metadata redacted';
                        $row['field_count'] = count($detail['value']);
                        $row['fields_truncated'] = $row['field_count'] > 32;
                        $row['field_types'] = [];
                        $row['numeric_metadata'] = [];
                        $fields = 0;
                        foreach ($detail['value'] as $key => $value) {
                            if (++$fields > 32) break;
                            if (!is_string($key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $key)) continue;
                            $row['field_types'][$key] = get_debug_type($value);
                            if (in_array($key, $safeNumericFields, true) && (is_int($value) || is_bool($value) || (is_float($value) && is_finite($value)))) $row['numeric_metadata'][$key] = $value;
                        }
                    }
                }
                $report['threads'][] = $row;
            }
        }
    }
    // Output caps do not bound the native allocation for an entire listing or a detail record.
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
})();
