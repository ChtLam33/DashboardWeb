# Dashboard Web – Cuves (Château Lamothe Despujols)


# Dashboard Web – Cuves & Barriques  
Château Lamothe Despujols

Ce dépôt contient **l’interface web complète** permettant de visualiser et gérer les données des capteurs de cuves (TF-Luna) et des capteurs de barriques (ESP32-C3, capteur de creux).  
Le serveur est hébergé chez **Infomaniak** et reçoit les mesures JSON envoyées par les capteurs IoT.

---

# 🌐 Structure générale

Le Dashboard Web se compose de **trois modules principaux** :

## 1️⃣ Page parent (index)
Chemin : `/index.php`

- Présente les accès aux différents modules :
  - **Dashboard Cuves**
  - **Dashboard Barriques**
  - (Futurs modules possibles : fermentation, météo, stock…)
- Page légère servant de hub général.

---

## 2️⃣ Dashboard Cuves  

- Hébergé sur Infomaniak, sous `/cuves/` (ex. `https://prod.lamothe-despujols.com/cuves/`)
- Réception des mesures des ESP32 via `api_cuve.php` (JSON HTTPS)
- Stockage dans `data_cuves.csv` puis génération de `cache_dashboard.json`
- Fichiers principaux :
  - `index.php` : dashboard graphique (cuves cylindriques, vague animée, Wi-Fi, etc.)
  - `api_cuve.php` : API d’entrée des données capteurs
  - `update_cache.php` : met à jour le cache JSON pour le dashboard
  - `get_config.php` / `save_config.php` : gestion de la config cuves (hauteurs, diamètres…)
  - `ota_check.php` : fournit la version et l’URL du firmware OTA
  - 
Fonctionnalités principales :

- Lecture de distance **TF-Luna** (capteur dans les cuves inox)
- Calcul automatique :
  - niveau
  - volume réel (L)
  - pourcentage de remplissage
- Enregistrement des mesures dans `un .csv`
- Visualisation instantanée des cuves, couleurs dynamiques
- Historique détaillé par cuve 
- Paramétrage automatisé envoyé aux capteurs :
  - période de mesure  
  - seuils couleur  
  - calibration cuve (non)
- Gestion OTA centralisée pour les capteurs cuves  
  (fichier `ota_check.php` + hébergement `firmware.bin`)

API associées :
- `api_cuve.php` — réception des JSON des capteurs  
- `get_config.php` — renvoi de la configuration par cuve  
- `ota_check.php` — vérification de mise à jour OTA  

---

## 3️⃣ Dashboard Barriques  
Dossier : `/barriques/`

Module avancé pour la gestion de l’élevage en barriques.

Fonctionnalités :

### 📌 Vue par capteur
- Dernière mesure brute (ADC)
- Couleur du niveau (vert / jaune / orange / rouge…)
- Creux estimé en **cm** et en **litres**
- RSSI, batterie, version firmware
- Attribution du **lot** (ex : L24, SE16…)
- Accès à l’**historique du capteur** (`history_capteur.php`)

### 📌 Vue par lot
- Regroupe automatiquement plusieurs capteurs d’un même lot
- Calcule :
  - nombre de barriques
  - volume total du lot
  - équivalent bouteilles
  - **ouillage estimé**
  - **part des anges cumulée**
- Graphique historique du lot (`history_lot.php`)
  - avec bande min/max
  - basé sur `lot_history.json`
  - gestion propre des anciens lots (archivage automatique)

### 📌 Historique des anciens lots
- Archivage intelligent basé sur les périodes réellement mesurées
- Affichage repliable
- Accès direct au graphique du lot archivés

API associées :
- `api_post.php` — réception des mesures barriques
- `get_config.php` — renvoi des paramètres capteurs (lot, intervalle, maintenance…)
- `ota_check.php` — OTA barriques  
- `lot_history.json` — suivi dans le temps de chaque capteur

---

# 🔔 Notifications Web Push

Le dashboard offre un système complet de **notifications** :

- batterie faible  
- capteur inactif  
- rappel hebdomadaire ou quotidien  
- seuil paramétrable :  
  *mesure attendue + marge d’inactivité*

Basé sur :
- `sw.js` (service worker)
- `push_subscribe.php`
- `send_push.php`

---

# 🛠 Structure du serveur (a peu près !)

```
DashboardWeb/
│
├── index.php
│
├── cuves/
│   ├── api_cuve.php
│   ├── get_config.php
│   ├── ota_check.php
│   ├── logs/cuves.log
│   └── *.php (dashboard + graph)
│
└── barriques/
    ├── api_post.php
    ├── get_config.php
    ├── ota_check.php
    ├── lot_history.json
    ├── logs/barriques.log
    └── *.php (dashboard + graph + notifications)
```

---

# ⚙️ Capteurs associés

- **Cuves** : ESP32 + TF-Luna (UART), firmware dans dépôt séparé  
  ➜ https://github.com/ChtLam33/GITHUB-ESP32-LUNA  

- **Barriques** : ESP32-C3 + capteur analogique creux  
  ➜ https://github.com/ChtLam33/GITHUB-ESP32-BARRIQUES  

---

# 📦 Hébergement & sécurité

- HTTPS strict  
- Pas de certificats côté ESP (utilisation de `client.setInsecure()`)
- Logs horodatés ISO8601  
- Aucun framework : PHP natif optimisé pour rapidité et légèreté  
- Compatible smartphone 100%

---

# 🚀 Roadmap

- Ajout des courbes **température** pour barriques  
- Envoi des mesures longue période (1/semaine)  
- Interface mobile améliorée  
- Export Excel des lots / capteurs  
- Interface calibration creux/litres côté web  

---

Château Lamothe Despujols  
*Innovation & précision au service du Sauternes*

