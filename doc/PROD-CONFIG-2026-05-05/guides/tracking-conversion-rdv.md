# Tracking Conversion RDV

## Objectif
Mettre en place une prise de RDV dans le chat, avec distinction `particulier` vs `entreprise`, envoi vers la bonne liaison Keap (Infusionsoft), et envoi automatique d un resume par email au conseiller.

## Decision cle
- Envoi email: **backend** (pas le front/site)
- Sync Keap: **backend**
- Front: capture + soumission des donnees uniquement

## Pourquoi backend
- Secrets proteges (SMTP/API)
- Retry via queue en cas d echec
- Logs et audit (preuve d envoi)
- Routage centralise (particulier/entreprise)
- Evite CORS et limitations du front-only

## Flux cible
1. User discute dans le chat.
2. Bot detecte le canal: `particulier` ou `entreprise`.
3. Bot ouvre le bon formulaire inline dans le chat.
4. Front soumet au backend.
5. Backend enregistre la demande RDV + resume conversation.
6. Backend declenche:
   - sync Keap via la bonne liaison
   - email resume au bon conseiller
7. Backend enregistre la conversion et le statut d envoi.

## Differenciation des canaux RDV
- `individual` (particulier)
- `business` (entreprise)

Regles de routage possibles:
- par `bot_id`
- par `site_id`
- par type detecte dans la conversation
- par campagne/segment

## Donnees minimales a stocker
### appointment_requests
- conversation_id
- lead_id
- channel (`individual|business`)
- form_key
- status (`started|submitted|synced|failed`)
- scheduled_at (nullable)
- keap_connection_id (nullable)
- keap_contact_id (nullable)
- advisor_email_sent_at (nullable)
- advisor_email_status (`pending|sent|failed`)
- advisor_email_error (nullable)

### appointment_summaries
- appointment_request_id
- summary_json (snapshot qualification)

### conversion_events
- appointment_request_id
- event_type (`appointment_started|appointment_submitted|keap_synced|advisor_email_sent`)
- metadata_json
- created_at

## Resume conseiller (email)
Contenu recommande:
- Type RDV: Particulier/Entreprise
- Coordonnees contact
- Disponibilites / creneau souhaite
- Niveau urgence
- Resume qualification (dettes, capacite, objectif)
- Lien vers conversation admin
- Date/heure de soumission

## Contrat Front / Backend
### Front
- capture des champs
- appelle l API backend
- affiche confirmation utilisateur

### Backend
- valide et persiste
- route vers la bonne liaison Keap
- envoie email conseiller
- tracke la conversion

## Endpoints MVP proposes
- `POST /api/chat/appointment/start`
- `POST /api/chat/appointment/submit`
- `GET /api/chat/appointment/{id}/status`

## KPI conversion
- taux ouverture formulaire
- taux soumission formulaire
- taux sync Keap reussie
- taux email conseiller envoye
- delai moyen soumission -> envoi email

## Risques a couvrir
- mauvais canal (particulier/entreprise)
- doublons contact Keap
- echec SMTP/API
- perte de donnees si pas de queue/retry

## Priorite implementation
1. Persistance RDV + events conversion
2. Detection canal + routage formulaire
3. Email resume conseiller (queue + retry)
4. Sync Keap multi-liaisons
5. Dashboard KPI conversion
