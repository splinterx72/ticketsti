<?php

declare(strict_types=1);

namespace App\Libs;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * SimpleXLSX — lector .xlsx de un solo archivo, sin Composer y en streaming.
 *
 * Inspirado en la API de la librería SimpleXLSX (SimpleXLSX::parse()->rows()),
 * pero reescrito para hosting compartido: la hoja se recorre con XMLReader
 * nodo por nodo (no se carga el XML completo en memoria), por lo que un GECA
 * de decenas de miles de filas se lee con pocos MB de RAM.
 *
 * Requisitos: extensiones zip y xmlreader (vienen activas en cPanel/NEUBOX;
 * si faltaran, actívalas en "Select PHP Version" > Extensions).
 *
 * Uso:
 *   $xlsx = SimpleXLSX::parse('/ruta/archivo.xlsx');
 *   foreach ($xlsx->readRows() as $numFila => $celdas) { ... }
 *
 * Notas:
 * - Lee la PRIMERA hoja del libro (o la indicada en readRows($indice)).
 * - Las fechas reales de Excel llegan como número serial (ej. 45231 o
 *   45231.5); usa SimpleXLSX::excelSerialToDateTime() para convertirlas.
 * - Las fórmulas devuelven su último valor calculado (el que guardó Excel).
 */
final class SimpleXLSX
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @var list<string> */
    private array $sharedStrings = [];

    /** @var list<array{name: string, path: string}> */
    private array $sheets = [];

    private function __construct(private readonly string $filePath)
    {
    }

    public static function isSupported(): bool
    {
        return class_exists(ZipArchive::class) && class_exists(XMLReader::class);
    }

    public static function parse(string $filePath): self
    {
        if (!self::isSupported()) {
            throw new RuntimeException(
                'El servidor no tiene las extensiones "zip" y "xmlreader". Actívalas en cPanel o sube el GECA en formato CSV.'
            );
        }
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('No se puede leer el archivo XLSX.');
        }

        $xlsx = new self($filePath);
        $xlsx->loadWorkbook();
        $xlsx->loadSharedStrings();
        return $xlsx;
    }

    /** @return list<string> nombres de las hojas en orden */
    public function sheetNames(): array
    {
        return array_map(static fn (array $s): string => $s['name'], $this->sheets);
    }

    /**
     * Recorre las filas de una hoja. Clave = número de fila de Excel (1..n),
     * valor = lista de celdas como string, rellenando huecos con ''.
     *
     * @return Generator<int, list<string>>
     */
    public function readRows(int $sheetIndex = 0): Generator
    {
        if (!isset($this->sheets[$sheetIndex])) {
            throw new RuntimeException('La hoja solicitada no existe en el archivo XLSX.');
        }

        $reader = new XMLReader();
        $uri = 'zip://' . $this->filePath . '#' . $this->sheets[$sheetIndex]['path'];
        if (!@$reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('No se pudo abrir la hoja del archivo XLSX.');
        }

        try {
            $rowNumber = 0;
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $r = $reader->getAttribute('r');
                $rowNumber = ($r !== null && ctype_digit($r)) ? (int) $r : $rowNumber + 1;

                $cells = $this->readRowCells($reader);
                yield $rowNumber => $cells;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Compatibilidad con la API clásica: devuelve todas las filas en un arreglo.
     * Para archivos grandes usa readRows(), que no acumula en memoria.
     *
     * @return list<list<string>>
     */
    public function rows(int $sheetIndex = 0): array
    {
        $all = [];
        foreach ($this->readRows($sheetIndex) as $cells) {
            $all[] = $cells;
        }
        return $all;
    }

    /**
     * Convierte un serial de fecha de Excel (sistema 1900) a 'Y-m-d H:i:s'.
     */
    public static function excelSerialToDateTime(float $serial): string
    {
        // 25569 = días entre 1899-12-30 y 1970-01-01 (incluye el bug del 29/02/1900).
        $seconds = (int) round(($serial - 25569) * 86400);
        return gmdate('Y-m-d H:i:s', $seconds);
    }

    /**
     * Lee las celdas <c> de la fila actual y deja el cursor al final de </row>.
     *
     * @return list<string>
     */
    private function readRowCells(XMLReader $reader): array
    {
        $cells = [];
        if ($reader->isEmptyElement) {
            return $cells;
        }

        $nextIndex = 0;
        $depth = $reader->depth;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row' && $reader->depth === $depth) {
                break;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') {
                continue;
            }

            $ref = $reader->getAttribute('r');
            $index = $ref !== null ? self::columnIndex($ref) : $nextIndex;
            $type = $reader->getAttribute('t') ?? 'n';
            $value = $this->readCellValue($reader, $type);

            // Rellena columnas vacías que Excel omite.
            for ($i = count($cells); $i < $index; $i++) {
                $cells[$i] = '';
            }
            $cells[$index] = $value;
            $nextIndex = $index + 1;
        }

        ksort($cells);
        return array_values($cells);
    }

    private function readCellValue(XMLReader $reader, string $type): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }

        $raw = '';
        $inline = '';
        $depth = $reader->depth;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'c' && $reader->depth === $depth) {
                break;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'v') {
                $raw = $reader->readString();
            } elseif ($reader->localName === 't' && $type === 'inlineStr') {
                $inline .= $reader->readString();
            }
        }

        return match ($type) {
            's'         => $this->sharedStrings[(int) $raw] ?? '',
            'inlineStr' => $inline,
            'b'         => $raw === '1' ? 'TRUE' : 'FALSE',
            'e'         => '',
            default     => self::normalizeNumber($raw),
        };
    }

    /** Evita que fichas/series numéricas lleguen como "12345.0" o notación científica simple. */
    private static function normalizeNumber(string $raw): string
    {
        if ($raw === '' || !is_numeric($raw)) {
            return $raw;
        }
        if (preg_match('/^-?\d+\.0+$/', $raw) === 1) {
            return substr($raw, 0, (int) strpos($raw, '.'));
        }
        if (stripos($raw, 'e') !== false) {
            $f = (float) $raw;
            if (floor($f) === $f && abs($f) < 1e15) {
                return number_format($f, 0, '.', '');
            }
        }
        return $raw;
    }

    /** "A1" -> 0, "Z9" -> 25, "AA3" -> 26 */
    private static function columnIndex(string $ref): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $ref));
        $index = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $index - 1);
    }

    private function loadWorkbook(): void
    {
        $zip = new ZipArchive();
        if ($zip->open($this->filePath) !== true) {
            throw new RuntimeException('El archivo no es un XLSX válido (no se pudo descomprimir).');
        }

        try {
            $workbookXml = $zip->getFromName('xl/workbook.xml');
            $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($workbookXml === false) {
                throw new RuntimeException('El XLSX no contiene xl/workbook.xml.');
            }

            // Relaciones rId -> ruta de la hoja.
            $targets = [];
            if ($relsXml !== false) {
                $rels = $this->loadXml($relsXml);
                foreach ($rels->getElementsByTagName('Relationship') as $rel) {
                    $target = $rel->getAttribute('Target');
                    if (str_starts_with($target, '/')) {
                        $target = ltrim($target, '/');
                    } elseif (!str_starts_with($target, 'xl/')) {
                        $target = 'xl/' . $target;
                    }
                    $targets[$rel->getAttribute('Id')] = $target;
                }
            }

            $wb = $this->loadXml($workbookXml);
            foreach ($wb->getElementsByTagNameNS(self::NS_MAIN, 'sheet') as $sheet) {
                $rid = '';
                foreach ($sheet->attributes ?? [] as $attr) {
                    if ($attr->localName === 'id') {
                        $rid = $attr->value;
                    }
                }
                $path = $targets[$rid] ?? '';
                if ($path !== '' && $zip->locateName($path) !== false) {
                    $this->sheets[] = ['name' => $sheet->getAttribute('name'), 'path' => $path];
                }
            }

            if ($this->sheets === [] && $zip->locateName('xl/worksheets/sheet1.xml') !== false) {
                $this->sheets[] = ['name' => 'Hoja1', 'path' => 'xl/worksheets/sheet1.xml'];
            }
            if ($this->sheets === []) {
                throw new RuntimeException('El XLSX no contiene hojas legibles.');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * La tabla de textos compartidos se carga en streaming; es la única
     * estructura que se mantiene en memoria (textos únicos del libro).
     */
    private function loadSharedStrings(): void
    {
        $zip = new ZipArchive();
        if ($zip->open($this->filePath) !== true) {
            return;
        }
        $exists = $zip->locateName('xl/sharedStrings.xml') !== false;
        $zip->close();
        if (!$exists) {
            return;
        }

        $reader = new XMLReader();
        if (!@$reader->open('zip://' . $this->filePath . '#xl/sharedStrings.xml', null, LIBXML_NONET | LIBXML_COMPACT)) {
            return;
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                    continue;
                }
                $this->sharedStrings[] = $this->readSharedItem($reader);
            }
        } finally {
            $reader->close();
        }
    }

    /** Concatena los <t> de un <si>, ignorando la guía fonética <rPh>. */
    private function readSharedItem(XMLReader $reader): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }
        $text = '';
        $depth = $reader->depth;
        $inPhonetic = false;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT) {
                if ($reader->localName === 'si' && $reader->depth === $depth) {
                    break;
                }
                if ($reader->localName === 'rPh') {
                    $inPhonetic = false;
                }
                continue;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'rPh') {
                $inPhonetic = !$reader->isEmptyElement;
                continue;
            }
            if ($reader->localName === 't' && !$inPhonetic) {
                $text .= $reader->readString();
            }
        }
        return $text;
    }

    private function loadXml(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            throw new RuntimeException('El XLSX contiene XML dañado.');
        }
        return $dom;
    }
}
