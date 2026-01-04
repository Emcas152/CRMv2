<?php
// Core (needed for fallback JSON responses)
require_once __DIR__ . '/../app/Core/helpers.php';
require_once __DIR__ . '/../app/Core/Response.php';

// Load controllers (no PSR-4 autoloader configured)
require_once __DIR__ . '/../app/Controllers/AuthController.php';
require_once __DIR__ . '/../app/Controllers/ClienteController.php';
require_once __DIR__ . '/../app/Controllers/PatientsController.php';
require_once __DIR__ . '/../app/Controllers/ProductsController.php';
require_once __DIR__ . '/../app/Controllers/DocumentsController.php';
require_once __DIR__ . '/../app/Controllers/AppointmentsController.php';
require_once __DIR__ . '/../app/Controllers/SalesController.php';
require_once __DIR__ . '/../app/Controllers/UsersController.php';
require_once __DIR__ . '/../app/Controllers/EmailTemplatesController.php';
require_once __DIR__ . '/../app/Controllers/ProfileController.php';
require_once __DIR__ . '/../app/Controllers/DashboardController.php';
require_once __DIR__ . '/../app/Controllers/QrController.php';
require_once __DIR__ . '/../app/Controllers/StaffMembersController.php';
require_once __DIR__ . '/../app/Controllers/UpdatesController.php';
require_once __DIR__ . '/../app/Controllers/ConversationsController.php';
require_once __DIR__ . '/../app/Controllers/TasksController.php';
require_once __DIR__ . '/../app/Controllers/CommentsController.php';
require_once __DIR__ . '/../app/Controllers/ReportController.php';
require_once __DIR__ . '/../app/Controllers/TwoFactorController.php';

use App\Controllers\AuthController;
use App\Controllers\ClienteController;
use App\Controllers\PatientsController;
use App\Controllers\ProductsController;
use App\Controllers\DocumentsController;
use App\Controllers\AppointmentsController;
use App\Controllers\SalesController;
use App\Controllers\UsersController;
use App\Controllers\EmailTemplatesController;
use App\Controllers\ProfileController;
use App\Controllers\DashboardController;
use App\Controllers\QrController;
use App\Controllers\StaffMembersController;
use App\Controllers\UpdatesController;
use App\Controllers\ConversationsController;
use App\Controllers\TasksController;
use App\Controllers\ReportController;
use App\Controllers\CommentsController;
use App\Controllers\TwoFactorController;

$base = '/api/v1';

// Auth
if ($uri === "$base/auth/login" && $method === 'POST') {
    (new AuthController())->login();
}

if ($uri === "$base/auth/verify-2fa" && $method === 'POST') {
    (new AuthController())->verify2FA();
}

// 2FA Management (require authentication)
if ($uri === "$base/2fa/status" && $method === 'GET') {
    (new TwoFactorController())->getStatus();
}

if ($uri === "$base/2fa/methods" && $method === 'GET') {
    (new TwoFactorController())->getMethods();
}

if ($uri === "$base/2fa/enable" && $method === 'POST') {
    (new TwoFactorController())->enable();
}

if ($uri === "$base/2fa/disable" && $method === 'POST') {
    (new TwoFactorController())->disable();
}

if ($uri === "$base/2fa/test" && $method === 'POST') {
    (new TwoFactorController())->testCode();
}

if ($uri === "$base/2fa/regenerate-backup-codes" && $method === 'POST') {
    (new TwoFactorController())->regenerateBackupCodes();
}

if ($uri === "$base/auth/register" && $method === 'POST') {
    (new AuthController())->register();
}

if ($uri === "$base/auth/me" && $method === 'GET') {
    (new AuthController())->me();
}

if ($uri === "$base/auth/logout" && $method === 'POST') {
    (new AuthController())->logout();
}

// Auth extras
if ($uri === "$base/auth/verify-email" && in_array($method, ['GET', 'POST'], true)) {
    (new AuthController())->verifyEmail();
}

if ($uri === "$base/auth/debug-token" && $method === 'GET') {
    (new AuthController())->debugToken();
}

// Back-compat (original endpoints lived at /verify-email and /debug-token)
if ($uri === "$base/verify-email" && in_array($method, ['GET', 'POST'], true)) {
    (new AuthController())->verifyEmail();
}

if ($uri === "$base/debug-token" && $method === 'GET') {
    (new AuthController())->debugToken();
}

// Clientes (legacy)
if ($uri === "$base/clientes" && $method === 'GET') {
    (new ClienteController())->index();
}

// Helper to build regex with base
$baseQuoted = preg_quote($base, '#');

// Patients
if (preg_match("#^$baseQuoted/patients(?:/(\d+))?(?:/([a-z-]+))?$#", $uri, $m)) {
    $id = $m[1] ?? null;
    $action = $m[2] ?? null;
    (new PatientsController())->handle($id, $action);
}

// Products
if (preg_match("#^$baseQuoted/products(?:/(\d+))?(?:/([a-z-]+))?$#", $uri, $m)) {
    $id = $m[1] ?? null;
    $action = $m[2] ?? null;
    (new ProductsController())->handle($id, $action);
}

// Documents
if (preg_match("#^$baseQuoted/documents(?:/(\d+))?(?:/([a-z-]+))?$#", $uri, $m)) {
    $id = $m[1] ?? null;
    $action = $m[2] ?? null;
    (new DocumentsController())->handle($id, $action);
}

// Appointments
if (preg_match("#^$baseQuoted/appointments(?:/(\d+))?(?:/([a-z-]+))?$#", $uri, $m)) {
    $id = $m[1] ?? null;
    $action = $m[2] ?? null;
    (new AppointmentsController())->handle($id, $action);
}

// Staff members
if (preg_match("#^$baseQuoted/staff-members(?:/(\d+))?$#", $uri, $m)) {
    $id = $m[1] ?? null;
    (new StaffMembersController())->handle($id);
}

// Sales
if (preg_match("#^$baseQuoted/sales(?:/(\d+))?$#", $uri, $m)) {
    $id = $m[1] ?? null;
    (new SalesController())->handle($id);
}

// Users
if (preg_match("#^$baseQuoted/users(?:/(\d+))?$#", $uri, $m)) {
    $id = $m[1] ?? null;
    (new UsersController())->handle($id);
}

// Email templates
if (preg_match("#^$baseQuoted/email-templates(?:/(\d+))?$#", $uri, $m)) {
    $id = $m[1] ?? null;
    (new EmailTemplatesController())->handle($id);
}

// Profile
if (preg_match("#^$baseQuoted/profile(?:/([a-z-]+))?$#", $uri, $m)) {
    $action = $m[1] ?? null;
    (new ProfileController())->handle($action);
}

// Dashboard
if ($uri === "$base/dashboard/stats" && $method === 'GET') {
    (new DashboardController())->stats();
}

if ($uri === "$base/dashboard/debug-stats" && $method === 'GET') {
    (new DashboardController())->debugStats();
}

// QR
if (preg_match("#^$baseQuoted/qr(?:/([a-z-]+))?$#", $uri, $m)) {
    $action = $m[1] ?? null;
    (new QrController())->handle($action);
}

// Updates
if (preg_match("#^$baseQuoted/updates(?:/(\\d+))?(?:/([a-z-]+))?$#", $uri, $m)) {
    $id = $m[1] ?? null;
    $action = $m[2] ?? null;
    (new UpdatesController())->handle($id, $action);
}

// Conversations + messages
if (preg_match("#^$baseQuoted/conversations(?:/(\\d+))?(?:/([a-z-]+))?$#", $uri, $m)) {
    $id = $m[1] ?? null;
    $action = $m[2] ?? null;
    (new ConversationsController())->handle($id, $action);
}

// Tasks
if (preg_match("#^$baseQuoted/tasks(?:/(\\d+))?(?:/([a-z-]+))?$#", $uri, $m)) {
    $id = $m[1] ?? null;
    $action = $m[2] ?? null;
    (new TasksController())->handle($id, $action);
}

// Comments
if (preg_match("#^$baseQuoted/comments(?:/(\\d+))?(?:/([a-z-]+))?$#", $uri, $m)) {
    $id = $m[1] ?? null;
    $action = $m[2] ?? null;
    (new CommentsController())->handle($id, $action);
}

// Reports (Exports with audit logging and role restrictions)
if (preg_match("#^$baseQuoted/reports(?:/([a-z-]+))?(?:/([a-z]+))?$#", $uri, $m)) {
    $action = $m[1] ?? null;
    $format = $m[2] ?? null;
    (new ReportController())->handle($action, $format);
}

\App\Core\Response::error('Endpoint no encontrado', 404);
