# Aliases + fautes a integrer

## Objectif
Enrichir `config/conversation_model.php` avec des variantes observees en vrai.

## impots
- aliases:
  - revenu quebec
  - revenu canada
  - impots non declares
  - taxes dues
  - retenues d'impot
- fautes frequentes:
  - impotss
  - inpot
  - revenu quebeq
  - revenu cananda

## recouvrement
- aliases:
  - agence de recouvrement
  - ils m'appellent
  - appels creanciers
  - lettre de recouvrement
- fautes frequentes:
  - recouvremnt
  - recouvreman
  - creancie

## marge_credit
- aliases:
  - marge bnc
  - ligne de credit
  - line of credit
- fautes frequentes:
  - marje
  - marge credi
  - ligne credit

## pret_etudiant
- aliases:
  - dette etudiante
  - pret etude
  - aide financiere etudes
- fautes frequentes:
  - pret etudient
  - pret etudian
  - etudient

## dettes_entreprise
- aliases:
  - dettes fournisseurs
  - taxes DAS
  - dette compagnie
  - dette societe
- fautes frequentes:
  - entreprize
  - fourniseur
  - compagni

## multiples_dettes
- aliases:
  - plusieurs dettes
  - cartes + impots
  - plein de dettes
  - un peu partout
- fautes frequentes:
  - plusieur dettes
  - mulitples dettes

## hors_contexte / blague (intent)
- variantes:
  - j'ai faim
  - j'ai la dalle
  - je cherche un resto
  - c'est quoi la meteo
  - raconte une blague

## frustration (intent)
- variantes:
  - tu me fatigues
  - j'en ai marre
  - tu repetes
  - ca sert a rien
  - tu comprends rien

## Notes integration
- Garder `inconnu` comme fallback explicite.
- Normaliser accents + apostrophes avant matching.
- Ajouter ces variantes dans tests de non-regression.
