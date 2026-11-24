<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;

/**
 * Imágenes de los avisos (carrusel de noticias de la pantalla de entrada).
 *
 * - JPG, PNG o WebP de hasta 5 MB; el tipo se toma del contenido real.
 * - Marco 16:9: se recomienda 1600×900 px y se exige al menos 960×540.
 * - Con GD (normal en cPanel) se vuelve a codificar como JPEG de máximo
 *   1920×1080: pesa menos y se eliminan metadatos o datos añadidos al archivo.
 * - Se guarda en storage/avisos/{año}/{mes}/{aleatorio}.jpg y solo se sirve
 *   por AvisoController::imagen (storage/ está bloqueado por web).
 */
final class ImagenAvisoService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MIN_ANCHO = 960;
    public const MIN_ALTO = 540;
    public const MAX_ANCHO = 1920;
    public const MAX_ALTO = 1080;

    private const TIPOS = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    /**
     * @param bool $soloSubidas true en producción: exige is_uploaded_file(); las
     *                          pruebas automáticas usan false para archivos locales.
     */
    public function __construct(private readonly string $base, private readonly bool $soloSubidas = true)
    {
    }

    public static function enProduccion(): self
    {
        return new self(STORAGE_PATH . '/avisos');
    }

    /** true si el formulario trae un archivo (aunque haya fallado la subida). */
    public static function hayArchivo(mixed $upload): bool
    {
        return is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * @param array<string, mixed> $upload un elemento de $_FILES
     * @return string ruta relativa dentro de storage/avisos
     */
    public function guardar(array $upload): string
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || (int) ($upload['size'] ?? 0) > self::MAX_BYTES) {
            throw new ValidationException(['La imagen pesa más de 5 MB. Redúcela (por ejemplo a 1600×900 px) e inténtalo de nuevo.']);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(['No se pudo subir la imagen; inténtalo de nuevo.']);
        }
        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || ($this->soloSubidas && !is_uploaded_file($tmp))) {
            throw new ValidationException(['No se recibió la imagen.']);
        }

        $info = @getimagesize($tmp);
        if ($info === false || !isset(self::TIPOS[$info[2]])) {
            throw new ValidationException(['La imagen debe ser JPG, PNG o WebP.']);
        }
        [$ancho, $alto] = $info;
        if ($ancho < self::MIN_ANCHO || $alto < self::MIN_ALTO) {
            throw new ValidationException([
                "La imagen mide {$ancho}×{$alto} px; se necesita al menos " . self::MIN_ANCHO . '×' . self::MIN_ALTO
                . ' px (lo ideal: 1600×900, formato 16:9).',
            ]);
        }

        $relativa = date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16));
        $dir = $this->base . '/' . dirname($relativa);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear la carpeta de imágenes de avisos.');
        }

        if (function_exists('imagecreatetruecolor')) {
            $relativa .= '.jpg';
            $this->recodificar($tmp, (int) $info[2], $ancho, $alto, $this->base . '/' . $relativa);
            return $relativa;
        }

        // Sin GD: se guarda tal cual, solo si no trae código incrustado.
        $inicio = (string) file_get_contents($tmp);
        if (preg_match('/<\?(php|=)/i', $inicio) === 1) {
            throw new ValidationException(['La imagen contiene datos no permitidos.']);
        }
        $relativa .= '.' . self::TIPOS[$info[2]];
        if (!copy($tmp, $this->base . '/' . $relativa)) {
            throw new \RuntimeException('No se pudo guardar la imagen.');
        }
        return $relativa;
    }

    public function rutaAbsoluta(?string $relativa): ?string
    {
        if ($relativa === null || $relativa === '' || str_contains($relativa, '..') || preg_match('#^[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.(jpg|png|webp)$#', $relativa) !== 1) {
            return null;
        }
        $abs = realpath($this->base . '/' . $relativa);
        $base = realpath($this->base);
        return $abs !== false && $base !== false && str_starts_with($abs, $base . DIRECTORY_SEPARATOR) && is_file($abs) ? $abs : null;
    }

    public function borrar(?string $relativa): void
    {
        $abs = $this->rutaAbsoluta($relativa);
        if ($abs !== null) {
            @unlink($abs);
        }
    }

    private function recodificar(string $origen, int $tipo, int $ancho, int $alto, string $destino): void
    {
        $src = match ($tipo) {
            IMAGETYPE_PNG  => @imagecreatefrompng($origen),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($origen) : false,
            default        => @imagecreatefromjpeg($origen),
        };
        if ($src === false) {
            throw new ValidationException(['No se pudo leer la imagen; guárdala de nuevo como JPG e inténtalo otra vez.']);
        }
        $escala = min(1.0, self::MAX_ANCHO / $ancho, self::MAX_ALTO / $alto);
        $w = max(1, (int) round($ancho * $escala));
        $h = max(1, (int) round($alto * $escala));
        $dst = imagecreatetruecolor($w, $h);
        // Fondo blanco para PNG/WebP con transparencia.
        imagefill($dst, 0, 0, (int) imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $ancho, $alto);
        imageinterlace($dst, true);
        $ok = imagejpeg($dst, $destino, 82);
        imagedestroy($src);
        imagedestroy($dst);
        if (!$ok) {
            throw new \RuntimeException('No se pudo guardar la imagen.');
        }
    }
}
