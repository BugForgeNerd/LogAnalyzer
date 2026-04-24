<?php
trait LogAnalyzerUltraTrait
{
	/**
	 * ermittleUltraLogdateiInformation
	 *
	 * Ermittelt und validiert die im Ultra-Modus zu verwendende Logdatei.
	 * - Prüft Existenz, Lesbarkeit und reguläre Datei
	 * - Verhindert Symlinks und Windows-Hardlinks
	 * - Liefert normalisierte Pfadinformationen zurück
	 *
	 * Parameter: keine
	 * Rückgabewert: array
	 */
    private function ermittleUltraLogdateiInformation(): array
    {
        $konfigurierterPfad = trim($this->leseAktiveLogDatei());

        if ($konfigurierterPfad === '') {
            return [
                'ok'            => false,
                'fehlermeldung' => 'Es ist keine Logdatei konfiguriert.',
                'pfad'          => '',
                'originalPfad'  => '',
                'realPfad'      => '',
                'istSymlink'    => false,
                'existiert'     => false
            ];
        }

        if (!file_exists($konfigurierterPfad)) {
            return [
                'ok'            => false,
                'fehlermeldung' => 'Die konfigurierte Logdatei wurde nicht gefunden: ' . $konfigurierterPfad,
                'pfad'          => '',
                'originalPfad'  => $konfigurierterPfad,
                'realPfad'      => '',
                'istSymlink'    => false,
                'existiert'     => false
            ];
        }

        if (is_link($konfigurierterPfad)) {
            return [
                'ok'            => false,
                'fehlermeldung' => 'Im Ultra-Modus sind Symlinks nicht zulässig. Bitte wählen Sie die echte Logdatei aus.',
                'pfad'          => '',
                'originalPfad'  => $konfigurierterPfad,
                'realPfad'      => '',
                'istSymlink'    => true,
                'existiert'     => true
            ];
        }

		// Windows Hardlink prüfen
		if (PHP_OS_FAMILY === 'Windows' && $this->istWindowsHardlink($konfigurierterPfad)) {
			return [
				'ok'            => false,
				'fehlermeldung' => 'Im Ultra-Modus sind Windows-Hardlinks nicht zulässig. Bitte wählen Sie die echte Logdatei aus.',
				'pfad'          => '',
				'originalPfad'  => $konfigurierterPfad,
				'realPfad'      => '',
				//'istSymlink'    => true,
				'istSymlink'    => false,
				'existiert'     => true
			];
		}

        $realPfad = realpath($konfigurierterPfad);
        $finalerPfad = ($realPfad !== false) ? $realPfad : $konfigurierterPfad;

        if (!is_file($finalerPfad)) {
            return [
                'ok'            => false,
                'fehlermeldung' => 'Die konfigurierte Logdatei ist keine reguläre Datei: ' . $konfigurierterPfad,
                'pfad'          => '',
                'originalPfad'  => $konfigurierterPfad,
                'realPfad'      => $realPfad !== false ? $realPfad : '',
                'istSymlink'    => false,
                'existiert'     => true
            ];
        }

        if (!is_readable($finalerPfad)) {
            return [
                'ok'            => false,
                'fehlermeldung' => 'Die Logdatei ist im Ultra-Modus nicht lesbar: ' . $finalerPfad,
                'pfad'          => '',
                'originalPfad'  => $konfigurierterPfad,
                'realPfad'      => $realPfad !== false ? $realPfad : '',
                'istSymlink'    => false,
                'existiert'     => true
            ];
        }

        return [
            'ok'            => true,
            'fehlermeldung' => '',
            'pfad'          => $finalerPfad,
            'originalPfad'  => $konfigurierterPfad,
            'realPfad'      => $realPfad !== false ? $realPfad : $finalerPfad,
            'istSymlink'    => false,
            'existiert'     => true
        ];
    }

	/**
	 * baueUltraProzessArgumente
	 *
	 * Baut die Argumentliste für ein Ultra-CLI-Kommando.
	 * - Fügt Logdatei, Filter und Zusatzargumente zusammen
	 * - Liefert die Argumente für proc_open zurück
	 *
	 * Parameter: string $kommando, array $status, array $zusaetzlicheArgumente
	 * Rückgabewert: array
	 */
	private function baueUltraProzessArgumente(string $kommando, array $status = [], array $zusaetzlicheArgumente = []): array
	{
		$programmPfad = $this->ermittleUltraProgrammPfad();
		$logInfo = $this->ermittleUltraLogdateiInformation();

		if ($programmPfad === '' || !(bool) ($logInfo['ok'] ?? false)) {
			return [];
		}

		$argumente = [
			$programmPfad,
			$kommando,
			'--file',
			(string) ($logInfo['pfad'] ?? ''),
			'--multiline-mode',
			'on'
		];

		foreach ($this->baueUltraFilterArgumente($status) as $key => $value) {
			if ($value === null || $value === false || $value === '') {
				continue;
			}

			$argumente[] = $key;
			if ($value !== true) {
				$argumente[] = (string) $value;
			}
		}

		foreach ($zusaetzlicheArgumente as $key => $value) {
			if ($value === null || $value === false || $value === '') {
				continue;
			}

			$argumente[] = $key;
			if ($value !== true) {
				$argumente[] = (string) $value;
			}
		}

		return $argumente;
	}

	/**
	 * ermittleUltraLogdateiPfad
	 *
	 * Liefert den validierten Pfad der Ultra-Logdatei.
	 * - Verwendet ermittleUltraLogdateiInformation
	 * - Gibt leeren String bei ungültiger Datei zurück
	 *
	 * Parameter: keine
	 * Rückgabewert: string
	 */
    private function ermittleUltraLogdateiPfad(): string
    {
        $info = $this->ermittleUltraLogdateiInformation();
        if (!(bool) ($info['ok'] ?? false)) {
            return '';
        }

        return (string) ($info['pfad'] ?? '');
    }

	/**
	 * ermittleUltraProgrammPfad
	 *
	 * Liefert den konfigurierten Pfad zum Ultra-CLI-Programm.
	 *
	 * Parameter: keine
	 * Rückgabewert: string
	 */
    private function ermittleUltraProgrammPfad(): string
    {
        return trim($this->ReadPropertyString('UltraProgrammPfad'));
    }

	/**
	 * pruefeUltraProgrammVerwendbarkeit
	 *
	 * Prüft, ob das Ultra-Programm verwendet werden kann.
	 * - Validiert Programmpfad und Ausführbarkeit
	 * - Prüft zusätzlich die Ultra-Logdatei
	 * - Liefert Fehlermeldung bei ungültiger Konfiguration
	 *
	 * Parameter: keine
	 * Rückgabewert: array
	 */
    private function pruefeUltraProgrammVerwendbarkeit(): array
    {
        $programmPfad = $this->ermittleUltraProgrammPfad();
        if ($programmPfad === '') {
            return [
                'ok'            => false,
                'fehlermeldung' => 'Für den Ultra-Modus ist kein Programmpfad konfiguriert.'
            ];
        }

        if (!file_exists($programmPfad) || !is_file($programmPfad)) {
            return [
                'ok'            => false,
                'fehlermeldung' => 'Das konfigurierte Ultra-Programm wurde nicht gefunden: ' . $programmPfad
            ];
        }

        if (!is_readable($programmPfad)) {
            return [
                'ok'            => false,
                'fehlermeldung' => 'Auf das konfigurierte Ultra-Programm kann nicht gelesen werden: ' . $programmPfad
            ];
        }

        if (strncasecmp(PHP_OS, 'WIN', 3) !== 0 && !is_executable($programmPfad)) {
            return [
                'ok'            => false,
                'fehlermeldung' => 'Das konfigurierte Ultra-Programm ist nicht ausführbar: ' . $programmPfad
            ];
        }

        $logInfo = $this->ermittleUltraLogdateiInformation();
        if (!(bool) ($logInfo['ok'] ?? false)) {
            return [
                'ok'            => false,
                'fehlermeldung' => (string) ($logInfo['fehlermeldung'] ?? 'Die Logdatei ist für den Ultra-Modus nicht verwendbar.')
            ];
        }

        return [
            'ok'            => true,
            'fehlermeldung' => ''
        ];
    }

	/**
	 * leereUltraAnalyseFuerAktuelleDatei
	 *
	 * Löscht Index und Analyse-Cache für die aktuelle Logdatei.
	 * - Führt das Ultra-Kommando clear-all aus
	 * - Nur wenn Programm und Logdatei gültig sind
	 *
	 * Parameter: keine
	 * Rückgabewert: void
	 */
    private function leereUltraAnalyseFuerAktuelleDatei(): void
    {
        $pruefung = $this->pruefeUltraProgrammVerwendbarkeit();
        if (!(bool) ($pruefung['ok'] ?? false)) {
            return;
        }

        $this->fuehreUltraJsonKommandoAus('clear-all');
    }

	/**
	 * ladeLogZeilenUltra
	 *
	 * Lädt Logzeilen über die Ultra-CLI.
	 * - Unterstützt Paging und Filter
	 * - Startet Build falls Index fehlt
	 * - Aktualisiert Seiten-Cache
	 *
	 * Parameter: array $status
	 * Rückgabewert: array
	 */
	private function ladeLogZeilenUltra(array $status): array
	{
		$pruefung = $this->pruefeUltraProgrammVerwendbarkeit();
		if (!(bool) ($pruefung['ok'] ?? false)) {
			return [
				'ok'            => false,
				'fehlermeldung' => (string) ($pruefung['fehlermeldung'] ?? 'Ultra-Modus nicht verfügbar.'),
				'zeilen'        => [],
				'hatWeitere'    => false,
				'trefferGesamt' => -1
			];
		}

		$this->aktualisiereUltraBuildStatus();
		$ultraStatus = $this->holeUltraStatusInformation();

		if ($this->hatAktiveFilter($status) && !(bool) ($ultraStatus['bereitFuerFilter'] ?? false)) {
			$this->starteUltraBuildFallsNoetig();

			return [
				'ok'            => false,
				'fehlermeldung' => 'Ultra-Index wird noch erstellt. Filter stehen erst nach Abschluss des Build zur Verfügung.',
				'zeilen'        => [],
				'hatWeitere'    => false,
				'trefferGesamt' => -1
			];
		}

		$maxZeilen = $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50));
		$seite = max(0, (int) ($status['seite'] ?? 0));
		$offset = $seite * $maxZeilen;

		$antwort = $this->fuehreUltraJsonKommandoAus(
			'page',
			$status,
			[
				'--offset'        => (string) $offset,
				'--limit'         => (string) $maxZeilen,
				'--include-total' => true
			]
		);

		$this->SendDebug(
			'UltraPageAntwort',
			json_encode([
				'seite' => $seite,
				'offset' => $offset,
				'limit' => $maxZeilen,
				'filterAktiv' => $this->hatAktiveFilter($status),
				'antwortOk' => (bool) ($antwort['ok'] ?? false),
				'fehlermeldung' => (string) ($antwort['fehlermeldung'] ?? ''),
				'zeilenAnzahlRoh' => is_array($antwort['zeilen'] ?? null) ? count($antwort['zeilen']) : null,
				'hatWeitere' => $antwort['hatWeitere'] ?? null,
				'trefferGesamt' => $antwort['trefferGesamt'] ?? null,
				'count' => $antwort['count'] ?? null
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			0
		);

		if (!(bool) ($antwort['ok'] ?? false)) {
			$this->SendDebug(
				'UltraPageFehlerzweig',
				json_encode([
					'seite' => $seite,
					'offset' => $offset,
					'filterAktiv' => $this->hatAktiveFilter($status),
					'analysestatusNochNichtVerfuegbar' => $this->istUltraAnalysebasisNochNichtVerfuegbar($antwort),
					'fehlermeldung' => (string) ($antwort['fehlermeldung'] ?? '')
				], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				0
			);

			if ($this->istUltraAnalysebasisNochNichtVerfuegbar($antwort)) {
				$this->starteUltraBuildFallsNoetig();
				return [
					'ok'            => false,
					'fehlermeldung' => 'Ultra-Index wird noch erstellt. Filter stehen erst nach Abschluss des Build zur Verfügung.',
					'zeilen'        => [],
					'hatWeitere'    => false,
					'trefferGesamt' => -1
				];
			}

			return [
				'ok'            => false,
				'fehlermeldung' => (string) ($antwort['fehlermeldung'] ?? 'Ultra-Page fehlgeschlagen.'),
				'zeilen'        => [],
				'hatWeitere'    => false,
				'trefferGesamt' => -1
			];
		}

		$zeilen = [];
		foreach ((array) ($antwort['zeilen'] ?? []) as $zeile) {
			if (!is_array($zeile)) {
				continue;
			}
			$zeilen[] = $this->normalisiereUltraAnzeigeZeile($zeile);
		}

		$hatWeitere = (bool) ($antwort['hatWeitere'] ?? false);
		$trefferGesamt = -1;
		if (array_key_exists('trefferGesamt', $antwort) && (int) $antwort['trefferGesamt'] >= 0) {
			$trefferGesamt = (int) $antwort['trefferGesamt'];
		} elseif (array_key_exists('count', $antwort) && (int) $antwort['count'] >= 0) {
			$trefferGesamt = (int) $antwort['count'];
		}

		$logDatei = $this->ermittleUltraLogdateiPfad();
		$this->schreibeSeitenCache([
			'listenSignatur'    => $this->ermittleListenCacheSignatur($status),
			'zaehlSignatur'     => $this->ermittleZaehlsignatur($status),
			'dateiGroesseCache' => is_file($logDatei) ? (int) filesize($logDatei) : 0,
			'dateiMTimeCache'   => is_file($logDatei) ? (int) filemtime($logDatei) : 0,
			'trefferGesamt'     => $trefferGesamt,
			'hatWeitere'        => $hatWeitere,
			'zeilen'            => $zeilen
		]);

		if (!$this->hatAktiveFilter($status)) {
			$this->starteUltraBuildFallsNoetig();
		}

		$this->SendDebug(
			'UltraPageNormalisiert',
			json_encode([
				'seite' => $seite,
				'offset' => $offset,
				'zeilenAnzahlNormalisiert' => count($zeilen),
				'hatWeitere' => $hatWeitere,
				'trefferGesamt' => $trefferGesamt
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			0
		);

		return [
			'ok'            => true,
			'fehlermeldung' => '',
			'zeilen'        => $zeilen,
			'hatWeitere'    => $hatWeitere,
			'trefferGesamt' => $trefferGesamt
		];
	}

	/**
	 * zaehleGefilterteZeilenUltra
	 *
	 * Zählt gefilterte Logzeilen über die Ultra-CLI.
	 * - Prüft Build-Status und startet ggf. den Index-Build
	 * - Führt das count-Kommando aus
	 * - Nutzt verschiedene Rückgabefelder als Fallback
	 *
	 * Parameter: array $status
	 * Rückgabewert: int
	 */
	private function zaehleGefilterteZeilenUltra(array $status): int
	{
		$this->aktualisiereUltraBuildStatus();
		$ultraStatus = $this->holeUltraStatusInformation();

		if (!(bool) ($ultraStatus['bereitFuerFilter'] ?? false)) {
			$this->starteUltraBuildFallsNoetig();
			return -1;
		}

		$antwort = $this->fuehreUltraJsonKommandoAus('count', $status);

		if (!(bool) ($antwort['ok'] ?? false)) {
			$this->SendDebug(
				'zaehleGefilterteZeilenUltra',
				'fehler=' . (string) ($antwort['fehlermeldung'] ?? 'Unbekannter Fehler'),
				0
			);
			return -1;
		}

		if (array_key_exists('trefferGesamt', $antwort)) {
			return (int) $antwort['trefferGesamt'];
		}

		if (array_key_exists('count', $antwort)) {
			return (int) $antwort['count'];
		}

		if (array_key_exists('anzahl', $antwort)) {
			return (int) $antwort['anzahl'];
		}

		if (array_key_exists('recordsImIndex', $ultraStatus)) {
			return (int) $ultraStatus['recordsImIndex'];
		}

		return -1;
	}

	/**
	 * ermittleFilterMetadatenUltra
	 *
	 * Ermittelt Filtermetadaten über die Ultra-CLI.
	 * - Prüft Build-Status und startet ggf. den Index-Build
	 * - Liest Typen und Sender aus Facet-Daten
	 * - Liefert Gesamtzeilen und Cachebarkeit
	 *
	 * Parameter: keine
	 * Rückgabewert: array
	 */
	private function ermittleFilterMetadatenUltra(): array
	{
		$status = $this->leseStatus();

		$this->aktualisiereUltraBuildStatus();
		$ultraStatus = $this->holeUltraStatusInformation();

		if (!(bool) ($ultraStatus['bereitFuerFilter'] ?? false)) {
			$this->starteUltraBuildFallsNoetig();

			return [
				'verfuegbareFilterTypen' => [],
				'verfuegbareSender'      => [],
				'gesamtZeilen'           => -1,
				'cachebar'               => false
			];
		}

		$antwort = $this->fuehreUltraJsonKommandoAus(
			'stats',
			$status,
			[
				'--with-intersections' => true
			]
		);

		if (!(bool) ($antwort['ok'] ?? false)) {
			$this->SendDebug(
				'ermittleFilterMetadatenUltra',
				'fehler=' . (string) ($antwort['fehlermeldung'] ?? 'Unbekannter Fehler'),
				0
			);

			return [
				'verfuegbareFilterTypen' => [],
				'verfuegbareSender'      => [],
				'gesamtZeilen'           => -1,
				'cachebar'               => false
			];
		}

		$typen = [];
		$sender = [];

		if (is_array($antwort['facets']['typ'] ?? null)) {
			foreach ($antwort['facets']['typ'] as $eintrag) {
				$wert = trim((string) ($eintrag['wert'] ?? ''));
				if ($wert !== '') {
					$typen[] = $wert;
				}
			}
		} elseif (is_array($antwort['verfuegbareFilterTypen'] ?? null)) {
			$typen = array_values($antwort['verfuegbareFilterTypen']);
		}

		if (is_array($antwort['facets']['sender'] ?? null)) {
			foreach ($antwort['facets']['sender'] as $eintrag) {
				$wert = trim((string) ($eintrag['wert'] ?? ''));
				if ($wert !== '') {
					$sender[] = $wert;
				}
			}
		} elseif (is_array($antwort['verfuegbareSender'] ?? null)) {
			$sender = array_values($antwort['verfuegbareSender']);
		}

		$typen = array_values(array_unique($typen));
		$sender = array_values(array_unique($sender));

		sort($typen, SORT_NATURAL | SORT_FLAG_CASE);
		sort($sender, SORT_NATURAL | SORT_FLAG_CASE);

		$gesamtZeilen = -1;

		if (strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
			if (array_key_exists('trefferGesamt', $antwort) && (int) $antwort['trefferGesamt'] >= 0) {
				$gesamtZeilen = (int) $antwort['trefferGesamt'];
			} elseif (array_key_exists('count', $antwort) && (int) $antwort['count'] >= 0) {
				$gesamtZeilen = (int) $antwort['count'];
			} elseif (array_key_exists('recordsImIndex', $ultraStatus)) {
				$gesamtZeilen = (int) $ultraStatus['recordsImIndex'];
			}
		}

		return [
			'verfuegbareFilterTypen' => $typen,
			'verfuegbareSender'      => $sender,
			'gesamtZeilen'           => $gesamtZeilen,
			'cachebar'               => true
		];
	}

	/**
	 * aktualisiereUltraBuildStatus
	 *
	 * Aktualisiert den Ultra-Build-Status im Modul.
	 * - Liest Status vom Ultra-Backend
	 * - Aktualisiert Build- und Index-Flags im Modulstatus
	 * - Passt Timer-Intervall während des Builds an
	 *
	 * Parameter: keine
	 * Rückgabewert: void
	 */
	private function aktualisiereUltraBuildStatus(): void
	{
		$pruefung = $this->pruefeUltraProgrammVerwendbarkeit();
		if (!(bool) ($pruefung['ok'] ?? false)) {
			return;
		}

		$antwort = $this->holeUltraStatusInformation();
		$status = $this->leseStatus();
		$normalIntervallMs = max(0, (int) $this->ReadPropertyInteger('AutoRefreshSekunden')) * 1000;
		$buildPollingMs = 2000;

		$this->SendDebug(
			'PagingStatus/UltraBuildStatusRead',
			json_encode([
				'seite' => (int) ($status['seite'] ?? 0),
				'maxZeilen' => (int) ($status['maxZeilen'] ?? 0),
				'ultraBuildLaeuftAlt' => (bool) ($status['ultraBuildLaeuft'] ?? false),
				'ultraIndexBereitAlt' => (bool) ($status['ultraIndexBereit'] ?? false),
				'bereitFuerFilterNeu' => (bool) ($antwort['bereitFuerFilter'] ?? false)
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			0
		);

		if ((bool) ($antwort['bereitFuerFilter'] ?? false)) {
			$status['ultraBuildLaeuft'] = false;
			$status['ultraBuildDatei'] = '';
			$status['ultraIndexBereit'] = true;

			$buildStart = (float) ($status['ultraBuildStart'] ?? 0);
			if ($buildStart > 0 && (int) ($status['ultraBuildDauerMs'] ?? 0) <= 0) {
				$status['ultraBuildDauerMs'] = (int) round((microtime(true) - $buildStart) * 1000);
			}

			$status['ultraBuildStart'] = 0.0;
			$this->schreibeStatus($status);
			$this->SetTimerInterval('VisualisierungAktualisieren', $normalIntervallMs);

			$this->SendDebug(
				'UltraBuildStatus',
				sprintf(
					'status=fertig datei=%s pollingMs=%d buildDauerMs=%d',
					basename($this->ermittleUltraLogdateiPfad()),
					$normalIntervallMs,
					(int) ($status['ultraBuildDauerMs'] ?? 0)
				),
				0
			);
			return;
		}

		if ((bool) ($status['ultraBuildLaeuft'] ?? false)) {
			$status['ultraIndexBereit'] = false;
			$this->schreibeStatus($status);
			$this->SetTimerInterval('VisualisierungAktualisieren', $buildPollingMs);

			$this->SendDebug(
				'UltraBuildStatus',
				sprintf(
					'status=laeuft datei=%s pollingMs=%d',
					basename((string) ($status['ultraBuildDatei'] ?? $this->ermittleUltraLogdateiPfad())),
					$buildPollingMs
				),
				0
			);
		}
	}

	/**
	 * holeUltraStatusInformation
	 *
	 * Liest Statusinformationen des Ultra-Backends.
	 * - Führt das CLI-Kommando "status" aus
	 * - Liefert dekodierte JSON-Antwort
	 *
	 * Parameter: keine
	 * Rückgabewert: array
	 */
    private function holeUltraStatusInformation(): array
    {
        $antwort = $this->fuehreUltraJsonKommandoAus('status');
        if (!(bool) ($antwort['ok'] ?? false)) {
            return ['ok' => false];
        }
        return $antwort;
    }

	/**
	 * starteUltraBuildFallsNoetig
	 *
	 * Startet den Ultra-Index-Build bei Bedarf.
	 * - Prüft Programm und Logdatei
	 * - Startet Build im Hintergrund
	 * - Aktualisiert Modulstatus und Timer
	 *
	 * Parameter: keine
	 * Rückgabewert: void
	 */
	private function starteUltraBuildFallsNoetig(): void
	{
		$pruefung = $this->pruefeUltraProgrammVerwendbarkeit();
		if (!(bool) ($pruefung['ok'] ?? false)) {
			return;
		}

		$logDatei = $this->ermittleUltraLogdateiPfad();
		if ($logDatei === '') {
			return;
		}

		$ultraStatus = $this->holeUltraStatusInformation();
		if ((bool) ($ultraStatus['bereitFuerFilter'] ?? false)) {
			$status = $this->leseStatus();
			$status['ultraBuildLaeuft'] = false;
			$status['ultraBuildDatei'] = '';
			$status['ultraIndexBereit'] = true;

			$buildStart = (float) ($status['ultraBuildStart'] ?? 0);
			if ($buildStart > 0 && (int) ($status['ultraBuildDauerMs'] ?? 0) <= 0) {
				$status['ultraBuildDauerMs'] = (int) round((microtime(true) - $buildStart) * 1000);
			}

			$status['ultraBuildStart'] = 0.0;
			$this->schreibeStatus($status);
			return;
		}

		$status = $this->leseStatus();
		if ((bool) ($status['ultraBuildLaeuft'] ?? false) && (string) ($status['ultraBuildDatei'] ?? '') === $logDatei) {
			return;
		}

		$this->SendDebug(
			'PagingStatus/UltraBuildStart',
			json_encode([
				'seite' => (int) ($status['seite'] ?? 0),
				'maxZeilen' => (int) ($status['maxZeilen'] ?? 0),
				'datei' => basename($logDatei)
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			0
		);

		if ($this->starteUltraJsonKommandoImHintergrund('build-index')) {
			$status['ultraBuildLaeuft'] = true;
			$status['ultraBuildDatei'] = $logDatei;
			$status['ultraIndexBereit'] = false;
			$status['ultraBuildStart'] = microtime(true);
			$status['ultraBuildDauerMs'] = 0;
			$this->schreibeStatus($status);
			$this->SetTimerInterval('VisualisierungAktualisieren', 2000);
			$this->SendDebug('UltraBuildStatus', sprintf('status=gestartet datei=%s pollingMs=%d', basename($logDatei), 2000), 0);
		}
	}

	/**
	 * fuehreUltraJsonKommandoAus
	 *
	 * Führt ein Ultra-CLI-Kommando synchron aus und verarbeitet die JSON-Antwort.
	 * - Baut Argumentliste und startet den Prozess
	 * - Liest stdout/stderr und dekodiert JSON
	 * - Liefert normalisierte Ergebnisstruktur zurück
	 *
	 * Parameter: string $kommando, array $status, array $zusaetzlicheArgumente
	 * Rückgabewert: array
	 */
	private function fuehreUltraJsonKommandoAus(string $kommando, array $status = [], array $zusaetzlicheArgumente = []): array
	{
		$pruefung = $this->pruefeUltraProgrammVerwendbarkeitOhneLogdatei();
		if (!(bool) ($pruefung['ok'] ?? false)) {
			return [
				'ok'            => false,
				'fehlermeldung' => (string) ($pruefung['fehlermeldung'] ?? 'Ultra-Programm nicht verwendbar.')
			];
		}

		$prozessArgumente = $this->baueUltraProzessArgumente($kommando, $status, $zusaetzlicheArgumente);
		if (count($prozessArgumente) === 0) {
			return [
				'ok'            => false,
				'fehlermeldung' => 'Ultra-Befehl konnte nicht erstellt werden.'
			];
		}

		$debugCmd = implode(' ', array_map(static function ($teil): string {
			return preg_match('/\s/', (string) $teil) ? '"' . (string) $teil . '"' : (string) $teil;
		}, $prozessArgumente));
		$this->SendDebug('UltraCmd', $debugCmd, 0);

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w']
		];

		$pipes = [];
		$options = [];

		if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
			$options['bypass_shell'] = true;
		}

		$process = @proc_open($prozessArgumente, $descriptors, $pipes, null, null, $options);

		if (!is_resource($process)) {
			return [
				'ok'            => false,
				'fehlermeldung' => 'Ultra-Prozess konnte nicht gestartet werden.'
			];
		}

		$stdout = '';
		$stderr = '';

		try {
			if (isset($pipes[0]) && is_resource($pipes[0])) {
				fclose($pipes[0]);
			}

			$stdout = isset($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '';
			$stderr = isset($pipes[2]) ? (string) stream_get_contents($pipes[2]) : '';
		} finally {
			foreach ($pipes as $index => $pipe) {
				if ($index === 0) {
					continue;
				}
				if (is_resource($pipe)) {
					fclose($pipe);
				}
			}
		}

		$exitCode = proc_close($process);

		$this->SendDebug(
			'UltraCmdResult',
			json_encode([
				'kommando' => $kommando,
				'exitCode' => $exitCode,
				'stdoutLeer' => trim($stdout) === '',
				'stderrLeer' => trim($stderr) === '',
				'stdoutPreview' => mb_substr(trim($stdout), 0, 1200),
				'stderrPreview' => mb_substr(trim($stderr), 0, 1200)
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			0
		);

		if (trim($stdout) === '') {
			$this->SendDebug(
				'UltraCmdResultEmpty',
				json_encode([
					'kommando' => $kommando,
					'exitCode' => $exitCode,
					'stderr' => trim($stderr)
				], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				0
			);

			return [
				'ok'            => false,
				'fehlermeldung' => trim($stderr) !== '' ? trim($stderr) : 'Ultra-Programm lieferte keine JSON-Ausgabe. ExitCode=' . $exitCode
			];
		}

		try {
			$daten = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
		} catch (\Throwable $e) {
			$this->SendDebug(
				'UltraCmdJsonDecodeError',
				json_encode([
					'kommando' => $kommando,
					'exitCode' => $exitCode,
					'fehler' => $e->getMessage(),
					'stdoutPreview' => mb_substr(trim($stdout), 0, 1200)
				], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				0
			);

			return [
				'ok'            => false,
				'fehlermeldung' => 'Ultra-JSON konnte nicht dekodiert werden: ' . $e->getMessage() . ' | Ausgabe: ' . $stdout
			];
		}

		if (!is_array($daten)) {
			return [
				'ok'            => false,
				'fehlermeldung' => 'Ultra-JSON hat kein Array geliefert.'
			];
		}

		$daten['ok'] = (bool) ($daten['ok'] ?? ($exitCode === 0));

		$this->SendDebug(
			'UltraCmdParsed',
			json_encode([
				'kommando' => $kommando,
				'ok' => (bool) ($daten['ok'] ?? false),
				'fehlermeldung' => (string) ($daten['fehlermeldung'] ?? ''),
				'keys' => array_keys($daten),
				'zeilenAnzahl' => is_array($daten['zeilen'] ?? null) ? count($daten['zeilen']) : null,
				'hatWeitere' => $daten['hatWeitere'] ?? null,
				'trefferGesamt' => $daten['trefferGesamt'] ?? null,
				'count' => $daten['count'] ?? null,
				'bereitFuerFilter' => $daten['bereitFuerFilter'] ?? null
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			0
		);

		$daten['fehlermeldung'] = (string) ($daten['fehlermeldung'] ?? '');

		if (!(bool) $daten['ok'] && $daten['fehlermeldung'] === '') {
			$daten['fehlermeldung'] = trim($stderr) !== '' ? trim($stderr) : 'Ultra-Kommando fehlgeschlagen. ExitCode=' . $exitCode;
		}

		return $daten;
	}

	/**
	 * starteUltraJsonKommandoImHintergrund
	 *
	 * Startet ein Ultra-CLI-Kommando im Hintergrund.
	 * - Baut Argumente abhängig vom Betriebssystem
	 * - Verwendet proc_open unter Windows oder exec unter Unix
	 * - Unterdrückt Ausgabe des Prozesses
	 *
	 * Parameter: string $kommando, array $status, array $zusaetzlicheArgumente
	 * Rückgabewert: bool
	 */
	private function starteUltraJsonKommandoImHintergrund(string $kommando, array $status = [], array $zusaetzlicheArgumente = []): bool
	{
		$pruefung = $this->pruefeUltraProgrammVerwendbarkeitOhneLogdatei();
		if (!(bool) ($pruefung['ok'] ?? false)) {
			return false;
		}

		if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
			$prozessArgumente = $this->baueUltraProzessArgumente($kommando, $status, $zusaetzlicheArgumente);
			if (count($prozessArgumente) === 0) {
				return false;
			}

			$debugCmd = implode(' ', array_map(static function ($teil): string {
				return preg_match('/\s/', (string) $teil) ? '"' . (string) $teil . '"' : (string) $teil;
			}, $prozessArgumente));
			$this->SendDebug('UltraCmdBackground', $debugCmd, 0);

			$descriptors = [
				0 => ['file', 'NUL', 'r'],
				1 => ['file', 'NUL', 'w'],
				2 => ['file', 'NUL', 'w']
			];

			$pipes = [];
			$options = [
				'bypass_shell' => true
			];

			$process = @proc_open($prozessArgumente, $descriptors, $pipes, null, null, $options);
			if (!is_resource($process)) {
				return false;
			}

			foreach ($pipes as $pipe) {
				if (is_resource($pipe)) {
					fclose($pipe);
				}
			}

			return true;
		}

		$cmd = $this->baueUltraShellBefehl($kommando, $status, $zusaetzlicheArgumente);
		if ($cmd === '') {
			return false;
		}

		@exec($cmd . ' > /dev/null 2>&1 &');
		return true;
	}

	/**
	 * pruefeUltraProgrammVerwendbarkeitOhneLogdatei
	 *
	 * Prüft, ob das Ultra-Programm unabhängig von der Logdatei verwendet werden kann.
	 * - Validiert Programmpfad, Lesbarkeit und Ausführbarkeit
	 * - Wird für Kommandos ohne Dateizugriff verwendet
	 *
	 * Parameter: keine
	 * Rückgabewert: array
	 */
    private function pruefeUltraProgrammVerwendbarkeitOhneLogdatei(): array
    {
        $programmPfad = $this->ermittleUltraProgrammPfad();
        if ($programmPfad === '') {
            return [
                'ok'            => false,
                'fehlermeldung' => 'Für den Ultra-Modus ist kein Programmpfad konfiguriert.'
            ];
        }

        if (!file_exists($programmPfad) || !is_file($programmPfad)) {
            return [
                'ok'            => false,
                'fehlermeldung' => 'Das konfigurierte Ultra-Programm wurde nicht gefunden: ' . $programmPfad
            ];
        }

        if (!is_readable($programmPfad)) {
            return [
                'ok'            => false,
                'fehlermeldung' => 'Auf das konfigurierte Ultra-Programm kann nicht gelesen werden: ' . $programmPfad
            ];
        }

        if (strncasecmp(PHP_OS, 'WIN', 3) !== 0 && !is_executable($programmPfad)) {
            return [
                'ok'            => false,
                'fehlermeldung' => 'Das konfigurierte Ultra-Programm ist nicht ausführbar: ' . $programmPfad
            ];
        }

        return [
            'ok'            => true,
            'fehlermeldung' => ''
        ];
    }

	/**
	 * quoteWindowsArgument
	 *
	 * Quotiert ein Argument für Windows-Kommandozeilen.
	 * - Escaped doppelte Anführungszeichen
	 * - Umschließt den Wert mit Quotes
	 *
	 * Parameter: string $value
	 * Rückgabewert: string
	 */
	private function quoteWindowsArgument(string $value): string
	{
		return '"' . str_replace('"', '""', $value) . '"';
	}

	/**
	 * baueUltraShellBefehl
	 *
	 * Baut den vollständigen Shell-Befehl für ein Ultra-CLI-Kommando.
	 * - Fügt Programm, Logdatei, Filter und Zusatzargumente zusammen
	 * - Verwendet OS-spezifisches Quoting für Windows oder Unix
	 *
	 * Parameter: string $kommando, array $status, array $zusaetzlicheArgumente
	 * Rückgabewert: string
	 */
	private function baueUltraShellBefehl(string $kommando, array $status = [], array $zusaetzlicheArgumente = []): string
	{
		$programmPfad = $this->ermittleUltraProgrammPfad();
		$logInfo = $this->ermittleUltraLogdateiInformation();
		if ($programmPfad === '' || !(bool) ($logInfo['ok'] ?? false)) {
			return '';
		}

		$argumente = [
			'--file'           => (string) ($logInfo['pfad'] ?? ''),
			'--multiline-mode' => 'on'
		];

		foreach ($this->baueUltraFilterArgumente($status) as $key => $value) {
			$argumente[$key] = $value;
		}
		foreach ($zusaetzlicheArgumente as $key => $value) {
			$argumente[$key] = $value;
		}

		// Windows-kompatibles Quoting
		if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
			$teile = [$this->quoteWindowsArgument($programmPfad), $kommando];
			foreach ($argumente as $key => $value) {
				if ($value === null || $value === false || $value === '') {
					continue;
				}
				$teile[] = $key;
				if ($value !== true) {
					$teile[] = $this->quoteWindowsArgument((string) $value);
				}
			}
			return implode(' ', $teile);
		}

		// Linux / Unix
		$teile = [escapeshellarg($programmPfad), escapeshellarg($kommando)];
		foreach ($argumente as $key => $value) {
			if ($value === null || $value === false || $value === '') {
				continue;
			}
			$teile[] = $key;
			if ($value !== true) {
				$teile[] = escapeshellarg((string) $value);
			}
		}

		return implode(' ', $teile);
	}

	/**
	 * baueUltraFilterArgumente
	 *
	 * Mappt Modulfilter auf Ultra-CLI-Argumente.
	 * - Konvertiert Typ-, Sender-, Objekt-ID- und Textfilter
	 * - Liefert Schlüssel/Wert-Paare für CLI-Aufruf
	 *
	 * Parameter: array $status
	 * Rückgabewert: array
	 */
    private function baueUltraFilterArgumente(array $status): array
    {
        $argumente = [];

        $filterTypen = $this->normalisiereFilterTypen($status['filterTypen'] ?? []);
        $senderFilter = $this->normalisiereSenderFilter($status['senderFilter'] ?? []);
        $objektIds = $this->normalisiereObjektIdFilterListe((string) ($status['objektIdFilter'] ?? ''));
        $textFilter = trim((string) ($status['textFilter'] ?? ''));

        if (count($filterTypen) > 0) {
            $argumente['--filter-type'] = implode(',', $filterTypen);
        }
        if (count($senderFilter) > 0) {
            $argumente['--filter-sender'] = implode(',', $senderFilter);
        }
        if (count($objektIds) > 0) {
            $argumente['--filter-object-id'] = implode(',', $objektIds);
        }
        if ($textFilter !== '') {
            $argumente['--filter-text'] = $textFilter;
        }

        return $argumente;
    }

	/**
	 * normalisiereUltraAnzeigeZeile
	 *
	 * Normalisiert eine Ultra-CLI-Zeile auf das UI-Format.
	 * - Vereinheitlicht Feldnamen (Zeit, Typ, Sender, etc.)
	 * - Extrahiert Uhrzeit aus Zeitstempel
	 *
	 * Parameter: array $zeile
	 * Rückgabewert: array
	 */
    private function normalisiereUltraAnzeigeZeile(array $zeile): array
    {
        $zeitstempel = (string) ($zeile['zeitstempel'] ?? $zeile['timestamp'] ?? $zeile['time'] ?? '');
        preg_match('/(\d{2}:\d{2}:\d{2})/', $zeitstempel, $treffer);
        $uhrzeit = $treffer[1] ?? '';

        return [
            'zeitstempel' => $zeitstempel,
            'zeit'        => $uhrzeit !== '' ? $uhrzeit : (string) ($zeile['zeit'] ?? ''),
            'objektId'    => (string) ($zeile['objektId'] ?? $zeile['objectId'] ?? ''),
            'typ'         => (string) ($zeile['typ'] ?? $zeile['type'] ?? ''),
            'sender'      => (string) ($zeile['sender'] ?? ''),
            'meldung'     => (string) ($zeile['meldung'] ?? $zeile['message'] ?? '')
        ];
    }

	/**
	 * normalisiereUltraFacetListe
	 *
	 * Normalisiert eine Facet-Liste der Ultra-CLI.
	 * - Extrahiert Werte aus String- oder Array-Einträgen
	 * - Entfernt Duplikate und sortiert alphabetisch
	 *
	 * Parameter: array $roh
	 * Rückgabewert: array
	 */
    private function normalisiereUltraFacetListe(array $roh): array
    {
        $werte = [];

        foreach ($roh as $eintrag) {
            if (is_string($eintrag)) {
                $wert = trim($eintrag);
                if ($wert !== '') {
                    $werte[$wert] = true;
                }
                continue;
            }

            if (!is_array($eintrag)) {
                continue;
            }

            $wert = (string) ($eintrag['value'] ?? $eintrag['name'] ?? $eintrag['key'] ?? $eintrag['label'] ?? '');
            $wert = trim($wert);
            if ($wert !== '') {
                $werte[$wert] = true;
            }
        }

        $werte = array_keys($werte);
        sort($werte, SORT_NATURAL | SORT_FLAG_CASE);

        return $werte;
    }

	/**
	 * istUltraAnalysebasisNochNichtVerfuegbar
	 *
	 * Prüft, ob die Ultra-Analysebasis noch nicht vorhanden ist.
	 * - Erkennt typische Fehlermeldungen des Ultra-Backends
	 *
	 * Parameter: array $antwort
	 * Rückgabewert: bool
	 */
	private function istUltraAnalysebasisNochNichtVerfuegbar(array $antwort): bool
	{
		$meldung = mb_strtolower(trim((string) ($antwort['fehlermeldung'] ?? '')), 'UTF-8');
		if ($meldung === '') {
			return false;
		}

		return str_contains($meldung, 'keine passende analysebasis vorhanden')
			|| str_contains($meldung, 'bitte zuerst build-index ausführen')
			|| str_contains($meldung, 'analysebasis')
			|| str_contains($meldung, 'build-index');
	}

	/**
	 * istWindowsHardlink
	 *
	 * Prüft, ob eine Datei unter Windows ein Hardlink ist.
	 * - Verwendet PowerShell zur Erkennung
	 *
	 * Parameter: string $pfad
	 * Rückgabewert: bool
	 */
	/**  Fataler Bremsklotz über PowerShell !!!
	private function istWindowsHardlink(string $pfad): bool
	{
		if (PHP_OS_FAMILY !== 'Windows') {
			return false;
		}

		$cmd = 'powershell -NoProfile -Command "(Get-Item \"' . $pfad . '\").LinkType"';

		@exec($cmd, $output, $code);

		if ($code !== 0 || empty($output)) {
			return false;
		}

		return stripos(implode("\n", $output), 'HardLink') !== false;
	}
	 */

	/**
	 * istWindowsHardlink
	 *
	 * Prüft, ob eine Datei unter Windows ein Hardlink ist.
	 * - Verwendet PowerShell zur Erkennung
	 *
	 * Parameter: string $pfad
	 * Rückgabewert: bool
	 */
	private function istWindowsHardlink(string $pfad): bool
	{
		if (PHP_OS_FAMILY !== 'Windows') {
			return false;
		}

		if ($pfad === '' || !is_file($pfad)) {
			return false;
		}

		$stat = @stat($pfad);
		if ($stat === false) {
			return false;
		}

		return ((int) ($stat['nlink'] ?? 1)) > 1;
	}

}
