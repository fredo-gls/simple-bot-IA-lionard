# Architecture cible — Lionard "Super Bot"
**Version : 1.0 · Mai 2026**
**Objectif : passer d'un bot générique à un conseiller conversationnel fiable qui qualifie, empathise et convertit**

---

## Table des matières

1. [Vision et critères de succès](#1-vision-et-critères-de-succès)
2. [Architecture actuelle vs cible](#2-architecture-actuelle-vs-cible)
3. [Cartographie des couches](#3-cartographie-des-couches)
4. [Refactoring par phase](#4-refactoring-par-phase)
5. [Découpage de ConversationPolicyEngine](#5-découpage-de-conversationpolicyengine)
6. [Plan des tests](#6-plan-des-tests)
7. [Base de connaissance cible](#7-base-de-connaissance-cible)
8. [Prompt system cible](#8-prompt-system-cible)
9. [Flux de conversation cible](#9-flux-de-conversation-cible)
10. [Checklist de mise en production](#10-checklist-de-mise-en-production)

---

## 1. Vision et critères de succès

### Ce que le "super bot" doit faire
- Qualifier un visiteur de zéro à RDV en suivant son rythme — sans limiter le nombre d'échanges
- Détecter les rollbacks (l'utilisateur revient sur une info, change d'avis, hésite) et s'adapter sans forcer la progression
- Ne jamais reposer une question déjà répondue
- Reconnaître le joual québécois et les formulations indirectes
- Orienter vers la bonne mission (RDV / NovaPlan / Abondance360) sans ambiguïté
- Répondre avec empathie spécifique, pas des formules génériques
- Suspendre toute logique commerciale en cas de crise émotionnelle

### Critères mesurables

| Indicateur | Aujourd'hui (estimé) | Cible |
|------------|----------------------|-------|
| Taux de complétion de qualification | ~30 % | ≥ 65 % |
| Rollbacks bien gérés (pas de re-question ni de rush) | non mesuré | ≥ 90 % |
| Questions redondantes par conversation | 2–4 | 0 |
| Conversations menant à un clic RDV | ~15 % | ≥ 35 % |
| Réponses génériques sur 20 conversations test | ~8 | ≤ 1 |
| Faux positifs recouvrement détecté | fréquents | < 5 % |
| Temps de réponse API | < 3 s (SSE) | < 2 s |

---

## 2. Architecture actuelle vs cible

### Actuelle — problèmes documentés

```
MessageOrchestrator
    ↓
UserMessageAnalyzer          ← parsing JSON fragile, taux ~70 %
    ↓
IntentDetector               ← matching par mots-clés, pas de contexte
    ↓
ConversationPolicyEngine     ← GOD CLASS 2 622 lignes, 99 méthodes
    ↓
ContextBuilder               ← 4 messages système fragmentés
    ↓
SystemPromptBuilder          ← 15+ INTERDIT, pas de few-shot
    ↓
KnowledgeRetriever           ← requête brute non reformulée
```

Résultat : bot paralysé par les interdictions, répète les questions, répond de façon générique.

---

### Cible — architecture "super bot"

```
┌─────────────────────────────────────────────────────────────┐
│  COUCHE 1 — COMPRÉHENSION                                   │
│  DialogueCommandExtractor   (Tool Calling, temp 0.0)        │
│  → 13 slots extraits avec fiabilité ~99 %                   │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│  COUCHE 2 — MÉMOIRE & ÉTAT                                  │
│  ConversationMemory + SlotMerger                            │
│  → fusion non-destructive des slots connus                  │
│  → machine à états (9 états de qualification)               │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│  COUCHE 3 — GUARDS (réduit à 3 règles absolues)             │
│  CrisisGuard        → 911 / 1-866-277-3553                  │
│  TriageGuard        → strip lien RDV si non confirmé        │
│  CTAButtonGuard     → append bouton sur completed_summary   │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│  COUCHE 4 — RETRIEVAL                                       │
│  QueryRefiner        (GPT-4o-mini, reformulation joual)     │
│  KnowledgeRetriever  (pgvector, top-5 chunks)               │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│  COUCHE 5 — GÉNÉRATION                                      │
│  ContextBuilder      (fenêtre 8 messages + 1 contexte)      │
│  SystemPromptBuilder (few-shot, missions, qualification)     │
│  AIClient GPT-4o     (génération finale)                    │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│  COUCHE 6 — POST-PROCESSING (léger)                         │
│  ResponseValidator   → format, longueur, vouvoiement        │
│  SemanticCache       → namespacing par bot_id               │
└─────────────────────────────────────────────────────────────┘
```

---

## 3. Cartographie des couches

### 3.1 Couche Compréhension — DialogueCommandExtractor

**Fichier** : `app/Core/Orchestration/DialogueCommandExtractor.php` ✅ créé

**Rôle** : extraire les signaux sémantiques du message utilisateur via OpenAI Tool Calling.

Slots extraits :
```
payment_capacity      → oui / non / partiel
urgency_level         → faible / moyen / élevé / critique
debt_type             → carte_credit / pret_perso / hypotheque / marge / fiscal / mixte
debt_structure        → unique / multiple
amount_range          → <5k / 5-15k / 15-30k / 30-75k / 75k+
housing               → proprio / locataire / avec_parents
employment            → employe / independant / sans_emploi / retraite
family                → seul / couple / enfants
emotional_state       → neutre / stress / honte / panique / soulagement
wants_rdv             → true / false
has_debt_confirmed    → true / false
is_new_loan_request   → true / false
cta_response          → accepted / refused / hesitant / none
is_correction         → true / false  ← l'utilisateur corrige une info précédente
```

Température : 0.0 · Modèle : GPT-4o-mini · Max tokens : 220

---

### 3.2 Couche Mémoire — ConversationMemory + SlotMerger

**Fichiers** :
- `app/Core/Memory/ConversationMemory.php` ✅ existant
- `app/Core/Memory/SlotMerger.php` 🔲 à créer (extrait de MessageOrchestrator)

**Règle de fusion** : un slot rempli n'est jamais écrasé par une valeur nulle — sauf si l'utilisateur donne explicitement une nouvelle valeur (rollback intentionnel).
```php
// Principe SlotMerger — avec détection de rollback
private function merge(array $existing, array $incoming, bool $isRollback = false): array {
    foreach ($incoming as $key => $value) {
        if ($value === null) {
            continue;  // jamais écraser avec null
        }
        if (!isset($existing[$key])) {
            $existing[$key] = $value;  // nouveau slot
        } elseif ($isRollback) {
            $existing[$key] = $value;  // rollback explicite → mise à jour autorisée
        }
        // sinon : slot déjà connu, on garde l'existant
    }
    return $existing;
}

// Rollback détecté si DialogueCommandExtractor retourne is_correction=true
// ou si la nouvelle valeur contredit directement l'ancienne
```

**Machine à états** (9 états) :

```
triage_pending
    ↓ dette confirmée
awaiting_debt_structure
    ↓
awaiting_payment_capacity
    ↓
awaiting_urgency
    ↓
awaiting_personal_context
    ↓
awaiting_amount
    ↓
awaiting_bank_attempt
    ↓
awaiting_timeline_risk
    ↓
awaiting_goal_preference
    ↓
completed_summary ──→ CTA RDV
```

Règle : sauter un état si le slot est déjà rempli.
Règle rollback : si l'utilisateur corrige une info déjà connue, mettre à jour le slot et reprendre depuis l'état courant — ne pas redémarrer la qualification.

---

### 3.3 Couche Guards — PolicyEngine slim

**Fichier cible** : `app/Core/Orchestration/ConversationPolicyEngine.php`
**Cible** : ~350 lignes (depuis 2 622)

Seuls 3 guards survivent dans le PolicyEngine principal :

```php
// Guard 1 — Crise (priorité absolue, jamais bypassé)
if ($this->crisisGuard->detect($userContent)) {
    return $this->crisisGuard->reply();
}

// Guard 2 — Triage (retirer le lien RDV si non confirmé)
if ($this->triageGuard->shouldStrip($conversation, $commands)) {
    $response = $this->triageGuard->strip($response);
}

// Guard 3 — CTA button (injecter le bouton sur completed_summary)
if ($this->ctaGuard->shouldAppend($conversation)) {
    $response = $this->ctaGuard->append($response);
}
```

Tout le reste (qualification flow, détection d'urgence, normalisation texte, smart routing) est extrait dans des services dédiés.

---

### 3.4 Couche Retrieval — QueryRefiner + KnowledgeRetriever

**Fichiers** :
- `app/Core/Retrieval/QueryRefiner.php` ✅ créé
- `app/Core/Retrieval/KnowledgeRetriever.php` ✅ existant

**Flux** :
```
Message brut : "chu pu capable payer mes cartes pis mon prêt"
    ↓ QueryRefiner (GPT-4o-mini)
Requête RAG  : "incapable de payer les mensualités de ses cartes de crédit et son prêt personnel"
    ↓ KnowledgeRetriever (pgvector, top-5)
Chunks       : proposition de consommateur, consolidation, options GLS
```

---

### 3.5 Couche Génération — SystemPromptBuilder cible

**Fichier** : `app/Support/PromptBuilding/SystemPromptBuilder.php` ✅ modifié

Structure du prompt final (ordre d'injection) :

```
[1] Identité Lionard          → qui il est, 27 000 personnes, 21 ans
[2] Règles core               → 8 règles (crise, vouvoiement, confidentialité...)
[3] Knowledge RAG             → chunks pertinents récupérés
[4] Liens bots                → liens actifs configurés
[5] Format                    → longueur, ton, style québécois
[6] Mission context           → arbre de décision RDV / NovaPlan / Abondance360
[7] Few-shot conversation      → 7 personas complètes (si flowState actif)
[8] Qualification objective    → exemples positifs par état (jamais d'INTERDIT)
```

**Règle d'or** : zéro instruction négative. Uniquement des exemples de ce qu'il faut faire.

---

### 3.6 Couche Post-processing — ResponseValidator

**Fichier cible** : `app/Core/Orchestration/ResponseValidator.php` 🔲 à créer

Rôle : validation légère post-GPT (sans réécrire la réponse).

```php
public function validate(string $response, Conversation $conv): ValidationResult
{
    return new ValidationResult(
        hasTutoiement:        $this->detectsTutoiement($response),
        hasMultipleQuestions: $this->countQuestions($response) > 1,
        exceedsLength:        str_word_count($response) > 120,
        mentionsInternals:    $this->detectsInternalTerms($response),
    );
}
```

Si la validation échoue, un rappel contextuel est injecté dans le prochain tour — pas de réécriture brutale.

---

## 4. Refactoring par phase

### Phase 1 — Fondations stables (1 à 2 semaines)
*Objectif : avoir un filet de sécurité avant de toucher au PolicyEngine*

- [ ] **Tests Pest — couverture minimale**
  - `MessageOrchestratorTest` — tester le pipeline complet (mock AIClient)
  - `DialogueCommandExtractorTest` — tester l'extraction de slots
  - `QueryRefinerTest` — tester la reformulation joual → français standard
  - `SlotMergerTest` — tester la fusion non-destructive
  - `CrisisGuardTest` — tester les déclencheurs de crise
- [ ] **Importer la base de connaissance**
  ```bash
  php artisan knowledge:import-json Doc/knowledge/base-connaissance-finale/03-knowledge-base-final.json --source=knowledge-base-v3
  php artisan knowledge:import-json Doc/knowledge/base-connaissance-finale/04-abondance360.json --source=abondance360
  ```
- [ ] **Vérifier les embeddings** : `php artisan tinker` → `KnowledgeChunk::count()`

---

### Phase 2 — Slim PolicyEngine (1 à 2 semaines)
*Objectif : réduire ConversationPolicyEngine de 2 622 → ~350 lignes*

Ordre de découpe (chaque extraction = un commit) :

```
Étape 2.1 — Extraire CrisisGuard
    app/Core/Policy/Guards/CrisisGuard.php
    → detect() + reply() + isCrisisKeyword()

Étape 2.2 — Extraire TriageGuard
    app/Core/Policy/Guards/TriageGuard.php
    → shouldStrip() + strip()

Étape 2.3 — Extraire CTAButtonGuard
    app/Core/Policy/Guards/CTAButtonGuard.php
    → shouldAppend() + append()

Étape 2.4 — Extraire QualificationFlowEngine
    app/Core/Qualification/QualificationFlowEngine.php
    → getNextState() + buildReply() + isComplete()

Étape 2.5 — Extraire SmartRoutingEngine
    app/Core/Routing/SmartRoutingEngine.php
    → route() + detectSegment() + getPersona()

Étape 2.6 — Extraire TextNormalizer
    app/Core/Support/TextNormalizer.php
    → softTone() + detectDuplicateSentences() + trimLength()

Étape 2.7 — PolicyEngine résiduel (~350 lignes)
    app/Core/Orchestration/ConversationPolicyEngine.php
    → beforeAssistantResponse() + afterAssistantResponse()
    → délègue aux 6 services ci-dessus
```

---

### Phase 3 — Qualité RAG (1 semaine)
*Objectif : améliorer la pertinence des chunks retournés*

- [ ] Ajouter un score de confiance sur les chunks (log si score < 0.6)
- [ ] SemanticCache namespacing par bot_id : `"semantic:{$botId}:" . md5($refinedQuery)`
- [ ] Logging RAG : enregistrer requête reformulée + chunks dans `retrieval_logs`
- [ ] Test de pertinence : 20 questions types → vérifier que le bon chunk est retourné

---

### Phase 4 — Optimisation conversation (1 semaine)
*Objectif : affiner le style et la conversion*

- [ ] A/B test fenêtre glissante : 8 messages vs 12 messages
- [ ] ResponseValidator : créer la classe + brancher dans le pipeline
- [ ] SlotMerger : extraire de MessageOrchestrator en classe dédiée
- [ ] Monitoring slots : dashboard pour voir les taux de complétion par état
- [ ] Laravel Horizon : configurer pour les jobs asynchrones

---

### Phase 5 — Production hardening (1 semaine)
*Objectif : déploiement fiable*

- [ ] Laravel Telescope ou Sentry : monitoring des erreurs
- [ ] Rate limiting sur les routes chatbot (100 req/min par IP)
- [ ] Health check : endpoint `/api/health` vérifiant DB + OpenAI + pgvector
- [ ] Rotation des clés OpenAI (via AWS Secrets Manager ou Vault)
- [ ] Runbook : procédure en cas de panne OpenAI (réponse de fallback statique)

---

## 5. Découpage de ConversationPolicyEngine

### Structure cible des services extraits

```
app/
└── Core/
    ├── Policy/
    │   ├── Guards/
    │   │   ├── CrisisGuard.php          (~60 lignes)
    │   │   ├── TriageGuard.php          (~40 lignes)
    │   │   └── CTAButtonGuard.php       (~35 lignes)
    │   └── Contracts/
    │       └── GuardInterface.php
    ├── Qualification/
    │   ├── QualificationFlowEngine.php  (~200 lignes)
    │   └── States/
    │       ├── TriagePendingState.php
    │       ├── AwaitingPaymentCapacityState.php
    │       ├── AwaitingUrgencyState.php
    │       └── CompletedSummaryState.php
    ├── Routing/
    │   └── SmartRoutingEngine.php       (~120 lignes)
    ├── Support/
    │   └── TextNormalizer.php           (~80 lignes)
    └── Orchestration/
        └── ConversationPolicyEngine.php (~350 lignes) ← slim
```

### Interface Guard (contrat commun)

```php
interface GuardInterface
{
    public function shouldActivate(Conversation $conv, string $userContent, array $commands): bool;
    public function apply(Conversation $conv, string $response): string;
}
```

### CrisisGuard — exemple complet

```php
class CrisisGuard implements GuardInterface
{
    private const KEYWORDS = [
        'suicide', 'suicider', 'me tuer', 'mourir', 'fin de vie',
        'je veux disparaître', 'je veux en finir', 'automutilation',
        'me faire du mal', 'plus envie de vivre'
    ];

    public function shouldActivate(Conversation $conv, string $userContent, array $commands): bool
    {
        $lower = mb_strtolower($userContent);
        foreach (self::KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }
        return ($commands['emotional_state'] ?? '') === 'crise';
    }

    public function apply(Conversation $conv, string $response): string
    {
        return "Ce que vous traversez semble très lourd, et je suis là pour vous entendre.\n\n"
             . "Si vous êtes en danger immédiat, composez le **911**.\n"
             . "Pour du soutien en santé mentale : **1-866-277-3553** "
             . "ou **811 option 2** — disponible 24h/24, confidentiel.\n\n"
             . "Je suis disponible si vous souhaitez continuer à parler.";
    }
}
```

---

## 6. Plan des tests

### Structure Pest recommandée

```
tests/
├── Unit/
│   ├── Core/
│   │   ├── DialogueCommandExtractorTest.php
│   │   ├── QueryRefinerTest.php
│   │   ├── SlotMergerTest.php
│   │   ├── TextNormalizerTest.php
│   │   └── Policy/
│   │       ├── CrisisGuardTest.php
│   │       ├── TriageGuardTest.php
│   │       └── CTAButtonGuardTest.php
│   └── Support/
│       └── SystemPromptBuilderTest.php
└── Feature/
    ├── MessageOrchestratorTest.php
    ├── ConversationQualificationTest.php
    └── KnowledgeRetrievalTest.php
```

### Cas de test prioritaires

**CrisisGuardTest** :
```php
it('détecte le mot suicide', fn() =>
    expect($guard->shouldActivate($conv, "j'ai envie de me suicider", []))->toBeTrue()
);
it('ne déclenche pas sur dette', fn() =>
    expect($guard->shouldActivate($conv, 'mes dettes me tuent de stress', []))->toBeFalse()
);
```

**SlotMergerTest** :
```php
it('ne réécrase pas un slot déjà connu', fn() =>
    expect(SlotMerger::merge(['urgency' => 'élevé'], ['urgency' => null]))
        ->toBe(['urgency' => 'élevé'])
);
```

**QueryRefinerTest** :
```php
it('reformule le joual', function() {
    $result = $refiner->refine('chu pu capable payer mes cartes');
    expect($result)->toContain('paiements');
});
it('retourne la requête originale si courte', fn() =>
    expect($refiner->refine('aide'))->toBe('aide')
);
```

---

## 7. Base de connaissance cible

### État actuel

| Fichier | Entrées | Statut |
|---------|---------|--------|
| 01-prompt-system-lionard-final.txt | — | ✅ en place |
| 02-rules-final.json | 8 règles | ✅ en place |
| 03-knowledge-base-final.json | 193 entrées | ⚠️ à importer |
| 04-abondance360.json | 6 entrées | ⚠️ à importer |

### Commandes d'import

```bash
php artisan knowledge:import-json \
  Doc/knowledge/base-connaissance-finale/03-knowledge-base-final.json \
  --source=knowledge-base-v3

php artisan knowledge:import-json \
  Doc/knowledge/base-connaissance-finale/04-abondance360.json \
  --source=abondance360

# Vérification
php artisan tinker --execute="echo KnowledgeChunk::count();"
```

### Entrées à ajouter à terme

- Témoignages clients anonymisés (renforce la confiance)
- Comparatif faillite vs proposition de consommateur (tableau)
- FAQ NovaPlan — questions fréquentes sur l'outil
- Glossaire termes légaux (syndic, libération, séquestre, etc.)
- Protocole pour travailleurs autonomes avec dettes fiscales

---

## 8. Prompt system cible

### Ce qui fonctionne (à garder)

- Identité Lionard avec chiffres validés (27 000 personnes, 21 ans)
- Arbre de décision des 3 missions (RDV / NovaPlan / Abondance360)
- 7 personas few-shot complètes (Pierre, Karine, honte, refus, hésitation, stress, lead chaud)
- Slots "déjà connus" affichés comme ✅ (jamais redemandés)
- Fenêtre glissante 8 messages + contexte consolidé en 1 message système

### Ce qui doit disparaître définitivement

- Toute instruction commençant par "INTERDIT", "NE JAMAIS", "ABSOLUMENT PAS"
- Répétition des mêmes règles dans le prompt ET dans les règles JSON
- Messages système fragmentés (ancienne version : 4 messages système séparés)
- Envoi de l'historique complet à GPT

### Principe directeur

> Montrer à GPT comment parler, pas ce qu'il ne doit pas dire.

| Ancienne règle (INTERDIT) | Nouvelle approche (few-shot) |
|---------------------------|------------------------------|
| "INTERDIT de redemander les dettes" | Exemple : "✅ Vous m'avez mentionné vos cartes — passons à la question suivante" |
| "NE JAMAIS recommander faillite" | Exemple : "Notre conseillère pourra vous guider selon votre situation exacte" |
| "INTERDIT de tutoyer" | Exemple : dialogue complet en vouvoiement naturel |

---

## 9. Flux de conversation cible

### Qualification complète — rythme adapté au visiteur

```
Visiteur : "j'ai des problèmes de dettes"

Lionard  : "Je vous entends. Pour mieux vous orienter, est-ce que
            vous arrivez encore à faire vos paiements minimum en ce moment ?"

Visiteur : "non, chu pu capable, j'ai des cartes pis un prêt perso"
            [slots : has_debt_confirmed=true, payment_capacity=non, debt_type=mixte]

Lionard  : "C'est une situation qu'on rencontre souvent, et il y a
            des options concrètes. Est-ce que vous avez reçu des
            appels de recouvrement récemment ?"

Visiteur : "oui, ils appellent presque tous les jours"
            [slot : urgency_level=élevé]

Lionard  : "Je comprends que c'est épuisant. Pour vous proposer
            les meilleures options, de quel montant total s'agit-il
            approximativement ?"

Visiteur : "autour de 25 000 $"
            [slot : amount_range=15-30k]

Lionard  : "Voici ce que je comprends de votre situation :
            → Dettes mixtes (cartes + prêt), environ 25 000 $
            → Paiements difficiles, appels de recouvrement actifs

            Nos conseillères ont accompagné des milliers de personnes
            dans une situation similaire. Est-ce que vous seriez à
            l'aise de prendre 15 minutes pour en parler avec l'une
            d'elles ? C'est gratuit, confidentiel, et sans engagement.

            [Prendre un rendez-vous gratuit →]"
```
```

### Rollback — l'utilisateur revient sur une info

```
Visiteur : "finalement je pense que j'arrive encore à payer le minimum
            sur mes cartes, c'est surtout le prêt qui est problématique"
            [slot payment_capacity mis à jour : partiel]

Lionard  : "Merci de préciser — c'est utile. Donc les cartes passent
            encore, mais le prêt personnel est difficile à gérer.
            Est-ce que vous avez des retards de paiement sur ce prêt
            en ce moment ?"

            ← NE PAS redémarrer la qualification depuis zéro
            ← Mettre à jour le slot, reprendre là où on était
```

### Hésitation prolongée — l'utilisateur n'est pas prêt

```
Visiteur : "je suis pas sûr, je veux y réfléchir encore"

Lionard  : "Tout à fait, c'est une décision qui mérite réflexion.
            Je suis là si vous avez d'autres questions.
            Sachez que la consultation est gratuite et sans engagement
            — vous pouvez prendre rendez-vous quand vous vous sentez prêt."

            ← NE PAS répéter le CTA à chaque message suivant
            ← Laisser la porte ouverte, attendre que l'utilisateur reprenne
```

### Contradiction — l'utilisateur donne des infos contradictoires

```
Visiteur (tour 2) : "je dois environ 10 000 $"
Visiteur (tour 5) : "avec mon hypothèque en retard ça monte à 80 000 $"

Lionard  : "Je vois — avec l'hypothèque en retard, la situation
            est plus complexe que je le pensais. C'est exactement
            le genre de dossier où une conseillère peut faire
            une vraie différence. Est-ce que vous seriez à l'aise
            d'en parler avec elle ?"

            ← Mettre à jour amount_range sans signaler la contradiction
            ← Utiliser la nouvelle info comme levier pour le CTA
```

### Routing NovaPlan (prévention)

```
Visiteur : "j'aimerais rembourser mes dettes tout seul avant que ça empire"

Lionard  : "Bonne initiative. NovaPlan est un outil gratuit de notre
            équipe qui vous permet de simuler un plan de remboursement
            personnalisé en quelques minutes.

            → https://app.novaplan.ca/add-debts

            Si la situation devient plus urgente, revenez — nos
            conseillères sont là."
```

### Routing Abondance360 (formations)

```
Visiteur : "j'ai réglé mes dettes l'an passé, je veux apprendre
            à mieux gérer mon argent maintenant"

Lionard  : "Félicitations pour ce chemin parcouru !
            Abondance360 est notre plateforme de formations en ligne
            sur la liberté financière — formations gratuites et payantes
            sur la budgétisation, la psychologie de l'argent, et la
            reconstruction financière.

            → https://abondance360.com"
```

---

## 10. Checklist de mise en production

### Avant déploiement

- [ ] Importer les 2 fichiers de knowledge base (199 entrées au total)
- [ ] Vérifier `KnowledgeChunk::count()` > 0 dans tinker
- [ ] Vérifier que `SIMPLE_BOT_UUID` dans `.env` correspond au bon bot
- [ ] Vérifier que la queue tourne (Horizon ou `queue:work`)
- [ ] Tester manuellement 7 scénarios (voir tableau ci-dessous)
- [ ] Coverage tests ≥ 30 % (Phase 1 complétée)
- [ ] ConversationPolicyEngine < 500 lignes (Phase 2 complétée)

### Variables d'environnement requises

```env
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o
OPENAI_MODEL_FAST=gpt-4o-mini
SIMPLE_BOT_UUID=<uuid>
QUEUE_CONNECTION=database
DB_CONNECTION=pgsql
```

### Scénarios de test manuel

| Scénario | Signal attendu | OK ? |
|----------|---------------|------|
| "j'ai des dettes" | Qualification démarre, pas de question répétée | ☐ |
| "chu pu capable payer" | Joual compris, réponse empathique | ☐ |
| "je veux mourir" | Suspension commerciale, ressources crise | ☐ |
| "je veux un nouveau prêt" | Triage : redirige, pas de RDV GLS | ☐ |
| Qualification complète | Résumé + bouton RDV au bon moment | ☐ |
| "j'ai réglé mes dettes" | Route vers Abondance360 | ☐ |
| "je veux simuler mon remboursement" | Route vers NovaPlan | ☐ |

---

## Résumé des priorités

```
SEMAINE 1-2  → Tests Pest + Import knowledge base
SEMAINE 3-4  → Slim PolicyEngine (2 622 → ~350 lignes)
SEMAINE 5    → Qualité RAG + SemanticCache namespacing
SEMAINE 6    → ResponseValidator + monitoring
SEMAINE 7    → Production hardening + Horizon
```

Le bot est déjà partiellement refactoré (couches 1, 4, 5 en place).
Les gains les plus importants viendront du slim PolicyEngine et de la couverture de tests.

---

*Document de référence — Lionard Super Bot v1.0 · Mai 2026*
*À mettre à jour après chaque phase complétée*
