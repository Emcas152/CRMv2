<?php
namespace App\Core;

class TwilioService
{
    private string $accountSid;
    private string $authToken;
    private string $fromNumber;

    public function __construct()
    {
        $this->accountSid = (string) getenv('TWILIO_ACCOUNT_SID');
        $this->authToken = (string) getenv('TWILIO_AUTH_TOKEN');
        $this->fromNumber = (string) getenv('TWILIO_WHATSAPP_FROM');
    }

    public function sendWhatsAppMessage($toNumber, $message)
    {
        if ($this->accountSid === '' || $this->authToken === '' || $this->fromNumber === '') {
            throw new \Exception('Credenciales de Twilio no configuradas. Verifica TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN y TWILIO_WHATSAPP_FROM.');
        }

        $to = $this->formatWhatsAppNumber($toNumber);

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";
        $data = [
            'From' => $this->fromNumber,
            'To' => $to,
            'Body' => $message,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_USERPWD, $this->accountSid . ':' . $this->authToken);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("Error de conexión con Twilio: {$error}");
        }

        $result = json_decode((string) $response, true);

        if ($httpCode !== 201) {
            $errorMessage = $result['message'] ?? 'Error desconocido al enviar WhatsApp';
            throw new \Exception("Error de Twilio: {$errorMessage}");
        }

        return [
            'success' => true,
            'message_sid' => $result['sid'] ?? null,
            'status' => $result['status'] ?? 'sent',
            'to' => $to,
            'from' => $this->fromNumber,
        ];
    }

    public function sendAppointmentReminder($appointment, $patient, $staffMember = null)
    {
        if (empty($patient['phone'])) {
            throw new \Exception('El paciente no tiene número de teléfono registrado.');
        }

        $appointmentDate = date('d/m/Y', strtotime($appointment['appointment_date']));
        $appointmentTime = date('h:i A', strtotime($appointment['appointment_time']));
        $staffName = $staffMember ? ($staffMember['name'] ?? 'Nuestro equipo') : 'Nuestro equipo';

        $message = $this->buildReminderMessage([
            'patient_name' => $patient['name'] ?? '',
            'date' => $appointmentDate,
            'time' => $appointmentTime,
            'service' => $appointment['service'] ?? 'Consulta',
            'staff_name' => $staffName,
            'notes' => $appointment['notes'] ?? null,
        ]);

        return $this->sendWhatsAppMessage($patient['phone'], $message);
    }

    private function buildReminderMessage($data)
    {
        $config = require __DIR__ . '/../../config/app.php';
        $clinicName = $config['app_name'] ?? 'CRM';
        $clinicPhone = $config['clinic_phone'] ?? '';

        $message = "🌟 *{$clinicName}* 🌟\n\n";
        $message .= "Hola *{$data['patient_name']}*,\n\n";
        $message .= "Te recordamos tu cita:\n\n";
        $message .= "📅 *Fecha:* {$data['date']}\n";
        $message .= "🕐 *Hora:* {$data['time']}\n";
        $message .= "💆 *Servicio:* {$data['service']}\n";
        $message .= "👨‍⚕️ *Atenderá:* {$data['staff_name']}\n";

        if (!empty($data['notes'])) {
            $message .= "\n📝 *Notas:* {$data['notes']}\n";
        }

        $message .= "\n✨ *Recomendaciones:*\n";
        $message .= "• Llega 10 minutos antes\n";
        $message .= "• Trae ropa cómoda\n";
        $message .= "• Si necesitas cancelar, avísanos con anticipación\n";

        if (!empty($clinicPhone)) {
            $message .= "\n📞 *Contacto:* {$clinicPhone}\n";
        }

        $message .= "\n¡Te esperamos! 💙";

        return $message;
    }

    private function formatWhatsAppNumber($number)
    {
        $clean = preg_replace('/[^0-9+]/', '', (string) $number);

        if (substr($clean, 0, 1) !== '+') {
            if (substr($clean, 0, 2) === '00') {
                $clean = '+' . substr($clean, 2);
            } else {
                $clean = '+' . $clean;
            }
        }

        if (strpos($clean, 'whatsapp:') !== 0) {
            $clean = 'whatsapp:' . $clean;
        }

        return $clean;
    }

    public function testConnection()
    {
        $status = [
            'configured' => false,
            'account_sid' => $this->accountSid !== '',
            'auth_token' => $this->authToken !== '',
            'from_number' => $this->fromNumber !== '',
            'message' => '',
        ];

        if ($status['account_sid'] && $status['auth_token'] && $status['from_number']) {
            $status['configured'] = true;
            $status['message'] = 'Twilio está configurado correctamente.';
        } else {
            $missing = [];
            if (!$status['account_sid']) { $missing[] = 'TWILIO_ACCOUNT_SID'; }
            if (!$status['auth_token']) { $missing[] = 'TWILIO_AUTH_TOKEN'; }
            if (!$status['from_number']) { $missing[] = 'TWILIO_WHATSAPP_FROM'; }
            $status['message'] = 'Faltan variables de entorno: ' . implode(', ', $missing);
        }

        return $status;
    }
}
