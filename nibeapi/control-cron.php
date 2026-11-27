<?php
/**
 * Steuerungs-Cronjob
 * control-cron.php v3.5.0
 *
 * Wertet alle aktiven Regeln aus (Zeitfenster, Wochentage, Bedingungen) und sendet
 * Werte an die Anlage - aber nur dann, wenn sich der Wert tatsächlich ändern muss.
 *
 * Cronjob (jede Minute):
 *   * * * * * /usr/bin/php /pfad/zu/nibeapi/control-cron.php >/dev/null 2>&1
 *
 * Aufruf von Hand:
 *   php control-cron.php --debug                 ausführliche Ausgabe
 *   php control-cron.php --dry-run               alles auswerten, aber NICHTS senden
 *   php control-cron.php --rule=3                nur Regel mit ID 3
 *   php control-cron.php --now="2026-12-01 23:30"  Test mit anderer Uhrzeit (am besten mit --dry-run)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dieses Skript darf nur per Kommandozeile / Cronjob ausgeführt werden.\n");
}

$options = getopt('', ['debug', 'dry-run', 'rule:', 'now:']);
$debugMode = isset($options['debug']) || isset($options['dry-run']) || isset($options['now']);
$dryRun = isset($options['dry-run']);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/selections.php';
require_once __DIR__ . '/control-engine.php';

function ctlOut($message, $type = 'INFO') {
    global $debugMode;
    if (!$debugMode && $type !== 'ERROR') {
        return;
    }
    $colors = ['INFO' => "\033[0;36m", 'SUCCESS' => "\033[0;32m", 'WARNING' => "\033[0;33m",
               'ERROR' => "\033[0;31m", 'DEBUG' => "\033[0;35m", 'MONITOR' => "\033[0;34m"];
    $c = $colors[$type] ?? '';
    echo $c . '[' . date('H:i:s') . "] [$type]\033[0m $message\n";
}

if (!USE_DB) {
    ctlOut('Die Steuerung benötigt die Datenbank (USE_DB = true)', 'ERROR');
    exit(1);
}
if (!controlSetting('CONTROL_ENABLED', true)) {
    ctlOut('Steuerung ist in der config.php deaktiviert (CONTROL_ENABLED = false)', 'WARNING');
    exit(0);
}

$now = new DateTime('now');
if (isset($options['now'])) {
    try {
        $now = new DateTime($options['now']);
    } catch (Exception $e) {
        ctlOut('Ungültiger Wert für --now: ' . $options['now'], 'ERROR');
        exit(1);
    }
}

$locked = false;
try {
    // --dry-run schreibt nichts, braucht deshalb keinen Lock und blockiert den Cronjob nicht
    if (!$dryRun) {
        if (!controlAcquireLock()) {
            ctlOut('Ein anderer Steuerungslauf ist noch aktiv - überspringe', 'WARNING');
            exit(0);
        }
        $locked = true;
    }

    ctlOut('=== Steuerung gestartet' . ($dryRun ? ' (DRY-RUN)' : '') . ' - ' . $now->format('D Y-m-d H:i') . ' ===', 'INFO');

    $summary = controlRun([
        'dryRun' => $dryRun,
        'ruleId' => isset($options['rule']) ? (int)$options['rule'] : null,
        'now'    => $now,
        'log'    => 'ctlOut',
    ]);

    if (!$dryRun) {
        controlTouchLastRun();
    }

    ctlOut(sprintf('Fertig: %d Regel(n), %d gesendet, %d unverändert, %d übersteuert, %d gedrosselt, %d Fehler',
        $summary['rules'], $summary['sent'], $summary['unchanged'], $summary['overridden'],
        $summary['throttled'], $summary['errors']), $summary['errors'] ? 'WARNING' : 'SUCCESS');
    debugLog('Steuerung abgeschlossen', $summary, 'CONTROL');

} catch (Exception $e) {
    ctlOut('KRITISCHER FEHLER: ' . $e->getMessage(), 'ERROR');
    debugLog('Steuerung Fehler', ['error' => $e->getMessage()], 'ERROR');
    if ($locked) {
        controlReleaseLock();
    }
    exit(1);
}

if ($locked) {
    controlReleaseLock();
}
exit(0);
