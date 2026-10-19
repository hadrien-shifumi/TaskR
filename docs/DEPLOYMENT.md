# Déploiement taskr — Scaleway, Ansible, Kamal, FrankenPHP

Runbook pour provisionner et déployer taskr sur les serveurs Scaleway.
Ce document décrit les étapes à suivre **lorsque tu seras prêt** ; rien n'a été
exécuté sur Scaleway à ce stade.

## Ressources créées (profil **default**, org `f7b84a13`)

| Ressource | ID / valeur |
|---|---|
| Projet | `6da8fcce-9a04-4921-96ee-7abfcccabd42` (Taskr) |
| Serveur `taskr-web-1` | `a378833d-1a2d-49a9-b476-aac6ee90aed3` — PRO2-XXS, fr-par-1, SBS 20G, IP `78.232.48.187` |
| Registry | `rg.fr-par.scw.cloud/taskr` (privé) — `fd115552-9600-4d8b-86f4-2a3da57e0905` |
| BDD `taskr-db` | `97c26abf-9fcd-4471-8d04-523cad79a86f` — PostgreSQL 17, db-play2-pico, endpoint `163.172.169.53:26756`, pgvector 0.8.2 activé, ACL limitée à `78.232.48.187/32` |

Le serveur est provisionné (user `deploy`, Docker 29, UFW 22/80/443, fail2ban,
unattended-upgrades, chrony, swap) et sécurisé via `ansible-playbook secure.yml`.
`.kamal/secrets` est rempli avec les vraies valeurs.

## Vue d'ensemble

| Brique | Rôle |
|---|---|
| `ansible/` | Cookbook de base : durcissement des serveurs avant tout déploiement |
| `Dockerfile` | Image de prod multi-stage (FrankenPHP 1.13 / PHP 8.5, Octane worker) |
| `docker/entrypoint.sh` | Attente BDD, migrations, caches, démarrage Octane |
| `config/deploy.yml` | Configuration Kamal 2 (registry + proxy + rôles web/worker) |
| `.kamal/secrets.example` | Secrets à remplir localement (gitignoré) |

L'application tourne en **FrankenPHP mode worker via Laravel Octane**
(`php artisan octane:frankenphp`). Le proxy Kamal (Traefik) termine le TLS.

## 1. Provisionner les serveurs Scaleway (manuel / console)

```bash
# Exemple : instance Ubuntu 24.04 (PRO2-XXS = amd64, PLAY2-PICO = arm64)
# avec ta clé SSH publique, dans le projet concerné.
scw instance server create \
  --profile blackstar \
  --name taskr-web-1 \
  --image ubuntu_jammy \
  --type PRO2-XXS \
  --zone fr-par-1 \
  --root-volume l:20G \
  --ip new
```

Note l'IP publique, puis installe Docker dessus (avant Kamal) :

```bash
ssh root@<IP> 'curl -fsSL https://get.docker.com | sh'
```

## 2. Créer le registry (une fois)

```bash
scw registry namespace create --profile blackstar --name taskr --region fr-par
```

Authentification : `docker login rg.fr-par.scw.cloud -u nologin -p <SCW_SECRET_KEY>`.

## 3. Créer la base PostgreSQL managée avec pgvector

```bash
# Instance PostgreSQL 16 avec l'extension pgvector activée
scw rdb instance create --profile blackstar \
  --name taskr-db \
  --node-type db-play2-pico \
  --engine PostgreSQL-16 \
  --is-ha-cluster false \
  --enable-backup true \
  --volume-size 10 \
  --region fr-par \
  --enable-extensions pgvector

# Base + utilisateur applicatif
scw rdb database create --profile blackstar <INSTANCE_ID> name=taskr region=fr-par
scw rdb user create --profile blackstar <INSTANCE_ID> name=taskr

# ACL : n'autoriser que l'IP du serveur applicatif (réseau privé de préférence)
scw rdb acl add --profile blackstar <INSTANCE_ID> ip=<SERVER_IP>/32 description=taskr-web-1
```

Récupère l'endpoint (`scw rdb instance get <INSTANCE_ID>` → `endpoint.url`/`port`)
et le mot de passe (`scw rdb password ...`). Le TLS est obligatoire : l'app
utilise `DB_SSLMODE=require` (déjà dans `config/deploy.yml`).

L'extension `pgvector` est requise par la recherche sémantique de la base de
connaissances (voir `KNOWLEDGE_EMBEDDINGS_*` dans `.env.example`).

## 4. Sécuriser les serveurs avec Ansible

```bash
cd ansible
cp inventory/hosts.ini.example inventory/hosts.ini   # gitignoré
# → renseigner l'IP, et tes clés publiques :
#    ansible/inventory/host_vars/<IP>.yml :
#      base_deploy_user_authorized_keys:
#        - ssh-ed25519 AAAA... hadrien@macbook

ansible-galaxy install -r requirements.yml
ansible-playbook secure.yml
```

Le playbook (idempotent) applique : utilisateur `deploy` + sudo sans mot de
passe, sshd durci (root/password interdits), UFW (SSH + 80/443 à ajouter dans
`base_firewall_allow`), fail2ban, unattended-upgrades, NTP, swap 2G,
durcissement kernel. **Attention** : une fois SSH root fermé, il faut la clé
publique configurée au point 4 avant de relancer.

## 5. Configurer Kamal

```bash
gem install kamal          # ou brew install kamal
cp .kamal/secrets.example .kamal/secrets   # gitignoré — à remplir
```

Renseigner dans `.kamal/secrets` : IP serveur, domaine, secret key registry,
`APP_KEY`, identifiants BDD, clé API embeddings.

**Important** : `deploy.yml` charge `.kamal/secrets` via dotenv (`Dotenv.load`)
en tête de fichier, donc les variables ERB (`ENV.fetch("KAMAL_...")` : hosts,
proxy, BDD, arch) sont résolues automatiquement — pas besoin de sourcer le
fichier dans le shell. Vérifier la configuration avec :

```bash
kamal config               # valide la configuration (vérifier :hosts !)
```

## 6. Déployer

```bash
kamal deploy     # déploiements suivants
kamal app logs -f
```

Au boot du conteneur web : attente de la BDD, `migrate --force --isolated`,
caches config/routes/views/events, puis démarrage d'Octane. Le rôle `worker`
lance `queue:work`. Chaque déploiement remplace le conteneur : les workers
Octane repartent propres (pas besoin d'`octane:reload`).

### Architecture cible

`utilisateur → proxy Kamal (443, ACME) → conteneur taskr (Octane/FrankenPHP :8080) → PostgreSQL managé (TLS, pgvector)`

## Vérifications post-déploiement

- `kamal app exec --reuse --interactive php artisan octane:status`
- `curl -fsSL https://<domaine>/up` → 200
- Backups BDD : `scw rdb backup list`

## Notes

- **Arch image** : `KAMAL_ARCH=amd64` (PRO2-XXS) ou `arm64` (PLAY2-PICO).
  Pour mélanger les deux types d'instances, utiliser un builder multi-arch.
- Les secrets ne sont jamais commités : `.kamal/secrets` et
  `ansible/inventory/hosts.ini` sont gitignorés.
