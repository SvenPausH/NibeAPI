<?php
/**
 * Gespeicherte Datenpunkt-Auswahlen + automatische Schema-Installation
 * selections.php v3.5.0
 *
 * Eine Auswahl gehört zu einem Device (die API-IDs unterscheiden sich je nach
 * Gerätetyp) und besteht aus einem Namen und einer Liste von API-IDs.
 */

/**
 * Legt fehlende Tabellen der Version 3.5.0 an (db_update_3.5.0.sql).
 * Läuft pro Request höchstens einmal und prüft vorher mit einer billigen Abfrage.
 */
function ensureExtensionTables() {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo = getDbConnection();

    $needed = ['nibe_auswahl', 'nibe_auswahl_punkte', 'nibe_regeln',
               'nibe_regel_bedingungen', 'nibe_regel_aktionen', 'nibe_regel_log'];
    $existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    if (!array_diff($needed, $existing)) {
        return;
    }

    $hint = ' Bitte die Datei db_update_3.5.0.sql manuell in der Datenbank ausführen (z. B. in phpMyAdmin).';

    $file = __DIR__ . '/db_update_3.5.0.sql';
    if (!is_readable($file)) {
        throw new Exception('Die Datei db_update_3.5.0.sql fehlt neben selections.php, die Tabellen können nicht automatisch angelegt werden.' . $hint);
    }

    // Kommentarzeilen entfernen, dann an ; trennen
    $lines = [];
    foreach (preg_split('/\R/', file_get_contents($file)) as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $lines[] = $line;
    }
    foreach (explode(';', implode("\n", $lines)) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            $what = preg_match('/TABLE IF NOT EXISTS `?(\w+)`?/i', $stmt, $m) ? 'Tabelle ' . $m[1] : 'Statement';
            debugLog('Tabellen-Installation fehlgeschlagen', ['stmt' => $what, 'error' => $e->getMessage()], 'ERROR');
            $done = false; // beim nächsten Aufruf erneut versuchen
            throw new Exception("$what konnte nicht angelegt werden: " . $e->getMessage() . '.' . $hint);
        }
    }
    debugLog('Tabellen der Version 3.5.0 angelegt', null, 'DB');
}

/**
 * Alle Auswahlen eines Devices (mit Anzahl der Datenpunkte)
 */
function getSelections($deviceId) {
    ensureExtensionTables();
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT a.id, a.name, a.deviceId, a.updated_at, COUNT(p.api_id) AS anzahl
        FROM nibe_auswahl a
        LEFT JOIN nibe_auswahl_punkte p ON p.auswahl_id = a.id
        WHERE a.deviceId = ?
        GROUP BY a.id, a.name, a.deviceId, a.updated_at
        ORDER BY a.name ASC
    ");
    $stmt->execute([(int)$deviceId]);
    return $stmt->fetchAll();
}

/**
 * Eine Auswahl inkl. API-IDs
 */
function getSelection($id) {
    ensureExtensionTables();
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id, name, deviceId, updated_at FROM nibe_auswahl WHERE id = ?");
    $stmt->execute([(int)$id]);
    $sel = $stmt->fetch();
    if (!$sel) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT api_id FROM nibe_auswahl_punkte WHERE auswahl_id = ? ORDER BY api_id");
    $stmt->execute([(int)$id]);
    $sel['apiIds'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return $sel;
}

/**
 * Auswahl anlegen ($id = null) oder aktualisieren.
 * $apiIds = null lässt die Punkte unverändert (nur umbenennen).
 * Gibt die ID zurück.
 */
function saveSelection($id, $name, $deviceId, $apiIds = null) {
    ensureExtensionTables();

    $name = trim((string)$name);
    if ($name === '') {
        throw new Exception('Bitte einen Namen angeben');
    }
    if (mb_strlen($name) > 100) {
        throw new Exception('Der Name darf höchstens 100 Zeichen lang sein');
    }

    $pdo = getDbConnection();
    $deviceId = (int)$deviceId;

    // Namen eindeutig pro Device
    $stmt = $pdo->prepare("SELECT id FROM nibe_auswahl WHERE deviceId = ? AND name = ? AND id <> ?");
    $stmt->execute([$deviceId, $name, (int)$id]);
    if ($stmt->fetch()) {
        throw new Exception("Es gibt bereits eine Auswahl mit dem Namen \"$name\"");
    }

    $pdo->beginTransaction();
    try {
        if ($id) {
            $stmt = $pdo->prepare("UPDATE nibe_auswahl SET name = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$name, (int)$id]);
            $stmt = $pdo->prepare("SELECT id FROM nibe_auswahl WHERE id = ?");
            $stmt->execute([(int)$id]);
            if (!$stmt->fetch()) {
                throw new Exception('Auswahl nicht gefunden');
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO nibe_auswahl (deviceId, name) VALUES (?, ?)");
            $stmt->execute([$deviceId, $name]);
            $id = (int)$pdo->lastInsertId();
        }

        if (is_array($apiIds)) {
            $pdo->prepare("DELETE FROM nibe_auswahl_punkte WHERE auswahl_id = ?")->execute([(int)$id]);
            $ins = $pdo->prepare("INSERT IGNORE INTO nibe_auswahl_punkte (auswahl_id, api_id) VALUES (?, ?)");
            foreach (array_unique(array_map('intval', $apiIds)) as $apiId) {
                $ins->execute([(int)$id, $apiId]);
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    return (int)$id;
}

/**
 * Auswahl löschen (die Punkte werden per ON DELETE CASCADE entfernt)
 */
function deleteSelection($id) {
    ensureExtensionTables();
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("DELETE FROM nibe_auswahl WHERE id = ?");
    $stmt->execute([(int)$id]);
    return $stmt->rowCount() > 0;
}
?>
