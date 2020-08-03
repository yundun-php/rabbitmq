<?php
/**
 * @node_name 导航权限节点描述
 * Desc: 功能描述
 * Created by PhpStorm.
 * User: 杜一凡 | <duyifan@yundun.com>
 * Date: 2020/7/9 11:28
 */
require_once '../vendor/autoload.php';

use Yd\RabbitMqBundle\RabbitMqBundle;

$logger = new \Monolog\Logger('ydrabbitmq');   //测试使用 实际代码中使用loges相关
$logger->pushHandler(new \Monolog\Handler\StreamHandler('/tmp/ydrabbitmq.log', \Monolog\Logger::INFO));
$config = [
    "host"     => '127.0.0.1',
    "port"     => 5671,
    "vhost"    => '/',
    "username" => 'tester',
    "password" => 'tester',
];

$queueConf = [
    "queueName" => "mq.adu.test",
    "exchange"  => 'ex.adu',
    "routeKey"  => 'rk.adu.test'
];
$options   = [];

$rabbitMqPublish = new RabbitMqBundle($config, $queueConf, $options);
$rabbitMqPublish->setLogger($logger,true);
$i               = 1;
while ($i < 20) {
    $msg = [
        'data' => "test" . mt_rand(1000, 9999),
        'time' => date('Y-m-d H:i:s')
    ];
    var_dump("添加数据", $msg);
    $res = $rabbitMqPublish->publish($msg);
    var_dump("返回结果", $res);
    $i++;
}










