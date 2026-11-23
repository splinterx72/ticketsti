<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Roles;
use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Session;
use App\Libs\SimpleXLSX;
use App\Services\GecaSyncException;
use App\Services\GecaSyncService;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Módulo de carga semanal del GECA (personal de TI).
 */
final class GecaController extends Controller
{
    private const ROLES = Roles::TI;

    private const ALLOWED_EXT = ['csv', 'xlsx'];

    public function index(): void
    {
        $this->requireRole(self::ROLES);

        $cargas = Database::getInstance()->query(
            'SELECT c.*, u.nombre AS agente
               FROM cargas_geca c
               LEFT JOIN usuarios u ON u.id = c.usuario_ti_id
              ORDER BY c.id DESC
              LIMIT 20'
        )->fetchAll();

        $this->view('geca/index', [
            'title'        => 'Carga semanal GECA',
            'cargas'       => $cargas,
            'maxUploadMb'  => $this->maxUploadMb(),
            'xlsxSoportado' => SimpleXLSX::isSupported(),
            'headers'      => GecaSyncService::HEADERS,
        ]);
    }

    public function upload(): void
    {
        $this->requireRole(self::ROLES);

        // Si el archivo supera post_max_size, PHP vacía $_POST (y el token CSRF).
        if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            Session::flash('danger', 'El archivo excede el tamaño máximo permitido por el servidor (' . $this->maxUploadMb() . ' MB).');
            redirect('/geca');
        }
        $this->verifyCsrf();

        $file = $_FILES['archivo'] ?? null;
        if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
            Session::flash('danger', 'Selecciona un archivo .xlsx o .csv.');
            redirect('/geca');
        }

        $uploadError = (int) $file['error'];
        if ($uploadError !== UPLOAD_ERR_OK) {
            Session::flash('danger', $this->uploadErrorMessage($uploadError));
            redirect('/geca');
        }

        $originalName = basename((string) $file['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            Session::flash('danger', 'Formato no permitido. Solo se aceptan archivos .xlsx o .csv.');
            redirect('/geca');
        }

        $size = (int) $file['size'];
        if ($size <= 0) {
            Session::flash('danger', 'El archivo está vacío.');
            redirect('/geca');
        }
        if ($size > $this->maxUploadMb() * 1048576) {
            Session::flash('danger', 'El archivo excede ' . $this->maxUploadMb() . ' MB.');
            redirect('/geca');
        }

        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            throw new HttpException(400, 'Carga de archivo inválida.');
        }

        // Se mueve a storage/uploads (bloqueado por web) con nombre aleatorio.
        $dir = STORAGE_PATH . '/uploads';
        if (!is_dir($dir) || !is_writable($dir)) {
            Session::flash('danger', 'La carpeta storage/uploads no existe o no tiene permisos de escritura (755).');
            redirect('/geca');
        }
        $target = $dir . '/geca_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $target)) {
            Session::flash('danger', 'No se pudo guardar el archivo en el servidor.');
            redirect('/geca');
        }

        $result = null;
        $error = null;
        try {
            $service = new GecaSyncService(Database::getInstance());
            $result = $service->sync($target, $originalName, Auth::id());
        } catch (GecaSyncException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log('[GECA][BD] ' . $e->getMessage());
            $error = 'La carga falló por un error de base de datos y se revirtió por completo; no hubo cambios. Detalle en storage/logs/php-error.log.';
        } catch (RuntimeException $e) {
            // Errores de lectura de archivo (XLSX dañado, CSV ilegible...).
            $error = 'No se pudo leer el archivo: ' . $e->getMessage();
        } catch (Throwable $e) {
            error_log('[GECA] ' . $e::class . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
            $error = 'La carga falló y se revirtió por completo; la base de datos no sufrió cambios. Revisa storage/logs/php-error.log.';
        } finally {
            // El archivo nunca se conserva en el servidor.
            if (is_file($target)) {
                @unlink($target);
            }
        }

        if ($error !== null || $result === null) {
            Session::flash('danger', $error ?? 'La carga no devolvió resultados.');
            redirect('/geca');
        }

        Session::set('_geca_result', $result);
        Session::flash('success', sprintf(
            'Carga #%d completada: %d registros (%d nuevos, %d actualizados).',
            $result['carga_id'],
            $result['total_registros'],
            $result['nuevos_insertados'],
            $result['actualizados']
        ));
        redirect('/geca/cargas/' . $result['carga_id']);
    }

    public function show(int $id): void
    {
        $this->requireRole(self::ROLES);

        $stmt = Database::getInstance()->prepare(
            'SELECT c.*, u.nombre AS agente
               FROM cargas_geca c
               LEFT JOIN usuarios u ON u.id = c.usuario_ti_id
              WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        $carga = $stmt->fetch();
        if (!$carga) {
            throw new HttpException(404, 'La carga solicitada no existe.');
        }

        // Detalle extendido (advertencias, tiempos): solo justo después de cargar.
        $detalle = Session::get('_geca_result');
        if (!is_array($detalle) || (int) ($detalle['carga_id'] ?? 0) !== $id) {
            $detalle = null;
        } else {
            Session::remove('_geca_result');
        }

        $this->view('geca/show', [
            'title'   => 'Carga GECA #' . $id,
            'carga'   => $carga,
            'detalle' => $detalle,
        ]);
    }

    /** Descarga la plantilla CSV con los 30 encabezados oficiales. */
    public function template(): void
    {
        $this->requireRole(self::ROLES);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="plantilla_geca.csv"');
        header('Cache-Control: no-store');
        echo GecaSyncService::templateCsv();
    }

    private function maxUploadMb(): int
    {
        $configured = (int) config('geca.max_upload_mb', 30);
        $limits = [$configured, self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size')];
        $limits = array_filter($limits, static fn (int $v): bool => $v > 0);
        return max(1, (int) min($limits));
    }

    /** Convierte "32M" -> 32 (MB). */
    private static function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        if ($value === '' || $value === '0') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        $mb = match ($unit) {
            'g' => $number * 1024,
            'm' => $number,
            'k' => $number / 1024,
            default => $number / 1048576,
        };
        return (int) floor($mb);
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido (' . $this->maxUploadMb() . ' MB).',
            UPLOAD_ERR_PARTIAL    => 'El archivo se subió incompleto. Inténtalo de nuevo.',
            UPLOAD_ERR_NO_FILE    => 'Selecciona un archivo .xlsx o .csv.',
            UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene carpeta temporal configurada.',
            UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo en disco.',
            default               => 'Error desconocido al subir el archivo.',
        };
    }
}
