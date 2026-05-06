# 🏗️ Analyse comparative des architectures de chatbot — Leçons pour Lionard

> **Objectif** : Comparer les modèles existants les plus avancés et extraire les idées concrètes applicables au projet Lionard sans réécriture complète.  
> **Date** : Mai 2026 — basé sur l'état de l'art actuel.

---

## Les 5 architectures analysées

| Modèle | Type | Points forts | Adapté à Lionard ? |
|---|---|---|---|
| **Intercom Fin** | Commercial, 3 couches RAG | Précision 99,9%, modèles spécialisés | ✅ Idées à extraire |
| **Rasa CALM** | Open source hybride | Flows + LLM, séparation des préoccupations | ✅✅ Le plus proche du besoin |
| **LangChain Agents** | Framework Python | Tool-calling, mémoire, chain-of-thought | ⚠️ Trop lourd à migrer |
| **Pure LLM Agent** | GPT-4o autonome | Flexibilité maximale | ❌ Pas de contrôle métier |
| **State Machine classique** | Déterministe | Prévisible, contrôlable | ❌ Trop rigide, pas naturel |

---

## 1. Intercom Fin — Le référent commercial

### Architecture (3 couches)

```
Couche 1 : Query Refinement
  └── Reformule la question utilisateur pour optimiser la recherche sémantique

Couche 2 : Retrieval & Scoring (modèle fin-cx-retrieval propriétaire)
  └── Récupère les chunks pertinents + score de pertinence/exactitude/utilité

Couche 3 : Generation (réseau de LLMs spécialisés selon le type de tâche)
  └── Génère la réponse finale ancrée dans les documents récupérés
```

### Ce qui fait la différence par rapport à Lionard

Fin n'utilise **pas un seul LLM** pour tout. Son architecture est **modulaire** :
- Un modèle pour la recherche (retrieval)
- Un autre pour la génération de réponse
- Un autre pour le scoring de pertinence

Surtout, Fin fait de la **query refinement** avant même de chercher. Si l'utilisateur écrit `"chu pu capable payer"`, Fin reformule en `"incapable de faire les paiements de dettes"` avant d'appeler le RAG.  
Lionard envoie la question brute au vecteur store — c'est pourquoi les chunks récupérés sont parfois hors sujet.

### Idées applicables à Lionard

**Ajouter une étape de query reformulation dans `KnowledgeRetriever`** :

```php
// AVANT (actuel)
$chunks = $this->retriever->retrieve(query: $userContent, ...);

// APRÈS (inspiré Fin)
$refinedQuery = $this->queryRefiner->refine($userContent, $conversationContext);
$chunks = $this->retriever->retrieve(query: $refinedQuery, ...);
```

`QueryRefiner` : appel GPT-4o-mini (moins cher) avec un prompt minimal :
> "Reformule cette question en français standard, maximisant la pertinence pour une recherche sur la gestion des dettes : [message utilisateur]"

**Effort** : 1 jour. **Gain** : RAG beaucoup plus précis sur le joual et les formulations courtes.

---

## 2. Rasa CALM — L'architecture la plus pertinente pour Lionard

### Concept clé : Séparer "comprendre" et "répondre"

CALM (Conversational AI with Language Models) est l'évolution 2025 de Rasa.  
L'idée centrale : **le LLM ne génère pas directement la réponse — il génère des commandes structurées.**

```
Message utilisateur
        ↓
[LLM Dialogue Understanding]
  → Génère des "commandes" JSON :
    { "action": "set_slot", "slot": "payment_capacity", "value": "cannot_pay" }
    { "action": "advance_flow", "next_step": "awaiting_urgency" }
        ↓
[Dialogue Manager] (déterministe)
  → Applique les commandes sur l'état
  → Décide du prochain step
        ↓
[LLM Response Generator]
  → Génère une réponse naturelle POUR CE STEP PRÉCIS
```

### Pourquoi c'est exactement ce dont Lionard a besoin

Le problème actuel de Lionard : **GPT fait 3 choses en même temps dans un seul appel** :
1. Il comprend l'intention
2. Il décide quoi faire ensuite (avancer le flow, extraire les slots)
3. Il génère la réponse naturelle

Ces 3 responsabilités mélangées = prompt overload = réponses génériques.

CALM dit : **un seul LLM = une seule responsabilité**.

### Traduction concrète pour Lionard (sans réécriture complète)

**Étape 1 : Ajouter un `DialogueCommandExtractor`** entre `UserMessageAnalyzer` et le prompt GPT principal.

```php
// Nouveau fichier : app/Core/Orchestration/DialogueCommandExtractor.php

class DialogueCommandExtractor
{
    public function extract(string $userContent, array $context): array
    {
        // Appel GPT-4o-mini (rapide, peu coûteux)
        // Prompt minimal : "Extrais les informations structurées de ce message"
        // Retourne du JSON :
        return [
            'slots_detected'  => ['payment_capacity' => 'cannot_pay'],
            'intent_signal'   => 'wants_rdv',
            'emotional_state' => 'distress',
            'flow_advance'    => true,
        ];
    }
}
```

**Étape 2 : `MessageOrchestrator` utilise ces commandes pour construire un prompt PLUS COURT**

Au lieu d'injecter 268 lignes de rules + flow + slots, on injecte seulement :
- Le contexte conversationnel (4-5 phrases max)
- Les slots déjà connus (résumé court)
- La prochaine étape (1 phrase)
- 2-3 exemples few-shot pour ce step précis

**Résultat** : GPT reçoit un prompt de 80 lignes au lieu de 400. Réponses beaucoup plus naturelles.

### Ce que Rasa CALM apporte aussi : la "conversation repair"

CALM gère nativement :
- **Digression** : l'utilisateur part sur un autre sujet → retour au flow automatique
- **Correction** : "en fait j'avais dit 25 000$ mais c'est plutôt 40 000$" → mise à jour du slot
- **Annulation** : "finalement je veux pas de RDV" → post_refusal state

Lionard implémente déjà ces patterns dans `ConversationPolicyEngine`, mais de façon hardcodée.  
L'approche CALM les gère via le LLM lui-même, avec des prompts d'exemples — beaucoup plus souple.

---

## 3. LangChain Agents — Idées sélectives

LangChain est trop lourd à adopter complètement (Python, dépendances, refactoring total).  
Mais 2 concepts sont directement applicables :

### 3.1 Structured Output / Tool Calling

Plutôt que d'analyser la réponse GPT en text libre pour en extraire les slots, utiliser **OpenAI Function Calling** :

```php
// Dans AIClient.php — ajouter un mode "extraction structurée"
$response = OpenAI::chat()->create([
    'model'    => 'gpt-4o-mini',
    'messages' => $payload->toMessages(),
    'tools'    => [[
        'type'     => 'function',
        'function' => [
            'name'       => 'extract_qualification_slots',
            'parameters' => [
                'type'       => 'object',
                'properties' => [
                    'payment_capacity' => ['type' => 'string', 'enum' => ['normal', 'partial', 'cannot_pay', 'unknown']],
                    'urgency'          => ['type' => 'string', 'enum' => ['high', 'medium', 'low', 'unknown']],
                    'emotional_state'  => ['type' => 'string', 'enum' => ['neutral', 'stressed', 'distressed', 'angry']],
                    'wants_rdv'        => ['type' => 'boolean'],
                ]
            ]
        ]
    ]],
    'tool_choice' => 'auto',
]);
```

**Avantage** : Extraction de slots fiable à 99%, sans regex ni pattern matching.  
Remplacerait partiellement le `SlotExtractor` actuel (qui reste utile pour les cas simples/rapides).

### 3.2 Memory Window (fenêtre glissante)

LangChain `ConversationBufferWindowMemory` : garder seulement les **N derniers échanges** dans le prompt.  
Lionard envoie actuellement TOUT l'historique à GPT — coûteux en tokens et peut noyer le contexte récent.

**Solution simple pour Lionard** :

```php
// Dans ContextBuilder.php
protected function getRecentMessages(Conversation $conversation, int $window = 8): array
{
    return $conversation->messages()
        ->latest()
        ->limit($window)  // Seulement les 8 derniers messages
        ->get()
        ->reverse()
        ->values()
        ->toArray();
}
```

**Effort** : 2h. **Gain** : -30% de tokens consommés, contexte plus focused.

---

## 4. Pure LLM Agent (GPT-4o autonome) — Pourquoi éviter

L'approche "laisser GPT décider de tout" sans couche de contrôle est populaire mais inadaptée ici.

**Problèmes pour un bot commercial/légal** :
- GPT peut halluciner des informations sur les solutions de dettes (faillite, proposition) → **risque légal**
- Sans state machine, GPT oublie l'objectif de qualification → conversations interminables
- Pas de garantie d'injecter le bouton RDV au bon moment → **pertes de conversions**
- Impossible à auditer et à corriger précisément

**Verdict** : La machine à états de Lionard est une force, pas un problème. Le problème c'est la couche de post-traitement qui réécrit GPT, pas la machine à états elle-même.

---

## 5. Architecture hybride optimale pour Lionard

En synthèse des 4 architectures analysées, voici l'architecture cible recommandée :

```
┌─────────────────────────────────────────────────────────────┐
│                    MESSAGE UTILISATEUR                       │
└─────────────────────┬───────────────────────────────────────┘
                       │
          ┌────────────▼────────────┐
          │   Query Refiner         │  ← NOUVEAU (inspiré Fin)
          │   (GPT-4o-mini, ~50ms)  │  Reformule pour RAG optimal
          └────────────┬────────────┘
                       │
          ┌────────────▼────────────┐
          │   Knowledge Retriever   │  ← EXISTANT (garder)
          │   (pgvector RAG)        │
          └────────────┬────────────┘
                       │
          ┌────────────▼────────────┐
          │  Dialogue Command       │  ← NOUVEAU (inspiré CALM)
          │  Extractor              │  Slots + intent (structured output)
          │  (OpenAI Tool Calling)  │
          └────────────┬────────────┘
                       │
          ┌────────────▼────────────┐
          │  State Machine          │  ← EXISTANT (garder, c'est bon)
          │  (qualification flow)   │  Décide du prochain état
          └────────────┬────────────┘
                       │
          ┌────────────▼────────────┐
          │  Few-Shot Prompt        │  ← MODIFIER (remplacer INTERDIT)
          │  Builder                │  Contexte court + exemples
          └────────────┬────────────┘
                       │
          ┌────────────▼────────────┐
          │  GPT-4o Response        │  ← EXISTANT (garder)
          │  Generator              │  Génère la réponse finale
          └────────────┬────────────┘
                       │
          ┌────────────▼────────────┐
          │  Policy Engine ALLÉGÉ   │  ← MODIFIER (garder 3 gardes)
          │  (crise + CTA + triage) │  Ne plus réécrire GPT
          └─────────────────────────┘
```

### Comparaison avant/après

| Dimension | Architecture actuelle | Architecture cible |
|---|---|---|
| Extraction des slots | Regex + patterns PHP | OpenAI Tool Calling (99% précis) |
| Query RAG | Message brut | Message reformulé (query refiner) |
| Prompt size | ~400 lignes | ~80 lignes (contexte court + few-shot) |
| Post-traitement | 8 gardes, réécrit GPT | 3 gardes, ne modifie pas GPT |
| Fluidité réponses | Générique (GPT sous pression) | Naturelle (GPT avec exemples) |
| Coût tokens | Élevé (tout l'historique) | -30% (fenêtre glissante) |

---

## Roadmap de mise en œuvre des nouvelles idées

### Semaine 1 — Quick wins (2-3 jours)
- [ ] Fenêtre glissante sur l'historique (`ContextBuilder` — 2h)
- [ ] Few-shot dans `buildQualificationObjective` (remplacer les INTERDIT — 1 jour)
- [ ] Alléger `postProcessQualificationReply` (garder 3 gardes — 1 jour)

### Semaine 2 — Extraction structurée (3-4 jours)
- [ ] `DialogueCommandExtractor` avec OpenAI Tool Calling
- [ ] Intégrer les slots extraits dans le `SlotExtractor` existant (complémentaire)
- [ ] Tests unitaires sur l'extraction

### Semaine 3 — Query Refiner (2 jours)
- [ ] `QueryRefiner` avec GPT-4o-mini
- [ ] A/B test : RAG avec vs sans reformulation
- [ ] Mesurer l'amélioration de la pertinence des chunks

### Semaine 4+ — Multi-missions
- [ ] Domaine Funnel
- [ ] Prompt modulaire par tunnel
- [ ] Bases de connaissance formations + outils

---

## Résumé des inspirations par source

| Source | Idée clé extraite | Effort d'implémentation |
|---|---|---|
| **Intercom Fin** | Query Refiner avant RAG | 1 jour |
| **Intercom Fin** | Scoring de pertinence des chunks | 2 jours |
| **Rasa CALM** | DialogueCommandExtractor (structured output) | 3 jours |
| **Rasa CALM** | Séparation compréhension / génération | 1 semaine |
| **LangChain** | OpenAI Tool Calling pour extraction slots | 2 jours |
| **LangChain** | Fenêtre glissante sur l'historique | 2h |
| **Tous** | Few-shot au lieu de règles prohibitives | 1 jour |

---

*Document créé le 2 mai 2026 — analyse basée sur Intercom Fin, Rasa CALM 2025, LangChain et recherche architectures enterprise.*

## Sources de référence

- [Rasa CALM — Documentation officielle](https://rasa.com/docs/learn/concepts/calm/)
- [Intercom Fin AI Engine](https://fin.ai/ai-engine)
- [How LLM Chatbot Architecture Works — Rasa Blog](https://rasa.com/blog/llm-chatbot-architecture)
- [Guide Enterprise Chatbot avec LLMs — Mercity AI](https://www.mercity.ai/blog-post/guide-to-building-an-enterprise-grade-customer-support-chatbot-using-llms)
- [AI Agent vs LLM Chatbot in 2025](https://medium.com/the-ai-spectrum/ai-agent-vs-llm-chatbot-in-2025-know-the-difference-1bdb1ca499ad)
