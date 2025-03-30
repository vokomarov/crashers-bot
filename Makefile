include .env.build
export

# Local config
CONTAINER_NAME=crashers_bot_api
CONTAINER_PORT=80
DB_CONTAINER_NAME=crashers_bot_db
DB_CONTAINER_PORT=33060
DB_CONTAINER_DATA=$(shell pwd)/storage/app/database
NETWORK_NAME=crashers-bot-local

# Deploy config
REPO=vovanms/crashers_bot_api
IMAGE_RELEASE=$(REPO):$(RELEASE_VERSION)
IMAGE_DEV=$(REPO):dev
IMAGE_LATEST=$(REPO):latest
WORKDIR=$(shell pwd)

.PHONY: build tag push start stop start-app stop-app start-db stop-db network

build:
	docker build . -t $(IMAGE_DEV)

tag:
	docker tag $(IMAGE_DEV) $(IMAGE_RELEASE)
	docker tag $(IMAGE_DEV) $(IMAGE_LATEST)

push:
	docker push $(IMAGE_RELEASE)
	docker push $(IMAGE_LATEST)

start: start-db start-app

stop: stop-app stop-db

start-app: network
	docker run \
      --rm \
      --name $(CONTAINER_NAME) \
      -p $(CONTAINER_PORT):8090 \
      --env-file .env \
      -v $(WORKDIR):/app \
      --net $(NETWORK_NAME) \
      $(IMAGE_DEV) \
      -o "http.pool.max_jobs=1" \
      -o "http.pool.num_workers=1" \
      -o "logs.mode=development" \
      -o "logs.level=debug"

stop-app:
	docker stop $(CONTAINER_NAME)

network:
	docker network create --driver bridge $(NETWORK_NAME) || true

start-db: network
	mkdir -p $(DB_CONTAINER_DATA)
	docker run \
	  --rm \
	  -d \
	  --name $(DB_CONTAINER_NAME) \
	  -p $(DB_CONTAINER_PORT):3306 \
	  --net $(NETWORK_NAME) \
	  -v $(DB_CONTAINER_DATA):/var/lib/mysql \
	  -e MYSQL_ROOT_PASSWORD=$(MYSQL_ROOT_PASSWORD) \
	  -e MYSQL_DATABASE=$(MYSQL_DATABASE) \
  	  -e MYSQL_USER=$(MYSQL_USER) \
  	  -e MYSQL_PASSWORD=$(MYSQL_PASSWORD) \
  	  --platform linux/amd64 \
  	  mysql:8.0.28

stop-db:
	docker stop $(DB_CONTAINER_NAME)

