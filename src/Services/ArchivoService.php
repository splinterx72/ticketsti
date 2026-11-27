<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\ValidationException;
use finfo;

/**
 * Archivos de tareas (referencias y evidencias), spec 7.6.
 *
 * - Extensiones permitidas: pdf, jpg, jpeg, png, webp, doc, docx, xls, xlsx, zip.
 * - El tipo real (finfo) debe corresponder a la extensión.
 * - Máximo 10 MB por archivo; se rechaza cualquier contenido con código PHP.
 * - Se guardan en storage/tareas/{subcarpeta}/{año}/{mes}/{aleatorio}.{ext};
 *   el nombre original solo se usa al descargar.
 */
final class ArchivoService
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** extensión => tipos MIME aceptados */
    public const PERMITIDOS = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];

    /**
     * @param bool $soloSubidas true en producción: exige is_uploaded_file(); las
     *                          pruebas automáticas usan false para archivos locales.
     */
    public function __construct(private readonly string $base, private readonly bool $soloSubidas = true)
    {
    }

    public static function enProduccion(): self
    {
        return new self(STORAGE_PATH . '/tareas');
    }

    /**
     * @param array<string, mixed> $upload un elemento de $_FILES
     * @return array{ruta: string, nombre_original: string, mime: string, tamano: int}
     */
    public function guardar(array $upload, string $subcarpeta): array
    {
        $nombre = self::nombreSeguro((string) ($upload['name'] ?? 'archivo'));
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(["No se pudo subir \"{$nombre}\" (" . self::mensajeError($error) . ').']);
        }
        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || ($this->soloSubidas && !is_uploaded_file($tmp))) {
            throw new ValidationException(["El archivo \"{$nombre}\" no es una subida válida."]);
        }

        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        if (!array_key_exists($ext, self::PERMITIDOS)) {
            throw new ValidationException(["\"{$nombre}\": tipo no permitido. Usa PDF, imagen (JPG, PNG, WEBP), Word, Excel o ZIP."]);
        }
        $tamano = (int) filesize($tmp);
        if ($tamano <= 0) {
            throw new ValidationException(["\"{$nombre}\" está vacío."]);
        }
        if ($tamano > self::MAX_BYTES) {
            throw new ValidationException(["\"{$nombre}\" excede el máximo de 10 MB."]);
        }
        $muestra = (string) file_get_contents($tmp, false, null, 0, 65536);
        if (stripos($muestra, '<?php') !== false || str_contains($muestra, '<?=')) {
            throw new ValidationException(["\"{$nombre}\" contiene código no permitido."]);
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, self::PERMITIDOS[$ext], true)) {
            throw new ValidationException(["\"{$nombre}\": el contenido no corresponde a un archivo .{$ext}."]);
        }

        $sub = preg_replace('/[^a-z0-9_-]/', '', strtolower($subcarpeta)) ?: 'archivos';
        $relDir = $sub . '/' . date('Y') . '/' . date('m');
        $dir = $this->base . '/' . $relDir;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ValidationException(['No se pudo preparar la carpeta de archivos (revisa permisos de storage/).']);
        }
        $ruta = $relDir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $destino = $this->base . '/' . $ruta;
        $ok = $this->soloSubidas ? move_uploaded_file($tmp, $destino) : rename($tmp, $destino);
        if (!$ok) {
            throw new ValidationException(["No se pudo guardar \"{$nombre}\"."]);
        }
        @chmod($destino, 0644);

        return ['ruta' => $ruta, 'nombre_original' => $nombre, 'mime' => $mime, 'tamano' => $tamano];
    }

    /**
     * Guarda varias subidas; si una falla, borra las ya guardadas y relanza el error.
     *
     * @param list<array<string, mixed>> $subidas
     * @return list<array{ruta: string, nombre_original: string, mime: string, tamano: int}>
     */
    public function guardarVarios(array $subidas, string $subcarpeta, int $maximo): array
    {
        if (count($subidas) > $maximo) {
            throw new ValidationException(["Máximo {$maximo} archivos por envío."]);
        }
        $ok = [];
        try {
            foreach ($subidas as $up) {
                $ok[] = $this->guardar($up, $subcarpeta);
            }
        } catch (\Throwable $e) {
            $this->descartar($ok);
            throw $e;
        }
        return $ok;
    }

    /** Borra archivos ya guardados (si la operación posterior falla). @param list<array{ruta: string}> $archivos */
    public function descartar(array $archivos): void
    {
        foreach ($archivos as $a) {
            $p = $this->rutaAbsoluta((string) $a['ruta']);
            if ($p !== null) {
                @unlink($p);
            }
        }
    }

    /** @param array<string, mixed> $archivo fila de tarea_archivos */
    public function enviar(array $archivo): never
    {
        $path = $this->rutaAbsoluta((string) $archivo['ruta']);
        if ($path === null) {
            throw new HttpException(404, 'El archivo ya no está disponible.');
        }
        $nombre = self::nombreSeguro((string) $archivo['nombre_original']);
        $ascii = (string) preg_replace('/[^A-Za-z0-9._ -]/', '_', $nombre);
        header('Content-Type: ' . (string) $archivo['mime']);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    public function rutaAbsoluta(string $ruta): ?string
    {
        if ($ruta === '' || str_contains($ruta, '..')) {
            return null;
        }
        $real = realpath($this->base . '/' . $ruta);
        $base = realpath($this->base);
        return ($real !== false && $base !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR) && is_file($real)) ? $real : null;
    }

    private static function nombreSeguro(string $nombre): string
    {
        $nombre = basename(str_replace('\\', '/', $nombre));
        $nombre = (string) preg_replace('/[\x00-\x1F\x7F"]/u', '', $nombre);
        $nombre = trim($nombre);
        return $nombre !== '' ? mb_substr($nombre, 0, 200) : 'archivo';
    }

    private static function mensajeError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'excede el tamaño permitido por el servidor',
            UPLOAD_ERR_PARTIAL => 'se subió incompleto',
            UPLOAD_ERR_NO_FILE => 'no se eligió archivo',
            default => 'error del servidor',
        };
    }
}
