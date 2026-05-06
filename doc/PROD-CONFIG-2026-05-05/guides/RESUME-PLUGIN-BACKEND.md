# Résumé : Plugin WordPress ↔ Backend Laravel

## Ce que fait le plugin (côté WordPress)

Le plugin est un **client HTTP**. Il ne fait que envoyer des requêtes au backend Laravel.
Il n'a aucune logique IA, aucune base de données, aucun traitement.

---

## Corrections apportées au plugin

| # | Fichier | Ce qui était cassé | Ce qui a été corrigé |
|---|---|---|---|
| 1 | `chatboot-widget.js` | Header envoyé : `X-Chatboot-Api-Key: xxx` | Remplacé par `Authorization: Bearer xxx` |
| 2 | `chatboot-widget.js` | Champ `language` dans le body du chat | Supprimé — déjà envoyé dans `Content-Language` header |
| 3 | `class-chatbot-lionard-public.php` | Clé widget vide → rien envoyé | Fallback ajouté : utilise la clé admin si widget vide |
| 4 | `chatboot-widget.js` | Streaming SSE `/api/chat/stream` crash côté serveur | Désactivé — le widget utilise uniquement `/api/chat` |

---

## Corrections apportées au backend

| # | Fichier | Ce qui était cassé | Ce qui a été corrigé |
|---|---|---|---|
| 1 | `ChatController.php` | `session_id` validé avec `max:200` → 422 si token long | Passé à `max:500` (les tokens signés font ~53 chars mais peuvent s'accumuler) |
| 2 | `php.ini` | `max_execution_time = 30` → timeout OpenAI | Passé à `120` — OpenAI peut prendre 30-60s |
| 3 | `php.ini` | Extension `intl` absente → chat plante sur `transliterator_transliterate()` | `extension=intl` activée |

---

## Ce que le plugin envoie au backend

### Requête chat (widget public)

```
POST /api/chat
Authorization: Bearer <SIMPLE_WIDGET_KEY ou SIMPLE_API_KEY>
Content-Type: application/json
Content-Language: fr

{
  "message": "bonjour",
  "session_id": "abc123.signature",
  "origin_url": "https://monsite.com/page",
  "page_title": "Titre de la page"
}
```

Réponse attendue :
```json
{
  "reply": "Bonjour ! Comment puis-je vous aider ?",
  "session_id": "abc123.signature"
}
```

> **Note session_id** : le backend génère un token signé `uuid.hmac16` à la première réponse.
> Le widget le stocke en `localStorage` et le renvoie à chaque message suivant.
> Si le `session_id` en localStorage est corrompu ou trop long → vider avec :
> `localStorage.removeItem('chatboot_ia_session_id')` dans la console navigateur.

---

### Requêtes admin (panneau WordPress)

Toutes avec :
```
Authorization: Bearer <SIMPLE_API_KEY>
Content-Type: application/json
Accept: application/json
```

| Action | Méthode | Endpoint |
|---|---|---|
| Test de connexion | GET | `/api/health` |
| Config du bot | GET | `/api/bot/config` |
| Sauvegarder prompt | POST | `/api/bot/prompt` |
| Importer document | POST | `/api/knowledge/import` |
| Importer JSON bulk | POST | `/api/knowledge/import-bulk` |
| Statut indexation | GET | `/api/knowledge/status` |
| Liste documents | GET | `/api/knowledge/list` |
| Supprimer document | DELETE | `/api/knowledge/{id}` |
| Activer/désactiver doc | POST | `/api/knowledge/{id}/toggle` |

---

## Ce que le backend doit avoir pour que ça marche

### 1. Extensions PHP obligatoires
```
intl        ← CRITIQUE (sans ça le chat plante sur transliterator_transliterate)
pdo_pgsql
openssl
mbstring
curl
```

Sur Linux/Ubuntu :
```bash
sudo apt install php8.3-intl php8.3-pgsql php8.3-curl php8.3-mbstring
sudo systemctl restart php8.3-fpm
```

Vérifier que `intl` est chargée :
```bash
php -m | grep intl
```

### 2. Fichier .env
```env
SIMPLE_BOT_UUID=<uuid du bot dans la table bots>
QUEUE_CONNECTION=database
OPENAI_API_KEY=sk-...
```

### 3. Tokens Sanctum à générer
```bash
php artisan tinker
$u = App\Models\User::where('email','admin@chatbot-ia.com')->first();

# Token admin (pour panneau WordPress — jamais exposé public)
echo $u->createToken('admin')->plainTextToken;

# Token widget (exposé dans le HTML public — chat uniquement)
echo $u->createToken('widget-public')->plainTextToken;
```

Ces deux tokens sont ensuite mis dans les paramètres du plugin WordPress :
- **Clé API admin** → token admin
- **Clé widget public** → token widget

### 4. Timeouts (OpenAI peut prendre 30-60s)
```ini
# php.ini / php-fpm.conf
max_execution_time = 120
request_terminate_timeout = 120
```

```nginx
# nginx.conf
fastcgi_read_timeout 120;
proxy_read_timeout 120;
```

### 5. Queue worker (embeddings base de connaissance)
```bash
php artisan queue:work --tries=3 --timeout=120
```

Sans le worker, les documents importés ne génèrent pas d'embeddings et le bot ne s'appuie pas sur la base de connaissance.

### 6. Redis (optionnel)
Le backend utilise Redis pour un cache sémantique (`SemanticCache`).
Si Redis n'est pas disponible, le backend continue de fonctionner normalement — il log un warning et ignore le cache.

---

## Résumé en une phrase

> Le plugin envoie des requêtes HTTP au backend avec `Authorization: Bearer <token>`.
> Le backend doit avoir l'extension `intl`, un bot créé en base, son UUID dans `.env`,
> deux tokens Sanctum générés (un admin, un widget), et les timeouts à 120s.
