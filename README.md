# BS AWO Jobs Statistik

WordPress-Plugin zur statistischen Auswertung offener und historischer Stellenausschreibungen (AWO-Kreisverband oder vergleichbare Organisationen). **Kernfunktionen:** Fluktuation erkennen, Vollzeitäquivalente (VZÄ) berechnen, Personalbedarf ableiten.

Vollständige Architektur und Vorgaben: **[ARCHITECTURE.md](ARCHITECTURE.md)**.

---

## Entwicklungsstand

Fortschritt orientiert sich am Entwicklungsfahrplan in ARCHITECTURE.md. Detaillierte Aufgaben und Testkriterien für die nächsten Schritte stehen in **`.prompts2do`**.

| Schritt | Inhalt | Status |
|--------|--------|--------|
| 1 | Datenbankstruktur, Migration bei Plugin-Aktivierung | ✅ erledigt |
| 2 | Excel/CSV-Import | ✅ erledigt |
| 3 | API-Import + Stunden-Extraktion + Zusammenführung | ✅ erledigt |
| 4 | Deduplizierung / Logische Stellen (Auto + manuelle Korrektur) | ✅ erledigt |
| **5** | **Analyse-Module (VZÄ, Fluktuation, Vakanz)** | ⏳ **als nächstes** |
| 6 | Cronjob: täglicher API-Snapshot | ⏳ ausstehend |
| 7 | WordPress Admin-UI (Dashboard, Import, Logische Stellen, Einstellungen) | ⏳ ausstehend |

### Wo weitermachen?

- **Nächster Schritt:** **Schritt 5 – Analyse-Module.**  
  Vollständige Beschreibung in **`.prompts2do`** (ab „Schritt 5: Analyse-Module“): VzaCalculator, FluktuationAnalyzer, VakanzAnalyzer mit den dort genannten Methoden und Testkriterien.
- Danach: Schritt 6 (SnapshotService + CronHandler), dann Schritt 7 (Admin-UI).

---

## Technik

- PHP ≥ 7.4, WordPress (wpdb, Plugin-API)
- Composer: `phpoffice/phpspreadsheet` (Excel/CSV); `vendor/` wird mitcommittet
- Core ohne WordPress-Abhängigkeiten (außer wo nötig); DB-Zugriff per Dependency Injection

---

*Ein Produkt von [Bezugssysteme (BS)](https://bezugssysteme.de).*
