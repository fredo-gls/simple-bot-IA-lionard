# Lionard Simple Chat

Plugin WordPress autonome pour le chatbot Lionard de dettes.ca — préqualification et orientation vers un rendez-vous humain.

## Architecture

- Le widget frontend envoie les messages vers une route REST WordPress locale.
- WordPress appelle l'API OpenAI côté serveur ; la clé n'est jamais exposée au navigateur.
- L'historique de conversation est conservé en `localStorage` (20 derniers messages).
- Chaque visiteur reçoit un `session_id` UUID persisté en `localStorage`.
- Les sessions et messages sont enregistrés en base de données WordPress.

## Fonctionnalités

| Fonctionnalité | Détail |
|---|---|
| Chat IA | GPT-4o-mini par défaut, modèle et température configurables |
| Boutons CTA | Syntaxe `[[button:Libellé\|URL]]` avec whitelist de domaines |
| Rendez-vous | Ouverture en modal iframe + tracking du clic |
| Capture e-mail | Gate optionnel avant la conversation, persisté en localStorage |
| Auto-fill Formlift | Email pré-rempli dans le formulaire RDV via paramètre URL |
| Statistiques | Tableau de bord des clics CTA (RDV particulier, entreprise, NovaPlan, Abondance360) |
| Conversations | Vue admin de toutes les sessions avec messages, statut et email visiteur |
| Base de connaissances | Articles indexés et injectés comme contexte dans le prompt |
| Position widget | Configurable desktop (gauche/droite) et mobile (gauche/centre/droite) |
| Avatar | Image personnalisable via la médiathèque WordPress |
| Recommencer | Crée une nouvelle session tout en conservant l'email collecté |

## Installation

1. Copier le dossier `lionard-simple-chat` dans `wp-content/plugins/`.
2. Activer **Lionard Simple Chat** dans l'écran Plugins WordPress.
3. Aller dans **Lionard Chat → Réglages**.
4. Saisir la clé API OpenAI.
5. Cocher **Activer le widget**.
6. Adapter le prompt système si nécessaire.

Shortcode disponible si le widget automatique est désactivé :

```text
[lionard_simple_chat]
```

## Onglets admin

| Onglet | Contenu |
|---|---|
| **Réglages** | Clé API, modèle, rate limit, URLs RDV, capture e-mail, recherche site |
| **Apparence** | Avatar, couleurs, textes, position desktop/mobile |
| **Prompt** | Prompt système envoyé à OpenAI |
| **Connaissances** | Base de connaissances injectée comme contexte |
| **Conversations** | Historique des sessions visiteurs |
| **Statistiques** | Compteurs de clics sur les boutons CTA |

## Sécurité

- Endpoint REST protégé par vérification origin/nonce.
- Rate limit par IP configurable (nombre de requêtes / fenêtre en secondes).
- Longueur des messages visiteurs limitée côté serveur.
- Clé OpenAI jamais exposée au navigateur.
- Boutons CTA limités aux domaines configurés dans la whitelist.
- Historique client traité comme contexte non fiable.
- Requêtes SQL toujours préparées via `$wpdb->prepare()`.
- IP déterminée par `REMOTE_ADDR` uniquement (pas de header proxy spoofable).
- Email visiteur validé côté serveur (`is_email()`) et côté client (regex) avant toute écriture.

## Syntaxe des boutons CTA

Le modèle génère les boutons avec :

```text
[[button:Libellé|https://exemple.com]]
```

Les domaines autorisés se configurent dans **Réglages → Domaines autorisés pour les CTA**.  
Le domaine du site WordPress est toujours autorisé automatiquement.

## Base de données

Le plugin crée trois tables (préfixe WordPress inclus) :

| Table | Contenu |
|---|---|
| `lsc_sessions` | Une ligne par session visiteur (session_key, statut, email, URLs) |
| `lsc_messages` | Messages de chaque session (rôle, contenu, horodatage) |
| `lsc_cta_events` | Clics sur les boutons CTA (type, URL, session, horodatage) |

La migration de schéma est non-destructive (`dbDelta`). Pour forcer une mise à jour du schéma après une montée de version, désactiver puis réactiver le plugin.

## Variables JavaScript injectées

Disponibles sous `window.LionardSimpleChat` :

| Variable | Type | Description |
|---|---|---|
| `restUrl` | string | URL de base des endpoints REST |
| `nonce` | string | Nonce WordPress REST |
| `primaryColor` | string | Couleur principale (hex) |
| `accentColor` | string | Couleur d'accent (hex) |
| `greeting` | string | Message d'accueil initial |
| `allowedHosts` | string[] | Domaines autorisés pour les CTA |
| `rdvPersonnelUrl` | string | URL formulaire RDV particulier |
| `rdvEntrepriseUrl` | string | URL formulaire RDV entreprise |
| `rdvCloseChat` | bool | Fermer le chat au clic RDV |
| `rdvKeepClosed` | bool | Garder le chat fermé après RDV |
| `emailGateEnabled` | bool | Activer la capture e-mail |
| `emailGateTitle` | string | Texte affiché dans le gate e-mail |
| `strings` | object | Libellés traduits (input, send, restart, error) |
