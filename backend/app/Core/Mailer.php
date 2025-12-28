<?php
namespace App\Core;

class Mailer {
    private $config;
    
    public function __construct() {
        $this->config = require __DIR__ . '/../../config/app.php';
    }
    
    public function send($to, $subject, $body, $isHtml = true) {
        $headers = [];
        $from = $this->config['mail_from_address'] ?? 'noreply@crm.com';
        $fromName = $this->config['mail_from_name'] ?? 'CRM';
        $headers[] = "From: {$fromName} <{$from}>";
        $headers[] = "Reply-To: {$from}";
        if ($isHtml) {
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-Type: text/html; charset=UTF-8";
        } else {
            $headers[] = "Content-Type: text/plain; charset=UTF-8";
        }
        $headers[] = "X-Mailer: PHP/" . phpversion();
        $sent = mail($to, $subject, $body, implode("\r\n", $headers));
        if (!$sent) {
            error_log("Failed to send email to {$to}. Subject: {$subject}");
            return false;
        }
        return true;
    }

    public function sendAppointmentReminder($appointment, $patient, $staffMember = null)
    {
        $subject = 'Recordatorio de Cita - ' . ($this->config['app_name'] ?? 'CRM');

        $date = date('d/m/Y', strtotime($appointment['appointment_date']));
        $time = date('H:i', strtotime($appointment['appointment_time']));
        $staffName = $staffMember ? ($staffMember['name'] ?? 'Nuestro equipo') : 'Nuestro equipo';

        $body = $this->getAppointmentReminderTemplate([
            'patient_name' => $patient['name'] ?? '',
            'date' => $date,
            'time' => $time,
            'service' => $appointment['service'] ?? 'Cita',
            'staff_name' => $staffName,
            'notes' => $appointment['notes'] ?? '',
            'clinic_name' => $this->config['app_name'] ?? 'CRM',
            'clinic_phone' => $this->config['clinic_phone'] ?? '',
            'clinic_address' => $this->config['clinic_address'] ?? '',
        ]);

        return $this->send($patient['email'], $subject, $body, true);
    }

    public function sendFromTemplate($templateName, $to, $vars = []) {
        try {
            $db = Database::getInstance();
            $template = $db->fetchOne('SELECT * FROM email_templates WHERE name = ?', [$templateName]);
            if (!$template) {
                error_log("Email template '{$templateName}' not found");
                return false;
            }
            $subject = $this->replaceVars($template['subject'], $vars);
            $body = $this->replaceVars($template['body'], $vars);
            return $this->send($to, $subject, $body, (bool)$template['is_html']);
        } catch (\Exception $e) {
            error_log('sendFromTemplate error: ' . $e->getMessage());
            return false;
        }
    }

    private function replaceVars($text, $vars) {
        foreach ($vars as $key => $value) {
            $text = str_replace("{{{$key}}}", $value, $text);
        }
        return $text;
    }

    private function getAppointmentReminderTemplate($data)
    {
        $notesHtml = '';
        if (!empty($data['notes'])) {
            $safeNotes = htmlspecialchars($data['notes']);
            $notesHtml = "
            <div class='notes'>
                <h3>📝 Notas Importantes</h3>
                <p>{$safeNotes}</p>
            </div>
            ";
        }

        $clinicName = htmlspecialchars($data['clinic_name'] ?? 'CRM');
        $clinicPhone = htmlspecialchars($data['clinic_phone'] ?? '');
        $clinicAddress = htmlspecialchars($data['clinic_address'] ?? '');
        $patientName = htmlspecialchars($data['patient_name'] ?? '');
        $date = htmlspecialchars($data['date'] ?? '');
        $time = htmlspecialchars($data['time'] ?? '');
        $service = htmlspecialchars($data['service'] ?? '');
        $staffName = htmlspecialchars($data['staff_name'] ?? '');

        return "
<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Recordatorio de Cita</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .appointment-box { background: #f8f9fa; border-left: 4px solid #667eea; padding: 20px; margin: 20px 0; border-radius: 5px; }
        .appointment-box h2 { margin-top: 0; color: #667eea; font-size: 18px; }
        .detail-row { display: flex; margin: 10px 0; padding: 8px 0; border-bottom: 1px solid #e0e0e0; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { font-weight: 600; width: 120px; color: #666; }
        .detail-value { flex: 1; color: #333; }
        .notes { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 5px; }
        .notes h3 { margin-top: 0; color: #856404; font-size: 16px; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 14px; color: #666; }
        .footer strong { color: #333; display: block; margin-bottom: 5px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🗓️ Recordatorio de Cita</h1>
        </div>

        <div class='content'>
            <p>Hola <strong>{$patientName}</strong>,</p>
            <p>Este es un recordatorio de tu cita programada:</p>

            <div class='appointment-box'>
                <h2>Detalles de la Cita</h2>

                <div class='detail-row'>
                    <div class='detail-label'>📅 Fecha:</div>
                    <div class='detail-value'><strong>{$date}</strong></div>
                </div>
                <div class='detail-row'>
                    <div class='detail-label'>🕐 Hora:</div>
                    <div class='detail-value'><strong>{$time}</strong></div>
                </div>
                <div class='detail-row'>
                    <div class='detail-label'>💆 Servicio:</div>
                    <div class='detail-value'>{$service}</div>
                </div>
                <div class='detail-row'>
                    <div class='detail-label'>👨‍⚕️ Atendido por:</div>
                    <div class='detail-value'>{$staffName}</div>
                </div>
            </div>

            {$notesHtml}

            <p>Por favor, llega <strong>10 minutos antes</strong> de tu cita.</p>
            <p>Si necesitas cancelar o reprogramar, contáctanos lo antes posible.</p>
        </div>

        <div class='footer'>
            <strong>{$clinicName}</strong>
            " . (!empty($clinicPhone) ? "<p>📞 {$clinicPhone}</p>" : "") . "
            " . (!empty($clinicAddress) ? "<p>📍 {$clinicAddress}</p>" : "") . "
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
