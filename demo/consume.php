<?php
/**
 * @node_name 导航权限节点描述
 * Desc: 功能描述
 * Created by PhpStorm.
 * User: 杜一凡 | <duyifan@yundun.com>
 * Date: 2020/7/9 11:28
 */
require_once '../vendor/autoload.php';
require_once '../src/ydrabbitmq/YdRabbitMq.php';

use Yd\YdRabbitMq;

$logger = new \Monolog\Logger('ydrabbitmqc');   //测试使用 实际代码中使用loges相关
$logger->pushHandler(new \Monolog\Handler\StreamHandler('/tmp/ydrabbitmqa.log', \Monolog\Logger::INFO));
$config          = [
    "host"     => '127.0.0.1',
    "port"     => 5671,
    "vhost"    => '/',
    "username" => 'tester',
    "password" => 'tester',
];
$options         = [];
$queueConf = [
    "queueName" => "mq.adu.test.b",
    "exchange"  => 'ex.adu',
    "routeKey"  => 'rk.adu.test.b'
];
$rabbitMqConsume = new YdRabbitMq($config, $queueConf, $options);
$rabbitMqConsume->setLogger($logger,true);

function printrMessage($message) {
    var_dump($message->body);
    $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);
}
$i=0;
while (1){
    echo "开始".$i."\r\n";
    $rabbitMqConsume->consume('printrMessage', "testConsume");
    $i++;
    echo "结束".$i."\r\n";
}













