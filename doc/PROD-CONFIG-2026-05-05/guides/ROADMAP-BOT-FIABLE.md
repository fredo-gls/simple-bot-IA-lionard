# 🗺️ Roadmap — De Lionard actuel vers un bot fiable, fluide et commercial

> **Objectif** : Un bot empathique, naturel, qui qualifie les leads, close sur RDV, vend des formations et des outils.  
> **Base de départ** : Le projet Laravel existant (architecture solide, prompt avancé, RAG opérationnel).  
> **Approche** : Corrections ciblées + enrichissement, sans réécriture complète.

---

## 🔍 Diagnostic — Pourquoi le bot est têtu et générique aujourd'hui

Le problème n'est **pas** le framework Laravel ni l'architecture DDD.  
Le problème est une **surcharge d'instructions contradictoires** qui paralyse GPT.

### Les 4 causes racines identifiées

**1. Trop de "INTERDIT ABSOLU" dans le prompt**  
`buildQualificationObjective()` contient 15+ interdictions consécutives avant l'instruction positive.  
GPT sous contraintes excessives produit des réponses plates et sécurisées.  
→ Effet : "l'élève stressé à l'examen" qui dit des banalités pour ne rien violer.

**2. Le `ConversationPolicyEngine` réécrit GPT trop souvent**  
2 622 lignes de post-traitement avec 8 gardes actives :
- `isNearDuplicateOfLastAssistant` → remplace par fallback hardcodé
- `hasRepeatedOpeningPattern` → force une réponse déterministe
- `containsForbiddenDiagnosis` → efface entièrement la réponse GPT
- `forcedFallbackQuestionForState` → question pré-écrite générique

Résultat : le bot dit souvent une phrase codée en dur, pas une vraie réponse IA.

**3. Prompt overload — 3 couches qui se contredisent**  
À chaque message, GPT reçoit en simultané :
- Prompt système Lionard (268 lignes, beaucoup de règles)
- 56 règles JSON (dont 40 sont déjà dans le prompt)
- Instructions de qualification flow (injectées en dernier, haute priorité)

Ces couches créent des conflits internes que GPT ne sait pas résoudre → réponse générique.

**4. Approche "règles" au lieu d'approche "exemples"**  
GPT apprend mieux par imitation (few-shot) que par liste de prohibitions.  
Un exemple de bon échange vaut 10 règles "INTERDIT".

---

## ✅ Ce qui fonctionne déjà bien (à ne pas toucher)

- Architecture DDD Laravel propre et extensible
- Pipeline RAG avec pgvector (KnowledgeRetriever)
- Machine à états de qualification (9 états, logique solide)
- Gestion des crises émotionnelles (priorité 1000, ressources correctes)
- Streaming SSE pour l'UX
- Retry automatique OpenAI avec backoff exponentiel
- HMAC sur les session_id
- SlotExtractor avec patterns joual québécois

---

## 📋 Plan d'action détaillé

---

### PHASE 1 — Fluidité immédiate
**Durée estimée : 2-3 jours**  
**Impact : bot naturel, moins répétitif, moins générique**

#### 1.1 Réécrire `buildQualificationObjective` en approche few-shot
**Fichier :** `app/Support/PromptBuilding/SystemPromptBuilder.php`

Remplacer les blocs "INTERDIT ABSOLU" par des exemples d'échanges réels annotés.

```
AVANT (actuel) :
"INTERDIT ABSOLU : commencer par 'Je comprends'"
"INTERDIT ABSOLU : enchaîner des phrases génériques"
"INTERDIT : utiliser 'Pour mieux comprendre votre situation' plus d'une fois"
[... 12 autres interdictions ...]
"REQUIS : ancrer dans les mots exacts de l'utilisateur"

APRÈS (few-shot) :
## Exemples de réponses de qualité — imiter ce style

État awaiting_payment_capacity :
Utilisateur : "j'ai des cartes de crédit, environ 4-5 cartes"
Bot : "Plusieurs cartes, ça peut vite peser lourd. Est-ce que vous arrivez encore à faire les paiements, même si c'est serré ?"

Utilisateur : "chu pu capable de payer"
Bot : "C'est dur d'en être là. Pour ne pas que ça empire, le plus utile serait d'en parler avec une conseillère — c'est gratuit et confidentiel."

Utilisateur : "oui mais c'est tight"
Bot : "Serré mais vous tenez encore — c'est souvent le bon moment pour agir avant que ça bascule. C'est une seule dette ou plusieurs types ?"
```

**Gain attendu :** Réponses beaucoup plus naturelles, variées, ancrées dans le contexte réel.

---

#### 1.2 Alléger `postProcessQualificationReply`
**Fichier :** `app/Core/Orchestration/ConversationPolicyEngine.php`

| Garde actuelle | Action | Raison |
|---|---|---|
| `crisisReply` | ✅ Garder | Non-négociable, protection légale |
| `appendRdvButton` sur completed_summary | ✅ Garder | Fonctionnel essentiel |
| `triage initial` | ✅ Garder | Logique métier core |
| `isNearDuplicateOfLastAssistant` | ❌ Supprimer | Few-shot le gère mieux |
| `hasRepeatedOpeningPattern` | ❌ Supprimer | Few-shot le gère mieux |
| `containsForbiddenDiagnosis` → force fallback | ⚠️ Alléger | Garder la détection, mais reformuler au lieu d'effacer |
| `forcedFallbackQuestionForState` | ❌ Supprimer | Cause les réponses hardcodées génériques |
| `softenTone` sur tout | ⚠️ Alléger | Appliquer seulement si ton détecté agressif ou sec |

**Gain attendu :** GPT garde sa vraie réponse au lieu d'être remplacé par une phrase codée en dur.

---

#### 1.3 Réduire les 56 règles JSON à 8 règles core
**Fichier :** `Doc/knowledge/base-connaissance-finale/02-rules-final.json`

Garder uniquement ce qui n'est **pas** dans le prompt système :
1. Crise émotionnelle / suicide (priorité 1000)
2. Retour prudent après crise (priorité 999)
3. Vouvoiement obligatoire
4. Interdiction diagnostic juridique personnalisé
5. Interdiction promesse de résultat
6. Interdiction calcul personnalisé
7. Triage obligatoire avant lien RDV
8. Différence prêt voulu vs prêt existant

Les 48 autres règles sont des doublons du prompt → les supprimer du JSON.  
**Gain attendu :** Moins de bruit d'instructions, GPT se concentre sur l'essentiel.

---

### PHASE 2 — Fiabilité structurelle
**Durée estimée : 3-4 jours**  
**Impact : bot stable, testable, sans régression**

#### 2.1 Écrire les tests Pest essentiels
**Dossiers :** `tests/Feature/` et `tests/Unit/` (actuellement vides)

Tests prioritaires :
- `MessageOrchestratorTest` : pipeline complet avec mock OpenAI
- `ConversationPolicyEngineTest` : crise → bonne réponse, triage → bon routing
- `SlotExtractorTest` : patterns joual, réponses courtes, montants
- `IntentDetectorTest` : keyword matching + cas ambigus

**Gain attendu :** Chaque modification peut être validée sans tester manuellement.

#### 2.2 Namespacing du SemanticCache par bot_id
**Fichier :** `app/Core/Cache/SemanticCache.php`

```php
// AVANT
$cacheKey = 'semantic_' . md5($query);

// APRÈS
$cacheKey = 'semantic_' . $this->botId . '_' . md5($query);
```

**Gain attendu :** Empêche qu'une réponse d'un bot soit servie à un autre bot.

#### 2.3 Activer Laravel Horizon pour les jobs async
**Fichiers :** `composer.json` + configuration Horizon

Les jobs `GenerateEmbeddingJob` et `ChunkDocumentJob` tournent en synchrone  
→ bloquent le processus PHP pendant l'indexation de connaissance.

**Gain attendu :** Indexation de la base de connaissance sans impact sur les conversations.

---

### PHASE 3 — Multi-missions (RDV + Formations + Outils)
**Durée estimée : 2-3 semaines**  
**Impact : nouveaux canaux de revenus, bot polyvalent**

#### 3.1 Créer le domaine Funnel
**Nouveau dossier :** `app/Domains/Funnel/`

```
Funnel/
├── Models/
│   ├── FunnelDefinition.php   # RDV | Formation | Outil
│   ├── FunnelStep.php         # Étapes de chaque tunnel
│   └── FunnelConversion.php   # Tracking des conversions
├── Services/
│   └── FunnelRouter.php       # Routing prospect → bon tunnel
└── migrations/
    └── create_funnels_table.php
```

#### 3.2 Nouveaux slots de qualification
**Fichier :** `app/Core/Orchestration/SlotExtractor.php`

Ajouter :
- `mission_detected` : `rdv` | `formation` | `outil` | `unknown`
- `learning_intent` : true/false (veut comprendre, apprendre, se former)
- `tool_intent` : true/false (veut un outil, calculateur, logiciel)
- `budget_range` : `low` (<50€) | `mid` (50-200€) | `high` (200€+)
- `objection_type` : `price` | `time` | `trust` | `urgency`

#### 3.3 Prompt modulaire par funnel
**Fichier :** `app/Support/PromptBuilding/SystemPromptBuilder.php`

```php
// Bloc commun (ton, vouvoiement, empathie) — toujours injecté
$parts[] = $this->buildCommonPersonality();

// Bloc funnel — injecté selon le tunnel détecté
$parts[] = match($funnelType) {
    'rdv'        => $this->buildRdvFunnelPrompt($slots),
    'formation'  => $this->buildFormationFunnelPrompt($catalog),
    'outil'      => $this->buildOutilFunnelPrompt($catalog),
    default      => $this->buildQualificationPrompt($slots),
};
```

#### 3.4 Base de connaissance formations + outils
**Nouveau dossier :** `Doc/knowledge/formations/` et `Doc/knowledge/outils/`

Pour chaque produit : description, prix, cas d'usage, témoignages, objections fréquentes + réponses.

#### 3.5 Intégration Stripe pour vente directe
**Nouveau fichier :** `app/Domains/Integration/Services/StripeService.php`

Génération de liens de paiement depuis le chat via l'API Stripe Checkout.

#### 3.6 Jobs de follow-up automatique
**Nouveau fichier :** `app/Jobs/FollowUpLeadJob.php`

- J+1 : email si prospect qualifié n'a pas converti
- J+3 : relance avec ressource gratuite (PDF, article)
- J+7 : dernière relance avant archivage

---

## ⏱️ Estimation de temps totale

| Phase | Contenu | Durée | Compétences nécessaires |
|---|---|---|---|
| **Phase 1** | Fluidité — few-shot + alléger PolicyEngine + réduire règles | **2-3 jours** | PHP / Prompt engineering |
| **Phase 2** | Fiabilité — tests + cache + Horizon | **3-4 jours** | PHP / Laravel |
| **Phase 3** | Multi-missions — Funnel + Stripe + Follow-up | **2-3 semaines** | PHP + intégrations API |
| **Total Phase 1+2** | Bot fluide et fiable sur la mission actuelle | **~1 semaine** | |
| **Total complet** | Bot multi-missions prêt pour production | **~1 mois** | |

---

## 🎯 Résumé exécutif

```
Semaine 1  → Bot fluide et fiable (Phase 1 + 2)
             Le bot actuel cesse d'être générique et répétitif.
             Les réponses sont naturelles, ancrées dans le contexte.
             Le pipeline est testé et stable.

Semaine 2-4 → Bot multi-missions (Phase 3)
             Tunnel formations : qualification → présentation → Stripe
             Tunnel outils : démo → upsell → lien d'achat
             Tunnel RDV : inchangé (déjà bon)
             Follow-up automatique sur les leads non convertis.
```

**La base de code actuelle est solide.** Il ne faut pas repartir de zéro.  
Les fondations (DDD, RAG, machine à états, streaming) sont les bonnes.  
Le travail restant est du raffinement, pas de la reconstruction.

---

## 📌 Par où commencer (ordre recommandé)

1. **Aujourd'hui** — Réécrire `buildQualificationObjective` avec 3-4 exemples few-shot par état
2. **Demain** — Alléger `postProcessQualificationReply` (supprimer 5 gardes redondantes)
3. **Jour 3** — Réduire les 56 règles JSON à 8 règles core
4. **Jour 4-5** — Écrire les tests Pest pour MessageOrchestrator et SlotExtractor
5. **Semaine 2** — Commencer le domaine Funnel et les nouveaux slots

---

*Document créé le 2 mai 2026 — basé sur audit complet du code source Lionard.*
