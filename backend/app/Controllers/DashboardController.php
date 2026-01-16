<?php
namespace App\Controllers;

use App\Core\Request;

class DashboardController
{
    protected static function initCore()
    {
        require_once __DIR__ . '/../Core/helpers.php';
        require_once __DIR__ . '/../Core/Request.php';
        require_once __DIR__ . '/../Core/Database.php';
        require_once __DIR__ . '/../Core/Auth.php';
        require_once __DIR__ . '/../Core/Response.php';
        require_once __DIR__ . '/../Core/ErrorHandler.php';
    }

    public function stats()
    {
        self::initCore();
        Request::body();

        try {
            $user = \App\Core\Auth::validateToken();
            if (!$user) {
                \App\Core\Response::unauthorized('Token inválido o expirado');
            }

            // CRM/ops roles only
            \App\Core\Auth::requireAnyRole(['superadmin', 'admin'], 'No tienes permisos para ver estadísticas');

            $db = \App\Core\Database::getInstance();
            $pdo = $db->getConnection();

            $tables = [];
            $stmt = $pdo->query('SHOW TABLES');
            while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }

            $hasSales = in_array('sales', $tables);
            $hasPatients = in_array('patients', $tables);
            $hasAppointments = in_array('appointments', $tables);
            $hasProducts = in_array('products', $tables);
            $hasStaffMembers = in_array('staff_members', $tables);
            // $hasStaffMembers reserved for future stats

            $currentMonth = date('Y-m-01');
            $previousMonth = date('Y-m-01', strtotime('-1 month'));
            $today = date('Y-m-d');

            $monthlyIncome = 0;
            $previousMonthIncome = 0;
            if ($hasSales) {
                $result = $db->fetchOne('SELECT COALESCE(SUM(total), 0) as total FROM sales WHERE created_at >= ?', [$currentMonth]);
                $monthlyIncome = $result ? $result['total'] : 0;

                $result = $db->fetchOne(
                    'SELECT COALESCE(SUM(total), 0) as total FROM sales WHERE created_at >= ? AND created_at < ?',
                    [$previousMonth, $currentMonth]
                );
                $previousMonthIncome = $result ? $result['total'] : 0;
            }

            $monthlyIncomeChange = $previousMonthIncome > 0
                ? (($monthlyIncome - $previousMonthIncome) / $previousMonthIncome) * 100
                : 0;

            $newPatientsMonth = 0;
            $previousMonthPatients = 0;
            if ($hasPatients) {
                $result = $db->fetchOne('SELECT COUNT(*) as count FROM patients WHERE created_at >= ?', [$currentMonth]);
                $newPatientsMonth = $result ? $result['count'] : 0;

                $result = $db->fetchOne(
                    'SELECT COUNT(*) as count FROM patients WHERE created_at >= ? AND created_at < ?',
                    [$previousMonth, $currentMonth]
                );
                $previousMonthPatients = $result ? $result['count'] : 0;
            }

            $newPatientsChange = $previousMonthPatients > 0
                ? (($newPatientsMonth - $previousMonthPatients) / $previousMonthPatients) * 100
                : 0;

            $averageTicket = 0;
            $previousAvgTicket = 0;
            if ($hasSales) {
                $result = $db->fetchOne('SELECT COALESCE(AVG(total), 0) as avg_total FROM sales WHERE created_at >= ?', [$currentMonth]);
                $averageTicket = $result ? $result['avg_total'] : 0;

                $result = $db->fetchOne(
                    'SELECT COALESCE(AVG(total), 0) as avg_total FROM sales WHERE created_at >= ? AND created_at < ?',
                    [$previousMonth, $currentMonth]
                );
                $previousAvgTicket = $result ? $result['avg_total'] : 0;
            }

            $averageTicketChange = $previousAvgTicket > 0
                ? (($averageTicket - $previousAvgTicket) / $previousAvgTicket) * 100
                : 0;

            $returnRate = 0;
            if ($hasPatients && $hasAppointments) {
                $result = $db->fetchOne('SELECT COUNT(*) as count FROM patients');
                $totalPatients = $result ? $result['count'] : 0;

                if ($totalPatients > 0) {
                    $result = $db->fetchOne(
                        'SELECT COUNT(DISTINCT patient_id) as count FROM appointments WHERE patient_id IN (SELECT patient_id FROM appointments GROUP BY patient_id HAVING COUNT(*) > 1)'
                    );
                    $returningPatients = $result ? $result['count'] : 0;
                    $returnRate = ($returningPatients / $totalPatients) * 100;
                }
            }

            $todayAppointments = 0;
            if ($hasAppointments) {
                $result = $db->fetchOne('SELECT COUNT(*) as count FROM appointments WHERE appointment_date = ?', [$today]);
                $todayAppointments = $result ? $result['count'] : 0;
            }

            $lowStockProducts = 0;
            if ($hasProducts) {
                $result = $db->fetchOne("SELECT COUNT(*) as count FROM products WHERE type = 'product' AND stock <= low_stock_alert AND active = 1");
                $lowStockProducts = $result ? $result['count'] : 0;
            }

            $upcomingAppointments = [];
            if ($hasAppointments && $hasPatients) {
                $upcomingAppointments = $db->fetchAll(
                    'SELECT a.*, p.name as patient_name
                     FROM appointments a
                     LEFT JOIN patients p ON a.patient_id = p.id
                     WHERE a.appointment_date BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
                     ORDER BY a.appointment_date, a.appointment_time
                     LIMIT 5',
                    [$today, $today]
                ) ?: [];
            }

            $monthlyPerformance = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = date('Y-m', strtotime("-$i months"));
                $monthStart = $month . '-01';
                $monthEnd = date('Y-m-t', strtotime($monthStart));

                $income = 0;
                $appointments = 0;

                if ($hasSales) {
                    $result = $db->fetchOne(
                        'SELECT COALESCE(SUM(total), 0) as total FROM sales WHERE DATE(created_at) BETWEEN ? AND ?',
                        [$monthStart, $monthEnd]
                    );
                    $income = $result ? $result['total'] : 0;
                }

                if ($hasAppointments) {
                    $result = $db->fetchOne(
                        'SELECT COUNT(*) as count FROM appointments WHERE appointment_date BETWEEN ? AND ?',
                        [$monthStart, $monthEnd]
                    );
                    $appointments = $result ? $result['count'] : 0;
                }

                $monthlyPerformance[] = [
                    'month' => date('M', strtotime($monthStart)),
                    'income' => floatval($income),
                    'appointments' => intval($appointments),
                ];
            }

            \App\Core\Response::json([
                'data' => [
                    'monthly_income' => floatval($monthlyIncome),
                    'monthly_income_change' => round($monthlyIncomeChange, 2),
                    'new_patients_month' => intval($newPatientsMonth),
                    'new_patients_change' => round($newPatientsChange, 2),
                    'average_ticket' => floatval($averageTicket),
                    'average_ticket_change' => round($averageTicketChange, 2),
                    'return_rate' => round($returnRate, 2),
                    'today_appointments' => intval($todayAppointments),
                    'low_stock_products' => intval($lowStockProducts),
                    'upcoming_appointments' => $upcomingAppointments,
                    'monthly_performance' => $monthlyPerformance,
                ],
            ]);
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::handle($e);
        }
    }

    public function debugStats()
    {
        self::initCore();
        Request::body();

        header('Content-Type: application/json; charset=utf-8');

        try {
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);
            $user = \App\Core\Auth::validateToken();

            if (!$authHeader) {
                \App\Core\Response::json(['error' => 'No Authorization header']);
            }

            if (!$user) {
                \App\Core\Response::json(['error' => 'Token validation failed']);
            }

            $db = \App\Core\Database::getInstance();
            $pdo = $db->getConnection();
            $stmt = $pdo->query('SHOW TABLES');
            $tables = [];
            while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }

            \App\Core\Response::json([
                'success' => true,
                'headers' => $headers,
                'user' => $user,
                'tables' => $tables,
            ]);
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::handle($e);
        }
    }
}

