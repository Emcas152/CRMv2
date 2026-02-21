<?php
namespace App\Core;

class Pdf
{
    private static function tryRequireAutoload(): void
    {
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    public static function outputFromHtml(string $html, string $filename, bool $download = false): void
    {
        self::tryRequireAutoload();

        if (class_exists('Dompdf\\Dompdf')) {
            $dompdf = new \Dompdf\Dompdf([
                    'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
            ]);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $disposition = $download ? 'attachment' : 'inline';
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . $disposition . '; filename="' . self::sanitizeFilename($filename) . '"');
            echo $dompdf->output();
            exit;
        }

        // Fallback: printable HTML (allows user to print-to-PDF from browser)
        $disposition = $download ? 'attachment' : 'inline';
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: ' . $disposition . '; filename="' . self::sanitizeFilename(preg_replace('/\\.pdf$/i', '.html', $filename)) . '"');
        echo $html;
        exit;
    }

    private static function sanitizeFilename(string $filename): string
    {
        $filename = trim($filename);
        if ($filename === '') {
            return 'document.pdf';
        }

        // Replace problematic characters
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? 'document.pdf';

        if (!preg_match('/\\.pdf$/i', $filename)) {
            $filename .= '.pdf';
        }

        return $filename;
    }
}
