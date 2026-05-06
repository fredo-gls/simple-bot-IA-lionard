# Convention — Mises à jour admin Lionard

Chaque mise à jour est un sous-dossier nommé `YYYY-MM-DD/`.  
Il contient les fichiers à appliquer dans l'admin, sans toucher au code.

---

## Contenu d'un paquet de mise à jour

| Fichier | Description | Où l'appliquer dans l'admin |
|---|---|---|
| `kb-entries.json` | Nouvelles entrées de base de connaissance | Admin → Base de connaissance → Importer / Ajouter manuellement |
| `rules.json` | Nouvelles règles ou règles modifiées | Admin → Règles actives → Ajouter |
| `PROMPT-ADMIN.txt` | Prompt système complet (remplace l'ancien) | Admin → Paramètres du bot → Champ "Prompt système" |

Un fichier manquant = rien à changer pour cette section.

---

## Ordre d'application

1. Importer `kb-entries.json` → chaque entrée est un chunk de connaissance (title + content).
2. Appliquer `rules.json` → créer ou remplacer les règles concernées.
3. Copier-coller `PROMPT-ADMIN.txt` dans le champ "Prompt système" → sauvegarder.

---

## Après application

Renommer `PROMPT-ADMIN.txt` en `PROMPT-ADMIN.applied.txt` dans le dossier  
(ou noter la date dans un commentaire) pour savoir que le paquet a été déployé.

---

## Rappels importants

- Le prompt système remplace **entièrement** l'ancien — c'est toujours la version complète.
- Les règles dans `rules.json` sont cumulatives sauf indication contraire.
- Les entrées KB dans `kb-entries.json` sont **additives** — ne pas supprimer les entrées existantes.
- Le bot link `rdv` (OnceHub URL) doit être configuré dans Admin → Liens du bot → purpose = `rdv`.  
  Sans ce lien, le bouton RDV ne s'affiche pas, peu importe le code.
