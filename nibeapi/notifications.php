<?php
/**
 * Notifications-Seite für Nibe API Dashboard
 * notifications.php - Version 3.4.00
 */

// WICHTIG: AJAX-Handler ZUERST laden (beendet bei AJAX mit exit)
require_once 'config.php';
require_once 'functions.php';
require_once 'database.php';
require_once 'ajax-handlers.php';

// Ab hier nur noch normaler HTML-Code (wird bei AJAX nicht erreicht)

// UTF-8 Encoding sicherstellen
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

// Prüfen ob Datenbank aktiviert ist
if (!USE_DB) {
    die('Datenbank-Funktionen sind deaktiviert. Bitte aktivieren Sie USE_DB in der config.php');
}

$version = '3.4.00';

// Devices laden
$devices = getAllDevices();
$selectedDeviceId = isset($_GET['deviceId']) ? (int)$_GET['deviceId'] : null;
$onlyActive = isset($_GET['onlyActive']) && $_GET['onlyActive'] === '1';

// Notifications laden
$notifications = [];
$error = null;

try {
    $notifications = getAllNotifications($selectedDeviceId, $onlyActive);
} catch (Exception $e) {
    $error = $e->getMessage();
    debugLog("Fehler beim Laden der Notifications", ['error' => $error], 'ERROR');
}

// Severity Texte
$severityTexts = [
    0 => 'Info',
    1 => 'Warnung',
    2 => 'Alarm',
    3 => 'Kritisch'
];

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Nibe API Dashboard <?php echo $version?></title>
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        .notifications-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filter-notifications {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .filter-notifications-row {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .severity-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            color: white;
        }
        
        .severity-0 { background: #2196F3; }
        .severity-1 { background: #FF9800; }
        .severity-2 { background: #f44336; }
        .severity-3 { background: #9C27B0; }
        
        .notification-active {
            background: #e8f5e9;
        }
        
        .notification-reset {
            background: #f5f5f5;
            opacity: 0.7;
        }
        
        .btn-refresh-notifications {
            background: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .btn-refresh-notifications:hover {
            background: #45a049;
        }
        
        .btn-reset-all {
            background: #f44336;
            color: white;
        }
        
        .btn-reset-all:hover {
            background: #da190b;
        }
        
        .btn-reset-single {
            padding: 6px 12px;
            background: #FF9800;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: background 0.3s;
            white-space: nowrap;
        }
        
        .btn-reset-single:hover {
            background: #F57C00;
        }
        
        .btn-reset-single:active {
            background: #E65100;
        }
        
        .stats-bar {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat-item {
            background: white;
            padding: 15px 20px;
            border-radius: 6px;
            border-left: 4px solid #2196F3;
            flex: 1;
            min-width: 150px;
        }
        
        .stat-item.warning { border-left-color: #FF9800; }
        .stat-item.error { border-left-color: #f44336; }
        .stat-item.critical { border-left-color: #9C27B0; }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #333;
        }
        
        .stat-label {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }
        
        .time-ago {
            font-size: 12px;
            color: #999;
        }
        
        .auto-refresh-notice {
            background: #e3f2fd;
            padding: 10px 15px;
            border-radius: 4px;
            border-left: 4px solid #2196F3;
            margin-bottom: 20px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="notifications-header">
            <h1>🔔 Notifications & Alarme <?php echo $version?></h1>
            <button class="btn-reset" onclick="window.location.href='index.php'">← Zurück zur Übersicht</button>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><strong>Fehler:</strong> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="auto-refresh-notice">
            ℹ️ Diese Seite prüft automatisch alle <?php echo NOTIFICATIONS_CHECK_INTERVAL; ?> Sekunden auf neue Notifications von der API.
        </div>
        
        <div class="filter-notifications">
            <form method="GET" action="notifications.php" id="filterForm">
                <div class="filter-notifications-row">
                    <div class="filter-group">
                        <label for="deviceId">Device</label>
                        <select name="deviceId" id="deviceId" onchange="document.getElementById('filterForm').submit()">
                            <option value="">Alle Devices</option>
                            <?php foreach ($devices as $device): ?>
                                <option value="<?php echo $device['deviceId']; ?>" 
                                        <?php echo $selectedDeviceId == $device['deviceId'] ? 'selected' : ''; ?>>
                                    Device <?php echo $device['deviceId']; ?> - <?php echo htmlspecialchars($device['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="toggle-option" style="margin-top: 25px;">
                        <input type="checkbox" id="onlyActive" name="onlyActive" value="1" 
                               <?php echo $onlyActive ? 'checked' : ''; ?>
                               onchange="document.getElementById('filterForm').submit()">
                        <label for="onlyActive">Nur aktive Notifications</label>
                    </div>
                    
                    <div class="button-group" style="margin-top: 25px;">
                        <button type="button" class="btn-refresh-notifications" onclick="refreshNotifications()">
                            🔄 Von API laden
                        </button>
                        <?php if ($selectedDeviceId !== null && $selectedDeviceId !== ''): ?>
                            <button type="button" class="btn-reset-all" onclick="resetAllNotifications(<?php echo $selectedDeviceId; ?>)">
                                🗑️ Alle zurücksetzen
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
        
        <?php
        // Statistiken berechnen
        $activeNotifications = array_filter($notifications, fn($n) => $n['resetNotifications'] === null);
        $stats = [
            'total' => count($notifications),
            'active' => count($activeNotifications),
            'severity' => [0 => 0, 1 => 0, 2 => 0, 3 => 0]
        ];
        
        foreach ($activeNotifications as $n) {
            $severity = $n['severity'];
            if (isset($stats['severity'][$severity])) {
                $stats['severity'][$severity]++;
            }
        }
        ?>
        
        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Gesamt</div>
            </div>
            <div class="stat-item warning">
                <div class="stat-number"><?php echo $stats['active']; ?></div>
                <div class="stat-label">Aktiv</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $stats['severity'][0]; ?></div>
                <div class="stat-label">Info</div>
            </div>
            <div class="stat-item warning">
                <div class="stat-number"><?php echo $stats['severity'][1]; ?></div>
                <div class="stat-label">Warnungen</div>
            </div>
            <div class="stat-item error">
                <div class="stat-number"><?php echo $stats['severity'][2]; ?></div>
                <div class="stat-label">Alarme</div>
            </div>
            <div class="stat-item critical">
                <div class="stat-number"><?php echo $stats['severity'][3]; ?></div>
                <div class="stat-label">Kritisch</div>
            </div>
        </div>
        
        <div class="info-bar">
            <div class="info-left">
                <div>Anzeige: <strong><?php echo count($notifications); ?></strong> Notification(s)</div>
                <div>Aktiv: <strong><?php echo $stats['active']; ?></strong></div>
            </div>
            <div class="last-update">
                Letzte Aktualisierung: <strong><span id="lastUpdate"><?php echo date('d.m.Y H:i:s'); ?></span></strong>
            </div>
        </div>
        
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Zeitstempel</th>
                        <th>Device</th>
                        <th>Severity</th>
                        <th>Header</th>
                        <th>Description</th>
                        <th>Equipment</th>
                        <th>Status</th>
                        <th>Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($notifications)): ?>
                        <tr><td colspan="8" class="no-data">
                            <?php if ($onlyActive): ?>
                                🎉 Keine aktiven Notifications vorhanden
                            <?php else: ?>
                                Keine Notifications vorhanden
                            <?php endif; ?>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <?php
                            $isActive = $notification['resetNotifications'] === null;
                            $rowClass = $isActive ? 'notification-active' : 'notification-reset';
                            $time = new DateTime($notification['time']);
                            $now = new DateTime();
                            $diff = $now->diff($time);
                            $timeAgo = '';
                            
                            if ($diff->days > 0) {
                                $timeAgo = $diff->days . ' Tag(e)';
                            } elseif ($diff->h > 0) {
                                $timeAgo = $diff->h . ' Stunde(n)';
                            } else {
                                $timeAgo = $diff->i . ' Minute(n)';
                            }
                            $timeAgo .= ' her';
                            ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td>
                                    <?php echo $time->format('d.m.Y H:i:s'); ?>
                                    <div class="time-ago"><?php echo $timeAgo; ?></div>
                                </td>
                                <td>
                                    <strong>Device <?php echo $notification['deviceId']; ?></strong><br>
                                    <small><?php echo htmlspecialchars($notification['deviceName'] ?? '-'); ?></small>
                                </td>
                                <td>
                                    <span class="severity-badge severity-<?php echo $notification['severity']; ?>">
                                        <?php echo $severityTexts[$notification['severity']] ?? 'Unbekannt'; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($notification['header']); ?></td>
                                <td><?php echo htmlspecialchars($notification['description']); ?></td>
                                <td><?php echo htmlspecialchars($notification['equipName']); ?></td>
                                <td>
                                    <?php if ($isActive): ?>
                                        <span style="color: #f44336; font-weight: bold;">● AKTIV</span>
                                    <?php else: ?>
                                        <span style="color: #4CAF50;">✓ Zurückgesetzt</span><br>
                                        <small><?php echo $notification['resetNotifications']; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($isActive): ?>
                                        <button class="btn-reset-single" 
                                                onclick="resetSingleNotification(<?php echo $notification['id']; ?>)"
                                                title="Diese Notification zurücksetzen">
                                            ❌ Reset
                                        </button>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
        // Auto-Refresh
        let refreshInterval = <?php echo NOTIFICATIONS_CHECK_INTERVAL * 1000; ?>;
        let refreshTimer = null;
        
        function startAutoRefresh() {
            if (refreshTimer) {
                clearInterval(refreshTimer);
            }
            
            refreshTimer = setInterval(() => {
                console.log('Auto-refresh: Lade neue Notifications...');
                checkForNewNotifications();
            }, refreshInterval);
        }
        
        async function checkForNewNotifications() {
            const deviceId = document.getElementById('deviceId').value;
            const onlyActive = document.getElementById('onlyActive').checked;
            
            try {
                const response = await fetch('?ajax=getAllNotifications&deviceId=' + (deviceId || '') + '&onlyActive=' + (onlyActive ? 'true' : 'false'));
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('lastUpdate').textContent = new Date().toLocaleString('de-DE');
                    
                    // Seite neu laden wenn sich Anzahl geändert hat
                    const currentCount = document.querySelectorAll('tbody tr').length;
                    if (result.count !== currentCount && currentCount > 0) {
                        console.log('Änderungen erkannt - lade Seite neu');
                        window.location.reload();
                    }
                }
            } catch (error) {
                console.error('Auto-refresh Fehler:', error);
            }
        }
        
        async function refreshNotifications() {
            const deviceId = document.getElementById('deviceId').value;
            
            if (!deviceId) {
                alert('Bitte wählen Sie ein Device aus');
                return;
            }
            
            if (!confirm('Möchten Sie die Notifications von der API neu laden?')) {
                return;
            }
            
            try {
                const response = await fetch('?ajax=fetchNotifications&deviceId=' + deviceId);
                const result = await response.json();
                
                if (result.success) {
                    alert(`✅ ${result.fetched} Notification(s) von API geladen, ${result.saved} neue gespeichert`);
                    window.location.reload();
                } else {
                    alert('❌ Fehler: ' + result.error);
                }
            } catch (error) {
                alert('❌ Verbindungsfehler: ' + error.message);
            }
        }
        
        async function resetAllNotifications(deviceId) {
            if (!confirm('Möchten Sie wirklich ALLE aktiven Notifications für dieses Device zurücksetzen?')) {
                return;
            }
            
            try {
                const response = await fetch('?ajax=resetNotifications&deviceId=' + deviceId);
                const result = await response.json();
                
                if (result.success) {
                    alert(`✅ ${result.resetCount} Notification(s) zurückgesetzt`);
                    window.location.reload();
                } else {
                    alert('❌ Fehler: ' + result.error);
                }
            } catch (error) {
                alert('❌ Verbindungsfehler: ' + error.message);
            }
        }
        
        async function resetSingleNotification(notificationId) {
            if (!confirm('Möchten Sie diese Notification zurücksetzen?')) {
                return;
            }
            
            try {
                const response = await fetch('?ajax=resetSingleNotification&notificationId=' + notificationId);
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ Notification erfolgreich zurückgesetzt');
                    window.location.reload();
                } else {
                    alert('❌ Fehler: ' + result.error);
                }
            } catch (error) {
                alert('❌ Verbindungsfehler: ' + error.message);
            }
        }
        
        // Auto-Refresh starten
        if (refreshInterval > 0) {
            startAutoRefresh();
            console.log('Auto-Refresh aktiviert (alle ' + (refreshInterval / 1000) + ' Sekunden)');
        }
        
        // Cleanup beim Verlassen
        window.addEventListener('beforeunload', () => {
            if (refreshTimer) {
                clearInterval(refreshTimer);
            }
        });
    </script>
</body>
</html>
