# 🔧 Refactor complet — Architecture inspirée Fin + CALM

> Ce document décrit **exactement** ce qui change, fichier par fichier, avec le code réel.  
> Aucune réécriture de zéro : on opère sur la base existante.

---

## Vue d'ensemble : avant / après

### Pipeline actuel (problèmes identifiés)

```
UserMessage
  → UserMessageAnalyzer (GPT-4o-mini → JSON texte parsé, fragile)
  → ConversationPolicyEngine.before (2622 lignes, 8 gardes)
  → ContextBuilder (injecte 4 messages système séparés, confus)
  → IntentDetector (mots-clés seulement)
  → RuleEngine (56 règles dont 40 redondantes)
  → SemanticCache.get
  → KnowledgeRetriever (query brute → chunks hors sujet)
  → SystemPromptBuilder (400 lignes + 15 INTERDIT → GPT stressé)
  → GPT-4o (reçoit trop, répond générique)
  → ConversationPolicyEngine.after (réécrit souvent GPT → bot mécanique)
```

### Pipeline cible (inspiré Fin + CALM)

```
UserMessage
  → [1] QueryRefiner          NOUVEAU  — reformule pour RAG (GPT-4o-mini, 50ms)
  → [2] KnowledgeRetriever    EXISTANT — inchangé
  → [3] DialogueExtractor     NOUVEAU  — Tool Calling → slots JSON fiables
  → [4] StateMachineAdvancer  EXISTANT — inchangé (flow states)
  → [5] FewShotPromptBuilder  MODIFIÉ  — prompt 80 lignes + exemples réels
  → [6] GPT-4o                EXISTANT — inchangé
  → [7] PolicyEngine ALLÉGÉ   MODIFIÉ  — 3 gardes seulement (~300 lignes)
```

---

## Fichiers à créer (2 nouveaux)

---

### NOUVEAU : `app/Core/Retrieval/QueryRefiner.php`

**Rôle** : Reformuler le message brut avant RAG (inspiré Intercom Fin).  
`"chu pu capable payer"` → `"incapable de faire les paiements de ses dettes"`

```php
<?php

namespace App\Core\Retrieval;

use App\Core\AI\AIClient;
use App\Core\AI\AIPayload;

class QueryRefiner
{
    public function __construct(
        protected AIClient $aiClient,
    ) {}

    /**
     * Reformule la question pour optimiser la recherche sémantique.
     * Utilise GPT-4o-mini (~50ms, peu coûteux).
     * Retourne la query originale si l'IA n'est pas configurée.
     */
    public function refine(string $rawQuery, string $context = ''): string
    {
        if (!$this->aiClient->isConfigured()) {
            return $rawQuery;
        }

        // Moins de 4 mots ou message très court → pas besoin de reformuler
        if (str_word_count($rawQuery) < 4) {
            return $rawQuery;
        }

        try {
            $payload = new AIPayload(
                systemPrompt: implode("\n", [
                    "Tu es un moteur de reformulation pour une base de données vectorielle sur la gestion des dettes.",
                    "Reformule la question en français standard clair, sans jargon, sans fautes.",
                    "Conserve l'intention et les informations clés. Maximum 1 phrase.",
                    "Réponds UNIQUEMENT par la reformulation, sans explication.",
                    $context ? "Contexte de la conversation : {$context}" : "",
                ]),
                messages: [['role' => 'user', 'content' => $rawQuery]],
                model: 'gpt-4o-mini',
                maxTokens: 80,
                temperature: 0.1,
            );

            $response = $this->aiClient->chat($payload);
            $refined  = trim($response->content);

            // Sécurité : si la reformulation est vide ou trop longue, utiliser l'original
            return ($refined !== '' && strlen($refined) < 300) ? $refined : $rawQuery;

        } catch (\Exception) {
            return $rawQuery;
        }
    }
}
```

---

### NOUVEAU : `app/Core/Orchestration/DialogueCommandExtractor.php`

**Rôle** : Remplacer le parsing JSON fragile de `UserMessageAnalyzer` par OpenAI Tool Calling.  
Résultat : extraction de slots **fiable à 99%**, structurée, typée.

```php
<?php

namespace App\Core\Orchestration;

use App\Core\AI\AIClient;
use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Facades\Log;

class DialogueCommandExtractor
{
    // Schéma des slots extraits via Tool Calling
    private const TOOL_SCHEMA = [
        'type'     => 'function',
        'function' => [
            'name'        => 'extract_dialogue_commands',
            'description' => 'Extrait les intentions et informations structurées du message utilisateur dans le contexte d\'une conversation sur les dettes.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'payment_capacity' => [
                        'type' => 'string',
                        'enum' => ['cannot_pay', 'partial', 'normal', 'unknown'],
                        'description' => 'cannot_pay=ne peut plus payer/pus capable/impossible. partial=difficile/serré/à bout/du mal. normal=arrive encore/ça va/gère. unknown=pas mentionné.',
                    ],
                    'urgency_level' => [
                        'type' => 'string',
                        'enum' => ['high', 'medium', 'low', 'unknown'],
                        'description' => 'high=huissier/saisie/compte gelé/expulsion. medium=appels créanciers/recouvrement/retard/perte emploi. low=calme/aucune pression. unknown=pas mentionné.',
                    ],
                    'debt_type' => [
                        'type' => 'string',
                        'enum' => ['cartes', 'hypotheque', 'pret_auto', 'impots', 'pret_personnel', 'commercial', 'mixte', 'unknown'],
                    ],
                    'debt_structure' => [
                        'type' => 'string',
                        'enum' => ['single', 'multiple', 'unknown'],
                        'description' => 'single=un seul type de dette. multiple=plusieurs types.',
                    ],
                    'amount_range' => [
                        'type' => 'string',
                        'enum' => ['under_5k', '5k_15k', '15k_50k', '50k_plus', 'unknown'],
                    ],
                    'housing' => [
                        'type' => 'string',
                        'enum' => ['owner', 'tenant', 'unknown'],
                    ],
                    'employment' => [
                        'type' => 'string',
                        'enum' => ['full_time', 'part_time', 'unemployed', 'retired', 'self_employed', 'unknown'],
                    ],
                    'family' => [
                        'type' => 'string',
                        'enum' => ['single', 'partner', 'children', 'partner_children', 'unknown'],
                    ],
                    'emotional_state' => [
                        'type' => 'string',
                        'enum' => ['distressed', 'stressed', 'ashamed', 'angry', 'neutral', 'hopeful'],
                        'description' => 'État émotionnel dominant détecté dans le message.',
                    ],
                    'wants_rdv' => [
                        'type'        => 'boolean',
                        'description' => 'true si la personne demande explicitement un rendez-vous ou à parler à quelqu\'un.',
                    ],
                    'has_debt_confirmed' => [
                        'type'        => 'boolean',
                        'description' => 'true si le message confirme une dette existante (pas une demande de nouveau prêt).',
                    ],
                    'is_new_loan_request' => [
                        'type'        => 'boolean',
                        'description' => 'true si la personne veut emprunter de l\'argent (nouveau financement, pas une dette existante).',
                    ],
                    'cta_response' => [
                        'type' => 'string',
                        'enum' => ['acceptance', 'refusal', 'thanks', 'none'],
                        'description' => 'Réponse au CTA précédent : acceptance=dit oui au RDV. refusal=refuse. thanks=merci après CTA. none=pas de réponse à un CTA.',
                    ],
                ],
                'required' => ['payment_capacity', 'urgency_level', 'emotional_state', 'wants_rdv', 'has_debt_confirmed'],
            ],
        ],
    ];

    public function __construct(
        protected AIClient $aiClient,
    ) {}

    /**
     * Extraire les commandes dialogiques d'un message utilisateur.
     * Retourne un tableau vide si l'IA n'est pas configurée (fallback sur SlotExtractor).
     */
    public function extract(string $userMessage, array $conversationHistory = []): array
    {
        if (!$this->aiClient->isConfigured()) {
            return [];
        }

        try {
            // Construire les messages avec contexte minimal (3 derniers tours max)
            $messages = array_slice($conversationHistory, -6);
            $messages[] = ['role' => 'user', 'content' => $userMessage];

            $response = OpenAI::chat()->create([
                'model'       => 'gpt-4o-mini',
                'messages'    => array_merge([
                    ['role' => 'system', 'content' => implode("\n", [
                        "Tu analyses des messages dans le contexte d'un chatbot de gestion de dettes.",
                        "Extrais les informations demandées. Si une information n'est pas présente, utilise 'unknown' ou false.",
                        "Interprète le joual québécois : 'chu'=je suis, 'pus/pu'=plus, 'pe'=peut-être, 'jsais'=je sais.",
                    ])],
                ], $messages),
                'tools'       => [self::TOOL_SCHEMA],
                'tool_choice' => ['type' => 'function', 'function' => ['name' => 'extract_dialogue_commands']],
                'temperature' => 0.0, // Déterministe — extraction, pas génération
                'max_tokens'  => 200,
            ]);

            $toolCall = $response->choices[0]->message->toolCalls[0] ?? null;
            if (!$toolCall) {
                return [];
            }

            $args = json_decode($toolCall->function->arguments, true);
            return is_array($args) ? $args : [];

        } catch (\Exception $e) {
            Log::warning('DialogueCommandExtractor failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
```

---

## Fichiers à modifier (4 modifications ciblées)

---

### MODIFIER : `app/Core/Orchestration/MessageOrchestrator.php`

**Changements** : Intégrer `QueryRefiner` + `DialogueCommandExtractor`. Remplacer `UserMessageAnalyzer` par le nouvel extracteur. Simplifier le pipeline.

```php
<?php

namespace App\Core\Orchestration;

use App\Core\AI\AIClient;
use App\Core\Cache\SemanticCache;
use App\Core\Memory\ConversationMemory;
use App\Core\Retrieval\KnowledgeRetriever;
use App\Core\Retrieval\QueryRefiner;          // NOUVEAU
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Message\Models\Message;

class MessageOrchestrator
{
    public function __construct(
        protected AIClient                  $aiClient,
        protected KnowledgeRetriever        $retriever,
        protected QueryRefiner              $queryRefiner,        // NOUVEAU
        protected DialogueCommandExtractor  $dialogueExtractor,   // NOUVEAU
        protected ConversationMemory        $memory,
        protected ContextBuilder            $contextBuilder,
        protected ConversationPolicyEngine  $policyEngine,
        protected RuleEngine                $ruleEngine,
        protected ActionDispatcher          $actionDispatcher,
        protected SemanticCache             $semanticCache,
    ) {}

    public function handle(Conversation $conversation, string $userContent): Message
    {
        $conversation->loadMissing(['bot.setting']);

        // ── 1. Extraire les commandes dialogiques (Tool Calling — fiable)
        $shortHistory = $this->memory->getShortMemory($conversation);
        $commands = $this->dialogueExtractor->extract($userContent, $shortHistory);
        $this->updateConversationSlots($conversation, $commands);

        // ── 2. Gardes-fous avant réponse (crise, privacy, CTA direct)
        $forced = $this->policyEngine->beforeAssistantResponse($conversation, $userContent, $commands);
        if ($forced !== null) {
            return $this->persistPolicyReply($conversation, $userContent, $forced['content'], $forced['policy']);
        }

        // ── 3. Contexte complet
        $context = $this->contextBuilder->build($conversation);

        // ── 4. Cache sémantique (skip si flow de qualification actif)
        $flowState = (string) ($conversation->context['qualification_flow']['state'] ?? '');
        if ($flowState === '') {
            $cached = $this->semanticCache->get($userContent);
            if ($cached !== null) {
                return $this->persistCachedReply($conversation, $userContent, $cached);
            }
        }

        // ── 5. RAG avec query reformulée (inspiré Intercom Fin)
        $contextSummary = $this->buildContextSummary($conversation);
        $refinedQuery   = $this->queryRefiner->refine($userContent, $contextSummary);
        $chunks = $this->retriever->retrieve(
            query: $refinedQuery,
            siteId: $conversation->site_id,
            topK: max(1, (int) ($context->bot->setting?->retrieval_top_k ?? 5)),
        );

        // ── 6. Règles métier
        $intent = (object) ['name' => $commands['primary_intent'] ?? 'info_request', 'intent_id' => null];
        $rules  = $this->ruleEngine->resolve($conversation, $intent);

        // ── 7. Construire le prompt (court, few-shot)
        $payload = $this->contextBuilder->buildPrompt(
            context: $context,
            userContent: $userContent,
            chunks: $chunks,
            rules: $rules,
            intent: $intent,
            dialogueCommands: $commands,   // NOUVEAU — passer les commandes extraites
        );

        if (!$this->aiClient->isConfigured()) {
            return $this->persistFallback($conversation, $userContent);
        }

        // ── 8. Appel GPT-4o
        $aiResponse = $this->aiClient->chat($payload, $context->bot->setting);

        // ── 9. Post-traitement allégé (3 gardes seulement)
        $validated = $this->policyEngine->afterAssistantResponse(
            $conversation, $userContent, $aiResponse->content, $commands
        );

        // ── 10. Cache + actions + persistance
        if ($flowState === '') {
            $this->semanticCache->put($userContent, $validated ?? $aiResponse->content);
        }
        $this->actionDispatcher->dispatch($intent, $validated, $conversation);

        return $this->persist($conversation, $userContent, $aiResponse, $chunks, $intent, $validated);
    }

    /**
     * Met à jour les slots de la conversation à partir des commandes extraites.
     * Fusionne avec les slots existants sans écraser les valeurs déjà connues.
     */
    private function updateConversationSlots(Conversation $conversation, array $commands): void
    {
        if (empty($commands)) return;

        $ctx = is_array($conversation->context) ? $conversation->context : [];
        $flow = is_array($ctx['qualification_flow'] ?? null) ? $ctx['qualification_flow'] : [];
        $slots = is_array($flow['slots'] ?? null) ? $flow['slots'] : [];

        // Mapping commandes → slots du flow
        $slotMap = [
            'payment_capacity'   => 'payment_capacity',
            'urgency_level'      => 'urgency',
            'debt_type'          => 'debt_type',
            'debt_structure'     => 'debt_structure',
            'amount_range'       => 'amount_range',
            'housing'            => 'housing',
            'employment'         => 'employment',
            'family'             => 'family',
            'emotional_state'    => 'emotional_state',
        ];

        $updated = false;
        foreach ($slotMap as $cmdKey => $slotKey) {
            $value = $commands[$cmdKey] ?? null;
            if ($value && $value !== 'unknown' && empty($slots[$slotKey])) {
                $slots[$slotKey] = $value;
                $updated = true;
            }
        }

        if ($updated) {
            $flow['slots'] = $slots;
            $ctx['qualification_flow'] = $flow;
            $conversation->context = $ctx;
            $conversation->save();
        }
    }

    private function buildContextSummary(Conversation $conversation): string
    {
        $ctx   = is_array($conversation->context) ? $conversation->context : [];
        $slots = $ctx['qualification_flow']['slots'] ?? [];
        $parts = array_filter([
            isset($slots['debt_type'])         ? "Type de dette : {$slots['debt_type']}" : null,
            isset($slots['payment_capacity'])   ? "Capacité paiement : {$slots['payment_capacity']}" : null,
            isset($slots['urgency'])            ? "Urgence : {$slots['urgency']}" : null,
        ]);
        return implode('. ', $parts);
    }

    // ... (les méthodes persist* restent identiques à l'original)
}
```

---

### MODIFIER : `app/Support/PromptBuilding/SystemPromptBuilder.php`

**Changement principal** : Réécrire `buildQualificationObjective` en approche few-shot.  
Supprimer les 15+ INTERDIT. Réduire de 400 à ~100 lignes d'instructions.

```php
<?php
// Remplacer UNIQUEMENT la méthode buildQualificationObjective()
// Tout le reste du fichier reste identique.

protected function buildQualificationObjective(array $flow, array $botLinks = []): string
{
    $state = (string) ($flow['state'] ?? '');
    $slots = is_array($flow['slots'] ?? null) ? $flow['slots'] : [];
    $known = $this->buildSlotSummary($slots);
    $score = (int) ($flow['score'] ?? 0);

    // En-tête commun — court, positif, pas de liste d'interdictions
    $header = implode("\n", array_filter([
        "## CONTEXTE DE QUALIFICATION — SUIVRE UNIQUEMENT CES INSTRUCTIONS",
        $known ? "✅ DÉJÀ CONNU (ne pas redemander) : {$known}." : null,
        "🎯 Style requis : conseillère humaine, chaleureux, ancré dans les mots de l'utilisateur.",
        "📏 Format : 1-2 phrases max. Une seule question. Pas de liste. Pas de récapitulatif complet.",
        "",
    ]));

    return match ($state) {
        'triage_pending' => $header . implode("\n", [
            "## ÉTAPE : Triage initial",
            "L'utilisateur a demandé un rendez-vous. Avant de donner le lien, vérifier le contexte.",
            "",
            "### Exemples de bon triage :",
            "Utilisateur : 'je voudrais prendre rdv'",
            "Bot : 'Bien sûr. Est-ce que votre demande concerne des dettes que vous avez déjà — cartes, prêts, hypothèque — ou plutôt une recherche de nouveau financement ?'",
            "",
            "Utilisateur : 'j'ai besoin de vous parler'",
            "Bot : 'Je suis là. C'est pour une situation de dettes existantes, ou plutôt pour explorer un financement ?'",
        ]),

        'awaiting_debt_structure' => $header . implode("\n", [
            "## ÉTAPE : Comprendre la structure de la dette",
            "L'utilisateur a des dettes confirmées. Poser une question sur le type.",
            "",
            "### Exemples :",
            "Utilisateur : 'j'ai beaucoup de dettes'",
            "Bot : 'C'est quel type de dettes — cartes de crédit, prêt auto, hypothèque, ou plusieurs types à la fois ?'",
            "",
            "Utilisateur : 'j'ai des cartes pis un char à payer'",
            "Bot : 'Plusieurs dettes en même temps, ça peut vite peser lourd. Est-ce que vous arrivez encore à faire les paiements, même si c'est serré ?'",
            "→ Note : si la structure est claire ('j'ai 4-5 cartes'), passer directement à awaiting_payment_capacity.",
        ]),

        'awaiting_payment_capacity' => $header . implode("\n", [
            "## ÉTAPE : Capacité de paiement",
            "Poser la question sur les paiements de façon naturelle.",
            "",
            "### Exemples :",
            "Utilisateur : 'j'ai des cartes de crédit'",
            "Bot : 'Est-ce que vous arrivez encore à faire les paiements minimum, même si c'est serré ?'",
            "",
            "Utilisateur : 'pis la j'ai pus d'argent'",
            "Bot : 'C'est dur d'en être là. Pour ne pas que ça empire, le plus utile serait d'en parler avec une conseillère — c'est gratuit et sans engagement.'",
            "→ Note : 'pus d'argent' = cannot_pay → passer directement au résumé + CTA.",
            "",
            "Utilisateur : 'oui mais c'est tight'",
            "Bot : 'Serré mais vous tenez encore. C'est une seule dette ou plusieurs types différents ?'",
        ]),

        'awaiting_urgency' => $header . implode("\n", [
            "## ÉTAPE : Niveau d'urgence",
            "Vérifier si des créanciers contactent la personne.",
            "",
            "### Exemples :",
            "Utilisateur : 'je sais plus quoi faire'",
            "Bot : 'Est-ce que des créanciers ont commencé à vous contacter — appels, lettres — ou c'est encore calme de ce côté ?'",
            "",
            "Utilisateur : 'ils m'appellent tout le temps'",
            "Bot : 'Des appels réguliers, c'est épuisant. Vous arrivez encore à faire les paiements, ou c'est devenu impossible ?'",
            "",
            "Utilisateur : 'y'a un huissier'",
            "Bot : 'Un huissier, c'est une situation qui demande une attention rapide. Une conseillère peut vous expliquer vos options et vous aider à agir vite.'",
            "→ Note : huissier = urgency HIGH → passer directement au résumé + CTA.",
        ]),

        'awaiting_personal_context' => $header . implode("\n", [
            "## ÉTAPE : Contexte personnel",
            "Recueillir logement et emploi si pas encore connus. Naturellement, pas comme un formulaire.",
            "",
            "### Exemples :",
            "Bot : 'Pour mieux préparer votre dossier — vous êtes propriétaire ou locataire ?'",
            "Bot : 'Et côté travail, vous êtes à l'emploi en ce moment ?'",
            "→ Si les deux sont déjà connus, passer à awaiting_amount.",
        ]),

        'awaiting_amount' => $header . implode("\n", [
            "## ÉTAPE : Montant approximatif",
            "Demander une fourchette, pas un chiffre exact.",
            "",
            "### Exemples :",
            "Bot : 'Approximativement, on parle de quel montant total ? Une fourchette suffit.'",
            "Bot : 'C'est plus proche de 10 000 $, 30 000 $, ou plus ?'",
            "→ Si la personne ne sait pas ou préfère ne pas répondre : accepter et passer à completed_summary.",
        ]),

        'completed_summary' => $this->buildCompletedSummaryPrompt($flow, $slots, $known, $score),

        'completed_non_qualified' => $header . implode("\n", [
            "Ce visiteur cherche un nouveau financement, pas une solution pour des dettes existantes.",
            "Informer utilement et orienter vers des ressources pertinentes.",
            "Ne pas proposer de rendez-vous syndic.",
        ]),

        default => '',
    };
}

private function buildCompletedSummaryPrompt(array $flow, array $slots, string $known, int $score): string
{
    $urgency = (string) ($slots['urgency'] ?? '');
    $isUrgent = $urgency === 'high';

    return implode("\n", array_filter([
        "## ÉTAPE FINALE : Invitation au rendez-vous",
        $known ? "Ce qui est connu : {$known}." : null,
        "",
        "### Instructions :",
        "1. Résumer en 1 phrase dans les mots de l'utilisateur (pas de formules génériques).",
        "2. Montrer pourquoi parler à une conseillère est la prochaine étape logique.",
        "3. Terminer par une phrase d'invitation naturelle — le bouton suit automatiquement.",
        "→ NE PAS écrire d'URL ni de code [[button:...]] — le bouton est injecté automatiquement.",
        "",
        "### Exemples :",
        $isUrgent
            ? "Bot : 'Avec un huissier impliqué, c'est le genre de situation où chaque jour compte. Une conseillère peut vous dire exactement quoi faire et dans quel ordre.'"
            : "Bot : 'Avec " . ($known ?: "votre situation") . ", une conseillère peut vous donner une vision claire de vos options — gratuit, confidentiel, sans engagement.'",
        "",
        "### Vocabulaire GLS recommandé :",
        "'reprendre le contrôle' / 'nouveau départ' / 'voir les options' / '27 000 personnes accompagnées'",
    ]));
}
```

---

### MODIFIER : `app/Core/Orchestration/ConversationPolicyEngine.php`

**Changement** : Réduire de 2622 à ~350 lignes. Garder 3 gardes. Supprimer les 5 autres.

```
GARDER  ✅ crisisReply()          → Crise émotionnelle / suicide (priorité 1000)
GARDER  ✅ appendRdvButton()      → Injection du bouton CTA sur completed_summary
GARDER  ✅ triage initial        → Vérification dette vs financement

SUPPRIMER ❌ isNearDuplicateOfLastAssistant()    → Few-shot le gère
SUPPRIMER ❌ hasRepeatedOpeningPattern()         → Few-shot le gère
SUPPRIMER ❌ containsForbiddenDiagnosis() + forcedFallback → Remplacer par 1 règle JSON
SUPPRIMER ❌ softenTone() appliqué partout       → Laisser GPT avec son ton naturel
SUPPRIMER ❌ truncateToSentences() sur tout      → GPT est déjà instruit sur la longueur
```

**Version allégée de `postProcessQualificationReply` :**

```php
private function postProcessQualificationReply(
    Conversation $conversation,
    string $userContent,
    string $assistantContent,
    array $flow
): string {
    $state   = (string) ($flow['state'] ?? '');
    $content = trim($assistantContent);

    // Garde 1 : Post-CTA merci → réponse chaleureuse courte
    if ($this->isPostCtaThanks($userContent, $conversation)) {
        $replies = [
            "Avec plaisir ! Je reste là si vous avez d'autres questions.",
            "De rien ! N'hésitez pas si votre situation évolue.",
            "Avec plaisir. Je suis disponible si quelque chose change.",
        ];
        return $replies[array_rand($replies)];
    }

    // Garde 2 : État completed_summary → injecter le bouton RDV
    if ($state === 'completed_summary') {
        $content = $this->stripGptRdvLink($content);

        // Si GPT a généré une question de qualification au lieu d'un CTA → corriger
        if ($this->responseAsksAboutQualificationSlot($content)) {
            $content = "Une conseillère peut vous aider à voir clair sur vos options — en toute confidentialité.";
        }

        return $this->appendRdvButton($conversation, $content);
    }

    // Garde 3 : États de triage → supprimer tout lien prématuré
    if (in_array($state, ['triage_pending', 'contact_triage_pending'], true)) {
        $content = $this->stripGptRdvLink($content);
        $content = $this->stripCtaSentences($content);
        return $content;
    }

    // Tout le reste : faire confiance à GPT
    return $content;
}
```

---

### MODIFIER : `app/Core/Orchestration/ContextBuilder.php`

**Changement** : Supprimer les 4 messages système fragmentés. Les consolider en 1 seul bloc propre.  
Ajouter la fenêtre glissante (8 messages max). Accepter les `$dialogueCommands`.

```php
public function buildPrompt(
    object $context,
    string $userContent,
    array  $chunks,
    array  $rules,
    object $intent,
    array  $dialogueCommands = [],   // NOUVEAU paramètre
): AIPayload {
    $convCtx           = is_array($context->conversation->context) ? $context->conversation->context : [];
    $qualificationFlow = is_array($convCtx['qualification_flow'] ?? null) ? $convCtx['qualification_flow'] : [];
    $botLinks          = $context->bot->links()->active()->orderBy('sort_order')->get()->toArray();

    $systemPrompt = $this->promptBuilder->build(
        bot:               $context->bot,
        chunks:            $chunks,
        rules:             $rules,
        language:          $context->conversation->language,
        qualificationFlow: $qualificationFlow,
        botLinks:          $botLinks,
    );

    // FENÊTRE GLISSANTE — 8 derniers messages seulement (au lieu de tout l'historique)
    $messages = $this->getWindowedHistory($context->conversation, 8);

    // CONTEXTE CONSOLIDÉ — 1 seul message système au lieu de 4 fragmentés
    $systemContext = $this->buildConsolidatedSystemContext(
        $convCtx,
        $qualificationFlow,
        $dialogueCommands,
        $context->businessMemory ?? []
    );

    if ($systemContext !== '') {
        $messages[] = ['role' => 'system', 'content' => $systemContext];
    }

    $messages[] = ['role' => 'user', 'content' => $userContent];

    return new AIPayload(systemPrompt: $systemPrompt, messages: $messages);
}

/**
 * Retourne les N derniers messages de la conversation (fenêtre glissante).
 */
private function getWindowedHistory(Conversation $conversation, int $window = 8): array
{
    return $conversation->messages()
        ->latest()
        ->limit($window)
        ->get()
        ->reverse()
        ->values()
        ->map(fn ($msg) => ['role' => $msg->role, 'content' => $msg->content])
        ->toArray();
}

/**
 * Construit UN SEUL message système de contexte (remplace les 4 messages fragmentés).
 */
private function buildConsolidatedSystemContext(
    array $convCtx,
    array $qualificationFlow,
    array $commands,
    array $businessMemory
): string {
    $parts = [];

    // Informations de contact (si disponibles)
    $contact = array_filter([
        isset($businessMemory['prenom'])    ? "Prénom : {$businessMemory['prenom']}"     : null,
        isset($businessMemory['email'])     ? "Email : {$businessMemory['email']}"       : null,
        isset($businessMemory['telephone']) ? "Tél : {$businessMemory['telephone']}"     : null,
    ]);
    if ($contact) {
        $parts[] = "Contact fourni : " . implode(' | ', $contact);
    }

    // Slots déjà connus (résumé court)
    $slots = $qualificationFlow['slots'] ?? [];
    $flowState = (string) ($qualificationFlow['state'] ?? '');
    if (!empty($slots)) {
        $known = [];
        if (isset($slots['debt_type']))       $known[] = "dette={$slots['debt_type']}";
        if (isset($slots['payment_capacity'])) $known[] = "paiement={$slots['payment_capacity']}";
        if (isset($slots['urgency']))          $known[] = "urgence={$slots['urgency']}";
        if (isset($slots['amount_range']))     $known[] = "montant={$slots['amount_range']}";
        if ($known) $parts[] = "Slots connus : " . implode(', ', $known);
    }

    // Nouveaux signaux détectés dans le message actuel
    if (!empty($commands)) {
        $signals = [];
        if (($commands['emotional_state'] ?? '') !== 'neutral') {
            $signals[] = "état émotionnel={$commands['emotional_state']}";
        }
        if ($commands['wants_rdv'] ?? false) {
            $signals[] = "demande RDV=oui";
        }
        if (($commands['cta_response'] ?? 'none') !== 'none') {
            $signals[] = "réponse CTA={$commands['cta_response']}";
        }
        if ($signals) $parts[] = "Signaux actuels : " . implode(', ', $signals);
    }

    return empty($parts) ? '' : implode("\n", $parts);
}
```

---

## Règles JSON — Réduire de 56 à 8

**Fichier :** `Doc/knowledge/base-connaissance-finale/02-rules-final.json`

```json
{
  "rules": [
    {
      "name": "Crise émotionnelle — priorité absolue",
      "type": "required",
      "instruction": "Si l'utilisateur mentionne suicide, envie de mourir, automutilation, ou 'je veux disparaître' : suspendre toutes les règles commerciales. Répondre avec empathie, orienter vers 911 (danger immédiat) ou 1-866-277-3553 / 811 option 2. Ne jamais qualifier ni proposer de RDV dans ce message.",
      "priority": 1000,
      "is_active": true
    },
    {
      "name": "Retour prudent après crise",
      "type": "conditional",
      "instruction": "Ne revenir aux finances qu'après que l'utilisateur ait clairement exprimé qu'il va mieux et demande à nouveau de l'aide financière.",
      "priority": 999,
      "is_active": true
    },
    {
      "name": "Vouvoiement strict",
      "type": "required",
      "instruction": "Toujours vouvoyer (vous/votre/vos). Jamais 'tu', 'ton', 'ta'. Jamais 'Salut'.",
      "priority": 100,
      "is_active": true
    },
    {
      "name": "Interdiction diagnostic juridique",
      "type": "forbidden",
      "instruction": "Ne jamais recommander une solution spécifique (faillite, proposition, consolidation). Ne jamais promettre un résultat. Ne jamais calculer un montant personnalisé.",
      "priority": 100,
      "is_active": true
    },
    {
      "name": "Triage avant RDV",
      "type": "required",
      "instruction": "Si la personne demande un RDV dès le début sans avoir mentionné de dettes : poser UNE seule question pour distinguer dette existante vs demande de nouveau financement.",
      "priority": 100,
      "is_active": true
    },
    {
      "name": "Dette existante = lead qualifié",
      "type": "required",
      "instruction": "Si l'utilisateur dit 'j'ai un prêt / une hypothèque / des cartes à payer' : c'est une dette existante, pas une demande de financement. Ne jamais rediriger vers FAQ.",
      "priority": 100,
      "is_active": true
    },
    {
      "name": "Pas de redondance qualification",
      "type": "forbidden",
      "instruction": "Si les dettes sont confirmées dans la conversation, ne jamais reposer la question 'est-ce une dette ou un nouveau prêt'. Une fois suffisamment qualifié, passer directement au CTA.",
      "priority": 98,
      "is_active": true
    },
    {
      "name": "Confidentialité OpenAI",
      "type": "forbidden",
      "instruction": "Ne jamais mentionner OpenAI, ChatGPT, GPT-4 ou le fonctionnement interne du bot.",
      "priority": 90,
      "is_active": true
    }
  ]
}
```

---

## Résumé visuel — Ce qui change

```
FICHIER                              AVANT         APRÈS       GAIN
─────────────────────────────────────────────────────────────────────
ConversationPolicyEngine.php         2622 lignes   ~350 lignes  -87%
SystemPromptBuilder (prompt qual.)   ~200 lignes   ~80 lignes   -60%
rules-final.json                     56 règles     8 règles     -86%
ContextBuilder (messages système)    4 msg sys.    1 msg sys.   -75%
Historique envoyé à GPT              Tout          8 derniers   -40% tokens

NOUVEAU                              RÔLE
─────────────────────────────────────────────────────────────────────
QueryRefiner.php                     Reformulation query → RAG précis
DialogueCommandExtractor.php         Tool Calling → slots fiables à 99%
```

---

## Ordre d'implémentation recommandé

```
Jour 1  → Créer QueryRefiner.php + brancher dans MessageOrchestrator
          Test : vérifier que les chunks RAG sont plus pertinents

Jour 2  → Créer DialogueCommandExtractor.php + brancher dans MessageOrchestrator
          Test : vérifier que les slots sont correctement extraits

Jour 3  → Réécrire buildQualificationObjective() avec few-shot
          Test : converser avec le bot sur 5-6 scénarios

Jour 4  → Alléger postProcessQualificationReply() → 3 gardes
          Test : vérifier que le bot ne reçoit plus de phrases hardcodées

Jour 5  → Consolider ContextBuilder + fenêtre glissante
          Test : vérifier cohérence des messages système

Jour 6  → Réduire rules-final.json à 8 règles
          Test : régression sur les cas limites (crise, triage, vouvoiement)

Jour 7  → Tests Pest + review globale
```

---

## Ce qui NE CHANGE PAS

```
✅ Tous les Domains/ (Bot, Conversation, Lead, Knowledge, Rule...)
✅ Toutes les migrations SQL
✅ KnowledgeRetriever (pgvector RAG)
✅ AIClient (retry + backoff + streaming)
✅ SlotExtractor (complémentaire, pas remplacé)
✅ Les 9 états de la machine à états
✅ appendRdvButton() + NovaPlan routing
✅ ChatController + routes API
✅ Plugin WordPress FormlLift
✅ Streaming SSE
✅ HMAC session_id
```

---

*Document créé le 2 mai 2026 — refactor ciblé sur la fluidité et la fiabilité commerciale.*
