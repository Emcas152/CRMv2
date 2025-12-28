# Configuración de WhatsApp con Twilio

Este documento explica cómo configurar Twilio para enviar recordatorios de citas por WhatsApp.

## 📋 Requisitos Previos

- Cuenta de Twilio (puedes crear una gratis en https://www.twilio.com/try-twilio)
- Crédito gratuito de $15 USD al registrarte
- Tarjeta de crédito (para verificación, no se cobra si usas el crédito gratuito)

## 🚀 Paso 1: Crear Cuenta en Twilio

1. Ve a https://www.twilio.com/try-twilio
2. Regístrate con tu email
3. Verifica tu número de teléfono
4. Recibirás $15 USD de crédito gratis

## 🔑 Paso 2: Obtener Credenciales

1. Inicia sesión en https://console.twilio.com
2. En el Dashboard verás:
   - **Account SID**: Algo como `ACxxxxxxxxxxxxxxxxxxxxxxxxxx`
   - **Auth Token**: Click en "Show" para verlo

3. Copia estos valores, los necesitarás en el `.env`

## 📱 Paso 3: Activar WhatsApp Sandbox (Para Pruebas)

Twilio ofrece un sandbox gratuito para probar WhatsApp sin necesidad de aprobar un número de negocio.

1. Ve a https://console.twilio.com/us1/develop/sms/try-it-out/whatsapp-learn
2. Verás un número de WhatsApp de Twilio (ejemplo: `+1 415 523 8886`)
3. Desde tu WhatsApp personal, envía un mensaje al número mostrado con el código que te dan
   - Ejemplo: `join <tu-código-único>`
4. Recibirás confirmación: "You are all set!"

**Importante:** El sandbox solo funciona con números que se hayan registrado de esta forma.

## 💰 Paso 4: Número de Producción (Opcional)

Para enviar a cualquier número (sin que se registren primero), necesitas:

1. Comprar un número de Twilio habilitado para WhatsApp:
   - Ve a https://console.twilio.com/us1/develop/phone-numbers/manage/search
   - Filtra por "WhatsApp"
   - Costo: ~$1.15 USD/mes
2. Configurar tu perfil de negocio en WhatsApp Business
3. Enviar templates aprobados por WhatsApp (proceso puede tardar días)

## ⚙️ Paso 5: Configurar el Backend

Edita el archivo `.env` en `backend-php-puro`:

```bash
# Twilio WhatsApp
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=tu_auth_token_aqui
TWILIO_WHATSAPP_FROM=whatsapp:+14155238886
```

**Notas:**
- El `TWILIO_ACCOUNT_SID` y `TWILIO_AUTH_TOKEN` los obtienes del Dashboard de Twilio
- El `TWILIO_WHATSAPP_FROM` es el número del sandbox (con prefijo `whatsapp:`)
- Si compraste un número, usa ese número con el prefijo `whatsapp:+tunumero`

## 📞 Formato de Números de Teléfono

Los números de teléfono de los pacientes deben estar en formato internacional:

**Correcto:**
- `+50212345678` (Guatemala)
- `+52155512345678` (México)
- `+34612345678` (España)

**Incorrecto:**
- `12345678` (sin código de país)
- `0050212345678` (con 00 en lugar de +)

El sistema automáticamente:
- Agrega el prefijo `whatsapp:` si no lo tiene
- Convierte `00` en `+` si es necesario

## 🧪 Paso 6: Probar la Integración

1. **Registra un paciente con tu número** en formato internacional
2. **Crea una cita** para ese paciente
3. **Click en el botón 📱** (WhatsApp) en la lista de citas
4. **Verifica** que recibiste el mensaje en WhatsApp

## 💡 Ejemplo de Mensaje

El recordatorio se verá así:

```
🌟 *CRM Spa Médico* 🌟

Hola *María González*,

Te recordamos tu cita:

📅 *Fecha:* 15/11/2025
🕐 *Hora:* 02:30 PM
💆 *Servicio:* Masaje Relajante
👨‍⚕️ *Atenderá:* Dr. Juan Pérez

✨ *Recomendaciones:*
• Llega 10 minutos antes
• Trae ropa cómoda
• Si necesitas cancelar, avísanos con anticipación

📞 *Contacto:* +502 1234-5678

¡Te esperamos! 💙
```

## 💰 Costos Estimados

### Sandbox (Gratis para pruebas)
- ✅ Gratis
- ⚠️ Solo números registrados
- ⚠️ Mensaje incluye "Sent from your Twilio Sandbox"

### Producción
- 📱 Número: $1.15 USD/mes
- 💬 Mensajes: $0.0085 USD por mensaje (Guatemala)
- 📊 Ejemplo: 200 mensajes/mes = $3 USD/mes

### Con Crédito Gratuito
Con los $15 USD gratis puedes enviar aproximadamente:
- ~1,700 mensajes de WhatsApp
- Suficiente para probar durante meses

## ❌ Solución de Problemas

### Error: "Credenciales de Twilio no configuradas"
- Verifica que las variables estén en el `.env`
- Asegúrate de que no tengan espacios extras
- Reinicia Apache después de editar el `.env`

### Error: "The number +502... is not a valid WhatsApp number"
- El número no está registrado en el sandbox
- Envía el mensaje "join tu-codigo" desde ese número
- O compra un número de producción

### Error: "Permission denied"
- Tu cuenta de Twilio no tiene permisos para WhatsApp
- Verifica que completaste la activación del sandbox
- Revisa el panel de control de Twilio

### No recibo mensajes
- Verifica que el número esté en formato internacional (+502...)
- Confirma que el número está registrado en el sandbox
- Revisa los logs de Twilio en https://console.twilio.com/us1/monitor/logs/sms

## 🔗 Enlaces Útiles

- [Twilio Console](https://console.twilio.com)
- [WhatsApp Sandbox](https://console.twilio.com/us1/develop/sms/try-it-out/whatsapp-learn)
- [Documentación de Twilio WhatsApp](https://www.twilio.com/docs/whatsapp)
- [Precios de WhatsApp](https://www.twilio.com/whatsapp/pricing)
- [Números disponibles](https://console.twilio.com/us1/develop/phone-numbers/manage/search)

## 📝 Notas Importantes

1. **Sandbox vs Producción**: El sandbox es perfecto para desarrollo y pruebas
2. **Templates**: Para producción, WhatsApp requiere que uses templates aprobados
3. **Límites**: Twilio tiene límites de rate (1 mensaje/segundo por defecto)
4. **Soporte**: El crédito gratuito NO caduca mientras uses la cuenta
5. **Escalabilidad**: Puedes enviar miles de mensajes sin problemas de infraestructura

## 🎯 Siguiente Paso

Una vez configurado, el botón 📱 de WhatsApp aparecerá en cada cita y podrás:
- Enviar recordatorios instantáneos
- Usar el mismo texto generado por IA
- Tener registro de mensajes enviados en el panel de Twilio
