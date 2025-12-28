<?php
namespace App\Core;

class JWT {
    public static function generate($payload) {
        $header = base64_encode(json_encode(['typ'=>'JWT','alg'=>'HS256']));
        $payload = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', "$header.$payload", $_ENV['JWT_SECRET'], true);
        return "$header.$payload.".base64_encode($signature);
    }

    public static function validate($token) {
        [$h,$p,$s] = explode('.', $token);
        $check = base64_encode(hash_hmac('sha256', "$h.$p", $_ENV['JWT_SECRET'], true));
        return $check === $s ? json_decode(base64_decode($p), true) : false;
    }
}
