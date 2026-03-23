<?php
/**
 * NEUSTART des Moduls: MC_ReloadModule(59139, "LogAnalyzer");
 * sudo /etc/init.d/symcon start
 * sudo /etc/init.d/symcon stop
 * sudo /etc/init.d/symcon restart
 *
 * ToDo:
 * - Versionsprüfung anpassen in Formular, Bug im Link zu Github						| erl.
 * - Oberen Bereich festhalten und nur unten scrollen, aber nur umschaltbar per Flag
 * - Mehrfachauswahl in den DropDowns
 * - Dropdown aller Logdateien, auch ältere zur Auswahl
 * - DarkLight aus Symcon Einstellung übernehmen
 * - Seiteweise vorblättern bei Auswahl der maxLines je Seite
 * - Prüfung Loggröße in Abhängigkeit der PHP memory									| erl.
 * - Große Logdateien ermöglichen
 * - Anzeige bei MaxExpand-Kachel
 * - 
*/

/**
 * Analysiert IP-Symcon Logdateien und stellt gefilterte Einträge für die Visualisierung bereit.
 *
 * Ermöglicht das Filtern nach Text, Level, Sender und ID sowie den Export der Ergebnisse
 * als CSV. Dient als Backend für eine WebFront-Visualisierung.
 *
 * @return void
 */
declare(strict_types=1);
class LogAnalyzer extends IPSModuleStrict
{
	/**
	 * Initialisiert das Modul und registriert grundlegende Eigenschaften.
	 *
	 * Setzt den Visualisierungstyp und legt die Konfigurationseigenschaft für den
	 * Logdateipfad an.
	 *
	 * @return void
	 */
    public function Create(): void
    {
        parent::Create();

        $this->SetVisualizationType(1);

        //$this->RegisterPropertyString('LogFilePath', '/var/log/symcon/logfile.log');
		$this->RegisterPropertyString("LogFilePath", IPS_GetLogDir() . "logfile.log");
		
    }
	
	/**
	 * Wird bei Änderungen oder Initialisierung des Moduls ausgeführt.
	 *
	 * Prüft den konfigurierten Logdateipfad und verwendet einen Standardpfad,
	 * falls keiner gesetzt ist. Gibt eine Debug-Meldung aus, wenn die Datei
	 * nicht existiert.
	 *
	 * @return void
	 */
	public function ApplyChanges(): void
	{
		parent::ApplyChanges();

		$path = $this->ReadPropertyString("LogFilePath");

		if (!file_exists($path)) {
			$this->SendDebug("LogFilePath", "Pfad nicht gefunden: " . $path, 0);
		} else {
			$this->SendDebug("LogFilePath", "Pfad OK: " . $path, 0);
		}
	}

	/**
	 * Liefert die HTML-Struktur für die Kachel-Visualisierung.
	 *
	 * Lädt die HTML-Datei aus dem Modulverzeichnis und stellt diese dem
	 * WebFront zur Darstellung bereit.
	 *
	 * @return string HTML-Inhalt der Visualisierung
	 */
    public function GetVisualizationTile(): string
    {
        return file_get_contents(__DIR__ . '/module.html');
    }

	/**
	 * Verarbeitet Aktionen aus der Visualisierung.
	 *
	 * Unterstützt das Laden gefilterter Logeinträge sowie den Export als CSV.
	 * Die Filterparameter werden als JSON übergeben und entsprechend ausgewertet.
	 *
	 * @param string $Ident Kennung der Aktion (z.B. 'getLogs', 'exportCSV')
	 * @param mixed  $Value JSON-codierte Parameter (Filter, Optionen)
	 * @return void
	 */
	public function RequestAction(string $Ident, mixed $Value): void
	{
		$this->SendDebug("RequestAction", "Ident: " . $Ident, 0);
		$this->SendDebug("RequestAction", "Value: " . $Value, 0);

		// =========================
		// GET LOGS (bestehend)
		// =========================
		if ($Ident === 'getLogs') {

			$data = json_decode($Value, true);
			
			$this->SendDebug("getLogs", "Filter: " . json_encode($data), 0);

			$text   = strtolower($data['text'] ?? '');
			$level  = $data['level'] ?? '';
			$sender = $data['sender'] ?? '';
			$id     = $data['id'] ?? '';
			$mode   = $data['mode'] ?? 'OR';
			$limit  = $data['limit'] ?? 200;
			
			$this->SendDebug("getLogs", "text=$text level=$level sender=$sender id=$id mode=$mode limit=$limit", 0);

			$lines = $this->ReadLogFile();

			////////////////////////////////////////////////
			// Größe der Logdatei prüfen
			if (is_string($lines)) {
				$this->SendDebug("getLogs", "Abbruch wegen Fehler", 0);
				$this->UpdateVisualizationValue(json_encode(['error' => $lines]));
				return; 
			}
			////////////////////////////////////////////////

			// Stacktraces & mehrzeilige Logs zusammenführen
			$lines = $this->GroupLogLines($lines);

			// Reihenfolge wie bisher
			$lines = array_reverse($lines);
			
			$this->SendDebug("getLogs", "Logzeilen gelesen: " . count($lines), 0);

			$rows = [];
			$levels = [];
			$senders = [];
			$ids = [];

			$totalFiltered = 0;

			foreach ($lines as $line) {

				$parsed = $this->ParseLogLine($line);
				// Wenn kein strukturiertes Format → als RAW behandeln
				if ($parsed === null) {

					$parsed = [
						'timestamp' => '',
						'id'        => '',
						'level'     => 'RAW',
						'sender'    => '',
						'message'   => $line
					];
				}

				$matchText   = ($text === '' || stripos($parsed['message'], $text) !== false);
				$matchLevel  = ($level === '' || $parsed['level'] === $level);
				$matchSender = ($sender === '' || $parsed['sender'] === $sender);
				$matchId     = ($id === '' || $parsed['id'] === $id);

				$conditions = [$matchText, $matchLevel, $matchSender, $matchId];

				$finalMatch = ($mode === 'AND')
					? !in_array(false, $conditions, true)
					: in_array(true, $conditions, true);
				
				$this->SendDebug("FilterCheck", "Match=" . (int)$finalMatch . " ID=" . $parsed['id'], 0);
				
				if (!$finalMatch) continue;

				$totalFiltered++;

				$levels[$parsed['level']] = true;
				$senders[$parsed['sender']] = true;
				$ids[$parsed['id']] = true;

				$parsed['timestamp'] = $this->FormatTimestamp($parsed['timestamp']);

				if (count($rows) < $limit) {
					$rows[] = $parsed;
				}
			}
			
			$this->SendDebug("getLogs", "Total filtered: " . $totalFiltered, 0);
			$this->SendDebug("getLogs", "Returned rows: " . count($rows), 0);

			$response = [
				'rows' => $rows,
				'meta' => [
					'totalFiltered' => $totalFiltered,
					'shown' => count($rows),
					'levels' => array_keys($levels),
					'senders' => array_keys($senders),
					'ids' => array_keys($ids)
				]
			];

			$this->UpdateVisualizationValue(json_encode($response));
			return;
		}

		// =========================
		// EXPORT CSV
		// =========================
		if ($Ident === 'exportCSV') {
			
			$this->SendDebug("exportCSV", "Export gestartet", 0);

			$data = json_decode($Value, true);
			
			$this->SendDebug("exportCSV", "Filter: " . json_encode($data), 0);

			$text   = strtolower($data['text'] ?? '');
			$level  = $data['level'] ?? '';
			$sender = $data['sender'] ?? '';
			$id     = $data['id'] ?? '';
			$mode   = $data['mode'] ?? 'OR';

			$lines = $this->ReadLogFile();
			$lines = array_reverse($lines);

			$csv = [];
			$csv[] = ['Timestamp', 'ID', 'Level', 'Sender', 'Message'];

			foreach ($lines as $line) {

				$parsed = $this->ParseLogLine($line);
				if ($parsed === null) {
					$parsed = [
						'timestamp' => '',
						'id'        => '',
						'level'     => 'RAW',
						'sender'    => '',
						'message'   => $line
					];
				}

				$matchText   = ($text === '' || stripos($parsed['message'], $text) !== false);
				$matchLevel  = ($level === '' || $parsed['level'] === $level);
				$matchSender = ($sender === '' || $parsed['sender'] === $sender);
				$matchId     = ($id === '' || $parsed['id'] === $id);

				$conditions = [$matchText, $matchLevel, $matchSender, $matchId];

				$finalMatch = ($mode === 'AND')
					? !in_array(false, $conditions, true)
					: in_array(true, $conditions, true);

				if (!$finalMatch) continue;

				$csv[] = [
					$parsed['timestamp'],
					$parsed['id'],
					$parsed['level'],
					$parsed['sender'],
					$parsed['message']
				];
			}
			
			$this->SendDebug("exportCSV", "CSV Zeilen: " . count($csv), 0);

			$output = $this->ArrayToCsv($csv);

			$this->UpdateVisualizationValue(json_encode([
				'csv' => $output
			]));

			return;
		}
			$this->SendDebug("RequestAction", "Ungültiges Ident: " . $Ident, 0);
			throw new Exception("Invalid Ident");
	}
	
	/**
	 * Liest die konfigurierte Logdatei ein.
	 *
	 * Gibt alle Logzeilen als Array zurück. Falls der Pfad leer oder die Datei
	 * nicht vorhanden ist, wird eine Debug-Meldung ausgegeben und ein leeres
	 * Array zurückgegeben.
	 *
	 * @return array Array mit Logzeilen
	 */	
    private function ReadLogFile(): array|string
    {
        $path = $this->ReadPropertyString('LogFilePath');
		
		$this->SendDebug("ReadLogFile", "Pfad: " . $path, 0);

        //if (!file_exists($path)) {
        //    return [];
        //}
		if (empty($path) || !file_exists($path)) {
			$this->SendDebug("LogFile", "Logfile nicht gefunden: " . $path, 0);
			return [];
		}
		
		////////////////////////////////////////////////
		// Größe der Logdatei prüfen
		$error = $this->IsLogFileTooLarge($path);
		if ($error !== null) {
			$this->SendDebug("LogFile", $error, 0);
			return $error; // kein UpdateVisualizationValue mehr!
		}
		////////////////////////////////////////////////
		
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$this->SendDebug("ReadLogFile", "Zeilen gelesen: " . count($lines), 0);
		return $lines;
    }

	/**
	 * Zerlegt eine Logzeile in ihre einzelnen Bestandteile.
	 *
	 * Erwartet ein durch '|' getrenntes Format und extrahiert Timestamp, ID,
	 * Level, Sender und Message. Ungültige Zeilen werden verworfen.
	 *
	 * @param string $line Eine einzelne Logzeile
	 * @return array|null Assoziatives Array oder null bei ungültigem Format
	 */
    private function ParseLogLine(string $line): ?array
    {
        $parts = explode('|', $line, 5);

		if (count($parts) < 5) {
			// Kein strukturiertes Log → als RAW behandeln (kein Fehler mehr)
			return null;
		}

        return [
            'timestamp' => trim($parts[0]),
            'id'        => trim($parts[1]),
            'level'     => trim($parts[2]),
            'sender'    => trim($parts[3]),
            'message'   => trim($parts[4])
        ];
    }
	
	/**
	 * Formatiert einen Zeitstempel in ein deutsches Datumsformat.
	 *
	 * Wandelt das Format MM/DD/YY HH:MM:SS in DD.MM.YYYY HH:MM:SS um.
	 * Falls das Format nicht erkannt wird, bleibt der Originalwert erhalten.
	 *
	 * @param string $ts Zeitstempel aus der Logdatei
	 * @return string Formatierter Zeitstempel
	 */
    private function FormatTimestamp(string $ts): string
    {
        // erwartet: 03/21/26 12:46:04
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{2}) (.*)$/', $ts, $m)) {
            $mm = $m[1];
            $dd = $m[2];
            $yy = (int)$m[3] + 2000;
            return sprintf('%02d.%02d.%04d %s', $dd, $mm, $yy, $m[4]);
        }

        return $ts;
    }

	/**
	 * Erstellt das Konfigurationsformular des Moduls.
	 *
	 * Enthält Eingabefelder für die Modulkonfiguration sowie Hinweise zur
	 * Version, Lizenz und optionalen Unterstützung (Spende).
	 *
	 * @return string JSON-kodiertes Formular für die Symcon-Oberfläche
	 */
	public function GetConfigurationForm(): string
	{
		// --- Kernel-Version prüfen ---
		$requiredVersion = '8.1';
		$installedVersion = IPS_GetKernelVersion();
		$warnLabel = [];

		if (version_compare($installedVersion, $requiredVersion, '<')) {
			$warnLabel[] = [
				'type'    => 'Label',
				'caption' => sprintf(
					$this->Translate('WARN_SYMPATH_VERSION'),
					$requiredVersion,
					$installedVersion
				),
				'color'   => '8B2500'
			];
		}

		// --- Form Elemente ---
		$baseElements = [
			// Logdatei für den Viewer
			[
				"type"    => "ValidationTextBox",
				"name"    => "LogFilePath",
				"caption" => $this->Translate("LogFilePathLabel")
			]
		];

		// --- Actions ---
		$actions = [
			[
				"type"    => "Label",
				"width"   => "50%",
				"caption" => $this->Translate('LICENSE_NOTICE')
			],
			[
				"type"    => "Label",
				"width"   => "50%",
				"bold"    => true,
				"caption" => $this->Translate('DONATION_HEADER')
			],
			[
				"type"    => "Label",
				"width"   => "50%",
				"caption" => $this->Translate('DONATION_TEXT')
			],
			[
				"type"  => "RowLayout",
				"items" => [
					[
						"type"    => "Image",
						"onClick" => "echo '" . $this->Translate('PAYPAL_LINK') . "';",
						"image"   => "data:image/png;base64,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"
					],
					[
						"type"    => "Label",
						"width"   => "70%",
						"caption" => $this->Translate('DONATION_INFO')
					]
				]
			],
			[
				"type"    => "Label",
				"caption" => $this->Translate('PAYPAL_LINK')
			]
		];

		return json_encode([
			'elements' => array_merge($warnLabel, $baseElements),
			'actions'  => $actions
		]);
	}

	/**
	 * Konvertiert ein Array in einen CSV-String.
	 *
	 * Erstellt aus einem zweidimensionalen Array eine CSV-Struktur mit
	 * Semikolon als Trennzeichen.
	 *
	 * @param array $data Zweidimensionales Array mit Daten
	 * @return string CSV-formatierter String
	 */
	private function ArrayToCsv(array $data): string
	{
		$fh = fopen('php://temp', 'r+');

		foreach ($data as $row) {
			fputcsv($fh, $row, ';');
		}

		rewind($fh);
		$csv = stream_get_contents($fh);
		fclose($fh);

		return $csv;
	}
	
	/**
	 * Prüft, ob eine einzelne Zeile den Beginn eines neuen Logeintrags darstellt.
	 *
	 * Hintergrund:
	 * Symcon-Logeinträge beginnen in der Regel mit einem Zeitstempel im Format
	 * "DD.MM.YYYY". Allerdings können Logdateien auch mehrzeilige Inhalte enthalten
	 * (z. B. Stacktraces, PHP-Fatal-Errors oder freie Textausgaben), die NICHT
	 * diesem Schema entsprechen.
	 *
	 * Diese Funktion dient dazu, solche mehrzeiligen Logeinträge korrekt zu erkennen
	 * und von Fortsetzungszeilen zu unterscheiden.
	 *
	 * @param string $line Eine einzelne Zeile aus der Logdatei.
	 *
	 * @return bool True, wenn die Zeile mit einem Datum beginnt und somit als
	 *              neuer Logeintrag interpretiert werden kann, andernfalls false.
	 */
	private function IsNewLogLine(string $line): bool
	{
		// typische Symcon-Logzeile beginnt mit Datum
		return preg_match('/^\d{2}\.\d{2}\.\d{4}/', $line) === 1;
	}

	/**
	 * Gruppiert mehrzeilige Logeinträge zu logischen Einheiten.
	 *
	 * Hintergrund:
	 * Die Symcon-Logdatei enthält neben standardisierten Einträgen auch
	 * mehrzeilige Inhalte wie:
	 * - PHP-Fatal-Errors
	 * - Stacktraces
	 * - Debug-Ausgaben mit Zeilenumbrüchen
	 *
	 * Diese Einträge entsprechen NICHT dem üblichen Schema:
	 * "Timestamp | ID | Level | Sender | Message"
	 *
	 * Stattdessen bestehen sie aus einem Startzeile mit Timestamp und mehreren
	 * nachfolgenden Zeilen ohne Zeitstempel.
	 *
	 * Diese Funktion:
	 * - erkennt neue Logeinträge anhand eines Zeitstempels
	 * - fügt nachfolgende Zeilen (z. B. Stacktraces) dem aktuellen Eintrag hinzu
	 * - gruppiert dadurch zusammengehörige mehrzeilige Inhalte zu einem String
	 *
	 * @param array $lines Array mit einzelnen Logzeilen aus der Datei.
	 *                     Jede Zeile entspricht einem String.
	 *
	 * @return array Array von gruppierten Logeinträgen.
	 *               Jeder Eintrag kann mehrere Zeilen enthalten, getrennt durch "\n".
	 */
	private function GroupLogLines(array $lines): array
	{
		$grouped = [];
		$current = '';

		foreach ($lines as $line) {

			if ($this->IsNewLogLine($line)) {

				// neue Logzeile beginnt → vorherige abschließen
				if ($current !== '') {
					$grouped[] = $current;
				}

				$current = $line;

			} else {
				// Fortsetzung (Stacktrace etc.)
				$current .= "\n" . $line;
			}
		}

		if ($current !== '') {
			$grouped[] = $current;
		}

		return $grouped;
	}
	
	private function IsLogFileTooLarge(string $path): ?string
	{
		if (!file_exists($path)) {
			return "Logdatei nicht gefunden.";
		}

		$fileSize = filesize($path);

		// memory_limit holen (z.B. "32M")
		$memoryLimit = ini_get('memory_limit');
		$bytes = $this->ConvertToBytes($memoryLimit);

		// 10% vom Memory, max 10MB
		$maxSize = min($bytes * 0.1, 20 * 1024 * 1024);

		// Fallback Minimum (optional)
		$maxSize = max($maxSize, 2 * 1024 * 1024);

		if ($fileSize > $maxSize) {

			$fileSizeMB = round($fileSize / 1024 / 1024, 2);
			$maxSizeMB  = round($maxSize / 1024 / 1024, 2);

			return sprintf(
				$this->Translate("LOGFILE_TOO_LARGE"),
				$fileSizeMB,
				$maxSizeMB
			);
		}

		return null;
	}
	
	private function ConvertToBytes(string $val): int
	{
		$val = trim($val);
		$last = strtolower($val[strlen($val)-1]);

		$num = (int)$val;

		switch ($last) {
			case 'g': $num *= 1024;
			case 'm': $num *= 1024;
			case 'k': $num *= 1024;
		}

		return $num;
	}	
	
}
?>