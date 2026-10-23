# Allumer l'écran d'un Android à distance — possibilités et limites

Ce document répond au point technique central du projet : **que permet réellement Android, et où sont les limites ?**

## 1. Ce qui est possible vs impossible

| Souhait | Possible ? | Pourquoi |
| --- | --- | --- |
| Allumer **l'écran** d'un téléphone allumé mais verrouillé / écran éteint | ✅ Oui | Une app peut lancer une activité marquée `setTurnScreenOn(true)` ; le système allume alors l'affichage. |
| Verrouiller le téléphone à distance | ✅ Oui | API *Device Admin* → `DevicePolicyManager.lockNow()`. |
| **Démarrer** un téléphone totalement éteint (ou batterie retirée) | ❌ Non | Aucun logiciel ne tourne quand l'appareil est éteint. Rien ne peut recevoir une commande. Il n'existe pas de « Wake-on-LAN » pour l'écran d'un téléphone. C'est une limite matérielle/OS absolue. |
| Déverrouiller le téléphone à distance (passer le code PIN) | ❌ Non (volontairement) | Android ne laisse aucune app contourner l'écran de verrouillage. On allume l'écran ; l'utilisateur déverrouille normalement. |

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

## 4. Le vrai défi : *recevoir* la commande quand l'écran est éteint

Allumer l'écran est simple **une fois la commande reçue**. La difficulté est que le téléphone, écran éteint depuis un moment, entre en **Doze** (veille profonde) : Android suspend le réseau et les tâches de fond pour économiser la batterie. Deux canaux pour contourner cela :

### Option A — WebSocket + service au premier plan (implémentée ici, zéro configuration)
- Un *foreground service* maintient une connexion WebSocket ouverte vers le serveur.
- Fonctionne immédiatement, sans compte tiers.
- **Limite :** en Doze profond, le réseau peut être suspendu et la connexion coupée jusqu'à la prochaine fenêtre de maintenance. Pour un usage fiable il faut **exempter l'app de l'optimisation de batterie** (bouton prévu dans l'app). Consomme un peu plus de batterie.

### Option B — Firebase Cloud Messaging (FCM) haute priorité (recommandé en production)
- Les messages FCM `priority: high` **réveillent l'app même en Doze** — c'est le mécanisme conçu par Google pour exactement ce cas.
- Plus fiable et plus économe que de garder un socket ouvert.
- **Coût :** nécessite un projet Firebase gratuit et le fichier `google-services.json`.

> Stratégie conseillée : **FCM comme canal principal**, le WebSocket comme repli / mode sans-configuration. Le serveur et l'app de ce projet sont structurés pour qu'on puisse brancher FCM sans tout réécrire (voir « Ajouter FCM » ci-dessous).

## 5. Contraintes selon la version d'Android

- **API 27+ (Android 8.1)** requis pour `setTurnScreenOn` — d'où `minSdk = 27`.
- **Android 12+** : la précision des alarmes et le lancement d'activités depuis le fond sont restreints ; lancer `WakeActivity` depuis un service au premier plan reste autorisé.
- **Android 13+** : permission `POST_NOTIFICATIONS` à demander (le service au premier plan affiche une notification obligatoire).
- **Android 14+** : un service au premier plan de type « connexion maintenue » doit déclarer `FOREGROUND_SERVICE_SPECIAL_USE` (fait dans le manifeste).
- **Surcouches constructeurs** (Xiaomi/MIUI, Huawei, Samsung, Oppo…) tuent agressivement les apps de fond. L'exemption de batterie et parfois un réglage « démarrage automatique » propre au constructeur sont nécessaires. C'est la cause n°1 d'« ça marche sur un téléphone mais pas l'autre ».

## 6. Sécurité (voir aussi le README)

- Chaque téléphone reçoit à l'association un **jeton secret** (32 octets aléatoires) ; il s'authentifie avec sur le WebSocket (`wss://`).
- L'utilisateur web s'authentifie par mot de passe (haché *bcrypt*) → cookie de session JWT `httpOnly`.
- Une commande n'est acceptée que pour un appareil **appartenant** à l'utilisateur connecté.
- **En production, tout doit passer par HTTPS/WSS** (TLS). Sans TLS, le jeton circulerait en clair.
- Révocation : « Dissocier » côté web invalide immédiatement le jeton (le serveur ferme le socket, code `4001`, et l'app efface sa configuration).

## 7. Ajouter FCM plus tard (esquisse)

1. Créer un projet Firebase, ajouter l'app Android, déposer `google-services.json` dans `android/app/`.
2. Ajouter le plugin `com.google.gms.google-services` et la dépendance `firebase-messaging`.
3. Un `FirebaseMessagingService` envoie son *registration token* au serveur à l'association (nouveau champ sur l'appareil).
4. Côté serveur : à la commande `wake`/`lock`, au lieu (ou en plus) du WebSocket, appeler l'API FCM `send` avec `android: { priority: "high" }` et un *data message* `{ action: "wake" }`.
5. À réception, le service appelle le même `ScreenController.wake()` / `.lock()` déjà présent.

Le découpage actuel (`ScreenController` isolé du transport) rend ce branchement local.
