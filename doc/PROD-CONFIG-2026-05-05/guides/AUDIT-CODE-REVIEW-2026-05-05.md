# Audit & Code Review — Lionard (bot closer/support dettes.ca)
**Date :** 5 mai 2026
**Auditeur :** Revue de code experte
**Objectif évalué :** Bot closer + support empathique capable d'orienter et de convertir des prospects endettés vers une conseillère GLS, en allégeant la prise en charge humaine.

---

## 1. Lecture stratégique — l'objectif business

Le challenge réel n'est ni un FAQ ni un bot RAG générique. C'est trois choses imbriquées :

1. **Closer empathique** — qualifier un visiteur en stress émotionnel sans le brusquer, et le convertir en RDV gratuit (Clinique Liberté).
2. **Triage intelligent** — distinguer dette existante (RDV) vs prévention (NovaPlan) vs apprentissage (Abondance360) vs hors-sujet/nouveau financement.
3. **Tampon humain** — réduire la charge des conseillères en absorbant les premières interactions, tout en sachant escalader proprement (crise, client existant, dossier complexe).

Tout doit être évalué à travers ces trois prismes. Un bot qui répond "correctement" mais qui pose deux questions de trop ou qui mégote sur l'empathie va saigner du taux de conversion, et c'est exactement là que se joue le ROI.

---

## 2. Ce qui est solide (à garder, à protéger)

### 2.1 Architecture en couches inspirée Intercom Fin / Rasa CALM

Le pipeline `MessageOrchestrator → DialogueCommandExtractor → QueryRefiner → KnowledgeRetriever → SystemPromptBuilder → AIClient → PolicyEngine.after` est une bonne implémentation des patterns modernes. La séparation "compréhension (tool calling déterministe à T=0) / récupération / génération (créative)" est exactement ce que font les bots performants. La majorité des projets que je vois sont encore monolithiques avec 1 seul appel GPT — ici l'effort est réel.

### 2.2 Tool Calling pour l'extraction de slots

`DialogueCommandExtractor` utilise OpenAI Tool Calling avec un schéma JSON typé (enum), température 0.0, et fallback gracieux. C'est un bon choix : beaucoup plus fiable que le parsing JSON manuel des anciennes implémentations. Les 13 slots couvrent bien le domaine (capacity, urgency, debt_type, structure, amount, housing, employment, family, emotional_state, wants_rdv, has_debt_confirmed, is_new_loan_request, cta_response).

### 2.3 RAG avec QueryRefiner

Reformuler le joual ("chu pu capable") en français standard avant l'embedding est une vraie valeur ajoutée. C'est ce que fait Intercom Fin sous le capot. La précision du retrieval s'effondre quand l'embedding est généré sur du texte parlé ou des fautes — ce détail fait la différence sur le terrain.

### 2.4 Charte conversationnelle et personas

`charte-style-conversation-lionard.md` est le meilleur asset du projet. La règle "ancrage → reformulation → action en 2-3 phrases", la liste des phrases interdites (« Je comprends » seul, « C'est beaucoup à porter »…) et la table joual sont d'un niveau qu'on retrouve rarement. Les 7 personas few-shot dans `SystemPromptBuilder::buildFewShotConversation()` sont du gold standard.

### 2.5 CrisisGuard

Le guard est sérieux : keywords FR + EN, mode crise persisté, gestion du retour calme, pas de bridge commercial brutal. C'est un sujet où n'importe quel manquement est un risque légal et humain — l'implémentation tient la route.

### 2.6 Stack technique

Laravel 13 + PHP 8.3 + pgvector + Sanctum + SSE streaming, c'est la stack que je recommanderais pour un projet de cette taille. Les choix sont cohérents.

---

## 3. Ce qui ne marche pas — problèmes critiques

### 3.1 Dette de code morte (urgence : haute)

Plusieurs fichiers sont théoriquement "remplacés" mais toujours présents et jamais purgés :

- `app/Core/Orchestration/UserMessageAnalyzer.php` (174 lignes) — la doc dit "supprimé", il existe encore. Aucun appelant.
- `app/Core/Orchestration/IntentDetector.php` (68 lignes) — idem, "supprimé" mais présent. Aucun appelant.
- `app/Core/Orchestration/ResponseValidator.php` (152 lignes) — créé, testé, jamais branché dans le pipeline. Le `MessageOrchestrator` ne l'appelle pas.
- `app/Core/Memory/SlotMerger.php` (127 lignes) — créé avec gestion `is_correction` (rollback), testé… mais pas utilisé. L'orchestrateur a sa propre méthode privée `updateConversationSlots()` qui n'a pas la logique de rollback.
- `app/Core/Orchestration/SlotExtractor.php` (614 lignes) — pattern hérité de l'avant-DialogueCommandExtractor. Encore appelé par `QualificationFlowEngine::fillSlotsAndAdvance()`. Doublon partiel avec DialogueCommandExtractor.

**Impact :** un nouveau dev se perd, le `git blame` ment, et surtout la fonctionnalité rollback (l'utilisateur se corrige) que la doc présente comme implémentée *ne tourne pas en prod*. Quand un prospect dit "finalement c'est plutôt 80 000 $", le slot `amount_range` n'est pas mis à jour parce que `updateConversationSlots()` ignore les corrections.

**Action :** soit brancher `SlotMerger` dans le pipeline et supprimer l'`updateConversationSlots()` privé de l'orchestrateur, soit l'inverse, mais arrêter cette double maintenance. Idem pour `ResponseValidator` — soit on l'utilise, soit on le supprime.

### 3.2 La promesse "PolicyEngine slim 350 lignes" n'est pas tenue à l'échelle du système

`ConversationPolicyEngine` est effectivement passé à 343 lignes — c'est bon. Mais la complexité a juste migré : `QualificationFlowEngine` fait **1694 lignes**, et c'est lui qui contient maintenant la quasi-totalité de la logique métier (state machine, post-processing GPT, regex de détection, fallback questions, refusal cooldown, slot inference par regex…). On a déplacé le désordre, pas réduit la complexité globale.

Le problème de fond : le `postProcess()` du QualificationFlowEngine **réécrit fréquemment la réponse de GPT**. Exemples observés :

- `forcedFallbackQuestionForState()` : si GPT pose la "mauvaise" question, on jette sa réponse et on injecte une question hardcodée.
- `containsForbiddenDiagnosis()` : si GPT prononce "proposition de consommateur" ou "consolidation", la réponse est remplacée par un texte générique.
- `responseAsksAboutWrongSlot()` : si GPT dévie d'un slot attendu, sa réponse est écrasée.
- `truncateToSentences(_, 4)` : on coupe la réponse à N phrases sans respecter la structure rhétorique.

**C'est exactement le problème "PolicyEngine trop lourd qui annule GPT" que le refactor V3 prétendait résoudre.** Il a été déplacé d'un fichier à un autre. Sur le terrain, le bot peut paraître "mécanique" parce que ses réponses sont régulièrement remplacées par des fallbacks hardcodés.

**Action :** réduire le `postProcess` à de la **validation/sanitization** (strip de liens RDV non autorisés, ajout du bouton, normalisation des accents), pas du remplacement. Si GPT pose la mauvaise question, c'est un signal pour améliorer le **prompt**, pas pour overrider à la main.

### 3.3 Le SemanticCache n'est pas sémantique

`app/Core/Cache/SemanticCache.php` fait un `hash('sha256', normalize(question))`. C'est un cache exact-match après normalisation — pas un cache sémantique. Conséquence : "j'ai 25 000 $ de dettes" et "j'ai environ 25k de dettes" ont deux clés différentes et ne se factorisent pas. Le hit rate doit être autour de 0–5 % en pratique.

Pour un vrai cache sémantique, il faut embedder la question, faire une recherche approximative (cosine ≥ 0.95) sur les 50 dernières clés, et invalider par bot/topic. Sinon, autant le renommer `ExactQuestionCache` et l'assumer.

**Action minimale :** soit l'implémenter pour de vrai (embed + recherche par similarité), soit le désactiver par défaut sur le bot Lionard où chaque réponse est censée être personnalisée. Aujourd'hui il bypass déjà tout dès qu'un flowState est actif — donc en qualification, il ne sert quasiment à rien.

### 3.4 Pas de logging de qualité RAG

Le modèle `RetrievalLog` existe en base de données (migration `2024_01_01_000015`), avec table créée. **Personne n'écrit dedans.** Aucun `RetrievalLog::create(...)` dans tout `app/`. Conséquence : impossible de répondre à "est-ce que mes chunks sont pertinents ?", "quel est le score moyen ?", "quels sont les 10 questions où le RAG retourne du bruit ?". C'est aveugle.

**Action :** logger systématiquement dans `KnowledgeRetriever::retrieve()` : query brute, refined query, top_k chunks avec scores, durée, conversation_id. C'est 30 lignes de code pour un retour énorme en monitoring.

### 3.5 Pas de mécanisme de handoff (escalation conseillère)

Le `BotSetting` a un flag `allow_human_handoff` et `ConversationState::STATE_HANDOFF` est défini, mais **aucun code n'utilise ces constantes**. Il n'y a aucune route, aucun job, aucun event qui prend en charge "passer la conversation à un humain".

Pour un bot qui doit alléger les conseillères, c'est un gros trou :

- Pas de notification temps réel à l'équipe quand un lead chaud est qualifié (saisie de salaire détecté, par exemple).
- Pas de reprise propre par la conseillère qui voit l'historique du chat.
- Pas de signal côté UI qu'une vraie personne va répondre.

**Action :** ajouter un véritable workflow handoff : event `LeadReadyForHumanHandoff` → notification Slack/email à l'équipe → mise en pause du bot pour cette conversation → reprise en mode "chat live". C'est le composant qui ferme la boucle business.

### 3.6 Notifications email fragiles, pas de retry/queue cohérent

Le projet utilise `MAIL_MAILER=log` par défaut (`.env.example`). En prod, `NewSupportTicketMail` et `SupportTicketConfirmationMail` existent mais je ne vois pas de file d'attente dédiée ni de fallback en cas d'échec SMTP. Pour un canal "support@dettes.ca", chaque email perdu = un lead perdu.

**Action :** dispatcher tous les emails sur la queue `mail` avec retry x5 et logging d'échec dans une table `mail_failures`.

### 3.7 Sécurité — points sensibles

- `ADMIN_PASSWORD=@DMIN123` dans `.env.example` : à supprimer du fichier exemple, c'est tentant pour les copies sans relecture.
- `OPENAI_API_KEY` directement dans `.env`. Pour la prod, prévoir AWS Secrets Manager ou Vault (déjà mentionné comme TODO dans la doc).
- Rate limiting `throttle:60,1` sur `/chat` est correct, mais `/chat/stream` partage la même limite et peut être consommé par un client qui replug en boucle.
- Pas de validation côté serveur que `session_id` n'a pas été tampered (Sanctum protège l'auth, mais le mapping session_id → conversation est confiance pure).

### 3.8 Tests insuffisants

581 lignes de tests pour ~6700 lignes de Core. Couverture probablement < 20 %. Les fichiers les plus critiques (`MessageOrchestrator`, `QualificationFlowEngine`, `DialogueCommandExtractor`, `SmartRoutingEngine`) n'ont pas de tests dédiés. Tester `QualificationFlowEngine` est complexe vu sa taille mais c'est exactement pour ça qu'il faut le faire — c'est lui qui décide si un lead chaud est converti ou perdu.

Il manque notamment :
- Tests sur les transitions de la state machine (rollback, completed_summary qui se rouvre, post_refusal cooldown).
- Tests sur le `SmartRoutingEngine` (segments qualified_lead / partnership / off_topic / info_general).
- Tests d'intégration scénarisés avec mock OpenAI : 7 personas validées + 5 scénarios d'échec connus.

---

## 4. Ce qui doit être amélioré pour atteindre l'objectif "closer empathique"

### 4.1 Empathie spécifique vs générique

La charte le dit bien : « ancrage dans les mots exacts du prospect ». Aujourd'hui le bot s'appuie sur les few-shots pour le faire, ce qui marche partiellement, mais il n'y a pas de mécanisme structurel pour **forcer la reprise des mots de l'utilisateur**. Une amélioration concrète : extraire les 2-3 mots émotionnels saillants du dernier message utilisateur (« étouffe », « plus capable », « je dors plus ») et les injecter en évidence dans le contexte système juste avant la génération.

```
[CONTEXTE — Mots saillants du prospect à reprendre absolument :]
- "ma carte m'étouffe"
- "je dors plus"
```

Ça oblige le LLM à les rebrancher au lieu de produire une formule type. Petit changement, gain qualitatif majeur.

### 4.2 Détection d'objections vs détection d'urgence

Le bot détecte bien les signaux d'urgence (huissier, saisie). Il détecte mal les **objections de closing** :
- « c'est payant ? » → détecter et répondre objection prix
- « je veux pas qu'on m'appelle » → objection canal
- « j'ai honte » → objection émotionnelle
- « je vais essayer tout seul » → objection autonomie

Ces objections sont aujourd'hui traitées implicitement par le LLM via les personas few-shot. Pour un closer, il faut les **typer explicitement** dans le `DialogueCommandExtractor` (ajouter un slot `objection_type` avec 6-8 valeurs) et avoir une sous-section du prompt qui répond pile à l'objection détectée. C'est ce que fait Drift sur leur conversational sales bot.

### 4.3 Score de qualification visible et actionable

`flow.score` existe mais il est invisible côté admin. Pour gérer une équipe de conseillères, il faut un dashboard "leads en cours" trié par score, avec la possibilité de filtrer par segment et d'intervenir manuellement. Aujourd'hui c'est une boîte noire — l'équipe GLS ne peut pas dire "j'ai 12 leads chauds en attente de RDV ce matin".

### 4.4 Continuité après reformulation/correction

Le scénario "Visiteur (tour 2) : 10 000 $ → Visiteur (tour 5) : 80 000 $ avec hypothèque" est documenté comme "rollback bien géré" dans `ARCHITECTURE-CIBLE`. En réalité, le code actuel n'a pas de gestion explicite de rollback dans le pipeline (le `SlotMerger` qui le fait n'est pas branché). Donc le slot `amount_range` reste figé sur la première valeur. Ça crée des résumés finaux faux (« avec environ 10 000 $ ») qui sapent la crédibilité.

### 4.5 Personnalisation par canal d'arrivée

`isInfoFirstPage()` détecte les pages "contact"/"nous-joindre" et déclenche `contact_triage_pending`. Bien. Mais il manque :
- Détection page blog/article spécifique → contexte "lecteur, pas encore en démarche"
- Détection page tarifs → contexte "magasinage, comparaison"
- Détection retour visiteur (cookie/UTM) → reprendre le contexte de la session précédente

Un closer humain adapte toujours son ouverture au "où la personne arrive". Le bot devrait le faire aussi.

### 4.6 Latence

`OPENAI_MAX_TOKENS=2048` est trop large pour ce cas d'usage. Les réponses doivent faire 2-3 phrases (~80 tokens). Mettre `max_tokens=200` réduit la latence et les coûts. La cible documentée (« < 2 s ») est inatteignable avec 2048 tokens et un GPT-4o.

`retrieval_top_k=5` est OK pour un FAQ mais lourd pour ce bot qui privilégie le few-shot. Tester avec `top_k=3` + score min plus strict (0.65 vs 0.55).

### 4.7 Fallback OpenAI absent

Le `persistFallback()` quand OpenAI n'est pas configuré est très sec : "Bonjour ! Je suis le chatbot. La clé OpenAI n'est pas configurée…". Pour la prod, il faut un fallback **utilisable par un humain** : "Notre assistant est temporairement indisponible. Vous pouvez nous joindre au 1-877-961-0008 ou prendre rendez-vous : [URL]". Sinon une panne OpenAI = lead perdu.

### 4.8 Métriques de conversion non instrumentées

Aucun event `LeadConverted`, `RdvButtonClicked`, `RdvBooked`. La conversion réelle (clic vers Clinique Liberté → RDV planifié) n'est pas trackée côté backend. Les UTM sont dans l'URL (`utm_source=chatbot`) mais sans webhook côté GLS qui dit "ce RDV vient bien du bot", on ne peut pas calibrer le bot.

**Action :** un webhook côté Clinique Liberté qui notifie le backend Lionard quand un RDV est pris avec utm_source=chatbot, et un event de tracking lié à `conversation_id` (passer le conversation_id en paramètre URL au moment du clic bouton).

---

## 5. Comparaison aux chatbots performants

### Intercom Fin
- ✅ Layer "Query Understanding + Refinement" : Lionard a `DialogueCommandExtractor` + `QueryRefiner`. Match.
- ✅ Pas de réponse hors knowledge : Lionard a un retrieval avec score min. Match faible — Fin est plus strict.
- ❌ Confidence-based escalation : Fin escalade dès que le score < seuil. Lionard n'a pas ça.
- ❌ Test mode admin : Fin permet de "rejouer" une conversation pour ajuster le KB. Lionard n'a pas d'outil équivalent.

### Drift Conversational AI
- ❌ Playbooks par persona/source : Drift adapte la conversation en fonction de la page d'arrivée et de l'entreprise visiteur. Lionard a un début (`isInfoFirstPage`) mais c'est minimal.
- ❌ A/B testing intégré sur les CTA : pas présent dans Lionard.
- ❌ Lead scoring + routing automatique vers SDR : embryon (`flow.score`) mais pas de pipeline complet.

### Ada / Zendesk Answer Bot
- ✅ KB structurée par intent : Lionard a `intents` + `intent_patterns` + RAG. Bonne couverture.
- ❌ Multi-langue avancé : Lionard a un fr/en basique. Pas de détection automatique fine ni de KB par langue.
- ❌ Suggested actions / quick replies : pas de boutons de réponse rapide dans le widget pour orienter (« Oui je suis en retard », « Non je gère »).

### Rasa CALM
- ✅ Tool calling pour commands : Lionard l'a via OpenAI. Match.
- ✅ Few-shot par état : Lionard l'a. Match.
- ❌ DialogueStack natif : Rasa gère plusieurs flows imbriqués (qualification + Q&A + collecte d'infos), Lionard a une state machine plate qui ne sait pas suspendre/reprendre.

### Closer-bots financiers spécialisés (Tally, Wisetack, Achieve)
- ✅ Vouvoiement strict / persona dédiée : Lionard match.
- ✅ Garde-fous diagnostic : Lionard match.
- ❌ Pré-qualification par revenus/montant en chiffres précis : Lionard reste en fourchettes (`amount_range` 5k_15k…). Plus flou.
- ❌ Calcul de "fit" instantané (« vous êtes éligible à X solutions ») : Lionard ne calcule pas — c'est probablement souhaité (garde-fou diagnostic), mais on peut au moins dire « il existe 3 voies pour un profil comme le vôtre ».
- ❌ SMS follow-up automatique 24h/48h après no-show : pas implémenté ici.

---

## 6. Recommandations priorisées

### Sprint 1 (1 semaine) — Hygiène et désherbage
1. Supprimer `UserMessageAnalyzer.php`, `IntentDetector.php` (code mort).
2. Brancher `SlotMerger` dans `MessageOrchestrator` à la place de `updateConversationSlots()` — débloquer le rollback.
3. Brancher `ResponseValidator` en post-processing (avec injection de rappel dans le prochain tour, pas réécriture).
4. Supprimer `ADMIN_PASSWORD` de `.env.example`.
5. Réduire `OPENAI_MAX_TOKENS` à 200 pour le bot Lionard (override BotSetting).
6. Activer le logging dans `RetrievalLog` à chaque retrieve.

### Sprint 2 (1-2 semaines) — Qualité du closing
7. Ajouter le slot `objection_type` dans `DialogueCommandExtractor` (price / channel / shame / autonomy / timing / trust).
8. Ajouter une section "objection courante" dans le prompt par état, avec réponse few-shot par objection.
9. Implémenter l'extraction des "mots saillants du prospect" et les injecter dans le contexte système.
10. Améliorer le `persistFallback` avec téléphone et lien RDV.
11. Webhook UTM RDV pris pour boucler la conversion côté backend.

### Sprint 3 (2 semaines) — Handoff et opérations
12. Implémenter le workflow handoff complet : event + notification équipe + mise en pause bot + reprise UI.
13. Dashboard admin "leads en cours" trié par score + segment.
14. Logger les RetrievalLog + dashboard de pertinence (% de chunks avec score > 0.65, top queries qui retournent du bruit).
15. SMS follow-up J+1 / J+3 après une promesse de RDV non confirmée (Twilio ou équivalent).

### Sprint 4 (2 semaines) — Tests et observabilité
16. Couverture Pest des chemins critiques : `MessageOrchestrator` (mock OpenAI), `QualificationFlowEngine` (toutes les transitions), `SmartRoutingEngine` (4 segments), `DialogueCommandExtractor` (10 messages réels).
17. 7 scénarios end-to-end avec mock OpenAI (Pierre, Karine, honte, refus, hésitation, stress, lead chaud) qui valident le bouton RDV final.
18. Sentry/Telescope en prod, alertes sur erreur OpenAI > 1 % des requêtes.
19. Health check endpoint qui pingue OpenAI + pgvector + Redis + DB.

### Sprint 5+ — Sophistication
20. Refacto progressif de `QualificationFlowEngine` (1694 lignes) en pattern State avec une classe par état (extraction comme prévue dans la doc cible).
21. Vrai cache sémantique embedding-based (ou suppression du cache pour Lionard).
22. Multi-tour anticipation : pré-charger les RDV disponibles côté Clinique Liberté pour les afficher dans le bouton.

---

## 7. Synthèse

Le projet a une **vision juste** et une **architecture moderne** — le projet est dans le top 10 % de ce que je vois sur le marché PHP/Laravel pour un chatbot conversationnel. La doc est exceptionnelle (charte, personas, architecture cible). Les choix de stack sont solides.

Les vrais points faibles sont :

1. **L'écart documentation/réalité** — beaucoup de choses sont dites "faites" alors qu'elles sont à moitié implémentées (SlotMerger, ResponseValidator, slim PolicyEngine). C'est un piège classique en refactor itératif : on rédige la cible avant de l'avoir construite.
2. **Le post-processing trop agressif** dans `QualificationFlowEngine::postProcess()` — qui réintroduit le problème "PolicyEngine qui annule GPT" sous un autre nom.
3. **L'absence de boucle business fermée** — pas de handoff, pas de tracking conversion, pas de dashboard équipe. Un closer-bot sans handoff ne peut pas vraiment "alléger les conseillères".
4. **Le manque d'instrumentation** — RetrievalLog vide, pas de métriques de conversion, ResponseValidator non branché.

Ces 4 points expliquent pourquoi le bot peut sembler "intelligent en démo mais générique en prod". Ils sont tous corrigeables en 4-5 sprints sans toucher à l'architecture.

L'ADN empathique du bot est dans la **charte de style** et les **personas few-shot**. Tant que ces deux assets sont à jour et de qualité, le bot a une chance d'être un vrai closer. Tout le reste (orchestration, RAG, guards) est de la plomberie qui doit servir cette voix — pas la dicter.

---

*Audit produit le 2026-05-05 — à mettre en regard de `ETAT-DU-PROJET-2026-05-02.md` et `ARCHITECTURE-CIBLE-SUPER-BOT.md`.*
