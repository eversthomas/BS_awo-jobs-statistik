# BS AWO Jobs Statistik

WordPress-Plugin zur statistischen Auswertung offener und historischer Stellenausschreibungen (AWO-Kreisverband oder vergleichbare Organisationen). **Kernfunktionen:** Fluktuation erkennen, Vollzeitäquivalente (VZÄ) berechnen, Personalbedarf ableiten.

Vollständige Architektur und Vorgaben: **[ARCHITECTURE.md](ARCHITECTURE.md)**.

---

## Entwicklungsstand

| Schritt | Inhalt | Status |
|--------|--------|--------|
| 1 | Datenbankstruktur, Migration bei Plugin-Aktivierung | ✅ erledigt |
| 2 | Excel/CSV-Import | ✅ erledigt |
| 3 | API-Import + Stunden-Extraktion + Zusammenführung | ✅ erledigt |
| 4 | Deduplizierung / Logische Stellen (Auto + manuelle Korrektur) | ✅ erledigt |
| 5 | Analyse-Module (VZÄ, Fluktuation, Vakanz) | ✅ erledigt |
| 6 | Cronjob: täglicher API-Snapshot | ✅ erledigt |
| 7 | WordPress Admin-UI (Dashboard, Import, Logische Stellen, Einstellungen) | ✅ erledigt |
| 8 | Charts im Dashboard (VZÄ-Verlauf, Fluktuation, Vakanzen, Fachbereiche) | ✅ erledigt |
| 9 | Excel-Export (formatiert, Einzeltabs + Gesamt-Export) | ✅ erledigt |
| 10 | PDF-Export (Kennzahlen, Tabellen, Diagramme) | ✅ erledigt |
| 11 | UX-Verbesserungen (Fortschrittsanzeige beim Import, Filter/Suche in Logische Stellen) | ✅ erledigt |

**Tests:** `test-analysis.php` (Analyse), `test-snapshot.php` (Snapshot manuell ausführen).

---

## Statistische Kennzahlen – Was wird erfasst und dargestellt?

### Definition: „Offen“ / „Aktuell“

Eine Stelle gilt als **aktuell offen**, wenn sie beim letzten API-Abruf noch vorhanden war (`zuletzt_gesehen_api IS NOT NULL`). Damit wird nur die aktuelle Sicht der API verwendet – nicht das Stopdatum aus Excel.

### Übersicht (Dashboard)

| Kennzahl | Erhebung | Beschreibung |
|----------|----------|--------------|
| **Offene Stellen** | Zählung | Anzahl der Stellen mit `zuletzt_gesehen_api IS NOT NULL` |
| **Gesamt-VZÄ** | Summe | Vollzeitäquivalente aller offenen Stellen; Formel: `Stunden / Vollzeitstunden` (Standard 39); Vollzeit ohne Stunden = 1,0; Teilzeit ohne Stunden = nicht berechenbar |
| **Unbekannt (Teilzeit)** | Zählung | Offene Stellen mit Teilzeit und fehlender Stundenzahl (nicht in VZÄ enthalten) |
| **Nach Stellentitel** | Gruppierung | Absolute Anzahl offener Stellen pro Stellentitel (Top 15) |
| **Nach Fachbereich** | Gruppierung | Absolute Anzahl offener Stellen pro Fachbereich der Stellenbörse |
| **Nach Postleitzahl** | Gruppierung | Absolute Anzahl offener Stellen pro PLZ (Top 15, nur Stellen mit erfasster PLZ) |

### Fluktuation

| Kennzahl | Erhebung | Beschreibung |
|----------|----------|--------------|
| **Top 10 Fluktuationsstellen** | Sortierung | Logische Stellen (Titel + Einrichtung) mit den meisten Ausschreibungen; zeigt Jahresend-Kopien und häufig wiederbesetzte Stellen |
| **Anzahl Ausschreibungen** | Zählung | Wie viele Stellennummern zu einer logischen Stelle gehören |
| **Stellennummern** | Auflistung | Sortierung: zuerst online (in API), dann offline; innerhalb jeweils neueste zuerst |
| **PLZ** | Aggregation | Alle PLZ der zugeordneten Ausschreibungen |

### Vakanzen

| Kennzahl | Erhebung | Beschreibung |
|----------|----------|--------------|
| **Tage offen** | Berechnung | `DATEDIFF(heute, startdatum)` für jede offene Stelle |
| **Sortierung** | Absteigend | Längste Vakanzen zuerst |
| **Einrichtung, Ort** | direkt | Aus den Ausschreibungsdaten |

### Fachbereiche

| Kennzahl | Erhebung | Beschreibung |
|----------|----------|--------------|
| **VZÄ nach Fachbereich (Stellenbörse)** | Summe | VZÄ pro `fachbereich_boerse` |
| **VZÄ nach Mandantenfeld** | Summe | VZÄ pro `fachbereich_intern` (internes Kürzel) |

### PLZ-Statistik

| Kennzahl | Erhebung | Beschreibung |
|----------|----------|--------------|
| **Anzahl Stellen** | Zählung | Offene Stellen pro PLZ (nur mit erfasster PLZ) |
| **VZÄ gesamt** | Summe | Summe der VZÄ pro PLZ |
| **Stellennummern, Stellentitel** | Auflistung | Alle zugehörigen Nummern und Titel zur gezielten Suche |

---

## Excel-Export

Alle Dashboard-Auswertungen können als Excel-Dateien (.xlsx) exportiert werden. Die exportierten Tabellen sind professionell formatiert (blauer Tabellenkopf, Zebra-Streifen, Rahmen, automatische Spaltenbreite, Zahlenformate für VZÄ).

### Einzelexport

Jeder Dashboard-Tab bietet einen Button „Als Excel exportieren“ für den jeweiligen Bereich:

- **Übersicht** – Kennzahlen und Zählungen (Titel, Fachbereich, PLZ)
- **Fluktuation** – Top 100 Fluktuationsstellen
- **Vakanzen** – Alle offenen Stellen mit Tagen
- **Fachbereiche** – VZÄ nach Börse, Mandantenfeld und pro Einrichtung
- **PLZ** – Statistik nach Postleitzahl

### Gesamt-Export

Ein zentraler Button **„Alle Daten als Excel exportieren“** im Dashboard erzeugt eine einzige Excel-Datei mit **5 Tabellenblättern**:

1. Übersicht  
2. Fluktuation  
3. Offene Vakanzen  
4. Fachbereiche  
5. PLZ  

Dateiname: `bs-awo-jobs-statistik-gesamt-YYYY-MM-DD.xlsx`

### PDF-Export

Der Button **„Als PDF exportieren“** erzeugt einen Bericht im PDF-Format mit:

- Kennzahlen (Offene Stellen, Gesamt-VZÄ, Unbekannt)
- Diagrammen: VZÄ-Verlauf, Top 10 Fluktuation, längste Vakanzen, Fachbereiche (Pie)
- Tabellen: Fluktuation, Vakanzen, Stellentitel

Die Diagramme werden serverseitig über QuickChart.io erzeugt. Dateiname: `bs-awo-jobs-statistik-bericht-YYYY-MM-DD.pdf`

---

## Verhalten des Plugins – Was wird wann wie gespeichert?

### Bei Plugin-Aktivierung

- Es werden **5 Tabellen** angelegt (falls noch nicht vorhanden):
  - `bs_awojobs_ausschreibungen` – Rohdaten der Stellen
  - `bs_awojobs_logische_stellen` – Deduplizierte logische Stellen
  - `bs_awojobs_zuordnungen` – Zuordnung Ausschreibung ↔ logische Stelle
  - `bs_awojobs_snapshots` – tägliche Snapshots der API
  - `bs_awojobs_konfiguration` – Einstellungen
- In der Konfiguration werden **Standardwerte** hinterlegt (API-URL leer, Vollzeitstunden 39, …).
- Der **Snapshot-Cronjob** wird für das eingestellte Intervall geplant (Standard: täglich).

### Bei Plugin-Deaktivierung

- **Keine Datenlöschung.** Alle Tabellen und Daten bleiben erhalten.
- Der **Snapshot-Cronjob** wird entfernt (kein weiterer automatischer Abruf).

### Excel/CSV-Import

- **Aktion:** Datei-Upload in der Admin-UI oder Ausführung von `test-import.php`.
- **Fortschrittsanzeige:** Beim Klick auf „Datei importieren“ oder „API jetzt synchronisieren“ erscheint eine Ladeanzeige („Import wird ausgeführt, bitte warten…“), bis die Seite neu geladen ist.
- **Speicherung:** Neue Zeilen in `bs_awojobs_ausschreibungen`; bei gleicher Stellennummer: `INSERT ... ON DUPLICATE KEY UPDATE` (Vorhandenes wird überschrieben).
- **Feld `quelle`:** `"excel"` bei neu angelegten Einträgen.
- **Stundenzahl:** Wird aus Excel nicht übernommen; bleibt NULL (nur Zeitmodell z.B. Vollzeit/Teilzeit).
- **Danach:** Deduplizierung wird ausgeführt (`LogischeStellen::run()`).

### API-Synchronisation (manuell oder Cronjob)

- **Aktion:** Button „API jetzt synchronisieren“ oder regelmäßiger Cronjob.
- **Speicherung:**
  - Neue Stellen: Einfügen in `bs_awojobs_ausschreibungen` mit `quelle = "api"`.
  - Bereits vorhandene (z.B. aus Excel): Aktualisierung (`quelle = "beide"`), Ergänzung fehlender Felder (u.a. Stundenzahl), `zuletzt_gesehen_api = NOW()`.
  - Stundenzahl aus dem HTML-Feld „Infos“ (Regeln in ARCHITECTURE.md).
- **Snapshot-Service:** Schreibt für jede in der API gefundene Stelle einen Eintrag in `bs_awojobs_snapshots` (Status `online`), für Stellen, die am Vortag online, heute aber nicht mehr sind, einen Eintrag mit Status `offline`.
- **Danach:** Deduplizierung wird ausgeführt.

### Täglicher Snapshot-Cronjob

- **Ablauf:** API wird abgerufen, Stundenzahlen und Zeitmodell werden je Stelle gespeichert.
- **Speicherung in `bs_awojobs_snapshots`:**
  - Für jede heute in der API sichtbare Stelle: Zeile mit `snapshot_datum = heute`, `status = "online"`, `stunden`, `zeitmodell`.
  - Für Stellen, die gestern online, heute nicht mehr sind: Zeile mit `status = "offline"`.
- **Aktualisierung:** `zuletzt_gesehen_api` in `bs_awojobs_ausschreibungen` wird für alle aktuell in der API sichtbaren Stellen gesetzt.

### Logische Stellen (Deduplizierung)

- **Suche und Filter:** Auf der Seite „Logische Stellen“ können Einträge per Suchfeld (Titel, Einrichtung, Stellennummer) gefiltert und zusätzlich nach Status (Online/Offline, verifiziert/automatisch) eingegrenzt werden. Filterung erfolgt clientseitig ohne Seitenneulade.
- **Automatisch:** Gruppierung aller Ausschreibungen nach `Titel + Einrichtung`; für jede Gruppe wird ggf. eine logische Stelle angelegt und die Ausschreibungen zugeordnet (`zuordnungstyp = "auto"`).
- **Manuell:**
  - **Zuordnung trennen:** Entfernt die Zuordnung und legt für diese Stellennummer eine neue logische Stelle an; Zuordnung wird dauerhaft gespeichert.
  - **Manuell zuordnen:** Weist eine Stellennummer einer anderen logischen Stelle zu; diese Zuordnung bleibt bestehen.
- **Permanenz:** Manuelle Änderungen bleiben bis zur nächsten manuellen Änderung oder bis zum Löschen aller Daten erhalten; die automatische Deduplizierung überschreibt keine bestehenden Zuordnungen.

### Löschen der Daten („Gefahrenzone“)

- **Aktion:** Button „Alle Daten unwiderruflich löschen“ in den Einstellungen (mit Bestätigungsdialog).
- **Folge:**
  - **TRUNCATE** für alle 5 Tabellen (Inhalte werden gelöscht, Tabellenstruktur bleibt).
  - Konfiguration wird neu mit Standardwerten befüllt.
  - `daten_beim_deinstallieren_loeschen` wird auf `1` gesetzt.
- **Hinweis:** Beim späteren **Löschen des Plugins** werden die Tabellen nur gelöscht, wenn `daten_beim_deinstallieren_loeschen = 1` ist (siehe uninstall.php).

### Deinstallation des Plugins (Löschen über WordPress)

- **uninstall.php** prüft zuerst den Wert von `daten_beim_deinstallieren_loeschen` in der Konfiguration.
- **Nur wenn der Wert `1` ist:** Alle 5 Tabellen werden mit `DROP TABLE` entfernt.
- **Standard (`0`):** Es wird nichts gelöscht; die Tabellen bleiben in der Datenbank.

---

## Technik

- PHP ≥ 7.4, WordPress (wpdb, Plugin-API)
- Composer: `phpoffice/phpspreadsheet` (Excel/CSV), `mpdf/mpdf` (PDF-Export); `vendor/` wird mitcommittet
- Charts: Chart.js (Dashboard, via CDN); QuickChart.io (PDF-Diagramme, externer API-Aufruf)
- Core ohne WordPress-Abhängigkeiten (außer wo nötig); DB-Zugriff per Dependency Injection

---

*Ein Produkt von [Bezugssysteme (BS)](https://bezugssysteme.de).*
