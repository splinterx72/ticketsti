<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Gestión › Diagnóstico (solo Admin TI): lo que suele causar un "Error 500"
 * en el hosting, sin entrar por FTP:
 *  - migraciones pendientes (lee cada .sql de database/migraciones y revisa
 *    que existan sus tablas y columnas nuevas);
 *  - extensiones de PHP que usan los módulos;
 *  - carpetas de storage con permiso de escritura;
 *  - últimas líneas del registro de errores.
 */
final class DiagnosticoService
{
    public const EXTENSIONES = [
        'pdo_mysql' => 'Conexión a la base de datos',
        'mbstring'  => 'Textos con acentos',
        'zip'       => 'Carga del GECA en Excel (.xlsx)',
        'xmlreader' => 'Carga del GECA en Excel (.xlsx)',
        'fileinfo'  => 'Revisar adjuntos, evidencias y escaneos',
        'gd'        => 'Imágenes de noticias, logos y firmas',
        'sodium'    => 'Cifrar las llaves de BitLocker',
        'openssl'   => 'Correo por SMTP seguro',
    ];

    public const CARPETAS = ['storage', 'storage/logs', 'storage/sessions', 'storage/uploads', 'storage/tareas', 'storage/tickets', 'storage/avisos', 'storage/keys', 'storage/formatos'];

    public function __construct(private readonly PDO $pdo, private readonly string $raiz)
    {
    }

    /** @return list<array{archivo: string, aplicada: bool, faltan: list<string>}> */
    public function migraciones(): array
    {
        $tablas = array_flip(array_map('strtolower', $this->pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchAll(PDO::FETCH_COLUMN)));
        $columnas = [];
        foreach ($this->pdo->query('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll() as $c) {
            $columnas[strtolower($c['TABLE_NAME'] . '.' . $c['COLUMN_NAME'])] = true;
        }
        $out = [];
        $archivos = glob($this->raiz . '/database/migraciones/*.sql') ?: [];
        sort($archivos);
        foreach ($archivos as $archivo) {
            $sql = (string) preg_replace('/--[^\n]*/', '', (string) file_get_contents($archivo));
            $faltan = [];
            preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $m);
            foreach ($m[1] as $t) {
                if (!isset($tablas[strtolower($t)])) {
                    $faltan[] = "tabla {$t}";
                }
            }
            preg_match_all('/ALTER\s+TABLE\s+`?(\w+)`?(.*?);/is', $sql, $alters, PREG_SET_ORDER);
            foreach ($alters as [, $tabla, $cuerpo]) {
                preg_match_all('/ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $cuerpo, $cols);
                foreach ($cols[1] as $col) {
                    if (!isset($columnas[strtolower($tabla . '.' . $col)])) {
                        $faltan[] = "columna {$tabla}.{$col}";
                    }
                }
            }
            $out[] = ['archivo' => basename($archivo), 'aplicada' => $faltan === [], 'faltan' => $faltan];
        }
        return $out;
    }

    /** @return list<array{nombre: string, ok: bool, para: string}> */
    public function extensiones(): array
    {
        $out = [];
        foreach (self::EXTENSIONES as $ext => $para) {
            $out[] = ['nombre' => $ext, 'ok' => extension_loaded($ext), 'para' => $para];
        }
        return $out;
    }

    /** @return list<array{ruta: string, ok: bool, detalle: string}> */
    public function carpetas(): array
    {
        $out = [];
        foreach (self::CARPETAS as $rel) {
            $abs = $this->raiz . '/' . $rel;
            $ok = is_dir($abs) && is_writable($abs);
            $out[] = ['ruta' => $rel, 'ok' => $ok, 'detalle' => !is_dir($abs) ? 'No existe' : ($ok ? 'Se puede escribir' : 'Sin permiso de escritura')];
        }
        return $out;
    }

    /** @return list<string> últimas líneas de storage/logs/php-error.log */
    public function ultimosErrores(int $n = 40): array
    {
        $archivo = $this->raiz . '/storage/logs/php-error.log';
        if (!is_file($archivo) || !is_readable($archivo)) {
            return [];
        }
        $tam = (int) filesize($archivo);
        $fh = fopen($archivo, 'rb');
        if ($fh === false) {
            return [];
        }
        // Solo el final del archivo (puede ser grande en el hosting).
        fseek($fh, max(0, $tam - 65536));
        $texto = (string) stream_get_contents($fh);
        fclose($fh);
        $lineas = array_values(array_filter(array_map('rtrim', explode("\n", $texto)), static fn (string $l): bool => $l !== ''));
        return array_slice($lineas, -$n);
    }

    /** @return array<string, string> datos de configuración relevantes (sin secretos) */
    public function configuracion(): array
    {
        $clave = (string) config('security.clave_cifrado', '');
        return [
            'PHP' => PHP_VERSION,
            'Correo' => (string) config('mail.driver', 'off'),
            'URL' => (string) config('app.base_url', ''),
            'Clave de cifrado (BitLocker)' => $clave !== '' ? 'En config/config.php'
                : (is_file($this->raiz . '/storage/keys/cifrado.key') ? 'En storage/keys/cifrado.key (respáldala)' : 'Aún no se crea (se genera al guardar la primera llave)'),
        ];
    }
}
