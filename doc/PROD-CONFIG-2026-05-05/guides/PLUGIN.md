# Architecture Plugin WordPress — Chatbot Lionard

> Document technique à destination de l'équipe dev.
> Version : 1.0.0 — Plugin WordPress standard (pas de framework).

---

## Sommaire

1. [Vue d'ensemble](#1-vue-densemble)
2. [Structure des fichiers](#2-structure-des-fichiers)
3. [Flux de démarrage du plugin](#3-flux-de-démarrage-du-plugin)
4. [Interface admin](#4-interface-admin)
5. [Widget front-end](#5-widget-front-end)
6. [Communication avec le backend](#6-communication-avec-le-backend)
7. [Stockage des options WordPress](#7-stockage-des-options-wordpress)
8. [Points d'extension](#8-points-dextension)

---

## 1. Vue d'ensemble

Le plugin est volontairement minimal. Il fait trois choses :

1. **Admin** — Interface de configuration (URL backend, apparence, base de connaissance)
2. **Front** — Injection du widget JS sur toutes les pages publiques
3. **Widget JS** — Gère la conversation (UI, appels API, session)

```
WordPress Admin                          Site public (visiteur)
─────────────────────────────────        ──────────────────────────────
Chatbot Lionard
  ├─ Tableau de bord                     <footer>
  ├─ Connexion        ──sauvegarde──►      <div id="chatboot-ia-root">
  ├─ Apparence          wp_options         chatbot-widget.js
  └─ Base de connaissance                  chatboot-widget.css
       ├─ Bouton "Scanner"  ─────────────► POST /api/scan
       ├─ Import texte      ─────────────► POST /api/knowledge/import
       └─ Statut index      ◄───────────── GET  /api/knowledge/status
                                           POST /api/chat  (conversation)
```

---

## 2. Structure des fichiers

```
chatbot-lionard/
├── chatbot-lionard.php                 Point d'entrée WordPress
│
├── includes/
│   └── class-chatbot-lionard.php       Classe principale — bootstraps les deux sous-classes
│
├── admin/
│   └── class-chatbot-lionard-admin.php Tout ce qui est admin (menu, settings, pages)
│
├── public/
│   └── class-chatbot-lionard-public.php Injection du widget sur le front
│
└── assets/
    ├── css/chatboot-widget.css          Styles du widget (bubble, panel, messages)
    └── js/chatbot-widget.js             Widget JS (UI + appels API)
```

---

## 3. Flux de démarrage du plugin

```
WordPress bootstrap
  └─ run_chatbot_lionard()                          chatbot-lionard.php
       └─ new Chatbot_Lionard()                     includes/class-chatbot-lionard.php
            ├─ new Chatbot_Lionard_Admin()
            ├─ new Chatbot_Lionard_Public()
            ├─ register_activation_hook → activate() (initialise les options)
            └─ run()
                 ├─ Admin::register()
                 │   ├─ add_action('admin_menu', register_menu)
                 │   ├─ add_action('admin_init', register_settings)
                 │   └─ add_action('admin_enqueue_scripts', enqueue_assets)
                 └─ Public::register()
                     ├─ add_action('wp_enqueue_scripts', enqueue_assets)
                     ├─ add_action('wp_footer', render_widget)
                     └─ add_shortcode('chatbot_lionard', render_widget_shortcode)
```

**À l'activation du plugin** (`activate()`), les options sont initialisées avec leurs valeurs par défaut via `add_option()` (n'écrase pas les valeurs existantes).

---

## 4. Interface admin

### Pages disponibles

| Slug | Méthode | Contenu |
|------|---------|---------|
| `chatbot-lionard` | `render_dashboard()` | Statut backend + bouton test connexion |
| `chatbot-lionard-settings` | `render_settings()` | URL backend + clé API + activer widget |
| `chatbot-lionard-appearance` | `render_appearance()` | Nom, titre, couleur, avatar, position, message d'accueil |
| `chatbot-lionard-knowledge` | `render_knowledge()` | Scanner WP + import texte + statut index |

### Groupes de settings WordPress

Les options sont organisées en deux groupes pour `settings_fields()` :

| Groupe | Options incluses |
|--------|-----------------|
| `chatbot_lionard_settings` | `backend_url`, `api_key`, `active` |
| `chatbot_lionard_appearance` | `widget_color`, `widget_position`, `widget_mob_position`, `widget_name`, `widget_title`, `widget_greeting`, `widget_avatar`, `offers_url` |

### JavaScript admin (inline)

Tout le JS admin est injecté via `wp_add_inline_script('jquery', ...)` dans `enqueue_assets()`. Un seul bloc JS gère les trois fonctionnalités interactives :

**Test de connexion (tableau de bord)**
```
Clic "Tester" → GET /api/health → affiche le JSON de réponse
```

**Import texte (page connaissance)**
```
Clic "Importer" → POST /api/knowledge/import { title, content }
               → vide le formulaire si succès
               → recharge les stats après 3s
```

**Rendu des stats**
```
GET /api/knowledge/status → 4 cartes (total/indexés/en attente/erreurs)
                         → tableau des sources
                         → statut + date du dernier scan
```

Les variables PHP sont injectées dans le JS via interpolation de string PHP :
```php
$backendUrl = esc_url_raw(get_option('chatbot_lionard_backend_url', ''));
$apiKey     = esc_js(get_option('chatbot_lionard_api_key', ''));
$siteUrl    = esc_js(home_url());
```

---

## 5. Widget front-end

### Injection

`Chatbot_Lionard_Public::enqueue_assets()` s'exécute sur `wp_enqueue_scripts`. Si l'option `chatbot_lionard_active !== '1'`, rien n'est chargé.

La configuration est passée au JS via `wp_localize_script()` sous le nom global `ChatbootIAConfig` :

```js
window.ChatbootIAConfig = {
    backendUrl:           "https://api.mondomaine.com",
    apiKey:               "",           // vide si pas de clé
    active:               true,
    widgetColor:          "#1d6ddb",
    widgetPosition:       "right",
    widgetMobilePosition: "center",
    widgetName:           "Assistant",
    widgetTitle:          "Assistant virtuel",
    widgetGreeting:       "Bonjour ! Comment puis-je vous aider ?",
    widgetAvatar:         "",
    offersUrl:            "",
}
```

`render_widget()` injecte `<div id="chatboot-ia-root"></div>` dans le footer. Le JS construit tout le HTML du widget à l'intérieur.

### Shortcode

```
[chatbot_lionard]
```

Injecte le même `<div id="chatboot-ia-root">` à l'endroit voulu dans une page.

---

## 6. Communication avec le backend

### Gestion de session

Le widget stocke un `session_id` dans `localStorage` (clé `chatboot_ia_session_id`). Ce `session_id` est envoyé à chaque message. Le backend l'utilise pour retrouver ou créer la `Conversation` correspondante.

```js
function getSessionId() {
    let id = localStorage.getItem('chatboot_ia_session_id');
    if (!id) {
        id = 'sess-' + Math.random().toString(36).slice(2) + Date.now();
        localStorage.setItem('chatboot_ia_session_id', id);
    }
    return id;
}
```

Le `session_id` est **permanent** (survit aux rechargements de page) jusqu'à ce que l'utilisateur clique sur "Relancer" — qui appelle `localStorage.removeItem('chatboot_ia_session_id')`, forçant la création d'une nouvelle conversation.

### Envoi d'un message

```js
// POST /api/chat

// Header
Referer: window.location.href
Content-Language: fr

// Payload envoyé
{
    message:    "le texte de l'utilisateur",
    session_id: "sess-abc123",
}

// Réponse attendue
{ "reply": "La réponse du bot", "session_id": "sess-abc123" }
```

### Headers envoyés

```js
{
    'Content-Type':        'application/json',
    'Accept':              'application/json',
    'Authorization':       'Bearer ' . config.apiKey || '',
    'Content-Language':    'fr'
}
```

Si `config.apiKey` est vide, le header est envoyé vide .

### Rendu des réponses

Le widget supporte un format enrichi dans les réponses du bot :

| Syntaxe dans la réponse | Rendu |
|-------------------------|-------|
| `[[button:Label\|https://url]]` | Bouton CTA cliquable |
| `[texte](https://url)` | Lien Markdown |
| `https://url` (dans le texte) | Lien automatique |
| `\n` | `<br>` |

Les suggestions rapides sont générées automatiquement selon le contenu de la réponse (mots-clés `offre`, `formation`, `remboursement`, `connexion`...) et pointent vers `config.offersUrl` si défini.

---

## 7. Stockage des options WordPress

Toutes les options sont stockées dans la table `wp_options` de WordPress via l'API native.

| Option | Type | Défaut |
|--------|------|--------|
| `chatbot_lionard_backend_url` | string (URL) | `''` |
| `chatbot_lionard_api_key` | string | `''` |
| `chatbot_lionard_active` | string `'0'`/`'1'` | `'0'` |
| `chatbot_lionard_widget_color` | string (hex) | `'#1d6ddb'` |
| `chatbot_lionard_widget_position` | string | `'right'` |
| `chatbot_lionard_widget_mob_position` | string | `'center'` |
| `chatbot_lionard_widget_name` | string | `'Assistant'` |
| `chatbot_lionard_widget_title` | string | `'Assistant virtuel'` |
| `chatbot_lionard_widget_greeting` | string | `'Bonjour ! Comment puis-je vous aider ?'` |
| `chatbot_lionard_widget_avatar` | string (URL) | `''` |
| `chatbot_lionard_offers_url` | string (URL) | `''` |

Toutes les options sont lues avec `get_option($name, $default)` et sanitisées à l'écriture via `register_setting()`.

---

## 8. Points d'extension

### Ajouter une page admin

Dans `register_menu()`, ajouter un `add_submenu_page()` et la méthode `render_*()` correspondante. Créer un nouveau groupe de settings dans `register_settings()` si nécessaire.

### Modifier l'apparence du widget

Éditer `assets/css/chatboot-widget.css`. Les classes principales :

| Classe | Élément |
|--------|---------|
| `.chatboot-ia-root` | Conteneur racine (position fixed) |
| `.chatboot-ia-toggle` | Bouton d'ouverture |
| `.chatboot-ia-panel` | Fenêtre de chat |
| `.chatboot-ia-header` | En-tête du chat |
| `.chatboot-ia-messages` | Zone des messages |
| `.chatboot-ia-message-user` | Bulle message utilisateur |
| `.chatboot-ia-message-bot` | Bulle message bot |
| `.chatboot-ia-cta` | Bouton d'action rapide |
| `.chatboot-ia-form` | Formulaire de saisie |

La couleur principale est définie en CSS et peut être surchargée dynamiquement via `config.widgetColor` (injectée en CSS inline si nécessaire — à implémenter si besoin).

### Ajouter un endpoint API côté plugin

Le plugin peut appeler n'importe quel endpoint du backend en suivant le pattern déjà en place dans le JS admin :

```js
fetch(API + '/api/mon-endpoint', {
    method: 'POST',
    headers: apiHeaders(),
    body: JSON.stringify({ ... }),
})
.then(r => r.json())
.then(data => { ... });
```

### Changer l'URL du backend dynamiquement par page

Surcharger `ChatbootIAConfig.backendUrl` dans un hook `wp_enqueue_scripts` avec une priorité plus haute, ou utiliser `wp_add_inline_script` pour injecter un override après le `wp_localize_script` du plugin.

### Désactiver le widget sur certaines pages

Dans un thème ou autre plugin :
```php
add_filter('chatbot_lionard_is_active', function($active) {
    if (is_page('contact')) return false;
    return $active;
});
```

> Ce filtre n'est pas encore implémenté dans la version actuelle — à ajouter dans `Chatbot_Lionard_Public::enqueue_assets()` si nécessaire :
> ```php
> $active = apply_filters('chatbot_lionard_is_active', get_option('chatbot_lionard_active') === '1');
> ```
