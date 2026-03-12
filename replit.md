# Glow PDF System

A PHP-based SaaS PDF document generator with user authentication and subscription management.

## Architecture

- **Language:** PHP 8.2
- **Database:** SQLite (via PDO) — stored in `glow.db`
- **PDF Generation:** Dompdf v2.0 (via Composer, in `vendor/`)
- **Frontend:** Tailwind CSS (CDN), vanilla JS
- **Server:** PHP built-in server on port 5000

## Key Files

- `index.php` — Main app: login, registration, PDF generation
- `admin.php` — Admin dashboard: user management, subscription activation
- `glow.db` — SQLite database file (auto-created on first run)
- `composer.json` — PHP dependencies (dompdf)
- `vendor/` — Composer packages

## Database Schema

```sql
CREATE TABLE usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    senha TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT "aguardando",  -- "ativo" | "aguardando"
    expira_em TEXT,    -- date string YYYY-MM-DD
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
)
```

## Features

- User registration and login (with password hashing)
- Free tier (with watermark) and VIP tier (R$ 29.90/month)
- PDF document types: Orçamento Técnico, Recibo, Contrato, Declaração
- PIX payment QR code generation for subscriptions
- Admin panel at `/admin.php` (login: admin@glow.com)

## Running

The app runs via PHP built-in server:
```
php -S 0.0.0.0:5000
```

## Notes

- Original project used MySQL; adapted to SQLite for Replit compatibility
- SQLite DB is auto-initialized with schema on first request
- Admin access requires session login with email `admin@glow.com`
