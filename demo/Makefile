help:
	@echo "make cluster_start 启动集群"
	@echo "make cluster_join  加入集群"

cluster_start:
	docker-compose rm -f -s
	rm -rf ./docker_data/*
	mkdir -p ./docker_data/rabbitmq_cluster1/
	mkdir -p ./docker_data/rabbitmq_cluster2/
	mkdir -p ./docker_data/rabbitmq_cluster3/
	mkdir -p ./docker_data/rabbitmq_cluster4/
	echo 'e289b196fc8dad249ab9fba4e6796672' > ./docker_data/rabbitmq_cluster1/.erlang.cookie
	echo 'e289b196fc8dad249ab9fba4e6796672' > ./docker_data/rabbitmq_cluster2/.erlang.cookie
	echo 'e289b196fc8dad249ab9fba4e6796672' > ./docker_data/rabbitmq_cluster3/.erlang.cookie
	echo 'e289b196fc8dad249ab9fba4e6796672' > ./docker_data/rabbitmq_cluster4/.erlang.cookie
	docker-compose up --no-start
	docker-compose start

cluster_join:
	#进入rabbitmq_cluster1容器，重新初始化一下，如果是新安装则reset可以忽略重置
	docker exec -it rabbitmq_cluster1 rabbitmqctl stop_app
	docker exec -it rabbitmq_cluster1 rabbitmqctl reset
	docker exec -it rabbitmq_cluster1 rabbitmqctl start_app
	#进入rabbitmq_cluster2容器，重新初始化一下，将node2节点加入到集群中
	docker exec -it rabbitmq_cluster2 rabbitmqctl stop_app
	docker exec -it rabbitmq_cluster2 rabbitmqctl reset
	docker exec -it rabbitmq_cluster2 rabbitmqctl join_cluster --ram node1@rabbitmq_cluster1 #参数 --ram 表示设置为内存节点
	docker exec -it rabbitmq_cluster2 rabbitmqctl start_app
	#进入rabbitmq_cluster3容器，重新初始化一下，将node3节点加入到集群中
	docker exec -it rabbitmq_cluster3 rabbitmqctl stop_app
	docker exec -it rabbitmq_cluster3 rabbitmqctl reset
	docker exec -it rabbitmq_cluster3 rabbitmqctl join_cluster --ram node1@rabbitmq_cluster1
	docker exec -it rabbitmq_cluster3 rabbitmqctl start_app
	#进入rabbitmq_cluster4容器，重新初始化一下，将node4节点加入到集群中
	docker exec -it rabbitmq_cluster4 rabbitmqctl stop_app
	docker exec -it rabbitmq_cluster4 rabbitmqctl reset
	docker exec -it rabbitmq_cluster4 rabbitmqctl join_cluster --ram node1@rabbitmq_cluster1
	docker exec -it rabbitmq_cluster4 rabbitmqctl start_app

