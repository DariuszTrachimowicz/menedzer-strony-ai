<?php

declare(strict_types=1);

namespace ASM\Storage;

final class PayloadCipher
{
    private string $key;

    public function __construct(string $secret)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Sekret szyfrowania nie może być pusty.');
        }
        if (! function_exists('openssl_encrypt') || ! in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            throw new \RuntimeException('Serwer nie udostępnia AES-256-GCM.');
        }
        $this->key = hash('sha256', 'asm-payload-v1|' . $secret, true);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'asm-operation-v1',
            16
        );
        if (! is_string($ciphertext) || strlen($tag) !== 16) {
            throw new \RuntimeException('Nie udało się zaszyfrować operacji.');
        }
        return 'v1.' . $this->encode($nonce) . '.' . $this->encode($tag) . '.' . $this->encode($ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $parts = explode('.', $payload, 4);
        if (count($parts) !== 4 || $parts[0] !== 'v1') {
            throw new \RuntimeException('Nieprawidłowy format zaszyfrowanej operacji.');
        }
        $nonce = $this->decode($parts[1]);
        $tag = $this->decode($parts[2]);
        $ciphertext = $this->decode($parts[3]);
        if (
            ! is_string($nonce) || strlen($nonce) !== 12 || ! is_string($tag)
            || strlen($tag) !== 16 || ! is_string($ciphertext)
        ) {
            throw new \RuntimeException('Uszkodzona zaszyfrowana operacja.');
        }
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'asm-operation-v1'
        );
        if (! is_string($plaintext)) {
            throw new \RuntimeException('Nie można uwierzytelnić zaszyfrowanej operacji.');
        }
        return $plaintext;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @return string|false */
    private function decode(string $value)
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
