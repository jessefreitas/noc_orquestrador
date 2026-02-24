<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function security_key_bytes(): string
{
    $raw = env_value('APP_KEY', 'noc-orquestrador-local-key-change-me');
    return hash('sha256', $raw, true);
}

function encrypt_secret(string $plainText): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipherText = openssl_encrypt(
        $plainText,
        'aes-256-gcm',
        security_key_bytes(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if (!is_string($cipherText)) {
        throw new RuntimeException('Falha ao criptografar segredo.');
    }

    return base64_encode($iv . $tag . $cipherText);
}

function decrypt_secret(string $encodedSecret): string
{
    $binary = base64_decode($encodedSecret, true);
    if ($binary === false || strlen($binary) < 29) {
        throw new RuntimeException('Segredo invalido.');
    }

    $iv = substr($binary, 0, 12);
    $tag = substr($binary, 12, 16);
    $cipherText = substr($binary, 28);

    $plainText = openssl_decrypt(
        $cipherText,
        'aes-256-gcm',
        security_key_bytes(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if (!is_string($plainText)) {
        throw new RuntimeException('Falha ao descriptografar segredo.');
    }

    return $plainText;
}
