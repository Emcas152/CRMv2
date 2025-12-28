<?php
/**
 * Servicio para integración con OpenAI API
 */

class OpenAIService {
    private $apiKey;
    private $apiUrl = 'https://api.openai.com/v1/chat/completions';
    private $model = 'gpt-3.5-turbo';
    
    public function __construct() {
        $this->apiKey = getenv('OPENAI_API_KEY');
        
        if (!$this->apiKey) {
            throw new Exception('OPENAI_API_KEY no configurada en .env');
        }
    }
    
    /**
     * Generar texto con GPT
     */
    public function generateText($prompt, $systemMessage = null, $temperature = 0.7, $maxTokens = 500) {
        $messages = [];
        
        if ($systemMessage) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemMessage
            ];
        }
        
        $messages[] = [
            'role' => 'user',
            'content' => $prompt
        ];
        
        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens
        ];
        
        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("Error de cURL: {$error}");
        }
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error']['message'] ?? 'Error desconocido';
            throw new Exception("Error de OpenAI (HTTP {$httpCode}): {$errorMessage}");
        }
        
        $result = json_decode($response, true);
        
        if (!isset($result['choices'][0]['message']['content'])) {
            throw new Exception('Respuesta inválida de OpenAI');
        }
        
        return trim($result['choices'][0]['message']['content']);
    }
    
    /**
     * Generar recordatorio de cita personalizado
     */
    public function generateAppointmentReminder($appointment, $patient, $staffMember = null) {
        $date = date('d/m/Y', strtotime($appointment['appointment_date']));
        $time = date('H:i', strtotime($appointment['appointment_time']));
        $staffName = $staffMember ? $staffMember['name'] : 'nuestro equipo';
        
        $systemMessage = "Eres un asistente amigable y profesional de un spa médico de belleza y bienestar. Tu tarea es generar recordatorios de citas personalizados, cálidos y profesionales para los pacientes. Usa un tono amable pero profesional, y mantén los mensajes concisos (máximo 250 palabras).";
        
        $prompt = "Genera un recordatorio de cita personalizado para:\n\n";
        $prompt .= "Paciente: {$patient['name']}\n";
        $prompt .= "Fecha: {$date}\n";
        $prompt .= "Hora: {$time}\n";
        $prompt .= "Servicio: {$appointment['service']}\n";
        $prompt .= "Profesional: {$staffName}\n";
        
        if (!empty($appointment['notes'])) {
            $prompt .= "Notas especiales: {$appointment['notes']}\n";
        }
        
        $prompt .= "\nEl recordatorio debe:\n";
        $prompt .= "1. Ser cálido y personalizado\n";
        $prompt .= "2. Mencionar el servicio de manera atractiva\n";
        $prompt .= "3. Incluir la fecha y hora\n";
        $prompt .= "4. Recordar llegar 10 minutos antes\n";
        $prompt .= "5. Incluir consejos breves de preparación si aplican (por ejemplo, no usar maquillaje para tratamientos faciales)\n";
        $prompt .= "6. Terminar con una nota positiva y contacto para cambios\n";
        $prompt .= "7. Usar emojis apropiados para hacerlo más amigable\n\n";
        $prompt .= "Formato: WhatsApp o SMS (informal pero profesional)";
        
        return $this->generateText($prompt, $systemMessage, 0.8, 500);
    }
    
    /**
     * Generar sugerencias de productos/servicios basado en historial
     */
    public function generateProductRecommendations($patientHistory, $preferences = []) {
        $systemMessage = "Eres un consultor experto en tratamientos estéticos y de belleza. Tu tarea es recomendar productos o servicios adecuados basándote en el historial del paciente.";
        
        $prompt = "Basándote en el siguiente historial de servicios del paciente:\n\n";
        $prompt .= json_encode($patientHistory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $prompt .= "\n\nGenera 3-5 recomendaciones personalizadas de tratamientos o productos que podrían interesarle. Incluye una breve explicación de por qué cada recomendación sería beneficiosa.";
        
        return $this->generateText($prompt, $systemMessage, 0.7, 600);
    }
    
    /**
     * Verificar si la API key es válida
     */
    public function testConnection() {
        try {
            $result = $this->generateText("Di 'OK' si puedes leer esto.", null, 0.1, 10);
            return ['success' => true, 'message' => 'Conexión exitosa con OpenAI'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
