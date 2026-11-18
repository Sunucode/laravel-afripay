# Changelog

## 2.2.0

Bictorys, en page hébergée. Version mineure : rien ne change pour les projets qui ne l'activent pas.

### Ajouté

- **Passerelle `bictorys`** (Sénégal, Côte d'Ivoire, le reste de l'UEMOA selon ce que le compte a d'activé). Un POST ouvre la page de paiement Bictorys, où le client choisit Wave, Orange Money, MTN, Maxit ou la carte. Même forme que PayTech : une URL à ouvrir, un webhook qui règle. Elle implémente `SettlesFromGateway`.
- **`SignatureVerifier::verifyBictorysSignature()`**, qui accepte les deux formes documentées par Bictorys : le HMAC-SHA256 sur « {horodatage}.{corps} », et à défaut la clé statique de l'en-tête `X-Secret-Key`, comparée à temps constant. Leur horodatage est en millisecondes là où Stripe compte en secondes : lu comme des secondes, il situerait chaque message dans quelques millénaires et toute signature valide serait rejetée.
- **Route `POST {webhook_path}/bictorys`** et sa méthode sur le contrôleur, comme les six autres. `GatewayInterface` n'a pas bougé : les deux en-têtes de Bictorys sont ramenés à une seule chaîne, au format `t=...,v1=...` déjà utilisé pour Wave.
- **Cas `Gateway::Bictorys`** dans l'enum, avec son libellé et ses pays.

### Corrigé

- **Un réglage `enabled` écrit « 1 » était lu de deux façons.** Les routes de webhook l'interprètent avec `filter_var` depuis toujours, `isEnabled()` et `enabledGateways()` le comparaient strictement à `true`. Un `AFRIPAY_WAVE_ENABLED=1` dans un `.env`, ou un `<env value="true"/>` de PHPUnit, qui vaut « 1 », enregistrait donc la route du webhook et faisait quand même échouer `charge()` en annonçant une passerelle désactivée. Les trois endroits lisent maintenant la même chose. Les valeurs déjà acceptées le restent à l'identique : seuls « 1 », « on » et « yes » cessent d'être lus comme un refus.

### Ce qui n'est pas couvert

- **L'API directe par opérateur** (`?payment_type=`). Elle rend un QR code pour Wave sur ordinateur, un message USSD à afficher, et réclame un code OTP pour Orange Money Côte d'Ivoire, là où `charge()` ne sait rendre qu'une URL. La couvrir demanderait un contrat supplémentaire, comme `ChargesOffSession` l'a été pour les prélèvements.
- **Les retraits (payouts).** Le package n'a aucune notion de retrait : leur clé privée n'a donc pas sa place dans sa configuration.
- **Le prélèvement hors session.** `canChargeOffSession('bictorys')` répond `false`, comme pour tout le Mobile Money.
- **Le remboursement automatique.** Un webhook `reversed` sur une transaction déjà réglée est journalisé en avertissement, jamais transformé en remboursement : c'est une décision métier, et `PaymentManager::refund()` reste là pour la prononcer.

### À vérifier avant de monter

- **La passerelle arrive désactivée** (`AFRIPAY_BICTORYS_ENABLED=false`). C'est voulu : un projet qui alimente son écran de paiement avec `AfriPay::enabledGateways()` afficherait sinon un bouton Bictorys sans identifiants derrière, dès le `composer update`. Rien n'apparaît tant que vous ne l'activez pas, et sans activation la route de webhook n'existe même pas.
- **Aucune migration, aucune table touchée** : `gateway` est un `string(30)`, « bictorys » y tient.
- **Les six autres passerelles sont inchangées.** Les 86 tests existants passent sans une modification.
- Trois réglages suffisent : `BICTORYS_API_KEY` (leur clé publique), `BICTORYS_WEBHOOK_SECRET` (un troisième secret, ni la publique ni la privée) et `BICTORYS_COUNTRY`. Chez Bictorys, les webhooks de test et de production se configurent séparément : les deux environnements se règlent dans leur tableau de bord, sinon la notification n'arrive jamais.
- **Le montant part en entier**, leur minimum est de 100 FCFA.

## 2.1.3

### Corrigé

- **La session d'enregistrement demande la création d'un client.** En `mode=setup` sans `customer`, la valeur par défaut `customer_creation=if_required` ne crée rien : Stripe ne la juge nécessaire que pour les abonnements et certains paiements. Or un prélèvement hors session exige `customer` autant que `payment_method`. La carte était donc autorisée par le client, puis perdue, et `confirmSavedMethod()` rendait `null` sans que rien n'explique pourquoi. La session passe désormais `customer_creation=always`.
- **Un enregistrement sans client est journalisé.** Mieux vaut ne rien garder qu'une carte inutilisable qu'on croira valide au premier prélèvement.

## 2.1.2

### Corrigé

- **Stripe comprend les PaymentIntent, pas seulement les sessions Checkout.** Un prélèvement hors session produit un PaymentIntent, qui dit `status` et `amount` là où une session dit `payment_status` et `amount_total`. Le lecteur ne connaissait que la session et rendait « en attente » pour un intent : un paiement confirmé après authentification forte n'était jamais réglé, parce que le webhook qui l'annonce était ignoré. Un intent en `requires_action` reste en attente, le client pouvant encore confirmer ; `canceled` et `requires_payment_method` le font échouer.
- **`verify()` interroge le bon endpoint.** Une référence `pi_` était cherchée sur `checkout/sessions`, qui rend 404 : la transaction restait en attente pour toujours.

## 2.1.1

### Corrigé

- **Propriétés déclarées sur le modèle Transaction.** L'analyse statique ne lit pas une migration : un projet hôte sous Larastan voyait `$transaction->status` comme une propriété inexistante et échouait sa propre analyse sur notre modèle. Docblocs seulement, aucun changement d'exécution.

## 2.1.0

Prélever une carte enregistrée, sans le client. Version mineure : rien ne change pour les projets qui n'en veulent pas.

### Ajouté

- **Contrat `ChargesOffSession`**, optionnel comme `SettlesFromGateway`, implémenté par Stripe seul. `charge()` a toujours besoin du client, puisqu'il rend une URL à ouvrir : c'est la bonne forme pour le Mobile Money, où chaque paiement s'autorise un par un. La facturation à la consommation et les abonnements ont besoin de l'inverse.
- **`startSavingMethod()`** ouvre la même page Checkout que `charge()`, en mode `setup`. Un paramètre de différence, aucun montant : rien n'est prélevé, le client autorise seulement. Le webhook, la vérification de signature et la page de retour continuent de fonctionner tels quels.
- **`confirmSavedMethod()`** lit l'autorisation terminée et garde les identifiants. La carte ne nous parvient jamais. Rend `null` tant que la session n'est pas finie, pour qu'une page de retour ouverte trop tôt n'enregistre rien ; la même carte autorisée deux fois met à jour une ligne au lieu d'en créer deux.
- **`chargeSaved()`** prélève sans le client, via un PaymentIntent `off_session` et `confirm`.
- **Table `afripay_payment_methods`**, polymorphe comme `afripay_transactions` : le moyen de paiement appartient à ce que l'application hôte facture. Aucun numéro de carte n'est stocké, seulement les deux identifiants et les quatre chiffres qu'un humain reconnaît.
- **`RequiresCustomerAuthentication`**, exception distincte. L'authentification forte s'applique même quand c'est l'entreprise qui prélève : la banque répond `authentication_required`, et ce n'est pas un refus. Traitée comme un échec ordinaire, elle ferait relancer puis suspendre un client dont la carte n'a jamais été refusée.
- **`PaymentMethod::hasExpired()`** : une date d'expiration se connaît à l'avance. Un client prévenu un mois avant change sa carte, un client prévenu par une coupure ne le fait pas.
- **`PaymentManager::canChargeOffSession()`** : demander avant de proposer un abonnement, plutôt que de le découvrir au premier prélèvement.

### À vérifier avant de monter

- **`php artisan migrate`** crée `afripay_payment_methods`. Aucune table existante n'est touchée.
- Les cinq autres passerelles sont inchangées. Une passerelle qui n'implémente pas le contrat est refusée explicitement par `PaymentManager`, et non en silence au moment du prélèvement.

## 2.0.0

Correctifs de fiabilité des paiements. Version majeure parce que `verify()` ne modifie plus la transaction : un projet en `^1.0` ne la reçoit pas sans le vouloir. Lire « À vérifier » avant de passer en `^2.0`.

### Changement incompatible

- **`verify()` est en lecture seule** pour Wave, PayDunya, Stripe et Orange Money. Le code qui appelait `AfriPay::verify($transaction)` puis lisait `$transaction->status` pour savoir si le paiement est reçu doit lire `$transaction->isConfirmedByGateway()` (ou `$transaction->remoteStatus`). Pour régler la transaction depuis la page de retour, appeler `verifyAndProcess()` avec `AFRIPAY_TRUST_WEBHOOK_ONLY=false`.

### Corrigé

- **Course entre la page de retour et le webhook.** `verify()` ne modifie plus la transaction pour Wave, PayDunya, Stripe et Orange Money : il lit le statut auprès de la passerelle et le place dans `$transaction->remoteStatus`, sans rien enregistrer. Avant, la page de retour passait la transaction en « payée » sans événement, et le webhook arrivé ensuite l'ignorait : `PaymentCompleted` ne partait jamais.
- **Idempotence.** Une transaction ne quitte « en attente » qu'une fois, sous verrou, et l'événement part à ce moment-là seulement. Un webhook rejoué, ou arrivé après la page de retour, ne déclenche plus rien (toutes passerelles).
- **PayDunya.** Le statut et le montant ne sont plus lus dans l'IPN, qui n'est pas signé : ils sont relus sur l'API de confirmation. La casse du statut (« Completed » ou « completed ») est indifférente.
- **Montant et devise.** Un paiement confirmé sans montant, ou d'un montant ou d'une devise différents de ceux de la transaction, échoue (Wave, PayDunya, Stripe).
- **Wave.** Signature lue avec plusieurs `v1=` (rotation de clé) ; l'événement `checkout.session.payment_failed` fait échouer la transaction ; un webhook sans montant est relu sur l'API.
- **Page de retour.** Une passerelle injoignable laisse la page sur « en attente » au lieu de lever une erreur.

### Ajouté

- `Transaction::isConfirmedByGateway()` : vrai si la transaction est payée en base, ou confirmée par la passerelle en attendant le webhook. C'est le test à utiliser sur la page de retour.
- Contrat `SettlesFromGateway` (`settle()`), implémenté par Wave, PayDunya, Stripe et Orange Money.
- `WAVE_TEST_AMOUNT` : montant facturé à la place du vrai, pour un essai en production. Vide par défaut.
- `ORANGE_MONEY_API_PATH` (défaut `orange-money-webpay/dev/v1`) et `ORANGE_MONEY_CURRENCY` (défaut `OUV`) : le passage en production d'Orange Money ne demande plus de modifier le code.
- Index unique sur (passerelle, référence). La migration ne touche jamais aux données : si des doublons existent, elle n'ajoute pas l'index et l'écrit dans le journal.

### À vérifier avant de monter

- **Wave ne facture plus 5 F en local ni en test.** Le montant de test dépendait de `APP_ENV` (`local` ou `testing`) ; il dépend désormais de `WAVE_TEST_AMOUNT`. Un poste qui comptait sur les 5 F doit renseigner `WAVE_TEST_AMOUNT=5`. Le paiement de ce montant vaut paiement attendu.
- **Routes des passerelles désactivées.** Seules les passerelles dont `AFRIPAY_*_ENABLED` est vrai ont leur route de webhook. Attention aux valeurs par défaut : Orange Money et PayPal sont désactivés par défaut, PayTech activé.
- **Page de retour avec `AFRIPAY_TRUST_WEBHOOK_ONLY=true`.** `$transaction->status` reste `pending` tant que le webhook n'est pas arrivé : tester `isConfirmedByGateway()` plutôt que `status->isCompleted()` pour afficher « paiement reçu ».
- **`php artisan migrate`** ajoute l'index unique ; lancer `route:cache` après avoir changé `AFRIPAY_*_ENABLED`.

PayPal et PayTech gardent leur fonctionnement, avec l'idempotence en plus.
