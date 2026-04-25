# LogAnalyzer – Architektur & Performance-Design

## Ziel der Architektur

Der LogAnalyzer ist für sehr große Logdateien optimiert.  
Die Architektur trennt strikt zwischen:

- UI / Statusverwaltung
- Cache-Schicht
- Datenzugriffsschicht
- Betriebssystem-spezifischer Verarbeitung
- Ultra CLI Verarbeitung

Ziel ist es:
- Vollscans zu vermeiden
- Parsing zu minimieren
- OS-Tools zu nutzen
- Ultra CLI optional zu integrieren
- UI reaktiv zu halten

---

# Gesamtarchitektur

```
UI (Visualization)
        ↓
Status / RequestAction
        ↓
Cache-Schicht (Attribute)
        ↓
Moduswahl
        ↓
Standard-Modus   System-Modus   Ultra-Modus
(PHP)            (OS optimiert) (CLI basiert, optional)
                     ↓               ↓
              Windows / Linux     Ultra CLI Tool
```

---

# Cache-Schicht

Persistente Symcon-Attribute:

## 1. VisualisierungsStatus
Speichert:
- Seite
- maxZeilen
- aktive Filter
- TrefferGesamt
- Ladezustände
- Theme
- Datei-Signatur

Zweck:
- UI Zustand erhalten
- unnötige Reloads vermeiden

---

## 2. SeitenCache

Speichert:
- aktuelle Tabellenzeilen
- hatWeitere
- TrefferGesamt
- Datei-Größe
- Datei-MTime
- Filter-Signaturen

Zweck:
- gleiche Seite nicht erneut laden

---

## 3. FilterMetadaten

Speichert:
- verfügbare Typen inklusive Anzahl
- verfügbare Sender inklusive Anzahl
- Gesamtzeilen
- Datei-Signatur
- Ladezeit

Zweck:
- Filteroptionen nur einmal berechnen

---

## 4. CSVExportCache

Speichert:
- Token
- Dateipfad
- Ablaufzeit (TTL)
- Scope (Seite / Gesamt)
- Logdatei
- Filter-Signatur
- Zeitstempel

Zweck:
- temporäre Exportverwaltung
- sichere Downloadlinks
- automatische Bereinigung

---

# Betriebsmodi

## Standard-Modus

### Eigenschaften
- komplette Datei wird gelesen
- alles in PHP
- exakt
- langsam bei großen Dateien

---

# System-Modus

Der System-Modus verwendet unterschiedliche Strategien pro OS.

```
System
 ├─ Windows → optimiertes PHP; PowerShell nur für spezielle Metadaten-/Fallback-Aufgaben
 └─ Linux/Unix → Shell Pipeline
```

---

# Ultra-Modus

Der Ultra-Modus nutzt optional ein externes CLI Tool zur Loganalyse.

## Eigenschaften

- kein Parsing in PHP
- CLI übernimmt Filterung
- CLI übernimmt Pagination
- CLI übernimmt Zählung
- CLI übernimmt CSV Export
- höchste Performance
- Modul bleibt ohne CLI vollständig funktionsfähig

---

# CSV Export Architektur

```
UI Button
 ↓
RequestAction
 ↓
UltraTrait::starteCsvExport
 ↓
CLI export-csv
 ↓
Temp Datei
 ↓
Token generieren
 ↓
ExportCache speichern
 ↓
Download Link anzeigen
```

---

# Download Hook Architektur

```
Browser
 ↓
/hook/loganalyzer/.../download?token
 ↓
ProcessHookData()
 ↓
Token prüfen
 ↓
ExportCache lookup
 ↓
Datei streamen
 ↓
optional löschen
```

---

# Sicherheitskonzept

- Token-basierter Download
- Token Formatprüfung
- keine Dateipfade aus URL
- kein CLI Aufruf über Hook
- TTL Ablauf
- kein Directory Zugriff
- kein Code Execution möglich
- Zugriff nur auf registrierte Exporte
- Shell-/CLI-Argumente werden vor Ausführung gekapselt

---

# Performance-Vergleich

| Modus | Lesen | Filter | Zählen | Export |
|------|------|-------|--------|--------|
Standard | PHP Vollscan | PHP | PHP | nein |
System Windows | Rückwärts | PHP | PHP | nein |
System Linux | tail | grep/awk | wc | nein |
Ultra | CLI | CLI | CLI | ja |

---

# Wichtigste Performance-Prinzipien

1. Nur Dateiende lesen wenn möglich
2. OS Tools statt PHP verwenden
3. Parsing minimieren
4. Cache konsequent nutzen
5. Queue statt vollständiger Trefferliste
6. getrennte Berechnung von:
   - Tabelle
   - Trefferanzahl
   - Filtermetadaten
7. tac nur wenn verfügbar
8. Linux nutzt Kernel-Pipeline
9. Windows nutzt optimiertes fread
10. Standard nur für kleine Dateien
11. Ultra CLI für sehr große Dateien
12. CSV Export außerhalb PHP
13. Streaming Download statt Memory
14. Token-basierte Exportverwaltung
15. Attribut-basierter Runtime Zustand
