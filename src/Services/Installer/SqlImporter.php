<?php

declare(strict_types=1);

namespace App\Services\Installer;

/**
 * Divide un script .sql en sentencias individuales para ejecutarlas con PDO
 * (PDO no acepta varias sentencias en una sola llamada con prepares nativos).
 *
 * Respeta cadenas entre comillas simples, dobles e invertidas (con escapes),
 * y descarta comentarios "-- ", "#" y bloques "/* ... *\/".
 */
final class SqlImporter
{
    /**
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        // Normaliza saltos de línea y elimina BOM.
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        if (str_starts_with($sql, "\xEF\xBB\xBF")) {
            $sql = substr($sql, 3);
        }

        $statements = [];
        $buffer = '';
        $len = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($quote !== null) {
                $buffer .= $c;
                if ($c === '\\' && $quote !== '`') {
                    // Copia el carácter escapado tal cual.
                    if ($next !== '') {
                        $buffer .= $next;
                        $i++;
                    }
                    continue;
                }
                if ($c === $quote) {
                    // Comilla duplicada ('') = comilla literal.
                    if ($next === $quote) {
                        $buffer .= $next;
                        $i++;
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }

            // Comentario de línea: "-- " o "--\n" o "#".
            if (($c === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) || $c === '#') {
                $eol = strpos($sql, "\n", $i);
                $i = $eol === false ? $len : $eol;
                $buffer .= "\n";
                continue;
            }

            // Comentario de bloque.
            if ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                $buffer .= ' ';
                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buffer .= $c;
                continue;
            }

            if ($c === ';') {
                $stmt = trim($buffer);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $c;
        }

        $stmt = trim($buffer);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }

        return $statements;
    }

    /** Nombre de la tabla si la sentencia es un CREATE TABLE. */
    public static function createdTable(string $statement): ?string
    {
        if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $statement, $m) === 1) {
            return $m[1];
        }
        return null;
    }
}
