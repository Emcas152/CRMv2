<?php
namespace App\Core;

class QR
{
    public static function generateCode(): string
    {
        // Short random token; stable enough for lookups and QR payload
        return bin2hex(random_bytes(16));
    }

    public static function qrImageUrl(string $code): ?string
    {
        // Keep it simple: provide the payload; frontend can render QR.
        // Avoid relying on third-party QR image services in the backend.
        return null;
    }
}
