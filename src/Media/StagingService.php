<?php

declare(strict_types=1);

namespace ASM\Media;

use ASM\Storage\PrivateStorage;

final class StagingService
{
    private PrivateStorage $storage;
    private UploadValidator $validator;
    private string $secret;
    private int $ttl;

    public function __construct(
        PrivateStorage $storage,
        UploadValidator $validator,
        string $secret,
        int $ttl = 1800
    ) {
        $this->storage = $storage;
        $this->validator = $validator;
        $this->secret = $secret;
        $this->ttl = $ttl;
    }

    /** @return array<string,mixed>|\WP_Error */
    public function stageFile(string $path, string $name)
    {
        if (! is_file($path)) {
            return new \WP_Error('asm_upload_unreadable', 'Nie można odczytać przesłanego pliku.', array('status' => 400));
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            return new \WP_Error('asm_upload_unreadable', 'Nie można odczytać przesłanego pliku.', array('status' => 400));
        }
        return $this->stageBytes($bytes, $name);
    }

    /** @return array<string,mixed>|\WP_Error */
    public function stageBytes(string $bytes, string $name)
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($name));
        $safeName = is_string($safeName) && $safeName !== '' ? $safeName : 'upload.bin';
        $id = bin2hex(random_bytes(16));
        $path = $this->storage->store($id, 'uploads/' . $safeName, $bytes);
        $validated = $this->validator->validate($path, $safeName);
        if ($validated instanceof \WP_Error) {
            return $validated;
        }

        $payload = array(
            'id' => $id,
            'name' => $safeName,
            'mime' => $validated['mime'],
            'sha256' => $validated['sha256'],
            'expires' => time() + $this->ttl,
        );
        $encoded = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $this->secret, true));

        return array(
            'token' => $encoded . '.' . $signature,
            'name' => $safeName,
            'mime' => $validated['mime'],
            'size' => $validated['size'],
            'sha256' => $validated['sha256'],
            'expires' => $payload['expires'],
        );
    }

    /** @return array<string,mixed>|\WP_Error */
    public function resolve(string $token)
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return $this->invalidToken();
        }
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $parts[0], $this->secret, true));
        if (! hash_equals($expected, $parts[1])) {
            return $this->invalidToken();
        }
        $json = $this->base64UrlDecode($parts[0]);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($payload) || (int) ($payload['expires'] ?? 0) < time()) {
            return $this->invalidToken();
        }

        try {
            $path = $this->storage->path((string) $payload['id'], 'uploads/' . (string) $payload['name']);
        } catch (\Throwable $error) {
            return $this->invalidToken();
        }
        $validated = $this->validator->validate($path, (string) $payload['name']);
        if ($validated instanceof \WP_Error || ! hash_equals((string) $payload['sha256'], (string) $validated['sha256'])) {
            return $this->invalidToken();
        }
        $validated['path'] = $path;
        return $validated;
    }

    private function invalidToken(): \WP_Error
    {
        return new \WP_Error('asm_invalid_upload_token', 'Token uploadu jest nieprawidłowy lub wygasł.', array('status' => 400));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @return string|false */
    private function base64UrlDecode(string $value)
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
