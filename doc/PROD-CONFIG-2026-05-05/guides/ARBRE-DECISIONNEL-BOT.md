# Arbre décisionnel — Bot Lionard

## Logique de routing : NovaPlan vs RDV vs Ressources

---

## Slots clés pour la décision

| Slot | Valeurs | Source |
|---|---|---|
| `payment_capacity` | `normal` · `partial` · `cannot_pay` | SlotExtractor |
| `urgency` | `high` · `medium` · `low` | SlotExtractor |
| `amount_range` | `5k_15k` · `15k_50k` · `50k_plus` | SlotExtractor |
| `debt_structure` | `single` · `multiple` | SlotExtractor |

---

## Arbre de décision principal

```
Dette existante confirmée ?
│
├── NON → Info générale / FAQ (non_qualified)
│
└── OUI
    │
    ├── urgency = HIGH (huissier / saisie / expulsion / compte gelé)
    │   └── 🔴 RDV IMMÉDIAT
    │       - 0 question supplémentaire
    │       - Bouton RDV direct + 1 phrase de rassurance
    │
    ├── urgency = MEDIUM (recouvrement / mise en demeure / appels créanciers)
    │   ├── payment_capacity = cannot_pay → 🔴 RDV URGENT
    │   ├── payment_capacity = partial    → 🔴 RDV recommandé
    │   └── payment_capacity = normal     → 🟡 NovaPlan + info RDV doux
    │       (rare : urgence détectée mais paie encore)
    │
    ├── urgency = LOW / inconnue
    │   ├── payment_capacity = cannot_pay → 🔴 RDV
    │   │   (paradoxe : ne paye plus mais pas urgent → situation plus grave
    │   │    que l'urgence détectée — traiter comme urgence)
    │   │
    │   ├── payment_capacity = partial    → 🟡 NovaPlan + soft RDV
    │   │   ("si ça continue..." / "le mois prochain il y a un risque")
    │   │
    │   └── payment_capacity = normal     → 🟢 NovaPlan
    │       ("je m'en sors encore" / "j'arrive encore à payer")
    │
    └── payment_capacity = inconnue
        → Continuer qualification (poser la question)
```

---

## Seuils de score et effet sur le routing

| Score | payment_capacity = normal | payment_capacity = partial | payment_capacity = cannot_pay |
|---|---|---|---|
| ≥ 60 | 🟢 NovaPlan | 🟡 NovaPlan ou 🔴 RDV (selon urgence) | 🔴 RDV |
| < 60 | 🟢 NovaPlan | 🟡 NovaPlan | 🔴 RDV |

> **Règle absolue** : `payment_capacity = normal` → NovaPlan, même si le score est élevé.
> Un utilisateur avec 50 000 $ de dettes qui paie encore normalement = profil prévention, pas syndic.

---

## Profils détaillés

### 🟢 Profil Prévention → NovaPlan

**Signaux détectés :**
- "je m'en sors encore"
- "j'arrive encore à payer"
- "je gère encore"
- "ça va pour le moment"
- "pas encore de problème"
- "plusieurs cartes mais je paie encore"

**Réponse bot :**
1. Valider positivement qu'il anticipe
2. Présenter NovaPlan comme outil de visualisation gratuit
3. Bouton `[[button:Créer mon plan de remboursement|URL]]`
4. Laisser la porte ouverte au RDV si ça évolue

**NE PAS faire :** proposer un syndic / RDV — prématuré, peut effrayer.

---

### 🟡 Profil Pré-crise → NovaPlan + Soft RDV

**Signaux détectés :**
- "si je n'arrive pas à payer le mois prochain"
- "ça commence à être difficile à tenir"
- "bientôt je ne pourrai plus"
- payment_capacity = partial + urgency = low/inconnue

**Réponse bot :**
1. Reconnaître la pression croissante
2. NovaPlan pour clarifier la situation maintenant
3. Mentionner qu'une conseillère est disponible si ça s'aggrave
4. Bouton NovaPlan (bouton RDV doux optionnel)

---

### 🔴 Profil Intervention → RDV

**Signaux détectés :**
- cannot_pay : "je ne peux plus payer", "n'arrive plus à payer", "sans revenu", "chômage"
- urgency high : huissier, saisie, expulsion, compte gelé, jugement
- urgency medium + cannot_pay ou partial

**Réponse bot :**
1. Reconnaître l'urgence/la difficulté sans dramatiser
2. Résumé de situation (1 phrase)
3. Valeur de la consultation : clarifier les options, éviter les mauvaises décisions, confidentiel, sans engagement
4. Bouton `[[button:Prendre rendez-vous|URL]]`

**Pour urgence haute :** 0 question — bouton immédiat + rassurance.

---

## Patterns de détection (SlotExtractor)

### payment_capacity = normal
```
je paie / je paye / j'arrive à payer / paiements normaux
je m'en sors (encore / bien / quand même)
j'arrive encore à / je gère encore / je fais face / parviens encore
ça va encore pour (le moment / l'instant)
je peux encore payer / pas de problème de paiement
encore en contrôle
```

### payment_capacity = partial (pré-crise)
```
si je n'arrive pas à payer (bientôt / le mois prochain)
risque de ne plus [payer]
bientôt (plus payer / incapable)
difficile à tenir / dur à tenir
commence à être difficile
```

### payment_capacity = cannot_pay
```
ne peux plus / impossible de payer / incapable
n'arrive plus à [payer / suivre / gérer]
sans revenu / chômage / mise à pied / licencié
perdu (emploi / travail / job)
"non" / "pas du tout" / "aucun paiement" (réponse courte isolée)
```

### urgency = high
```
huissier / saisie / expulsion / compte gelé / jugement
mise en demeure / avis de défaut
perdre (son) logement
```

### urgency = medium
```
recouvrement / retard / appels de créancier
menaces / réclamation
sans emploi / perdu l'emploi
```

---

## Configuration requise

Dans `.env` :
```env
CHATBOT_QUALIFICATION_RDV_URL=https://www.dettes.ca/clinique-liberte
CHATBOT_NOVAPLAN_URL=https://app.novaplan.ca/add-debts
```

Ou via l'admin du bot — liens configurés avec les purposes :
- `rdv` → bouton RDV
- `novaplan` ou `planif` → bouton NovaPlan

---

## Impact sur la conversation de test (2026-05-01)

| Message | Slot détecté | État avant fix | État après fix |
|---|---|---|---|
| "j'ai plusieurs cartes de crédit mais je m'en sors encore" | debt_type=cartes, debt_structure=multiple, **payment_capacity=normal** ✅ | non détecté → question répétée | détecté → skip question capacité |
| "si je n'arrive pas à payer le mois prochain" | **payment_capacity=partial** ✅ | non détecté | détecté → profil pré-crise |
| "comment parler à une conseillère ?" | has_rdv_request=true | bot hésitait à donner le lien | → NovaPlan bouton (profil normal) |
