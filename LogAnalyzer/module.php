<?php
/**
 * NEUSTART des Moduls: MC_ReloadModule(59139, "LogAnalyzer");
 * sudo /etc/init.d/symcon start
 * sudo /etc/init.d/symcon stop
 * sudo /etc/init.d/symcon restart
 *
 * ToDo:
 * - Ultra Version einbauen
 * - CSV Export neu implementieren
 * - DarkLight Abruf vom Tile ausgehend
 * - Ladezeit Filter 0ms, wenn nicht notwendig zum Laden, bei Modi System
*/

declare(strict_types=1);
require_once __DIR__ . '/libs/LogAnalyzerStandardTrait.php';
require_once __DIR__ . '/libs/LogAnalyzerSystemTrait.php';
require_once __DIR__ . '/libs/LogAnalyzerUltraTrait.php';

class LogAnalyzer extends IPSModuleStrict
{
	use LogAnalyzerStandardTrait;
	use LogAnalyzerSystemTrait;
	use LogAnalyzerUltraTrait;

	private const ATTR_STATUS = 'VisualisierungsStatus';
	private const ATTR_FILTERMETA = 'FilterMetadaten';
	private const ATTR_SEITENCACHE = 'SeitenCache';

    /**
     * Create
     *
     * Wird beim Erstellen der Modulinstanz aufgerufen.
     * - Registriert Eigenschaften, Attribute und Timer
     * - Initialisiert Standardwerte für Status und Cache
     *
     * Parameter: keine
     * Rückgabewert: void
     */
	public function Create(): void
	{
		// nicht löschen
		parent::Create();

		$this->RegisterPropertyString('LogDatei', IPS_GetLogDir() . 'logfile.log');		// nur zu Initialisierung, wird später überschrieben
		$this->RegisterPropertyInteger('MaxZeilen', 50);
		$this->RegisterPropertyBoolean('VerwendeSift', false);
		$this->RegisterPropertyInteger('AutoRefreshSekunden', 0);
		$this->RegisterPropertyString('Betriebsmodus', 'standard');
		$this->RegisterPropertyString('UltraProgrammPfad', '');

		$this->RegisterAttributeString(self::ATTR_STATUS, json_encode([
			'seite'                    => 0,
			'maxZeilen'                => 50,
			'theme'                    => 'dark',
			'kompakt'                  => false,
			'filterTypen'              => [],
			'objektIdFilter'           => '',
			'senderFilter'             => [],
			'textFilter'               => '',
			'trefferGesamt'            => -1,
			'zaehlungLaeuft'           => false,
			'dateiGroesseCache'        => 0,
			'dateiMTimeCache'          => 0,
			'zaehlSignatur'            => '',
			'tabellenLadungLaeuft'     => false,
			'tabellenLadungText'       => '',
			'letzteTabellenLadezeitMs' => 0,
			'ultraBuildLaeuft'         => false,
			'ultraBuildDatei'          => '',
			'ultraIndexBereit'         => false
		], JSON_THROW_ON_ERROR));

		$this->RegisterAttributeString(self::ATTR_FILTERMETA, json_encode([
			'verfuegbareFilterTypen' => [],
			'verfuegbareSender'      => [],
			'dateiGroesseCache'      => 0,
			'dateiMTimeCache'        => 0,
			'ladezeitMs'             => 0,
			'laedt'                  => false,
			'signatur'               => ''
		], JSON_THROW_ON_ERROR));

		$this->RegisterAttributeString(self::ATTR_SEITENCACHE, json_encode([
			'listenSignatur'    => '',
			'zaehlSignatur'     => '',
			'dateiGroesseCache' => 0,
			'dateiMTimeCache'   => 0,
			'trefferGesamt'     => -1,
			'hatWeitere'        => false,
			'zeilen'            => []
		], JSON_THROW_ON_ERROR));

		// Visu Aktulisieren
		$this->RegisterTimer('VisualisierungAktualisieren', 0, 'LOGANALYZER_AktualisierenVisualisierung($_IPS["TARGET"]);');
	}

    public function Destroy(): void
    {
		// nicht löschen
        parent::Destroy();
    }

    /**
     * ApplyChanges
     *
     * Wird bei Änderungen der Modulkonfiguration aufgerufen.
     * - Aktualisiert Timer, Visualisierung und Zusammenfassung
     * - Prüft und korrigiert den Logdateipfad bei Bedarf
     *
     * Parameter: keine
     * Rückgabewert: void
     */
    public function ApplyChanges(): void
    {
		// nicht löschen
        parent::ApplyChanges();

		// Tile Visu nutzen
        $this->SetVisualizationType(1);

        $intervall = max(0, $this->ReadPropertyInteger('AutoRefreshSekunden')) * 1000;
        $this->SetTimerInterval('VisualisierungAktualisieren', $intervall);

        $logDatei = $this->ReadPropertyString('LogDatei');

		if (!is_file($logDatei)) {
			$verfuegbareDateien = $this->ermittleVerfuegbareLogdateien();
			if (count($verfuegbareDateien) > 0) {
				$logDatei = (string) $verfuegbareDateien[0]['pfad'];
				IPS_SetProperty($this->InstanceID, 'LogDatei', $logDatei);
			}
		}
		
        $summary = basename($logDatei);
        if (is_file($logDatei)) {
            $summary .= ' · ' . $this->formatiereDateigroesse((int) filesize($logDatei));
        }
		
		if (IPS_GetProperty($this->InstanceID, 'LogDatei') !== $logDatei) {
			IPS_ApplyChanges($this->InstanceID);
			return;
		}
        $this->SetSummary($summary);
    }

    /**
     * GetConfigurationForm
     *
     * Liefert das Konfigurationsformular des Moduls.
     * - Erstellt die angezeigten Hinweise und Aktionen
     * - Gibt das Formular als JSON zurück
     *
     * Parameter: keine
     * Rückgabewert: string
     */
	public function GetConfigurationForm(): string
	{
		$elements = [
			[
				"type"    => "Label",
				"caption" => 'Alle Einstellungen werden direkt in der Tile View direkt konfiguriert.'
			],
			[
				"type"    => "Label",
				"caption" => 'Die alte WebFront Visualisierung wird nicht unterstützt. Weitere Infos sind in der Anleitung zu finden.'
			],
			[
				"type"    => "ValidationTextBox",
				"name"    => "UltraProgrammPfad",
				"caption" => "Pfad zum Ultra-Programm"
			],
			[
				"type"    => "Label",
				"caption" => "Beispiel Debian 13: /usr/local/bin/loganalyzer_ultra_amd64-debian"
			]
		];

		$actions = [
			[
				'type'    => 'Button',
				'caption' => 'Visualisierung aktualisieren',
				'onClick' => 'LOGANALYZER_AktualisierenVisualisierung($id);'
			],
			[
				"type"    => "Label",
				"width"   => "50%",
				"caption" => "Diese Software steht unter der Apache-2.0-Lizenz und ist sowohl privat als auch kommerziell kostenlos nutzbar. Sie kann von dir genutzt werden, ohne dass Lizenzgebühren anfallen. Jegliche Haftung für Schäden ist ausgeschlossen. Weitere Informationen zum Modul und seiner Funktionsweise finden Sie auf GitHub: https://github.com/BugForgeNerd/LogAnalyzer"
			],
			[
				"type"    => "Label",
				"width"   => "50%",
				"bold"    => true,
				"caption" => "Spenden zur Stärkung der OpenSource Entwickler"
			],
			[
				"type"    => "Label",
				"width"   => "50%",
				"caption" => "Open Source bietet Kostenersparnis, hohe Sicherheit, Transparenz und Unabhängigkeit von Herstellern. Durch den offenen Quellcode ist Software flexibel anpassbar, interoperabel und profitiert von einer aktiven Community, die Fehler schnell findet und behebt."
			],
			[
				"type"  => "RowLayout",
				"items" => [
					[
						"type"    => "Image",
						"onClick" => "echo '" . "https://www.paypal.com/donate/?hosted_button_id=GPLYXLH6AJYF8" . "';",	
						"image"   => 						"data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADsAAAA6CAYAAAAOeSEWAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAoiSURBVGhD7ZprkBTVFcd/5/bsC1heAvIKLAgsuwqYGI3ECPjAfAhRtOKjKjGlljwUTfIhSlZNrVtBVqIJsomFIBUrqZRVPkvFqphofMVoJEYSXrsrbxU1PFR2kd3Zmb4nH3qmp+fua4adjR/0V9U1955zunv+fe89t/t2wxcIcQ19p9ZQNeBiPEa7nh5RPiPhH8Kwh5Pje3ilLumG9JXCi62qvw3Puys4tDqn0EiZlC8dE/m12gI8D/4GdtT8GcTd8YQovNjqu1/EeOcFlajY7spp0nqci2P1eWLmB2y55WDEcUIY19BnRKZ3bsE0rqB0vbt4QGQ+SfvCSZWryl1XvhRW7Iz6YZAeq27rud25K4Fua0tgMjLjiGGl48ybwopN+JWISJc6IDI20+W0uGg5unPkghmuHzJr9dCIM28KK9bEqoLWiP757nC7sHuF0kJTdpHSo/H2c5ygvCisWHS6U8+uQqQVo60cjXO7eyTeyPiIM28KK1YkJTb1512tmlQ6WpVEC8HWCh2tmd+Olsh2TEkeV2yHhmNc6dPcW1ixms7EqdaR6DhUaDsM8cNC+xHCLR75zdoOC20Hhc8OCMfeVdqPKMNL26Kny5fCiZ1bWwpM7jrRpGx+e8SWB2qFjlbh0gs2sH7vNa47Vwon9mDZKRiJBZVI66axSQXtLWt1z+ByZXB5KcgGb/2u8113LhROrBBk4lSlU/KxiXRkhrCb58DUU9Il42vsrmxnbhROrGrkzsnNTN2I1S7iumPGaZmycBYP7j456s6FwoklnYnDenbrdiU2V8aPV8aPjVoEjU2LGnKhcGKFqkyDRrtzqnyiYsXARV0MUT9Z6pp6ozBiL7/cA6nsNNVEsR2Zcj5j9bw5ypguHo3FO+KaeqMwYrfNHI/IwIwh0qIAahW1GUN3Y9W9COfMVmaflW0L8Im17XSNvVEYsaZoujvTZBFt1Z5IX4SBA5XLLlHmnetGBCjNXF/V6pp7ozBisVVZVbflbC53eQIjRyoXnq8sWwxVlW5ABtE/uaZc6Kk9cqeq/gE8b0l2Bo6QOKpMHg2lJdlZuqgIBg6E4cNg7BgoH+Tu2RVKMjmLG6dscx29URix1b98GSNzXXOAwpxZltMrC3Mu5RmWVCx0zblQiG4soKlu3EWrKjBpXGGEwtESqz9xjbnSd7HTVw5HGBWo6kJTzIPBkUR94rRj7ZXxGybtcx250nexalLza3SOjTB0oHaaUvJFZR+auJClk//iuvKh72JN9M6Jzq07bHB2PR9UdqLcSssnM1gy9XXXnS99vORA9ap7MOanQaWLrnxWlXJ25Ca+Mx2oPg7SimoHokcwZg/t/iZumrwTKcwCOZ3/2QlQvepZjPmOaw6787e/oVROdJ0R9A4WT+rzMmku9L0bQxeLbJHGGN5LN07o311Tf9E3sWfUDkCoCCppgenOklpS7UmsKpQmd7jm/qJvYluLpyDidcrAacGDyhTPc3wRRI5w3dTDrrm/6JtYz0zKNjiie2pVAJXmQiag3ujhsufAxPkfEfc9fN2M1U3BZjPbzKnK2BE9LGzL8zx73zOutb/oezbuifX7HgAWu+YQ5VaWVNzrmvuLvnXj3nEytYNPk2vqT/pPrKqA9PBQio+Yza6xP+nfbrx2zyKMN8M1A6D2ZZZOetI1f8mXfEmPZI/ZyhVfh9g3s2zB6tkHeO0vsaPu42xfAVm3Zy5iZmIjS64YH5H38I6/lPdq4rq90xGZj7WCxp7ghgkHssVW370RYxZ0uXCmtIL/fbbXbMx2FIh1+99C9GuuOcVhlIUsqcj9mXbd3l8gcjsACb7FsorXo1OPQPpNnIK1cXzNLPiKlKPyALW1hZ+ual+KOZ8otEPWW/YRwG8j9RwwwfEUKDZNZM2zZ9SWgZkICqpxBhcNY9TxgST81IM5IDKWJ0uHhPVC8ZWJ4xAGBBX5iKGbyhl6bBCqkbsrqaJW87jQ4cU7yKIJH5M1ZivrZ1DkbQHAahM7lgcrhtNWnklxbBMIqI1TOrScycMsW3YvxOMCVMqA7SW27fG4lKWWOP3NJUYOxK1ZAIDYN2i87c3wXDNqx5Mo+17g023cfKVHzKQWvuVFFk+8EIB1+y9G9CkAlA9ZUjGONe+UUFp8BcI5qMbA/rvE2OfifvECDCD8jSGtW/m0vAW0GHiVxRXzAFJvygGP6Zmxqo2AMLfW46AsCmMs/xzEB0OObf/0aYq8VCIL9onLgFsxMhIAX+rjfuJJYrHVCGDlH8BsSL0E2172R2JmLgrY5FUYGROeQ2kChIf2lpDQa0M7vFa6Yc/Edms2AsE6jwjgEbfeYYyOAMCaH3FkSAueXxwcT5vTB4h2i6owKYmZR/XdTRwacAjjLQpaVRW1vz7WNuBhjARCrTZh7VqsvhEKBVDbxFWJt0F3BQ/xnMnMe0YBsPWMmzFmLghY/2Eab3sUidxWil7Bun1NdMhB4JLgeNiY2vvbffNUKBTZDKwFtiAEQgFsohmxkfFvwsWBjNjwsx5AGIbxpmFkKCJgbQLr34GRQ4jMD/6ovjxlwvuns2P5jZz61rlY+1a4v+83UVdnUR5JHdsjkVxQXH1XNZ4E603Wf48YNwEafKIQMgJhKkLwLkRoQ1maVEYjzApC9HEOTDiTxRXLKNKzQfaHexfFtmclO5HwYSMj1ka+ibD2aZL+Gnz7K6x/M76dRmPNSuCCIETBT96/67nfxAF47DEf2E3gsmh5cALfPhK+5BJZ2CGxhxApC2L8a9la80nwwBARq/owyhpU7kXtDfhMYWnFBjyTeSOtch91YgG4dlI7qu+m7Ed573cfZV089RrTxWDMXv6ox/a9wWt7VR9frqZ5eedJXMl8GWq8MCuXzVoxri0hFwUx+iG7ftwCQHPNNqpXbUfkVMR8N0yH6jfQePtfAVjffBJSGgwB5SiLK67uZvUiMwtYDb9hLF67r7oDzgZAdKfeWafy4HWVwazCMT5Y/346NmjZ/zRPQCRI/cqHNC8/lg7IQnRbMKwFjNxLVX091fUr2hJFb2JkWCoqvJJBF9B0Vw4s1m5jZHtNJqQ0822E8E43QkElmCkAPLOB9XvrWL9/VYfRVxGKghga5U4VlOqgrjupqwt6QCjWi75M1qbgT3ZBWfujWLsDFESG4sV+hvFuB4LMB+lsmiGhmWUX1TiqP+SVuszXX1ldrqeH+diDCAeCso4G+TnoLYjxwxCrTYzcNwrRVC/IjFdCsSr/xWoDNtmA2tXRgCz+VXcctXPwtR7VF7B2I8nkMkhchPUbsH4DXuL3kT2EGMuCooL6dTTWZD+wi+4GGoAGPP6Q5YuyZNxhtGM2yhqQF1GeArkGXy8L9tcGPPsExrfh8bDroofIvjcuMN5p9Zf66j2BIPj+a5z29rxUMvtc6D+x01eMwRRtwZgRqG0pMfar8a01e9yw/yd53Gvmi+nAk+sRXQh2zuct9AvH/wAcerqGMemSoQAAAABJRU5ErkJggg=="
					],
					[
						"type"    => "Label",
						"caption" => " "
					],
					[
						"type"    => "Label",
						"width"   => "70%",
						"caption" => "Wenn Sie mich unterstützen möchten, dann geht das ganz einfach und freiwillig unter dem folgenden Link."
					],
					[
						"type"    => "Label",
						"caption" => " "
					]
				]
			],
			[
				"type"    => "Label",
				"width"   => "50%",
				"caption" => "https://www.paypal.com/donate/?hosted_button_id=GPLYXLH6AJYF8"
			]

        ];

        return json_encode([
            'elements' => $elements,
            'actions'  => $actions
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * GetVisualizationTile
     *
     * Liefert die HTML-Visualisierung für die Tile-Ansicht.
     * - Lädt die HTML-Datei des Moduls
     * - Übergibt die initialen Visualisierungsdaten
     *
     * Parameter: keine
     * Rückgabewert: string
     */
    public function GetVisualizationTile(): string
    {
        $start = microtime(true);
        $logDatei = $this->ReadPropertyString('LogDatei');
        $modus = $this->ermittleAktivenModus();
        $dateiGroesse = is_file($logDatei) ? (int) @filesize($logDatei) : 0;

        $this->SendDebug(
            'TileInit',
            sprintf(
                'phase=start modus=%s datei=%s groesseBytes=%d',
                $modus,
                basename($logDatei),
                $dateiGroesse
            ),
            0
        );

        $datei = __DIR__ . '/module.html';	// ggf auch automatisch
        if (!is_file($datei)) {
            $this->SendDebug('TileInit', 'phase=fehler grund=module-html-fehlt', 0);
            return '<div style="padding:1rem;font-family:sans-serif;">module.html nicht gefunden.</div>';
        }

        $html = file_get_contents($datei);
        if ($html === false) {
            $this->SendDebug('TileInit', 'phase=fehler grund=module-html-lesefehler', 0);
            return '<div style="padding:1rem;font-family:sans-serif;">module.html konnte nicht geladen werden.</div>';
        }

		$cssDatei = __DIR__ . '/module.css';
		$cssBlock = '';
		if (is_file($cssDatei)) {
			$css = file_get_contents($cssDatei);
			if ($css !== false) {
				$cssBlock = "<style>\n" . $css . "\n</style>";
			} else {
				$this->SendDebug('TileInit', 'phase=warnung grund=module-css-lesefehler', 0);
			}
		} else {
			$this->SendDebug('TileInit', 'phase=warnung grund=module-css-fehlt', 0);
		}
	
        try {
            $initialDaten = $this->erstelleVisualisierungsDaten();

            $this->SendDebug(
                'TileInit',
                sprintf(
                    'phase=ende modus=%s datei=%s ok=%s zeilen=%d dauerMs=%d',
                    $modus,
                    basename($logDatei),
                    ($initialDaten['ok'] ?? false) ? 'true' : 'false',
                    is_array($initialDaten['zeilen'] ?? null) ? count($initialDaten['zeilen']) : 0,
                    (int) round((microtime(true) - $start) * 1000)
                ),
                0
            );

			$html = str_replace('%%MODULE_CSS%%', $cssBlock, $html);

            return str_replace(
                '%%INITIAL_DATA%%',
                json_encode($initialDaten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $html
            );
        } catch (\Throwable $e) {
            $this->SendDebug(
                'TileInit',
                sprintf(
                    'phase=exception modus=%s datei=%s dauerMs=%d meldung=%s',
                    $modus,
                    basename($logDatei),
                    (int) round((microtime(true) - $start) * 1000),
                    $e->getMessage()
                ),
                0
            );
            throw $e;
        }
    }

    /**
     * RequestAction
     *
     * Verarbeitet Aktionen aus der Visualisierung.
     * - Reagiert auf Navigation, Filter, Einstellungen und Dateiauswahl
     * - Aktualisiert Status, Cache und Anzeige
     *
     * Parameter: string $Ident, mixed $Value
     * Rückgabewert: void
     */
	public function RequestAction(string $Ident, mixed $Value): void
	{
		$this->SendDebug('RequestAction', 'Ident=' . $Ident . ' Value=' . print_r($Value, true), 0);
		try {
			$status = $this->leseStatus();
			switch ($Ident) {
				case 'Laden':
				case 'Aktualisieren':
					$status['trefferGesamt'] = -1;
					$status['zaehlungLaeuft'] = false;
					$status['dateiGroesseCache'] = 0;
					$status['dateiMTimeCache'] = 0;
					$status['zaehlSignatur'] = '';
					$this->schreibeStatus($status);
					$this->leereSeitenCache();

					if ($this->ermittleAktivenModus() === 'ultra') {
						$this->leereUltraAnalyseFuerAktuelleDatei();

						$status['ultraBuildLaeuft'] = false;
						$status['ultraBuildDatei'] = '';
						$status['ultraIndexBereit'] = false;
						$this->schreibeStatus($status);
					}

					$modusPruefung = $this->pruefeModusVerwendbarkeit();
					if (!(bool) ($modusPruefung['ok'] ?? false)) {
						$this->setzeTabellenLadezustand(false, '', 'RequestAction/Aktualisieren-modus-blockiert');
						$this->aktualisiereVisualisierung();
						return;
					}

					$this->setzeTabellenLadezustand(true, 'Tabelle wird aktualisiert …', 'RequestAction/Aktualisieren');
					$this->aktualisiereVisualisierungNurStatus('vor-tabellenladung-aktualisieren');
					$this->aktualisiereVisualisierung();
					return;
				case 'SeiteVor':
					$maxZeilen = $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50));
					$aktuelleSeite = max(0, (int) ($status['seite'] ?? 0));
					$trefferGesamt = (int) ($status['trefferGesamt'] ?? -1);

					if ($trefferGesamt >= 0) {
						$maxSeite = max(0, (int) ceil($trefferGesamt / $maxZeilen) - 1);
						$status['seite'] = min($aktuelleSeite + 1, $maxSeite);
					} else {
						$status['seite'] = $aktuelleSeite + 1;
					}

					$this->schreibeStatus($status);

					$modusPruefung = $this->pruefeModusVerwendbarkeit();
					if (!(bool) ($modusPruefung['ok'] ?? false)) {
						$this->setzeTabellenLadezustand(false, '', 'RequestAction/SeiteVor-modus-blockiert');
						$this->aktualisiereVisualisierung();
						return;
					}

					$this->setzeTabellenLadezustand(true, 'Ältere Einträge werden geladen …', 'RequestAction/SeiteVor');
					$this->aktualisiereVisualisierungNurStatus('vor-tabellenladung-seitevor');
					$this->aktualisiereVisualisierung();
					return;
				case 'SeiteZurueck':
					$status['seite'] = max(0, (int) $status['seite'] - 1);
					$this->schreibeStatus($status);

					$modusPruefung = $this->pruefeModusVerwendbarkeit();
					if (!(bool) ($modusPruefung['ok'] ?? false)) {
						$this->setzeTabellenLadezustand(false, '', 'RequestAction/SeiteZurueck-modus-blockiert');
						$this->aktualisiereVisualisierung();
						return;
					}
					$this->setzeTabellenLadezustand(true, 'Neuere Einträge werden geladen …', 'RequestAction/SeiteZurueck');
					$this->aktualisiereVisualisierungNurStatus('vor-tabellenladung-seitezurueck');
					$this->aktualisiereVisualisierung();
					return;
				case 'FilterAnwenden':
					$daten = $this->dekodiereJsonArray((string) $Value);

					$status['filterTypen'] = $this->normalisiereFilterTypen($daten['filterTypen'] ?? []);
					$status['objektIdFilter'] = $this->normalisiereObjektIdFilterString((string) ($daten['objektIdFilter'] ?? ''));
					$status['senderFilter'] = $this->normalisiereSenderFilter($daten['senderFilter'] ?? []);
					$status['textFilter'] = trim((string) ($daten['textFilter'] ?? ''));
					$status['seite'] = 0;

					$status['trefferGesamt'] = -1;
					$status['zaehlungLaeuft'] = false;
					$status['dateiGroesseCache'] = 0;
					$status['dateiMTimeCache'] = 0;
					$status['zaehlSignatur'] = '';

					$this->schreibeStatus($status);
					$this->leereSeitenCache();

					if ($this->ermittleAktivenModus() === 'standard') {
						$this->schreibeFilterMetadaten([
							'verfuegbareFilterTypen' => [],
							'verfuegbareSender'      => [],
							'gesamtZeilenCache'      => -1,
							'dateiGroesseCache'      => 0,
							'dateiMTimeCache'        => 0,
							'ladezeitMs'             => 0,
							'laedt'                  => false,
							'signatur'               => ''
						]);
					}

					$modusPruefung = $this->pruefeModusVerwendbarkeit();
					if (!(bool) ($modusPruefung['ok'] ?? false)) {
						$this->setzeTabellenLadezustand(false, '', 'RequestAction/FilterAnwenden-modus-blockiert');
						$this->aktualisiereVisualisierung();
						return;
					}

					$this->setzeTabellenLadezustand(true, 'Gefilterte Tabelle wird geladen …', 'RequestAction/FilterAnwenden');
					$this->aktualisiereVisualisierungNurStatus('vor-tabellenladung-filteranwenden');
					$this->aktualisiereVisualisierung();
					return;
				case 'ZaehleTreffer':
					$this->zaehleTrefferAsynchron();
					return;
				case 'LadeFilterOptionen':
					$this->ladeFilterMetadatenAsynchron();
					return;
				case 'LogDateiAuswaehlen':
					$datei = trim((string) $Value);
					$verfuegbareDateien = $this->ermittleVerfuegbareLogdateien();
					$gueltigePfade = array_column($verfuegbareDateien, 'pfad');

					if (!in_array($datei, $gueltigePfade, true)) {
						throw new Exception('Ungültige Logdatei ausgewählt: ' . $datei);
					}

					IPS_SetProperty($this->InstanceID, 'LogDatei', $datei);
					IPS_ApplyChanges($this->InstanceID);

					$status = [
						'seite'                    => 0,
						'maxZeilen'                => $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50)),
						'theme'                    => $this->normalisiereTheme((string) ($status['theme'] ?? 'dark')),
						'kompakt'                  => $this->normalisiereKompakt($status['kompakt'] ?? false),
						'filterTypen'              => [],
						'objektIdFilter'           => '',
						'senderFilter'             => [],
						'textFilter'               => '',
						'trefferGesamt'            => -1,
						'zaehlungLaeuft'           => false,
						'dateiGroesseCache'        => 0,
						'dateiMTimeCache'          => 0,
						'zaehlSignatur'            => '',
						'tabellenLadungLaeuft'     => false,
						'tabellenLadungText'       => '',
						'letzteTabellenLadezeitMs' => 0,
						'ultraBuildLaeuft'         => false,
						'ultraBuildDatei'          => '',
						'ultraIndexBereit'         => false
					];
					$this->schreibeStatus($status);

					$this->schreibeFilterMetadaten([
						'verfuegbareFilterTypen' => [],
						'verfuegbareSender'      => [],
						'gesamtZeilenCache'      => -1,
						'dateiGroesseCache'      => 0,
						'dateiMTimeCache'        => 0,
						'ladezeitMs'             => 0,
						'laedt'                  => false,
						'signatur' => ''
					]);

					$this->leereSeitenCache();

					$modusPruefung = $this->pruefeModusVerwendbarkeit();
					if (!(bool) ($modusPruefung['ok'] ?? false)) {
						$this->setzeTabellenLadezustand(false, '', 'RequestAction/LogDateiAuswaehlen-modus-blockiert');
						$this->aktualisiereVisualisierung();
						return;
					}

					$this->setzeTabellenLadezustand(true, 'Logdatei wird geladen …', 'RequestAction/LogDateiAuswaehlen');
					$this->aktualisiereVisualisierungNurStatus('vor-tabellenladung-logdateiwechsel');
					$this->aktualisiereVisualisierung();
					return;

				case 'SetzeMaxZeilen':
					$status['maxZeilen'] = $this->normalisiereMaxZeilen((int) $Value);
					$status['seite'] = 0;
					$this->schreibeStatus($status);

					$modusPruefung = $this->pruefeModusVerwendbarkeit();
					if (!(bool) ($modusPruefung['ok'] ?? false)) {
						$this->setzeTabellenLadezustand(false, '', 'RequestAction/SetzeMaxZeilen-modus-blockiert');
						$this->aktualisiereVisualisierung();
						return;
					}

					$this->setzeTabellenLadezustand(true, 'Tabellenbereich wird neu geladen …', 'RequestAction/SetzeMaxZeilen');
					$this->aktualisiereVisualisierungNurStatus('vor-tabellenladung-maxzeilen');
					$this->aktualisiereVisualisierung();
					return;

				case 'SetzeTheme':
					$status['theme'] = $this->normalisiereTheme((string) $Value);
					$this->schreibeStatus($status);
					$this->aktualisiereVisualisierung();
					return;

				case 'SetzeKompakt':
					$status['kompakt'] = $this->normalisiereKompakt($Value);
					$this->schreibeStatus($status);
					$this->aktualisiereVisualisierung();
					return;

				case 'SetzeBetriebsmodus':
					$modus = strtolower(trim((string) $Value));
					if (!in_array($modus, ['standard', 'system', 'ultra'], true)) {
						$modus = 'standard';
					}

					IPS_SetProperty($this->InstanceID, 'Betriebsmodus', $modus);
					IPS_ApplyChanges($this->InstanceID);

					$status['trefferGesamt'] = -1;
					$status['zaehlungLaeuft'] = false;
					$status['dateiGroesseCache'] = 0;
					$status['dateiMTimeCache'] = 0;
					$status['zaehlSignatur'] = '';
					$status['tabellenLadungLaeuft'] = false;
					$status['tabellenLadungText'] = '';
					$status['ultraBuildLaeuft'] = false;
					$status['ultraBuildDatei'] = '';
					$status['ultraIndexBereit'] = false;
					$this->schreibeStatus($status);

					$this->schreibeFilterMetadaten([
						'verfuegbareFilterTypen' => [],
						'verfuegbareSender'      => [],
						'gesamtZeilenCache'      => -1,
						'dateiGroesseCache'      => 0,
						'dateiMTimeCache'        => 0,
						'ladezeitMs'             => 0,
						'laedt'                  => false,
						'signatur' => ''
					]);

					$this->leereSeitenCache();
					$this->aktualisiereVisualisierung();
					return;
			}

			throw new Exception('Unbekannte Aktion: ' . $Ident);
		} catch (\Throwable $e) {
			$this->SendDebug('RequestAction FEHLER', $e->getMessage(), 0);
			throw $e;}
	}

    /**
     * AktualisierenVisualisierung
     *
     * Öffentliche Methode zum Aktualisieren der Visualisierung.
     * - Ruft die interne Aktualisierung der Anzeige auf
     * - Kann durch Timer oder Aktion verwendet werden
     *
     * Parameter: keine
     * Rückgabewert: void
     */
	public function AktualisierenVisualisierung(): void
	{
		$this->aktualisiereVisualisierung();
	}

    /**
     * aktualisiereVisualisierungNurStatus
     *
     * Aktualisiert nur den Statusbereich der Visualisierung.
     * - Erstellt kompakte Statusdaten für die Oberfläche
     * - Überträgt die Daten ohne vollständigen Tabellenneuaufbau
     *
     * Parameter: string $quelle
     * Rückgabewert: void
     */
    private function aktualisiereVisualisierungNurStatus(string $quelle = ''): void
    {
        $daten = $this->erstelleVisualisierungsStatusDaten();
        $this->uebernehmeLadeAnzeigeInDaten($daten);

        $this->SendDebug(
            'VisualisierungStatus',
            sprintf(
                'quelle=%s zeilen=%d treffer=%d zaehlung=%s filterLaeuft=%s tabellenLadung=%s ladezeitMs=%d filterLadezeitMs=%d',
                $quelle !== '' ? $quelle : '-',
                is_array($daten['zeilen'] ?? null) ? count($daten['zeilen']) : 0,
                (int) ($daten['trefferGesamt'] ?? -1),
                ($daten['zaehlungLaeuft'] ?? false) ? 'true' : 'false',
                ($daten['filterMetadatenLaeuft'] ?? false) ? 'true' : 'false',
                ($daten['tabellenLadungLaeuft'] ?? false) ? 'true' : 'false',
                (int) ($daten['ladezeitMs'] ?? 0),
                (int) ($daten['filterLadezeitMs'] ?? 0)
            ),
            0
        );

        $this->sendeLadeAnzeigeDebug('aktualisiereVisualisierungNurStatus/' . ($quelle !== '' ? $quelle : '-'), $daten);

        $erfolg = $this->UpdateVisualizationValue(
            json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        $this->SendDebug('UpdateVisualizationValue Status', $erfolg ? 'true' : 'false', 0);
    }

    /**
     * aktualisiereVisualisierung
     *
     * Aktualisiert die vollständige Visualisierung des Moduls.
     * - Erstellt neue Anzeigedaten und übergibt sie an die Oberfläche
     * - Setzt Ladezustände nach erfolgreichem Aufbau zurück
     *
     * Parameter: keine
     * Rückgabewert: void
     */
    private function aktualisiereVisualisierung(): void
    {
        try {
            $start = microtime(true);
            $daten = $this->erstelleVisualisierungsDaten();
            $dauerMs = (int) round((microtime(true) - $start) * 1000);

            $statusVorher = $this->leseStatus();
            $statusNachher = $statusVorher;

            $statusNachher['letzteTabellenLadezeitMs'] = (int) ($daten['ladezeitMs'] ?? 0);

            if ((bool) ($statusNachher['tabellenLadungLaeuft'] ?? false)) {
                $statusNachher['tabellenLadungLaeuft'] = false;
                $statusNachher['tabellenLadungText'] = '';
                $this->SendDebug('Ladebalken', 'quelle=aktualisiereVisualisierung sichtbar=false text=-', 0);
            }

            $this->schreibeStatus($statusNachher);

            $daten['status'] = $statusNachher;
            $daten['tabellenLadungLaeuft'] = false;
            $daten['tabellenLadungText'] = '';
            $daten['ladezeitMs'] = (int) ($statusNachher['letzteTabellenLadezeitMs'] ?? 0);

            $this->uebernehmeLadeAnzeigeInDaten($daten);

            $this->SendDebug(
                'Visualisierung',
                sprintf(
                    'ok=%s datei=%s seite=%d maxZeilen=%d zeilen=%d hatWeitere=%s treffer=%d zaehlung=%s filterGeladen=%s filterLaeuft=%s tabellenLadungVorher=%s tabellenLadungNachher=%s dauerMs=%d',
                    ($daten['ok'] ?? false) ? 'true' : 'false',
                    basename((string) ($daten['logDatei'] ?? '')),
                    (int) (($daten['status']['seite'] ?? 0)),
                    (int) ($daten['maxZeilen'] ?? 0),
                    is_array($daten['zeilen'] ?? null) ? count($daten['zeilen']) : 0,
                    ($daten['hatWeitere'] ?? false) ? 'true' : 'false',
                    (int) ($daten['trefferGesamt'] ?? -1),
                    ($daten['zaehlungLaeuft'] ?? false) ? 'true' : 'false',
                    ($daten['filterMetadatenGeladen'] ?? false) ? 'true' : 'false',
                    ($daten['filterMetadatenLaeuft'] ?? false) ? 'true' : 'false',
                    ($statusVorher['tabellenLadungLaeuft'] ?? false) ? 'true' : 'false',
                    ($statusNachher['tabellenLadungLaeuft'] ?? false) ? 'true' : 'false',
                    $dauerMs
                ),
                0
            );

            $this->sendeLadeAnzeigeDebug('aktualisiereVisualisierung', $daten);

            $erfolg = $this->UpdateVisualizationValue(
                json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
            $this->SendDebug('UpdateVisualizationValue', $erfolg ? 'true' : 'false', 0);
        } catch (\Throwable $e) {
            $this->SendDebug('Visualisierung FEHLER', $e->getMessage(), 0);
            throw $e;
        }
    }

    /**
     * erstelleVisualisierungsDaten
     *
     * Erstellt die vollständigen Daten für die Visualisierung.
     * - Lädt Status, Metadaten und Logzeilen
     * - Bereitet alle Werte für die Anzeige auf
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function erstelleVisualisierungsDaten(): array
	{
		$status = $this->leseStatus();
		$filterMetadaten = $this->leseFilterMetadatenFuerAnzeige();
		$logDatei = $this->ReadPropertyString('LogDatei');
		$maxZeilen = $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50));
		$betriebsmodus = $this->ermittleAktivenModus();

		$this->SendDebug(
			'VisualisierungsDaten',
			sprintf(
				'phase=start modus=%s datei=%s seite=%d maxZeilen=%d filterAktiv=%s',
				$betriebsmodus,
				basename($logDatei),
				(int) ($status['seite'] ?? 0),
				$maxZeilen,
				$this->hatAktiveFilter($status) ? 'true' : 'false'
			),
			0
		);
		
		$ergebnis = [
			'ok'                     => true,
			'fehlermeldung'          => '',
			'status'                 => $status,
			'maxZeilen'              => $maxZeilen,
			'logDatei'               => $logDatei,
			'dateiGroesse'           => '',
			'zeilen'                 => [],
			'hatWeitere'             => false,
			'zeitstempel'            => date('Y-m-d H:i:s'),
			'trefferGesamt'          => (int) ($status['trefferGesamt'] ?? -1),
			'zaehlungLaeuft'         => (bool) ($status['zaehlungLaeuft'] ?? false),
			'bereichVon'             => 0,
			'bereichBis'             => 0,
			'ladezeitMs'             => (int) ($status['letzteTabellenLadezeitMs'] ?? 0),
			'filterLadezeitMs'       => (int) ($filterMetadaten['ladezeitMs'] ?? 0),
			'verfuegbareFilterTypen' => $filterMetadaten['verfuegbareFilterTypen'],
			'verfuegbareSender'      => $filterMetadaten['verfuegbareSender'],
			'filterMetadatenGeladen' => $filterMetadaten['geladen'],
			'filterMetadatenLaeuft'  => $filterMetadaten['laedt'],
			'verfuegbareLogdateien'  => $this->ermittleVerfuegbareLogdateien(),
			'aktuelleLogDatei'       => $logDatei,
			'tabellenLadungLaeuft'   => (bool) ($status['tabellenLadungLaeuft'] ?? false),
			'tabellenLadungText'     => (string) ($status['tabellenLadungText'] ?? ''),
			'betriebsmodus'          => $betriebsmodus
		];

		$modusPruefung = $this->pruefeModusVerwendbarkeit();
		if (!(bool) ($modusPruefung['ok'] ?? false)) {
			$ergebnis['ok'] = false;
			$ergebnis['fehlermeldung'] = (string) ($modusPruefung['fehlermeldung'] ?? 'Modus nicht verfügbar.');
			return $ergebnis;
		}

		if (!is_file($logDatei)) {
			$ergebnis['ok'] = false;
			$ergebnis['fehlermeldung'] = 'Logdatei nicht gefunden: ' . $logDatei;
			return $ergebnis;
		}

		$ergebnis['dateiGroesse'] = $this->formatiereDateigroesse((int) filesize($logDatei));

		$start = microtime(true);
		$leseErgebnis = $this->ladeLogZeilen($status);
		$ergebnis['ladezeitMs'] = (int) round((microtime(true) - $start) * 1000);

		$ergebnis['ok'] = $leseErgebnis['ok'];
		$ergebnis['fehlermeldung'] = $leseErgebnis['fehlermeldung'];
		$ergebnis['zeilen'] = $leseErgebnis['zeilen'];
		$ergebnis['hatWeitere'] = $leseErgebnis['hatWeitere'];

		if (array_key_exists('trefferGesamt', $leseErgebnis) && (int) $leseErgebnis['trefferGesamt'] >= 0) {
			$ergebnis['trefferGesamt'] = (int) $leseErgebnis['trefferGesamt'];
		}

		$seite = max(0, (int) ($status['seite'] ?? 0));
		$anzahlAktuelleSeite = count($ergebnis['zeilen']);

		if ($anzahlAktuelleSeite > 0) {
			$ergebnis['bereichVon'] = ($seite * $maxZeilen) + 1;
			$ergebnis['bereichBis'] = ($seite * $maxZeilen) + $anzahlAktuelleSeite;
		}

		$this->SendDebug(
			'VisualisierungsDaten',
			sprintf(
				'phase=ende modus=%s ok=%s zeilen=%d hatWeitere=%s treffer=%d ladezeitMs=%d',
				$betriebsmodus,
				$ergebnis['ok'] ? 'true' : 'false',
				count($ergebnis['zeilen'] ?? []),
				($ergebnis['hatWeitere'] ?? false) ? 'true' : 'false',
				(int) ($ergebnis['trefferGesamt'] ?? -1),
				(int) ($ergebnis['ladezeitMs'] ?? 0)
			),
			0
		);

		return $ergebnis;
	}

    /**
     * erstelleVisualisierungsStatusDaten
     *
     * Erstellt die Statusdaten für die Visualisierung.
     * - Lädt Status, Filtermetadaten und Cacheinformationen
     * - Gibt nur die aktuell nötigen Anzeigewerte zurück
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function erstelleVisualisierungsStatusDaten(): array
	{
		$status = $this->leseStatus();
		$filterMetadaten = $this->leseFilterMetadatenFuerAnzeige();
		$logDatei = $this->ReadPropertyString('LogDatei');
		$maxZeilen = $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50));
		$betriebsmodus = $this->ermittleAktivenModus();

		$ergebnis = [
			'ok'                     => true,
			'fehlermeldung'          => '',
			'status'                 => $status,
			'maxZeilen'              => $maxZeilen,
			'logDatei'               => $logDatei,
			'dateiGroesse'           => '',
			'zeilen'                 => [],
			'hatWeitere'             => false,
			'zeitstempel'            => date('Y-m-d H:i:s'),
			'trefferGesamt'          => (int) ($status['trefferGesamt'] ?? -1),
			'zaehlungLaeuft'         => (bool) ($status['zaehlungLaeuft'] ?? false),
			'bereichVon'             => 0,
			'bereichBis'             => 0,
			'ladezeitMs'             => (int) ($status['letzteTabellenLadezeitMs'] ?? 0),
			'filterLadezeitMs'       => (int) ($filterMetadaten['ladezeitMs'] ?? 0),
			'verfuegbareFilterTypen' => $filterMetadaten['verfuegbareFilterTypen'],
			'verfuegbareSender'      => $filterMetadaten['verfuegbareSender'],
			'filterMetadatenGeladen' => $filterMetadaten['geladen'],
			'filterMetadatenLaeuft'  => $filterMetadaten['laedt'],
			'verfuegbareLogdateien'  => $this->ermittleVerfuegbareLogdateien(),
			'aktuelleLogDatei'       => $logDatei,
			'tabellenLadungLaeuft'   => (bool) ($status['tabellenLadungLaeuft'] ?? false),
			'tabellenLadungText'     => (string) ($status['tabellenLadungText'] ?? ''),
			'betriebsmodus'          => $betriebsmodus
		];

		$modusPruefung = $this->pruefeModusVerwendbarkeit();
		if (!(bool) ($modusPruefung['ok'] ?? false)) {
			$ergebnis['ok'] = false;
			$ergebnis['fehlermeldung'] = (string) ($modusPruefung['fehlermeldung'] ?? 'Modus nicht verfügbar.');
			return $ergebnis;
		}

		if (!is_file($logDatei)) {
			$ergebnis['ok'] = false;
			$ergebnis['fehlermeldung'] = 'Logdatei nicht gefunden: ' . $logDatei;
			return $ergebnis;
		}

		$dateiGroesse = (int) filesize($logDatei);
		$dateiMTime = (int) filemtime($logDatei);
		$ergebnis['dateiGroesse'] = $this->formatiereDateigroesse($dateiGroesse);

		$cache = $this->leseSeitenCache();
		$listenSignatur = $this->ermittleListenCacheSignatur($status);
		$zaehlSignatur = $this->ermittleZaehlsignatur($status);

		$cacheGueltig =
			((string) ($cache['listenSignatur'] ?? '') === $listenSignatur) &&
			((string) ($cache['zaehlSignatur'] ?? '') === $zaehlSignatur) &&
			((int) ($cache['dateiGroesseCache'] ?? 0) === $dateiGroesse) &&
			((int) ($cache['dateiMTimeCache'] ?? 0) === $dateiMTime);

		if ($cacheGueltig) {
			$ergebnis['zeilen'] = is_array($cache['zeilen'] ?? null) ? $cache['zeilen'] : [];
			$ergebnis['hatWeitere'] = (bool) ($cache['hatWeitere'] ?? false);

			if ((int) ($cache['trefferGesamt'] ?? -1) >= 0) {
				$ergebnis['trefferGesamt'] = (int) $cache['trefferGesamt'];
			}

			$seite = max(0, (int) ($status['seite'] ?? 0));
			$anzahlAktuelleSeite = count($ergebnis['zeilen']);

			if ($anzahlAktuelleSeite > 0) {
				$ergebnis['bereichVon'] = ($seite * $maxZeilen) + 1;
				$ergebnis['bereichBis'] = ($seite * $maxZeilen) + $anzahlAktuelleSeite;
			}
		}

		$this->SendDebug('StatusCache',
			sprintf(
				'datei=%s modus=%s zeilen=%d hatWeitere=%s treffer=%d tabellenLadung=%s zaehlung=%s filterLaeuft=%s',
				basename($logDatei),
				$betriebsmodus,
				is_array($ergebnis['zeilen'] ?? null) ? count($ergebnis['zeilen']) : 0,
				($ergebnis['hatWeitere'] ?? false) ? 'true' : 'false',
				(int) ($ergebnis['trefferGesamt'] ?? -1),
				($ergebnis['tabellenLadungLaeuft'] ?? false) ? 'true' : 'false',
				($ergebnis['zaehlungLaeuft'] ?? false) ? 'true' : 'false',
				($ergebnis['filterMetadatenLaeuft'] ?? false) ? 'true' : 'false'
			),
			0
		);
		return $ergebnis;
	}

    /**
     * ladeFilterMetadatenAsynchron
     *
     * Lädt verfügbare Filteroptionen und Metadaten zur Logdatei.
     * - Prüft Cache und Dateistand
     * - Aktualisiert Filtertypen, Sender und Gesamtanzahl
     *
     * Parameter: keine
     * Rückgabewert: void
     */
	private function ladeFilterMetadatenAsynchron(): void
	{
		$modusPruefung = $this->pruefeModusVerwendbarkeit();
		if (!(bool) ($modusPruefung['ok'] ?? false)) {
			$this->SendDebug(
				'LadeFilterOptionen',
				sprintf(
					'modus-blockiert datei=%s meldung=%s',
					basename($this->ReadPropertyString('LogDatei')),
					(string) ($modusPruefung['fehlermeldung'] ?? '')
				),
				0
			);

			$meta = $this->leseFilterMetadatenRoh();
			$meta['laedt'] = false;
			$this->schreibeFilterMetadaten($meta);

			$this->sendeDebugLadezustandSnapshot(
				'ladeFilterMetadatenAsynchron/modus-blockiert',
				$this->leseStatus(),
				$meta
			);

			$this->aktualisiereVisualisierung();
			return;
		}

		$logDatei = $this->ReadPropertyString('LogDatei');
		$status = $this->leseStatus();
		$meta = $this->leseFilterMetadatenRoh();

		$this->SendDebug(
			'LadeFilterOptionenStart',
			sprintf(
				'modus=%s datei=%s signatur=%s metaLaedt=%s metaTypen=%d metaSender=%d ultraBuildLaeuft=%s ultraIndexBereit=%s',
				$this->ermittleAktivenModus(),
				basename($logDatei),
				$this->ermittleFilterMetadatenSignatur($status),
				(bool) ($meta['laedt'] ?? false) ? 'true' : 'false',
				is_array($meta['verfuegbareFilterTypen'] ?? null) ? count($meta['verfuegbareFilterTypen']) : 0,
				is_array($meta['verfuegbareSender'] ?? null) ? count($meta['verfuegbareSender']) : 0,
				(bool) ($status['ultraBuildLaeuft'] ?? false) ? 'true' : 'false',
				(bool) ($status['ultraIndexBereit'] ?? false) ? 'true' : 'false'
			),
			0
		);

		if (!is_file($logDatei)) {
			$meta['verfuegbareFilterTypen'] = [];
			$meta['verfuegbareSender'] = [];
			$meta['gesamtZeilenCache'] = -1;
			$meta['dateiGroesseCache'] = 0;
			$meta['dateiMTimeCache'] = 0;
			$meta['ladezeitMs'] = 0;
			$meta['laedt'] = false;
			$meta['signatur'] = '';
			$this->schreibeFilterMetadaten($meta);

			$this->sendeDebugLadezustandSnapshot(
				'ladeFilterMetadatenAsynchron/datei-fehlt',
				$this->leseStatus(),
				$meta
			);

			$this->aktualisiereVisualisierungNurStatus('ladeFilter-datei-fehlt');
			return;
		}

		$dateiGroesse = (int) filesize($logDatei);
		$dateiMTime = (int) filemtime($logDatei);
		$signatur = $this->ermittleFilterMetadatenSignatur($status);

		$cacheGueltig =
			((int) ($meta['dateiGroesseCache'] ?? 0) === $dateiGroesse) &&
			((int) ($meta['dateiMTimeCache'] ?? 0) === $dateiMTime) &&
			((string) ($meta['signatur'] ?? '') === $signatur) &&
			is_array($meta['verfuegbareFilterTypen'] ?? null) &&
			is_array($meta['verfuegbareSender'] ?? null);

		$this->SendDebug(
			'LadeFilterOptionenCache',
			sprintf(
				'datei=%s cacheGueltig=%s dateiGroesse=%d cacheGroesse=%d dateiMTime=%d cacheMTime=%d signaturNeu=%s signaturAlt=%s',
				basename($logDatei),
				$cacheGueltig ? 'true' : 'false',
				$dateiGroesse,
				(int) ($meta['dateiGroesseCache'] ?? 0),
				$dateiMTime,
				(int) ($meta['dateiMTimeCache'] ?? 0),
				$signatur,
				(string) ($meta['signatur'] ?? '')
			),
			0
		);

		if ($cacheGueltig) {
			$this->SendDebug(
				'LadeFilterOptionen',
				sprintf(
					'plattform=%s datei=%s cache=gueltig',
					(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
					basename($logDatei)
				),
				0
			);

			$this->sendeDebugLadezustandSnapshot(
				'ladeFilterMetadatenAsynchron/cache-gueltig',
				$this->leseStatus(),
				$meta
			);

			$this->aktualisiereVisualisierungNurStatus('ladeFilter-cache');
			return;
		}

		if ((bool) ($meta['laedt'] ?? false)) {
			$this->SendDebug(
				'LadeFilterOptionen',
				sprintf(
					'plattform=%s datei=%s status=laeuft-bereits',
					(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
					basename($logDatei)
				),
				0
			);

			$this->sendeDebugLadezustandSnapshot(
				'ladeFilterMetadatenAsynchron/laeuft-bereits',
				$this->leseStatus(),
				$meta
			);

			return;
		}

		$meta['laedt'] = true;
		$this->schreibeFilterMetadaten($meta);

		$this->sendeDebugLadezustandSnapshot(
			'ladeFilterMetadatenAsynchron/start',
			$this->leseStatus(),
			$meta
		);

		$this->aktualisiereVisualisierungNurStatus('ladeFilter-start');

		$start = microtime(true);
		$ermittelt = $this->ermittleFilterMetadaten();
		$ladezeitMs = (int) round((microtime(true) - $start) * 1000);

		if (
			$this->ermittleAktivenModus() === 'ultra' &&
			!(bool) ($ermittelt['cachebar'] ?? true)
		) {
			$meta = $this->leseFilterMetadatenRoh();
			$meta['laedt'] = true;
			$this->schreibeFilterMetadaten($meta);

			$this->SendDebug(
				'LadeFilterOptionenUltra',
				sprintf(
					'cachebar=false datei=%s buildLaeuft=%s indexBereit=%s ladezeitMs=%d',
					basename($logDatei),
					(bool) (($this->leseStatus()['ultraBuildLaeuft'] ?? false)) ? 'true' : 'false',
					(bool) (($this->leseStatus()['ultraIndexBereit'] ?? false)) ? 'true' : 'false',
					$ladezeitMs
				),
				0
			);

			$this->sendeDebugLadezustandSnapshot(
				'ladeFilterMetadatenAsynchron/ultra-index-laeuft',
				$this->leseStatus(),
				$meta
			);

			$this->aktualisiereVisualisierungNurStatus('ladeFilter-ultra-index-laeuft');
			return;
		}

		$meta = $this->leseFilterMetadatenRoh();
		$meta['verfuegbareFilterTypen'] = $ermittelt['verfuegbareFilterTypen'];
		$meta['verfuegbareSender'] = $ermittelt['verfuegbareSender'];
		$meta['gesamtZeilenCache'] = (int) ($ermittelt['gesamtZeilen'] ?? -1);
		$meta['dateiGroesseCache'] = $dateiGroesse;
		$meta['dateiMTimeCache'] = $dateiMTime;
		$meta['ladezeitMs'] = $ladezeitMs;
		$meta['laedt'] = false;
		$meta['signatur'] = $signatur;

		$this->schreibeFilterMetadaten($meta);

		$basisStatus = $this->leseStatus();

		if (
			$this->ermittleAktivenModus() !== 'standard' &&
			!$this->hatAktiveFilter($basisStatus) &&
			(int) ($ermittelt['gesamtZeilen'] ?? -1) >= 0
		) {
			if ((int) ($basisStatus['trefferGesamt'] ?? -1) < 0) {
				$neuerStatus = $this->leseStatus(); // ganz frisch lesen

				$neuerStatus['trefferGesamt'] = (int) $ermittelt['gesamtZeilen'];
				$neuerStatus['dateiGroesseCache'] = $dateiGroesse;
				$neuerStatus['dateiMTimeCache'] = $dateiMTime;
				$neuerStatus['zaehlSignatur'] = $this->ermittleZaehlsignatur($neuerStatus);

				// ganz wichtig: laufende Zählung hier nicht künstlich wieder auf true zurückschreiben
				// wir übernehmen nur den aktuellen Wert aus dem frisch gelesenen Status
				$this->schreibeStatus($neuerStatus);
			}
		}

		$this->SendDebug(
			'FilterMetadaten',
			sprintf(
				'plattform=%s datei=%s gesamt=%d typen=%d sender=%d dauerMs=%d',
				(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
				basename($logDatei),
				(int) ($ermittelt['gesamtZeilen'] ?? -1),
				count($ermittelt['verfuegbareFilterTypen'] ?? []),
				count($ermittelt['verfuegbareSender'] ?? []),
				$ladezeitMs
			),
			0
		);

		$this->sendeDebugLadezustandSnapshot(
			'ladeFilterMetadatenAsynchron/ende',
			$this->leseStatus(),
			$meta
		);

		$this->aktualisiereVisualisierungNurStatus('ladeFilter-ende');
	}

    /**
     * zaehleTrefferAsynchron
     *
     * Ermittelt die Anzahl gefilterter Treffer.
     * - Nutzt vorhandene Cachewerte wenn möglich
     * - Schreibt das Ergebnis in den Modulstatus zurück
     *
     * Parameter: keine
     * Rückgabewert: void
     */
	private function zaehleTrefferAsynchron(): void
	{
		$modusPruefung = $this->pruefeModusVerwendbarkeit();
		if (!(bool) ($modusPruefung['ok'] ?? false)) {
			$this->SendDebug(
				'ZaehleTreffer',
				sprintf(
					'modus-blockiert datei=%s meldung=%s',
					basename($this->ReadPropertyString('LogDatei')),
					(string) ($modusPruefung['fehlermeldung'] ?? '')
				),
				0
			);

			$status = $this->leseStatus();
			$status['zaehlungLaeuft'] = false;
			$this->schreibeStatus($status);

			$this->sendeDebugLadezustandSnapshot(
				'zaehleTrefferAsynchron/modus-blockiert',
				$status,
				$this->leseFilterMetadatenRoh()
			);

			$this->aktualisiereVisualisierung();
			return;
		}

		$status = $this->leseStatus();
		$logDatei = $this->ReadPropertyString('LogDatei');

		$this->SendDebug(
			'ZaehleTrefferStart',
			sprintf(
				'modus=%s datei=%s filterAktiv=%s trefferAlt=%d zaehlungLaeuft=%s ultraBuildLaeuft=%s ultraIndexBereit=%s',
				$this->ermittleAktivenModus(),
				basename($logDatei),
				$this->hatAktiveFilter($status) ? 'true' : 'false',
				(int) ($status['trefferGesamt'] ?? -1),
				(bool) ($status['zaehlungLaeuft'] ?? false) ? 'true' : 'false',
				(bool) ($status['ultraBuildLaeuft'] ?? false) ? 'true' : 'false',
				(bool) ($status['ultraIndexBereit'] ?? false) ? 'true' : 'false'
			),
			0
		);

		if (!is_file($logDatei)) {
			$aktuellerStatus = $this->leseStatus();
			$aktuellerStatus['trefferGesamt'] = 0;
			$aktuellerStatus['zaehlungLaeuft'] = false;
			$this->schreibeStatus($aktuellerStatus);

			$this->sendeDebugLadezustandSnapshot(
				'zaehleTrefferAsynchron/datei-fehlt',
				$aktuellerStatus,
				$this->leseFilterMetadatenRoh()
			);

			$this->aktualisiereVisualisierungNurStatus('zaehleTreffer-datei-fehlt');
			return;
		}

		$dateiGroesse = (int) filesize($logDatei);
		$dateiMTime = (int) filemtime($logDatei);
		$signatur = $this->ermittleZaehlsignatur($status);

		$cacheGueltig =
			((int) ($status['trefferGesamt'] ?? -1) >= 0) &&
			((int) ($status['dateiGroesseCache'] ?? 0) === $dateiGroesse) &&
			((int) ($status['dateiMTimeCache'] ?? 0) === $dateiMTime) &&
			((string) ($status['zaehlSignatur'] ?? '') === $signatur);

		$this->SendDebug(
			'ZaehleTrefferCache',
			sprintf(
				'datei=%s cacheGueltig=%s dateiGroesse=%d cacheGroesse=%d dateiMTime=%d cacheMTime=%d signaturNeu=%s signaturAlt=%s',
				basename($logDatei),
				$cacheGueltig ? 'true' : 'false',
				$dateiGroesse,
				(int) ($status['dateiGroesseCache'] ?? 0),
				$dateiMTime,
				(int) ($status['dateiMTimeCache'] ?? 0),
				$signatur,
				(string) ($status['zaehlSignatur'] ?? '')
			),
			0
		);

		if ($cacheGueltig) {
			$this->SendDebug(
				'ZaehleTreffer',
				sprintf(
					'plattform=%s datei=%s cache=status',
					(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
					basename($logDatei)
				),
				0
			);

			$this->sendeDebugLadezustandSnapshot(
				'zaehleTrefferAsynchron/cache-status',
				$this->leseStatus(),
				$this->leseFilterMetadatenRoh()
			);

			$this->aktualisiereVisualisierungNurStatus('zaehleTreffer-cache-status');
			return;
		}

		if (!(bool) $this->hatAktiveFilter($status)) {
			$metaAnzeige = $this->leseFilterMetadatenFuerAnzeige();
			$gesamtMeta = (int) ($metaAnzeige['gesamtZeilen'] ?? -1);

			$this->SendDebug(
				'ZaehleTrefferMetaAnzeige',
				sprintf(
					'datei=%s geladen=%s laedt=%s gesamt=%d',
					basename($logDatei),
					(bool) ($metaAnzeige['geladen'] ?? false) ? 'true' : 'false',
					(bool) ($metaAnzeige['laedt'] ?? false) ? 'true' : 'false',
					$gesamtMeta
				),
				0
			);

			if ((bool) ($metaAnzeige['geladen'] ?? false) && $gesamtMeta >= 0) {
				$aktuellerStatus = $this->leseStatus();
				$aktuellerStatus['trefferGesamt'] = $gesamtMeta;
				$aktuellerStatus['zaehlungLaeuft'] = false;
				$aktuellerStatus['dateiGroesseCache'] = $dateiGroesse;
				$aktuellerStatus['dateiMTimeCache'] = $dateiMTime;
				$aktuellerStatus['zaehlSignatur'] = $signatur;
				$this->schreibeStatus($aktuellerStatus);

				$this->SendDebug(
					'ZaehleTreffer',
					sprintf(
						'plattform=%s datei=%s cache=filtermeta anzahl=%d',
						(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
						basename($logDatei),
						$gesamtMeta
					),
					0
				);

				$this->sendeDebugLadezustandSnapshot(
					'zaehleTrefferAsynchron/cache-filtermeta',
					$aktuellerStatus,
					$this->leseFilterMetadatenRoh()
				);

				$this->aktualisiereVisualisierungNurStatus('zaehleTreffer-cache-filtermeta');
				return;
			}
		}

		if ($this->hatAktiveFilter($status)) {
			$seitenCache = $this->leseSeitenCache();
			$seitenCacheGueltig =
				((string) ($seitenCache['zaehlSignatur'] ?? '') === $signatur) &&
				((int) ($seitenCache['dateiGroesseCache'] ?? 0) === $dateiGroesse) &&
				((int) ($seitenCache['dateiMTimeCache'] ?? 0) === $dateiMTime) &&
				((int) ($seitenCache['trefferGesamt'] ?? -1) >= 0);

			$this->SendDebug(
				'ZaehleTrefferSeitenCache',
				sprintf(
					'datei=%s gueltig=%s seitenTreffer=%d',
					basename($logDatei),
					$seitenCacheGueltig ? 'true' : 'false',
					(int) ($seitenCache['trefferGesamt'] ?? -1)
				),
				0
			);

			if ($seitenCacheGueltig) {
				$anzahl = (int) $seitenCache['trefferGesamt'];

				$aktuellerStatus = $this->leseStatus();
				$aktuellerStatus['trefferGesamt'] = $anzahl;
				$aktuellerStatus['zaehlungLaeuft'] = false;
				$aktuellerStatus['dateiGroesseCache'] = $dateiGroesse;
				$aktuellerStatus['dateiMTimeCache'] = $dateiMTime;
				$aktuellerStatus['zaehlSignatur'] = $signatur;
				$this->schreibeStatus($aktuellerStatus);

				$this->SendDebug(
					'ZaehleTreffer',
					sprintf(
						'plattform=%s datei=%s cache=seiten anzahl=%d',
						(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
						basename($logDatei),
						$anzahl
					),
					0
				);

				$this->sendeDebugLadezustandSnapshot(
					'zaehleTrefferAsynchron/cache-seiten',
					$aktuellerStatus,
					$this->leseFilterMetadatenRoh()
				);

				$this->aktualisiereVisualisierungNurStatus('zaehleTreffer-cache-seiten');
				return;
			}
		}

		if ((bool) ($status['zaehlungLaeuft'] ?? false)) {
			$this->SendDebug(
				'ZaehleTreffer',
				sprintf(
					'plattform=%s datei=%s status=laeuft-bereits',
					(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
					basename($logDatei)
				),
				0
			);

			$this->sendeDebugLadezustandSnapshot(
				'zaehleTrefferAsynchron/laeuft-bereits',
				$status,
				$this->leseFilterMetadatenRoh()
			);

			return;
		}

		$aktuellerStatus = $this->leseStatus();
		$aktuellerStatus['zaehlungLaeuft'] = true;
		$this->schreibeStatus($aktuellerStatus);

		$this->sendeDebugLadezustandSnapshot(
			'zaehleTrefferAsynchron/start',
			$aktuellerStatus,
			$this->leseFilterMetadatenRoh()
		);

		$this->aktualisiereVisualisierungNurStatus('zaehleTreffer-start');

		$start = microtime(true);
		$anzahl = $this->zaehleGefilterteZeilen($status);
		$dauerMs = (int) round((microtime(true) - $start) * 1000);

		if ($this->ermittleAktivenModus() === 'ultra') {
			$this->SendDebug(
				'ZaehleTrefferUltraStatus',
				sprintf(
					'anzahl=%d filterAktiv=%s trefferAlt=%d signatur=%s ultraBuildLaeuft=%s ultraIndexBereit=%s',
					$anzahl,
					$this->hatAktiveFilter($status) ? 'true' : 'false',
					(int) ($status['trefferGesamt'] ?? -1),
					(string) $signatur,
					(bool) ($status['ultraBuildLaeuft'] ?? false) ? 'true' : 'false',
					(bool) ($status['ultraIndexBereit'] ?? false) ? 'true' : 'false'
				),
				0
			);
		}

		$status = $this->leseStatus();
		$status['trefferGesamt'] = $anzahl;
		$status['zaehlungLaeuft'] = false;
		$status['dateiGroesseCache'] = $dateiGroesse;
		$status['dateiMTimeCache'] = $dateiMTime;
		$status['zaehlSignatur'] = $signatur;

		$this->schreibeStatus($status);

		$this->SendDebug(
			'ZaehleTreffer',
			sprintf(
				'plattform=%s datei=%s anzahl=%d dauerMs=%d',
				(strncasecmp(PHP_OS, 'WIN', 3) === 0) ? 'windows' : 'linux',
				basename($logDatei),
				$anzahl,
				$dauerMs
			),
			0
		);

		$this->sendeDebugLadezustandSnapshot(
			'zaehleTrefferAsynchron/ende',
			$status,
			$this->leseFilterMetadatenRoh()
		);

		$this->aktualisiereVisualisierungNurStatus('zaehleTreffer-ende');
	}

    /**
     * ladeLogZeilen
     *
     * Lädt Logzeilen abhängig vom aktiven Betriebsmodus.
     * - Leitet an Standard- oder Systempfad weiter
     * - Liefert Zeilen, Treffer und Seitendaten zurück
     *
     * Parameter: array $status
     * Rückgabewert: array
     */
	private function ladeLogZeilen(array $status): array
	{
		$modus = $this->ermittleAktivenModus();

		if ($modus === 'standard') {
			return $this->ladeLogZeilenStandard($status);
		}

		if ($modus === 'ultra') {
			return $this->ladeLogZeilenUltra($status);
		}

		if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
			return $this->ladeLogZeilenWindows($status);
		}

		$maxZeilen = $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50));
		$seite = max(0, (int) $status['seite']);

		$take = (($seite + 1) * $maxZeilen) + 1;
		$head = $maxZeilen + 1;

		$befehl = $this->baueShellBefehl($status, $take, $head);

		$start = microtime(true);
		$ausgabe = [];
		$rueckgabeCode = 0;
		exec($befehl, $ausgabe, $rueckgabeCode);
		$dauerMs = (int) round((microtime(true) - $start) * 1000);

		if ($rueckgabeCode > 1) {
			$this->SendDebug(
				'ladeLogZeilen FEHLER',
				'plattform=linux rc=' . $rueckgabeCode . ' dauerMs=' . $dauerMs,
				0
			);

			return [
				'ok'            => false,
				'fehlermeldung' => 'Fehler beim Ausführen des Shell-Befehls. Rückgabecode: ' . $rueckgabeCode,
				'zeilen'        => [],
				'hatWeitere'    => false
			];
		}

		$zeilen = [];
		$verworfen = 0;
		$verworfenBeispiele = [];

		foreach ($ausgabe as $zeile) {
			$parsed = $this->parseLogZeile($zeile);
			if ($parsed === null) {
				$verworfen++;

				if (count($verworfenBeispiele) < 5) {
					$beispiel = trim($zeile);
					if (mb_strlen($beispiel, 'UTF-8') > 140) {
						$beispiel = mb_substr($beispiel, 0, 140, 'UTF-8') . '…';
					}
					$verworfenBeispiele[] = $beispiel;
				}
				continue;
			}

			$zeilen[] = $parsed;
		}

		$hatWeitere = count($zeilen) > $maxZeilen;
		if ($hatWeitere) {
			array_pop($zeilen);
		}

		$logDatei = $this->ReadPropertyString('LogDatei');
		$dateiGroesse = is_file($logDatei) ? (int) filesize($logDatei) : 0;
		$dateiMTime = is_file($logDatei) ? (int) filemtime($logDatei) : 0;
		$listenSignatur = $this->ermittleListenCacheSignatur($status);
		$zaehlSignatur = $this->ermittleZaehlsignatur($status);

		$this->schreibeSeitenCache([
			'listenSignatur'    => $listenSignatur,
			'zaehlSignatur'     => $zaehlSignatur,
			'dateiGroesseCache' => $dateiGroesse,
			'dateiMTimeCache'   => $dateiMTime,
			'trefferGesamt'     => (int) ($status['trefferGesamt'] ?? -1),
			'hatWeitere'        => $hatWeitere,
			'zeilen'            => $zeilen
		]);

		$this->SendDebug(
			'ladeLogZeilen',
			sprintf(
				'plattform=linux datei=%s seite=%d maxZeilen=%d raw=%d parsed=%d verworfen=%d hatFilter=%s hatWeitere=%s dauerMs=%d cache=geschrieben',
				basename($logDatei),
				$seite,
				$maxZeilen,
				count($ausgabe),
				count($zeilen),
				$verworfen,
				$this->hatAktiveFilter($status) ? 'true' : 'false',
				$hatWeitere ? 'true' : 'false',
				$dauerMs
			),
			0
		);

		if ($verworfen > 0) {
			$this->SendDebug(
				'ladeLogZeilen verworfen',
				json_encode($verworfenBeispiele, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
				0
			);
		}

		return [
			'ok'            => true,
			'fehlermeldung' => '',
			'zeilen'        => $zeilen,
			'hatWeitere'    => $hatWeitere
		];
	}

    /**
     * zaehleGefilterteZeilen
     *
     * Zählt gefilterte Logzeilen abhängig vom aktiven Betriebsmodus.
     * - Leitet an Standard- oder Systempfad weiter
     * - Liefert die Anzahl passender Einträge zurück
     *
     * Parameter: array $status
     * Rückgabewert: int
     */
	private function zaehleGefilterteZeilen(array $status): int
	{
		$modus = $this->ermittleAktivenModus();

		if ($modus === 'standard') {
			return $this->zaehleGefilterteZeilenStandard($status);
		}

		if ($modus === 'ultra') {
			return $this->zaehleGefilterteZeilenUltra($status);
		}

		if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
			return $this->zaehleGefilterteZeilenWindows($status);
		}

		$befehl = $this->baueZaehlBefehl($status);

		$start = microtime(true);
		$ausgabe = [];
		$rc = 0;
		exec($befehl, $ausgabe, $rc);
		$dauerMs = (int) round((microtime(true) - $start) * 1000);

		if ($rc > 1 || !isset($ausgabe[0])) {
			$this->SendDebug('ZaehleTrefferFehler', 'plattform=linux rc=' . $rc . ' dauerMs=' . $dauerMs, 0);
			return 0;
		}
		return (int) trim($ausgabe[0]);
	}

    /**
     * ermittleFilterMetadaten
     *
     * Ermittelt Filtertypen, Sender und Gesamtmenge zur Logdatei.
     * - Leitet an Standard- oder Systempfad weiter
     * - Liefert Metadaten für die Filteranzeige zurück
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function ermittleFilterMetadaten(): array
	{
		$modus = $this->ermittleAktivenModus();

		if ($modus === 'standard') {
			return $this->ermittleFilterMetadatenStandard();
		}

		if ($modus === 'ultra') {
			return $this->ermittleFilterMetadatenUltra();
		}

		if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
			return $this->ermittleWindowsMetadatenUndGesamtmenge();
		}

		$befehl = $this->baueFilterMetadatenBefehl();

		$start = microtime(true);
		$ausgabe = [];
		$rc = 0;
		exec($befehl, $ausgabe, $rc);
		$dauerMs = (int) round((microtime(true) - $start) * 1000);

		if ($rc > 1) {
			$this->SendDebug('FilterMetadatenFehler', 'plattform=linux rc=' . $rc . ' dauerMs=' . $dauerMs, 0);
			return [
				'verfuegbareFilterTypen' => [],
				'verfuegbareSender'      => [],
				'gesamtZeilen'           => -1
			];
		}

		$typen = [];
		$sender = [];
		$gesamtZeilen = -1;

		foreach ($ausgabe as $zeile) {
			$teile = explode("\t", $zeile, 2);
			if (count($teile) < 2) {
				continue;
			}

			$prefix = $teile[0];
			$wert = trim($teile[1]);

			if ($prefix === 'G') {
				$gesamtZeilen = (int) $wert;
				continue;
			}

			if ($wert === '') {
				continue;
			}

			if ($prefix === 'T') {
				$typen[] = $wert;
			} elseif ($prefix === 'S') {
				$sender[] = $wert;
			}
		}

		$typen = array_values(array_unique($typen));
		$sender = array_values(array_unique($sender));

		sort($typen, SORT_NATURAL | SORT_FLAG_CASE);
		sort($sender, SORT_NATURAL | SORT_FLAG_CASE);

		return [
			'verfuegbareFilterTypen' => $typen,
			'verfuegbareSender'      => $sender,
			'gesamtZeilen'           => $gesamtZeilen
		];
	}

    /**
     * ermittleListenCacheSignatur
     *
     * Erzeugt eine Signatur für den Seiten- und Listen-Cache.
     * - Berücksichtigt Datei, Seite und aktive Filter
     * - Dient zur Prüfung auf gültige Cacheeinträge
     *
     * Parameter: array $status
     * Rückgabewert: string
     */
	private function ermittleListenCacheSignatur(array $status): string
	{
		return md5(json_encode([
			'logDatei'        => $this->ReadPropertyString('LogDatei'),
			'seite'           => max(0, (int) ($status['seite'] ?? 0)),
			'maxZeilen'       => $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50)),
			'filterTypen'     => $this->normalisiereFilterTypen($status['filterTypen'] ?? []),
			'objektIdFilter'  => $this->normalisiereObjektIdFilterString((string) ($status['objektIdFilter'] ?? '')),
			'senderFilter'    => $this->normalisiereSenderFilter($status['senderFilter'] ?? []),
			'textFilter'      => trim((string) ($status['textFilter'] ?? ''))
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}


    /**
     * leseSeitenCache
     *
     * Liest den gespeicherten Seiten-Cache aus dem Attribut.
     * - Dekodiert die Cachedaten aus JSON
     * - Gibt eine normalisierte Cache-Struktur zurück
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function leseSeitenCache(): array
	{
		$json = $this->ReadAttributeString(self::ATTR_SEITENCACHE);
		$daten = $this->dekodiereJsonArray($json);

		return [
			'listenSignatur'    => (string) ($daten['listenSignatur'] ?? ''),
			'zaehlSignatur'     => (string) ($daten['zaehlSignatur'] ?? ''),
			'dateiGroesseCache' => (int) ($daten['dateiGroesseCache'] ?? 0),
			'dateiMTimeCache'   => (int) ($daten['dateiMTimeCache'] ?? 0),
			'trefferGesamt'     => (int) ($daten['trefferGesamt'] ?? -1),
			'hatWeitere'        => (bool) ($daten['hatWeitere'] ?? false),
			'zeilen'            => is_array($daten['zeilen'] ?? null) ? array_values($daten['zeilen']) : []
		];
	}

    /**
     * schreibeSeitenCache
     *
     * Schreibt den Seiten-Cache in das Modulattribut.
     * - Speichert Signatur, Dateistand und geladene Zeilen
     * - Normalisiert die Struktur vor dem Speichern
     *
     * Parameter: array $cache
     * Rückgabewert: void
     */
	private function schreibeSeitenCache(array $cache): void
	{
		$this->WriteAttributeString(
			self::ATTR_SEITENCACHE,
			json_encode([
				'listenSignatur'    => (string) ($cache['listenSignatur'] ?? ''),
				'zaehlSignatur'     => (string) ($cache['zaehlSignatur'] ?? ''),
				'dateiGroesseCache' => (int) ($cache['dateiGroesseCache'] ?? 0),
				'dateiMTimeCache'   => (int) ($cache['dateiMTimeCache'] ?? 0),
				'trefferGesamt'     => (int) ($cache['trefferGesamt'] ?? -1),
				'hatWeitere'        => (bool) ($cache['hatWeitere'] ?? false),
				'zeilen'            => is_array($cache['zeilen'] ?? null) ? array_values($cache['zeilen']) : []
			], JSON_THROW_ON_ERROR)
		);
	}

    /**
     * leereSeitenCache
     *
     * Setzt den Seiten-Cache auf einen leeren Zustand zurück.
     * - Entfernt gespeicherte Zeilen und Trefferwerte
     * - Initialisiert die Cachefelder neu
     *
     * Parameter: keine
     * Rückgabewert: void
     */
	private function leereSeitenCache(): void
	{
		$this->schreibeSeitenCache([
			'listenSignatur'    => '',
			'zaehlSignatur'     => '',
			'dateiGroesseCache' => 0,
			'dateiMTimeCache'   => 0,
			'trefferGesamt'     => -1,
			'hatWeitere'        => false,
			'zeilen'            => []
		]);
	}

    /**
     * leseStatus
     *
     * Liest den aktuellen Visualisierungsstatus aus dem Attribut.
     * - Dekodiert gespeicherte Zustandsdaten aus JSON
     * - Gibt eine normalisierte Statusstruktur zurück
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function leseStatus(): array
	{
		$json = $this->ReadAttributeString(self::ATTR_STATUS);
		$daten = $this->dekodiereJsonArray($json);

		return [
			'seite'                    => max(0, (int) ($daten['seite'] ?? 0)),
			'maxZeilen'                => $this->normalisiereMaxZeilen((int) ($daten['maxZeilen'] ?? $this->ReadPropertyInteger('MaxZeilen'))),
			'theme'                    => $this->normalisiereTheme((string) ($daten['theme'] ?? 'dark')),
			'kompakt'                  => $this->normalisiereKompakt($daten['kompakt'] ?? false),
			'filterTypen'              => $this->normalisiereFilterTypen($daten['filterTypen'] ?? []),
			'objektIdFilter'           => $this->normalisiereObjektIdFilterString((string) ($daten['objektIdFilter'] ?? '')),
			'senderFilter'             => $this->normalisiereSenderFilter($daten['senderFilter'] ?? []),
			'textFilter'               => trim((string) ($daten['textFilter'] ?? '')),
			'trefferGesamt'            => (int) ($daten['trefferGesamt'] ?? -1),
			'zaehlungLaeuft'           => (bool) ($daten['zaehlungLaeuft'] ?? false),
			'dateiGroesseCache'        => (int) ($daten['dateiGroesseCache'] ?? 0),
			'dateiMTimeCache'          => (int) ($daten['dateiMTimeCache'] ?? 0),
			'zaehlSignatur'            => (string) ($daten['zaehlSignatur'] ?? ''),
			'tabellenLadungLaeuft'     => (bool) ($daten['tabellenLadungLaeuft'] ?? false),
			'tabellenLadungText'       => trim((string) ($daten['tabellenLadungText'] ?? '')),
			'letzteTabellenLadezeitMs' => max(0, (int) ($daten['letzteTabellenLadezeitMs'] ?? 0)),
			'ultraBuildLaeuft'         => (bool) ($daten['ultraBuildLaeuft'] ?? false),
			'ultraBuildDatei'          => trim((string) ($daten['ultraBuildDatei'] ?? '')),
			'ultraIndexBereit'         => (bool) ($daten['ultraIndexBereit'] ?? false)
		];
	}

    /**
     * schreibeStatus
     *
     * Schreibt den aktuellen Visualisierungsstatus in das Attribut.
     * - Normalisiert die Werte vor dem Speichern
     * - Persistiert den Status als JSON
     *
     * Parameter: array $status
     * Rückgabewert: void
     */
	private function schreibeStatus(array $status): void
	{
		$this->WriteAttributeString(
			self::ATTR_STATUS,
			json_encode([
				'seite'                    => max(0, (int) ($status['seite'] ?? 0)),
				'maxZeilen'                => $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50)),
				'theme'                    => $this->normalisiereTheme((string) ($status['theme'] ?? 'dark')),
				'kompakt'                  => $this->normalisiereKompakt($status['kompakt'] ?? false),
				'filterTypen'              => $this->normalisiereFilterTypen($status['filterTypen'] ?? []),
				'objektIdFilter'           => $this->normalisiereObjektIdFilterString((string) ($status['objektIdFilter'] ?? '')),
				'senderFilter'             => $this->normalisiereSenderFilter($status['senderFilter'] ?? []),
				'textFilter'               => trim((string) ($status['textFilter'] ?? '')),
				'trefferGesamt'            => (int) ($status['trefferGesamt'] ?? -1),
				'zaehlungLaeuft'           => (bool) ($status['zaehlungLaeuft'] ?? false),
				'dateiGroesseCache'        => (int) ($status['dateiGroesseCache'] ?? 0),
				'dateiMTimeCache'          => (int) ($status['dateiMTimeCache'] ?? 0),
				'zaehlSignatur'            => (string) ($status['zaehlSignatur'] ?? ''),
				'tabellenLadungLaeuft'     => (bool) ($status['tabellenLadungLaeuft'] ?? false),
				'tabellenLadungText'       => trim((string) ($status['tabellenLadungText'] ?? '')),
				'letzteTabellenLadezeitMs' => max(0, (int) ($status['letzteTabellenLadezeitMs'] ?? 0)),
				'ultraBuildLaeuft'         => (bool) ($status['ultraBuildLaeuft'] ?? false),
				'ultraBuildDatei'          => trim((string) ($status['ultraBuildDatei'] ?? '')),
				'ultraIndexBereit'         => (bool) ($status['ultraIndexBereit'] ?? false)
			], JSON_THROW_ON_ERROR)
		);
	}

	/**
	 * ermittleLadeAnzeigeZustand
	 *
	 * Ermittelt den zentralen Anzeigezustand für die Oberfläche.
	 * - Trennt Tabellenladung von Hintergrundarbeit
	 * - Liefert fertige Anzeigeinformationen für HTML/Tile
	 *
	 * Parameter: array $daten
	 * Rückgabewert: array
	 */
	private function ermittleLadeAnzeigeZustand(array $daten): array
	{
		$status = is_array($daten['status'] ?? null) ? $daten['status'] : [];

		$tabellenLadung = (bool) ($daten['tabellenLadungLaeuft'] ?? $status['tabellenLadungLaeuft'] ?? false);
		$tabellenText = trim((string) ($daten['tabellenLadungText'] ?? $status['tabellenLadungText'] ?? ''));

		$zaehlungLaeuft = (bool) ($daten['zaehlungLaeuft'] ?? false);
		$filterLaeuft = (bool) ($daten['filterMetadatenLaeuft'] ?? false);

		$betriebsmodus = (string) ($daten['betriebsmodus'] ?? $this->ermittleAktivenModus());
		$ultraBuildLaeuft = (bool) ($status['ultraBuildLaeuft'] ?? false);
		$ultraIndexBereit = (bool) ($status['ultraIndexBereit'] ?? false);

		$logDatei = (string) ($daten['logDatei'] ?? $this->ReadPropertyString('LogDatei'));
		$dateiGroesse = 0;
		if ($logDatei !== '' && @is_file($logDatei)) {
			$groesse = @filesize($logDatei);
			if ($groesse !== false) {
				$dateiGroesse = (int) $groesse;
			}
		}

		$istGross = $dateiGroesse >= 200 * 1024 * 1024;
		$istSehrGross = $dateiGroesse >= 500 * 1024 * 1024;
		$filterLadezeitMs = (int) ($daten['filterLadezeitMs'] ?? 0);
		$filterLangsam = $filterLadezeitMs >= 5000;

		$anzeigeLadebalken = $tabellenLadung;
		$anzeigeLadebalkenText = $anzeigeLadebalken
			? ($tabellenText !== '' ? $tabellenText : 'Tabelle wird geladen …')
			: '';

		$anzeigeHintergrundlaufend = false;
		$anzeigeHintergrundText = '';

		if ($betriebsmodus === 'ultra' && $ultraBuildLaeuft && !$ultraIndexBereit) {
			$anzeigeHintergrundlaufend = true;
			$anzeigeHintergrundText = 'Ultra-Index wird aufgebaut …';
		} elseif ($zaehlungLaeuft && $filterLaeuft) {
			$anzeigeHintergrundlaufend = true;

			if ($betriebsmodus === 'ultra' && !$ultraIndexBereit) {
				$anzeigeHintergrundText = 'Treffer und Filteroptionen werden vorbereitet …';
			} elseif ($istSehrGross) {
				$anzeigeHintergrundText = 'Treffer und Filteroptionen werden aus großer Logdatei ermittelt …';
			} else {
				$anzeigeHintergrundText = 'Treffer und Filteroptionen werden ermittelt …';
			}
		} elseif ($zaehlungLaeuft) {
			$anzeigeHintergrundlaufend = true;

			if ($istSehrGross) {
				$anzeigeHintergrundText = 'Treffer werden in großer Logdatei ermittelt …';
			} else {
				$anzeigeHintergrundText = 'Treffer werden ermittelt …';
			}
		} elseif ($filterLaeuft) {
			$anzeigeHintergrundlaufend = true;

			if ($betriebsmodus === 'ultra' && !$ultraIndexBereit) {
				$anzeigeHintergrundText = 'Filteroptionen werden vorbereitet …';
			} elseif ($istSehrGross || $filterLangsam) {
				$anzeigeHintergrundText = 'Filteroptionen werden aus großer Logdatei ermittelt …';
			} else {
				$anzeigeHintergrundText = 'Filteroptionen werden geladen …';
			}
		}

		return [
			'anzeigeLadebalken'         => $anzeigeLadebalken,
			'anzeigeLadebalkenText'     => $anzeigeLadebalkenText,
			'anzeigeHintergrundlaufend' => $anzeigeHintergrundlaufend,
			'anzeigeHintergrundText'    => $anzeigeHintergrundText
		];
	}

    /**
     * uebernehmeLadeAnzeigeInDaten
     *
     * Ergänzt Visualisierungsdaten um zentral berechnete Ladeanzeige.
     * - Schreibt Anzeigezustände in das Datenarray
     * - Dient als einzige Quelle für die HTML-Anzeige
     *
     * Parameter: array &$daten
     * Rückgabewert: void
     */
    private function uebernehmeLadeAnzeigeInDaten(array &$daten): void
    {
        $anzeige = $this->ermittleLadeAnzeigeZustand($daten);

        $daten['anzeigeLadebalken'] = (bool) $anzeige['anzeigeLadebalken'];
        $daten['anzeigeLadebalkenText'] = (string) $anzeige['anzeigeLadebalkenText'];
        $daten['anzeigeHintergrundlaufend'] = (bool) $anzeige['anzeigeHintergrundlaufend'];
        $daten['anzeigeHintergrundText'] = (string) $anzeige['anzeigeHintergrundText'];
    }

    /**
     * sendeLadeAnzeigeDebug
     *
     * Protokolliert den zentralen Anzeigezustand für das Debugging.
     * - Schreibt die endgültige Anzeigeentscheidung ins Debug
     * - Hilft beim Nachvollziehen von Flackern und Hängern
     *
     * Parameter: string $quelle, array $daten
     * Rückgabewert: void
     */
    private function sendeLadeAnzeigeDebug(string $quelle, array $daten): void
    {
        $status = is_array($daten['status'] ?? null) ? $daten['status'] : [];

        $this->SendDebug(
            'LadeAnzeige',
            sprintf(
                'quelle=%s balken=%s balkenText=%s hintergrund=%s hintergrundText=%s tabellenLadung=%s zaehlung=%s filterLaeuft=%s ultraBuildLaeuft=%s ultraIndexBereit=%s',
                $quelle !== '' ? $quelle : '-',
                !empty($daten['anzeigeLadebalken']) ? 'true' : 'false',
                trim((string) ($daten['anzeigeLadebalkenText'] ?? '')) !== '' ? (string) $daten['anzeigeLadebalkenText'] : '-',
                !empty($daten['anzeigeHintergrundlaufend']) ? 'true' : 'false',
                trim((string) ($daten['anzeigeHintergrundText'] ?? '')) !== '' ? (string) $daten['anzeigeHintergrundText'] : '-',
                !empty($daten['tabellenLadungLaeuft']) ? 'true' : 'false',
                !empty($daten['zaehlungLaeuft']) ? 'true' : 'false',
                !empty($daten['filterMetadatenLaeuft']) ? 'true' : 'false',
                !empty($status['ultraBuildLaeuft']) ? 'true' : 'false',
                !empty($status['ultraIndexBereit']) ? 'true' : 'false'
            ),
            0
        );
    }
	
	/**
	 * setzeTabellenLadezustand
	 *
	 * Setzt den Ladezustand der Tabellenanzeige.
	 * - Aktualisiert Sichtbarkeit und Text des Ladehinweises
	 * - Speichert den Zustand im Modulstatus
	 *
	 * Parameter: bool $laeuft, string $text, string $quelle
	 * Rückgabewert: void
	 */
	private function setzeTabellenLadezustand(bool $laeuft, string $text = '', string $quelle = ''): void
	{
		$status = $this->leseStatus();

		$alterStatus = (bool) ($status['tabellenLadungLaeuft'] ?? false);
		$alterText = trim((string) ($status['tabellenLadungText'] ?? ''));

		$neuerText = $laeuft ? trim($text) : '';

		if ($alterStatus === $laeuft && $alterText === $neuerText) {
			return;
		}

		$status['tabellenLadungLaeuft'] = $laeuft;
		$status['tabellenLadungText'] = $neuerText;
		$this->schreibeStatus($status);

		$this->SendDebug(
			'Ladebalken',
			sprintf(
				'quelle=%s sichtbar=%s text=%s vorherSichtbar=%s vorherText=%s',
				$quelle !== '' ? $quelle : '-',
				$laeuft ? 'true' : 'false',
				$laeuft ? $neuerText : '-',
				$alterStatus ? 'true' : 'false',
				$alterText !== '' ? $alterText : '-'
			),
			0
		);
	}

    /**
     * leseFilterMetadatenRoh
     *
     * Liest die gespeicherten Filtermetadaten unverändert aus.
     * - Dekodiert das Attribut für Filterinformationen
     * - Gibt die Rohwerte in normalisierter Form zurück
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function leseFilterMetadatenRoh(): array
	{
		$json = $this->ReadAttributeString(self::ATTR_FILTERMETA);
		$daten = $this->dekodiereJsonArray($json);

		return [
			'verfuegbareFilterTypen' => is_array($daten['verfuegbareFilterTypen'] ?? null) ? array_values($daten['verfuegbareFilterTypen']) : [],
			'verfuegbareSender'      => is_array($daten['verfuegbareSender'] ?? null) ? array_values($daten['verfuegbareSender']) : [],
			'gesamtZeilenCache'      => (int) ($daten['gesamtZeilenCache'] ?? -1),
			'dateiGroesseCache'      => (int) ($daten['dateiGroesseCache'] ?? 0),
			'dateiMTimeCache'        => (int) ($daten['dateiMTimeCache'] ?? 0),
			'ladezeitMs'             => (int) ($daten['ladezeitMs'] ?? 0),
			'laedt'                  => (bool) ($daten['laedt'] ?? false),
			'signatur'               => (string) ($daten['signatur'] ?? '')
		];
	}


    /**
     * leseFilterMetadatenFuerAnzeige
     *
     * Bereitet die Filtermetadaten für die Anzeige auf.
     * - Prüft Dateistand und Metadaten-Signatur
     * - Liefert nur gültige Werte für die Oberfläche zurück
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function leseFilterMetadatenFuerAnzeige(): array
	{
		$roh = $this->leseFilterMetadatenRoh();
		$logDatei = $this->ReadPropertyString('LogDatei');
		$status = $this->leseStatus();

		if (!is_file($logDatei)) {
			return [
				'verfuegbareFilterTypen' => [],
				'verfuegbareSender'      => [],
				'gesamtZeilen'           => -1,
				'geladen'                => false,
				'laedt'                  => (bool) $roh['laedt'],
				'ladezeitMs'             => (int) $roh['ladezeitMs']
			];
		}

		$dateiGroesse = (int) filesize($logDatei);
		$dateiMTime = (int) filemtime($logDatei);
		$signatur = $this->ermittleFilterMetadatenSignatur($status);

		$geladen =
			((int) $roh['dateiGroesseCache'] === $dateiGroesse) &&
			((int) $roh['dateiMTimeCache'] === $dateiMTime) &&
			((string) $roh['signatur'] === $signatur);

		return [
			'verfuegbareFilterTypen' => $geladen ? array_values($roh['verfuegbareFilterTypen']) : [],
			'verfuegbareSender'      => $geladen ? array_values($roh['verfuegbareSender']) : [],
			'gesamtZeilen'           => $geladen ? (int) $roh['gesamtZeilenCache'] : -1,
			'geladen'                => $geladen,
			'laedt'                  => (bool) $roh['laedt'],
			'ladezeitMs'             => (int) $roh['ladezeitMs']
		];
	}

    /**
     * schreibeFilterMetadaten
     *
     * Schreibt Filtermetadaten in das Modulattribut.
     * - Speichert verfügbare Filterwerte und Cacheinformationen
     * - Normalisiert die Daten vor dem Schreiben
     *
     * Parameter: array $meta
     * Rückgabewert: void
     */
	private function schreibeFilterMetadaten(array $meta): void
	{
		$this->WriteAttributeString(
			self::ATTR_FILTERMETA,
			json_encode([
				'verfuegbareFilterTypen' => is_array($meta['verfuegbareFilterTypen'] ?? null) ? array_values($meta['verfuegbareFilterTypen']) : [],
				'verfuegbareSender'      => is_array($meta['verfuegbareSender'] ?? null) ? array_values($meta['verfuegbareSender']) : [],
				'gesamtZeilenCache'      => (int) ($meta['gesamtZeilenCache'] ?? -1),
				'dateiGroesseCache'      => (int) ($meta['dateiGroesseCache'] ?? 0),
				'dateiMTimeCache'        => (int) ($meta['dateiMTimeCache'] ?? 0),
				'ladezeitMs'             => (int) ($meta['ladezeitMs'] ?? 0),
				'laedt'                  => (bool) ($meta['laedt'] ?? false),
				'signatur'               => (string) ($meta['signatur'] ?? '')
			], JSON_THROW_ON_ERROR)
		);
	}

    /**
     * hatAktiveFilter
     *
     * Prüft, ob im Status aktive Filter gesetzt sind.
     * - Berücksichtigt Typ, Objekt-ID, Sender und Text
     * - Liefert true bei mindestens einem aktiven Filter
     *
     * Parameter: array $status
     * Rückgabewert: bool
     */
	private function hatAktiveFilter(array $status): bool
	{
		return
			count($this->normalisiereFilterTypen($status['filterTypen'] ?? [])) > 0 ||
			count($this->normalisiereObjektIdFilterListe((string) ($status['objektIdFilter'] ?? ''))) > 0 ||
			count($this->normalisiereSenderFilter($status['senderFilter'] ?? [])) > 0 ||
			trim((string) ($status['textFilter'] ?? '')) !== '';
	}

    /**
     * ermittleAktivenModus
     *
     * Ermittelt den aktuell konfigurierten Betriebsmodus.
     * - Liest die Moduleigenschaft Betriebsmodus aus
     * - Gibt nur gültige Moduswerte zurück
     *
     * Parameter: keine
     * Rückgabewert: string
     */
	private function ermittleAktivenModus(): string
	{
		$modus = strtolower(trim($this->ReadPropertyString('Betriebsmodus')));

		return in_array($modus, ['standard', 'system', 'ultra'], true)
			? $modus
			: 'standard';
	}

    /**
     * pruefeModusVerwendbarkeit
     *
     * Prüft, ob der aktuelle Betriebsmodus verwendet werden kann.
     * - Validiert Logdatei, Modusstatus und Größenbeschränkungen
     * - Liefert Status und Fehlermeldung zurück
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function pruefeModusVerwendbarkeit(): array
	{
		$modus = $this->ermittleAktivenModus();

		if ($modus === 'ultra') {
			return $this->pruefeUltraProgrammVerwendbarkeit();
		}

		$logDatei = $this->ReadPropertyString('LogDatei');

		if (!is_file($logDatei)) {
			return [
				'ok' => false,
				'fehlermeldung' => 'Logdatei nicht gefunden: ' . $logDatei
			];
		}

		if ($modus === 'standard') {
			$dateiGroesse = (int) filesize($logDatei);
			$grenze = 6 * 1024 * 1024;

			if ($dateiGroesse > $grenze) {
				return [
					'ok' => false,
					'fehlermeldung' => 'Die ausgewählte Logdatei ist größer als 6 MB. Bitte verwenden Sie den Modus System.'
				];
			}
		}

		return [
			'ok' => true,
			'fehlermeldung' => ''
		];
	}

    /**
     * ermittleZaehlsignatur
     *
     * Erzeugt eine Signatur für Trefferzählungen.
     * - Berücksichtigt die aktiven Filterwerte
     * - Dient zur Prüfung auf gültige Zählergebnisse
     *
     * Parameter: array $status
     * Rückgabewert: string
     */
    private function ermittleZaehlsignatur(array $status): string
    {
        return md5(json_encode([
            'filterTypen'    => $this->normalisiereFilterTypen($status['filterTypen'] ?? []),
            'objektIdFilter' => $this->normalisiereObjektIdFilterString((string) ($status['objektIdFilter'] ?? '')),
            'senderFilter'   => $this->normalisiereSenderFilter($status['senderFilter'] ?? []),
            'textFilter'     => trim((string) ($status['textFilter'] ?? ''))
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * ermittleFilterMetadatenSignatur
     *
     * Erzeugt eine Signatur für Filtermetadaten.
     * - Berücksichtigt Modus, Datei und relevante Filter
     * - Dient zur Prüfung auf gültige Metadaten
     *
     * Parameter: array $status
     * Rückgabewert: string
     */
	private function ermittleFilterMetadatenSignatur(array $status): string
	{
		$modus = $this->ermittleAktivenModus();

/**		// Ohne Schnittmengen
		if ($modus !== 'standard') {
			return md5(json_encode([
				'modus'   => $modus,
				'logDatei'=> $this->ReadPropertyString('LogDatei')
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		}
*/

		// mit Schnittmengen Anfang
		if ($modus === 'ultra') {
			return md5(json_encode([
				'modus'            => $modus,
				'logDatei'         => $this->ReadPropertyString('LogDatei'),
				'filterTypen'      => array_values((array) ($status['filterTypen'] ?? [])),
				'senderFilter'     => array_values((array) ($status['senderFilter'] ?? [])),
				'objektIdFilter'   => trim((string) ($status['objektIdFilter'] ?? '')),
				'textFilter'       => trim((string) ($status['textFilter'] ?? ''))
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		}

		if ($modus !== 'standard') {
			return md5(json_encode([
				'modus'   => $modus,
				'logDatei'=> $this->ReadPropertyString('LogDatei')
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		}
		// mit Schnittmengen Ende


		return md5(json_encode([
			'modus'          => $modus,
			'logDatei'       => $this->ReadPropertyString('LogDatei'),
			'objektIdFilter' => $this->normalisiereObjektIdFilterString((string) ($status['objektIdFilter'] ?? '')),
			'textFilter'     => trim((string) ($status['textFilter'] ?? '')),
			'filterTypen'    => $this->normalisiereFilterTypen($status['filterTypen'] ?? []),
			'senderFilter'   => $this->normalisiereSenderFilter($status['senderFilter'] ?? [])
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}

    /**
     * parseLogZeile
     *
     * Parst eine Logzeile in die Anzeigestruktur des Moduls.
     * - Extrahiert Felder und Uhrzeit aus der Zeile
     * - Gibt null bei ungültigem Format zurück
     *
     * Parameter: string $zeile
     * Rückgabewert: ?array
     */
	private function parseLogZeile(string $zeile): ?array
	{
		$teile = $this->extrahiereLogFelder($zeile);
		if ($teile === null) {
			return null;
		}

		$zeitstempel = $teile['zeitstempel'];
		$objektId = $teile['objektId'];
		$typ = $teile['typ'];
		$sender = $teile['sender'];
		$meldung = $teile['meldung'];

		preg_match('/(\d{2}:\d{2}:\d{2})/', $zeitstempel, $treffer);
		$uhrzeit = $treffer[1] ?? '';

		return [
			'zeitstempel' => $zeitstempel,
			'zeit'        => $uhrzeit,
			'objektId'    => $objektId,
			'typ'         => $typ,
			'sender'      => $sender,
			'meldung'     => $meldung
		];
	}

    /**
     * extrahiereLogFelder
     *
     * Zerlegt eine Logzeile in ihre einzelnen Felder.
     * - Erwartet das Pipe-getrennte Logformat
     * - Gibt null bei ungültigen oder leeren Zeilen zurück
     *
     * Parameter: string $zeile
     * Rückgabewert: ?array
     */
	private function extrahiereLogFelder(string $zeile): ?array
	{
		$zeile = trim($this->normalisiereUtf8String($zeile));
		if ($zeile === '') {
			return null;
		}

		$teile = explode('|', $zeile, 5);
		if (count($teile) < 5) {
			return null;
		}

		return [
			'zeitstempel' => trim($this->normalisiereUtf8String($teile[0])),
			'objektId'    => trim($this->normalisiereUtf8String($teile[1])),
			'typ'         => trim($this->normalisiereUtf8String($teile[2])),
			'sender'      => trim($this->normalisiereUtf8String($teile[3])),
			'meldung'     => trim($this->normalisiereUtf8String($teile[4]))
		];
	}

    /**
     * logZeileErfuelltFilter
     *
     * Prüft, ob eine Logzeile die aktiven Filterbedingungen erfüllt.
     * - Vergleicht Typ, Objekt-ID, Sender und Textfilter
     * - Liefert true bei vollständiger Übereinstimmung
     *
     * Parameter: array $felder, array $status
     * Rückgabewert: bool
     */
	private function logZeileErfuelltFilter(array $felder, array $status): bool
	{
		$filterTypen = $this->normalisiereFilterTypen($status['filterTypen'] ?? []);
		$objektIds = $this->normalisiereObjektIdFilterListe((string) ($status['objektIdFilter'] ?? ''));
		$senderFilter = $this->normalisiereSenderFilter($status['senderFilter'] ?? []);
		$textFilter = trim((string) ($status['textFilter'] ?? ''));

		if (count($objektIds) > 0 && !in_array($felder['objektId'], $objektIds, true)) {
			return false;
		}

		if (count($filterTypen) > 0 && !in_array($felder['typ'], $filterTypen, true)) {
			return false;
		}

		if (count($senderFilter) > 0 && !in_array($felder['sender'], $senderFilter, true)) {
			return false;
		}

		if ($textFilter !== '' && mb_stripos($felder['meldung'], $textFilter, 0, 'UTF-8') === false) {
			return false;
		}
		return true;
	}


    /**
     * baueAnzeigeZeileAusFeldern
     *
     * Baut aus extrahierten Feldern eine Anzeigezeile auf.
     * - Ergänzt die gekürzte Uhrzeit für die Oberfläche
     * - Gibt die normalisierte Zeilenstruktur zurück
     *
     * Parameter: array $felder
     * Rückgabewert: array
     */
	private function baueAnzeigeZeileAusFeldern(array $felder): array
	{
		preg_match('/(\d{2}:\d{2}:\d{2})/', $felder['zeitstempel'], $treffer);
		$uhrzeit = $treffer[1] ?? '';

		return [
			'zeitstempel' => $felder['zeitstempel'],
			'zeit'        => $uhrzeit,
			'objektId'    => $felder['objektId'],
			'typ'         => $felder['typ'],
			'sender'      => $felder['sender'],
			'meldung'     => $felder['meldung']
		];
	}

    /**
     * normalisiereUtf8String
     *
     * Normalisiert einen String auf gültiges UTF-8.
     * - Prüft vorhandene Kodierung und konvertiert bei Bedarf
     * - Gibt den bereinigten String zurück
     *
     * Parameter: string $wert
     * Rückgabewert: string
     */
	private function normalisiereUtf8String(string $wert): string
	{
		if ($wert === '') {
			return '';
		}

		if (preg_match('//u', $wert) === 1) {
			return $wert;
		}

		if (function_exists('mb_convert_encoding')) {
			$konvertiert = @mb_convert_encoding($wert, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
			if (is_string($konvertiert) && $konvertiert !== '' && preg_match('//u', $konvertiert) === 1) {
				return $konvertiert;
			}
		}

		if (function_exists('iconv')) {
			$ersetzt = @iconv('UTF-8', 'UTF-8//IGNORE', $wert);
			if (is_string($ersetzt)) {
				return $ersetzt;
			}
		}

		return $wert;
	}

    /**
     * normalisiereMaxZeilen
     *
     * Prüft und begrenzt die zulässige Anzahl an Tabellenzeilen.
     * - Akzeptiert nur definierte Werte aus der Auswahlliste
     * - Verwendet einen Standardwert bei ungültiger Eingabe
     *
     * Parameter: int $wert
     * Rückgabewert: int
     */
	private function normalisiereMaxZeilen(int $wert): int
	{
		$erlaubt = [20, 50, 100, 200, 500, 1000, 2000, 3000];
		return in_array($wert, $erlaubt, true) ? $wert : 50;
	}

    /**
     * normalisiereFilterTypen
     *
     * Normalisiert die Liste ausgewählter Filtertypen.
     * - Entfernt leere und doppelte Einträge
     * - Gibt eine bereinigte Werteliste zurück
     *
     * Parameter: mixed $filterTypen
     * Rückgabewert: array
     */
    private function normalisiereFilterTypen(mixed $filterTypen): array
    {
        if (!is_array($filterTypen)) {
            return [];
        }
        $ergebnis = [];
        foreach ($filterTypen as $typ) {
            $typ = trim((string) $typ);
            if ($typ === '') {
                continue;
            }
            $ergebnis[] = $typ;
        }
        return array_values(array_unique($ergebnis));
    }

    /**
     * normalisiereSenderFilter
     *
     * Normalisiert die Liste ausgewählter Senderfilter.
     * - Akzeptiert String oder Array als Eingabe
     * - Entfernt leere und doppelte Einträge
     *
     * Parameter: mixed $senderFilter
     * Rückgabewert: array
     */
    private function normalisiereSenderFilter(mixed $senderFilter): array
    {
        if (is_string($senderFilter)) {
            $senderFilter = [$senderFilter];
        }
        if (!is_array($senderFilter)) {
            return [];
        }
        $ergebnis = [];
        foreach ($senderFilter as $sender) {
            $sender = trim((string) $sender);
            if ($sender === '') {
                continue;
            }
            $ergebnis[] = $sender;
        }
        return array_values(array_unique($ergebnis));
    }

    /**
     * normalisiereObjektIdFilterListe
     *
     * Zerlegt den Objekt-ID-Filter in eine bereinigte Liste.
     * - Trennt Eingaben nach Leerzeichen, Komma oder Semikolon
     * - Begrenzt die Anzahl der übernommenen Werte
     *
     * Parameter: string $wert
     * Rückgabewert: array
     */
    private function normalisiereObjektIdFilterListe(string $wert): array
    {
        $teile = preg_split('/[\s,;]+/', trim($wert)) ?: [];
        $ergebnis = [];
        foreach ($teile as $teil) {
            $teil = trim($teil);
            if ($teil === '') {
                continue;
            }
            $ergebnis[] = $teil;
            if (count($ergebnis) >= 10) {
                break;
            }
        }
        return array_values(array_unique($ergebnis));
    }

    /**
     * normalisiereObjektIdFilterString
     *
     * Normalisiert den Objekt-ID-Filter als Zeichenkette.
     * - Bereitet die Liste intern auf
     * - Gibt die Werte als kommagetrennten String zurück
     *
     * Parameter: string $wert
     * Rückgabewert: string
     */
    private function normalisiereObjektIdFilterString(string $wert): string
    {
        return implode(', ', $this->normalisiereObjektIdFilterListe($wert));
    }

    /**
     * normalisiereTheme
     *
     * Normalisiert das gewählte Farbschema der Anzeige.
     * - Akzeptiert nur gültige Theme-Werte
     * - Verwendet dark als Standardwert
     *
     * Parameter: string $theme
     * Rückgabewert: string
     */
	private function normalisiereTheme(string $theme): string
	{
		$theme = strtolower(trim($theme));
		return in_array($theme, ['dark', 'light'], true) ? $theme : 'dark';
	}

    /**
     * normalisiereKompakt
     *
     * Normalisiert den Kompaktmodus der Anzeige.
     * - Wandelt den übergebenen Wert in bool um
     * - Liefert den bereinigten Zustand zurück
     *
     * Parameter: mixed $wert
     * Rückgabewert: bool
     */
	private function normalisiereKompakt(mixed $wert): bool
	{
		return (bool) $wert;
	}

    /**
     * dekodiereJsonArray
     *
     * Dekodiert einen JSON-String in ein Array.
     * - Gibt bei Fehlern ein leeres Array zurück
     * - Protokolliert JSON-Fehler als Debugmeldung
     *
     * Parameter: string $json
     * Rückgabewert: array
     */
    private function dekodiereJsonArray(string $json): array
    {
        if ($json === '') {
            return [];
        }
        try {
            $daten = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($daten) ? $daten : [];
        } catch (\Throwable $e) {
            $this->SendDebug('JSON-Fehler', $e->getMessage(), 0);
            return [];
        }
    }

    /**
     * formatiereDateigroesse
     *
     * Formatiert eine Dateigröße in eine lesbare Darstellung.
     * - Wandelt Bytes in passende Einheiten um
     * - Gibt den formatierten Text zurück
     *
     * Parameter: int $bytes
     * Rückgabewert: string
     */
    private function formatiereDateigroesse(int $bytes): string
    {
        $einheiten = ['B', 'KB', 'MB', 'GB', 'TB'];
        $wert = (float) $bytes;
        $index = 0;
        while ($wert >= 1024 && $index < count($einheiten) - 1) {
            $wert /= 1024;
            $index++;
        }
        return number_format($wert, 2, ',', '.') . ' ' . $einheiten[$index];
    }

    /**
     * ermittleVerfuegbareLogdateien
     *
     * Ermittelt verfügbare Logdateien im Logverzeichnis.
     * - Sammelt Dateiinformationen und Anzeigetexte
     * - Sortiert die Dateien nach Zeitstempel absteigend
     *
     * Parameter: keine
     * Rückgabewert: array
     */
	private function ermittleVerfuegbareLogdateien(): array
	{
		$logDir = rtrim(IPS_GetLogDir(), DIRECTORY_SEPARATOR);
		$muster = $logDir . DIRECTORY_SEPARATOR . 'logfile*.log';
		$dateien = glob($muster);

		if ($dateien === false) {
			return [];
		}

		$ergebnis = [];

		foreach ($dateien as $datei) {
			if (!is_file($datei)) {
				continue;
			}

			$basename = basename($datei);
			$unixzeit = $this->extrahiereUnixzeitAusDateiname($basename);
			$mtime = (int) filemtime($datei);
			$groesseBytes = (int) filesize($datei);
			$groesseFormatiert = $this->formatiereDateigroesse($groesseBytes);

			$ergebnis[] = [
				'pfad'      => $datei,
				'dateiname' => $basename,
				'anzeige'   => $this->formatiereLogdateiAnzeige($basename, $unixzeit, $groesseFormatiert),
				'unixzeit'  => $unixzeit,
				'mtime'     => $mtime,
				'groesse'   => $groesseFormatiert
			];
		}

		usort($ergebnis, static function (array $a, array $b): int {
			$zeitA = (int) ($a['unixzeit'] ?: $a['mtime']);
			$zeitB = (int) ($b['unixzeit'] ?: $b['mtime']);

			return $zeitB <=> $zeitA;
		});

		return $ergebnis;
	}

    /**
     * extrahiereUnixzeitAusDateiname
     *
     * Liest einen Unix-Zeitstempel aus dem Logdateinamen aus.
     * - Prüft das erwartete Namensmuster der Datei
     * - Gibt 0 zurück, wenn kein Zeitstempel gefunden wurde
     *
     * Parameter: string $dateiname
     * Rückgabewert: int
     */
	private function extrahiereUnixzeitAusDateiname(string $dateiname): int
	{
		if (preg_match('/^logfile(\d{9,})\.log$/', $dateiname, $treffer) === 1) {
			return (int) $treffer[1];
		}
		return 0;
	}

    /**
     * formatiereLogdateiAnzeige
     *
     * Erzeugt den Anzeigetext für eine Logdatei.
     * - Formatiert Zeitstempel und Dateigröße lesbar
     * - Gibt den fertigen Auswahltext zurück
     *
     * Parameter: string $dateiname, int $unixzeit, string $groesseFormatiert
     * Rückgabewert: string
     */
	private function formatiereLogdateiAnzeige(string $dateiname, int $unixzeit, string $groesseFormatiert = ''): string
	{
		if ($unixzeit > 0) {
			$anzeige = 'log-' . date('Y.m.d-H:i:s', $unixzeit);
		} else {
			$anzeige = $dateiname;
		}
		if ($groesseFormatiert !== '') {
			$anzeige .= ' · ' . $groesseFormatiert;
		}
		return $anzeige;
	}

	private function sendeDebugLadezustandSnapshot(string $quelle, array $status, array $meta = []): void
	{
		$modus = $this->ermittleAktivenModus();
		$logDatei = $this->ReadPropertyString('LogDatei');

		$this->SendDebug(
			'LadezustandSnapshot',
			sprintf(
				'quelle=%s modus=%s datei=%s tabellenLadung=%s tabellenText=%s zaehlung=%s treffer=%d filterLaeuft=%s filterTypen=%d sender=%d filterLadezeitMs=%d letzteTabellenLadezeitMs=%d ultraBuildLaeuft=%s ultraIndexBereit=%s ultraBuildDatei=%s',
				$quelle !== '' ? $quelle : '-',
				$modus,
				basename($logDatei),
				(bool) ($status['tabellenLadungLaeuft'] ?? false) ? 'true' : 'false',
				trim((string) ($status['tabellenLadungText'] ?? '')) !== '' ? trim((string) ($status['tabellenLadungText'] ?? '')) : '-',
				(bool) ($status['zaehlungLaeuft'] ?? false) ? 'true' : 'false',
				(int) ($status['trefferGesamt'] ?? -1),
				(bool) ($meta['laedt'] ?? false) ? 'true' : 'false',
				is_array($meta['verfuegbareFilterTypen'] ?? null) ? count($meta['verfuegbareFilterTypen']) : 0,
				is_array($meta['verfuegbareSender'] ?? null) ? count($meta['verfuegbareSender']) : 0,
				(int) ($meta['ladezeitMs'] ?? 0),
				(int) ($status['letzteTabellenLadezeitMs'] ?? 0),
				(bool) ($status['ultraBuildLaeuft'] ?? false) ? 'true' : 'false',
				(bool) ($status['ultraIndexBereit'] ?? false) ? 'true' : 'false',
				trim((string) ($status['ultraBuildDatei'] ?? '')) !== '' ? basename((string) ($status['ultraBuildDatei'] ?? '')) : '-'
			),
			0
		);
	}

}