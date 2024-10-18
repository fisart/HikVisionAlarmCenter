# ProcessCameraEvents Modul für IP-Symcon

Dieses Modul integriert HIKVISION-Kameraereignisse in das IP-Symcon-Heimautomationssystem. Es verarbeitet Bewegungsmelder-Benachrichtigungen, lädt Schnappschüsse herunter und verwaltet Variablen und Medienobjekte in IP-Symcon zur Automatisierung und Visualisierung.

---

## Inhaltsverzeichnis

- [Einleitung](#einleitung)
- [Funktionen](#funktionen)
- [Anforderungen](#anforderungen)
- [Installation](#installation)
- [Konfiguration](#konfiguration)
  - [Modulkonfigurationsformular](#modulkonfigurationsformular)
  - [Kameraeinrichtung](#kameraeinrichtung)
- [Nutzung](#nutzung)
  - [Verarbeitung von Bewegungsmeldungen](#verarbeitung-von-bewegungsmeldungen)
  - [Aktivieren/Deaktivieren der Bewegungsmelder](#aktivieren-deaktivieren-der-bewegungsmelder)
- [Methoden](#methoden)
  - [Öffentliche Methoden](#öffentliche-methoden)
  - [Private Methoden](#private-methoden)
- [Fehlerbehebung](#fehlerbehebung)
- [Sicherheitsüberlegungen](#sicherheitsüberlegungen)
- [Beitrag](#beitrag)
- [Lizenz](#lizenz)

---

## Einleitung

Das `ProcessCameraEvents`-Modul ist dafür ausgelegt, Ereignisse von HIKVISION- und ANNKE-Kameras zu empfangen und zu verarbeiten. Es hört über einen Webhook auf Bewegungsmeldungen, verarbeitet die Ereignisdaten, lädt Schnappschüsse herunter und aktualisiert IP-Symcon-Variablen und Medienobjekte entsprechend.

## Funktionen

- **Webhook-Integration:** Registriert einen Webhook, um Bewegungsmeldungen von HIKVISION- und ANNKE-Kameras zu empfangen.
- **Ereignisverarbeitung:** Parst XML-Ereignisbenachrichtigungen und extrahiert relevante Daten.
- **Schnappschuss-Download:** Lädt bei Bewegungsmeldungen Kamera-Schnappschüsse herunter.
- **Variablenverwaltung:** Erstellt und aktualisiert dynamisch Variablen und Medienobjekte in IP-Symcon.
- **Bewegungsmelder-Steuerung:** Aktiviert oder deaktiviert die Bewegungsmelder aller Kameras über einen einzigen Schalter.
- **Egg Timer-Integration:** Verwendet das Egg Timer-Modul, um Bewegungsstatus-Zustände zu verwalten.

## Anforderungen

- **IP-Symcon:** Version mit Modulunterstützung.
- **HIKVISION- oder ANNKE-Kameras:** Kameras, die Ereignisbenachrichtigungen senden können.
- **Egg Timer-Modul:** Installierbar über den IP-Symcon Module Store.
- **Netzwerkzugriff:** Eine ordnungsgemäße Netzwerkkonfiguration, um die Kommunikation zwischen IP-Symcon und den Kameras zu ermöglichen.

## Installation

1. **Repository klonen oder herunterladen:**

   ```bash
   git clone https://github.com/yourusername/ProcessCameraEvents.git
   ```

2. **Modul in IP-Symcon installieren:**

   - Öffne die IP-Symcon-Verwaltungskonsole.
   - Navigiere zu **Module**.
   - Klicke auf **Hinzufügen** und gib den Pfad zum geklonten Repository ein.

3. **Abhängigkeiten installieren:**

   - **Egg Timer-Modul:**
     - Öffne den Module Store in IP-Symcon.
     - Suche nach **Egg Timer**.
     - Installiere das Modul.

## Konfiguration

### Modulkonfigurationsformular

Das Modul bietet ein Konfigurationsformular in IP-Symcon zum Einrichten der notwendigen Parameter. Im Folgenden findest du eine detaillierte Erklärung zu jedem Element im Konfigurationsformular:

#### Beschriftungen und Hinweise

- **Kompatibilitäts-Hinweis:**
  - *Dieses Modul funktioniert nur mit HIKVISION- und ANNKE-Kameras. Zuerst müssen die Smart Events in der Kamera eingerichtet werden.*
- **Authentifizierungseinstellungen:**
  - *Hinweis: In der Kamerakonfiguration muss die `System/Sicherheit/Web-Authentifizierung` auf `digest/basic` eingestellt sein.*
- **Webhook-Konfiguration:**
  - *Anschließend muss der Webhook-Aufruf im Kamera-Alarmserver (erweiterte Netzwerkeinstellungen) eingerichtet werden.*
- **Variablenerstellung:**
  - *Nachdem eine HIKVISION-Kamera ein Ereignis an den Symcon-Webhook gesendet hat, wird für jede Kamera ein Satz von Variablen erstellt.*
- **Variablenstruktur:**
  - *Die oberste Variable ist der Kamera-Name. Darunter findest du die IP-Adresse, Datum und Uhrzeit des Ereignisses, Passwort, Benutzername und das Bild des letzten Ereignisses.*
- **Kamera-Zugangsdaten:**
  - *Falls deine Kameras unterschiedliche Passwörter/Benutzer-IDs haben, müssen diese Daten in die entsprechende Variable eingegeben werden.*
- **Egg Timer-Modul erforderlich (wichtig):**
  - **Hinweis: Um dieses Modul verwenden zu können, muss zuerst das Egg Timer-Modul aus dem IP-Symcon Module Store installiert werden.**

#### Konfigurationsparameter

- **Webhook-Name:** (`WebhookName`, Standard: `HIKVISION_EVENTS`)
  - Der Name des Webhook-Endpunkts, der Ereignisse von den Kameras empfängt.
- **Kanal-ID:** (`ChannelId`, Standard: `101`)
  - Die Kamera-Kanal-ID, die zum Abrufen von Schnappschüssen verwendet wird.
- **Speicherpfad:** (`SavePath`, Standard: `/user/`)
  - Der relative Pfad, in dem Schnappschüsse im IP-Symcon-Verzeichnis gespeichert werden.
- **Subnetz:** (`Subnet`, Standard: `192.168.50.`)
  - Das Subnetz deines Kameranetzwerks. Wird verwendet, um Kameras zu entdecken und IP-Adressen zu verwalten.
- **Benutzername:** (`UserName`, Standard: `NotSet`)
  - Der Standardbenutzername für den Zugriff auf die Kameras. Wenn einzelne Kameras unterschiedliche Zugangsdaten haben, müssen diese in den nach der Ereignisverarbeitung erstellten Variablen festgelegt werden.
- **Passwort:** (`Password`, Standard: `NotSet`)
  - Das Standardpasswort für den Zugriff auf die Kameras.
- **Dauer der Bewegungsereignisse in Sekunden:** (`MotionActive`, Standard: `30`)
  - Die Dauer in Sekunden, für die das Bewegungsereignis aktiv bleibt.
- **Debug-Nachrichten:** (`debug`, Standard: `false`)
  - Aktiviert oder deaktiviert die Protokollierung von Debug-Meldungen für das Modul.

#### Statusanzeigen

Das Modul stellt Statuscodes bereit, um seinen aktuellen Zustand anzuzeigen:

- **102:** Instanz ist aktiv.
- **104:** Instanz ist inaktiv.
- **101:** Instanz wird erstellt.
- **103:** Instanz wird gelöscht.
- **105:** Instanz wurde nicht erstellt.
- **200:** Instanz befindet sich im Fehlerzustand.

### Kameraeinrichtung

1. **Smart Events in Kameras einrichten:**

   - Greife auf die Weboberfläche deiner HIKVISION- oder ANNKE-Kamera zu.
   - Navigiere zum Abschnitt **Smart Events**.
   - Aktiviere und konfiguriere die gewünschten Smart Events (z.B. Bewegungsmelder).

2. **Web-Authentifizierung auf Digest/Basic einstellen:**

   - In den Kameraeinstellungen gehe zu `System > Sicherheit > Web-Authentifizierung`.
   - Stelle den Authentifizierungsmodus auf `digest/basic` ein.

3. **Webhook im Kamera-Alarmserver konfigurieren:**

   - In den erweiterten Netzwerkeinstellungen der Kamera finde die Konfiguration des **Alarmservers**.
   - Stelle den Webhook-Aufruf so ein, dass er auf deinen IP-Symcon-Server verweist:

     ```
     http://<ip-symcon-server-adresse>/hook/<WebhookName>
     ```

4. **Zugangsdaten für einzelne Kameras festlegen (falls erforderlich):**

   - Wenn deine Kameras unterschiedliche Benutzernamen oder Passwörter haben, können diese in den nach dem ersten Ereignis erstellten Variablen festgelegt werden.

5. **Egg Timer-Modul installieren (Pflicht):**

   - Bevor dieses Modul verwendet wird, installiere das **Egg Timer**-Modul aus dem IP-Symcon Module Store.
   - Der Egg Timer ist entscheidend für die Verwaltung des Bewegungsstatus und des Timings.

## Nutzung

### Verarbeitung von Bewegungsmeldungen

Wenn ein Bewegungsereignis auftritt:

- Die Kamera sendet eine XML-Benachrichtigung an den Webhook.
- Das Modul verarbeitet die eingehenden Daten in `ProcessHookData()`.
- Variablen und Medienobjekte werden erstellt oder aktualisiert:
  - **Kamera-Name (oberste Ebene):**
    - Repräsentiert den Namen der Kamera.
  - **Ereignisbeschreibung:**
    - Details zum Bewegungsereignis.
  - **Datum und Uhrzeit des Ereignisses:**
    - Zeitstempel des Bewegungsereignisses.
  - **IP-Adresse:**
    - Die IP-Adresse der Kamera.
  - **Passwort:**
    - Kamera-Passwort (falls abweichend vom Standard).
  - **Benutzername:**
    - Kamera-Ben

utzername (falls abweichend vom Standard).
  - **Bild des letzten Ereignisses:**
    - Schnappschuss, der während des Bewegungsereignisses aufgenommen wurde.

- Ein Egg Timer wird gestartet, um den Bewegungsstatus zu verwalten.
- Der Bewegungsstatus wird nach der festgelegten Dauer zurückgesetzt.

### Aktivieren/Deaktivieren der Bewegungsmelder

- Verwende die Variable `Activate_all_Cameras`, um die Bewegungsmelder aller Kameras zu aktivieren oder zu deaktivieren.
- Durch Ändern dieser Variable wird `RequestAction()` ausgelöst, das `ExecuteMotionDetectionAPI()` aufruft, um die Kameraeinstellungen über deren API zu aktualisieren.

## Methoden

### Öffentliche Methoden

#### `Create()`

Initialisiert das Modul, registriert Eigenschaften und Variablen und stellt sicher, dass der Webhook eingerichtet ist.

#### `ApplyChanges()`

Registriert den Webhook neu, wenn sich die Eigenschaften ändern.

#### `ProcessHookData()`

Verarbeitet eingehende Webhook-Daten:

- Liest den Eingabestream.
- Bestimmt, ob es sich um Dateidaten oder POST-Daten handelt.
- Parst XML und extrahiert Bewegungsereignisdaten.
- Ruft `handleMotionData()` auf.

#### `RequestAction($Ident, $Value)`

Verarbeitet Aktionen für interaktive Variablen:

- Für `Activate_all_Cameras` aktualisiert es den Bewegungsmelderstatus für alle Kameras.

#### `Destroy()`

Bereinigt Ressourcen und hebt die Registrierung des Webhooks auf, wenn das Modul zerstört wird.

### Private Methoden

#### `RegisterHook($WebHook)`

Registriert oder aktualisiert den Webhook in IP-Symcon.

#### `handleMotionData($motionData, $source)`

Verarbeitet Bewegungsereignisdaten:

- Verwalte Variablen und Medienobjekte.
- Lädt Schnappschüsse herunter.
- Startet den Egg Timer.

#### `parseEventNotificationAlert($xmlString)`

Parst XML-Ereignisbenachrichtigungen in ein assoziatives Array.

#### `downloadHikvisionSnapshot($cameraIp, $channelId, $username, $password, $relativePath)`

Lädt ein Schnappschussbild von der angegebenen Kamera herunter.

#### `manageVariable($parent, $name, $type, $profile, $logging, $aggregationType, $initialValue)`

Erstellt oder aktualisiert eine Variable mit den angegebenen Parametern.

#### `manageMedia($parent, $name, $imageFile)`

Erstellt oder aktualisiert ein Medienobjekt zur Anzeige von Bildern.

#### `handle_egg_timer($source, $kamera_name, $kameraId)`

Verwaltet die Egg Timer-Instanz für die Bewegungssteuerung.

#### `ExecuteMotionDetectionAPI($status)`

Aktiviert oder deaktiviert die Bewegungsmelder aller Kameras:

- Ruft die Kamera-IP-Adressen basierend auf dem Subnetz ab.
- Ruft für jede Kamera `callMotionDetectionAPI()` auf.
- Aktualisiert die Bewegungsmeldereinstellungen über die API der Kameras.

#### `callMotionDetectionAPI($ip, $username, $password, $path)`

Ruft die aktuellen Bewegungsmeldereinstellungen von einer Kamera ab.

#### `sendModifiedXML($ip, $username, $password, $path, $modifiedXml)`

Sendet modifizierte XML-Konfigurationen an die Kamera zurück.

#### `updateDetectionEnabled($xmlString, $detectionType, $id, $newEnabledValue)`

Aktualisiert den `<enabled>`-Wert in der Konfigurations-XML der Kamera.

#### `getStringAfterSmart($inputString)`

Extrahiert den Erkennungstyp aus einem gegebenen Pfad.

#### `GetAllObjectIDsByTypeAndName($rootID, $objectType, $objectName, $matchType, $caseSensitive)`

Ruft Objekt-IDs ab, die bestimmten Kriterien entsprechen.

## Fehlerbehebung

- **Webhook nicht ausgelöst:**

  - Stelle sicher, dass die Webhook-URL in den Kameraeinstellungen korrekt konfiguriert ist.
  - Überprüfe, ob der Webhook in IP-Symcon registriert ist.
  - Vergewissere dich, dass die `Web-Authentifizierung` der Kamera auf `digest/basic` eingestellt ist.

- **Schnappschüsse werden nicht heruntergeladen:**

  - Überprüfe, ob der Benutzername und das Passwort korrekt sind.
  - Stelle sicher, dass die Snapshot-URL der Kamera zugänglich ist.
  - Vergewissere dich, dass `Kanal-ID` und `Speicherpfad` richtig konfiguriert sind.

- **Variablen werden nicht aktualisiert:**

  - Überprüfe, ob das Modul die erforderlichen Berechtigungen hat.
  - Prüfe, ob bei aktivierten Debug-Protokollen Fehler in den IP-Symcon-Logs auftreten.
  - Stelle sicher, dass die Kamera ein Ereignis an den Webhook gesendet hat.

- **Bewegungsmelder nicht gesteuert:**

  - Stelle sicher, dass die Variable `Activate_all_Cameras` korrekt gesetzt ist.
  - Vergewissere dich, dass die Kameras API-Befehle zur Bewegungsmelderegelung akzeptieren.
  - Überprüfe die Netzwerkverbindung zwischen IP-Symcon und den Kameras.

- **Egg Timer funktioniert nicht:**

  - Stelle sicher, dass das Egg Timer-Modul aus dem Module Store installiert ist.
  - Überprüfe, ob der Egg Timer richtig initialisiert und den Kameravariablen zugeordnet ist.

## Sicherheitsüberlegungen

- **Zugangsdaten-Speicherung:**

  - Zugangsdaten werden in IP-Symcon-Variablen gespeichert.
  - Sichere den Zugang zu IP-Symcon, um unbefugten Zugriff zu verhindern.
  - Vermeide die Verwendung von Standardzugangsdaten; aktualisiere Benutzernamen und Passwörter.

- **Netzwerksicherheit:**

  - Verwende HTTPS, falls dies von den Kameras unterstützt wird, um API-Kommunikation zu verschlüsseln.
  - Sichere dein Netzwerk, um Abhörmaßnahmen zu verhindern.
  - Implementiere Firewall-Regeln, um den Zugriff auf die Kameras und den IP-Symcon-Server zu beschränken.

- **Zugangskontrolle:**

  - Beschränke den Zugriff auf den Webhook-Endpunkt.
  - Implementiere bei Bedarf IP-Filterung oder Authentifizierung.
  - Halte dein IP-Symcon-System und deine Kameras regelmäßig auf dem neuesten Stand und spiele Sicherheitsupdates ein.

## Beitrag

Beiträge sind willkommen! Bitte reiche Issues oder Pull Requests für Verbesserungen oder Fehlerkorrekturen ein.

## Lizenz

Dieses Projekt ist unter der MIT-Lizenz lizenziert.

---

**Hinweis:** Stelle sicher, dass alle Pfade, Modul-IDs und Klassennamen korrekt an deine tatsächlichen Implementierungsdetails angepasst werden.
