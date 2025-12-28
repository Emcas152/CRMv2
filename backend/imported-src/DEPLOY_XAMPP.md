Despliegue en XAMPP / Hosting con subcarpeta

Resumen rápido
- Este proyecto espera que el directorio `public/` sea el punto de entrada (DocumentRoot ideal).
- Si no puedes cambiar DocumentRoot en tu hosting (por ejemplo en alojamiento compartido o XAMPP con `htdocs`), puedes colocar el proyecto en una subcarpeta y usar `index.php` en las URLs o dejar que `.htaccess` enrute las peticiones.

Pasos recomendados (XAMPP local)
1. Copiar proyecto a `C:\xampp\htdocs\backend-php-puro`.
2. Asegúrate de que `core/helpers.php` esté cargando el `.env` correcto: revisa `.env` en la raíz del proyecto y ajusta `APP_URL` si es necesario.
3. Recomendado: configurar un VirtualHost para apuntar directamente a `.../backend-php-puro/public` como `DocumentRoot`.
   - Edita `C:\xampp\apache\conf\extra\httpd-vhosts.conf` y añade algo como:
```
<VirtualHost *:80>
    ServerName crm.local
    DocumentRoot "C:/xampp/htdocs/backend-php-puro/public"
    <Directory "C:/xampp/htdocs/backend-php-puro/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
   - Añade `127.0.0.1 crm.local` al `C:\Windows\System32\drivers\etc\hosts` y reinicia Apache.

Rutas alternativas (si no configuras VirtualHost)
- Usa la ruta con `index.php` incluida en la URL desde el frontend para evitar dependencias de mod_rewrite:
  - Ejemplo: `http://localhost/backend-php-puro/public/index.php/api/login`
- O asegúrate de que `.htaccess` esté aplicado (AllowOverride All en Apache) y que `mod_rewrite` esté habilitado.

Variables .env y `config/database.php`
- `config/database.php` usa variables de `.env` con estos nombres (se mapean varios nombres para compatibilidad):
  - DB_HOST
  - DB_PORT
  - DB_NAME o DB_DATABASE
  - DB_USER o DB_USERNAME
  - DB_PASS o DB_PASSWORD
  - DB_CHARSET

Ejemplo mínimo `.env` (ajusta según hosting):
```
APP_URL=http://localhost/backend-php-puro/public
DB_HOST=localhost
DB_PORT=3306
DB_NAME=crm_spa_medico
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4
JWT_SECRET=tu-secreto
```

Notas de seguridad
- No dejes scripts sensibles (p. ej. `update-password.php`) accesibles públicamente en producción. Mueve esos scripts fuera de `public/` o protéjelos.
- No guardes credenciales en repositorio público.

Diagnóstico rápido
- Si obtienes 404 al llamar `/public/api/login`, prueba con `/public/index.php/api/login`.
- Si obtienes errores 500 sobre `config/database.php` faltante, crea/ajusta `config/database.php` o confirma `.env` presente.

Si quieres, aplico los cambios de VirtualHost automáticamente (edito archivos de configuración), o te guío paso a paso para tu entorno XAMPP.