# Dataset intents + slots (source: resume conseillers)

## Format
- `input_user`: formulation utilisateur plausible
- `intent_attendu`
- `slots_attendus`
- `question_suivante`

## Cas 1 - Marge de credit (Mikael)
- input_user: "J'ai surtout une marge BNC d'environ 13 000 et je veux voir mes options."
- intent_attendu: `intention_dette_generale`
- slots_attendus:
  - `debt_type`: `marge_credit`
  - `debt_structure`: `single`
  - `has_debt_confirmed`: `true`
  - `amount_range`: `10k_15k`
- question_suivante: "Est-ce que vous arrivez a faire plus que le paiement minimum ?"

## Cas 2 - Impots Revenu Quebec (Martin)
- input_user: "Je dois 28 000 a Revenu Quebec et je paie 600 par mois."
- intent_attendu: `intention_dette_impot`
- slots_attendus:
  - `debt_type`: `impots`
  - `has_debt_confirmed`: `true`
  - `amount_range`: `15k_50k`
  - `payment_capacity`: `partial`
- question_suivante: "Avez-vous recu des avis ou des relances officielles ?"

## Cas 3 - Multiples dettes + recouvrement (Josee)
- input_user: "J'ai plusieurs dettes, des retards et des agences de recouvrement qui me contactent."
- intent_attendu: `intention_recouvrement`
- slots_attendus:
  - `debt_type`: `multiples_dettes`
  - `debt_structure`: `multiple`
  - `urgency`: `high`
  - `has_debt_confirmed`: `true`
- question_suivante: "Recevez-vous des appels de creanciers regulierement ?"

## Cas 4 - Pret etudiant
- input_user: "J'ai encore un pret etudiant et je ne sais pas si je peux m'en sortir."
- intent_attendu: `intention_pret_etudiant`
- slots_attendus:
  - `debt_type`: `pret_etudiant`
  - `has_debt_confirmed`: `true`
- question_suivante: "Depuis combien de temps avez-vous termine vos etudes ?"

## Cas 5 - Dette entreprise
- input_user: "Ma compagnie accumule des dettes fournisseurs et taxes, je suis encore actif."
- intent_attendu: `intention_entreprise`
- slots_attendus:
  - `debt_type`: `dettes_entreprise`
  - `segment`: `qualified_business`
  - `has_debt_confirmed`: `true`
- question_suivante: "Votre entreprise est-elle toujours active ?"

## Cas 6 - Hors contexte
- input_user: "J'ai faim je cherche un resto"
- intent_attendu: `intention_hors_contexte`
- slots_attendus:
  - `debt_type`: `inconnu`
- question_suivante: "Est-ce qu'il y a une situation financiere a regarder ?"

## Cas 7 - Frustration
- input_user: "Tu me fatigues, tu repetes toujours la meme question"
- intent_attendu: `intention_frustration`
- slots_attendus:
  - `frustration_detected`: `true`
- question_suivante: "Si c'est plus simple, je peux vous mettre en lien direct avec une conseillere humaine."

## Cas 8 - RDV explicite
- input_user: "Oui je veux un rendez-vous"
- intent_attendu: `intention_rdv`
- slots_attendus:
  - `wants_rdv`: `true`
- question_suivante: "Retourner directement le formulaire/bouton RDV"
