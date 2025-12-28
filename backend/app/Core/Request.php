<?php
namespace App\Core;

class Request {
    public static function body()
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $raw = file_get_contents('php://input');
        // Avoid reading php://stdin when serving HTTP requests (cli-server),
        // as it can block indefinitely and stall the whole server.
        if (($raw === '' || $raw === false) && PHP_SAPI === 'cli') {
            $raw = file_get_contents('php://stdin');
        }

        if ($raw === false) {
            $raw = '';
        }

        $rawTrimmed = trim($raw);

        if ($rawTrimmed === '') {
            return !empty($_POST) ? $_POST : [];
        }

        // JSON
        if (stripos($contentType, 'application/json') !== false || str_starts_with($rawTrimmed, '{') || str_starts_with($rawTrimmed, '[')) {
            $data = json_decode($rawTrimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }

            // Common when passing JSON with backslashes from shells: {\"k\":\"v\"}
            $unescaped = stripslashes($rawTrimmed);
            if ($unescaped !== $rawTrimmed) {
                $data2 = json_decode($unescaped, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data2)) {
                    return $data2;
                }
            }
        }

        // Form URL Encoded (or unknown content-type)
        $form = [];
        parse_str($rawTrimmed, $form);
        if (is_array($form) && !empty($form)) {
            return $form;
        }

        return [];
    }
}
