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

<img width="1427" height="779" alt="API Datenpunkte" src="https://github.com/SvenPausH/NibeAPI/blob/main/API%20Datenpunkte%20v2.png" />
