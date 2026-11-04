# Le serveur MCP dans les annuaires

`server.json` décrit le serveur MCP du bac à sable (`/mcp`, voir `platform/src/Mcp/PlaygroundTools.php`) pour le
[registre officiel MCP](https://registry.modelcontextprotocol.io), que d'autres annuaires (PulseMCP…) recopient.
La page de documentation publique est `/en/playground/ai` (`/bac-a-sable/ia` en français).

Publier, une fois le serveur en production (le registre ne vérifie pas l'URL, mais les annuaires qui le recopient
l'appellent) :

```bash
cd mcp
mcp-publisher login github   # le nom io.github.Oipnet/… exige le compte GitHub Oipnet
mcp-publisher publish
```

Une nouvelle version (outils changés, description, icône…) : augmenter `version`, puis `mcp-publisher publish`.

`icon.png` (256×256) est l'icône de la fiche, servie depuis GitHub (raw.githubusercontent.com, branche main) : la
fusionner sur main avant de publier.
