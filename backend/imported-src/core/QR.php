<?php
/**
 * Utility simple para generar identificadores únicos para QR
 */
class QR
{
    public static function generateCode()
    {
        // Genera un UUID v4
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function qrImageUrl($code)
    {
        // Devuelve una URL externa que genera el QR (Google Chart API compatible)
        $data = urlencode($code);
        return "https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl={$data}&chld=L|1";
    }
}
