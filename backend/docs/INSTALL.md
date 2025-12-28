# Instalación (Windows / MySQL)

Este proyecto tiene frontend Angular + backend PHP en `/backend`.

## 1) Base de datos (MySQL)

Requisitos:
- MySQL en ejecución (XAMPP o MySQL Server)
- Cliente `mysql` disponible en PATH (o especificar `-MysqlPath`)

Desde la raíz del repo, ejecuta en PowerShell:

- Instalar schema (sin borrar nada):
  - `./tools/install.ps1 -DbName crm_spa_medico -DbUser root -DbPasswordPlain ''`

- Instalación limpia + datos de prueba:
  - `./tools/install.ps1 -DbName crm_spa_medico -DbUser root -DbPasswordPlain '' -DropAll -Seed`

Si usas XAMPP y no tienes `mysql` en PATH, puedes indicar la ruta:
- `./tools/install.ps1 -MysqlPath 'C:\xampp\mysql\bin\mysql.exe' -DbName crm_spa_medico -DbUser root -DbPasswordPlain '' -DropAll -Seed`

Esto aplica:
- Schema: `backend/docs/schema.mysql.sql`
- Seed: `backend/docs/seed.mysql.sql`

También, si no existe, crea `backend/.env` desde `backend/.env.example`.

## 2) Frontend

- `npm install`
- `npm start`

## 3) Backend

Sirve la carpeta `backend/public` con Apache (XAMPP) o Nginx.

Asegúrate que `backend/.env` tenga los `DB_*` correctos y `APP_URL` apunte a tu dominio/puerto.
