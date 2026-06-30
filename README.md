# Botora Admin Panel

Panneau d'administration PHP pour gérer les licences, crédits et utilisateurs de Botora.

## Stack
- PHP 8.0+ avec PDO
- MySQL 8.0+ (ou MariaDB 10.5+)
- HTML/CSS/JS vanilla (aucune dépendance frontend)

## Installation

### 1. Cloner le dépôt sur votre VPS
```bash
git clone https://github.com/newdebug200/botora-admin.git /var/www/botora-admin
```

### 2. Créer la base de données
```bash
mysql -u root -p < sql/schema.sql
```

### 3. Configurer
Éditez `config.php` ou définissez ces variables d'environnement sur votre serveur :
```
DB_HOST=localhost
DB_NAME=botora_admin
DB_USER=your_db_user
DB_PASS=your_db_password
APP_URL=https://admin.votredomaine.com
APP_SECRET=votre-secret-aleatoire
BOTORA_API_KEY=votre-cle-api-secrete
```

### 4. Créer le premier admin
```bash
php -r "
require 'config.php';
require 'includes/db.php';
\$db = db();
\$db->prepare('INSERT INTO admins (name,email,password_hash,role) VALUES (?,?,?,?)')->execute([
  'Votre Nom', 'admin@email.com', password_hash('motdepasse', PASSWORD_DEFAULT), 'superadmin'
]);
echo 'Admin créé.';
"
```

### 5. Configurer le serveur web
**Apache** — `.htaccess` à placer à la racine :
```apache
RewriteEngine On
RewriteRule ^$ admin/dashboard.php [L]
Options -Indexes
```

**Nginx** — exemple de config :
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
    index index.php admin/dashboard.php;
}
```

## API Botora
Trois endpoints pour que Botora communique avec le panel :

| Endpoint | Méthode | Rôle |
|---|---|---|
| `/api/validate.php` | POST | Valider une licence au démarrage |
| `/api/consume.php` | POST | Déduire des crédits |
| `/api/features.php` | GET | Récupérer les fonctionnalités du plan |

Chaque requête doit inclure le header `X-Api-Key: <BOTORA_API_KEY>`.

## Rôles admin
- **superadmin** : Accès total, gestion des admins
- **admin** : Gestion complète des clients
- **viewer** : Lecture seule (rapports)
