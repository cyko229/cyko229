# RemoteWake

Plateforme pour **allumer et verrouiller l'écran de votre propre téléphone Android à distance**, depuis un navigateur, où que vous soyez.

Elle se compose de trois parties :

| Dossier | Rôle |
| --- | --- |
| `server/` | Serveur relais Node.js (authentification, association des appareils, envoi des commandes, push FCM). |
| `web/` | Application web **installable (PWA)** : connexion, liste des appareils, boutons **Allumer l'écran** / **Verrouiller**, chronomètre de maintien de l'écran. |
| `android/` | Application Android (Kotlin) à installer sur le téléphone. |
| `docs/` | Étude des possibilités et limites d'Android — **à lire en premier** — et le guide de déploiement. |

> ℹ️ **Important — ce que ça fait et ne fait pas.** On allume *l'écran* d'un téléphone **allumé mais verrouillé / en veille**. On **ne peut pas** démarrer un appareil totalement éteint (aucune limite logicielle ne peut contourner ça), ni déverrouiller le code. Voir [`docs/android-capabilities.md`](docs/android-capabilities.md).

## Comment ça marche

```
Navigateur (web)  ──HTTPS──►  Serveur relais  ──WSS──►  App Android  ──►  allume/verrouille l'écran
     (bouton)                   (vous êtes          (socket             (WakeActivity /
                                 le seul             sécurisé            DevicePolicyManager)
                                 autorisé)           permanent)
```

Le téléphone garde une connexion sécurisée ouverte vers le serveur. Quand vous cliquez sur le bouton, le serveur pousse la commande au téléphone, qui allume l'écran — même verrouillé, même écran éteint.

Si le téléphone dort trop profondément pour que le socket réponde (mode **Doze**), le serveur bascule tout seul sur **Firebase Cloud Messaging** : un message *data* haute priorité passe là où le WebSocket est suspendu.

## Fonctionnalités

- **Allumer l'écran / verrouiller** à distance depuis n'importe quel navigateur.
- **Maintien de l'écran allumé** : après le déverrouillage du téléphone, l'écran reste allumé pendant la durée que vous réglez — un chronomètre dans l'application web, de 0 s (désactivé) à 60 min. L'application web affiche le compte à rebours réel pendant qu'il tourne.
- **PWA** : l'application web s'installe sur l'écran d'accueil (téléphone comme ordinateur), s'ouvre en plein écran et démarre instantanément même hors ligne.
- **FCM** : canal de secours automatique pour joindre un téléphone en veille profonde. Chaque commande poussée est **signée** avec le secret de l'appareil, limitée à la liste blanche `wake` / `lock`, horodatée et dédoublonnée — un push non signé n'est jamais exécuté.

## Démarrage rapide (développement local)

### 1. Serveur

```bash
cd server
npm install
cp .env.example .env         # puis éditez JWT_SECRET
npm start                    # écoute sur http://localhost:8080
```

L'application web est servie par le serveur : ouvrez **http://localhost:8080**.

### 2. Associer un téléphone

1. Dans l'app web : créez un compte, puis « Générer un code d'association ».
2. Dans l'app Android : saisissez l'adresse du serveur et le code à 6 chiffres, appuyez sur **Associer**.
3. Activez **le verrouillage à distance** (droit administrateur) et **ignorez l'optimisation de batterie** (deux boutons dans l'app) pour la fiabilité.

### 3. Utiliser

Dans l'app web, l'appareil apparaît « En ligne ». Cliquez **Allumer l'écran** ou **Verrouiller**.

Sous les boutons, le **chronomètre** règle la durée pendant laquelle l'écran restera allumé *après que vous aurez déverrouillé le téléphone*. Utilisez les boutons `−` / `+` (pas de 30 s) ou les raccourcis (30 s, 2 min, 5 min, 15 min, Désactivé), puis **Enregistrer la durée** : le réglage part immédiatement vers le téléphone. Quand la minuterie tourne, une barre de progression affiche le temps restant.

> Pour la méthode la plus douce (le téléphone se comporte normalement, on relève juste le délai de veille), appuyez une fois sur **« Autoriser le maintien de l'écran allumé »** dans l'app Android. Sans cette autorisation, un verrou d'écran prend le relais automatiquement — ça marche aussi, et un appui sur le bouton power y met fin à tout moment.

### 4. Installer l'application web (PWA)

Ouvrez l'application web dans Chrome / Edge / Safari puis :

- **Android / Chrome, ordinateur** : la carte « Installer l'application » apparaît sur le tableau de bord — cliquez **Installer**. (Ou menu ⋮ → *Installer l'application*.)
- **iOS / Safari** : bouton Partager → *Sur l'écran d'accueil*.

L'installation nécessite **HTTPS** (ou `localhost` en développement). Une fois installée, l'app s'ouvre en plein écran ; le *shell* est mis en cache, mais **jamais** les appels `/api/` : l'état des appareils et les commandes sont toujours demandés au serveur en direct.

### 5. Activer les notifications push (FCM) — facultatif mais recommandé

Sans FCM, tout fonctionne mais dépend de l'exemption de batterie. Avec FCM, un téléphone en veille profonde reste joignable.

1. Créez un projet Firebase gratuit, ajoutez-y une app Android de package `com.cyko.remotewake`, et déposez le `google-services.json` obtenu dans `android/app/`.
2. *Paramètres du projet → Comptes de service → Générer une nouvelle clé privée*, puis dans `server/.env` :
   ```bash
   FCM_SERVICE_ACCOUNT_FILE=./firebase-service-account.json
   ```
3. Relancez le serveur : il affiche `[fcm] push channel enabled`. Recompilez l'app, réassociez le téléphone (ou rouvrez simplement l'app) pour qu'il enregistre son jeton push.

L'appareil s'affiche alors « Veille — joignable par push » dans l'application web même quand le socket est fermé. Détails et modèle de sécurité : [`docs/android-capabilities.md`](docs/android-capabilities.md).

## Récupérer l'app Android

**Sans rien installer :** GitHub compile l'APK à chaque push. Onglet **Actions** → dernier run **« APK Android »** → section **Artifacts** → téléchargez `remotewake-debug-apk`. Décompressez, transférez le `.apk` sur le téléphone et installez-le (il faudra autoriser les « sources inconnues »).

Pour un lien stable, ouvrable directement depuis le téléphone, poussez un tag : l'APK est alors joint à la page **Releases**.

```bash
git tag v1.0 && git push origin v1.0
```

**Compiler vous-même :** ouvrez le dossier `android/` dans **Android Studio**, ou `cd android && ./gradlew assembleDebug`. Il faut un accès réseau à `google()` / `mavenCentral()` pour télécharger les dépendances. `minSdk = 27` (Android 8.1+).

Pour tester sans serveur public, lancez le serveur sur votre PC et utilisez son adresse réseau local (ex. `http://192.168.1.20:8080`) ; le téléphone doit être sur le même Wi-Fi.

## Tests

```bash
cd server
npm test            # store + signature des commandes (sans dépendances réseau)
npm run test:e2e    # scénario complet : il faut d'abord lancer `npm start` dans un autre terminal
```

Le test e2e simule un téléphone (WebSocket) et un client web : inscription → association → réglage de la durée de maintien → commande `wake` signée → accusé de réception, plus l'enregistrement du jeton push, les cas d'auth, de propriété, de durée hors bornes et de jeton invalide.

## Déploiement

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/cyko229/cyko229)

Tout est fourni : [`render.yaml`](render.yaml) pour un déploiement en un clic, [`Dockerfile`](Dockerfile) + [`docker-compose.yml`](docker-compose.yml) + [`Caddyfile`](Caddyfile) pour votre propre serveur (HTTPS automatique).

```bash
# Sur votre serveur, avec un domaine qui pointe dessus :
cp .env.example .env     # DOMAIN + JWT_SECRET
docker compose up -d
```

Procédure complète, sauvegardes et mises à jour : **[`docs/deploiement.md`](docs/deploiement.md)**.

> ⚠️ RemoteWake a besoin d'un **process Node permanent** (les téléphones gardent un WebSocket ouvert) et de **HTTPS**. Les hébergements serverless — Netlify Functions, Vercel, Cloudflare Workers — ne conviennent donc pas.

## Mise en production & sécurité

- **Servez tout en HTTPS/WSS** (TLS). Placez le serveur Node derrière un reverse-proxy (Caddy, Nginx) ou une plateforme qui termine le TLS. Sans TLS, le jeton de l'appareil circulerait en clair. Le `docker-compose.yml` fourni s'en charge via Caddy.
- Mettez un `JWT_SECRET` long et aléatoire, et `SECURE_COOKIES=true`.
- Pour une fiabilité maximale en veille profonde, configurez **Firebase Cloud Messaging** (§5 ci-dessus). Le WebSocket seul fonctionne sans configuration mais dépend de l'exemption de batterie.
- **L'installation de la PWA exige HTTPS.** Une fois le TLS en place, l'application web devient installable sans rien d'autre à faire.
- Ne versionnez jamais la clé de compte de service Firebase : `server/firebase-service-account.json` et `android/app/google-services.json` sont dans `.gitignore`.
- Le store JSON (`server/data/`) convient à un usage personnel ; passez à une vraie base pour plusieurs utilisateurs.

## Limites connues

- Appareil **éteint** → impossible à réveiller (limite matérielle).
- Surcouches constructeurs (MIUI, EMUI…) peuvent tuer le service de fond : réglez « démarrage auto » + exemption batterie.
- Le WebSocket seul peut se couper en Doze ; FCM lève cette limite.
- Le maintien de l'écran démarre **au déverrouillage**, pas à l'allumage : c'est délibéré (inutile de brûler de la batterie sur un écran de verrouillage). Si vous ne déverrouillez pas dans les 5 minutes, l'armement expire.
- Un appui sur le bouton power met fin au maintien de l'écran : l'utilisateur garde toujours le dernier mot.
