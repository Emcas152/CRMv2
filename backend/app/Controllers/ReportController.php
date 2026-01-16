<?php
namespace App\Controllers;

use App\Core\Request;

class ReportController
{
    protected static function initCore()
    {
        require_once __DIR__ . '/../Core/helpers.php';
        require_once __DIR__ . '/../Core/Request.php';
        require_once __DIR__ . '/../Core/Database.php';
        require_once __DIR__ . '/../Core/Auth.php';
        require_once __DIR__ . '/../Core/Response.php';
        require_once __DIR__ . '/../Core/Audit.php';
        require_once __DIR__ . '/../Core/ErrorHandler.php';
    }

    public function handle($action = null, $format = null)
    {
        self::initCore();

        try {
            \App\Core\Auth::requireAuth();
            $user = \App\Core\Auth::getCurrentUser();

            // Exportación restringida a roles operacionales
            \App\Core\Auth::requireAnyRole(
                ['superadmin', 'admin'],
                'No tienes permisos para exportar datos'
            );

            $method = $_SERVER['REQUEST_METHOD'];

            if ($method === 'GET' && $action === 'patients') {
                return $this->exportPatients($user, $format ?? 'pdf');
            }

            if ($method === 'GET' && $action === 'sales') {
                return $this->exportSales($user, $format ?? 'pdf');
            }

            if ($method === 'GET' && $action === 'appointments') {
                return $this->exportAppointments($user, $format ?? 'pdf');
            }

            if ($method === 'GET' && $action === 'products') {
                return $this->exportProducts($user, $format ?? 'pdf');
            }

            \App\Core\Response::error('Endpoint no encontrado', 404);
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::handle($e);
        }
    }

    /**
     * Exportar pacientes a PDF
     */
    private function exportPatients($user, $format)
    {
        $db = \App\Core\Database::getInstance();

        // Validar formato
        if (!in_array($format, ['pdf', 'csv'], true)) {
            \App\Core\Response::error('Formato no soportado', 400);
        }

        // Registrar exportación en auditoría
        \App\Core\Audit::log('export_patients', 'patients', null, [
            'format' => $format,
            'exported_by' => $user['user_id'],
            'user_email' => $user['email']
        ]);

        try {
            // Obtener pacientes (sin datos muy sensibles)
            $patients = $db->fetchAll(
                'SELECT id, name, email, phone, birthday, created_at FROM patients ORDER BY created_at DESC',
                []
            );

            if ($format === 'pdf') {
                return $this->generatePDFReport(
                    'Reporte de Pacientes',
                    ['ID', 'Nombre', 'Email', 'Teléfono', 'Cumpleaños', 'Creado'],
                    $patients,
                    $user
                );
            } else {
                return $this->generateCSVReport(
                    'patients_' . date('Y-m-d_H-i-s') . '.csv',
                    ['ID', 'Nombre', 'Email', 'Teléfono', 'Cumpleaños', 'Creado'],
                    $patients,
                    $user
                );
            }
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al exportar pacientes', $e);
        }
    }

    /**
     * Exportar ventas/compras a PDF
     */
    private function exportSales($user, $format)
    {
        $db = \App\Core\Database::getInstance();

        if (!in_array($format, ['pdf', 'csv'], true)) {
            \App\Core\Response::error('Formato no soportado', 400);
        }

        \App\Core\Audit::log('export_sales', 'sales', null, [
            'format' => $format,
            'exported_by' => $user['user_id']
        ]);

        try {
            $sales = $db->fetchAll(
                'SELECT s.id, s.total, s.payment_method, s.status, 
                        p.name as patient_name, s.created_at
                 FROM sales s
                 LEFT JOIN patients p ON s.patient_id = p.id
                 ORDER BY s.created_at DESC
                 LIMIT 500',
                []
            );

            if ($format === 'pdf') {
                return $this->generatePDFReport(
                    'Reporte de Ventas',
                    ['ID', 'Total', 'Método Pago', 'Estado', 'Paciente', 'Fecha'],
                    $sales,
                    $user
                );
            } else {
                return $this->generateCSVReport(
                    'sales_' . date('Y-m-d_H-i-s') . '.csv',
                    ['ID', 'Total', 'Método Pago', 'Estado', 'Paciente', 'Fecha'],
                    $sales,
                    $user
                );
            }
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al exportar ventas', $e);
        }
    }

    /**
     * Exportar citas a PDF
     */
    private function exportAppointments($user, $format)
    {
        $db = \App\Core\Database::getInstance();

        if (!in_array($format, ['pdf', 'csv'], true)) {
            \App\Core\Response::error('Formato no soportado', 400);
        }

        \App\Core\Audit::log('export_appointments', 'appointments', null, [
            'format' => $format,
            'exported_by' => $user['user_id']
        ]);

        try {
            $appointments = $db->fetchAll(
                'SELECT a.id, a.service, a.status, a.appointment_date, 
                        p.name as patient_name, sm.name as doctor_name
                 FROM appointments a
                 LEFT JOIN patients p ON a.patient_id = p.id
                 LEFT JOIN staff_members sm ON a.staff_member_id = sm.id
                 ORDER BY a.appointment_date DESC
                 LIMIT 500',
                []
            );

            if ($format === 'pdf') {
                return $this->generatePDFReport(
                    'Reporte de Citas',
                    ['ID', 'Servicio', 'Estado', 'Fecha', 'Paciente', 'Doctor'],
                    $appointments,
                    $user
                );
            } else {
                return $this->generateCSVReport(
                    'appointments_' . date('Y-m-d_H-i-s') . '.csv',
                    ['ID', 'Servicio', 'Estado', 'Fecha', 'Paciente', 'Doctor'],
                    $appointments,
                    $user
                );
            }
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al exportar citas', $e);
        }
    }

    /**
     * Exportar productos a PDF
     */
    private function exportProducts($user, $format)
    {
        $db = \App\Core\Database::getInstance();

        if (!in_array($format, ['pdf', 'csv'], true)) {
            \App\Core\Response::error('Formato no soportado', 400);
        }

        \App\Core\Audit::log('export_products', 'products', null, [
            'format' => $format,
            'exported_by' => $user['user_id']
        ]);

        try {
            // Solo admin/superadmin pueden ver costos
            $showCost = in_array($user['role'], ['superadmin', 'admin'], true);

            $columns = 'id, name, sku, type, stock, active';
            if ($showCost) {
                $columns .= ', price';
            }

            $products = $db->fetchAll(
                "SELECT $columns FROM products ORDER BY name ASC",
                []
            );

            $headers = ['ID', 'Nombre', 'SKU', 'Tipo', 'Stock', 'Activo'];
            if ($showCost) {
                $headers[] = 'Precio';
            }

            if ($format === 'pdf') {
                return $this->generatePDFReport(
                    'Reporte de Productos',
                    $headers,
                    $products,
                    $user
                );
            } else {
                return $this->generateCSVReport(
                    'products_' . date('Y-m-d_H-i-s') . '.csv',
                    $headers,
                    $products,
                    $user
                );
            }
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al exportar productos', $e);
        }
    }

    /**
     * Generar reporte en PDF con marca de agua
     */
    private function generatePDFReport($title, $headers, $data, $user)
    {
        // Usar TCPDF si está disponible, o fallback a HTML simple
        // Para MVP, usar HTML + navegador para convertir a PDF

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }
        .watermark {
            position: fixed;
            opacity: 0.1;
            font-size: 60px;
            transform: rotate(-45deg);
            top: 50%;
            left: 50%;
            z-index: -1;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        .header h1 {
            margin: 0;
            color: #333;
        }
        .metadata {
            font-size: 12px;
            color: #666;
            margin: 10px 0;
            padding: 10px;
            background: #eee;
            border-radius: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: white;
        }
        th {
            background-color: #007bff;
            color: white;
            padding: 12px;
            text-align: left;
            border: 1px solid #ddd;
        }
        td {
            padding: 10px;
            border: 1px solid #ddd;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        tr:hover {
            background-color: #f0f0f0;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 11px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="watermark">DOCUMENTO CONFIDENCIAL - ' . strtoupper($user['name']) . '</div>
    
    <div class="header">
        <h1>' . htmlspecialchars($title) . '</h1>
    </div>
    
    <div class="metadata">
        <strong>Exportado por:</strong> ' . htmlspecialchars($user['name']) . ' (' . htmlspecialchars($user['email']) . ')<br>
        <strong>Fecha:</strong> ' . date('d/m/Y H:i:s') . '<br>
        <strong>Rol:</strong> ' . htmlspecialchars($user['role']) . '
    </div>
    
    <table>
        <thead>
            <tr>';

        foreach ($headers as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }

        $html .= '</tr>
        </thead>
        <tbody>';

        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($headers as $idx => $header) {
                $value = '';
                if (is_array($row)) {
                    $value = array_values($row)[$idx] ?? '';
                } else {
                    $value = $row->$idx ?? '';
                }
                $html .= '<td>' . htmlspecialchars((string)$value) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody>
    </table>
    
    <div class="footer">
        <p>Este documento contiene información confidencial y está protegido por auditoría.<br>
        Total registros: ' . count($data) . '</p>
    </div>
</body>
</html>';

        // Devolver HTML para que el navegador lo convierta a PDF
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="' . date('Y-m-d') . '_' . str_replace(' ', '_', $title) . '.html"');
        echo $html;
        exit;
    }

    /**
     * Generar reporte en CSV
     */
    private function generateCSVReport($filename, $headers, $data, $user)
    {
        $output = fopen('php://output', 'w');

        // Headers HTTP
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        // BOM para UTF-8 (Excel lo lee correctamente)
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Metadatos
        fputcsv($output, ['Exportado por:', $user['name'] . ' (' . $user['email'] . ')']);
        fputcsv($output, ['Fecha:', date('d/m/Y H:i:s')]);
        fputcsv($output, ['Rol:', $user['role']]);
        fputcsv($output, []);

        // Headers
        fputcsv($output, $headers);

        // Datos
        foreach ($data as $row) {
            if (is_array($row)) {
                fputcsv($output, array_values($row));
            } else {
                fputcsv($output, (array)$row);
            }
        }

        fclose($output);
        exit;
    }
}
