# Dashboard Web – Cuves (Château Lamothe Despujols)

Ce dépôt contient la partie serveur / interface web pour le suivi des cuves :

- Hébergé sur Infomaniak, sous `/cuves/` (ex. `https://prod.lamothe-despujols.com/cuves/`)
- Réception des mesures des ESP32 via `api_cuve.php` (JSON HTTPS)
- Stockage dans `data_cuves.csv` puis génération de `cache_dashboard.json`
- Fichiers principaux :
  - `index.php` : dashboard graphique (cuves cylindriques, vague animée, Wi-Fi, etc.)
  - `api_cuve.php` : API d’entrée des données capteurs
  - `update_cache.php` : met à jour le cache JSON pour le dashboard
  - `get_config.php` / `save_config.php` : gestion de la config cuves (hauteurs, diamètres…)
  - `ota_check.php` : fournit la version et l’URL du firmware OTA

Le firmware ESP32 qui envoie les données vers ce dashboard est dans ce dépôt :  
?? https://github.com/ChtLam33/GITHUB-ESP32-LUNA
