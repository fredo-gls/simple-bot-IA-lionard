# Système de score — Qualification des leads

## Vue d'ensemble

Le score est un entier calculé au fil de la conversation. Il mesure l'urgence et la gravité
de la situation du visiteur. Il est stocké dans `conversation.context.qualification_flow.score`.

Le score **n'est pas** le seul critère de routing — voir section Routing ci-dessous.

---

## Score par slot

Chaque slot rempli ajoute des points au score. Un slot n'est comptabilisé qu'une seule fois
(la première valeur détectée, jamais écrasée).

### `debt_structure` — Structure de la dette

| Valeur | Points | Signification |
|---|---|---|
| `multiple` | 20 | Plusieurs types de dettes différents |
| `single` | 10 | Un seul type de dette |
| autre | 5 | Valeur par défaut / non détectée clairement |

### `urgency` — Niveau d'urgence

| Valeur | Points | Signification |
|---|---|---|
| `high` | 35 | Action légale en cours (huissier, saisie, expulsion, compte gelé, mise en demeure) |
| `medium` | 20 | Pression active de créanciers (appels, recouvrement, menaces) ou perte d'emploi |
| `low` | 5 | Pas de pression externe pour le moment |

### `payment_capacity` — Capacité de paiement

| Valeur | Points | Signification |
|---|---|---|
| `cannot_pay` | 30 | Ne peut plus payer du tout |
| `partial` | 18 | Paie encore mais difficilement (serré, à peine, à bout) |
| `normal` | 5 | Gère encore ses paiements normalement |

### `amount_range` — Montant approximatif des dettes

| Valeur | Points | Signification |
|---|---|---|
| `50k_plus` | 25 | Plus de 50 000 $ |
| `15k_50k` | 18 | Entre 15 000 $ et 50 000 $ |
| `5k_15k` | 10 | Entre 5 000 $ et 15 000 $ |
| `<5k` | 5 | Moins de 5 000 $ (ou non détecté) |

---

## Bonus de combinaison

Appliqués une seule fois à la fin de la qualification (`completed_summary`),
quand tous les slots sont remplis. Corrigent des combinaisons sous-évaluées.

| Combinaison | Bonus | Justification |
|---|---|---|
| `urgency=medium` + `payment_capacity=partial` | +10 | Créanciers qui appellent ET paie difficilement = pré-crise réelle |
| `debt_structure=multiple` + (`partial` ou `cannot_pay`) | +5 | Plusieurs dettes + difficulté = pression cumulée |

Les deux bonus ne se cumulent pas (seul le premier applicable est retenu).

---

## Cas spéciaux

### Lead chaud détecté en cours de conversation

Si le visiteur exprime une crise aiguë **avant** la fin de la qualification
(impossibilité totale de payer, perte de revenu soudaine, détresse forte),
le flow saute directement à `completed_summary` avec :

```
score = max(score_actuel + 30, 75)
```

Garantit un score minimum de 75 pour tout lead chaud, indépendamment des slots remplis.

### Refus ou ambiguïté d'un slot

- Refus explicite → le slot est ignoré, le flow avance quand même
- Réponse ambiguë → `SlotExtractor` ne remplit pas le slot → question posée une fois de plus

---

## Scores représentatifs

| Profil | Slots | Score brut | Bonus | Score final | Routing |
|---|---|---|---|---|---|
| Crise aiguë | high + cannot_pay + multiple + 50k+ | 35+30+20+25 = 110 | — | 110 | RDV |
| Urgence réelle | medium + partial + multiple + 15k-50k | 20+18+20+18 = 76 | — | 76 | RDV |
| Pré-crise (cas corrigé) | medium + partial + multiple + 5k-15k | 20+18+20+10 = 68 | — | 68 | RDV |
| Cas limite corrigé bonus | medium + partial + single + 5k-15k | 20+18+10+10 = 58 | +10 | 68 | RDV |
| Gestion difficile | low + partial + single + 5k-15k | 5+18+10+10 = 43 | — | 43 | NovaPlan |
| Prévention | low + normal + single + 5k-15k | 5+5+10+10 = 30 | — | 30 | NovaPlan |
| Minimum absolu | low + normal + single + <5k | 5+5+5+5 = 20 | — | 20 | NovaPlan |

---

## Routing final

Le routing **n'est pas basé sur le score**. Il est décidé par `isNovaPlanProfile()`
qui examine les slots directement :

```
payment_capacity = normal                      → NovaPlan
payment_capacity = partial ET urgency = low    → NovaPlan
payment_capacity = partial ET urgency = null   → NovaPlan
tout autre cas                                 → RDV
```

### Cas → RDV

- `cannot_pay` (peu importe l'urgence)
- `partial` + `urgency=medium` (créanciers qui appellent + paie difficilement)
- `partial` + `urgency=high` (action légale)
- `urgency=high` (peu importe la capacité)

### Cas → NovaPlan

- `normal` (gère encore ses paiements)
- `partial` + pas de pression active de créanciers

### Note sur le seuil configurable

Le panneau admin **Parcours & Intentions** affiche un champ "Seuil score lead qualifié (60)".
Ce champ est sauvegardé en base mais **n'est pas utilisé** par le moteur de routing actuel.
Le routing réel passe par `isNovaPlanProfile()`, pas par ce seuil.

Si on veut aligner le routing sur le score plutôt que sur les slots, c'est un changement
dans `ConversationPolicyEngine::isNovaPlanProfile()` — à décider.

---

## Fichiers concernés

| Fichier | Rôle |
|---|---|
| `app/Core/Orchestration/ConversationPolicyEngine.php` | `scoreForSlot()`, `applyScoreBonuses()`, `isNovaPlanProfile()`, `advanceToNextMissingSlot()` |
| `app/Core/Orchestration/SlotExtractor.php` | Détection des valeurs de slots depuis le texte |
| `app/Core/Orchestration/ContextBuilder.php` | Injection du flow dans le prompt système |
| `app/Support/PromptBuilding/SystemPromptBuilder.php` | Questions posées par état (`awaiting_*`) |
