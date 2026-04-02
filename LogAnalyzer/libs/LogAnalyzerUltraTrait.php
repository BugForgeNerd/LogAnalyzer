<?php
trait LogAnalyzerUltraTrait
{
    /**
     * Ermittelt und validiert die im Ultra-Modus zu verwendende Logdatei.
     */
    private function ermittleUltraLogdateiInformation(): array
    {
        $konfigurierterPfad = trim($this->ReadPropertyString('LogDatei'));

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
     * Shortcut für den finalen Ultra-Logdateipfad.
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
     * Liefert den konfigurierten Programmpfad.
     */
    private function ermittleUltraProgrammPfad(): string
    {
        return trim($this->ReadPropertyString('UltraProgrammPfad'));
    }

    /**
     * Prüft, ob Ultra grundsätzlich verwendet werden kann.
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
     * Löscht Index und Query-Cache bewusst neu.
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
     * Lädt eine Seite aus der Ultra-CLI.
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
                '--offset' => (string) $offset,
                '--limit'  => (string) $maxZeilen
            ]
        );

        if (!(bool) ($antwort['ok'] ?? false)) {
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

        return [
            'ok'            => true,
            'fehlermeldung' => '',
            'zeilen'        => $zeilen,
            'hatWeitere'    => $hatWeitere,
            'trefferGesamt' => $trefferGesamt
        ];
    }

    /**
     * Zählt Treffer über die Ultra-CLI.
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
     * Ermittelt Filterwerte über die Ultra-CLI.
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
			'facets',
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

/**		// Ohne Schnittmengen
		if (is_array($antwort['verfuegbareFilterTypen'] ?? null)) {
			$typen = array_values($antwort['verfuegbareFilterTypen']);
		} elseif (is_array($antwort['facets']['typ'] ?? null)) {
			foreach ($antwort['facets']['typ'] as $eintrag) {
				$wert = trim((string) ($eintrag['wert'] ?? ''));
				if ($wert !== '') {
					$typen[] = $wert;
				}
			}
		}

		if (is_array($antwort['verfuegbareSender'] ?? null)) {
			$sender = array_values($antwort['verfuegbareSender']);
		} elseif (is_array($antwort['facets']['sender'] ?? null)) {
			foreach ($antwort['facets']['sender'] as $eintrag) {
				$wert = trim((string) ($eintrag['wert'] ?? ''));
				if ($wert !== '') {
					$sender[] = $wert;
				}
			}
		}
*/

		// mit Schnittmengen Anfang
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
		// mit Schnittmengen Ende



		$typen = array_values(array_unique($typen));
		$sender = array_values(array_unique($sender));

		sort($typen, SORT_NATURAL | SORT_FLAG_CASE);
		sort($sender, SORT_NATURAL | SORT_FLAG_CASE);

		$gesamtZeilen = -1;
		if (array_key_exists('recordsImIndex', $ultraStatus)) {
			$gesamtZeilen = (int) $ultraStatus['recordsImIndex'];
		}

		return [
			'verfuegbareFilterTypen' => $typen,
			'verfuegbareSender'      => $sender,
			'gesamtZeilen'           => $gesamtZeilen,
			'cachebar'               => true
		];
	}

    /**
     * Prüft den aktuellen Build-Status und schreibt ihn in den Modulstatus.
     */
    private function aktualisiereUltraBuildStatus(): void
    {
        $pruefung = $this->pruefeUltraProgrammVerwendbarkeit();
        if (!(bool) ($pruefung['ok'] ?? false)) {
            return;
        }

        $antwort = $this->holeUltraStatusInformation();
        $status = $this->leseStatus();

        if ((bool) ($antwort['bereitFuerFilter'] ?? false)) {
            $status['ultraBuildLaeuft'] = false;
            $status['ultraBuildDatei'] = '';
            $status['ultraIndexBereit'] = true;
            $this->schreibeStatus($status);
            return;
        }

        if ((bool) ($status['ultraBuildLaeuft'] ?? false)) {
            $status['ultraIndexBereit'] = false;
            $this->schreibeStatus($status);
        }
    }

    /**
     * Liest den Ultra-Status aus.
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
     * Startet den Index-Build nur wenn nötig.
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
            $this->schreibeStatus($status);
            return;
        }

        $status = $this->leseStatus();
        if (
            (bool) ($status['ultraBuildLaeuft'] ?? false) &&
            (string) ($status['ultraBuildDatei'] ?? '') === $logDatei
        ) {
            return;
        }

        if ($this->starteUltraJsonKommandoImHintergrund('build-index', [], ['--multiline-mode' => 'on'])) {
            $status['ultraBuildLaeuft'] = true;
            $status['ultraBuildDatei'] = $logDatei;
            $status['ultraIndexBereit'] = false;
            $this->schreibeStatus($status);
        }
    }

    /**
     * Führt ein CLI-Kommando synchron aus und erwartet JSON auf stdout.
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

        $cmd = $this->baueUltraShellBefehl($kommando, $status, $zusaetzlicheArgumente);
        if ($cmd === '') {
            return [
                'ok'            => false,
                'fehlermeldung' => 'Ultra-Befehl konnte nicht erstellt werden.'
            ];
        }

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $pipes = [];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return [
                'ok'            => false,
                'fehlermeldung' => 'Ultra-Prozess konnte nicht gestartet werden.'
            ];
        }

        $stdout = '';
        $stderr = '';
        try {
            $stdout = isset($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '';
            $stderr = isset($pipes[2]) ? (string) stream_get_contents($pipes[2]) : '';
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }

        $exitCode = proc_close($process);
        if (trim($stdout) === '') {
            return [
                'ok'            => false,
                'fehlermeldung' => trim($stderr) !== '' ? trim($stderr) : 'Ultra-Programm lieferte keine JSON-Ausgabe. ExitCode=' . $exitCode
            ];
        }

        try {
            $daten = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
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
        $daten['fehlermeldung'] = (string) ($daten['fehlermeldung'] ?? '');
        if (!(bool) $daten['ok'] && $daten['fehlermeldung'] === '') {
            $daten['fehlermeldung'] = trim($stderr) !== '' ? trim($stderr) : 'Ultra-Kommando fehlgeschlagen. ExitCode=' . $exitCode;
        }

        return $daten;
    }

    /**
     * Startet ein CLI-Kommando im Hintergrund.
     */
    private function starteUltraJsonKommandoImHintergrund(string $kommando, array $status = [], array $zusaetzlicheArgumente = []): bool
    {
        $pruefung = $this->pruefeUltraProgrammVerwendbarkeitOhneLogdatei();
        if (!(bool) ($pruefung['ok'] ?? false)) {
            return false;
        }

        $cmd = $this->baueUltraShellBefehl($kommando, $status, $zusaetzlicheArgumente);
        if ($cmd === '') {
            return false;
        }

        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            @pclose(@popen('start "" /B ' . $cmd . ' >NUL 2>&1', 'r'));
            return true;
        }

        @exec($cmd . ' > /dev/null 2>&1 &');
        return true;
    }

    /**
     * Validiert nur das Programm, nicht die Logdatei.
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
     * Baut den finalen Shell-Befehl.
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
     * Mappt Modulfilter auf CLI-Argumente.
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
     * Normalisiert Datensätze der Ultra-CLI auf das UI-Format.
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
     * Normalisiert eine Facet-Liste auf reine Werte.
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
}
