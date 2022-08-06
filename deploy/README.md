# Infrastructure

Root directory of infrastructure related files, service deployment and configuration.

## Production

Namespace: `telegram-bots`

### Configure

```shell
$ cp crashers-bot/secret.yml.example crashers-bot/secret.yml
$ cp mysql/secret.yml.example mysql/secret.yml
```

Set configuration keys and tokens in `crashers-bot/secret.yml` file

### Install

```shell
$ kubectl apply -f namespace.yml
$ kubectl apply -f mysql/secret.yml -f mysql/service.yml
$ kubectl apply -f crashers-bot/secret.yml -f crashers-bot/config.yml -f crashers-bot/service.yml -f crashers-bot/deployment.yml
$ kubectl exec -n telegram-bots deployments/crashers-bot -it -- php artisan migrate --force
$ kubectl exec -n telegram-bots deployments/crashers-bot -it -- php artisan webhook:set
```

Launch application based command:

```shell
$ kubectl exec -n telegram-bots deployments/crashers-bot -it -- php artisan migrate
```

### Deploy

#### API

```shell
$ kubectl -n telegram-bots set image deployment/crashers-bot crashers-bot=vovanms/crashers_bot_api:1.0.11   # Deploy new tag
$ kubectl -n telegram-bots rollout status deployment/crashers-bot                                           # Watch deployment status
$ kubectl -n telegram-bots rollout undo deployment/crashers-bot                                             # Rollback current deployment
$ kubectl -n telegram-bots rollout history deployment/crashers-bot                                          # List past deployment revision
$ kubectl -n telegram-bots rollout restart deployment/crashers-bot                                          # Redeploy currently deployed tag
```

Launch after every deploy

```shell
$ kubectl exec -n telegram-bots deployments/crashers-bot -it -- php artisan migrate --force
$ kubectl exec -n telegram-bots deployments/crashers-bot -it -- php artisan webhook:set
```

#### MySQL

```shell
$ kubectl -n telegram-bots set image statefulset/mysql mysql=cashtrack/mysql:1.0.8           # Deploy new tag of MySQL
$ kubectl -n telegram-bots rollout status statefulset/mysql                                  # Watch deployment status
$ kubectl -n telegram-bots rollout undo statefulset/mysql                                    # Rollback current deployment
$ kubectl -n telegram-bots rollout history statefulset/mysql                                 # List past deployment revision
$ kubectl -n telegram-bots rollout restart statefulset/mysql                                 # Redeploy currently deployed tag
```

## Troubleshooting

```shell
$ kubectl -n telegram-bots port-forward service/mysql 33060:3306               # Connect to MySQL from local
$ kubectl exec -n telegram-bots pods/mysql-0 -it -- bash                       # SSH into a Pod
$ kubectl exec -n telegram-bots pods/mysql-0 --container backup -it -- bash    # SSH into a specific container of a Pod
```
### Encode secret value

```shell
$ echo -n 'admin' | base64
```
