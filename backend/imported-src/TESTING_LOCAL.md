# Testing Local del Backend PHP Puro

## 🎯 Problema
No puedes conectarte a la base de datos remota de Hostalia desde tu PC local por restricciones de seguridad.

## ✅ Solución: Base de Datos Local

### Paso 1: Instalar XAMPP (si no lo tienes)
1. Descarga XAMPP: https://www.apachefriends.org/
2. Instala y ejecuta **Apache** y **MySQL**

### Paso 2: Crear Base de Datos Local
1. Abre http://localhost/phpmyadmin
2. Crea una nueva base de datos: `crm_spa_medico`
3. Importa el archivo `install.sql`:
   - Click en la base de datos
   - Tab "Importar"
   - Selecciona `install.sql`
   - Click "Continuar"

### Paso 3: Configurar Entorno Local
Ya está creado el archivo `.env.local` con configuración para MySQL local:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=crm_spa_medico
DB_USER=root
DB_PASS=
```

### Paso 4: Ejecutar Tests
```powershell
cd C:\Users\edwin\Desktop\CRM\backend-php-puro
php test.php
```

El sistema automáticamente usará `.env.local` si existe, sino usará `.env` (producción).

## 🚀 Para Producción (Hostalia)

Cuando subas al servidor:
1. **NO subas** el archivo `.env.local`
2. Solo sube el `.env` con datos de producción
3. El backend automáticamente usará `.env` en el servidor

## 📋 Archivos de Configuración

```
backend-php-puro/
├── .env              ← Producción (Hostalia)
├── .env.local        ← Desarrollo local (NO subir)
├── .env.example      ← Plantilla
└── core/helpers.php  ← Carga variables de entorno
```

## ⚠️ Importante

- `.env.local` tiene prioridad sobre `.env`
- `.env.local` debe estar en `.gitignore` (no subir a GitHub)
- En el servidor solo debe existir `.env`
