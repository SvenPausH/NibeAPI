# NibeAPI 
Nibe REST API für Nibe S Geräte

Anzeige der Datenpunkte über die REST API.
Wenn das schreiben erlaubt ist können die Daten auch geändert werden. 
Das ändern wird immer angeboten egal ob das ändern in der Steuerung erlaubt wurde oder nicht.

## Einrichten der lokalen REST API 
Ihr stellt euch vor eure WP und klickt euch zu Menüpunkt 7.5.15

* Schieberegler oben auf An stellen
* Benutzername: den braucht Ihr dann für die Anmeldung am REST-Server
* Passwort: siehe Benutzername
* IP-Adressenbeschränkung: Vielleicht dem ein oder andern schon von den Modbus TCP Settings bekannt, es kann durchaus sinnvoll sein hier den Zugriff auf die IP einzuschränken, von der Ihr dann auch auf REST zugreift
* Vertrauensw. IP: Die IP Adresse des Gerätes, von dem Ihr auf die REST API zugreifen wollt, falls ihr die Adressenbeschränkung aktiviert habt
* Nur Lesen von REST API: Sollte man für initiale Tests erstmal anschalten, falls man später bestimmte Werte auch schreiben will muss man den Teil deaktivieren


Für die Ausführung wird ein Webserver mit PHP benötigt.
Kopiert die beiden Dateien config.php und index.php in ein Verzeichnis. 
Bei einem Neuen Server direkt in das root Verzeichnis des Webservers.

In der config.php müssen 4 Parameter angepasst werden:

* API_URL       hier sollte es reichen wenn die IP Adresse geändert wird.
* API_BASE_URL  hier sollte es reichen wenn die IP Adresse geändert wird.
* API_USERNAME  wird in der Nibesteuerung festgelegt
* API_PASSWORD  wird in der Nibesteuerung festgelegt

Wenn die Datenbank genutzt werden soll müssen noch die Anpassungen für die Maria oder Mysql Datenbank gemacht werden. 

### Version 3 ist eine Datenbankfunktion dazu gekommen.
In die Datenbank werden alle schreibaren Datenpunkte geschrieben. 
Wenn sich ein Wert ändert wird diese mit Zeitstempel in die Log Tabelle geschrieben.
Wird ein Wert mit NibeApi geändert ist dieser Wert mit Markiert.
So kann unterschieden werden ein Wert mit der Anwendung oder an der Steuerung bzw. mit der App geändert wurde.
Nibe ändert schreibbare Werte auch selbst. Bei mir sind es 2 Werte mit Gartminuten. Diese können mit dem Parameter NO_DB_UPDATE_APIID ausgeschlossen werden damit das Log nicht unnötig voll läuft.
Die Api wird alle 10 Sekunden gelesen. Der Upate Interval ist in der config.php einstellbar.

### Version 3.2.00
- ✨ PHP Datei wurde in mehrere Dateien aufgeteil damit die Wartbarkeit verbessert wird.
- ✨ Edit-Modal für Wertänderungen
- ✨ History mit Undo-Funktion
- ✨ Import-Funktion
- ✨ Dynamisches Update-Intervall
- ✨ Persistente Sortierung
- 🐛 Diverse Bugfixes
- 

<img width="1427" height="779" alt="API Datenpunkte History" src="https://github.com/SvenPausH/NibeAPI/blob/main/nibeapi_v3_2_uebersicht.png" />
<img width="1427" height="779" alt="API Datenpunkte" src="https://github.com/SvenPausH/NibeAPI/blob/main/nibeapi_v3_2_history.png" />



