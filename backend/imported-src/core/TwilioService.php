<?php
/**
 * Servicio para integración con Twilio WhatsApp
 */

class TwilioService
{
    private $accountSid;
    private $authToken;
    private $fromNumber;

    public function __construct()
    {
        $this->accountSid = getenv('TWILIO_ACCOUNT_SID');
        $this->authToken = getenv('TWILIO_AUTH_TOKEN');
        $this->fromNumber = getenv('TWILIO_WHATSAPP_FROM'); // Formato: whatsapp:+14155238886
    }

    /**
     * Envía un mensaje de WhatsApp
     * 
     * @param string $toNumber Número del destinatario (formato: +502XXXXXXXX)
     * @param string $message Mensaje a enviar
     * @return array Respuesta de la API de Twilio
     * @throws Exception Si hay error en el envío
     */
    public function sendWhatsAppMessage($toNumber, $message)
    {
        // Validar configuración
        if (empty($this->accountSid) || empty($this->authToken) || empty($this->fromNumber)) {
            throw new Exception('Credenciales de Twilio no configuradas. Por favor verifica las variables de entorno TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN y TWILIO_WHATSAPP_FROM.');
        }

        // Formatear número de destino (agregar prefijo whatsapp: si no lo tiene)
        $to = $this->formatWhatsAppNumber($toNumber);

        // Preparar datos para la API
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";
        
        $data = [
            'From' => $this->fromNumber,
            'To' => $to,
            'Body' => $message
        ];

        // Realizar petición a la API de Twilio
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_USERPWD, $this->accountSid . ':' . $this->authToken);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Para desarrollo local

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Verificar errores de cURL
        if ($error) {
            throw new Exception("Error de conexión con Twilio: {$error}");
        }

        // Parsear respuesta
        $result = json_decode($response, true);

        // Verificar errores de la API
        if ($httpCode !== 201) {
            $errorMessage = $result['message'] ?? 'Error desconocido al enviar WhatsApp';
            throw new Exception("Error de Twilio: {$errorMessage}");
        }

        return [
            'success' => true,
            'message_sid' => $result['sid'] ?? null,
            'status' => $result['status'] ?? 'sent',
            'to' => $to,
            'from' => $this->fromNumber
        ];
    }

    /**
     * Envía un recordatorio de cita por WhatsApp
     * 
     * @param array $appointment Datos de la cita
     * @param array $patient Datos del paciente
     * @param array $staffMember Datos del staff (opcional)
     * @return array Respuesta del envío
     * @throws Exception Si hay error
     */
    public function sendAppointmentReminder($appointment, $patient, $staffMember = null)
    {
        // Validar que el paciente tenga teléfono
        if (empty($patient['phone'])) {
            throw new Exception('El paciente no tiene número de teléfono registrado.');
        }

        // Formatear fecha y hora
        $appointmentDate = date('d/m/Y', strtotime($appointment['appointment_date']));
        $appointmentTime = date('h:i A', strtotime($appointment['appointment_time']));
        
        // Obtener nombre del doctor/staff
        $staffName = $staffMember ? $staffMember['name'] : 'Nuestro equipo';

        // Construir mensaje
        $message = $this->buildReminderMessage([
            'patient_name' => $patient['name'],
            'date' => $appointmentDate,
            'time' => $appointmentTime,
            'service' => $appointment['service'] ?? 'Consulta',
            'staff_name' => $staffName,
            'notes' => $appointment['notes'] ?? null
        ]);

        // Enviar mensaje
        return $this->sendWhatsAppMessage($patient['phone'], $message);
    }

    /**
     * Construye el mensaje de recordatorio
     * 
     * @param array $data Datos para el mensaje
     * @return string Mensaje formateado
     */
    private function buildReminderMessage($data)
    {
        $config = require __DIR__ . '/../config/app.php';
        $clinicName = $config['app_name'] ?? 'CRM Spa Médico';
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

    /**
     * Formatea un número de teléfono para WhatsApp
     * 
     * @param string $number Número de teléfono
     * @return string Número formateado con prefijo whatsapp:
     */
    private function formatWhatsAppNumber($number)
    {
        // Eliminar espacios y caracteres especiales
        $clean = preg_replace('/[^0-9+]/', '', $number);

        // Si no tiene +, agregarlo (asumiendo formato internacional)
        if (substr($clean, 0, 1) !== '+') {
            // Si empieza con 00, reemplazar por +
            if (substr($clean, 0, 2) === '00') {
                $clean = '+' . substr($clean, 2);
            } else {
                // Asumir que falta el + al inicio
                $clean = '+' . $clean;
            }
        }

        // Agregar prefijo whatsapp: si no lo tiene
        if (strpos($clean, 'whatsapp:') !== 0) {
            $clean = 'whatsapp:' . $clean;
        }

        return $clean;
    }

    /**
     * Verifica la configuración de Twilio
     * 
     * @return array Estado de la configuración
     */
    public function testConnection()
    {
        $status = [
            'configured' => false,
            'account_sid' => !empty($this->accountSid),
            'auth_token' => !empty($this->authToken),
            'from_number' => !empty($this->fromNumber),
            'message' => ''
        ];

        if ($status['account_sid'] && $status['auth_token'] && $status['from_number']) {
            $status['configured'] = true;
            $status['message'] = 'Twilio está configurado correctamente.';
        } else {
            $missing = [];
            if (!$status['account_sid']) $missing[] = 'TWILIO_ACCOUNT_SID';
            if (!$status['auth_token']) $missing[] = 'TWILIO_AUTH_TOKEN';
            if (!$status['from_number']) $missing[] = 'TWILIO_WHATSAPP_FROM';
            
            $status['message'] = 'Faltan las siguientes variables de entorno: ' . implode(', ', $missing);
        }

        return $status;
    }
}
