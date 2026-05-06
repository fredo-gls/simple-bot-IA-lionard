# Chatbot Lionard — Guide d'installation complet

> Version simplifiée, mono-site, déployable en ~30 minutes sur un VPS OVH.
> Aucun UUID à configurer côté WordPress — juste l'URL du backend.

---

## Sommaire

1. [Architecture](#1-architecture)
2. [Ce que contient ce dossier](#2-ce-que-contient-ce-dossier)
3. [Prérequis VPS](#3-prérequis-vps)
4. [Installation du backend sur VPS](#4-installation-du-backend-sur-vps)
5. [Configuration du bot (première fois)](#5-configuration-du-bot-première-fois)
6. [Installation du plugin WordPress](#6-installation-du-plugin-wordpress)
7. [Vérification finale](#7-vérification-finale)
8. [Maintenance et mises à jour](#8-maintenance-et-mises-à-jour)
9. [Dépannage](#9-dépannage)
10. [Variables d'environnement — référence complète](#10-variables-denvironnement--référence-complète)

---

## 1. Architecture

```
Visiteur WordPress
       │
       │  POST /api/chat { message, session_id }
       ▼
┌──────────────────────────┐
│  VPS OVH                 │
│  Nginx → PHP-FPM 8.2     │
│  Laravel 11              │
│  ├─ ChatController       │  ← Reçoit le message
│  ├─ MessageOrchestrator  │  ← Pipeline RAG + OpenAI
│  ├─ AIClient             │  ← Appel GPT-4o
│  └─ KnowledgeRetriever   │  ← Recherche vectorielle (pgvector)
│                          │
│  PostgreSQL + pgvector   │  ← Base de données + embeddings
│  Redis                   │  ← Cache + queues
└──────────────────────────┘
```

**Flux simplifié :**
1. Le widget JS envoie `{ message, session_id }` à `POST /api/chat`
2. Le backend charge le bot configuré via `SIMPLE_BOT_UUID` dans `.env`
3. Il retrouve ou crée une conversation identifiée par `session_id`
4. Il appelle OpenAI et retourne `{ reply, session_id }`
5. Le widget affiche la réponse

**Différence avec la version multi-tenant :**
- Pas de `botUuid` ni `siteUuid` à gérer côté WordPress
- Un seul bot, configuré une fois dans `.env`
- L'endpoint est `/api/chat` (pas `/api/v1/widget/...`)

---

## 2. Ce que contient ce dossier

```
chatbot-lionard/
├── backend/                    ← Fichiers à SUPERPOSER sur le backend principal
│   ├── app/
│   │   ├── Http/Controllers/
│   │   │   ├── SimpleApi/ChatController.php   ← Endpoint /api/chat
│   │   │   └── Internal/HealthController.php  ← Endpoint /api/health
│   │   └── Providers/AppServiceProvider.php   ← Rate limiter simplifié
│   ├── config/chatbot.php                     ← Config SIMPLE_BOT_UUID
│   ├── routes/api.php                         ← Routes simplifiées
│   └── .env.example                           ← Template .env
│
├── plugin-wordpress/           ← Plugin WP COMPLET, prêt à zipper
│   ├── chatbot-lionard.php     ← Entrée du plugin
│   ├── includes/
│   ├── admin/                  ← Interface admin (3 pages seulement)
│   ├── public/                 ← Injection du widget
│   └── assets/
│       ├── css/chatbot-widget.css
│       └── js/chatbot-widget.js  ← Widget simplifié (session_id, pas d'UUID)
│
└── INSTALL.md                  ← Ce fichier
```

> **Important :** Le dossier `backend/` ici contient uniquement les fichiers modifiés
> ou nouveaux par rapport au backend principal (`../backend/`). Voir section 4 pour
> les instructions de déploiement.

---

## 3. Prérequis VPS

| Composant | Version minimale | Recommandé |
|-----------|-----------------|------------|
| OS        | Ubuntu 22.04    | Ubuntu 24.04 LTS |
| PHP       | 8.2             | 8.2 |
| PostgreSQL| 14              | 16 |
| Redis     | 6               | 7 |
| RAM       | 2 Go            | 4 Go |
| Stockage  | 20 Go SSD       | 40 Go |

**OVH VPS recommandé :** VPS Starter (4 Go RAM, 80 Go SSD) ≈ 6 €/mois.

---

## 4. Installation du backend sur VPS

### 4.1 — Connexion et mise à jour

```bash
ssh root@VOTRE_IP_OVH
apt update && apt upgrade -y
```

### 4.2 — Installer PHP 8.2

```bash
apt install -y software-properties-common
add-apt-repository ppa:ondrej/php -y
apt update

apt install -y \
  php8.2 php8.2-fpm php8.2-cli php8.2-pgsql php8.2-mbstring \
  php8.2-xml php8.2-curl php8.2-zip php8.2-bcmath php8.2-intl \
  php8.2-redis php8.2-gd php8.2-tokenizer

php -v   # → PHP 8.2.x
```

### 4.3 — Installer Composer

```bash
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
composer --version
```

### 4.4 — Installer PostgreSQL + pgvector

```bash
apt install -y postgresql postgresql-contrib
apt install -y postgresql-server-dev-all build-essential git

# Extension pgvector (recherche vectorielle pour le RAG)
git clone --branch v0.7.0 https://github.com/pgvector/pgvector.git /tmp/pgvector
cd /tmp/pgvector && make && make install

# Créer la base et l'utilisateur
sudo -u postgres psql << 'EOF'
CREATE DATABASE chatbot_lionard;
CREATE USER chatbot_user WITH ENCRYPTED PASSWORD 'VotreMotDePasse_changez_moi';
GRANT ALL PRIVILEGES ON DATABASE chatbot_lionard TO chatbot_user;
\c chatbot_lionard
CREATE EXTENSION IF NOT EXISTS vector;
EOF
```

### 4.5 — Installer Redis

```bash
apt install -y redis-server
systemctl enable redis-server && systemctl start redis-server
redis-cli ping   # → PONG
```

### 4.6 — Installer Nginx

```bash
apt install -y nginx
systemctl enable nginx
```

### 4.7 — Déployer le code

**Option A — Depuis votre machine de développement (Windows) :**

```bash
# Sur votre PC Windows, dans PowerShell :
rsync -avz --exclude=vendor --exclude=.env --exclude=node_modules \
  "e:/Developpement/Chatboot IA/backend/" \
  root@VOTRE_IP:/var/www/chatbot-lionard/

# Puis copier les fichiers overlay de chatbot-lionard :
rsync -avz --exclude=.env \
  "e:/Developpement/Chatboot IA/chatbot-lionard/backend/" \
  root@VOTRE_IP:/var/www/chatbot-lionard/
```

**Option B — Via Git (recommandé pour les mises à jour) :**

```bash
# Sur le VPS :
mkdir -p /var/www/chatbot-lionard
cd /var/www/chatbot-lionard
git clone https://github.com/votre-repo/chatbot-ia.git .

# Appliquer l'overlay chatbot-lionard par-dessus :
cp /chemin/vers/chatbot-lionard/backend/routes/api.php routes/api.php
cp /chemin/vers/chatbot-lionard/backend/config/chatbot.php config/chatbot.php
cp /chemin/vers/chatbot-lionard/backend/app/Http/Controllers/SimpleApi/ChatController.php \
   app/Http/Controllers/SimpleApi/ChatController.php
cp /chemin/vers/chatbot-lionard/backend/app/Http/Controllers/Internal/HealthController.php \
   app/Http/Controllers/Internal/HealthController.php
cp /chemin/vers/chatbot-lionard/backend/app/Providers/AppServiceProvider.php \
   app/Providers/AppServiceProvider.php
```

**Ensuite (sur le VPS dans /var/www/chatbot-lionard) :**

```bash
composer install --no-dev --optimize-autoloader

# Permissions
chown -R www-data:www-data /var/www/chatbot-lionard
chmod -R 775 /var/www/chatbot-lionard/storage
chmod -R 775 /var/www/chatbot-lionard/bootstrap/cache
```

### 4.8 — Configurer le .env

```bash
cp .env.example .env
nano .env
```

Renseigner au minimum :

```env
APP_URL=https://votre-domaine.com
APP_KEY=                          # sera généré à l'étape suivante

DB_PASSWORD=VotreMotDePasse_changez_moi

OPENAI_API_KEY=sk-proj-...

# SIMPLE_BOT_UUID sera renseigné après l'étape 5
SIMPLE_BOT_UUID=
```

```bash
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 4.9 — Configurer Nginx

```bash
nano /etc/nginx/sites-available/chatbot-lionard
```

```nginx
server {
    listen 80;
    server_name votre-domaine.com;
    root /var/www/chatbot-lionard/public;
    index index.php;

    # Logs
    access_log /var/log/nginx/chatbot-lionard-access.log;
    error_log  /var/log/nginx/chatbot-lionard-error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    client_max_body_size 20M;

    # CORS pour le widget WordPress
    add_header Access-Control-Allow-Origin  "*" always;
    add_header Access-Control-Allow-Methods "POST, GET, OPTIONS" always;
    add_header Access-Control-Allow-Headers "Content-Type, X-Chatboot-Api-Key" always;

    if ($request_method = OPTIONS) {
        return 204;
    }
}
```

```bash
ln -s /etc/nginx/sites-available/chatbot-lionard /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

### 4.10 — SSL Let's Encrypt

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d votre-domaine.com
# Certbot configure HTTPS automatiquement
```

### 4.11 — Supervisor pour les queues

Les queues servent à générer les embeddings en arrière-plan (RAG).

```bash
apt install -y supervisor
nano /etc/supervisor/conf.d/chatbot-lionard.conf
```

```ini
[program:chatbot-lionard-worker]
command=php /var/www/chatbot-lionard/artisan queue:work redis --queue=default,embeddings --sleep=3 --tries=3 --max-time=3600
directory=/var/www/chatbot-lionard
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/chatbot-lionard-worker.log
```

```bash
supervisorctl reread && supervisorctl update
supervisorctl status   # → chatbot-lionard-worker: RUNNING
```

---

## 5. Configuration du bot (première fois)

Le bot est l'entité centrale qui contient le nom, le comportement, le prompt système.
Il doit exister en base avant de pouvoir utiliser l'API.

### 5.1 — Créer le bot via Tinker

```bash
cd /var/www/chatbot-lionard
php artisan tinker
```

```php
// Dans Tinker :
$bot = \App\Domains\Bot\Models\Bot::create([
    'name'            => 'Lionard',          // Nom affiché dans les logs
    'slug'            => 'lionard',
    'description'     => 'Assistant du site monsite.com',
    'tone'            => 'professionnel',
    'welcome_message' => 'Bonjour ! Comment puis-je vous aider ?',
    'is_active'       => true,
    'user_id'         => 1,                  // ID de l'utilisateur admin
]);

echo $bot->uuid;   // ← COPIER CET UUID
```

### 5.2 — Ajouter l'UUID dans .env

```bash
nano /var/www/chatbot-lionard/.env
```

```env
SIMPLE_BOT_UUID=l-uuid-copie-ici   # ex: a1b2c3d4-e5f6-...
```

```bash
php artisan config:cache
```

### 5.3 — Vérifier

```bash
curl https://votre-domaine.com/api/health
```

Réponse attendue :
```json
{
  "status": "ok",
  "checks": {
    "database": "ok",
    "redis": "ok",
    "bot": "ok",
    "openai": "ok"
  }
}
```

---

## 6. Installation du plugin WordPress

### 6.1 — Préparer le ZIP

Sur votre machine :
```bash
# Windows PowerShell :
Compress-Archive -Path "chatbot-lionard/plugin-wordpress/*" -DestinationPath "chatbot-lionard.zip"
```

Ou via l'explorateur Windows : clic droit sur le dossier `plugin-wordpress/` → Envoyer vers → Dossier compressé. Renommer le ZIP en `chatbot-lionard.zip`.

### 6.2 — Installer dans WordPress

1. WordPress Admin → **Extensions** → **Ajouter**
2. **Téléverser une extension** → sélectionner `chatbot-lionard.zip`
3. **Installer** → **Activer**

### 6.3 — Configurer

Aller dans **Chatbot Lionard → Connexion** :

| Champ | Valeur |
|-------|--------|
| **URL du backend** | `https://votre-domaine.com` (URL de votre VPS) |
| **Clé API** | Valeur de `SIMPLE_API_KEY` dans le .env (laisser vide si non défini) |
| **Activer le widget** | Cocher ✓ |

Cliquer sur **Enregistrer**.

### 6.4 — Personnaliser l'apparence

Aller dans **Chatbot Lionard → Apparence** pour définir :
- Nom et sous-titre du chatbot
- Message d'accueil
- Couleur principale (`#1d6ddb` par défaut)
- Avatar (URL d'une image 200×200px)
- Position (droite/gauche, desktop/mobile)

### 6.5 — Tester

Sur le tableau de bord du plugin → cliquer sur **Tester la connexion**.
Puis ouvrir votre site WordPress et vérifier que le widget apparaît en bas de page.

---

## 7. Vérification finale

```bash
# 1. Health check backend
curl https://votre-domaine.com/api/health

# 2. Test du chat
curl -X POST https://votre-domaine.com/api/chat \
  -H "Content-Type: application/json" \
  -d '{"message": "Bonjour, que faites-vous ?"}' | python3 -m json.tool
# → { "reply": "Bonjour ! Je suis ...", "session_id": "sess-abc..." }

# 3. Test avec session (conversation continue)
curl -X POST https://votre-domaine.com/api/chat \
  -H "Content-Type: application/json" \
  -d '{"message": "Merci", "session_id": "sess-abc..."}' | python3 -m json.tool

# 4. Vérifier les workers
supervisorctl status

# 5. Logs en temps réel
tail -f /var/www/chatbot-lionard/storage/logs/laravel.log
```

---

## 8. Maintenance et mises à jour

### Déployer une mise à jour du backend

```bash
cd /var/www/chatbot-lionard

# Mettre à jour le code
git pull   # ou rsync depuis le PC de dev

# Réappliquer l'overlay si besoin
cp /chemin/overlay/routes/api.php routes/api.php
# etc.

# Mettre à jour les dépendances
composer install --no-dev --optimize-autoloader

# Appliquer les migrations
php artisan migrate --force

# Vider les caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Redémarrer les workers
supervisorctl restart chatbot-lionard-worker
```

### Ajouter une base de connaissance (RAG)

Pour que le bot réponde à partir du contenu de votre site :

```bash
php artisan tinker
```

```php
// Créer une source de connaissance
$source = \App\Domains\Knowledge\Models\KnowledgeSource::create([
    'bot_id'    => \App\Domains\Bot\Models\Bot::first()->id,
    'name'      => 'Site web',
    'type'      => 'manual',
    'is_active' => true,
]);

// Importer un document texte
$doc = $source->documents()->create([
    'title'   => 'Présentation de l\'entreprise',
    'content' => file_get_contents('/tmp/presentation.txt'),
    'status'  => 'pending',
]);

// Déclencher le chunking + embedding
\App\Jobs\ChunkDocumentJob::dispatch($doc);
```

### Sauvegarder la base de données

```bash
# Backup quotidien (à mettre dans cron)
pg_dump -U chatbot_user chatbot_lionard | gzip > /backups/chatbot_$(date +%Y%m%d).sql.gz
```

---

## 9. Dépannage

### Le widget ne s'affiche pas sur WordPress

1. Vérifier que **Activer le widget** est coché dans le plugin
2. Vérifier que l'URL du backend est correcte (sans slash final)
3. Ouvrir la console navigateur (F12) → chercher des erreurs CORS
4. Vérifier que Nginx a les headers CORS (voir section 4.9)

### Erreur "Bot introuvable" dans les logs

```bash
# Vérifier que SIMPLE_BOT_UUID est correct
php artisan tinker
>>> \App\Domains\Bot\Models\Bot::all()->pluck('uuid', 'name')
```

### Les réponses IA ne marchent pas (fallback message)

```bash
# Vérifier la clé OpenAI
php artisan tinker
>>> config('openai.api_key')   # ne doit pas être vide
```

Si vide → vérifier `.env` et relancer `php artisan config:cache`.

### Erreur 500 sur /api/chat

```bash
# Voir les logs
tail -100 /var/www/chatbot-lionard/storage/logs/laravel.log
```

### Les embeddings ne se génèrent pas

```bash
# Vérifier les workers
supervisorctl status
# S'ils sont arrêtés :
supervisorctl start chatbot-lionard-worker

# Voir les logs workers
tail -100 /var/log/chatbot-lionard-worker.log
```

### Erreur CORS en production

Ajouter dans Nginx (section 4.9) et vérifier que le domaine WordPress est bien autorisé.
Si besoin de restreindre à un domaine spécifique, remplacer `"*"` par `"https://votresite.com"`.

---

## 10. Variables d'environnement — référence complète

| Variable | Obligatoire | Description |
|----------|-------------|-------------|
| `APP_KEY` | Oui | Généré par `php artisan key:generate` |
| `APP_URL` | Oui | URL publique du VPS, avec `https://` |
| `DB_PASSWORD` | Oui | Mot de passe PostgreSQL |
| `OPENAI_API_KEY` | Oui | Clé API OpenAI (`sk-proj-...`) |
| `SIMPLE_BOT_UUID` | Oui | UUID du bot créé via Tinker (étape 5) |
| `SIMPLE_API_KEY` | Non | Clé secrète partagée avec le plugin WP |
| `OPENAI_DEFAULT_MODEL` | Non | Défaut : `gpt-4o` |
| `OPENAI_EMBEDDING_MODEL` | Non | Défaut : `text-embedding-3-small` |
| `OPENAI_MAX_TOKENS` | Non | Défaut : `1024` |
| `OPENAI_TEMPERATURE` | Non | Défaut : `0.7` (0=précis, 1=créatif) |
| `REDIS_HOST` | Non | Défaut : `127.0.0.1` |
| `DB_HOST` | Non | Défaut : `127.0.0.1` |

---

## Récapitulatif — checklist de déploiement

```
□ VPS OVH commandé (Ubuntu 22.04/24.04, min 2 Go RAM)
□ PHP 8.2 + extensions installées
□ PostgreSQL + pgvector installés, base créée
□ Redis installé et démarré
□ Code backend déployé dans /var/www/chatbot-lionard/
□ Overlay chatbot-lionard/ appliqué
□ composer install exécuté
□ .env configuré (DB, Redis, OpenAI)
□ php artisan key:generate exécuté
□ php artisan migrate --force exécuté
□ Nginx configuré + SSL Let's Encrypt
□ Supervisor configuré, worker démarré
□ Bot créé via Tinker, UUID dans .env
□ php artisan config:cache exécuté
□ curl /api/health → status ok
□ Plugin WordPress installé et activé
□ URL backend configurée dans le plugin
□ Widget visible sur le site WordPress
□ Test de conversation réussi
```

---

*Chatbot Lionard — version 1.0.0*
