# Allumer l'écran d'un Android à distance — possibilités et limites

Ce document répond au point technique central du projet : **que permet réellement Android, et où sont les limites ?**

## 1. Ce qui est possible vs impossible

| Souhait | Possible ? | Pourquoi |
| --- | --- | --- |
| Allumer **l'écran** d'un téléphone allumé mais verrouillé / écran éteint | ✅ Oui | Une app peut lancer une activité marquée `setTurnScreenOn(true)` ; le système allume alors l'affichage. |
| Verrouiller le téléphone à distance | ✅ Oui | API *Device Admin* → `DevicePolicyManager.lockNow()`. |
| **Démarrer** un téléphone totalement éteint (ou batterie retirée) | ❌ Non | Aucun logiciel ne tourne quand l'appareil est éteint. Rien ne peut recevoir une commande. Il n'existe pas de « Wake-on-LAN » pour l'écran d'un téléphone. C'est une limite matérielle/OS absolue. |
| Déverrouiller le téléphone à distance (passer le code PIN) | ❌ Non (volontairement) | Android ne laisse aucune app contourner l'écran de verrouillage. On allume l'écran ; l'utilisateur déverrouille normalement. |
| **Maintenir l'écran allumé** après le déverrouillage, pendant une durée choisie | ✅ Oui | Soit en relevant temporairement le délai de veille du système (`SCREEN_OFF_TIMEOUT`), soit avec un verrou d'écran. Voir §3bis. |

> **Conclusion :** votre demande — « uniquement allumer l'écran, pas démarrer l'appareil éteint » — est exactement ce qui est faisable. Le projet s'y tient.

## 2. Comment on allume l'écran (le cœur technique)

Un service en arrière-plan ne peut pas allumer l'écran directement. La méthode fiable et officielle :

```kotlin
// WakeActivity.kt — activité transparente, sans UI
if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) { // API 27+
    setShowWhenLocked(true)   // s'affiche par-dessus l'écran de verrouillage
    setTurnScreenOn(true)     // demande au système d'allumer l'affichage
}
```

Quand une commande `wake` arrive, le service lance cette activité invisible. Le simple fait de l'afficher allume l'écran — **même verrouillé, même écran éteint** — puis l'activité se referme. On **n'enlève pas** le verrouillage : l'utilisateur déverrouille comme d'habitude.

L'ancienne approche `PowerManager.FULL_WAKE_LOCK` est **dépréciée** depuis Android 7 et ne doit pas être utilisée.

## 3. Verrouiller à distance

```kotlin
val dpm = getSystemService(DEVICE_POLICY_SERVICE) as DevicePolicyManager
dpm.lockNow()   // nécessite que l'app soit "administrateur de l'appareil"
```

L'utilisateur active ce droit une fois, via un écran système explicite (bouton « Activer le verrouillage à distance » dans l'app). On ne demande **qu'une** politique : `force-lock`. Aucune donnée n'est effacée.

## 3bis. Maintenir l'écran allumé après le déverrouillage

La durée est **réglée depuis l'application web** (chronomètre, 0 s – 60 min) ; le serveur la pousse au téléphone (message `config` sur le WebSocket, ou champ `keepAwakeSeconds` dans la commande `wake`).

**Pourquoi attendre le déverrouillage ?** Juste après un `wake`, l'écran affiche le verrouillage. Maintenir l'affichage allumé à ce moment-là ne ferait que consommer de la batterie sur un écran inutile. On *arme* donc la minuterie à la réception de la commande, et on ne l'*engage* qu'au signal `ACTION_USER_PRESENT` — la diffusion système émise exactement au moment où l'utilisateur déverrouille. Si l'utilisateur ne déverrouille pas dans les 5 minutes, l'armement expire tout seul.

Deux mécanismes, par ordre de préférence (`KeepAwakeManager.kt`) :

| Mécanisme | Permission | Comportement |
| --- | --- | --- |
| Relever `Settings.System.SCREEN_OFF_TIMEOUT`, puis le restaurer | `WRITE_SETTINGS` (accordée par l'utilisateur, bouton dans l'app) | Le plus propre : le téléphone se comporte normalement, API non dépréciée. La valeur d'origine est enregistrée sur disque et restaurée même après un redémarrage du processus. |
| `SCREEN_BRIGHT_WAKE_LOCK` avec expiration | `WAKE_LOCK` (déjà accordée à l'installation) | Repli sans configuration. Déprécié mais fonctionnel partout. Le système relâche le verrou si l'utilisateur appuie sur le bouton power — **l'utilisateur garde toujours le dernier mot**. |

Le téléphone renvoie au serveur l'instant de fin (`keepAwakeUntil`), ce qui permet au compte à rebours affiché dans l'application web d'être réel et non une estimation.

## 4. Le vrai défi : *recevoir* la commande quand l'écran est éteint

Allumer l'écran est simple **une fois la commande reçue**. La difficulté est que le téléphone, écran éteint depuis un moment, entre en **Doze** (veille profonde) : Android suspend le réseau et les tâches de fond pour économiser la batterie. Deux canaux pour contourner cela :

**Les deux canaux sont implémentés.** Le WebSocket est utilisé quand il est vivant (latence minimale) ; sinon le serveur bascule automatiquement sur FCM, et le téléphone accuse réception en HTTP (`POST /api/device/ack`).

### Option A — WebSocket + service au premier plan (zéro configuration)
- Un *foreground service* maintient une connexion WebSocket ouverte vers le serveur.
- Fonctionne immédiatement, sans compte tiers.
- **Limite :** en Doze profond, le réseau peut être suspendu et la connexion coupée jusqu'à la prochaine fenêtre de maintenance. Pour un usage fiable il faut **exempter l'app de l'optimisation de batterie** (bouton prévu dans l'app). Consomme un peu plus de batterie.

### Option B — Firebase Cloud Messaging (FCM) haute priorité (recommandé en production)
- Les messages FCM `priority: high` **réveillent l'app même en Doze** — c'est le mécanisme conçu par Google pour exactement ce cas.
- Plus fiable et plus économe que de garder un socket ouvert.
- On envoie des **messages *data*** (et non des notifications) : ils sont remis à `onMessageReceived` même app en arrière-plan, ce qui permet d'*agir* au lieu de simplement afficher une bulle. TTL de 60 s : un réveil en retard n'a aucun intérêt.
- **Coût :** nécessite un projet Firebase gratuit et le fichier `google-services.json`.

> Stratégie retenue : **FCM comme canal de secours automatique**, le WebSocket comme chemin rapide quand le téléphone est éveillé. Sans identifiants Firebase, le serveur journalise `[fcm] push channel disabled` et tout continue de fonctionner via le WebSocket seul.

#### Commandes autorisées : ce qu'on accepte d'un push

Un push transite par les serveurs de Google, donc l'app ne l'exécute **que** s'il est signé (`CommandHandler.kt`) :

1. L'action doit faire partie de la liste blanche (`wake`, `lock`) — toute autre valeur est rejetée et journalisée.
2. Le message porte un HMAC-SHA256 calculé par le serveur avec **le jeton de l'appareil** — le secret que seuls ce téléphone et le serveur connaissent. Une clé Firebase volée ne suffit donc pas à piloter le téléphone.
3. Le message doit dater de moins de 2 minutes (anti-rejeu).
4. Les `commandId` déjà exécutés sont mémorisés : FCM peut livrer deux fois, on n'agit qu'une.

#### Lancer une activité depuis un push

Depuis Android 10, une app en arrière-plan ne peut pas toujours lancer une activité, et le refus est **silencieux**. `ScreenController` vérifie donc, 1,2 s après la demande, que `WakeActivity` est bien apparue ; sinon il poste une notification avec un **`setFullScreenIntent`**, le chemin officiel pour afficher une activité par-dessus l'écran de verrouillage. Sur Android 14+ ce droit se demande à l'utilisateur (`USE_FULL_SCREEN_INTENT`, bouton dans l'app).

## 5. Contraintes selon la version d'Android

- **API 27+ (Android 8.1)** requis pour `setTurnScreenOn` — d'où `minSdk = 27`.
- **Android 12+** : la précision des alarmes et le lancement d'activités depuis le fond sont restreints ; lancer `WakeActivity` depuis un service au premier plan reste autorisé.
- **Android 13+** : permission `POST_NOTIFICATIONS` à demander (le service au premier plan affiche une notification obligatoire).
- **Android 14+** : un service au premier plan de type « connexion maintenue » doit déclarer `FOREGROUND_SERVICE_SPECIAL_USE` (fait dans le manifeste) ; et `USE_FULL_SCREEN_INTENT` n'est plus accordée à l'installation pour une app qui n'est ni téléphonie ni réveil — l'app propose donc un bouton vers le réglage système correspondant.
- **`WRITE_SETTINGS`** (facultative, pour le maintien de l'écran) ne s'accorde jamais à l'installation : elle passe par `ACTION_MANAGE_WRITE_SETTINGS`. Sans elle, le repli par verrou d'écran s'applique automatiquement.
- **Surcouches constructeurs** (Xiaomi/MIUI, Huawei, Samsung, Oppo…) tuent agressivement les apps de fond. L'exemption de batterie et parfois un réglage « démarrage automatique » propre au constructeur sont nécessaires. C'est la cause n°1 d'« ça marche sur un téléphone mais pas l'autre ».

## 6. Sécurité (voir aussi le README)

- Chaque téléphone reçoit à l'association un **jeton secret** (32 octets aléatoires) ; il s'authentifie avec sur le WebSocket (`wss://`).
- L'utilisateur web s'authentifie par mot de passe (haché *bcrypt*) → cookie de session JWT `httpOnly`.
- Une commande n'est acceptée que pour un appareil **appartenant** à l'utilisateur connecté.
- **En production, tout doit passer par HTTPS/WSS** (TLS). Sans TLS, le jeton circulerait en clair.
- Révocation : « Dissocier » côté web invalide immédiatement le jeton (le serveur ferme le socket, code `4001`, et l'app efface sa configuration).
- Les commandes poussées par FCM sont **signées** avec le jeton de l'appareil et horodatées (voir §4). Les endpoints appelés par le téléphone (`/api/device/*`) s'authentifient avec ce même jeton.

## 7. Activer FCM (tout le code est déjà là)

Côté **Firebase** :

1. Créer un projet Firebase (gratuit) et y ajouter une app Android avec le package `com.cyko.remotewake`.
2. Télécharger `google-services.json` et le déposer dans `android/app/`. Le plugin Gradle ne s'applique que si ce fichier existe — sans lui, l'app compile et fonctionne en WebSocket seul.
3. *Paramètres du projet → Comptes de service → Générer une nouvelle clé privée* : c'est le JSON du serveur.

Côté **serveur**, dans `.env` :

```bash
FCM_SERVICE_ACCOUNT_FILE=./firebase-service-account.json
# ou bien FCM_PROJECT_ID / FCM_CLIENT_EMAIL / FCM_PRIVATE_KEY
```

Au démarrage, le serveur affiche `[fcm] push channel enabled`. Le téléphone transmet son *registration token* (`POST /api/device/fcm-token`) à l'association et à chaque rotation ; l'application web affiche alors « Veille — joignable par push » même socket fermé.

Implémentation : `server/src/fcm.js` (JWT de compte de service → jeton OAuth → `messages:send`, sans SDK) et `android/.../FcmService.kt`. `ScreenController` reste isolé du transport : les deux canaux appellent exactement le même code.
