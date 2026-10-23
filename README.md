# RemoteWake

Plateforme pour **allumer et verrouiller l'écran de votre propre téléphone Android à distance**, depuis un navigateur, où que vous soyez.

Elle se compose de trois parties :

| Dossier | Rôle |
| --- | --- |
| `server/` | Serveur relais Node.js (authentification, association des appareils, envoi des commandes). |
| `web/` | Application web (connexion, liste des appareils, boutons **Allumer l'écran** / **Verrouiller**). |
| `android/` | Application Android (Kotlin) à installer sur le téléphone. |
| `docs/` | Étude des possibilités et limites d'Android — **à lire en premier**. |

> ℹ️ **Important — ce que ça fait et ne fait pas.** On allume *l'écran* d'un téléphone **allumé mais verrouillé / en veille**. On **ne peut pas** démarrer un appareil totalement éteint (aucune limite logicielle ne peut contourner ça), ni déverrouiller le code. Voir [`docs/android-capabilities.md`](docs/android-capabilities.md).

## Comment ça marche

```
Navigateur (web)  ──HTTPS──►  Serveur relais  ──WSS──►  App Android  ──►  allume/verrouille l'écran
     (bouton)                   (vous êtes          (socket             (WakeActivity /
                                 le seul             sécurisé            DevicePolicyManager)
                                 autorisé)           permanent)
```

Le téléphone garde une connexion sécurisée ouverte vers le serveur. Quand vous cliquez sur le bouton, le serveur pousse la commande au téléphone, qui allume l'écran — même verrouillé, même écran éteint.

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

## Compiler l'app Android

Ouvrez le dossier `android/` dans **Android Studio** (ou `./gradlew assembleDebug`). Il faut un accès réseau à `google()` / `mavenCentral()` pour télécharger les dépendances — ce que le bac à sable de développement de ce dépôt bloque, d'où une compilation à faire sur votre machine. `minSdk = 27` (Android 8.1+).

Pour tester sans serveur public, lancez le serveur sur votre PC et utilisez son adresse réseau local (ex. `http://192.168.1.20:8080`) ; le téléphone doit être sur le même Wi-Fi.

## Tests

```bash
cd server
npm test            # teste le store (sans dépendances réseau)
npm run test:e2e    # scénario complet : il faut d'abord lancer `npm start` dans un autre terminal
```

Le test e2e simule un téléphone (WebSocket) et un client web : inscription → association → commande `wake` → accusé de réception, plus les cas d'auth, de propriété et de jeton invalide.

## Mise en production & sécurité

- **Servez tout en HTTPS/WSS** (TLS). Placez le serveur Node derrière un reverse-proxy (Caddy, Nginx) ou une plateforme qui termine le TLS. Sans TLS, le jeton de l'appareil circulerait en clair.
- Mettez un `JWT_SECRET` long et aléatoire, et `SECURE_COOKIES=true`.
- Pour une fiabilité maximale en veille profonde, ajoutez **Firebase Cloud Messaging** comme canal principal (procédure dans `docs/android-capabilities.md`). Le WebSocket actuel fonctionne sans configuration mais dépend de l'exemption de batterie.
- Le store JSON (`server/data/`) convient à un usage personnel ; passez à une vraie base pour plusieurs utilisateurs.

## Limites connues

- Appareil **éteint** → impossible à réveiller (limite matérielle).
- Surcouches constructeurs (MIUI, EMUI…) peuvent tuer le service de fond : réglez « démarrage auto » + exemption batterie.
- Le WebSocket seul peut se couper en Doze ; FCM lève cette limite.
