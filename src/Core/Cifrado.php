<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Cifrado simétrico autenticado (libsodium secretbox, XSalsa20-Poly1305).
 * Formato guardado: "v1:" + base64(nonce || cifrado).
 * Clave: config security.clave_cifrado (base64, 32 bytes); si falta, se crea
 * una sola vez en storage/keys/cifrado.key (carpeta bloqueada por web).
 */
final class Cifrado
{
    public function __construct(private readonly string $clave)
    {
        if (!self::disponible()) {
            throw new \RuntimeException('Falta la extensión sodium de PHP (actívala en cPanel › Select PHP Version › Extensions).');
        }
        if (strlen($clave) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('La clave de cifrado debe medir 32 bytes.');
        }
    }

    public static function disponible(): bool
    {
        return function_exists('sodium_crypto_secretbox') && defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES');
    }

    public static function delSistema(): self
    {
        if (!self::disponible()) {
            throw new \RuntimeException('Falta la extensión sodium de PHP (actívala en cPanel › Select PHP Version › Extensions).');
        }
        $cfg = (string) config('security.clave_cifrado', '');
        if ($cfg !== '') {
            $clave = base64_decode($cfg, true);
            if ($clave !== false && strlen($clave) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return new self($clave);
            }
        }
        $dir = STORAGE_PATH . '/keys';
        $archivo = $dir . '/cifrado.key';
        if (!is_file($archivo)) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $nueva = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            if (@file_put_contents($archivo, $nueva, LOCK_EX) === false) {
                throw new \RuntimeException('No se pudo crear la clave de cifrado en storage/keys (revisa que la carpeta exista y tenga permiso de escritura).');
            }
            @chmod($archivo, 0600);
        }
        $clave = base64_decode(trim((string) file_get_contents($archivo)), true);
        if ($clave === false || strlen($clave) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('La clave de cifrado en storage/keys/cifrado.key no es válida.');
        }
        return new self($clave);
    }

    public function cifrar(string $texto): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($texto, $nonce, $this->clave));
    }

    public function descifrar(string $guardado): string
    {
        $bin = str_starts_with($guardado, 'v1:') ? base64_decode(substr($guardado, 3), true) : false;
        if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Dato cifrado inválido.');
        }
        $texto = sodium_crypto_secretbox_open(
            substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->clave
        );
        if ($texto === false) {
            throw new \RuntimeException('No se pudo descifrar: la clave de cifrado no coincide.');
        }
        return $texto;
    }
}
