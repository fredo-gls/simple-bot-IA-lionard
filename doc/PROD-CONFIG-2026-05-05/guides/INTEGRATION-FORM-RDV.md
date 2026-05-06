# Intégration — Formulaire RDV inline dans le chat
**Pour l'équipe widget WordPress · Backend Laravel · Mai 2026**

Ce document décrit le contrat technique entre le widget de chat (WordPress) et le backend Laravel pour afficher un formulaire de prise de rendez-vous **directement dans la conversation**, sans rupture vers une page externe.

---

## 1. Pourquoi ce changement

Aujourd'hui : bot propose RDV → bouton → redirection vers `clinique-liberte` (FormLift) → utilisateur saisit nom/email/tél → Keap reçoit → redirection OnceHub.

Demain : bot propose RDV → **formulaire inline dans la bulle de chat** (avec données déjà collectées pré-remplies) → submit → backend Laravel pousse à Keap (FormLift) en arrière-plan → utilisateur redirigé vers OnceHub avec ses infos déjà pré-remplies dans le calendrier.

Bénéfice attendu : réduction du drop-off au moment du form de 30 à 50 %.

---

## 2. Le marqueur `[[form:rdv]]`

Quand le bot atteint l'état `completed_summary` (qualification terminée, prêt à proposer le RDV), le backend insère le marqueur `[[form:rdv]]` à la fin de sa réponse, à la place du `[[button:Prendre rendez-vous|URL]]` historique.

**Exemple de réponse bot :**
```
Avec environ 25 000 $ et des appels de recouvrement actifs, une conseillère
peut vous donner une vision claire de vos options — gratuit, confidentiel,
sans engagement.

[[form:rdv]]
```

Le widget doit :
1. Détecter ce marqueur lors du parsing du message
2. Le retirer de l'affichage texte
3. Render le formulaire inline à la place

> Si l'admin désactive l'inline form (`CHATBOT_LEAD_FORM_INLINE_ENABLED=false`), le backend retombe sur l'ancien `[[button:...|URL]]`. Le widget doit donc continuer à supporter les deux formats.

---

## 3. Endpoints API

### 3.1 GET — Schéma du formulaire pré-rempli

```
GET /api/v1/widget/conversations/{conversation_uuid}/lead-form
Authorization: Bearer {sanctum_widget_token}
```

**Réponse 200 :**
```json
{
  "data": {
    "form_id": "lead-form-rdv-v1",
    "conversation_uuid": "01HX...",
    "submit_endpoint": "/api/v1/widget/conversations/01HX.../lead-form",
    "submit_label": "Prendre mon rendez-vous gratuit",
    "compliance_text": "J'accepte d'être contacté(e) par Groupe Leblanc Syndic...",
    "fields": [
      {
        "name": "user_type",
        "type": "radio",
        "label": "Vous êtes",
        "required": true,
        "value": "particulier",
        "options": [
          {"value": "particulier", "label": "Particulier"},
          {"value": "entreprise",  "label": "Entreprise"}
        ]
      },
      {
        "name": "first_name",
        "type": "text",
        "label": "Prénom",
        "required": true,
        "value": "",
        "placeholder": "Votre prénom",
        "autocomplete": "given-name",
        "max_length": 100
      },
      {
        "name": "reason",
        "type": "select",
        "label": "Motif du rendez-vous",
        "required": true,
        "value": "recouvrement_urgent",
        "help": "Détecté automatiquement à partir de notre conversation — vous pouvez ajuster.",
        "options": [
          {"value": "cartes_credit",     "label": "Cartes de crédit"},
          {"value": "plusieurs_dettes",  "label": "Plusieurs dettes à consolider"},
          {"value": "recouvrement_urgent","label": "Recouvrement / urgence"},
          ...
        ]
      },
      {
        "name": "appointment_type",
        "type": "radio",
        "label": "Type de rendez-vous",
        "required": true,
        "value": "video",
        "options": [
          {"value": "video", "label": "Visio (recommandé)"},
          {"value": "phone", "label": "Téléphone"}
        ]
      },
      {
        "name": "compliance",
        "type": "checkbox",
        "label": "J'accepte d'être contacté(e)...",
        "required": true,
        "value": false
      }
    ]
  }
}
```

**Champs retournés** (dans l'ordre attendu d'affichage) :

| name              | type     | requis | pré-rempli depuis            |
|-------------------|----------|--------|------------------------------|
| user_type         | radio    | oui    | business memory (défaut: particulier) |
| first_name        | text     | oui    | business memory               |
| last_name         | text     | oui    | business memory               |
| email             | email    | oui    | business memory               |
| phone             | tel      | oui    | business memory               |
| reason            | select   | oui    | déduit des slots qualification |
| appointment_type  | radio    | oui    | défaut: video                 |
| compliance        | checkbox | oui    | toujours false par défaut     |

Le widget doit afficher chaque champ selon son `type`, avec son `value` pré-rempli. Un champ avec `value` non vide doit apparaître renseigné mais reste éditable.

### 3.2 POST — Soumission du formulaire

```
POST /api/v1/widget/conversations/{conversation_uuid}/lead-form
Authorization: Bearer {sanctum_widget_token}
Content-Type: application/json
```

**Body :**
```json
{
  "user_type": "particulier",
  "first_name": "Jean",
  "last_name": "Dupont",
  "email": "jean@example.com",
  "phone": "514 555-1234",
  "reason": "recouvrement_urgent",
  "appointment_type": "video",
  "compliance": true,
  "message": "Optionnel — note libre du visiteur"
}
```

**Réponse 201 (succès, lead créé) :**
```json
{
  "data": {
    "lead_uuid": "01HX...",
    "redirect_url": "https://meetings.oncehub.com/groupe-leblanc-syndic?name=Jean+Dupont&email=jean%40example.com&phone=514+555-1234&note=Type+%3A+Particulier+%7C+Motif+%3A+recouvrement_urgent&utm_source=chatbot...",
    "redirect_target": "oncehub",
    "already_exists": false,
    "message": "Merci ! Vous allez être redirigé(e) vers le calendrier pour choisir votre créneau."
  }
}
```

**Réponse 200 (déjà soumis — idempotency) :**
Même format, `already_exists: true`. Le widget redirige quand même vers `redirect_url`.

**Réponse 422 (validation) :**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["L'adresse courriel ne semble pas valide."],
    "compliance": ["Veuillez accepter les conditions pour confirmer votre rendez-vous."]
  }
}
```

Le widget doit afficher chaque message d'erreur sous le champ correspondant.

### 3.3 Comportement attendu côté widget après soumission

1. Désactiver le bouton submit, afficher un état "loading" (`Envoi en cours...`).
2. POST sur `submit_endpoint`.
3. Si 201 ou 200 → afficher un mini message de confirmation (`message` du payload), attendre 500–800 ms, puis `window.location.href = redirect_url` (ou ouvrir dans un nouvel onglet selon UX souhaitée).
4. Si 422 → ré-activer le formulaire, afficher les erreurs.
5. Si 5xx → fallback : afficher un message « Notre système rencontre un problème, vous pouvez nous joindre directement au 1-877-961-0008 ou sur https://www.dettes.ca/clinique-liberte ».

---

## 4. Validation côté backend

| Champ            | Règles                                                |
|------------------|-------------------------------------------------------|
| user_type        | obligatoire, valeurs : `particulier` ou `entreprise`  |
| first_name       | obligatoire, max 100 chars                            |
| last_name        | obligatoire, max 100 chars                            |
| email            | obligatoire, format email, max 200 chars              |
| phone            | obligatoire, max 30 chars, regex `^[0-9 +()\-\.]{7,30}$` |
| reason           | obligatoire, max 80 chars                             |
| appointment_type | obligatoire, valeurs : `video`, `phone`, `visio`, `téléphone`, `telephone` |
| compliance       | obligatoire, doit être truthy (`true`, `1`, `"on"`)   |
| message          | optionnel, max 1000 chars                             |

---

## 5. Flow technique complet

```
[1] Bot atteint completed_summary
    └─ ContextBuilder injecte [[form:rdv]] dans la réponse
    └─ Widget reçoit le message via SSE / POST /messages

[2] Widget détecte le marqueur
    └─ GET /lead-form → récupère le schéma + valeurs pré-remplies
    └─ Render le form inline dans la bulle de chat

[3] Utilisateur remplit + submit
    └─ POST /lead-form avec les valeurs

[4] Backend Laravel
    ├─ Validation des champs
    ├─ Création de Lead (table `leads`)
    ├─ Mise à jour conversation.context.business + lead_captured
    ├─ Dispatch PushLeadToKeapJob (asynchrone)
    │   └─ Job pousse vers KEAP_FORMLIFT_URL
    │   └─ Si échec → retry 3x avec backoff (10s, 1min, 3min)
    ├─ Émission de l'event LeadCaptured (analytics, dashboard)
    └─ Construction de l'URL OnceHub avec query params (nom, email, tél, note)

[5] Backend retourne redirect_url
    └─ Widget redirige le navigateur vers OnceHub
    └─ Le calendrier OnceHub apparaît avec les champs déjà pré-remplis
```

---

## 6. Configuration .env requise

```env
# Activer/désactiver le formulaire inline (true par défaut)
CHATBOT_LEAD_FORM_INLINE_ENABLED=true

# URL du webhook FormLift (récupérable dans l'admin FormLift WordPress)
KEAP_FORMLIFT_URL=https://www.dettes.ca/?formlift=submit&form_id=XX

# OnceHub — URL de base + suffixes par type
ONCEHUB_BASE_URL=https://meetings.oncehub.com/groupe-leblanc-syndic
ONCEHUB_ROUTE_VIDEO=
ONCEHUB_ROUTE_PHONE=
ONCEHUB_ROUTE_DEFAULT=
```

---

## 7. Mapping FormLift / Keap

Le job `PushLeadToKeapJob` envoie ces champs avec les conventions Infusionsoft :

| Champ envoyé                  | Provient de                       | Custom Field Keap à créer                |
|-------------------------------|-----------------------------------|-------------------------------------------|
| `inf_field_FirstName`         | `first_name`                      | (standard)                                |
| `inf_field_LastName`          | `last_name`                       | (standard)                                |
| `inf_field_Email`             | `email`                           | (standard)                                |
| `inf_field_Phone1`            | `phone`                           | (standard)                                |
| `inf_custom_UserType`         | `user_type`                       | `_UserType`                               |
| `inf_custom_Reason`           | `reason`                          | `_Reason`                                 |
| `inf_custom_AppointmentType`  | `appointment_type`                | `_AppointmentType`                        |
| `inf_custom_Compliance`       | `compliance` (1/0)                | `_Compliance`                             |
| `inf_custom_ChatbotConvId`    | `conversation.id`                 | `_ChatbotConvId`                          |
| `inf_custom_ChatbotLeadUuid`  | `lead.uuid`                       | `_ChatbotLeadUuid`                        |
| `inf_custom_DebtType`         | slot `debt_type`                  | `_DebtType`                               |
| `inf_custom_DebtAmount`       | slot `amount_range`               | `_DebtAmount`                             |
| `inf_custom_Urgency`          | slot `urgency`                    | `_Urgency`                                |
| `inf_custom_Source`           | `chatbot_lionard` (constant)      | `_LeadSource`                             |

**Action côté Keap :**
- Créer les custom fields ci-dessus dans Keap si pas déjà présents
- Ajouter ces champs comme « hidden inputs » dans la config FormLift WordPress du form de référence
- Le shortcode FormLift existant continue de fonctionner pour la page Clinique Liberté — on ne casse rien.

---

## 8. Sécurité

- Toutes les routes sont protégées par Sanctum + rate limit `widget` (default 60/min/IP).
- Idempotency : un même email pour la même conversation ne crée pas de doublon (retourne le Lead existant).
- Le `compliance` est obligatoire (RGPD/Loi 25 Québec).
- Aucun secret Keap exposé côté widget — toute la communication CRM passe par le backend Laravel.

---

## 9. Fallback en cas d'échec

| Cas                               | Comportement                                                |
|-----------------------------------|-------------------------------------------------------------|
| KEAP_FORMLIFT_URL non configurée  | Lead créé en BDD, log warning, redirect OnceHub OK          |
| FormLift retourne 5xx             | Job retry 3x ; lead reste en BDD avec `keap_export_status=failed` |
| OnceHub URL non configurée        | Fallback sur `CHATBOT_QUALIFICATION_RDV_URL`                |
| Validation échoue                 | 422 avec messages d'erreur en français                      |
| Conversation introuvable          | 404                                                         |

Aucun cas ne perd un lead : tout est tracé en BDD `leads`, retraitable via l'admin.

---

## 10. Tests à effectuer côté widget

- [ ] Détection du marqueur `[[form:rdv]]` dans une réponse SSE streamée
- [ ] Affichage progressif du form (les champs pré-remplis ne flashent pas)
- [ ] Saisie + submit → redirection OnceHub avec params visibles dans l'URL
- [ ] Resoumission identique → bonne gestion de l'idempotency
- [ ] Erreurs de validation 422 → messages affichés sous chaque champ
- [ ] Mode `CHATBOT_LEAD_FORM_INLINE_ENABLED=false` → bouton classique fonctionne encore
- [ ] Test mobile (clavier qui pousse le viewport, autocomplete iOS/Android)

---

*Document maintenu par l'équipe backend — toute évolution du contrat doit être versionnée (`form_id` actuel = `lead-form-rdv-v1`).*
