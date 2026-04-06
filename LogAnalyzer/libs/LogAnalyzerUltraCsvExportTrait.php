<?php

declare(strict_types=1);

trait LogAnalyzerUltraCsvExportTrait
{
	/**
	 * leseCsvExportVerwaltung
	 *
	 * Liest die CSV-Exportverwaltung aus dem Modul-Attribut.
	 * - Normalisiert die gespeicherten Export-Einträge
	 * - Liefert aktive Token-Information und Exportliste zurück
	 *
	 * Parameter: keine
	 * Rückgabewert: array
	 */
    private function leseCsvExportVerwaltung(): array
    {
        if (!defined('self::ATTR_EXPORTE')) {
            return [
                'activeToken' => '',
                'exports'     => []
            ];
        }

        $roh = $this->ReadAttributeString(self::ATTR_EXPORTE);
        $daten = $this->dekodiereJsonArray($roh);

        $exports = [];
        foreach ((array) ($daten['exports'] ?? []) as $eintrag) {
            if (!is_array($eintrag)) {
                continue;
            }

            $token = trim((string) ($eintrag['token'] ?? ''));
            if ($token === '') {
                continue;
            }

            $exports[$token] = [
                'token'              => $token,
                'datei'              => trim((string) ($eintrag['datei'] ?? '')),
                'dateiname'          => trim((string) ($eintrag['dateiname'] ?? '')),
                'modus'              => trim((string) ($eintrag['modus'] ?? 'all')),
                'scope'              => trim((string) ($eintrag['scope'] ?? 'all')),
                'erstelltAm'         => (int) ($eintrag['erstelltAm'] ?? 0),
                'laeuftAbAm'         => (int) ($eintrag['laeuftAbAm'] ?? 0),
                'letzterZugriffAm'   => (int) ($eintrag['letzterZugriffAm'] ?? 0),
                'dateigroesseBytes'  => (int) ($eintrag['dateigroesseBytes'] ?? 0),
                'exportierteZeilen'  => (int) ($eintrag['exportierteZeilen'] ?? -1),
                'status'             => trim((string) ($eintrag['status'] ?? 'ready')),
                'fehlermeldung'      => trim((string) ($eintrag['fehlermeldung'] ?? '')),
                'logDatei'           => trim((string) ($eintrag['logDatei'] ?? '')),
                'filterSignatur'     => trim((string) ($eintrag['filterSignatur'] ?? '')),
                'downloadUrl'        => trim((string) ($eintrag['downloadUrl'] ?? '')),
                'downloadToken'      => $token
            ];
        }

        return [
            'activeToken' => trim((string) ($daten['activeToken'] ?? '')),
            'exports'     => $exports
        ];
    }

	/**
	 * schreibeCsvExportVerwaltung
	 *
	 * Speichert die CSV-Exportverwaltung im Modul-Attribut.
	 * - Normalisiert alle Export-Einträge
	 * - Persistiert aktive Token-Information und Exportliste
	 *
	 * Parameter: array $verwaltung
	 * Rückgabewert: void
	 */
    private function schreibeCsvExportVerwaltung(array $verwaltung): void
    {
        $exports = [];
        foreach ((array) ($verwaltung['exports'] ?? []) as $token => $eintrag) {
            if (!is_array($eintrag)) {
                continue;
            }

            $finalToken = trim((string) ($eintrag['token'] ?? $token));
            if ($finalToken === '') {
                continue;
            }

            $exports[$finalToken] = [
                'token'             => $finalToken,
                'datei'             => trim((string) ($eintrag['datei'] ?? '')),
                'dateiname'         => trim((string) ($eintrag['dateiname'] ?? '')),
                'modus'             => trim((string) ($eintrag['modus'] ?? 'all')),
                'scope'             => trim((string) ($eintrag['scope'] ?? 'all')),
                'erstelltAm'        => (int) ($eintrag['erstelltAm'] ?? 0),
                'laeuftAbAm'        => (int) ($eintrag['laeuftAbAm'] ?? 0),
                'letzterZugriffAm'  => (int) ($eintrag['letzterZugriffAm'] ?? 0),
                'dateigroesseBytes' => (int) ($eintrag['dateigroesseBytes'] ?? 0),
                'exportierteZeilen' => (int) ($eintrag['exportierteZeilen'] ?? -1),
                'status'            => trim((string) ($eintrag['status'] ?? 'ready')),
                'fehlermeldung'     => trim((string) ($eintrag['fehlermeldung'] ?? '')),
                'logDatei'          => trim((string) ($eintrag['logDatei'] ?? '')),
                'filterSignatur'    => trim((string) ($eintrag['filterSignatur'] ?? '')),
                'downloadUrl'       => trim((string) ($eintrag['downloadUrl'] ?? ''))
            ];
        }

        $this->WriteAttributeString(
            self::ATTR_EXPORTE,
            json_encode([
                'activeToken' => trim((string) ($verwaltung['activeToken'] ?? '')),
                'exports'     => $exports
            ], JSON_THROW_ON_ERROR)
        );
    }

	/**
	 * initialisiereCsvExportStatusFelder
	 *
	 * Initialisiert CSV-Export-Statusfelder im Statusarray.
	 * - Setzt Standardwerte für Exportzustand und Metadaten
	 * - Normalisiert Datentypen der Statuswerte
	 *
	 * Parameter: array $status
	 * Rückgabewert: array
	 */
    private function initialisiereCsvExportStatusFelder(array $status): array
    {
        $status['csvExportLaeuft'] = (bool) ($status['csvExportLaeuft'] ?? false);
        $status['csvExportBereit'] = (bool) ($status['csvExportBereit'] ?? false);
        $status['csvExportFehlermeldung'] = trim((string) ($status['csvExportFehlermeldung'] ?? ''));
        $status['csvExportToken'] = trim((string) ($status['csvExportToken'] ?? ''));
        $status['csvExportScope'] = trim((string) ($status['csvExportScope'] ?? 'all'));
        $status['csvExportDatei'] = trim((string) ($status['csvExportDatei'] ?? ''));
        $status['csvExportDateiname'] = trim((string) ($status['csvExportDateiname'] ?? ''));
        $status['csvExportDateigroesseBytes'] = max(0, (int) ($status['csvExportDateigroesseBytes'] ?? 0));
        $status['csvExportExportierteZeilen'] = (int) ($status['csvExportExportierteZeilen'] ?? -1);
        $status['csvExportLaeuftAbAm'] = (int) ($status['csvExportLaeuftAbAm'] ?? 0);
        $status['csvExportDownloadUrl'] = trim((string) ($status['csvExportDownloadUrl'] ?? ''));

        return $status;
    }

	/**
	 * bereinigeAbgelaufeneCsvExporte
	 *
	 * Entfernt abgelaufene oder ungültige CSV-Exporte.
	 * - Löscht Dateien vom Dateisystem
	 * - Aktualisiert Verwaltung und Statusdaten
	 *
	 * Parameter: keine
	 * Rückgabewert: void
	 */
    private function bereinigeAbgelaufeneCsvExporte(): void
    {
        $verwaltung = $this->leseCsvExportVerwaltung();
        $exports = (array) ($verwaltung['exports'] ?? []);
        $activeToken = trim((string) ($verwaltung['activeToken'] ?? ''));
        $jetzt = time();
        $geaendert = false;

        foreach ($exports as $token => $eintrag) {
            $datei = trim((string) ($eintrag['datei'] ?? ''));
            $laeuftAbAm = (int) ($eintrag['laeuftAbAm'] ?? 0);
            $status = trim((string) ($eintrag['status'] ?? 'ready'));

            $istAbgelaufen = $laeuftAbAm > 0 && $laeuftAbAm <= $jetzt;
            $dateiFehlt = ($datei === '') || !is_file($datei);
            $darfEntferntWerden = $istAbgelaufen || ($status !== 'building' && $dateiFehlt);

            if (!$darfEntferntWerden) {
                continue;
            }

            if ($datei !== '' && is_file($datei)) {
                @unlink($datei);
            }

            unset($exports[$token]);
            if ($activeToken === $token) {
                $activeToken = '';
            }
            $geaendert = true;
        }

        if (!$geaendert) {
            return;
        }

        $verwaltung['activeToken'] = $activeToken;
        $verwaltung['exports'] = $exports;
        $this->schreibeCsvExportVerwaltung($verwaltung);

        $status = $this->initialisiereCsvExportStatusFelder($this->leseStatus());
        if ($activeToken === '') {
            $status['csvExportBereit'] = false;
            $status['csvExportToken'] = '';
            $status['csvExportScope'] = 'all';
            $status['csvExportDatei'] = '';
            $status['csvExportDateiname'] = '';
            $status['csvExportDateigroesseBytes'] = 0;
            $status['csvExportExportierteZeilen'] = -1;
            $status['csvExportLaeuftAbAm'] = 0;
            $status['csvExportDownloadUrl'] = '';
            if (!$status['csvExportLaeuft']) {
                $status['csvExportFehlermeldung'] = '';
            }
            $this->schreibeStatus($status);
        }
    }

	/**
	 * ermittleMaximaleCsvExporteProInstanz
	 *
	 * Liefert die maximale Anzahl gespeicherter CSV-Exporte pro Instanz.
	 *
	 * Parameter: keine
	 * Rückgabewert: int
	 */
	private function ermittleMaximaleCsvExporteProInstanz(): int
	{
		return 5;
	}

	/**
	 * holeGueltigeCsvExporteFuerAnzeige
	 *
	 * Liefert gültige CSV-Exporte für die Visualisierung.
	 * - Entfernt abgelaufene Einträge
	 * - Bereitet Anzeigeinformationen auf
	 *
	 * Parameter: keine
	 * Rückgabewert: array
	 */
	private function holeGueltigeCsvExporteFuerAnzeige(): array
	{
		$this->bereinigeAbgelaufeneCsvExporte();

		$verwaltung = $this->leseCsvExportVerwaltung();
		$exports = (array) ($verwaltung['exports'] ?? []);
		$logDateiAktuell = (string) $this->leseAktiveLogDatei();

		$liste = [];

		foreach ($exports as $token => $eintrag) {
			if (!is_array($eintrag)) {
				continue;
			}

			$datei = trim((string) ($eintrag['datei'] ?? ''));
			if ($datei === '' || !is_file($datei)) {
				continue;
			}

			$laeuftAbAm = (int) ($eintrag['laeuftAbAm'] ?? 0);
			if ($laeuftAbAm > 0 && $laeuftAbAm <= time()) {
				continue;
			}

			$scope = trim((string) ($eintrag['scope'] ?? 'all'));
			$dateiname = trim((string) ($eintrag['dateiname'] ?? basename($datei)));
			$downloadUrl = trim((string) ($eintrag['downloadUrl'] ?? ''));
			$bytes = (int) ($eintrag['dateigroesseBytes'] ?? filesize($datei));
			$exportierteZeilen = (int) ($eintrag['exportierteZeilen'] ?? -1);
			$logDatei = trim((string) ($eintrag['logDatei'] ?? ''));

			$liste[] = [
				'token'                    => (string) $token,
				'scope'                    => $scope,
				'scopeText'                => $scope === 'page' ? 'Aktuelle Seite' : 'Gesamtes Filterergebnis',
				'dateiname'                => $dateiname,
				'dateiPfad'                => $datei,
				'dateigroesseBytes'        => $bytes,
				'dateigroesseFormatiert'   => $bytes > 0 ? $this->formatiereDateigroesse($bytes) : '',
				'downloadUrl'              => $downloadUrl,
				'laeuftAbAm'               => $laeuftAbAm,
				'laeuftAbText'             => $laeuftAbAm > 0 ? date('d.m.Y H:i:s', $laeuftAbAm) : '',
				'erstelltAm'               => (int) ($eintrag['erstelltAm'] ?? 0),
				'erstelltAmText'           => ((int) ($eintrag['erstelltAm'] ?? 0)) > 0 ? date('d.m.Y H:i:s', (int) $eintrag['erstelltAm']) : '',
				'exportierteZeilen'        => $exportierteZeilen,
				'istAktuelleLogDatei'      => ($logDatei !== '' && $logDatei === $logDateiAktuell),
				'logDatei'                 => $logDatei
			];
		}

		usort($liste, static function (array $a, array $b): int {
			return ((int) ($b['erstelltAm'] ?? 0)) <=> ((int) ($a['erstelltAm'] ?? 0));
		});

		return $liste;
	}

	/**
	 * begrenzeCsvExportAnzahl
	 *
	 * Begrenzt die Anzahl gespeicherter CSV-Exporte.
	 * - Entfernt die ältesten Einträge
	 * - Löscht zugehörige Dateien
	 *
	 * Parameter: keine
	 * Rückgabewert: void
	 */
	private function begrenzeCsvExportAnzahl(): void
	{
		$verwaltung = $this->leseCsvExportVerwaltung();
		$exports = (array) ($verwaltung['exports'] ?? []);
		$maximal = max(1, $this->ermittleMaximaleCsvExporteProInstanz());

		if (count($exports) <= $maximal) {
			return;
		}

		uasort($exports, static function (array $a, array $b): int {
			return ((int) ($a['erstelltAm'] ?? 0)) <=> ((int) ($b['erstelltAm'] ?? 0));
		});

		while (count($exports) > $maximal) {
			$token = (string) array_key_first($exports);
			if ($token === '') {
				break;
			}

			$eintrag = (array) ($exports[$token] ?? []);
			$datei = trim((string) ($eintrag['datei'] ?? ''));
			if ($datei !== '' && is_file($datei)) {
				@unlink($datei);
			}

			unset($exports[$token]);

			if (trim((string) ($verwaltung['activeToken'] ?? '')) === $token) {
				$verwaltung['activeToken'] = '';
			}
		}

		$verwaltung['exports'] = $exports;
		$this->schreibeCsvExportVerwaltung($verwaltung);
	}

	/**
	 * starteCsvExportUltra
	 *
	 * Startet einen CSV-Export im Ultra-Modus.
	 * - Prüft Voraussetzungen und Build-Status
	 * - Führt Export aus und registriert Ergebnis
	 *
	 * Parameter: string $scope
	 * Rückgabewert: array
	 */
	private function starteCsvExportUltra(string $scope = 'all'): array
	{
		$scope = ($scope === 'page') ? 'page' : 'all';

		$status = $this->initialisiereCsvExportStatusFelder($this->leseStatus());
		$status['csvExportLaeuft'] = true;
		$status['csvExportBereit'] = false;
		$status['csvExportFehlermeldung'] = '';
		$status['csvExportScope'] = $scope;
		$this->schreibeStatus($status);

		$pruefung = $this->pruefeModusVerwendbarkeit();
		if (!(bool) ($pruefung['ok'] ?? false)) {
			return $this->beendeCsvExportMitFehler((string) ($pruefung['fehlermeldung'] ?? 'Modus nicht verfügbar.'));
		}

		if ($this->ermittleAktivenModus() !== 'ultra') {
			return $this->beendeCsvExportMitFehler('CSV-Export ist nur im Ultra-Modus verfügbar.');
		}

		$this->aktualisiereUltraBuildStatus();
		$ultraStatus = $this->holeUltraStatusInformation();
		if (!(bool) ($ultraStatus['bereitFuerFilter'] ?? false)) {
			$this->starteUltraBuildFallsNoetig();
			return $this->beendeCsvExportMitFehler('Ultra-Index ist noch nicht bereit. Bitte Export nach abgeschlossenem Build erneut starten.');
		}

		$this->bereinigeAbgelaufeneCsvExporte();

		$exportVerzeichnis = $this->ermittleCsvExportVerzeichnis();
		if ($exportVerzeichnis === '') {
			return $this->beendeCsvExportMitFehler('Exportverzeichnis konnte nicht erstellt werden.');
		}

		$token = $this->erzeugeCsvExportToken();
		$dateiname = $this->erzeugeCsvExportDateiname($scope, $token);
		$datei = $exportVerzeichnis . DIRECTORY_SEPARATOR . $dateiname;
		$downloadUrl = '/hook/loganalyzer/' . $this->InstanceID . '/download?token=' . rawurlencode($token);

		$statusSnapshot = $this->leseStatus();
		$antwort = $this->fuehreUltraCsvExportAus($statusSnapshot, $scope, $datei);
		if (!(bool) ($antwort['ok'] ?? false)) {
			return $this->beendeCsvExportMitFehler((string) ($antwort['fehlermeldung'] ?? 'CSV-Export fehlgeschlagen.'));
		}

		if (!is_file($datei)) {
			return $this->beendeCsvExportMitFehler('CSV-Export meldete Erfolg, die Datei wurde jedoch nicht gefunden.');
		}

		$eintrag = [
			'token'             => $token,
			'datei'             => $datei,
			'dateiname'         => $dateiname,
			'modus'             => $scope,
			'scope'             => $scope,
			'erstelltAm'        => time(),
			'laeuftAbAm'        => time() + $this->ermittleCsvExportTtlSekunden(),
			'letzterZugriffAm'  => 0,
			'dateigroesseBytes' => (int) filesize($datei),
			'exportierteZeilen' => (int) ($antwort['exportierteZeilen'] ?? -1),
			'status'            => 'ready',
			'fehlermeldung'     => '',
			'logDatei'          => (string) $this->leseAktiveLogDatei(),
			'filterSignatur'    => $this->ermittleCsvExportFilterSignatur($statusSnapshot, $scope),
			'downloadUrl'       => $downloadUrl
		];

		$this->registriereCsvExportEintrag($eintrag);
		$this->begrenzeCsvExportAnzahl();

		$status = $this->initialisiereCsvExportStatusFelder($this->leseStatus());
		$status['csvExportLaeuft'] = false;
		$status['csvExportBereit'] = true;
		$status['csvExportFehlermeldung'] = '';
		$status['csvExportToken'] = $token;
		$status['csvExportScope'] = $scope;
		$status['csvExportDatei'] = $datei;
		$status['csvExportDateiname'] = $dateiname;
		$status['csvExportDateigroesseBytes'] = (int) filesize($datei);
		$status['csvExportExportierteZeilen'] = (int) ($antwort['exportierteZeilen'] ?? -1);
		$status['csvExportLaeuftAbAm'] = (int) ($eintrag['laeuftAbAm'] ?? 0);
		$status['csvExportDownloadUrl'] = $downloadUrl;
		$this->schreibeStatus($status);

		$this->SendDebug(
			'CsvExport',
			sprintf(
				'status=fertig scope=%s datei=%s bytes=%d zeilen=%d exportsSichtbar=%d',
				$scope,
				basename($dateiname),
				(int) filesize($datei),
				(int) ($antwort['exportierteZeilen'] ?? -1),
				count($this->holeGueltigeCsvExporteFuerAnzeige())
			),
			0
		);

		return [
			'ok'                => true,
			'fehlermeldung'     => '',
			'token'             => $token,
			'datei'             => $datei,
			'dateiname'         => $dateiname,
			'dateigroesseBytes' => (int) filesize($datei),
			'exportierteZeilen' => (int) ($antwort['exportierteZeilen'] ?? -1)
		];
	}

	/**
	 * beendeCsvExportMitFehler
	 *
	 * Beendet den CSV-Export mit Fehlerstatus.
	 * - Setzt Statusfelder zurück
	 * - Protokolliert Fehlermeldung
	 *
	 * Parameter: string $fehlermeldung
	 * Rückgabewert: array
	 */
	private function beendeCsvExportMitFehler(string $fehlermeldung): array
    {
        $status = $this->initialisiereCsvExportStatusFelder($this->leseStatus());
        $status['csvExportLaeuft'] = false;
        $status['csvExportBereit'] = false;
        $status['csvExportFehlermeldung'] = trim($fehlermeldung);
        $status['csvExportToken'] = '';
        $status['csvExportDatei'] = '';
        $status['csvExportDateiname'] = '';
        $status['csvExportDateigroesseBytes'] = 0;
        $status['csvExportExportierteZeilen'] = -1;
        $status['csvExportLaeuftAbAm'] = 0;
        $status['csvExportDownloadUrl'] = '';
        $this->schreibeStatus($status);

        $this->SendDebug('CsvExport', 'status=fehler meldung=' . $fehlermeldung, 0);

        return [
            'ok'            => false,
            'fehlermeldung' => trim($fehlermeldung)
        ];
    }

	/**
	 * fuehreUltraCsvExportAus
	 *
	 * Führt den eigentlichen Ultra CSV-Export aus.
	 * - Übergibt Parameter an das Ultra-Backend
	 * - Normalisiert die Rückgabedaten
	 *
	 * Parameter: array $status, string $scope, string $zielDatei
	 * Rückgabewert: array
	 */
    private function fuehreUltraCsvExportAus(array $status, string $scope, string $zielDatei): array
    {
        $zusaetzlicheArgumente = $this->baueUltraCsvExportZusatzargumente($status, $scope, $zielDatei);
        $antwort = $this->fuehreUltraJsonKommandoAus('export-csv', $status, $zusaetzlicheArgumente);

        if (!(bool) ($antwort['ok'] ?? false)) {
            return [
                'ok'            => false,
                'fehlermeldung' => (string) ($antwort['fehlermeldung'] ?? 'Ultra-Export fehlgeschlagen.')
            ];
        }

        $antwort['exportierteZeilen'] = (int) ($antwort['exportierteZeilen'] ?? -1);
        $antwort['csvDatei'] = (string) ($antwort['csvDatei'] ?? $zielDatei);

        return $antwort;
    }

	/**
	 * baueUltraCsvExportZusatzargumente
	 *
	 * Baut Zusatzargumente für den Ultra CSV-Export.
	 * - Setzt Offset und Limit bei Seitenexport
	 *
	 * Parameter: array $status, string $scope, string $zielDatei
	 * Rückgabewert: array
	 */
    private function baueUltraCsvExportZusatzargumente(array $status, string $scope, string $zielDatei): array
    {
        $argumente = [
            '--output' => $zielDatei
        ];

        if ($scope === 'page') {
            $maxZeilen = $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50));
            $seite = max(0, (int) ($status['seite'] ?? 0));
            $argumente['--offset'] = (string) ($seite * $maxZeilen);
            $argumente['--limit'] = (string) $maxZeilen;
        }

        return $argumente;
    }

	/**
	 * ermittleCsvExportVerzeichnis
	 *
	 * Ermittelt das Verzeichnis für CSV-Exportdateien.
	 * - Erstellt das Verzeichnis bei Bedarf
	 *
	 * Parameter: keine
	 * Rückgabewert: string
	 */
	private function ermittleCsvExportVerzeichnis(): string
	{
		$basis = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loganalyzer_exports';

		if (!is_dir($basis)) {
			@mkdir($basis, 0777, true);
		}

		return $basis;
	}

	/**
	 * ermittleCsvExportTtlSekunden
	 *
	 * Liefert die Lebensdauer eines CSV-Exports in Sekunden.
	 *
	 * Parameter: keine
	 * Rückgabewert: int
	 */
    private function ermittleCsvExportTtlSekunden(): int
    {
        return 3600;
    }

	/**
	 * erzeugeCsvExportToken
	 *
	 * Erzeugt ein eindeutiges Token für einen CSV-Export.
	 *
	 * Parameter: keine
	 * Rückgabewert: string
	 */
    private function erzeugeCsvExportToken(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return md5((string) microtime(true) . '-' . (string) mt_rand());
        }
    }

	/**
	 * erzeugeCsvExportDateiname
	 *
	 * Erzeugt einen Dateinamen für den CSV-Export.
	 *
	 * Parameter: string $scope, string $token
	 * Rückgabewert: string
	 */
    private function erzeugeCsvExportDateiname(string $scope, string $token): string
    {
        $scopeTeil = ($scope === 'page') ? 'seite' : 'gesamt';
        return sprintf(
            'loganalyzer_export_%d_%s_%s.csv',
            (int) $this->InstanceID,
            $scopeTeil,
            substr($token, 0, 12)
        );
    }

	/**
	 * registriereCsvExportEintrag
	 *
	 * Registriert einen CSV-Export in der Verwaltung.
	 *
	 * Parameter: array $eintrag
	 * Rückgabewert: void
	 */
    private function registriereCsvExportEintrag(array $eintrag): void
    {
        $verwaltung = $this->leseCsvExportVerwaltung();
        $token = trim((string) ($eintrag['token'] ?? ''));
        if ($token === '') {
            return;
        }

        $verwaltung['exports'][$token] = $eintrag;
        $verwaltung['activeToken'] = $token;
        $this->schreibeCsvExportVerwaltung($verwaltung);
    }

	/**
	 * holeAktivenCsvExport
	 *
	 * Liefert den aktuell aktiven CSV-Export.
	 *
	 * Parameter: keine
	 * Rückgabewert: array
	 */
    private function holeAktivenCsvExport(): array
    {
        $this->bereinigeAbgelaufeneCsvExporte();

        $verwaltung = $this->leseCsvExportVerwaltung();
        $token = trim((string) ($verwaltung['activeToken'] ?? ''));
        if ($token === '') {
            return [];
        }

        $eintrag = (array) (($verwaltung['exports'] ?? [])[$token] ?? []);
        if ($eintrag === []) {
            return [];
        }

        $datei = trim((string) ($eintrag['datei'] ?? ''));
        if ($datei === '' || !is_file($datei)) {
            return [];
        }

        $eintrag['dateigroesseBytes'] = (int) ($eintrag['dateigroesseBytes'] ?? filesize($datei));
        return $eintrag;
    }

	/**
	 * loescheCsvExportDatei
	 *
	 * Löscht eine CSV-Exportdatei anhand des Tokens.
	 * - Entfernt Datei und Verwaltungseintrag
	 * - Aktualisiert Statusinformationen
	 *
	 * Parameter: string $token
	 * Rückgabewert: bool
	 */
	private function loescheCsvExportDatei(string $token): bool
	{
		$token = trim($token);
		if ($token === '') {
			return false;
		}

		$verwaltung = $this->leseCsvExportVerwaltung();
		$eintrag = (array) (($verwaltung['exports'] ?? [])[$token] ?? []);
		if ($eintrag === []) {
			return false;
		}

		$datei = trim((string) ($eintrag['datei'] ?? ''));
		if ($datei !== '' && is_file($datei)) {
			@unlink($datei);
		}

		unset($verwaltung['exports'][$token]);
		if (trim((string) ($verwaltung['activeToken'] ?? '')) === $token) {
			$verwaltung['activeToken'] = '';
		}
		$this->schreibeCsvExportVerwaltung($verwaltung);

		$liste = $this->holeGueltigeCsvExporteFuerAnzeige();
		$status = $this->initialisiereCsvExportStatusFelder($this->leseStatus());

		if ($liste === []) {
			$status['csvExportBereit'] = false;
			$status['csvExportToken'] = '';
			$status['csvExportScope'] = 'all';
			$status['csvExportDatei'] = '';
			$status['csvExportDateiname'] = '';
			$status['csvExportDateigroesseBytes'] = 0;
			$status['csvExportExportierteZeilen'] = -1;
			$status['csvExportLaeuftAbAm'] = 0;
			$status['csvExportDownloadUrl'] = '';
		}

		$this->schreibeStatus($status);

		return true;
	}

	/**
	 * uebernehmeCsvExportInVisualisierungsDaten
	 *
	 * Überträgt CSV-Exportdaten in die Visualisierungsstruktur.
	 * - Setzt Status- und Listeninformationen
	 *
	 * Parameter: array &$daten
	 * Rückgabewert: void
	 */
	private function uebernehmeCsvExportInVisualisierungsDaten(array &$daten): void
    {
        $status = $this->initialisiereCsvExportStatusFelder((array) ($daten['status'] ?? $this->leseStatus()));
        $aktiverExport = $this->holeAktivenCsvExport();
        $exportListe = $this->holeGueltigeCsvExporteFuerAnzeige();

        $daten['csvExportUnterstuetzt'] = ($this->ermittleAktivenModus() === 'ultra');
        $daten['csvExportLaeuft'] = (bool) ($status['csvExportLaeuft'] ?? false);
        $daten['csvExportBereit'] = (bool) ($status['csvExportBereit'] ?? false);
        $daten['csvExportFehlermeldung'] = (string) ($status['csvExportFehlermeldung'] ?? '');
        $daten['csvExportToken'] = (string) ($status['csvExportToken'] ?? '');
        $daten['csvExportScope'] = (string) ($status['csvExportScope'] ?? 'all');
        $daten['csvExportDateiname'] = (string) ($status['csvExportDateiname'] ?? '');
        $daten['csvExportDateiPfad'] = (string) ($status['csvExportDatei'] ?? '');
        $daten['csvExportDateigroesseBytes'] = (int) ($status['csvExportDateigroesseBytes'] ?? 0);
        $daten['csvExportDateigroesseFormatiert'] = $daten['csvExportDateigroesseBytes'] > 0
            ? $this->formatiereDateigroesse((int) $daten['csvExportDateigroesseBytes'])
            : '';
        $daten['csvExportExportierteZeilen'] = (int) ($status['csvExportExportierteZeilen'] ?? -1);
        $daten['csvExportLaeuftAbAm'] = (int) ($status['csvExportLaeuftAbAm'] ?? 0);
        $daten['csvExportLaeuftAbText'] = ((int) ($status['csvExportLaeuftAbAm'] ?? 0)) > 0
            ? date('d.m.Y H:i:s', (int) $status['csvExportLaeuftAbAm'])
            : '';
        $daten['csvExportDownloadUrl'] = (string) ($status['csvExportDownloadUrl'] ?? '');

        if ($aktiverExport !== []) {
            $daten['csvExportBereit'] = true;
            $daten['csvExportToken'] = (string) ($aktiverExport['token'] ?? $daten['csvExportToken']);
            $daten['csvExportScope'] = (string) ($aktiverExport['scope'] ?? $daten['csvExportScope']);
            $daten['csvExportDateiname'] = (string) ($aktiverExport['dateiname'] ?? $daten['csvExportDateiname']);
            $daten['csvExportDateiPfad'] = (string) ($aktiverExport['datei'] ?? $daten['csvExportDateiPfad']);
            $daten['csvExportDateigroesseBytes'] = (int) ($aktiverExport['dateigroesseBytes'] ?? $daten['csvExportDateigroesseBytes']);
            $daten['csvExportDateigroesseFormatiert'] = $daten['csvExportDateigroesseBytes'] > 0
                ? $this->formatiereDateigroesse((int) $daten['csvExportDateigroesseBytes'])
                : '';
            $daten['csvExportExportierteZeilen'] = (int) ($aktiverExport['exportierteZeilen'] ?? $daten['csvExportExportierteZeilen']);
            $daten['csvExportLaeuftAbAm'] = (int) ($aktiverExport['laeuftAbAm'] ?? $daten['csvExportLaeuftAbAm']);
            $daten['csvExportLaeuftAbText'] = $daten['csvExportLaeuftAbAm'] > 0
                ? date('d.m.Y H:i:s', (int) $daten['csvExportLaeuftAbAm'])
                : '';
            $daten['csvExportDownloadUrl'] = (string) ($aktiverExport['downloadUrl'] ?? $daten['csvExportDownloadUrl']);
        }

        $daten['csvExportListe'] = $exportListe;
        $daten['csvExportAnzahl'] = count($exportListe);
        $daten['csvExportListeVorhanden'] = $exportListe !== [];
    }

	/**
	 * ermittleCsvExportFilterSignatur
	 *
	 * Erzeugt eine Signatur für den CSV-Export basierend auf Filtern.
	 *
	 * Parameter: array $status, string $scope
	 * Rückgabewert: string
	 */
    private function ermittleCsvExportFilterSignatur(array $status, string $scope): string
    {
        return md5(json_encode([
            'scope'           => $scope,
            'logDatei'        => (string) $this->leseAktiveLogDatei(),
            'filterTypen'     => $this->normalisiereFilterTypen($status['filterTypen'] ?? []),
            'objektIdFilter'  => $this->normalisiereObjektIdFilterString((string) ($status['objektIdFilter'] ?? '')),
            'senderFilter'    => $this->normalisiereSenderFilter($status['senderFilter'] ?? []),
            'textFilter'      => trim((string) ($status['textFilter'] ?? '')),
            'seite'           => max(0, (int) ($status['seite'] ?? 0)),
            'maxZeilen'       => $this->normalisiereMaxZeilen((int) ($status['maxZeilen'] ?? 50))
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

	/**
	 * holeCsvExportPerToken
	 *
	 * Liefert einen CSV-Export anhand seines Tokens.
	 *
	 * Parameter: string $token
	 * Rückgabewert: array
	 */
	private function holeCsvExportPerToken(string $token): array
	{
		$verwaltung = $this->leseCsvExportVerwaltung();
		$exports = is_array($verwaltung['exports'] ?? null) ? $verwaltung['exports'] : [];

		foreach ($exports as $eintrag) {
			if ((string) ($eintrag['token'] ?? '') !== $token) {
				continue;
			}

			return [
				'ok' => true,
				'datei' => (string) ($eintrag['datei'] ?? ''),
				'dateiname' => (string) ($eintrag['dateiname'] ?? ''),
				'eintrag' => $eintrag
			];
		}

		return [
			'ok' => false
		];
	}

	/**
	 * aktualisiereCsvExportLetztenDownload
	 *
	 * Aktualisiert den Zeitstempel des letzten Downloads.
	 *
	 * Parameter: string $token
	 * Rückgabewert: void
	 */
	private function aktualisiereCsvExportLetztenDownload(string $token): void
	{
		$verwaltung = $this->leseCsvExportVerwaltung();
		$exports = is_array($verwaltung['exports'] ?? null) ? $verwaltung['exports'] : [];

		foreach ($exports as &$eintrag) {
			if ((string) ($eintrag['token'] ?? '') !== $token) {
				continue;
			}

			$eintrag['letzterZugriffAm'] = time();
			break;
		}

		unset($eintrag);

		$verwaltung['exports'] = $exports;
		$this->schreibeCsvExportVerwaltung($verwaltung);
	}

}
