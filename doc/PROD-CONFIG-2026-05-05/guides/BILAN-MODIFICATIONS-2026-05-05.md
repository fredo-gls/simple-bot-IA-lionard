# Bilan des modifications - Lionard (2026-05-05)

## Objectif global
Rendre le bot moins rigide, éviter les faux positifs (ex: "j'ai faim" -> dette), réduire le forçage RDV, et préparer une architecture pilotée par intentions/flows.



## 2) Correctifs runtime "transliterator_transliterate"
- Fichiers:
  - `app/Core/Support/TextNormalizer.php`
  - `app/Core/Routing/SmartRoutingEngine.php`
  - `app/Core/Cache/SemanticCache.php`
- Modification: ajout de fallback robuste (`transliterator_transliterate` -> `iconv` -> texte original).
- Pourquoi: le serveur crashait quand l'extension `intl` n'était pas disponible, ce qui bloquait toute réponse.

## 3) Suppression du forçage global du bouton RDV
- Fichier: `app/Core/Orchestration/ConversationPolicyEngine.php`
- Modification: suppression de l'ajout systématique du bouton RDV dans `afterAssistantResponse()` hors cas métier.
- Pourquoi: le bot forçait l'affichage du lien RDV même hors qualification, ce qui rendait la conversation artificielle.

## 4) Couche d'esquive hors contexte (intents légers)
- Fichier: `app/Core/Orchestration/ConversationPolicyEngine.php`
- Modification: ajout d'une détection d'intentions conversationnelles légères:
  - `intention_hors_contexte`
  - `intention_blague`
  - `intention_test_bot`
  - `intention_insulte_legere`
  - `intention_retour_finances`
- Pourquoi: éviter d'interpréter à tort des messages hors sujet comme des dettes.

## 5) Compteur hors contexte (1/2/3)
- Fichier: `app/Core/Orchestration/ConversationPolicyEngine.php`
- Modification:
  - incrément `off_topic_count` en contexte si hors sujet consécutif,
  - reset dès retour au sujet finance,
  - réponses graduelles:
    - 1er: humour + redirection,
    - 2e: redirection plus claire,
    - 3e+: sortie cordiale sans relance.
- Pourquoi: éviter les boucles de conversion forcée et fermer proprement la conversation quand l'utilisateur reste hors sujet.

## 6) Correction faux positif "pas de dette"
- Fichier: `app/Core/Signals/ConversationSignalDetector.php`
- Modification: priorité absolue aux négations explicites (`pas de dette`, `je n'ai pas de dettes`, etc.) dans `containsDebtSignal()`.
- Pourquoi: empêcher l'activation de la qualification dette sur des messages de négation.

## 7) Référentiel officiel de conversation (V2)
- Fichier: `config/conversation_model.php`
- Modification: ajout d'un référentiel central:
  - taxonomie officielle des dettes (11 types) + aliases/fautes/profil/question/garde-fou,
  - flows `particulier` / `entreprise` (champs cibles),
  - intents `intention_*`,
  - règles bot,
  - scénarios de test de base.
- Pourquoi: sortir de la logique figée par mots-clés et préparer un pilotage plus cohérent du moteur.

## 8) Alignement extracteur/orchestrateur sur intents normalisés
- Fichiers:
  - `app/Core/Orchestration/DialogueCommandExtractor.php`
  - `app/Core/Orchestration/MessageOrchestrator.php`
- Modification:
  - `debt_type` aligné sur la nouvelle taxonomie,
  - ajout de `intent_name` (`intention_*`) dans le schéma d'extraction,
  - priorité à `intent_name` dans l'orchestrateur.
- Pourquoi: normaliser les décisions conversationnelles avec la nomenclature métier demandée.

## 9) Injection des règles métier dans le prompt système
- Fichier: `app/Support/PromptBuilding/SystemPromptBuilder.php`
- Modification: ajout de `buildCoreBotRules()` et injection des règles depuis `config/conversation_model.php`.
- Pourquoi: garantir que les contraintes métier clés soient systématiquement présentes dans le prompt.

## 10) Templates de cadrage créés
- Dossier: `Changes 5-5`
- Fichiers:
  - `taxonomy_debts.yaml`
  - `flows_particulier_entreprise.yaml`
  - `intents_map.yaml`
  - `policy_rules.md`
  - `test_scenarios.yaml`
- Pourquoi: fournir une base editable pour stabiliser la gouvernance fonctionnelle (taxonomie, flows, intents, tests).

## Statut actuel (synthèse)
- Corrigé: crashs runtime, forçage RDV global, hors contexte en boucle, faux positif "pas de dette".
- En place: base V2 (taxonomie/intents/règles/flows en config) + intents légers.
- Reste à finaliser: exécution complète du dual-flow particulier/entreprise dans la machine d'états et automatisation des scénarios de test.
