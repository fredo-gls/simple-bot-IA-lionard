# Intégration Formlift — Guide technique

## Contexte et objectif

L'objectif est de permettre à l'utilisateur de remplir le formulaire de prise de contact
(Formlift/Keap) **directement dans le chat**, sans quitter la conversation ni remplir
ses informations deux fois.

### Chaîne actuelle (sans intégration)

```
Chat → bouton "Prendre RDV" → page dettes.ca/clinique-liberte → Formlift → Keap/Infusionsoft → OnceHub (calendrier)
```

Le problème : l'utilisateur quitte le chat, remplit le formulaire Formlift,
puis Infusionsoft le redirige vers OnceHub où il doit choisir un créneau.
Deux étapes distinctes, risque d'abandon entre les deux.

### Objectif

```
Chat → modal in-chat (Formlift en iframe) → soumission → OnceHub ouvert en nouvel onglet
                                                        OU lien OnceHub affiché dans le chat
```

---

## Architecture cible

### Deux profils — deux formulaires

| Profil       | Formulaire Formlift    | Configuré dans admin via |
|---|---|---|
| Particulier  | shortcode Formlift #1  | Bot → Liens → purpose = `form_particulier` |
| Entreprise   | shortcode Formlift #2  | Bot → Liens → purpose = `form_entreprise`  |

L'admin saisit les shortcodes Formlift dans le panneau admin du widget.
Le chat demande d'abord le type de profil avant d'afficher le bon formulaire.

---

## Phase 1 — Iframe Formlift dans le chat (sans API Keap)

### Ce qu'on construit

1. **Admin** — deux champs pour les URLs de formulaires (particulier / entreprise)
2. **Chat widget JS** — modal qui affiche le bon iframe selon le profil choisi
3. **postMessage** — écoute la soumission du formulaire pour déclencher l'étape suivante
4. **Gestion du redirect OnceHub** — ouverture en nouvel onglet automatique

### Flux utilisateur

```
1. Bot propose le formulaire après qualification (lead chaud / tiède)
2. Widget affiche : "Êtes-vous un particulier ou une entreprise ?"
   → Particulier | Entreprise  (deux boutons)
3. Selon le choix → iframe Formlift correspondant s'affiche dans un modal in-chat
4. Utilisateur remplit le formulaire
5. Soumission → Formlift envoie à Keap/Infusionsoft
6. Infusionsoft redirige vers OnceHub → détecté par le widget
   → OnceHub s'ouvre dans un nouvel onglet
   → Message dans le chat : "Votre demande est enregistrée ! Un lien de calendrier vient de s'ouvrir."
```

### Challenge cross-origin

Le redirect vers OnceHub se passe DANS l'iframe (cross-origin).
Le widget ne peut pas lire l'URL de l'iframe directement (bloqué par le navigateur).

**Solution :** écouter l'événement `message` (postMessage) depuis la page Formlift.
Formlift doit envoyer un message au parent au moment de la soumission.

Si Formlift ne supporte pas postMessage nativement → utiliser un proxy :
ajouter un snippet JS sur la page WordPress qui héberge le formulaire Formlift
pour détecter la soumission et envoyer `window.parent.postMessage(...)`.

---

## Implémentation technique

### 1. Admin — configuration des URLs de formulaires

**Option recommandée :** utiliser le système `bot_links` existant.

Ajouter dans le panneau admin deux liens avec les purposes suivants :
- `form_particulier` → URL de la page WordPress qui contient le shortcode Formlift particulier
- `form_entreprise`  → URL de la page WordPress qui contient le shortcode Formlift entreprise

Ces URLs sont déjà remontées au widget via l'API `/api/widget/{uuid}/init`.

Pas de nouveau champ en base — réutiliser la table `bot_links`.

### 2. Widget JS — modal de formulaire

```javascript
// Déclenchement : quand le bot envoie un message avec action spéciale
// ou quand l'utilisateur clique sur un bouton "Remplir le formulaire"

function showFormModal(profileType) {
  const formUrl = profileType === 'entreprise'
    ? window.lionardConfig.formEntrepriseUrl
    : window.lionardConfig.formParticulierUrl;

  if (!formUrl) return;

  // Créer le modal
  const modal = document.createElement('div');
  modal.className = 'lionard-form-modal';
  modal.innerHTML = `
    <div class="lionard-form-modal-inner">
      <button class="lionard-form-modal-close">×</button>
      <iframe
        src="${formUrl}"
        class="lionard-form-iframe"
        frameborder="0"
        allow="payment"
      ></iframe>
    </div>
  `;
  document.body.appendChild(modal);

  // Écouter la soumission via postMessage
  window.addEventListener('message', onFormMessage);
}

function onFormMessage(event) {
  // Vérifier l'origine (domaine WordPress dettes.ca)
  if (!event.origin.includes('dettes.ca')) return;

  if (event.data?.type === 'formlift_submitted') {
    closeFormModal();
    showChatMessage('Votre demande est enregistrée. Un lien de calendrier vient de s\'ouvrir.');

    // Ouvrir OnceHub si l'URL est fournie dans le message
    if (event.data.oncehubUrl) {
      window.open(event.data.oncehubUrl, '_blank');
    }
  }
}
```

### 3. Snippet JS WordPress — émettre postMessage à la soumission

À ajouter dans le footer de la page WordPress qui héberge le formulaire Formlift.
Détecte la soumission du formulaire et envoie un message au parent (le widget).

```javascript
// À insérer dans le <head> ou footer de la page Formlift sur WordPress
(function() {
  // Écouter le redirect post-soumission (changement d'URL dans l'iframe)
  // Formlift déclenche généralement un événement ou une redirection

  // Option A : si Formlift supporte un hook JS natif
  if (window.FormliftEvents) {
    window.FormliftEvents.on('submit_success', function(data) {
      window.parent.postMessage({
        type: 'formlift_submitted',
        email: data.email || null,
        oncehubUrl: 'https://oncehub.com/xxx' // URL fixe ou dynamique
      }, '*');
    });
  }

  // Option B : détecter le changement de contenu de la page (fallback)
  // Si Formlift affiche un message de confirmation après soumission
  const observer = new MutationObserver(function() {
    const confirmation = document.querySelector('.formlift-confirmation, .wpcf7-response-output.wpcf7-mail-sent-ok');
    if (confirmation) {
      observer.disconnect();
      window.parent.postMessage({
        type: 'formlift_submitted',
        oncehubUrl: document.querySelector('[data-oncehub-url]')?.dataset.oncehubUrl || null
      }, '*');
    }
  });
  observer.observe(document.body, { childList: true, subtree: true });
})();
```

### 4. Initialisation du widget — exposer les URLs de formulaires

Dans la réponse de `/api/widget/{uuid}/init`, ajouter les URLs :

```json
{
  "bot": { ... },
  "links": [
    { "purpose": "rdv",              "url": "https://...", "title": "Prendre rendez-vous" },
    { "purpose": "form_particulier", "url": "https://dettes.ca/formulaire-particulier", "title": "Formulaire particulier" },
    { "purpose": "form_entreprise",  "url": "https://dettes.ca/formulaire-entreprise",  "title": "Formulaire entreprise"  }
  ]
}
```

Le widget JS lit ces links au démarrage et les stocke dans `window.lionardConfig`.

### 5. Déclenchement depuis le bot

Le bot peut déclencher l'ouverture du modal via un message avec une action spéciale.

**Option A :** syntaxe bouton existante avec purpose spécial
```
[[button:Remplir le formulaire|action:show_form]]
```

**Option B :** message système avec type `form_trigger` dans le payload
```json
{ "type": "form_trigger", "profile": "particulier" }
```

Le widget intercepte ce type de message et ouvre le modal plutôt que de l'afficher comme texte.

---

## Phase 2 — Intégration API Keap (quand credentials disponibles)

### Ce qu'on construit

Bypasser entièrement Formlift. Le chat collecte les données directement et les pousse
dans Keap via l'API, puis fournit le lien OnceHub dans le chat.

### Flux

```
1. Bot qualifie le lead (prénom, email, téléphone — 3 questions max)
2. Backend Laravel → API Keap → crée le contact
3. Bot affiche le lien OnceHub directement dans le chat
4. Utilisateur clique → choisit son créneau sur OnceHub
```

### Avantages

- Une seule étape pour l'utilisateur
- Données de qualification (montant, urgence, type de dette) ajoutées automatiquement
  comme tags ou champs personnalisés dans Keap
- Pas de double saisie, pas d'iframe, pas de cross-origin

### Données à collecter avant l'appel API

| Champ | Source |
|---|---|
| Prénom | Question directe dans le chat |
| Email | Question directe dans le chat |
| Téléphone | Question directe (optionnel) |
| Type de dette | SlotExtractor (déjà en session) |
| Montant | SlotExtractor (déjà en session) |
| Urgence | SlotExtractor (déjà en session) |
| Capacité de paiement | SlotExtractor (déjà en session) |

### Endpoint backend à créer

```
POST /api/widget/{uuid}/lead
Body: { prenom, email, telephone, slots }
→ Appel API Keap
→ Retourne { success: true, oncehubUrl: "..." }
```

### Questions à résoudre avant de coder Phase 2

1. L'URL OnceHub est-elle fixe ou dépend-elle du contact créé dans Keap ?
2. Keap envoie-t-il automatiquement l'email de confirmation avec le lien OnceHub ?
3. Quelles tags/custom fields sont attendus dans Keap pour le routing interne ?
4. Quelle est la clé API Keap et quel environnement (sandbox / prod) ?

---

## Plan de développement

### Phase 1 — Iframe Formlift (à faire maintenant)

- [ ] Ajouter deux bot_links `form_particulier` et `form_entreprise` en admin
- [ ] Widget JS : composant modal avec iframe
- [ ] Widget JS : écoute postMessage + ouverture OnceHub en nouvel onglet
- [ ] Snippet JS WordPress : émettre postMessage à la soumission Formlift
- [ ] Widget JS : question de profil (Particulier / Entreprise) avant d'afficher le formulaire
- [ ] Test end-to-end sur un lead qualifié réel

### Phase 2 — API Keap (quand credentials disponibles)

- [ ] Obtenir clé API Keap + documenter les custom fields attendus
- [ ] Créer endpoint `POST /api/widget/{uuid}/lead` dans Laravel
- [ ] Ajouter collecte prénom/email/téléphone dans le flow du bot
- [ ] Widget JS : appel endpoint + affichage lien OnceHub
- [ ] Désactiver les bot_links `form_*` une fois Phase 2 opérationnelle

---

## Fichiers concernés

| Fichier | Rôle |
|---|---|
| `app/Http/Controllers/AdminApi/BotLinkController.php` | Validation des purposes form_* |
| `app/Http/Controllers/WidgetApi/InitController.php` (ou équivalent) | Exposer les links au widget |
| `E:\BUILDAPP\www\wordpress\wp-content\plugins\wordpress-plugin-chatboot-ia\assets\js\` | Widget JS (modal, postMessage) |
| Page WordPress Formlift | Snippet JS de détection de soumission |
| `.env` | Clé API Keap (Phase 2) |
