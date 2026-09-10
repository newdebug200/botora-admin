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
# Compatibilité historique : utilisée si DB_MODE=local
DB_HOST=localhost
DB_NAME=botora_admin
DB_USER=your_db_user
DB_PASS=your_db_password

# Source active : local, online ou dual
DB_MODE=local
DB_AUTO_MIGRATE=true

# Connexion locale (utile en mode local ou dual)
DB_LOCAL_HOST=localhost
DB_LOCAL_PORT=3306
DB_LOCAL_NAME=botora_admin
DB_LOCAL_USER=your_local_db_user
DB_LOCAL_PASS=your_local_db_password

# Connexion en ligne (utile en mode online ou dual)
DB_ONLINE_HOST=your-online-db-host
DB_ONLINE_PORT=3306
DB_ONLINE_NAME=botora_admin
DB_ONLINE_USER=your_online_db_user
DB_ONLINE_PASS=your_online_db_password

APP_URL=https://admin.votredomaine.com
APP_SECRET=votre-secret-aleatoire
BOTORA_API_KEY=votre-cle-api-secrete
BOTORA_SERVICE_KEY=abcd
MAILER_DSN=smtp://username:password@smtp.example.com:587?encryption=tls&auth_mode=login
MAIL_FROM=no-reply@votredomaine.com
MAIL_FROM_NAME=Botora Admin
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

La récupération de mot de passe utilise un **code numérique à 6 chiffres**, envoyé par e-mail sans URL. Le code expire après 15 minutes, est limité à 5 tentatives et est invalidé après une utilisation.

| Endpoint | Méthode | Rôle |
|---|---|---|
| `/api/password-reset-request.php` | POST | Envoyer un code de récupération à l’adresse indiquée |
| `/api/password-reset-confirm.php` | POST | Vérifier le code et enregistrer le nouveau mot de passe |

Exemple de demande : `{"email":"utilisateur@example.com"}`. Exemple de confirmation : `{"email":"utilisateur@example.com","code":"123456","new_password":"nouveau-mot-de-passe"}`. Les e-mails sont envoyés avec Symfony Mailer via SMTP. Configurez `MAILER_DSN`, `MAIL_FROM` et `MAIL_FROM_NAME` dans `.env`, puis exécutez `composer install` sur le serveur pour installer les dépendances. Les appels de récupération provenant de `whatsapp-grok-platform` doivent inclure `X-Botora-Service-Key`; la même valeur doit être configurée dans `BOTORA_ADMIN_SERVICE_KEY` côté backend et `BOTORA_SERVICE_KEY` côté panneau.

L’endpoint `/api/consume-central.php`, utilisé pour débiter les tokens IA, est également protégé par cette même clé interservices. Il refuse toute requête sans en-tête `X-Botora-Service-Key` valide.

## Suppression de compte et historique WhatsApp

Les utilisateurs envoient une demande de suppression avec un motif depuis Botora. La demande est visible dans `admin/account-deletion-requests.php`, où un administrateur peut la refuser ou valider la suppression définitive. Avant cette suppression, les numéros WhatsApp associés sont copiés dans `whatsapp_trial_history`. Cette table est volontairement indépendante du compte et conserve les numéros déjà utilisés pendant un essai gratuit afin d’empêcher la création successive de comptes d’essai avec le même numéro.

## Paiements FedaPay centralisés

`botora-admin` est le seul service qui communique avec FedaPay. Les endpoints interservices utilisent le header privé `X-Botora-Service-Key` :

| Endpoint | Méthode | Rôle |
|---|---|---|
| `/api/payment-create.php` | POST | Créer une transaction et retourner le lien FedaPay |
| `/api/payment-verify.php` | POST | Reconsulter une transaction et créditer si elle est approuvée |
| `/api/credits.php` | POST | Retourner le solde et l’historique d’un client |
| `/api/webhook/fedapay.php` | POST public | Recevoir les événements FedaPay et confirmer la transaction |

L’URL à enregistrer dans FedaPay est :

```text
https://botora.bluelifetech.site/api/webhook/fedapay.php
```

Définissez une même clé privée sur les deux serveurs avec `BOTORA_SERVICE_KEY` dans `botora-admin` et `BOTORA_ADMIN_SERVICE_KEY` dans `whatsapp-grok-platform`. Les clés secrètes FedaPay restent uniquement sur le serveur `botora-admin`.

## Vérification automatique de la base

À chaque chargement de la page d’accueil, Botora Admin vérifie la connexion active, crée la base si elle est absente, puis applique le schéma SQL de manière idempotente. Les tables existantes et leurs données ne sont pas supprimées. Les pages protégées et les endpoints API réutilisent ensuite la même connexion préparée.

Le mode `local` utilise la base locale, le mode `online` utilise la base en ligne et le mode `dual` ouvre les deux connexions, avec la base locale comme source active historique. Le mode `dual` prépare les deux côtés mais ne réalise pas de synchronisation automatique : une synchronisation de données doit être explicitement conçue avec une règle de priorité, des identifiants stables et une gestion des conflits afin d’éviter les doublons ou les écrasements.

## Premier compte administrateur automatique

Après la création ou la vérification du schéma, l’application vérifie si la table `admins` contient déjà un compte. Si elle est vide, elle crée un compte `superadmin` avec les paramètres `BOTORA_ADMIN_NAME`, `BOTORA_ADMIN_EMAIL` et `BOTORA_ADMIN_PASSWORD`. Cette opération est idempotente : aucun compte existant n’est modifié.

Pour remplacer les valeurs de test, définissez ces variables avant le premier chargement :

```env
BOTORA_ADMIN_NAME=Votre Nom
BOTORA_ADMIN_EMAIL=votre-email@example.com
BOTORA_ADMIN_PASSWORD=VotreMotDePasseFort
```

Une fois le premier compte créé, changez son mot de passe depuis les paramètres du panneau et retirez les valeurs de test de la configuration serveur.

## Rôles admin
- **superadmin** : Accès total, gestion des admins
- **admin** : Gestion complète des clients
- **viewer** : Lecture seule (rapports)
