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
| 5 | Analyse-Module (VZÄ, Fluktuation, Vakanz) | ✅ erledigt |
| 6 | Cronjob: täglicher API-Snapshot | ✅ erledigt |
| **7** | **WordPress Admin-UI** (Dashboard, Import, Logische Stellen, Einstellungen) | ⏳ **als nächstes** |

### Wo weitermachen?

- **Nächster Schritt:** **Schritt 7 – WordPress Admin-UI.**  
  Vollständige Beschreibung in **`.prompts2do`** (ab „Schritt 7: WordPress Admin-UI“).
- **Tests:** `test-analysis.php` (Analyse), `test-snapshot.php` (Snapshot manuell ausführen).

---

## Technik

- PHP ≥ 7.4, WordPress (wpdb, Plugin-API)
- Composer: `phpoffice/phpspreadsheet` (Excel/CSV); `vendor/` wird mitcommittet
- Core ohne WordPress-Abhängigkeiten (außer wo nötig); DB-Zugriff per Dependency Injection

---

*Ein Produkt von [Bezugssysteme (BS)](https://bezugssysteme.de).*
