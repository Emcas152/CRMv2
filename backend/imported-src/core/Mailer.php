<?php
/**
 * Clase para envío de emails
 */

class Mailer {
    private $config;
    
    public function __construct() {
        $this->config = require __DIR__ . '/../config/app.php';
    }
    
    /**
     * Enviar email usando PHP mail() o SMTP
     */
    public function send($to, $subject, $body, $isHtml = true) {
        $headers = [];
        
        // From
        $from = $this->config['mail_from_address'] ?? 'noreply@crm.com';
        $fromName = $this->config['mail_from_name'] ?? 'CRM Spa Médico';
        $headers[] = "From: {$fromName} <{$from}>";
        $headers[] = "Reply-To: {$from}";
        
        // Content type
        if ($isHtml) {
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-Type: text/html; charset=UTF-8";
        } else {
            $headers[] = "Content-Type: text/plain; charset=UTF-8";
        }
        
        // Additional headers
        $headers[] = "X-Mailer: PHP/" . phpversion();
        
        // Intentar enviar
        $sent = mail($to, $subject, $body, implode("\r\n", $headers));
        
        if (!$sent) {
            error_log("Failed to send email to {$to}. Subject: {$subject}");
            return false;
        }
        
        return true;
    }
    
    /**
     * Enviar recordatorio de cita
     */
    public function sendAppointmentReminder($appointment, $patient, $staffMember = null) {
        $subject = "Recordatorio de Cita - " . $this->config['app_name'];
        
        // Formatear fecha y hora
        $date = date('d/m/Y', strtotime($appointment['appointment_date']));
        $time = date('H:i', strtotime($appointment['appointment_time']));
        
        $staffName = $staffMember ? $staffMember['name'] : 'Nuestro equipo';
        
        // Template HTML
        $body = $this->getAppointmentReminderTemplate([
            'patient_name' => $patient['name'],
            'date' => $date,
            'time' => $time,
            'service' => $appointment['service'],
            'staff_name' => $staffName,
            'notes' => $appointment['notes'] ?? '',
            'clinic_name' => $this->config['app_name'] ?? 'CRM Spa Médico',
            'clinic_phone' => $this->config['clinic_phone'] ?? '+502 1234-5678',
            'clinic_address' => $this->config['clinic_address'] ?? 'Guatemala',
        ]);
        
        return $this->send($patient['email'], $subject, $body, true);
    }
    
    /**
     * Send email using a template from database
     * @param string $templateName - name of template in email_templates table
     * @param string $to - recipient email
     * @param array $vars - associative array of variables to replace in template
     * @return bool
     */
    public function sendFromTemplate($templateName, $to, $vars = []) {
        try {
            $db = Database::getInstance();
            $template = $db->fetchOne('SELECT * FROM email_templates WHERE name = ?', [$templateName]);
            
            if (!$template) {
                error_log("Email template '{$templateName}' not found");
                return false;
            }
            
            // Replace variables in subject and body
            $subject = $this->replaceVars($template['subject'], $vars);
            $body = $this->replaceVars($template['body'], $vars);
            
            return $this->send($to, $subject, $body, (bool)$template['is_html']);
        } catch (Exception $e) {
            error_log('sendFromTemplate error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Replace {{variable}} placeholders with actual values
     */
    private function replaceVars($text, $vars) {
        foreach ($vars as $key => $value) {
            $text = str_replace("{{{$key}}}", $value, $text);
        }
        return $text;
    }
    
    /**
     * Template HTML para recordatorio de cita
     */
    private function getAppointmentReminderTemplate($data) {
        return "
<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Recordatorio de Cita</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .content {
            padding: 30px;
        }
        .appointment-box {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .appointment-box h2 {
            margin-top: 0;
            color: #667eea;
            font-size: 18px;
        }
        .detail-row {
            display: flex;
            margin: 10px 0;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            width: 120px;
            color: #666;
        }
        .detail-value {
            flex: 1;
            color: #333;
        }
        .notes {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .notes h3 {
            margin-top: 0;
            color: #856404;
            font-size: 16px;
        }
        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            font-size: 14px;
            color: #666;
        }
        .footer strong {
            color: #333;
            display: block;
            margin-bottom: 5px;
        }
        .button {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🗓️ Recordatorio de Cita</h1>
        </div>
        
        <div class='content'>
            <p>Hola <strong>{$data['patient_name']}</strong>,</p>
            
            <p>Este es un recordatorio de tu cita programada:</p>
            
            <div class='appointment-box'>
                <h2>Detalles de la Cita</h2>
                
                <div class='detail-row'>
                    <div class='detail-label'>📅 Fecha:</div>
                    <div class='detail-value'><strong>{$data['date']}</strong></div>
                </div>
                
                <div class='detail-row'>
                    <div class='detail-label'>🕐 Hora:</div>
                    <div class='detail-value'><strong>{$data['time']}</strong></div>
                </div>
                
                <div class='detail-row'>
                    <div class='detail-label'>💆 Servicio:</div>
                    <div class='detail-value'>{$data['service']}</div>
                </div>
                
                <div class='detail-row'>
                    <div class='detail-label'>👨‍⚕️ Atendido por:</div>
                    <div class='detail-value'>{$data['staff_name']}</div>
                </div>
            </div>
            
            " . (!empty($data['notes']) ? "
            <div class='notes'>
                <h3>📝 Notas Importantes</h3>
                <p>{$data['notes']}</p>
            </div>
            " : "") . "
            
            <p>Por favor, llega <strong>10 minutos antes</strong> de tu cita.</p>
            
            <p>Si necesitas cancelar o reprogramar, contáctanos lo antes posible.</p>
        </div>
        
        <div class='footer'>
            <strong>{$data['clinic_name']}</strong>
            <p>📞 {$data['clinic_phone']}</p>
            <p>📍 {$data['clinic_address']}</p>
            <p style='margin-top: 15px; font-size: 12px; color: #999;'>
                Este es un mensaje automático, por favor no responder a este correo.
            </p>
        </div>
    </div>
</body>
</html>
        ";
    }
}
