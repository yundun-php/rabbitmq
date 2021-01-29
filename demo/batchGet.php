<?php
/**
 * @node_name 导航权限节点描述
 * Desc: 功能描述
 * Created by PhpStorm.
 * User: 杜一凡 | <duyifan@yundun.com>
 * Date: 2020/12/8 17:43
 */
require_once '../vendor/autoload.php';
require_once '../src/ydrabbitmq/YdRabbitMq.php';

use Yd\YdRabbitMq;

$logger = new \Monolog\Logger('ydrabbitmq_batchget');   //测试使用 实际代码中使用loges相关
$logger->pushHandler(new \Monolog\Handler\StreamHandler('/tmp/ydrabbitmq_batchget.log', \Monolog\Logger::INFO));
$config          = [
    "host"     => '127.0.0.1',
    "port"     => 5671,
    "vhost"    => '/',
    "username" => 'tester',
    "password" => 'tester',
];
$options         = [];
$queueConf = [
    "queueName" => "mq.adu.test.a",
    "exchange"  => 'ex.adu',
    "routeKey"  => 'rk.adu.test.a'
];
$rabbitMqConsume = new YdRabbitMq($config, $queueConf, $options);
$rabbitMqConsume->setLogger($logger,true);
while (1){
    echo "开始   ".microtime(1)."\r\n";
    $data = $rabbitMqConsume->batchGet(10);
    if($data){
        consumeM($data);
    }
    echo  "结束   ".microtime(1)."\r\n";
    echo "--------------------------\r\n";
    usleep(1000000);
}

function consumeM($data=[]) {
    var_dump("consumeM");
    print_r($data);
}

