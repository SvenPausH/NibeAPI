<?php
/**
 * Steuerungs-Engine (Regeln mit Zeitfenster, Bedingungen und Aktionen)
 * control-engine.php v3.5.0
 *
 * Wird vom Cronjob (control-cron.php) UND von der Web-Oberfläche (steuerung.php,
 * Live-Vorschau) verwendet, damit beide exakt dieselbe Auswertung machen.
 *
 * Ablauf einer Regel:
 *   1. Ist die Regel aktiv und liegt "jetzt" in Wochentagen + Zeitfenster?
 *        nein -> je nach Einstellung "nichts tun" oder SONST-Aktionen ausführen
 *   2. Bedingungen auswerten (UND bindet stärker als ODER, leere Liste = wahr)
 *        wahr -> DANN-Aktionen, falsch -> SONST-Aktionen
 *        nicht auswertbar (Datenpunkt nicht lesbar) -> nichts tun
 *   3. Jede Aktion wird NUR gesendet, wenn der aktuelle Wert vom Zielwert abweicht.
 *
 * Alle Werte (Bedingungen, Hysterese, Aktionen) sind in der Anzeigeeinheit
 * angegeben, also z.B. -300 Gradminuten oder 45,5 °C und nicht der Rohwert.
 */

// ----------------------------------------------------------------------------
// Konfiguration (mit Defaults, damit eine ältere config.php weiter funktioniert)
// ----------------------------------------------------------------------------

function controlSetting($name, $default) {
    return defined($name) ? constant($name) : $default;
}

// ----------------------------------------------------------------------------
// Regeln laden / speichern / löschen
// ----------------------------------------------------------------------------

/**
 * Lädt Regeln inkl. Bedingungen und Aktionen, sortiert nach Priorität.
 */
function controlLoadRules($onlyActive = false, $ruleId = null) {
    ensureExtensionTables();
    $pdo = getDbConnection();

    $sql = "SELECT * FROM nibe_regeln WHERE 1=1";
    $params = [];
    if ($onlyActive) {
        $sql .= " AND aktiv = 1";
    }
    if ($ruleId !== null) {
        $sql .= " AND id = ?";
        $params[] = (int)$ruleId;
    }
    $sql .= " ORDER BY prioritaet ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rules = $stmt->fetchAll();
    if (!$rules) {
        return [];
    }

    $byId = [];
    foreach ($rules as &$r) {
        $r['id'] = (int)$r['id'];
        $r['aktiv'] = (int)$r['aktiv'];
        $r['prioritaet'] = (int)$r['prioritaet'];
        $r['min_haltezeit'] = (int)$r['min_haltezeit'];
        $r['bedingungen'] = [];
        $r['dann'] = [];
        $r['sonst'] = [];
        $byId[$r['id']] = &$r;
    }
    unset($r);

    $in = implode(',', array_keys($byId));

    $stmt = $pdo->query("SELECT * FROM nibe_regel_bedingungen WHERE regel_id IN ($in) ORDER BY regel_id, position, id");
    foreach ($stmt->fetchAll() as $c) {
        $c['deviceId'] = (int)$c['deviceId'];
        $c['api_id'] = (int)$c['api_id'];
        $c['vergleichswert'] = (float)$c['vergleichswert'];
        $c['hysterese'] = (float)$c['hysterese'];
        $byId[(int)$c['regel_id']]['bedingungen'][] = $c;
    }

    $stmt = $pdo->query("SELECT * FROM nibe_regel_aktionen WHERE regel_id IN ($in) ORDER BY regel_id, zweig, position, id");
    foreach ($stmt->fetchAll() as $a) {
        $a['deviceId'] = (int)$a['deviceId'];
        $a['api_id'] = (int)$a['api_id'];
        $a['wert'] = (float)$a['wert'];
        $byId[(int)$a['regel_id']][$a['zweig']][] = $a;
    }

    return array_values($byId);
}

function controlParseNumber($v, $label) {
    if (is_string($v)) {
        $v = str_replace(',', '.', trim($v));
    }
    if ($v === '' || $v === null || !is_numeric($v)) {
        throw new Exception("$label: Bitte eine Zahl angeben");
    }
    return (float)$v;
}

function controlNormalizeTime($t, $label) {
    $t = trim((string)$t);
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $t, $m)) {
        throw new Exception("$label: Ungültige Uhrzeit \"$t\" (Format HH:MM)");
    }
    return sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0);
}

/**
 * Validiert und bereinigt die Eingabedaten einer Regel (aus dem Formular).
 */
function controlValidateRule(array $in) {
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') {
        throw new Exception('Bitte einen Namen für die Regel angeben');
    }
    if (mb_strlen($name) > 100) {
        throw new Exception('Der Name darf höchstens 100 Zeichen lang sein');
    }

    $days = array_values(array_unique(array_filter(
        array_map('intval', (array)($in['wochentage'] ?? [])),
        fn($d) => $d >= 1 && $d <= 7
    )));
    sort($days);
    if (!$days) {
        throw new Exception('Bitte mindestens einen Wochentag auswählen');
    }

    $ausserhalb = ($in['ausserhalb'] ?? 'nichts') === 'sonst' ? 'sonst' : 'nichts';
    $haltezeit = (int)($in['min_haltezeit'] ?? 0);
    if ($haltezeit < 0 || $haltezeit > 1440) {
        throw new Exception('Die Mindest-Haltezeit muss zwischen 0 und 1440 Minuten liegen');
    }

    $rule = [
        'name'          => $name,
        'beschreibung'  => mb_substr(trim((string)($in['beschreibung'] ?? '')), 0, 255),
        'aktiv'         => !empty($in['aktiv']) ? 1 : 0,
        'prioritaet'    => (int)($in['prioritaet'] ?? 100),
        'wochentage'    => implode(',', $days),
        'zeit_von'      => controlNormalizeTime($in['zeit_von'] ?? '00:00', 'Von'),
        'zeit_bis'      => controlNormalizeTime($in['zeit_bis'] ?? '00:00', 'Bis'),
        'ausserhalb'    => $ausserhalb,
        'min_haltezeit' => $haltezeit,
        'bedingungen'   => [],
        'dann'          => [],
        'sonst'         => [],
    ];

    foreach ((array)($in['bedingungen'] ?? []) as $i => $c) {
        $n = $i + 1;
        $op = $c['operator'] ?? '<';
        if (!in_array($op, ['<', '<=', '=', '>=', '>', '!='], true)) {
            throw new Exception("Bedingung $n: Ungültiger Operator");
        }
        $apiId = (int)($c['api_id'] ?? 0);
        if ($apiId <= 0) {
            throw new Exception("Bedingung $n: Bitte eine API-ID angeben");
        }
        $hystRaw = $c['hysterese'] ?? 0;
        $hyst = (trim((string)$hystRaw) === '') ? 0.0 : controlParseNumber($hystRaw, "Bedingung $n Hysterese");
        if ($hyst < 0) {
            throw new Exception("Bedingung $n: Die Hysterese darf nicht negativ sein");
        }
        $rule['bedingungen'][] = [
            'verknuepfung'   => (($c['verknuepfung'] ?? 'UND') === 'ODER' && $i > 0) ? 'ODER' : 'UND',
            'deviceId'       => (int)($c['deviceId'] ?? 0),
            'api_id'         => $apiId,
            'operator'       => $op,
            'vergleichswert' => controlParseNumber($c['vergleichswert'] ?? null, "Bedingung $n Vergleichswert"),
            'hysterese'      => $hyst,
        ];
    }

    foreach (['dann' => 'DANN', 'sonst' => 'SONST'] as $zweig => $label) {
        $seen = [];
        foreach ((array)($in[$zweig] ?? []) as $i => $a) {
            $n = $i + 1;
            $apiId = (int)($a['api_id'] ?? 0);
            if ($apiId <= 0) {
                throw new Exception("$label-Aktion $n: Bitte eine API-ID angeben");
            }
            $deviceId = (int)($a['deviceId'] ?? 0);
            $key = $deviceId . ':' . $apiId;
            if (isset($seen[$key])) {
                throw new Exception("$label-Aktion $n: Diese API-ID wird im $label-Zweig bereits gesetzt");
            }
            $seen[$key] = true;
            $rule[$zweig][] = [
                'deviceId' => $deviceId,
                'api_id'   => $apiId,
                'wert'     => controlParseNumber($a['wert'] ?? null, "$label-Aktion $n Wert"),
            ];
        }
    }

    if (!$rule['dann']) {
        throw new Exception('Bitte mindestens eine DANN-Aktion angeben');
    }
    if ($rule['ausserhalb'] === 'sonst' && !$rule['sonst']) {
        throw new Exception('"Außerhalb des Zeitfensters SONST ausführen" benötigt mindestens eine SONST-Aktion');
    }

    return $rule;
}

/**
 * Regel speichern ($id = null: neu anlegen). Gibt die ID zurück.
 * Beim Ändern wird der gemerkte Zustand (letzter Zweig) zurückgesetzt.
 */
function controlSaveRule($id, array $in) {
    ensureExtensionTables();
    $rule = controlValidateRule($in);
    $pdo = getDbConnection();

    $pdo->beginTransaction();
    try {
        if ($id) {
            $stmt = $pdo->prepare("
                UPDATE nibe_regeln SET name = ?, beschreibung = ?, aktiv = ?, prioritaet = ?, wochentage = ?,
                    zeit_von = ?, zeit_bis = ?, ausserhalb = ?, min_haltezeit = ?,
                    letzter_zweig = NULL, letzter_wechsel = NULL
                WHERE id = ?
            ");
            $stmt->execute([$rule['name'], $rule['beschreibung'], $rule['aktiv'], $rule['prioritaet'],
                $rule['wochentage'], $rule['zeit_von'], $rule['zeit_bis'], $rule['ausserhalb'],
                $rule['min_haltezeit'], (int)$id]);
            $check = $pdo->prepare("SELECT id FROM nibe_regeln WHERE id = ?");
            $check->execute([(int)$id]);
            if (!$check->fetch()) {
                throw new Exception('Regel nicht gefunden');
            }
            $pdo->prepare("DELETE FROM nibe_regel_bedingungen WHERE regel_id = ?")->execute([(int)$id]);
            $pdo->prepare("DELETE FROM nibe_regel_aktionen WHERE regel_id = ?")->execute([(int)$id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO nibe_regeln (name, beschreibung, aktiv, prioritaet, wochentage, zeit_von, zeit_bis, ausserhalb, min_haltezeit)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$rule['name'], $rule['beschreibung'], $rule['aktiv'], $rule['prioritaet'],
                $rule['wochentage'], $rule['zeit_von'], $rule['zeit_bis'], $rule['ausserhalb'],
                $rule['min_haltezeit']]);
            $id = (int)$pdo->lastInsertId();
        }

        $insC = $pdo->prepare("
            INSERT INTO nibe_regel_bedingungen (regel_id, position, verknuepfung, deviceId, api_id, operator, vergleichswert, hysterese)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($rule['bedingungen'] as $pos => $c) {
            $insC->execute([(int)$id, $pos, $c['verknuepfung'], $c['deviceId'], $c['api_id'],
                $c['operator'], $c['vergleichswert'], $c['hysterese']]);
        }

        $insA = $pdo->prepare("
            INSERT INTO nibe_regel_aktionen (regel_id, zweig, position, deviceId, api_id, wert)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach (['dann', 'sonst'] as $zweig) {
            foreach ($rule[$zweig] as $pos => $a) {
                $insA->execute([(int)$id, $zweig, $pos, $a['deviceId'], $a['api_id'], $a['wert']]);
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    return (int)$id;
}

function controlDeleteRule($id) {
    ensureExtensionTables();
    $stmt = getDbConnection()->prepare("DELETE FROM nibe_regeln WHERE id = ?");
    $stmt->execute([(int)$id]);
    return $stmt->rowCount() > 0;
}

/**
 * Regel aktivieren/deaktivieren. Beim Deaktivieren wird der Zustand zurückgesetzt.
 */
function controlSetRuleActive($id, $active) {
    ensureExtensionTables();
    $stmt = getDbConnection()->prepare("
        UPDATE nibe_regeln SET aktiv = ?, letzter_zweig = NULL, letzter_wechsel = NULL WHERE id = ?
    ");
    $stmt->execute([$active ? 1 : 0, (int)$id]);
}

/**
 * Regel kopieren (inaktiv, Name mit " (Kopie)").
 */
function controlDuplicateRule($id) {
    $rules = controlLoadRules(false, $id);
    if (!$rules) {
        throw new Exception('Regel nicht gefunden');
    }
    $r = $rules[0];
    $in = [
        'name'          => mb_substr($r['name'], 0, 91) . ' (Kopie)',
        'beschreibung'  => $r['beschreibung'],
        'aktiv'         => 0,
        'prioritaet'    => $r['prioritaet'],
        'wochentage'    => explode(',', $r['wochentage']),
        'zeit_von'      => $r['zeit_von'],
        'zeit_bis'      => $r['zeit_bis'],
        'ausserhalb'    => $r['ausserhalb'],
        'min_haltezeit' => $r['min_haltezeit'],
        'bedingungen'   => $r['bedingungen'],
        'dann'          => $r['dann'],
        'sonst'         => $r['sonst'],
    ];
    return controlSaveRule(null, $in);
}

// ----------------------------------------------------------------------------
// Datenpunkte lesen (Live von der Nibe-API, je Device einmal pro Durchlauf)
// ----------------------------------------------------------------------------

/**
 * Liest alle Datenpunkte eines Devices: api_id => Details (Werte in Anzeigeeinheit).
 * Das Ergebnis wird pro PHP-Prozess gecacht, ein Fehler ebenfalls (damit ein
 * nicht erreichbares Gerät pro Durchlauf nur einmal ein Timeout verursacht).
 */
function &controlPointCache() {
    static $cache = [];
    return $cache;
}

/**
 * Leert den Punkt-Cache (nur für Tests / lang laufende Prozesse nötig).
 */
function controlClearPointCache() {
    $cache = &controlPointCache();
    $cache = [];
}

function controlGetPoints($deviceId) {
    $cache = &controlPointCache();
    $deviceId = (int)$deviceId;

    if (!array_key_exists($deviceId, $cache)) {
        try {
            $url = API_BASE_URL . '/api/' . API_VERSION . '/devices/' . $deviceId . '/points';
            $raw = json_decode(fetchApiData($url, API_KEY, API_USERNAME, API_PASSWORD), true);
            if (!is_array($raw)) {
                throw new Exception('Ungültige Antwort der API');
            }
            $points = [];
            foreach ($raw as $key => $p) {
                $meta = $p['metadata'] ?? [];
                $apiId = (int)($meta['variableId'] ?? $key);
                $rawValue = $p['value']['integerValue'] ?? null;
                if ($rawValue === null) {
                    continue;
                }
                $divisor = max(1, (int)($meta['divisor'] ?? 1));
                $minRaw = (int)($meta['minValue'] ?? 0);
                $maxRaw = (int)($meta['maxValue'] ?? 0);
                $points[$apiId] = [
                    'api_id'   => $apiId,
                    'title'    => (string)($p['title'] ?? ''),
                    'raw'      => (int)$rawValue,
                    'divisor'  => $divisor,
                    'decimal'  => (int)($meta['decimal'] ?? 0),
                    'unit'     => (string)($meta['unit'] ?? ''),
                    'value'    => $rawValue / $divisor,
                    'writable' => !empty($meta['isWritable'])
                                  && ($meta['modbusRegisterType'] ?? '') === 'MODBUS_HOLDING_REGISTER',
                    'min'      => ($minRaw != 0 && $maxRaw != 0) ? $minRaw / $divisor : null,
                    'max'      => ($minRaw != 0 && $maxRaw != 0) ? $maxRaw / $divisor : null,
                ];
            }
            $cache[$deviceId] = ['points' => $points, 'error' => null];
        } catch (Exception $e) {
            $cache[$deviceId] = ['points' => [], 'error' => $e->getMessage()];
        }
    }

    if ($cache[$deviceId]['error'] !== null) {
        throw new Exception('Device ' . $deviceId . ' nicht lesbar: ' . $cache[$deviceId]['error']);
    }
    return $cache[$deviceId]['points'];
}

/**
 * Ein einzelner Datenpunkt; wirft eine Exception wenn Device oder Punkt fehlen.
 */
function controlGetPoint($deviceId, $apiId) {
    $points = controlGetPoints($deviceId);
    if (!isset($points[(int)$apiId])) {
        throw new Exception("API-ID {$apiId} auf Device {$deviceId} nicht gefunden");
    }
    return $points[(int)$apiId];
}

/**
 * Katalog für die Auswahllisten der Oberfläche: alle Devices mit ihren Datenpunkten.
 */
function controlPointCatalog() {
    $menu = [];
    try {
        $menu = getAllMenupunkte();
    } catch (Exception $e) {
    }

    $catalog = [];
    foreach (getAllDevices() as $dev) {
        $id = (int)$dev['deviceId'];
        $entry = ['deviceId' => $id, 'name' => $dev['name'], 'serialNumber' => $dev['serialNumber'],
                  'error' => null, 'points' => []];
        try {
            foreach (controlGetPoints($id) as $p) {
                $entry['points'][] = [
                    'id'       => $p['api_id'],
                    'title'    => $p['title'],
                    'menu'     => $menu[$p['api_id']] ?? '',
                    'value'    => $p['value'],
                    'display'  => controlFormatValue($p['value'], $p),
                    'unit'     => $p['unit'],
                    'decimal'  => $p['decimal'],
                    'writable' => $p['writable'],
                    'min'      => $p['min'],
                    'max'      => $p['max'],
                ];
            }
            usort($entry['points'], fn($a, $b) => $a['id'] <=> $b['id']);
        } catch (Exception $e) {
            $entry['error'] = $e->getMessage();
        }
        $catalog[] = $entry;
    }
    return $catalog;
}

function controlFormatValue($value, array $point) {
    return number_format((float)$value, (int)$point['decimal'], ',', '.') . ($point['unit'] !== '' ? ' ' . $point['unit'] : '');
}

// ----------------------------------------------------------------------------
// Auswertung
// ----------------------------------------------------------------------------

function controlTimeToMinutes($time) {
    $p = explode(':', $time);
    return ((int)$p[0]) * 60 + (int)($p[1] ?? 0);
}

/**
 * Liegt $now in Wochentagen + Zeitfenster der Regel?
 * von = bis: ganztägig. von > bis: Fenster über Mitternacht - der Wochentag
 * bezieht sich dann auf den Tag, an dem das Fenster beginnt.
 */
function controlInWindow(array $rule, DateTimeInterface $now) {
    $days = array_map('intval', explode(',', $rule['wochentage']));
    $von = controlTimeToMinutes($rule['zeit_von']);
    $bis = controlTimeToMinutes($rule['zeit_bis']);
    $nowMin = (int)$now->format('G') * 60 + (int)$now->format('i');
    $today = (int)$now->format('N');

    if ($von === $bis) {
        return in_array($today, $days, true);
    }
    if ($von < $bis) {
        return in_array($today, $days, true) && $nowMin >= $von && $nowMin < $bis;
    }
    // über Mitternacht
    if ($nowMin >= $von) {
        return in_array($today, $days, true);
    }
    if ($nowMin < $bis) {
        $yesterday = $today === 1 ? 7 : $today - 1;
        return in_array($yesterday, $days, true);
    }
    return false;
}

/**
 * Vergleich. $holding = Regel steht gerade im DANN-Zweig: die Hysterese
 * erweitert dann den Bereich, in dem die Bedingung wahr bleibt
 * (bei < und <= nach oben, bei > und >= nach unten).
 */
function controlCompare($value, $op, $threshold, $hysteresis, $holding) {
    $value = round($value, 3);
    switch ($op) {
        case '<':
            return $value < ($holding ? $threshold + $hysteresis : $threshold);
        case '<=':
            return $value <= ($holding ? $threshold + $hysteresis : $threshold);
        case '>':
            return $value > ($holding ? $threshold - $hysteresis : $threshold);
        case '>=':
            return $value >= ($holding ? $threshold - $hysteresis : $threshold);
        case '=':
            return abs($value - $threshold) < 0.0005;
        case '!=':
            return abs($value - $threshold) >= 0.0005;
    }
    return false;
}

/**
 * Bedingungen auswerten. UND bindet stärker als ODER.
 * Ist ein Datenpunkt nicht lesbar, ist sein Ergebnis "unbekannt" (null) und wird
 * dreiwertig verrechnet (falsch UND unbekannt = falsch, wahr ODER unbekannt = wahr).
 * Ergebnis null = nicht entscheidbar -> die Regel tut dann nichts.
 *
 * @return array ['ergebnis' => bool|null, 'details' => [...]]
 */
function controlEvalConditions(array $conditions, bool $holding) {
    if (!$conditions) {
        return ['ergebnis' => true, 'details' => []];
    }

    $details = [];
    $groups = [[]];
    foreach ($conditions as $i => $c) {
        $value = null;
        $error = null;
        $title = '';
        $unit = '';
        $decimal = 0;
        try {
            $p = controlGetPoint($c['deviceId'], $c['api_id']);
            $value = $p['value'];
            $title = $p['title'];
            $unit = $p['unit'];
            $decimal = $p['decimal'];
        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        $result = $value === null ? null
            : controlCompare($value, $c['operator'], $c['vergleichswert'], $c['hysterese'], $holding);

        $details[] = [
            'deviceId'       => $c['deviceId'],
            'api_id'         => $c['api_id'],
            'titel'          => $title,
            'verknuepfung'   => $i > 0 ? $c['verknuepfung'] : null,
            'operator'       => $c['operator'],
            'vergleichswert' => $c['vergleichswert'],
            'hysterese'      => $c['hysterese'],
            'wert'           => $value,
            'anzeige'        => $value === null ? null
                                : controlFormatValue($value, ['decimal' => $decimal, 'unit' => $unit]),
            'ergebnis'       => $result,
            'fehler'         => $error,
        ];

        if ($i > 0 && $c['verknuepfung'] === 'ODER') {
            $groups[] = [];
        }
        $groups[count($groups) - 1][] = $result;
    }

    $orResult = false;
    foreach ($groups as $group) {
        if (in_array(false, $group, true)) {
            $and = false;
        } elseif (in_array(null, $group, true)) {
            $and = null;
        } else {
            $and = true;
        }

        if ($and === true) {
            $orResult = true;
            break;
        }
        if ($and === null) {
            $orResult = null;
        }
    }

    return ['ergebnis' => $orResult, 'details' => $details];
}

/**
 * Wertet eine Regel aus (ohne etwas zu schreiben).
 *
 * @return array mit u.a. 'zweig' (dann|sonst|null), 'aktionen' (inkl. 'schreiben' = Wert weicht ab)
 */
function controlEvaluateRule(array $rule, DateTimeInterface $now) {
    $ev = [
        'regel_id'      => $rule['id'],
        'name'          => $rule['name'],
        'aktiv'         => (bool)$rule['aktiv'],
        'imZeitfenster' => false,
        'wunschZweig'   => null,
        'zweig'         => null,
        'gehalten'      => false,
        'bedingungen'   => [],
        'ergebnis'      => null,
        'aktionen'      => [],
        'hinweis'       => '',
    ];

    $last = $rule['letzter_zweig'] ?? null;
    $ev['imZeitfenster'] = controlInWindow($rule, $now);

    if (!$ev['imZeitfenster']) {
        if ($rule['ausserhalb'] === 'sonst') {
            $ev['wunschZweig'] = 'sonst';
            $ev['hinweis'] = 'Außerhalb des Zeitfensters: SONST-Aktionen';
        } else {
            $ev['hinweis'] = 'Außerhalb des Zeitfensters: keine Aktion';
        }
    } else {
        $eval = controlEvalConditions($rule['bedingungen'], $last === 'dann');
        $ev['bedingungen'] = $eval['details'];
        $ev['ergebnis'] = $eval['ergebnis'];
        if ($eval['ergebnis'] === true) {
            $ev['wunschZweig'] = 'dann';
        } elseif ($eval['ergebnis'] === false) {
            $ev['wunschZweig'] = 'sonst';
        } else {
            $ev['hinweis'] = 'Bedingung nicht auswertbar (Datenpunkt nicht lesbar): keine Aktion';
        }
    }

    // Mindest-Haltezeit: nicht zu schnell zwischen DANN und SONST wechseln
    $zweig = $ev['wunschZweig'];
    if ($zweig !== null && $last !== null && $zweig !== $last
        && $rule['min_haltezeit'] > 0 && !empty($rule['letzter_wechsel'])) {
        $until = (new DateTime($rule['letzter_wechsel']))->modify('+' . $rule['min_haltezeit'] . ' minutes');
        if ($now < $until) {
            $zweig = $last;
            $ev['gehalten'] = true;
            $ev['hinweis'] = 'Wechsel auf ' . strtoupper($ev['wunschZweig']) . ' gesperrt bis ' . $until->format('H:i')
                           . ' (Mindest-Haltezeit)';
        }
    }
    $ev['zweig'] = $zweig;

    if ($zweig !== null) {
        foreach ($rule[$zweig] as $a) {
            $ev['aktionen'][] = controlEvaluateAction($a);
        }
    }

    return $ev;
}

/**
 * Prüft eine Aktion gegen den aktuellen Wert des Ziel-Datenpunkts.
 */
function controlEvaluateAction(array $a) {
    $res = [
        'deviceId'     => $a['deviceId'],
        'api_id'       => $a['api_id'],
        'titel'        => '',
        'ziel'         => $a['wert'],
        'zielRaw'      => null,
        'zielScaled'   => null,
        'aktuell'      => null,
        'aktuellRaw'   => null,
        'anzeigeZiel'  => null,
        'anzeigeAktuell' => null,
        'schreiben'    => false,
        'fehler'       => null,
        'uebersteuertVon' => null,
    ];

    try {
        $p = controlGetPoint($a['deviceId'], $a['api_id']);
    } catch (Exception $e) {
        $res['fehler'] = $e->getMessage();
        return $res;
    }

    $res['titel'] = $p['title'];
    $res['aktuell'] = $p['value'];
    $res['aktuellRaw'] = $p['raw'];
    $res['zielRaw'] = (int)round($a['wert'] * $p['divisor']);
    $res['zielScaled'] = $res['zielRaw'] / $p['divisor'];
    $res['anzeigeAktuell'] = controlFormatValue($p['value'], $p);
    $res['anzeigeZiel'] = controlFormatValue($res['zielRaw'] / $p['divisor'], $p);

    if (!$p['writable']) {
        $res['fehler'] = 'API-ID ist nicht schreibbar';
    } elseif ($p['min'] !== null && ($res['zielRaw'] / $p['divisor'] < $p['min'] - 1e-9
                                  || $res['zielRaw'] / $p['divisor'] > $p['max'] + 1e-9)) {
        $res['fehler'] = 'Zielwert ' . $res['anzeigeZiel'] . ' liegt außerhalb des erlaubten Bereichs ('
                       . controlFormatValue($p['min'], $p) . ' bis ' . controlFormatValue($p['max'], $p) . ')';
    } else {
        $res['schreiben'] = ($res['zielRaw'] !== $p['raw']);
    }
    return $res;
}

/**
 * Wertet mehrere Regeln in Prioritätsreihenfolge aus. Zielt eine Aktion auf einen
 * Datenpunkt, den eine Regel mit höherer Priorität (kleinere Zahl) bereits
 * beansprucht hat, wird sie übersteuert und nicht ausgeführt.
 * Inaktive Regeln werden (für die Vorschau) mit ausgewertet, beanspruchen aber nichts.
 */
function controlEvaluateAll(array $rules, DateTimeInterface $now) {
    $claimed = [];
    $out = [];
    foreach ($rules as $rule) {
        $ev = controlEvaluateRule($rule, $now);
        if ($rule['aktiv'] && $ev['zweig'] !== null) {
            foreach ($ev['aktionen'] as &$a) {
                $key = $a['deviceId'] . ':' . $a['api_id'];
                if (isset($claimed[$key])) {
                    $a['uebersteuertVon'] = $claimed[$key];
                    $a['schreiben'] = false;
                    $a['fehler'] = null;
                } else {
                    $claimed[$key] = $rule['name'];
                }
            }
            unset($a);
        }
        $out[] = $ev;
    }
    return $out;
}

// ----------------------------------------------------------------------------
// Schreiben
// ----------------------------------------------------------------------------

/**
 * Sendet mehrere Werte eines Devices in einem PATCH an die Nibe-API.
 *
 * @param array $items Liste aus ['api_id' => int, 'raw' => int]
 */
function controlSendWrites($deviceId, array $items) {
    $url = API_BASE_URL . '/api/' . API_VERSION . '/devices/' . (int)$deviceId . '/points';

    $body = [];
    foreach ($items as $it) {
        $body[] = [
            'type'         => 'datavalue',
            'isOk'         => true,
            'variableId'   => (int)$it['api_id'],
            'integerValue' => (int)$it['raw'],
            'stringValue'  => '',
        ];
    }

    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if (!empty(API_USERNAME) && !empty(API_PASSWORD)) {
        $headers[] = 'Authorization: Basic ' . base64_encode(API_USERNAME . ':' . API_PASSWORD);
    } elseif (!empty(API_KEY)) {
        $headers[] = 'Authorization: Bearer ' . API_KEY;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, defined('API_TIMEOUT') ? API_TIMEOUT : 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $response = curl_exec($ch);
    $err = curl_errno($ch) ? curl_error($ch) : null;
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err !== null) {
        throw new Exception('cURL Fehler: ' . $err);
    }
    if ($http < 200 || $http >= 300) {
        $snippet = is_string($response) ? mb_substr(trim(strip_tags($response)), 0, 120) : '';
        throw new Exception('HTTP Fehler ' . $http . ($snippet !== '' ? ': ' . $snippet : ''));
    }
}

// ----------------------------------------------------------------------------
// Protokoll
// ----------------------------------------------------------------------------

function controlLog($rule, $zweig, $deviceId, $apiId, $old, $new, $status, $message, DateTimeInterface $now) {
    try {
        $stmt = getDbConnection()->prepare("
            INSERT INTO nibe_regel_log (regel_id, regel_name, zeitstempel, zweig, deviceId, api_id, alter_wert, neuer_wert, status, meldung)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$rule['id'], mb_substr($rule['name'], 0, 100), $now->format('Y-m-d H:i:s'), $zweig,
            $deviceId, $apiId, $old, $new, $status, mb_substr($message, 0, 255)]);
    } catch (Exception $e) {
        debugLog('Regel-Protokoll konnte nicht geschrieben werden', ['error' => $e->getMessage()], 'ERROR');
    }
}

/**
 * Wurde dieser Zielwert für den Datenpunkt gerade erst gesendet (oder ist der Versuch
 * gerade erst fehlgeschlagen)? Verhindert, dass bei einer Anlage, die einen Wert nicht
 * übernimmt oder sofort selbst zurücksetzt, jede Minute neu gesendet wird.
 * Es zählt nur der LETZTE Eintrag für diesen Datenpunkt: ein Wechsel 1 -> 0 -> 1
 * wird deshalb nicht blockiert.
 */
function controlRecentlyTried($deviceId, $apiId, $targetValue, DateTimeInterface $now) {
    $minutes = (int)controlSetting('CONTROL_RESEND_MINUTES', 10);
    if ($minutes <= 0) {
        return false;
    }
    $stmt = getDbConnection()->prepare("
        SELECT neuer_wert, zeitstempel FROM nibe_regel_log
        WHERE deviceId = ? AND api_id = ? AND status IN ('gesendet', 'fehler')
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([(int)$deviceId, (int)$apiId]);
    $last = $stmt->fetch();
    if (!$last || $last['neuer_wert'] === null) {
        return false;
    }
    if (abs((float)$last['neuer_wert'] - $targetValue) >= 0.0005) {
        return false;
    }
    $until = (new DateTime($last['zeitstempel']))->modify("+{$minutes} minutes");
    return $now < $until;
}

// ----------------------------------------------------------------------------
// Hauptlauf (Cronjob)
// ----------------------------------------------------------------------------

/**
 * Führt alle aktiven Regeln aus.
 *
 * @param array $opts dryRun (bool), ruleId (int|null), now (DateTime|null), log (callable|null)
 * @return array Zusammenfassung
 */
function controlRun(array $opts = []) {
    $dryRun = !empty($opts['dryRun']);
    $log = $opts['log'] ?? function ($msg, $type = 'INFO') {};
    $now = $opts['now'] ?? new DateTime('now');

    $summary = ['rules' => 0, 'sent' => 0, 'unchanged' => 0, 'errors' => 0, 'overridden' => 0, 'throttled' => 0];

    $rules = controlLoadRules(true, $opts['ruleId'] ?? null);
    $summary['rules'] = count($rules);
    if (!$rules) {
        $log('Keine aktiven Regeln', 'INFO');
        return $summary;
    }

    $evals = controlEvaluateAll($rules, $now);
    $pdo = getDbConnection();
    $writes = []; // deviceId => [ ['api_id','raw','alt','neu','rule','zweig','titel'] ]

    foreach ($rules as $idx => $rule) {
        $ev = $evals[$idx];
        $tag = "Regel #{$rule['id']} \"{$rule['name']}\"";

        $state = $ev['imZeitfenster'] ? 'im Zeitfenster' : 'außerhalb Zeitfenster';
        $log("$tag: $state, Zweig " . ($ev['zweig'] ? strtoupper($ev['zweig']) : '-')
            . ($ev['hinweis'] !== '' ? ' (' . $ev['hinweis'] . ')' : ''), 'MONITOR');
        foreach ($ev['bedingungen'] as $c) {
            $log('  WENN ' . ($c['verknuepfung'] ? $c['verknuepfung'] . ' ' : '')
                . "[{$c['deviceId']}] {$c['api_id']} {$c['titel']}: " . ($c['anzeige'] ?? 'n/a')
                . " {$c['operator']} " . $c['vergleichswert']
                . ($c['hysterese'] > 0 ? " (Hysterese {$c['hysterese']})" : '')
                . ' => ' . ($c['ergebnis'] === null ? 'UNBEKANNT' : ($c['ergebnis'] ? 'wahr' : 'falsch'))
                . ($c['fehler'] ? ' - ' . $c['fehler'] : ''), 'DEBUG');
        }

        foreach ($ev['aktionen'] as $a) {
            $name = "[{$a['deviceId']}] {$a['api_id']} {$a['titel']}";
            if ($a['uebersteuertVon']) {
                $summary['overridden']++;
                $log("  $name: übersteuert von Regel \"{$a['uebersteuertVon']}\"", 'WARNING');
            } elseif ($a['fehler']) {
                $summary['errors']++;
                $log("  $name: {$a['fehler']}", 'ERROR');
                if (!$dryRun && !controlRecentlyTried($a['deviceId'], $a['api_id'], $a['ziel'], $now)) {
                    controlLog($rule, $ev['zweig'], $a['deviceId'], $a['api_id'], $a['aktuell'], $a['ziel'], 'fehler', $a['fehler'], $now);
                }
            } elseif (!$a['schreiben']) {
                $summary['unchanged']++;
                $log("  $name: bereits {$a['anzeigeAktuell']} - nichts zu tun", 'DEBUG');
            } elseif (!$dryRun && controlRecentlyTried($a['deviceId'], $a['api_id'], $a['zielScaled'], $now)) {
                $summary['throttled']++;
                $log("  $name: Zielwert {$a['anzeigeZiel']} wurde vor Kurzem bereits gesendet - warte "
                    . controlSetting('CONTROL_RESEND_MINUTES', 10) . ' Min. vor erneutem Versuch', 'WARNING');
            } else {
                $log("  $name: {$a['anzeigeAktuell']} -> {$a['anzeigeZiel']}" . ($dryRun ? ' (DRY-RUN, nicht gesendet)' : ''), 'SUCCESS');
                $writes[$a['deviceId']][] = [
                    'api_id' => $a['api_id'], 'raw' => $a['zielRaw'], 'alt' => $a['aktuell'],
                    'neu' => $a['zielScaled'],
                    'rule' => $rule, 'zweig' => $ev['zweig'], 'titel' => $a['titel'],
                ];
            }
        }
    }

    // Senden (ein PATCH je Device)
    if (!$dryRun) {
        foreach ($writes as $deviceId => $items) {
            try {
                controlSendWrites($deviceId, $items);
                foreach ($items as $it) {
                    $summary['sent']++;
                    controlLog($it['rule'], $it['zweig'], $deviceId, $it['api_id'], $it['alt'], $it['neu'], 'gesendet',
                        "{$it['titel']}: {$it['alt']} -> {$it['neu']}", $now);
                    // Im Werte-Log als "durch Regel geändert" markieren (cwna = 'R')
                    logManualWrite($it['api_id'], $it['raw'], $deviceId, 'R');
                }
                $log("Device $deviceId: " . count($items) . ' Wert(e) gesendet', 'SUCCESS');
            } catch (Exception $e) {
                foreach ($items as $it) {
                    $summary['errors']++;
                    controlLog($it['rule'], $it['zweig'], $deviceId, $it['api_id'], $it['alt'], $it['neu'], 'fehler',
                        'Senden fehlgeschlagen: ' . $e->getMessage(), $now);
                }
                $log("Device $deviceId: Senden fehlgeschlagen - " . $e->getMessage(), 'ERROR');
            }
        }

        // Zustand der Regeln merken (Hysterese, Haltezeit)
        $upd = $pdo->prepare("UPDATE nibe_regeln SET letzte_auswertung = ? WHERE id = ?");
        $updState = $pdo->prepare("UPDATE nibe_regeln SET letzter_zweig = ?, letzter_wechsel = ?, letzte_auswertung = ? WHERE id = ?");
        foreach ($rules as $idx => $rule) {
            $ev = $evals[$idx];
            // Ist der Wunschzweig wegen fehlender Daten unbekannt, den bisherigen Zustand behalten
            $unknown = $ev['zweig'] === null && $ev['imZeitfenster'];
            if (!$unknown && $ev['zweig'] !== ($rule['letzter_zweig'] ?? null)) {
                $updState->execute([$ev['zweig'], $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $rule['id']]);
                $from = $rule['letzter_zweig'] ? strtoupper($rule['letzter_zweig']) : '-';
                $to = $ev['zweig'] ? strtoupper($ev['zweig']) : 'inaktiv (außerhalb Zeitfenster)';
                controlLog($rule, $ev['zweig'], null, null, null, null, 'info', "Zweig gewechselt: $from -> $to", $now);
            } else {
                $upd->execute([$now->format('Y-m-d H:i:s'), $rule['id']]);
            }
        }

        // Alte Protokolleinträge aufräumen (einmal pro Stunde reicht)
        if ((int)$now->format('i') === 7) {
            $days = (int)controlSetting('CONTROL_LOG_RETENTION_DAYS', 90);
            if ($days > 0) {
                $pdo->prepare("DELETE FROM nibe_regel_log WHERE zeitstempel < ?")
                    ->execute([(clone $now)->modify("-{$days} days")->format('Y-m-d H:i:s')]);
            }
        }
    }

    return $summary;
}

// ----------------------------------------------------------------------------
// Lock + Lebenszeichen für den Cronjob
// ----------------------------------------------------------------------------

/**
 * Setzt den Steuerungs-Lock atomar. Ein Lock gilt höchstens $maxAge Sekunden
 * (falls ein Lauf abgestürzt ist).
 */
function controlAcquireLock($maxAge = 120) {
    ensureExtensionTables();
    $pdo = getDbConnection();
    $now = time();
    $pdo->exec("INSERT IGNORE INTO nibe_system_config (config_key, value) VALUES ('control_lock', '0')");
    $stmt = $pdo->prepare("
        UPDATE nibe_system_config SET value = ?
        WHERE config_key = 'control_lock' AND (value = '0' OR CAST(value AS UNSIGNED) < ?)
    ");
    $stmt->execute([(string)$now, $now - $maxAge]);
    return $stmt->rowCount() > 0;
}

function controlReleaseLock() {
    try {
        getDbConnection()->exec("UPDATE nibe_system_config SET value = '0' WHERE config_key = 'control_lock'");
    } catch (Exception $e) {
    }
}

function controlTouchLastRun() {
    $stmt = getDbConnection()->prepare("
        INSERT INTO nibe_system_config (config_key, value) VALUES ('control_last_run', ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value)
    ");
    $stmt->execute([(string)time()]);
}

function controlGetLastRun() {
    ensureExtensionTables();
    $stmt = getDbConnection()->query("SELECT value FROM nibe_system_config WHERE config_key = 'control_last_run'");
    $v = (int)$stmt->fetchColumn();
    return $v > 0 ? $v : null;
}
?>
