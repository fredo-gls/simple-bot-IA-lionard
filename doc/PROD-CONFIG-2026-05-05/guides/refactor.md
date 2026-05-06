Oui. Pour une **V2 ultra clean**, je te recommande de sortir la logique énorme du `ConversationPolicyEngine` et de créer une architecture par services spécialisés. Actuellement, ton moteur fait trop de choses au même endroit : crise, routing, scoring, qualification, anti-RDV, suggestions, post-traitement. C’est puissant, mais difficile à maintenir. 

# Architecture V2 recommandée

```txt
User
 ↓
MessageOrchestrator
 ↓
UserMessageAnalyzer
 ↓
SafetyGuard
 ↓
ConversationStateManager
 ↓
IntentResolver
 ↓
LeadScoringService
 ↓
RoutingDecisionEngine
 ↓
KnowledgeRetrievalService
 ↓
PromptBuilder
 ↓
AIClient
 ↓
ResponseGuard
 ↓
ActionDispatcher
 ↓
Persist
```

## 1. `MessageOrchestrator`

Rôle : uniquement coordonner.

Il ne doit pas contenir de logique métier lourde.

```php
final class MessageOrchestrator
{
    public function handle(Conversation $conversation, string $message): Message
    {
        $analysis = $this->analyzer->analyze($message);

        $safety = $this->safetyGuard->check($conversation, $message, $analysis);
        if ($safety->shouldInterrupt()) {
            return $this->responder->policyReply($conversation, $message, $safety);
        }

        $state = $this->stateManager->advance($conversation, $message, $analysis);

        $intent = $this->intentResolver->resolve($conversation, $message, $analysis);

        $score = $this->leadScoring->score($conversation, $analysis, $state);

        $decision = $this->decisionEngine->decide(
            conversation: $conversation,
            analysis: $analysis,
            state: $state,
            intent: $intent,
            score: $score,
        );

        $chunks = $this->retrieval->retrieveForDecision($conversation, $message, $decision);

        $payload = $this->promptBuilder->build(
            conversation: $conversation,
            message: $message,
            decision: $decision,
            chunks: $chunks,
        );

        $response = $this->aiClient->chat($payload);

        $final = $this->responseGuard->validate($conversation, $message, $response, $decision);

        $this->actionDispatcher->dispatch($conversation, $decision, $final);

        return $this->messageRepository->persist($conversation, $message, $final, $decision, $chunks);
    }
}
```

Ton orchestrateur actuel est déjà bien structuré, mais il doit déléguer encore plus. 

---

# 2. `SafetyGuard`

Remplace la partie crise de `ConversationPolicyEngine`.

```php
final class SafetyGuard
{
    public function check(Conversation $conversation, string $message, array $analysis): SafetyDecision
    {
        if ($this->detectCrisis($message)) {
            return SafetyDecision::interrupt(
                policy: 'crisis_emergency',
                response: "Je suis vraiment désolé que vous viviez cela. Votre sécurité passe d’abord..."
            );
        }

        return SafetyDecision::continue();
    }
}
```

Objectif : **toujours prioritaire**, avant scoring, RAG ou IA.

---

# 3. `ConversationStateManager`

Gère uniquement les états :

```txt
idle
triage_pending
awaiting_debt_type
awaiting_debt_structure
awaiting_urgency
awaiting_payment_capacity
awaiting_amount
completed_qualified
completed_non_qualified
resource_orientation
```

```php
final class ConversationStateManager
{
    public function advance(Conversation $conversation, string $message, array $analysis): ConversationState
    {
        $state = $conversation->context['state'] ?? 'idle';

        return match ($state) {
            'idle' => $this->startState($conversation, $analysis),
            'triage_pending' => $this->resolveTriage($conversation, $analysis),
            'awaiting_debt_structure' => $this->captureDebtStructure($conversation, $message),
            'awaiting_urgency' => $this->captureUrgency($conversation, $message),
            default => ConversationState::fromConversation($conversation),
        };
    }
}
```

---

# 4. `LeadScoringService`

Le scoring doit sortir du policy engine.

```php
final class LeadScoringService
{
    public function score(Conversation $conversation, array $analysis, ConversationState $state): LeadScore
    {
        $score = 0;

        if ($analysis['has_debt_signal']) {
            $score += 20;
        }

        if ($analysis['has_rdv_request']) {
            $score += 20;
        }

        if ($analysis['urgency'] === 'high') {
            $score += 30;
        }

        if (($state->slots['payment_capacity'] ?? null) === 'cannot_pay') {
            $score += 25;
        }

        return LeadScore::make($score);
    }
}
```

Avec statuts :

```php
enum LeadTemperature: string
{
    case Cold = 'cold';
    case Warm = 'warm';
    case Hot = 'hot';
    case Crisis = 'crisis';
    case NonQualified = 'non_qualified';
}
```

---

# 5. `RoutingDecisionEngine`

C’est le cerveau business.

Il décide :

* répondre avec info
* poser une question
* proposer un outil
* proposer RDV
* rediriger vers liens pratiques
* bloquer demande de prêt
* capturer lead

```php
final class RoutingDecisionEngine
{
    public function decide(
        Conversation $conversation,
        array $analysis,
        ConversationState $state,
        object $intent,
        LeadScore $score
    ): ConversationDecision {
        if ($analysis['has_loan_request'] && ! $analysis['has_debt_signal']) {
            return ConversationDecision::nonQualifiedLoan();
        }

        if ($score->isHot()) {
            return ConversationDecision::offerAppointment();
        }

        if ($state->needsMoreQualification()) {
            return ConversationDecision::askNextQualificationQuestion($state);
        }

        if ($analysis['intent'] === 'resource_support') {
            return ConversationDecision::resourceOrientation();
        }

        return ConversationDecision::informThenRequalify();
    }
}
```

---

# 6. `ResponseGuard`

Remplace le post-traitement actuel :

* anti-répétition
* ton humain
* suppression CTA trop tôt
* fallback si réponse vide
* limitation longueur
* vérification “pas de prêt”

```php
final class ResponseGuard
{
    public function validate(
        Conversation $conversation,
        string $userMessage,
        AIResponse $response,
        ConversationDecision $decision
    ): string {
        $content = $this->tone->soften($response->content);

        $content = $this->antiRepetition->clean($conversation, $content);

        if (! $decision->allowsAppointmentCta()) {
            $content = $this->removeAppointmentCta($content);
        }

        if (! $decision->allowsLoanLanguage()) {
            $content = $this->removeLoanPromise($content);
        }

        return $this->lengthLimiter->limit($content, 4);
    }
}
```

---

# 7. `ActionDispatcher` V2

Ton `ActionDispatcher` déclenche aujourd’hui les stratégies par intent, mais il ne tient pas assez compte du score/contexte. 

V2 :

```php
$this->actionDispatcher->dispatch(
    conversation: $conversation,
    decision: $decision,
    score: $score,
    finalResponse: $final
);
```

Et tu déclenches un lead uniquement si :

```php
$score->isHot()
|| $decision->type === 'offer_appointment'
|| $state->name === 'completed_qualified'
```

---

# Structure de dossiers recommandée

```txt
app/Core/Conversation/
  Orchestration/
    MessageOrchestrator.php

  Analysis/
    UserMessageAnalyzer.php
    IntentResolver.php

  Safety/
    SafetyGuard.php
    CrisisDetector.php

  State/
    ConversationStateManager.php
    ConversationState.php
    StateTransition.php

  Scoring/
    LeadScoringService.php
    LeadScore.php
    LeadTemperature.php

  Decision/
    RoutingDecisionEngine.php
    ConversationDecision.php
    DecisionType.php

  Retrieval/
    KnowledgeRetrievalService.php

  Response/
    ResponseGuard.php
    ToneSanitizer.php
    AntiRepetitionGuard.php
    CtaGuard.php

  Actions/
    ActionDispatcher.php
```

---

# Priorité de refactor

1. **Corriger l’encodage UTF-8**
2. Extraire `SafetyGuard`
3. Extraire `LeadScoringService`
4. Extraire `ConversationStateManager`
5. Créer `RoutingDecisionEngine`
6. Simplifier `ConversationPolicyEngine` ou le supprimer
7. Modifier `ActionDispatcher` pour utiliser `decision + score`

