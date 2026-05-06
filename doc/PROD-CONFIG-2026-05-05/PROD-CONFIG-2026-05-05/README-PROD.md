# Pack configuration prod - Lionard (2026-05-05)

## Contenu
- wp-upload/conversation-model.json : fichier a coller dans WP Admin > Chatbot Lionard > Moteur > Modele JSON.
- prompt/prompt-systeme-final.txt : prompt a coller dans WP Admin > Base de connaissance > Prompt systeme.
- regles/*.json : composants de regles (taxonomie, intents, flows, policy).
- connaissance/*.md : base de travail metier (aliases/fautes + dataset).
- tests/test-scenarios.json : cas de non-regression.

## Procedure prod
1. Coller prompt/prompt-systeme-final.txt dans le prompt admin et enregistrer.
2. Coller wp-upload/conversation-model.json dans la page Moteur.
3. Definir la version (ex: 2026-05-05) puis cliquer Publier vers backend.
4. Verifier via GET /api/bot/conversation-model que data.version et data.model sont presents.
