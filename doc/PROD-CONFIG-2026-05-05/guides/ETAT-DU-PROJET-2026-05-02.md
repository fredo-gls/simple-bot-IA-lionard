# État du projet — Lionard Chatbot
**Dernière mise à jour : 2 mai 2026**

---

## 1. Vue d'ensemble du projet

**Lionard** est le chatbot conversationnel de **Groupe Leblanc Syndic (GLS)**, déployé sur dettes.ca.

### Stack technique
- Laravel 13, PHP 8.3, architecture DDD
- OpenAI GPT-4o (génération de réponses) + GPT-4o-mini (extraction, raffinement)
- RAG avec pgvector (PostgreSQL)
- OpenAI Tool Calling pour l'extraction structurée de slots
- SSE streaming pour l'UX temps réel
- Laravel Horizon (jobs asynchrones recommandé)

### Identité du bot
- **Nom** : Lionard
- **Persona** : Conseiller empathique et direct, style québécois
- **Ton** : Vouvoiement strict, jamais "Salut", jamais "tu"
- **Chiffres clés validés** : 27 000 personnes accompagnées · 21 ans d'expérience

---

## 2. Les 3 missions de Lionard

### Mission 1 — RDV GLS (priorité absolue, source de revenus)
- **Objectif** : Qualifier et convertir les visiteurs ayant des dettes existantes en rendez-vous avec une conseillère GLS
- **Lien RDV** : https://www.dettes.ca/clinique-liberte
- **Déclencheur** : Toute mention de dettes existantes (prêt, hypothèque, carte de crédit, recouvrement, etc.)

### Mission 2 — NovaPlan (outil de prévention GLS)
- **Objectif** : Orienter les personnes qui gèrent encore leurs paiements vers la simulation de remboursement
- **Lien** : https://app.novaplan.ca/add-debts
- **Déclencheur** : Personne sans retard, en mode prévention, veut un plan personnalisé
- **Note** : NovaPlan est développé par GLS — ce n'est pas un concurrent

### Mission 3 — Abondance360 (formations GLS)
- **Objectif** : Orienter vers les formations en ligne sur la liberté financière
- **Lien** : https://abondance360.com
- **Déclencheur** : Personne sans dettes problématiques, veut apprendre, reconstruire après résolution
- **Note** : Plateforme dirigée par GLS, formations gratuites et payantes

### Arbre de décision
```
Visiteur mentionne des dettes existantes?
  OUI → Qualification → RDV GLS (Mission 1)
  NON → Gère encore ses paiements et veut simuler?
    OUI → NovaPlan (Mission 2)
    NON → Veut apprendre / formations?
      OUI → Abondance360 (Mission 3)
      NON → FAQ générale
```

---

## 3. Refactoring complet — Mai 2026

### Problème de départ
Le bot avait un comportement "têtu et générique" malgré une architecture RAG solide. 4 causes racines identifiées :

1. **15+ instructions INTERDIT** → GPT devenait paralysé et répondait de façon générique
2. **PolicyEngine trop lourd** (2622 lignes) → overrides systématiques des réponses GPT
3. **Prompt surchargé** → historique complet envoyé, 4 messages système fragmentés
4. **Pas de few-shot** → GPT n'avait aucun exemple concret du style attendu

### Solution appliquée : approche "exemples plutôt qu'interdictions"
Inspirée de Rasa CALM et Intercom Fin.

---

## 4. Fichiers créés / modifiés

### Nouveaux fichiers PHP

#### `app/Core/Retrieval/QueryRefiner.php` *(CRÉÉ)*
Reformule la requête brute avant le RAG (couche 1 inspirée Intercom Fin).
- Modèle : GPT-4o-mini, température 0.1, max_tokens 80
- Exemple : "chu pu capable payer" → "incapable de faire les paiements de ses dettes"
- Fallback : retourne la requête originale si erreur ou message < 4 mots

#### `app/Core/Orchestration/DialogueCommandExtractor.php` *(CRÉÉ)*
Remplace le `UserMessageAnalyzer` (parsing JSON fragile) par OpenAI Tool Calling.
- Température 0.0 (déterministe), max_tokens 220
- Slots extraits : `payment_capacity`, `urgency_level`, `debt_type`, `debt_structure`, `amount_range`, `housing`, `employment`, `family`, `emotional_state`, `wants_rdv`, `has_debt_confirmed`, `is_new_loan_request`, `cta_response`
- Interprète le joual québécois (chu, pus, bin, tsé, faque, etc.)
- Fallback : tableau vide (SlotExtractor prend le relais)

---

### Fichiers PHP modifiés

#### `app/Core/Orchestration/MessageOrchestrator.php` *(MODIFIÉ)*
Supprimé : `UserMessageAnalyzer`, `IntentDetector`
Ajouté : `QueryRefiner`, `DialogueCommandExtractor`

Nouveau pipeline :
1. `$commands = $this->dialogueExtractor->extract($userContent, $shortHistory)`
2. `$this->updateConversationSlots($conversation, $commands)` — fusion non-destructive
3. `$this->policyEngine->beforeAssistantResponse($conversation, $userContent, $commands)`
4. Vérification SemanticCache (ignoré si flowState actif)
5. `$refinedQuery = $this->queryRefiner->refine($userContent, $contextSummary)`
6. `$chunks = $this->retriever->retrieve(query: $refinedQuery, ...)`
7. `$intent = (object)['name' => $commands['primary_intent'] ?? 'info_request', ...]`
8. `$payload = $this->contextBuilder->buildPrompt(..., dialogueCommands: $commands)`
9. `$aiResponse = $this->aiClient->chat($payload, $context->bot->setting)`
10. `$validated = $this->policyEngine->afterAssistantResponse(...)`

#### `app/Support/PromptBuilding/SystemPromptBuilder.php` *(MODIFIÉ)*
Nouvelles méthodes injectées dans `build()` :

- **`buildMissionContext()`** — toujours injecté, décrit les 3 missions avec arbre de décision
- **`buildFewShotConversation()`** — injecté quand flowState actif, contient 7 personas complètes :
  1. Pierre — qualification complète étape par étape
  2. Karine — recouvrement intensif (appels quotidiens)
  3. Honte / blocage émotionnel
  4. Refus formulaire / RDV
  5. Hésitation (peur, coût, engagement)
  6. Stress élevé (ne dort plus, anxiété)
  7. Lead chaud prêt immédiatement
- **`buildQualificationObjective()`** — entièrement réécrit : 15+ INTERDIT → exemples few-shot positifs par état (style Julie/Lionard)
- **`buildFilledSlotsInstruction()`** — "INTERDIT DE REDEMANDER" → "✅ Déjà connu"
- Bloc `isDebtConfirmed` : 3 INTERDIT ABSOLU → 3 lignes ✅ CONTEXTE ACTIF

Ordre d'injection dans `build()` :
```
identité → règles → knowledge → liens → format → buildMissionContext() → buildFewShotConversation() → buildQualificationObjective()
```

#### `app/Core/Orchestration/ContextBuilder.php` *(MODIFIÉ)*
- Signature `buildPrompt()` : ajout du paramètre `array $dialogueCommands = []`
- **`getWindowedHistory()`** : retourne les 8 derniers messages (au lieu de tout l'historique) → -30% tokens
- **`buildConsolidatedSystemContext()`** : 1 seul message système consolidé remplaçant 4 messages fragmentés (analyse_interne + rappel dette + coordonnées + signaux)

---

### Fichiers knowledge modifiés

#### `Doc/knowledge/base-connaissance-finale/02-rules-final.json` *(REMPLACÉ)*
Réduit de **56 règles → 8 règles core**.

Les 48 règles supprimées étaient des doublons du prompt système. Règles conservées :

| Priorité | Règle |
|----------|-------|
| 1000 | Crise émotionnelle — priorité absolue (suicide, automutilation) |
| 999 | Retour prudent après crise |
| 100 | Vouvoiement strict |
| 100 | Interdiction diagnostic juridique et calcul personnalisé |
| 100 | Triage avant lien RDV |
| 100 | Dette existante = lead qualifié — ne pas rediriger vers FAQ |
| 98 | Pas de redondance dans la qualification |
| 95 | Confidentialité — ne pas révéler le fonctionnement interne |

#### `Doc/knowledge/base-connaissance-finale/04-abondance360.json` *(CRÉÉ)*
6 entrées : définition Abondance360, intent routing, page résumé, différences entre les 3 plateformes, quand recommander Abondance360, quand recommander NovaPlan.

---

## 5. Base de connaissance — imports à effectuer

### Fichier 03 (193 entrées — base principale)
```bash
php artisan knowledge:import-json \
  Doc/knowledge/base-connaissance-finale/03-knowledge-base-final.json \
  --source=knowledge-base-v3
```
Contenu : FAQ dettes.ca, NovaPlan, proposition de consommateur, faillite, consolidation, recouvrement, protocoles de crise, intents de routing, personas.

### Fichier 04 (6 entrées — Abondance360)
```bash
php artisan knowledge:import-json \
  Doc/knowledge/base-connaissance-finale/04-abondance360.json \
  --source=abondance360
```

### Vérification dry-run (sans importer)
```bash
php artisan knowledge:import-json Doc/knowledge/base-connaissance-finale/03-knowledge-base-final.json --dry-run
```

---

## 6. Style de conversation — référence

Basé sur la simulation "Consultation initiale — Groupe Leblanc Syndic" et les 20 personas du fichier `conversation.rtf`.

### Principes clés extraits des dialogues
1. **Une question à la fois** — jamais deux questions dans le même message
2. **Validation avant progression** — toujours reconnaître ce que la personne vient de dire
3. **Empathie spécifique** — "c'est beaucoup à porter" plutôt que "je comprends"
4. **Normalisation** — "vous n'êtes pas seul(e), c'est une situation qu'on rencontre souvent"
5. **Légèreté progressive** — humour québécois doux quand la tension baisse
6. **Ne jamais poser deux fois la même question** — si le slot est rempli, passer à la suite
7. **Récapitulatif avant CTA** — résumer ce qu'on sait avant de proposer le RDV
8. **CTA doux** — "est-ce que vous seriez à l'aise avec ça ?" plutôt que "prenez RDV maintenant"
9. **Joual accepté** — comprendre "chu", "pus", "bin", "tsé", "faque", "pantoute"
10. **Transition après crise** — ne jamais revenir aux finances avant que la personne le demande

### Exemple de ton (style Julie/Lionard)
```
"Je comprends que c'est stressant. Pour mieux vous aider,
j'aurais juste une question : est-ce que vous arrivez encore
à faire vos paiements minimums en ce moment ?"

→ (après réponse)

"C'est une situation qu'on rencontre souvent, et il y a
des options concrètes. Est-ce que vous seriez à l'aise
de prendre quelques minutes pour parler avec une de nos
conseillères ? C'est gratuit, confidentiel, et sans engagement."
```

---

## 7. Architecture des états de qualification

Le `qualification_flow` dans `conversation.context` suit une machine à états :

| État | Description |
|------|-------------|
| `triage_pending` | Premier contact — distinguer dette existante vs nouveau financement |
| `awaiting_debt_structure` | Confirmer le type de dette |
| `awaiting_payment_capacity` | La personne arrive-t-elle encore à payer ? |
| `awaiting_urgency` | Recouvrement, poursuites, saisies ? |
| `awaiting_personal_context` | Logement, emploi, famille |
| `awaiting_amount` | Estimation du montant total des dettes |
| `awaiting_bank_attempt` | A-t-elle essayé de consolider à la banque ? |
| `awaiting_timeline_risk` | Depuis combien de temps ? Seuil de tolérance ? |
| `awaiting_goal_preference` | Priorité : vitesse, protection d'actifs, ou paiements bas ? |
| `completed_summary` | Résumé + CTA RDV |

---

## 8. Tâches restantes (backlog)

### Haute priorité
- [ ] **Slim ConversationPolicyEngine** : réduire de 2622 lignes à ~350 lignes. Garder seulement 3 guards :
  - Guard 1 : Crise émotionnelle (suicide/automutilation)
  - Guard 2 : Append bouton RDV sur `completed_summary`
  - Guard 3 : Triage strip (retirer le lien RDV si non confirmé)
- [ ] **Importer la base de connaissance** (commandes ci-dessus, section 5)
- [ ] **Vérifier que ChunkDocumentJob + embeddings fonctionnent** après import

### Priorité moyenne
- [ ] **Écrire les tests Pest** (dossiers `tests/` vides) :
  - `MessageOrchestratorTest`
  - `ConversationPolicyEngineTest`
  - `SlotExtractorTest`
  - `DialogueCommandExtractorTest`
  - `QueryRefinerTest`
- [ ] **SemanticCache namespacing par bot_id** (actuellement global)
- [ ] **Configurer Laravel Horizon** pour les jobs asynchrones (ChunkDocumentJob, embeddings)

### Basse priorité
- [ ] Monitoring des slots (dashboard pour voir les taux de complétion par état)
- [ ] A/B test : fenêtre glissante 8 vs 12 messages
- [ ] Logs de qualité RAG (pertinence des chunks retournés)

---

## 9. Variables d'environnement requises

```env
# OpenAI
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o
OPENAI_MODEL_FAST=gpt-4o-mini

# Bot
SIMPLE_BOT_UUID=<uuid-du-bot-dans-la-table-bots>

# Queue (pour les embeddings)
QUEUE_CONNECTION=database   # ou redis avec Horizon

# Base de données
DB_CONNECTION=pgsql
# pgvector doit être activé : CREATE EXTENSION IF NOT EXISTS vector;
```

---

## 10. Résumé des fichiers du projet Doc/

```
Doc/
├── guides/
│   ├── ETAT-DU-PROJET-2026-05-02.md        ← ce fichier
│   ├── ROADMAP-BOT-FIABLE.md               ← plan initial d'amélioration
│   ├── ANALYSE-ARCHITECTURES-CHATBOT.md    ← analyse Rasa CALM / Intercom Fin
│   ├── REFACTOR-COMPLET.md                 ← spécifications détaillées du refactoring
│   ├── ARBRE-DECISIONNEL-BOT.md            ← logique de décision du bot
│   ├── INSTALL.md                          ← installation du projet
│   └── PROD-CHECKLIST.md                   ← checklist avant mise en production
├── knowledge/
│   └── base-connaissance-finale/
│       ├── 01-prompt-system-lionard-final.txt   ← prompt système de base
│       ├── 02-rules-final.json                  ← 8 règles core (réduit de 56)
│       ├── 03-knowledge-base-final.json         ← 193 entrées (À IMPORTER)
│       └── 04-abondance360.json                 ← 6 entrées Abondance360 (À IMPORTER)
├── updates/
├── changes/
└── prompts/
```

---

*Document généré le 2026-05-02 — Refactoring Lionard v2.0*
