# BS AWO Jobs Statistik – Plugin Architektur
### Ein Produkt von [Bezugssysteme (BS)](https://bezugssysteme.de)

> Dieses Dokument ist die verbindliche Grundlage für alle Entwicklungsentscheidungen.
> Cursor und alle Entwickler halten sich an diese Vorgaben.

---

## Projektziel

Ein WordPress-Plugin (mit WordPress-unabhängigem PHP-Core) zur statistischen Auswertung offener und historischer Stellenausschreibungen eines AWO-Kreisverbandes. Kernfunktionen: Fluktuation erkennen, Vollzeitäquivalente (VZÄ) berechnen, Personalbedarf ableiten.

Das Plugin wird als **Open Source auf GitHub** veröffentlicht und soll von beliebigen AWO-Strukturen (und anderen Organisationen) genutzt werden können – jede Installation ist autark.

---

## Architekturprinzip: Modularer Core

```
┌─────────────────────────────────────────────────┐
│                 WordPress Plugin                 │
│         (UI, Admin, Cronjob, Shortcodes)         │
└────────────────────┬────────────────────────────┘
                     │
┌────────────────────▼────────────────────────────┐
│                  PHP Core                        │
│     (WordPress-unabhängig, testbar, portabel)    │
├──────────────┬──────────────┬───────────────────┤
│ Import-      │ Analyse-     │ Ausgabe-           │
│ Module       │ Module       │ Module             │
│              │              │                    │
│ - API        │ - VZÄ        │ - WordPress        │
│ - Excel/CSV  │ - Fluktuation│ - (erweiterbar)    │
│ - (erweiterb)│ - Vakanzzeit │                    │
│              │ - Regional   │                    │
└──────────────┴──────────────┴───────────────────┘
                     │
┌────────────────────▼────────────────────────────┐
│            Normalisierte Datenbank               │
│                (MySQL / wpdb)                    │
└─────────────────────────────────────────────────┘
```

### Kernprinzip
Der PHP-Core hat **keine WordPress-Abhängigkeiten**. WordPress ist nur die Hülle. Der Core kann unabhängig getestet und theoretisch auch ohne WordPress betrieben werden.

---

## Datenquellen

### Quelle 1: JSON API (Stellenbörse)
- Enthält **nur aktuell online geschaltete** Stellenangebote
- Liefert reichere Detailinformationen (u.a. Stundenzahl)
- Zeitstempel als **Unix-Timestamps**
- Stundenzahl steht **im Fließtext** des Feldes `Infos` (HTML), nicht als eigenes Feld
- Stundenzahl kann als Spanne angegeben sein (z.B. "25 bis 30 Stunden") → es wird immer der **höhere Wert** verwendet
- Mandantenfeld heißt: `Mandantnr/Einrichtungsnr`

### Quelle 2: Excel-Export (Stellenbörse)
- Enthält **alle** Stellenanzeigen inkl. historischer (offline)
- Datumsformat: `DD.MM.YYYY HH:MM` (z.B. `20.02.2026 00:00`)
- Enthält Start- und Stopdatum → historische Auswertung möglich
- Stundenzahl **nicht enthalten**, nur Kategorie (Vollzeit/Teilzeit) in Spalte `BA Zeiteinteilung`
- Mandantenfeld heißt: `Internes Kürzel`

### Gemeinsamer Schlüssel
`Stellennummer` (im Excel: `S-Nr`) ist in beiden Quellen vorhanden und **immer eindeutig**. Dies ist der primäre Verknüpfungsschlüssel.

---

## Bekannte Datenqualitätsprobleme

| Problem | Beschreibung | Lösungsansatz |
|---|---|---|
| Stundenzahl nur in API | Historische Stunden gehen verloren wenn Stelle offline geht | Täglicher Snapshot-Cronjob |
| Stopdatum unzuverlässig | Kollegen setzen Stopdatum 3-6 Monate in Zukunft aus Vorsicht | Stelle gilt als offen solange sie in API erscheint |
| Jahresend-Kopien | Jedes Jahr wird jede aktive Stelle kopiert, Original offline, Kopie bekommt neue Stellennummer | Deduplizierung über Titel + Einrichtung (siehe Logische Stellen) |
| Internes Kürzel lückenhaft | Nur für neuere Stellen gepflegt | Auswertung nach internem Kürzel zeigt nur Teilmenge |
| Fließtext-Stunden | Regex-Extraktion aus HTML-Text | Robuster Parser, Fallback auf NULL wenn nicht erkennbar |
| Spalte "Kopie" im Excel | Steht immer "Nein", auch bei echten Kopien | Ignorieren, unbrauchbar |
| Tippfehler in Freifeldern | Menschliche Fehler bei Eingabe | Tool kann nicht schützen, Disziplin beim Erfassen nötig |

---

## Konzept: Logische Stellen (Deduplizierung)

Da Jahresend-Kopien dieselbe Stelle unter neuer Stellennummer führen, brauchen wir zwei Ebenen:

**Ebene 1: Ausschreibung** – jede konkrete Stellennummer, unveränderlich gespeichert.

**Ebene 2: Logische Stelle** – abstrakte Einheit "Erzieher*in in Kita Xanten", unabhängig von Stellennummern.

### Zuordnungsstrategie (Variante C: Automatisch mit manueller Korrektur)
1. System gruppiert automatisch über `Titel + Einrichtung` als zusammengesetzten Schlüssel
2. Zuordnungen werden als `automatisch` markiert
3. Nutzer kann Zuordnungen in der Admin-Oberfläche manuell korrigieren oder bestätigen
4. Manuell geprüfte Zuordnungen werden als `manuell_verifiziert` markiert

---

## Datenbankstruktur

Alle Tabellen verwenden das WordPress-Tabellenpräfix: `{$wpdb->prefix}bs_awojobs_`

### Tabelle: `{prefix}bs_awojobs_ausschreibungen`
Rohdaten jeder Stellenausschreibung – unveränderlich, eine Zeile pro Stellennummer.

| Feld | Typ | Beschreibung |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | Interne ID |
| `stellennummer` | VARCHAR(20) UNIQUE | Schlüssel aus Stellenbörse |
| `titel` | VARCHAR(255) | Stellenbezeichnung |
| `einrichtung` | VARCHAR(255) | Name der ausschreibenden Einrichtung |
| `fachbereich_boerse` | VARCHAR(100) | Fachbereich laut Stellenbörse |
| `fachbereich_intern` | VARCHAR(100) NULL | Internes Kürzel / Mandantenfeld (optional) |
| `anstellungsart` | VARCHAR(50) | z.B. "Fachkraft", "Hilfskraft" |
| `vertragsart` | VARCHAR(50) | z.B. "Festanstellung", "Befristet" |
| `zeitmodell` | VARCHAR(50) | z.B. "Teilzeit - Schicht", "Vollzeit" |
| `stunden` | DECIMAL(4,2) NULL | Extrahierte Stundenzahl (höchster Wert bei Spanne) |
| `stunden_quelle` | VARCHAR(10) NULL | "api" oder "snapshot" – woher die Stunden kamen |
| `startdatum` | DATE NULL | Wann online geschaltet |
| `stopdatum` | DATE NULL | Wann offline (unzuverlässig, siehe oben) |
| `plz_einsatzort` | VARCHAR(10) NULL | PLZ des Einsatzortes |
| `einsatzort` | VARCHAR(100) NULL | Ort des Einsatzortes |
| `erstellt_von` | VARCHAR(255) NULL | E-Mail/Name des Erstellers (aus Excel) |
| `quelle` | VARCHAR(10) | "api", "excel" oder "beide" |
| `importiert_am` | DATETIME | Wann dieser Datensatz importiert wurde |
| `zuletzt_gesehen_api` | DATETIME NULL | Letzter API-Snapshot in dem diese Stelle erschien |

---

### Tabelle: `{prefix}bs_awojobs_logische_stellen`
Abstrakte Einheit für deduplizierte Stellen.

| Feld | Typ | Beschreibung |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | Interne ID |
| `titel` | VARCHAR(255) | Normalisierter Titel |
| `einrichtung` | VARCHAR(255) | Einrichtung |
| `fachbereich_boerse` | VARCHAR(100) NULL | Fachbereich |
| `fachbereich_intern` | VARCHAR(100) NULL | Internes Kürzel |
| `anstellungsart` | VARCHAR(50) NULL | Fachkraft / Hilfskraft |
| `manuell_verifiziert` | TINYINT(1) | 0 = automatisch, 1 = manuell geprüft |
| `erstellt_am` | DATETIME | |
| `aktualisiert_am` | DATETIME | |

---

### Tabelle: `{prefix}bs_awojobs_zuordnungen`
Verknüpfung zwischen Ausschreibungen und logischen Stellen (n:1).

| Feld | Typ | Beschreibung |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | |
| `logische_stelle_id` | INT FK | Verweis auf logische_stellen |
| `stellennummer` | VARCHAR(20) FK | Verweis auf ausschreibungen |
| `zuordnungstyp` | VARCHAR(10) | "auto" oder "manuell" |
| `erstellt_am` | DATETIME | |

---

### Tabelle: `{prefix}bs_awojobs_snapshots`
Tägliche API-Snapshots zur Sicherung von Stundendaten historischer Stellen.

| Feld | Typ | Beschreibung |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | |
| `stellennummer` | VARCHAR(20) | |
| `snapshot_datum` | DATE | Datum des Snapshots |
| `stunden` | DECIMAL(4,2) NULL | Stundenzahl zum Zeitpunkt des Snapshots |
| `zeitmodell` | VARCHAR(50) NULL | |
| `status` | VARCHAR(10) | "online" oder "offline" |

Index auf `(stellennummer, snapshot_datum)` für Performance.

---

### Tabelle: `{prefix}bs_awojobs_konfiguration`
Key-Value-Store für installationsspezifische Einstellungen.

| Feld | Typ | Beschreibung |
|---|---|---|
| `schluessel` | VARCHAR(100) PK | Konfigurationsschlüssel |
| `wert` | TEXT | Wert |
| `beschreibung` | VARCHAR(255) NULL | Erklärung für Admin-UI |

#### Standardwerte

| Schlüssel | Standardwert | Beschreibung |
|---|---|---|
| `api_url` | `` | URL der JSON API |
| `vollzeit_stunden` | `39` | Wochenstunden für 1,0 VZÄ |
| `fachbereich_intern_aktiv` | `0` | Internes Kürzel als Fachbereich nutzen |
| `cronjob_intervall` | `daily` | Snapshot-Häufigkeit |
| `daten_beim_deinstallieren_loeschen` | `0` | Tabellen bei Deinstallation löschen |

---

## Cron-Zuverlässigkeit

Der Snapshot-Cronjob nutzt **WordPress Cron** (`wp_schedule_event`), der bei Seitenaufrufen ausgelöst wird. Bei wenig Traffic kann der Cron ggf. nicht täglich laufen.

**Optional – Echter System-Cron (nachrüstbar):**
- `DISABLE_WP_CRON` in `wp-config.php` setzen (falls gewünscht)
- System-Cron einrichten, z.B. alle 15 Min.:  
  `*/15 * * * * curl -s "https://deine-domain.de/wp-cron.php?doing_wp_cron" >/dev/null 2>&1`
- Dadurch wird der Snapshot zuverlässig zum geplanten Zeitpunkt ausgeführt

---

## Deinstallationsverhalten

- **Deaktivieren** des Plugins: Keine Datenlöschung, alle Daten bleiben erhalten.
- **Deinstallieren** (Löschen über WordPress): Tabellen bleiben standardmäßig erhalten (`daten_beim_deinstallieren_loeschen = 0`).
- **Explizites Löschen**: Nur über den Button "Alle Daten unwiderruflich löschen" in den Plugin-Einstellungen, mit Bestätigungsdialog. Setzt `daten_beim_deinstallieren_loeschen = 1` und löst Löschung aus.
- Implementierung: `uninstall.php` prüft die Konfigurationsoption.

---

## VZÄ-Berechnung

```
VZÄ = Stunden der Stelle / vollzeit_stunden (Konfiguration, Standard: 39)
```

Beispiel: Eine 21-Stunden-Stelle = 21 / 39 = 0,538 VZÄ

Bei Stunden als Spanne (z.B. "25 bis 30"): **immer den höheren Wert** verwenden (30).

Wenn keine Stundenzahl vorhanden (nur "Teilzeit"/"Vollzeit" aus Excel):
- Vollzeit → 39 Stunden (aus Konfiguration)
- Teilzeit → NULL (kein VZÄ berechenbar, in Auswertung als "unbekannt" kennzeichnen)

---

## Fachbereich-Logik

Zwei Ebenen, konfigurierbar:

| Modus | Beschreibung |
|---|---|
| Nur Stellenbörse | `fachbereich_boerse` wird für alle Auswertungen verwendet |
| Intern bevorzugt | `fachbereich_intern` wenn vorhanden, sonst `fachbereich_boerse` |
| Beide parallel | Auswertungen können nach beiden Ebenen gefiltert werden |

Gesteuert über Konfiguration `fachbereich_intern_aktiv`.

---

## Stundenzahl-Extraktion (Regex)

Die Stundenzahl steckt im HTML-Fließtext des API-Feldes `Infos`. Vorgehen:

1. HTML-Tags entfernen (`strip_tags`)
2. Regex nach Mustern wie:
   - `(\d+[,.]?\d*)\s*(Stunden|Std\.?)` → einfache Zahl
   - `(\d+)\s*bis\s*(\d+)\s*(Stunden|Std\.?)` → Spanne, höheren Wert nehmen
3. Wenn kein Match → `stunden = NULL`, `stunden_quelle = NULL`
4. Komma als Dezimaltrennzeichen normalisieren (21,00 → 21.00)

---

## Externe Abhängigkeiten (Composer)

| Paket | Zweck |
|---|---|
| `phpoffice/phpspreadsheet` | Excel/CSV-Import |
| `nesbot/carbon` | Datums-Normalisierung |

Der `vendor/`-Ordner wird **mit ins Repository committed**, damit das Plugin ohne `composer install` funktioniert.

---

## Entwicklungsfahrplan

| Schritt | Inhalt | Testkriterium |
|---|---|---|
| 1 | Datenbankstruktur, Migration bei Plugin-Aktivierung | Tabellen existieren nach Aktivierung |
| 2 | Excel/CSV-Import | Importierte Zeilen sind in DB sichtbar |
| 3 | API-Import + Stunden-Extraktion + Zusammenführung via Stellennummer | Stundenzahl korrekt extrahiert, Datensätze zusammengeführt |
| 4 | Deduplizierung / Logische Stellen (Auto + manuelle Korrektur) | Jahresend-Kopien werden als zusammengehörig erkannt |
| 5 | Analyse-Module: VZÄ, offene Stellen, Vakanzzeit, Fluktuation | Zahlen plausibel und nachvollziehbar |
| 6 | Cronjob: täglicher API-Snapshot | Nach simuliertem Zeitablauf sind Snapshots in DB |
| 7 | WordPress Admin-UI: Konfiguration, Import, Auswertungsdarstellung | Vollständig bedienbar ohne Code-Kenntnisse |
| 8 | Charts im Dashboard (VZÄ-Verlauf, Fluktuation, Vakanzzeiten) | Diagramme laden, Daten stimmen mit Analyse-Modulen überein |
| 9 | Excel-Export der statistischen Daten | Dashboard-Tabs als Excel-Dateien exportierbar |
| 10 | PDF-Export (inkl. Charts) | PDF enthält Kennzahlen und eingebettete Diagramme |
| 11 | UX-Verbesserungen | Fortschrittsanzeige beim Excel-Import, Filter/Suche in Logische Stellen |

---

## Projektstruktur (Plugin-Ordner)

```
BS_awo-jobs-statistik/
├── BS_awo-jobs-statistik.php     # WordPress Plugin Header + Bootstrap
├── uninstall.php                  # Deinstallations-Hook
├── ARCHITECTURE.md                # Dieses Dokument
├── composer.json
├── vendor/                        # Committed! Kein composer install nötig
├── src/
│   ├── Core/
│   │   ├── Database.php           # Tabellenerstellung, Migration
│   │   ├── Config.php             # Konfigurationsverwaltung
│   │   └── Installer.php          # Aktivierung / Deaktivierung
│   ├── Import/
│   │   ├── ImportInterface.php    # Interface für alle Importer
│   │   ├── ApiImporter.php        # JSON API Import
│   │   └── ExcelImporter.php      # Excel/CSV Import
│   ├── Parser/
│   │   └── StundenParser.php      # Regex-Extraktion Stundenzahl aus HTML
│   ├── Analysis/
│   │   ├── VzaCalculator.php      # VZÄ-Berechnung
│   │   ├── FluktuationAnalyzer.php
│   │   └── VakanzAnalyzer.php
│   ├── Dedup/
│   │   └── LogischeStellen.php    # Deduplizierung, Zuordnung
│   └── Snapshot/
│       └── SnapshotService.php    # Täglicher API-Snapshot
├── wordpress/
│   ├── Admin/
│   │   ├── AdminPage.php
│   │   └── SettingsPage.php
│   └── Cron/
│       └── CronHandler.php
└── tests/                         # Unit Tests (PHPUnit)
```

---

*Letzte Aktualisierung: Februar 2026*
