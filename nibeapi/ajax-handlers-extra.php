<?php
/**
 * AJAX-Handler v3.5.0: gespeicherte Auswahlen und Steuerung
 * ajax-handlers-extra.php  (wird von ajax-handlers.php eingebunden)
 *
 * Alle verändernden Aktionen akzeptieren nur POST mit JSON-Body.
 */

require_once __DIR__ . '/selections.php';
require_once __DIR__ . '/control-engine.php';

if (!isset($_GET['ajax'])) {
    return;
}

$extraActions = [
    'getSelections', 'getSelection', 'saveSelection', 'deleteSelection',
    'controlGetRules', 'controlSaveRule', 'controlDeleteRule', 'controlToggleRule',
    'controlDuplicateRule', 'controlCatalog', 'controlPreview', 'controlLog',
];

if (!in_array($_GET['ajax'], $extraActions, true)) {
    return;
}

ob_clean();
header('Content-Type: application/json; charset=utf-8');

function ajaxReply(array $payload) {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function ajaxInput() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Diese Aktion erfordert POST');
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Ungültige Anfrage');
    }
    return $data;
}

if (!USE_DB) {
    ajaxReply(['success' => false, 'error' => 'Datenbank ist deaktiviert']);
}

try {
    switch ($_GET['ajax']) {

        // ----------------------------------------------------------- Auswahlen
        case 'getSelections':
            ajaxReply(['success' => true, 'selections' => getSelections((int)($_GET['deviceId'] ?? 0))]);

        case 'getSelection':
            $sel = getSelection((int)($_GET['id'] ?? 0));
            if (!$sel) {
                throw new Exception('Auswahl nicht gefunden');
            }
            ajaxReply(['success' => true, 'selection' => $sel]);

        case 'saveSelection':
            $in = ajaxInput();
            $apiIds = array_key_exists('apiIds', $in) && is_array($in['apiIds']) ? $in['apiIds'] : null;
            $id = saveSelection($in['id'] ?? null, $in['name'] ?? '', (int)($in['deviceId'] ?? 0), $apiIds);
            ajaxReply(['success' => true, 'id' => $id, 'selection' => getSelection($id)]);

        case 'deleteSelection':
            $in = ajaxInput();
            ajaxReply(['success' => deleteSelection((int)($in['id'] ?? 0))]);

        // ----------------------------------------------------------- Steuerung
        case 'controlGetRules':
            ajaxReply([
                'success'  => true,
                'rules'    => controlLoadRules(),
                'lastRun'  => controlGetLastRun(),
                'now'      => time(),
                'enabled'  => (bool)controlSetting('CONTROL_ENABLED', true),
                'devices'  => getAllDevices(),
            ]);

        case 'controlCatalog':
            ajaxReply(['success' => true, 'catalog' => controlPointCatalog()]);

        case 'controlSaveRule':
            $in = ajaxInput();
            $id = controlSaveRule($in['id'] ?? null, (array)($in['rule'] ?? []));
            ajaxReply(['success' => true, 'id' => $id]);

        case 'controlDeleteRule':
            $in = ajaxInput();
            ajaxReply(['success' => controlDeleteRule((int)($in['id'] ?? 0))]);

        case 'controlToggleRule':
            $in = ajaxInput();
            controlSetRuleActive((int)($in['id'] ?? 0), !empty($in['active']));
            ajaxReply(['success' => true]);

        case 'controlDuplicateRule':
            $in = ajaxInput();
            ajaxReply(['success' => true, 'id' => controlDuplicateRule((int)($in['id'] ?? 0))]);

        case 'controlPreview':
            // Liest live von der Anlage, sendet aber nichts.
            $rules = controlLoadRules();
            $now = new DateTime('now');
            ajaxReply([
                'success' => true,
                'now'     => $now->format('Y-m-d H:i:s'),
                'results' => controlEvaluateAll($rules, $now),
            ]);

        case 'controlLog':
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
            $ruleId = (int)($_GET['regelId'] ?? 0);
            $onlyEvents = !empty($_GET['nurAenderungen']);
            $sql = "SELECT * FROM nibe_regel_log WHERE 1=1";
            $params = [];
            if ($ruleId > 0) {
                $sql .= " AND regel_id = ?";
                $params[] = $ruleId;
            }
            if ($onlyEvents) {
                $sql .= " AND status <> 'info'";
            }
            $sql .= " ORDER BY id DESC LIMIT " . $limit;
            ensureExtensionTables();
            $stmt = getDbConnection()->prepare($sql);
            $stmt->execute($params);
            ajaxReply(['success' => true, 'log' => $stmt->fetchAll()]);
    }
} catch (Exception $e) {
    debugLog('AJAX Fehler (' . $_GET['ajax'] . ')', ['error' => $e->getMessage()], 'ERROR');
    ajaxReply(['success' => false, 'error' => $e->getMessage()]);
}
