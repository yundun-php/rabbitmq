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

$rabbitMqPublish = new YdRabbitMq($config, $queueConf, $options);
//$rabbitMqPublish = YdRabbitMq::obj($config, $queueConf, $options);
$rabbitMqPublish->setLogger($logger,true);
$i               = 1;
while ($i < 10000000) {
    $msg = [
        'queue' => 'queueConfa',
        'data' => "test" . mt_rand(1000, 9999),
        'time' => date('Y-m-d H:i:s')
    ];
    var_dump("添加数据", $msg);
    $res = $rabbitMqPublish->publish($msg);
    var_dump("返回结果", $res);
    $msg = [
        'queue' => 'queueConfb',
        'data' => "test" . mt_rand(1000, 9999),
        'time' => date('Y-m-d H:i:s')
    ];
    var_dump("添加数据", $msg);
    $res = $rabbitMqPublish->publish($msg);
    var_dump("返回结果", $res);
    $msg = [
        'queue' => 'queueConfc',
        'data' => "test" . mt_rand(1000, 9999),
        'time' => date('Y-m-d H:i:s')
    ];
    var_dump("添加数据", $msg);
    $res = $rabbitMqPublish->publish($msg);
    var_dump("返回结果", $res);
    sleep(1);
    $i++;
}










