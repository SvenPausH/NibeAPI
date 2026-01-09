<?php
/**
 * Historie-Seite für nibe_datenpunkte_log
 * history.php - Version 3.3.00
 */

// UTF-8 Encoding sicherstellen
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

// Konfiguration und Funktionen einbinden
require_once 'config.php';
require_once 'functions.php';
require_once 'database.php';

// Prüfen ob Datenbank aktiviert ist
if (!USE_DB) {
    die('Datenbank-Funktionen sind deaktiviert. Bitte aktivieren Sie USE_DB in der config.php');
}

$version = '3.4.00';

// Pagination Parameter
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['perPage']) ? (int)$_GET['perPage'] : 999999; // Default: Alle
$perPage = ($perPage > 0 && $perPage <= 999999) ? $perPage : 999999;
$offset = ($page - 1) * $perPage;

// Filter Parameter
$filterApiId = $_GET['filterApiId'] ?? '';
$filterModbusId = $_GET['filterModbusId'] ?? '';
$filterTitle = $_GET['filterTitle'] ?? '';
$filterWert = $_GET['filterWert'] ?? '';
$filterZeitVon = $_GET['filterZeitVon'] ?? '';
$filterZeitBis = $_GET['filterZeitBis'] ?? '';

// Zeitstempel automatisch vervollständigen für Datenbankabfrage
$filterZeitVonFull = $filterZeitVon ? $filterZeitVon . ' 00:00:00' : '';
$filterZeitBisFull = $filterZeitBis ? $filterZeitBis . ' 23:59:59' : '';

// Sortierung Parameter
$sortBy = $_GET['sortBy'] ?? 'zeitstempel';
$sortDir = $_GET['sortDir'] ?? 'desc';

// Daten abrufen
$historyData = [];
$totalRecords = 0;
$error = null;

try {
    $pdo = getDbConnection();
    
    // WHERE-Bedingungen aufbauen
    $whereClauses = [];
    $params = [];
    
    if (!empty($filterApiId)) {
        $whereClauses[] = "dp.api_id LIKE ?";
        $params[] = "%$filterApiId%";
    }
    
    if (!empty($filterModbusId)) {
        $whereClauses[] = "dp.modbus_id LIKE ?";
        $params[] = "%$filterModbusId%";
    }
    
    if (!empty($filterTitle)) {
        $whereClauses[] = "dp.title LIKE ?";
        $params[] = "%$filterTitle%";
    }
    
    if (!empty($filterWert)) {
        $whereClauses[] = "log.wert LIKE ?";
        $params[] = "%$filterWert%";
    }
    
    if (!empty($filterZeitVonFull)) {
        $whereClauses[] = "log.zeitstempel >= ?";
        $params[] = $filterZeitVonFull;
    }
    
    if (!empty($filterZeitBisFull)) {
        $whereClauses[] = "log.zeitstempel <= ?";
        $params[] = $filterZeitBisFull;
    }
    
    $whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
    
    // Sortierung aufbauen
    $sortFields = explode(',', $sortBy);
    $sortDirections = explode(',', $sortDir);
    $orderClauses = [];
    
    foreach ($sortFields as $index => $field) {
        $field = trim($field);
        $dir = isset($sortDirections[$index]) ? strtoupper(trim($sortDirections[$index])) : 'ASC';
        $dir = in_array($dir, ['ASC', 'DESC']) ? $dir : 'ASC';
        
        switch ($field) {
            case 'api_id':
                $orderClauses[] = "dp.api_id $dir";
                break;
            case 'modbus_id':
                $orderClauses[] = "dp.modbus_id $dir";
                break;
            case 'title':
                $orderClauses[] = "dp.title $dir";
                break;
            case 'wert':
                $orderClauses[] = "log.wert $dir";
                break;
            case 'zeitstempel':
                $orderClauses[] = "log.zeitstempel $dir";
                break;
        }
    }
    
    $orderSQL = !empty($orderClauses) ? 'ORDER BY ' . implode(', ', $orderClauses) : 'ORDER BY dp.api_id ASC, log.zeitstempel DESC';
    
    // Gesamtanzahl abfragen
    $countSQL = "SELECT COUNT(*) as total 
                 FROM nibe_datenpunkte_log log
                 INNER JOIN nibe_datenpunkte dp ON log.nibe_datenpunkte_id = dp.id
                 $whereSQL";
    
    $countStmt = $pdo->prepare($countSQL);
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetch()['total'];
    
    // Daten abfragen mit Pagination
    $dataSQL = "SELECT 
                    dp.api_id,
                    dp.modbus_id,
                    dp.title,
                    log.wert,
                    log.zeitstempel,
                    log.cwna
                FROM nibe_datenpunkte_log log
                INNER JOIN nibe_datenpunkte dp ON log.nibe_datenpunkte_id = dp.id
                $whereSQL
                $orderSQL
                LIMIT ? OFFSET ?";
    
    $dataStmt = $pdo->prepare($dataSQL);
    $dataStmt->execute(array_merge($params, [$perPage, $offset]));
    $historyData = $dataStmt->fetchAll();
    
} catch (Exception $e) {
    $error = $e->getMessage();
    debugLog("Fehler beim Laden der Historie", ['error' => $error], 'ERROR');
}

// Pagination berechnen
$totalPages = ceil($totalRecords / $perPage);
$totalPages = max(1, $totalPages);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historie - Nibe API Dashboard <?php echo $version?></title>
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        .pagination {
            display: flex;
            gap: 10px;
            justify-content: center;
            align-items: center;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        
        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #333;
            background: white;
        }
        
        .pagination a:hover {
            background: #f5f5f5;
        }
        
        .pagination .current {
            background: #2196F3;
            color: white;
            border-color: #2196F3;
        }
        
        .pagination .disabled {
            opacity: 0.5;
            pointer-events: none;
        }
        
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .per-page-selector {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .per-page-selector select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .cwna-badge-history {
            display: inline-block;
            padding: 2px 6px;
            background: #FF9800;
            color: white;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .cwna-badge-history.import {
            background: #2196F3;
        }
        
        input[type="date"] {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            min-width: 150px;
        }
        
        input[type="date"]:focus {
            outline: none;
            border-color: #4CAF50;
        }
        
        .date-info {
            font-size: 11px;
            color: #666;
            margin-top: 3px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-actions">
            <h1>📊 Historie - Datenpunkt-Verlauf <?php echo $version?></h1>
            <button class="btn-reset" onclick="window.location.href='index.php'">← Zurück zur Übersicht</button>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><strong>Fehler:</strong> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="filter-section">
            <div class="filter-row" style="margin-bottom: 15px;">
                <div class="filter-group">
                    <label for="filterApiId">API ID</label>
                    <input type="text" id="filterApiId" name="filterApiId" value="<?php echo htmlspecialchars($filterApiId); ?>" placeholder="Filter nach API ID...">
                </div>
                <div class="filter-group">
                    <label for="filterModbusId">Modbus ID</label>
                    <input type="text" id="filterModbusId" name="filterModbusId" value="<?php echo htmlspecialchars($filterModbusId); ?>" placeholder="Filter nach Modbus ID...">
                </div>
                <div class="filter-group">
                    <label for="filterTitle">Title</label>
                    <input type="text" id="filterTitle" name="filterTitle" value="<?php echo htmlspecialchars($filterTitle); ?>" placeholder="Filter nach Title...">
                </div>
                <div class="filter-group">
                    <label for="filterWert">Wert</label>
                    <input type="text" id="filterWert" name="filterWert" value="<?php echo htmlspecialchars($filterWert); ?>" placeholder="Filter nach Wert...">
                </div>
            </div>
            
            <div class="filter-row" style="margin-bottom: 15px;">
                <div class="filter-group">
                    <label for="filterZeitVon">Datum von</label>
                    <input type="date" id="filterZeitVon" name="filterZeitVon" value="<?php echo htmlspecialchars($filterZeitVon); ?>">
                    <div class="date-info">Uhrzeit: 00:00:00</div>
                </div>
                <div class="filter-group">
                    <label for="filterZeitBis">Datum bis</label>
                    <input type="date" id="filterZeitBis" name="filterZeitBis" value="<?php echo htmlspecialchars($filterZeitBis); ?>">
                    <div class="date-info">Uhrzeit: 23:59:59</div>
                </div>
                <div class="filter-group">
                    <label for="sortBy">Sortierung</label>
                    <select id="sortBy" name="sortBy">
                        <option value="api_id,zeitstempel" <?php echo $sortBy === 'api_id,zeitstempel' ? 'selected' : ''; ?>>API ID, Zeitstempel</option>
                        <option value="modbus_id,zeitstempel" <?php echo $sortBy === 'modbus_id,zeitstempel' ? 'selected' : ''; ?>>Modbus ID, Zeitstempel</option>
                        <option value="title,zeitstempel" <?php echo $sortBy === 'title,zeitstempel' ? 'selected' : ''; ?>>Title, Zeitstempel</option>
                        <option value="zeitstempel" <?php echo $sortBy === 'zeitstempel' ? 'selected' : ''; ?>>Nur Zeitstempel</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="sortDir">Reihenfolge</label>
                    <select id="sortDir" name="sortDir">
                        <option value="asc,desc" <?php echo $sortDir === 'asc,desc' ? 'selected' : ''; ?>>ASC, DESC (Neuste oben)</option>
                        <option value="desc,desc" <?php echo $sortDir === 'desc,desc' ? 'selected' : ''; ?>>DESC, DESC</option>
                        <option value="asc,asc" <?php echo $sortDir === 'asc,asc' ? 'selected' : ''; ?>>ASC, ASC</option>
                        <option value="desc" <?php echo $sortDir === 'desc' ? 'selected' : ''; ?>>DESC (nur Zeitstempel)</option>
                        <option value="asc" <?php echo $sortDir === 'asc' ? 'selected' : ''; ?>>ASC (nur Zeitstempel)</option>
                    </select>
                </div>
            </div>
            
            <div class="button-group">
                <button type="button" class="btn-reset" onclick="resetFilters()">Zurücksetzen</button>
            </div>
        </div>
        
        <div class="info-bar">
            <div class="info-left">
                <div>Gesamt: <strong><?php echo number_format($totalRecords, 0, ',', '.'); ?></strong> Einträge</div>
                <div>Seite: <strong><?php echo $page; ?></strong> von <strong><?php echo $totalPages; ?></strong></div>
                <div>Zeige: <strong><?php echo count($historyData); ?></strong> Einträge</div>
            </div>
            <div class="per-page-selector">
                <label>Pro Seite:</label>
                <select onchange="changePerPage(this.value)">
                    <option value="10" <?php echo $perPage == 10 ? 'selected' : ''; ?>>10</option>
                    <option value="25" <?php echo $perPage == 25 ? 'selected' : ''; ?>>25</option>
                    <option value="50" <?php echo $perPage == 50 ? 'selected' : ''; ?>>50</option>
                    <option value="100" <?php echo $perPage == 100 ? 'selected' : ''; ?>>100</option>
                    <option value="250" <?php echo $perPage == 250 ? 'selected' : ''; ?>>250</option>
                    <option value="500" <?php echo $perPage == 500 ? 'selected' : ''; ?>>500</option>
                    <option value="1000" <?php echo $perPage == 1000 ? 'selected' : ''; ?>>1000</option>
                    <option value="999999" <?php echo $perPage == 999999 ? 'selected' : ''; ?>>Alle</option>
                </select>
            </div>
        </div>
        
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">« Erste</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">‹ Zurück</a>
                <?php else: ?>
                    <span class="disabled">« Erste</span>
                    <span class="disabled">‹ Zurück</span>
                <?php endif; ?>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Weiter ›</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>">Letzte »</a>
                <?php else: ?>
                    <span class="disabled">Weiter ›</span>
                    <span class="disabled">Letzte »</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>API ID</th>
                        <th>Modbus ID</th>
                        <th>Title</th>
                        <th>Wert</th>
                        <th>Zeitstempel</th>
                        <th>Art</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historyData)): ?>
                        <tr><td colspan="6" class="no-data">Keine Daten gefunden</td></tr>
                    <?php else: ?>
                        <?php foreach ($historyData as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['api_id']); ?></td>
                                <td><?php echo htmlspecialchars($row['modbus_id']); ?></td>
                                <td><?php echo htmlspecialchars($row['title']); ?></td>
                                <td><?php echo htmlspecialchars($row['wert']); ?></td>
                                <td><?php echo htmlspecialchars($row['zeitstempel']); ?></td>
                                <td>
                                    <?php if ($row['cwna'] === 'X'): ?>
                                        <span class="cwna-badge-history">MANUELL</span>
                                    <?php elseif ($row['cwna'] === 'I'): ?>
                                        <span class="cwna-badge-history import">IMPORT</span>
                                    <?php else: ?>
                                        Auto
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
        // Live-Filter Funktionalität
        let filterTimeout;
        
        function applyFilters() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => {
                const url = new URL(window.location.href);
                url.searchParams.set('filterApiId', document.getElementById('filterApiId').value);
                url.searchParams.set('filterModbusId', document.getElementById('filterModbusId').value);
                url.searchParams.set('filterTitle', document.getElementById('filterTitle').value);
                url.searchParams.set('filterWert', document.getElementById('filterWert').value);
                url.searchParams.set('filterZeitVon', document.getElementById('filterZeitVon').value);
                url.searchParams.set('filterZeitBis', document.getElementById('filterZeitBis').value);
                url.searchParams.set('sortBy', document.getElementById('sortBy').value);
                url.searchParams.set('sortDir', document.getElementById('sortDir').value);
                url.searchParams.set('page', '1');
                window.location.href = url.toString();
            }, 500); // 500ms Verzögerung für bessere Performance
        }
        
        function resetFilters() {
            window.location.href = 'history.php';
        }
        
        function changePerPage(value) {
            const url = new URL(window.location.href);
            url.searchParams.set('perPage', value);
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        }
        
        // Event Listeners beim Laden der Seite
        document.addEventListener('DOMContentLoaded', function() {
            // Text-Filter
            document.getElementById('filterApiId').addEventListener('input', applyFilters);
            document.getElementById('filterModbusId').addEventListener('input', applyFilters);
            document.getElementById('filterTitle').addEventListener('input', applyFilters);
            document.getElementById('filterWert').addEventListener('input', applyFilters);
            
            // Datums-Filter (type="date" - nur Datum, Uhrzeit wird automatisch im Backend gesetzt)
            document.getElementById('filterZeitVon').addEventListener('change', applyFilters);
            document.getElementById('filterZeitBis').addEventListener('change', applyFilters);
            
            // Sortierung
            document.getElementById('sortBy').addEventListener('change', applyFilters);
            document.getElementById('sortDir').addEventListener('change', applyFilters);
        });
    </script>
</body>
</html>
