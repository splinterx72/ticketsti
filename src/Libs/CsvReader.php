<?php

declare(strict_types=1);

namespace App\Libs;

use Generator;
use RuntimeException;

/**
 * Lector CSV en streaming (fgetcsv) para los archivos que exporta Excel.
 *
 * - Detecta el delimitador (',', ';', tabulador o '|') en las primeras líneas.
 *   Excel en español (México) suele guardar con ';'.
 * - Detecta la codificación: UTF-8 (con o sin BOM) o ANSI de Excel
 *   (Windows-1252, superconjunto de ISO-8859-1) y convierte todo a UTF-8.
 * - Lee fila por fila: el consumo de memoria es constante sin importar el
 *   tamaño del archivo.
 */
final class CsvReader
{
    private string $delimiter = ',';

    private string $sourceEncoding = 'UTF-8';

    private bool $hasBom = false;

    private function __construct(private readonly string $filePath)
    {
    }

    public static function open(string $filePath): self
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('No se puede leer el archivo CSV.');
        }
        $reader = new self($filePath);
        $reader->detect();
        return $reader;
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    public function encoding(): string
    {
        return $this->sourceEncoding;
    }

    /**
     * @return Generator<int, list<string>> número de línea lógica (1..n) => celdas
     */
    public function readRows(): Generator
    {
        $handle = fopen($this->filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo CSV.');
        }

        try {
            if ($this->hasBom) {
                fseek($handle, 3);
            }
            $rowNumber = 0;
            while (($fields = fgetcsv($handle, 0, $this->delimiter, '"', '')) !== false) {
                $rowNumber++;
                if ($fields === [null]) {
                    yield $rowNumber => [];
                    continue;
                }
                $cells = [];
                foreach ($fields as $field) {
                    $cells[] = $this->toUtf8((string) $field);
                }
                yield $rowNumber => $cells;
            }
        } finally {
            fclose($handle);
        }
    }

    private function toUtf8(string $value): string
    {
        if ($this->sourceEncoding === 'UTF-8' || $value === '') {
            return $value;
        }
        return (string) mb_convert_encoding($value, 'UTF-8', $this->sourceEncoding);
    }

    private function detect(): void
    {
        $handle = fopen($this->filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo CSV.');
        }
        // Muestra de hasta 64 KB: suficiente para encabezados y varias filas.
        $sample = (string) fread($handle, 65536);
        fclose($handle);

        if (str_starts_with($sample, "\xEF\xBB\xBF")) {
            $this->hasBom = true;
            $sample = substr($sample, 3);
        }

        // Codificación. Si la muestra corta un carácter multibyte al final,
        // recortamos hasta 3 bytes antes de decidir.
        $this->sourceEncoding = 'UTF-8';
        if (!$this->hasBom) {
            $check = $sample;
            for ($i = 0; $i < 4 && $check !== '' && !mb_check_encoding($check, 'UTF-8'); $i++) {
                $check = substr($check, 0, -1);
            }
            if (!mb_check_encoding($check, 'UTF-8')) {
                $this->sourceEncoding = in_array('Windows-1252', mb_list_encodings(), true)
                    ? 'Windows-1252'
                    : 'ISO-8859-1';
            }
        }

        // Delimitador: el candidato más consistente en las primeras líneas,
        // contando solo fuera de comillas.
        $lines = preg_split('/\r\n|\r|\n/', $sample) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));
        $lines = array_slice($lines, 0, 10);

        $best = ',';
        $bestScore = -1;
        foreach ([';', ',', "\t", '|'] as $candidate) {
            $counts = array_map(static fn (string $l): int => self::countOutsideQuotes($l, $candidate), $lines);
            if ($counts === [] || $counts[0] === 0) {
                continue;
            }
            $header = $counts[0];
            $consistent = count(array_filter($counts, static fn (int $c): bool => $c === $header));
            $score = $consistent * 1000 + $header;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }
        $this->delimiter = $best;
    }

    private static function countOutsideQuotes(string $line, string $char): int
    {
        $count = 0;
        $inQuotes = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($c === '"') {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes && $c === $char) {
                $count++;
            }
        }
        return $count;
    }
}
