<?php
/**
 * Datenpunkt-Export als JSON
 * export.php v1.1
 *
 * Aufruf: export.php?deviceId=X
 * Liefert alle aktuellen Datenpunkte als JSON-Download.
 */

ob_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/database.php';

// ============================================================================
// EINGABE
// ============================================================================

if (!isset($_GET['deviceId'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'deviceId Parameter fehlt'], JSON_UNESCAPED_UNICODE);
    exit;
}

$deviceId = (int)$_GET['deviceId'];

// ============================================================================
// API-DATEN ABRUFEN
// ============================================================================

try {
    $url     = API_BASE_URL . '/api/' . API_VERSION . '/devices/' . $deviceId . '/points';
    $rawJson = fetchApiData($url, API_KEY, API_USERNAME, API_PASSWORD);
    $rawData = json_decode($rawJson, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Ungültige API-Antwort: ' . json_last_error_msg());
    }

    $processed = processApiData($rawData, $deviceId);

} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// DATENPUNKTE AUFBEREITEN (identischer Filter wie index.php)
// ============================================================================

$datapoints = [];

foreach ($processed as $point) {
    // Kein HIDE_VALUES-Filter – Export enthält alle Datenpunkte
    $datapoints[] = [
        'api_id'        => (int)$point['variableid'],
        'modbus_id'     => $point['modbusregisterid'],
        'title'         => $point['title'],
        'register_type' => $point['modbusregistertype'],
        'value'         => $point['value'],
        'rawvalue'      => (int)$point['rawvalue'],
        'unit'          => $point['unit'],
        'divisor'       => (int)$point['divisor'],
        'decimal'       => (int)$point['decimal'],
        'menuepunkt'    => $point['menuepunkt'] ?? null,
    ];
}

// ============================================================================
// DATEINAME: YYYY-M-D HH-MM-SS_deviceX.json (kein führendes 0 bei Monat/Tag)
// Beispiel: 2026-6-23 10-00-00_device1.json
// ============================================================================

$now      = new DateTime();
$filename = $now->format('Y')      . '-'
          . (int)$now->format('n') . '-'
          . (int)$now->format('j') . ' '
          . $now->format('H-i-s')
          . '_device' . $deviceId . '.json';

$export = [
    'meta' => [
        'app'         => 'NibeAPI',
        'version'     => '1.1',
        'exported_at' => $now->format('Y-m-d H:i:s'),
        'deviceId'    => $deviceId,
        'count'       => count($datapoints),
    ],
    'datapoints' => $datapoints,
];

ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
