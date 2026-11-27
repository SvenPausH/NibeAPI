# NibeAPI 3.5.0 – Gespeicherte Auswahlen und Steuerung

## Neue / geänderte Dateien

| Datei | Neu/geändert | Zweck |
|---|---|---|
| `nibeapi/db_update_3.5.0.sql` | neu | Tabellen (werden auch automatisch angelegt) |
| `nibeapi/selections.php` | neu | Auswahlen: DB-Funktionen, Tabellen-Installer |
| `nibeapi/control-engine.php` | neu | Regel-Engine (Cron und Oberfläche nutzen dieselbe Auswertung) |
| `nibeapi/control-cron.php` | neu | **Cronjob, jede Minute** |
| `nibeapi/steuerung.php` | neu | Steuerungsseite |
| `nibeapi/ajax-handlers-extra.php` | neu | AJAX für Auswahlen und Steuerung |
| `nibeapi/assets/steuerung.js` / `.css` | neu | Oberfläche der Steuerungsseite |
| `nibeapi/ajax-handlers.php` | geändert | bindet `ajax-handlers-extra.php` ein (3 Zeilen) |
| `nibeapi/index.php` | geändert | Auswahl-Leiste, Button „Steuerung“ |
| `nibeapi/assets/script.js` / `styles.css` | geändert | Auswahl-Funktionen, Styles |
| `nibeapi/database.php` | geändert | `logManualWrite()` hat optionalen 4. Parameter (Kennzeichen) |
| `nibeapi/history.php` | geändert | Filter/Badge „Regel“ |
| `nibeapi/_config.php` | geändert | neue Einstellungen `CONTROL_*` |
| `tests/` | neu | Mock-Nibe-API und Integrationstest |

## Installation

1. Dateien einspielen. In die eigene `config.php` die drei Zeilen aus dem Block „STEUERUNG“ der `_config.php` übernehmen (ohne sie gelten die Standardwerte).
2. Tabellen: entweder `db_update_3.5.0.sql` einspielen, oder einfach `index.php` / `steuerung.php` einmal öffnen. Fehlende Tabellen werden automatisch angelegt (der DB-Benutzer braucht dafür CREATE-Rechte).
3. Cronjob ergänzen (zusätzlich zum bestehenden):

       * * * * * /usr/bin/php /pfad/zu/nibeapi/control-cron.php >/dev/null 2>&1

4. Auf der Steuerungsseite Regeln anlegen. Oben zeigt ein Banner, ob der Cronjob läuft.

Testen ohne etwas zu senden: `php control-cron.php --dry-run` (zeigt jede Bedingung, jeden aktuellen Wert und was gesendet würde). Mit `--now="2026-12-01 23:30"` lässt sich eine andere Uhrzeit simulieren.

## Gespeicherte Auswahlen (Übersichtsseite)

Datenpunkte mit den Häkchen markieren → **➕ Speichern unter…** → Namen vergeben. Danach im Dropdown wählen: die Häkchen werden gesetzt und „Nur ausgewählte anzeigen“ wird aktiviert. **Bearbeiten** = Häkchen ändern und **💾 Speichern** (die Leiste zeigt „● Ungespeicherte Änderungen“). **✏️ Umbenennen** und **🗑️ Löschen** wirken auf die gewählte Auswahl. Auswahlen gehören zu einem Gerät, weil sich die Datenpunkt-IDs je Gerätetyp unterscheiden.

## Steuerung

Eine Regel besteht aus:

* **Wann:** Wochentage + Uhrzeit von–bis (über Mitternacht möglich), optional „außerhalb des Zeitfensters SONST ausführen“
* **WENN:** Bedingungen auf beliebige Datenpunkte beliebiger Geräte, verknüpft mit UND/ODER (UND bindet stärker)
* **DANN / SONST:** Datenpunkte, die auf einen Wert gesetzt werden

Regeln speichern ausschließlich die **API-ID**, nie den Namen. Der Name wird nur zur Anzeige live von der Anlage gelesen, ein Namenswechsel nach einem Nibe-Update ändert an den Regeln nichts.

Gesendet wird **nur, wenn der aktuelle Wert vom Zielwert abweicht.** Kann ein Datenpunkt nicht gelesen werden, passiert nichts.

Gegen Flattern gibt es die **Hysterese** (je Bedingung) und die **Mindest-Haltezeit** (je Regel). Bei mehreren Regeln auf denselben Datenpunkt gewinnt die kleinere **Priorität**. Alle Werte werden in der Anzeigeeinheit angegeben (z. B. 45,5 °C, Gradminuten −200).

### Beispiel für deinen Fall (S135 hilft nachts, S2125 läuft durch)

Datenpunkte legst du über ihre **API-ID** fest (Spalte „API ID“ der Übersicht); die 🔍-Suche findet sie über Name oder Menüpunkt. Die Schwellen sind Startwerte zum Ausprobieren:

**Regel „S135 Nachthilfe“** – täglich 22:00–06:00, Mindest-Haltezeit 60 Min.
* WENN S2125 · *Gradminuten* **<** −300 (Hysterese 100) UND S2125 · *Außentemperatur* **<** 0
* DANN S135 · *(Datenpunkt zum Freigeben/Einschalten)* = Wert „an“
* SONST S135 · *(derselbe Datenpunkt)* = Wert „aus“
* Außerhalb des Zeitfensters: SONST ausführen (S135 geht morgens sicher aus)

Wirkung: Reicht die S2125 allein nicht mehr (Gradminuten fallen, z. B. wegen Abtauens), steigt die S135 ein. Erholen sich die Gradminuten um mehr als die Hysterese, geht sie nach der Haltezeit wieder aus, und die S2125 läuft allein weiter. Damit die S2125 durchläuft und nicht taktet, setzt du in einer zweiten Regel mit höherer Priorität (kleinere Zahl) das, was die S2125 braucht.

Welcher Datenpunkt das Ein-/Ausschalten bzw. Sperren einer Wärmepumpe bei deinem Modell tatsächlich ist, musst du im Modbus-/Punkte-Verzeichnis deiner S2125/S135 nachsehen. Ich kenne die Register deiner Anlage nicht und habe keine geraten. Prüfe die Regel zuerst mit `--dry-run` und der „🔍 Live prüfen“-Anzeige.

## Wichtige Hinweise

* **Keine Anmeldung:** Wie die bestehenden Seiten hat auch `steuerung.php` keinen Zugriffsschutz, kann aber Werte an die Anlage senden. Bitte per `.htaccess`/Basic-Auth oder Netzwerk absichern.
* **Fehler im Repo behoben:** `assets/script.js` enthielt eine abgeschnittene Zeile (`undoBtn.className = 'btn`), die einen JavaScript-Syntaxfehler verursachte. In `_config.php` fehlte ein Slash (`/ E-Mail Benachrichtigungen`). Beides ist korrigiert.
* Änderungen durch Regeln erscheinen im Werte-Log mit Kennzeichen `R` („REGEL“, in der Historie filterbar).
* Das Protokoll der Steuerung (`nibe_regel_log`) speichert nur echte Ereignisse (gesendet, Fehler, Zweigwechsel), keine Einträge jede Minute, und wird nach `CONTROL_LOG_RETENTION_DAYS` Tagen bereinigt.

## Tests

    php -S 127.0.0.1:8443 tests/mock-nibe-api.php &
    # config.php auf http://127.0.0.1:8443 und eine Test-Datenbank stellen
    php tests/test-control.php

Achtung: der Test leert die Tabellen `nibe_regel*` und `nibe_auswahl*` der konfigurierten Datenbank.

## Wenn die automatische Tabellen-Anlage nicht klappt

Die Tabellen werden angelegt, sobald `index.php`, `steuerung.php` oder `control-cron.php` laufen und sie fehlen. Voraussetzungen: die Datei `db_update_3.5.0.sql` liegt im selben Ordner wie `selections.php`, und der DB-Benutzer aus der `config.php` darf `CREATE TABLE`. Falls nicht, steht die genaue Ursache in einem roten Hinweis oben auf der Seite. Dann die SQL-Datei einmal manuell einspielen (z. B. in phpMyAdmin, Reiter „SQL“ bzw. „Importieren“).
