<?php
/**
 * @node_name 导航权限节点描述
 * Desc: 功能描述
 * Created by PhpStorm.
 * User: 杜一凡 | <duyifan@yundun.com>
 * Date: 2021/1/29 18:35
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
$queueConf       = [
    "queueName" => "mq.adu.test.c",
    "exchange"  => 'ex.adu',
    "routeKey"  => 'rk.adu.test.c'
];
$rabbitMqConsume = new YdRabbitMq($config, $queueConf, $options);
$rabbitMqConsume->setLogger($logger, true);



function printrMessage($message) {
    var_dump($message->body);
    $pub = new pub();
    $pub->p($message->body);
    $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);
}


$i = 0;
while (1) {
    echo "开始" . $i . "\r\n";
    $rabbitMqConsume->consume('printrMessage', "testConsume");
    $i++;
    echo "结束" . $i . "\r\n";
}

class pub{

        public $config          = [
        "host"     => '127.0.0.1',
        "port"     => 5671,
        "vhost"    => '/',
        "username" => 'tester',
        "password" => 'tester',
        ];
    public$options         = [];
    public $queueConfd      = [
        "queueName" => "mq.adu.test.d",
        "exchange"  => 'ex.adu',
        "routeKey"  => 'rk.adu.test.d'
        ];
    public $rabbitMqPublish;
    public function __construct(){
        $this->rabbitMqPublish = new YdRabbitMq($this->config, $this->queueConfd, $this->options);
        $logger = new \Monolog\Logger('ydrabbitmqc');   //测试使用 实际代码中使用loges相关
        $logger->pushHandler(new \Monolog\Handler\StreamHandler('/tmp/ydrabbitmqa.log', \Monolog\Logger::INFO));
        $this->rabbitMqPublish->setLogger($logger, true);
    }

    public function p($msg){
        $this->rabbitMqPublish->publish($msg);
    }
}












