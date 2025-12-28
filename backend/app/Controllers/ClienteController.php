<?php
namespace App\Controllers;

use App\Core\Response;

class ClienteController {
    public function index() {
        Response::json([['id'=>1,'nombre'=>'Cliente Demo']]);
    }
}
