# Lionard Simple Chat

Plugin WordPress autonome pour afficher le chatbot Lionard sans backend Laravel.

## Fonctionnement

- Le widget public envoie les messages vers une route REST WordPress locale.
- WordPress appelle OpenAI cote serveur avec la cle configuree dans l'admin.
- La cle OpenAI n'est jamais exposee dans le JavaScript.
- Le plugin utilise l'API OpenAI Responses avec `store: false`.
- L'historique est conserve uniquement en `sessionStorage` cote navigateur.

## Installation

1. Copier le dossier `lionard-simple-chat` dans `wp-content/plugins/`.
2. Activer `Lionard Simple Chat` dans WordPress.
3. Aller dans `Lionard Simple`.
4. Ajouter la cle API OpenAI.
5. Activer le widget.
6. Adapter le prompt systeme si necessaire.

Shortcode disponible :

```text
[lionard_simple_chat]
```

## Securite incluse

- Endpoint REST avec verification origin / nonce.
- Rate limit par IP configurable.
- Limite de longueur du message visiteur.
- Historique court, non persistant en base.
- Pas de backend Laravel.
- Pas de cle API exposee au navigateur.
- Boutons CTA limites aux domaines autorises.
- Historique client traite comme contexte non fiable.

## Syntaxe des boutons

Le modele doit produire les boutons avec :

```text
[[button:Libelle|https://exemple.com]]
```

Les domaines autorises se configurent dans l'admin.

