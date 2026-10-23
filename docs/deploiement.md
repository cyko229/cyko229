# Déployer RemoteWake

Tout est prêt dans le dépôt : vous n'avez à écrire aucun fichier de configuration.

## Avant de commencer : deux contraintes non négociables

1. **HTTPS obligatoire.** Sans TLS, le jeton secret du téléphone circulerait en clair, et les navigateurs refusent d'installer une PWA sur du HTTP simple. Les deux options ci-dessous s'en occupent automatiquement.
2. **Il faut un process Node permanent.** Le téléphone garde un WebSocket ouvert en continu. Les hébergements « serverless » (Netlify Functions, Vercel, Cloudflare Workers…) ne conviennent donc **pas** : ils n'exécutent du code que le temps d'une requête. Il faut un vrai serveur qui tourne en permanence.

---

## Option A — Render (le plus simple)

Aucune ligne de commande.

1. Poussez ce dépôt sur GitHub (déjà fait).
2. Sur [render.com](https://render.com) : **New → Blueprint**, choisissez ce dépôt.
3. Render lit [`render.yaml`](../render.yaml) et fait le reste : il crée le service, **génère tout seul** le `JWT_SECRET`, attache un disque de 1 Go pour les associations, et active HTTPS.
4. Cliquez **Apply**. Au bout de quelques minutes vous avez une URL du type `https://remotewake-xxxx.onrender.com`.

> **Le plan gratuit ne convient pas.** Render met un service gratuit en veille après ~15 min d'inactivité, ce qui coupe le WebSocket des téléphones — exactement ce que ce projet ne doit pas faire. Le blueprint demande donc le plan payant le moins cher (`starter`), qui reste allumé en permanence ; un disque persistant impose de toute façon un plan payant.

Le même fichier fonctionne, avec des noms de champs différents, sur Railway, Fly.io ou Koyeb : service Node permanent, disque monté, `node src/index.js` depuis `server/`.

---

## Option B — Votre propre serveur (VPS) avec Docker

Vous avez une machine et un nom de domaine qui pointe vers elle.

```bash
git clone https://github.com/cyko229/cyko229.git remotewake
cd remotewake

cp .env.example .env
nano .env        # DOMAIN=votredomaine.fr
                 # JWT_SECRET=...  (commande ci-dessous)

docker compose up -d
```

Pour le secret :

```bash
node -e "console.log(require('crypto').randomBytes(48).toString('hex'))"
# ou, sans Node : openssl rand -hex 48
```

C'est tout. [Caddy](../Caddyfile) obtient le certificat Let's Encrypt tout seul au premier démarrage et renouvelle ensuite sans intervention ; il transmet aussi les connexions WebSocket telles quelles.

Vérifier :

```bash
docker compose ps
curl https://votredomaine.fr/api/health
# {"ok":true,"devicesOnline":0,"pushEnabled":false,"uptimeSeconds":12}
```

### Mettre à jour

```bash
git pull && docker compose up -d --build
```

Les associations survivent : elles sont sur le volume `remotewake-data`, pas dans l'image.

### Sauvegarder / restaurer

```bash
# sauvegarde
docker compose exec remotewake cat /data/store.json > store-backup.json

# restauration
docker compose cp store-backup.json remotewake:/data/store.json
docker compose restart remotewake
```

---

## Option C — Docker seul, derrière un proxy existant

Si vous avez déjà Nginx, Traefik ou un autre reverse-proxy :

```bash
docker build -t remotewake .
docker run -d --name remotewake --restart unless-stopped \
  -p 127.0.0.1:8080:8080 \
  -e JWT_SECRET="$(openssl rand -hex 48)" \
  -e SECURE_COOKIES=true \
  -v remotewake-data:/data \
  remotewake
```

Votre proxy doit **transmettre les en-têtes `Upgrade` / `Connection`** sur `/device`, sinon les téléphones ne pourront pas se connecter. Avec Nginx :

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade    $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host       $host;
    proxy_read_timeout 3600s;   # le socket des téléphones reste ouvert
}
```

---

## Après le déploiement

1. **Créez votre compte** sur `https://votredomaine.fr` (le premier inscrit n'a aucun privilège particulier : pensez à un mot de passe solide, ou coupez l'inscription une fois votre compte créé).
2. **Installez la PWA** : la carte « Installer l'application » apparaît dans le tableau de bord.
3. **Associez le téléphone** : générez un code, saisissez-le dans l'app Android avec l'adresse `https://votredomaine.fr`.
4. **Réglez le chronomètre** de maintien de l'écran depuis l'application web.
5. **Activez FCM** (facultatif mais recommandé pour joindre un téléphone en veille profonde) — procédure dans le [README](../README.md#5-activer-les-notifications-push-fcm--facultatif-mais-recommandé).

## Points de sécurité à ne pas manquer

- `JWT_SECRET` long et aléatoire, et **jamais** versionné.
- `SECURE_COOKIES=true` dès que vous êtes en HTTPS (fait par les deux options ci-dessus).
- Ne versionnez jamais `server/firebase-service-account.json` ni `android/app/google-services.json` : les deux sont dans `.gitignore`.
- Le store JSON convient à un usage personnel. Pour plusieurs utilisateurs, remplacez `server/src/store.js` par une vraie base de données.
