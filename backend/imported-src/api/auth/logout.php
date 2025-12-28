<?php
/**
 * Endpoint de Logout
 */

Auth::requireAuth();

// En JWT no hay logout real del lado del servidor
// El cliente debe eliminar el token

Response::success(null, 'Sesión cerrada exitosamente');
