# Checklist backend production

## 1. Extensions PHP requises
Vérifier que ces extensions sont activées dans php.ini (ou php-fpm) :

```
extension=intl          ← CRITIQUE — sans ça le chat plante (transliterator_transliterate)
extension=pdo_pgsql
extension=openssl
extension=mbstring
extension=curl
extension=fileinfo
extension=zip
```

Sur Linux/Ubuntu :
```bash
sudo apt install php8.3-intl php8.3-pgsql php8.3-curl php8.3-mbstring
sudo systemctl restart php8.3-fpm
```

## 2. Certificats SSL pour OpenAI
PHP doit pouvoir appeler api.openai.com. Vérifier :

```bash
php -r "echo file_get_contents('https://api.openai.com');" 2>&1
```

Si erreur cURL 60 (SSL), configurer dans php.ini :
```ini
curl.cainfo=/etc/ssl/certs/ca-certificates.crt
openssl.cafile=/etc/ssl/certs/ca-certificates.crt
```

## 3. Fichier .env production

```env
APP_ENV=production
APP_DEBUG=false

DB_CONNECTION=pgsql
DB_HOST=...
DB_PORT=5432
DB_DATABASE=chatbot_ia
DB_USERNAME=...
DB_PASSWORD=...

OPENAI_API_KEY=sk-proj-...
OPENAI_DEFAULT_MODEL=gpt-4o
OPENAI_EMBEDDING_MODEL=text-embedding-3-small

SIMPLE_BOT_UUID=<uuid du bot — récupérer via tinker ou API admin>
SIMPLE_API_KEY=<token Sanctum widget — généré via createToken()>

QUEUE_CONNECTION=database   ← ou redis si disponible
```

## 4. Migrations + seed
```bash
php artisan migrate --force
php artisan db:seed --class=AdminUserSeeder --force
```

## 5. Token admin et bot

Générer un token Sanctum pour le widget :
```bash
php artisan tinker
$user = App\Models\User::where('email', 'admin@chatbot-ia.com')->first();
echo $user->createToken('widget-public')->plainTextToken;
```

Mettre ce token dans :
- `.env` → `SIMPLE_API_KEY`
- Plugin WordPress → champ "Clé widget public"

Récupérer le UUID du bot :
```bash
php artisan tinker
App\Domains\Bot\Models\Bot::first()->uuid;
```
Mettre dans `.env` → `SIMPLE_BOT_UUID`

## 6. Queue worker (embeddings)
Le worker doit tourner en permanence pour générer les embeddings :

```bash
# Avec supervisor (recommandé)
php artisan queue:work --tries=3 --timeout=120 --sleep=3

# Fichier supervisor /etc/supervisor/conf.d/chatbot-worker.conf :
[program:chatbot-worker]
command=php /var/www/chatbot/artisan queue:work --tries=3 --timeout=120
autostart=true
autorestart=true
```

## 7. Timeout PHP / Nginx
Pour éviter les coupures sur les réponses longues (OpenAI peut prendre 30-60s) :

**php.ini / php-fpm.conf :**
```ini
max_execution_time = 120
request_terminate_timeout = 120
```

**nginx.conf :**
```nginx
fastcgi_read_timeout 120;
proxy_read_timeout 120;
```

## 8. Import base de connaissance
Après déploiement, importer le fichier JSON :
```bash
curl -X POST https://votre-backend/api/knowledge/import-bulk \
  -H "Authorization: Bearer <token_admin>" \
  -H "Content-Type: application/json" \
  -d @knowledge-unified-dettes-ca-v2-import-safe.json
```
Puis lancer le worker pour générer les embeddings.

## 9. Plugin WordPress
Dans les paramètres du plugin :
- **URL backend** : `https://votre-backend`
- **Clé API admin** : token admin Sanctum
- **Clé widget public** : token widget Sanctum (ou laisser vide → utilise la clé admin)
