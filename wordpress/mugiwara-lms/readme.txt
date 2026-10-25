=== Mugiwara LMS – Formations Google Drive ===
Requires at least: 6.2
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.4.0

Transforme la boutique WooCommerce de Mugiwara Academy en plateforme de formation.

== Fonctionnement ==

1. Un client achète une formation (produit WooCommerce). Le paiement crée son compte (achat invité désactivé).
2. Dès que la commande est payée (« En cours » ou « Terminée »), l'accès s'ouvre automatiquement.
3. L'élève retrouve ses formations dans Mon compte → Mes formations : progression, bouton « Reprendre », recherche et filtres.
4. Le lecteur affiche chaque leçon (vidéo, PDF, Docs, dossier…) directement depuis Google Drive, avec sommaire par modules,
   « Marquer comme terminée », leçon suivante/précédente, téléchargement, onglet Ressources et « Télécharger toute la formation ».
5. Remboursement ou annulation : l'accès accordé par la commande est retiré.

== Mise en place ==

1. Extensions → Ajouter → Téléverser : envoyez mugiwara-lms.zip, puis activez.
2. Sur Google Drive, partagez chaque dossier de formation en « Tous les utilisateurs disposant du lien » (lecteur).
3. Espace formation → Ajouter : donnez un titre, puis
   - soit collez le lien du dossier Drive dans « Importer depuis Google Drive » (clé API requise, voir Réglages) ;
   - soit ajoutez les modules et leçons à la main en collant les liens Drive.
4. Sur chaque fiche produit, encadré « Espace formation » : cochez la ou les formations ouvertes par l'achat
   (le bouton « Créer la formation de ce produit » prépare tout).
5. Espace formation → Réglages → « Donner l'accès aux anciens acheteurs » pour les commandes déjà passées.

Organisation conseillée d'un dossier Drive :
  Ma formation/
    01 - Introduction/        → module 1 (chaque fichier = une leçon)
    02 - Les bases/           → module 2
    Ressources/               → onglet Ressources (aussi : « Bonus », « Annexes »)

== Bon à savoir ==

* Les fichiers Drive doivent être partagés par lien pour être lus dans le site. Les liens directs vers Drive ne sont jamais
  écrits dans les boutons de téléchargement : ils passent par le site, qui vérifie l'achat. Le lecteur intégré, lui,
  laisse voir l'identifiant du fichier : un élève déterminé pourrait le partager.
* Shortcode : [mugiwara_formations] affiche l'espace élève dans n'importe quelle page Elementor.
* Les gabarits sont personnalisables : copiez templates/*.php dans votre-thème/mugiwara-lms/.
