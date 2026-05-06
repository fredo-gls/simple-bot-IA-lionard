# Modifications — 2026-05-01

## Vue d'ensemble

Trois axes de travail ce jour :
1. Correction des corruptions UTF-8 dans les fichiers backend
2. Implémentation du slot-filling pour le flux de qualification
3. Amélioration du ton et de l'empathie des réponses GPT-4o

---

## 1. Correction UTF-8 / Mojibake

**Commit :** `99415ef` — *Fix backend encoding issues*

**Problème :** Les classes de caractères regex contenaient des octets corrompus (ex: `[eÃƒÆ'Ã‚Âª]`) au lieu de vrais accents français, ce qui cassait silencieusement toutes les détections sur des mots comme "réclamation", "déjà", "répondu".

**Fichiers corrigés :**
- `app/Core/Orchestration/ConversationPolicyEngine.php` — réécriture complète en UTF-8 propre
- `routes/console.php` — commentaires français réparés

---

## 2. Slot-Filling — Nouveau fichier : `SlotExtractor.php`

**Fichier :** `app/Core/Orchestration/SlotExtractor.php` *(nouveau)*

**Problème résolu :** Le flux de qualification posait les questions une par une dans un ordre fixe, même si l'utilisateur avait déjà donné l'information. Exemple : l'utilisateur dit "j'ai 30 000$ de dettes et je n'arrive plus à payer" → le bot posait quand même les 4 questions séquentielles.

**Solution :** `SlotExtractor` scanne chaque message pour TOUS les slots en même temps.

**Slots détectés :**

| Slot | Valeurs possibles |
|---|---|
| `debt_structure` | `single`, `multiple` |
| `urgency` | `high`, `medium`, `low` |
| `payment_capacity` | `cannot_pay`, `partial`, `normal` |
| `amount_range` | `5k_15k`, `15k_50k`, `50k_plus` |
| `debt_type` | `cartes`, `hypotheque`, `pret_auto`, `impots`, `pret_personnel`, `commercial`, `mixte` |

**Règle clé :** Ne retourne que les slots avec détection confiante. `unknown` = ne pas remplir = poser la question.

**Patterns critiques ajoutés pour `payment_capacity` :**
```
"non" / "pas du tout" (réponse courte négative isolée)  →  cannot_pay
"n'arrive plus à [payer / suivre / gérer]"              →  cannot_pay
"n'arrive plus" suivi de [à]                            →  cannot_pay
```
Ces patterns manquaient et causaient le bug principal (question répétée sur la capacité de paiement).

---

## 3. Slot-Filling — Refactor `ConversationPolicyEngine.php`

**Fichier :** `app/Core/Orchestration/ConversationPolicyEngine.php`

### Nouvelles dépendances
```php
public function __construct(
    protected KnowledgeRetriever $retriever,
    protected SlotExtractor $slotExtractor,  // NOUVEAU
) {}
```

### Nouvelles méthodes

**`fillSlotsAndAdvance(array $flow, string $userContent, array $analysis): array`**
- Extrait les slots du message courant via `SlotExtractor`
- Fusionne dans les slots existants (sans jamais écraser)
- Attribue les points de score uniquement au premier remplissage
- Appelle `advanceToNextMissingSlot()` pour avancer l'état

**`advanceToNextMissingSlot(array $flow): array`**
- Avance l'état au premier slot manquant dans l'ordre logique
- Ordre : `debt_structure` → `urgency` → `payment_capacity` → `amount_range` → `completed_summary`
- Saute automatiquement les slots déjà remplis

**`scoreForSlot(string $slot, string $value): int`**
- Scoring par slot et par valeur (ex: `urgency=high` → +35 pts)
- Appelé uniquement à la première détection (pas de double-scoring)

**`recoverSlotsFromHistory(Conversation $conversation, array $flow): array`**
- Déclenché quand `isAlreadyAnsweredSignal()` détecte de la frustration
- Scanne les 10 derniers messages utilisateur avec `SlotExtractor`
- Récupère tous les slots manquants depuis l'historique

### `isAlreadyAnsweredSignal()` — patterns ajoutés
```
j'ai dit que / j'ai dit non / j'ai dit oui / j'ai dit ça
je (vous/te) l'ai (déjà) dit
c'est ce que j'ai dit
j'ai (déjà) répondu
```

### Comportement à l'activation
Les deux blocs d'activation (signal de dette direct, demande RDV) appellent maintenant `fillSlotsAndAdvance()` immédiatement — si l'utilisateur donne tout dans son premier message, le flux démarre directement à la bonne étape.

---

## 4. Amélioration du ton — `SystemPromptBuilder.php`

**Fichier :** `app/Support/PromptBuilding/SystemPromptBuilder.php`

### Section `## STYLE — RÈGLES DE TON` ajoutée

Injectée dans chaque état de qualification, cette section force GPT-4o à :

**EMPATHIE AU PREMIER CONTACT**
> GPT-4o regarde son propre historique. Si aucun message assistant n'a encore reconnu la difficulté → obligatoirement 1 phrase sincère en reprenant les mots exacts de l'utilisateur avant la question. Si empathie déjà exprimée → aller directement à la question.

**INTERDIT (anti-robotisme)**
- `"Quand [X]"` ou `"Lorsque [X]"` en ouverture — formule mécanique détectée
- Répéter un élément de situation déjà cité deux fois dans la conversation
- Utiliser deux fois le même mot de réconfort ("oppressant", "peser lourd") en messages consécutifs
- Faire un résumé complet avant chaque question

**REQUIS**
- Ancrer dans les mots exacts de l'utilisateur (pas de paraphrase générique)
- Varier le registre (direct / rassurant / neutre selon le contexte)

### États `awaiting_*` refactorisés

Chaque état de qualification a été réécrit :
- Label `"NE PAS redemander"` sur les slots déjà connus
- Appel à `buildFilledSlotsInstruction()` → clause `INTERDIT DE REDEMANDER: [résumé]`
- Instructions ACTION directes, non formulaiques
- Suppression du pattern "1 phrase empathique + question" (causait le "Quand X s'accumulent")

### `buildSlotSummary()` — support `debt_type` ajouté

| Code interne | Label français |
|---|---|
| `cartes` | cartes de crédit |
| `hypotheque` | hypothèque |
| `pret_auto` | prêt auto |
| `impots` | dettes fiscales (impôts) |
| `pret_personnel` | prêt personnel / marge de crédit |
| `commercial` | dettes commerciales |
| `mixte` | plusieurs types de dettes |

---

## Scénario de test type

**Message utilisateur :** `"j'ai 30 000$ de dettes et je n'arrive plus à payer"`

**Slots extraits automatiquement :**
- `amount_range` = `15k_50k` (depuis "30 000$")
- `payment_capacity` = `cannot_pay` (depuis "n'arrive plus à payer")

**Comportement attendu :**
1. Empathie obligatoire (premier contact qualificatif)
2. Une seule question : structure de la dette (single/multiple)
3. Questions urgency et amount_range **skippées** — déjà renseignées

---

## Fichiers modifiés

| Fichier | Type | Raison |
|---|---|---|
| `app/Core/Orchestration/SlotExtractor.php` | Nouveau | Extraction simultanée des slots |
| `app/Core/Orchestration/ConversationPolicyEngine.php` | Modifié | UTF-8 + slot-filling + isAlreadyAnsweredSignal |
| `app/Support/PromptBuilding/SystemPromptBuilder.php` | Modifié | Règles de ton + INTERDIT + buildFilledSlotsInstruction |
| `routes/console.php` | Modifié | Correction UTF-8 commentaires français |
