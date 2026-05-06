# Moteur d'auto-apprentissage — Lionard

Idée : un système backend qui analyse les conversations stockées, détecte les problèmes et propose des améliorations automatiques au prompt et aux règles. Claude API est le cerveau d'analyse.

---

## Architecture cible

```
Conversations (DB)
       ↓
ConversationAnalyzerJob  (Laravel scheduler — nuit)
       ↓
Claude API claude-opus   (méta-prompt "auditeur chatbot commercial")
       ↓
ConversationAnalysis     (rapport structuré JSON en DB)
       ↓
PromptSuggestion         (pending → approved → applied)
       ↓
system_prompt mis à jour en DB automatiquement
```

---

## Phase 1 — Analyse des conversations

**Job :** `AnalyzeConversationsJob`
**Service :** `ConversationAuditor`

Le job tourne chaque nuit, échantillonne les conversations récentes (ex. 50 dernières) et envoie à Claude un méta-prompt du type :

> "Tu es un expert en optimisation de chatbots commerciaux. Analyse ces conversations et identifie :
> 1. Les décrochages (visiteur qui arrête de répondre)
> 2. Les questions mal posées ou hors contexte
> 3. Les leads qualifiés qui n'ont pas converti
> 4. Les réponses robotiques ou redondantes
> 5. Les règles du prompt ignorées ou mal appliquées
>
> Pour chaque problème, propose une correction concrète au prompt ou aux règles.
> Retourne un JSON structuré."

**Migration :** `conversation_analyses`
```
id, created_at
sample_size          (int)
date_from / date_to  (date)
report               (json) — liste de findings avec priority/issue/suggestion/conversation_uuid
model_used           (string)
tokens_used          (int)
```

---

## Phase 2 — Suggestions avec approbation humaine

Chaque finding génère une `PromptSuggestion`. Un humain valide avant application.

**Migration :** `prompt_suggestions`
```
id, created_at, updated_at
conversation_analysis_id (FK)
type                 (prompt_tweak | rule_change | flow_logic | tone_fix)
priority             (high | medium | low)
issue_description    (text)
current_value        (text) — extrait actuel du prompt ou de la règle
suggested_value      (text) — version améliorée proposée par Claude
conversation_excerpt (text) — extrait de conversation qui a motivé la suggestion
status               (pending | approved | rejected | applied)
applied_at           (timestamp nullable)
approved_by          (string nullable)
```

**Routes admin :**
- `GET /api/admin/prompt-suggestions` — lister avec filtre par statut/priorité
- `POST /api/admin/prompt-suggestions/{id}/approve` — approuver + appliquer
- `POST /api/admin/prompt-suggestions/{id}/reject` — rejeter avec motif

---

## Phase 3 — Auto-application conditionnelle (optionnel, plus tard)

Certains types de changements peuvent s'appliquer sans validation humaine :
- `tone_fix` — ajustement du ton émotionnel, exemples de phrases
- `prompt_tweak` — reformulation d'une instruction sans changer la logique

Nécessitent toujours validation humaine :
- `flow_logic` — modifications de la logique de qualification
- `rule_change` — modification des règles métier (gardes-fous, CTA, triage)

---

## Fichiers à créer

```
app/Jobs/AnalyzeConversationsJob.php
app/Services/ConversationAuditor.php
app/Models/ConversationAnalysis.php
app/Models/PromptSuggestion.php
database/migrations/xxxx_create_conversation_analyses_table.php
database/migrations/xxxx_create_prompt_suggestions_table.php
app/Http/Controllers/SimpleApi/PromptSuggestionController.php
```

---

## Exemple de méta-prompt complet pour Claude

```
Tu es un expert senior en optimisation de chatbots commerciaux spécialisés en qualification de leads.

Tu vas analyser un échantillon de conversations réelles entre Lionard (chatbot de dettes.ca) et des visiteurs.

Contexte :
- Lionard qualifie des visiteurs en difficulté financière pour proposer un rendez-vous avec une conseillère.
- L'objectif est la qualité des rendez-vous, pas le volume.
- Le flow de qualification : triage → structure de dette → urgence → capacité de paiement → montant → CTA.

Pour chaque conversation, identifie :
1. Décrochage : à quel message le visiteur a arrêté de répondre et pourquoi ?
2. Redondance : une question posée alors que la réponse était déjà dans le contexte ?
3. Opportunité manquée : un signal fort ignoré (urgence, honte, découragement) ?
4. Robotisat ion : une réponse qui sonnait comme un formulaire plutôt qu'un humain ?
5. Erreur de qualification : lead qualifié non converti, ou non-qualifié poussé vers RDV ?

Retourne un JSON avec cette structure :
{
  "findings": [
    {
      "priority": "high|medium|low",
      "type": "prompt_tweak|rule_change|flow_logic|tone_fix",
      "issue": "description du problème",
      "conversation_excerpt": "extrait de la conversation concernée",
      "current_behavior": "ce que le bot fait actuellement",
      "suggested_fix": "correction concrète du prompt ou de la règle"
    }
  ],
  "global_score": 0-100,
  "top_issue": "résumé du problème principal en 1 phrase"
}
```

---

## Notes

- Modèle recommandé : `claude-opus-4-7` (meilleure analyse, coût acceptable en batch nocturne)
- Taille d'échantillon : 20-50 conversations par run, prioriser les conversations avec flow_state != '' (qualification engagée)
- Ne pas inclure les PII (noms, emails) dans les extraits envoyés à Claude
- Conserver un historique de toutes les suggestions pour voir l'évolution du bot dans le temps
