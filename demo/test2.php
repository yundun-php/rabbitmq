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

class test2 {

    public $config     = [
        "host"     => '127.0.0.1',
        "port"     => 5671,
        "vhost"    => '/',
        "username" => 'tester',
        "password" => 'tester',
    ];
    public $options    = [];
    public $queueConf  = [
        "queueName" => "mq.adu.test",
        "exchange"  => 'ex.adu',
        "routeKey"  => 'rk.adu.test'
    ];
    public $queueConfa = [
        "queueName" => "mq.adu.test.a",
        "exchange"  => 'ex.adu',
        "routeKey"  => 'rk.adu.test.a'
    ];
    public $queueConfb = [
        "queueName" => "mq.adu.test.b",
        "exchange"  => 'ex.adu',
        "routeKey"  => 'rk.adu.test.b'
    ];
    public $queueConfc = [
        "queueName" => "mq.adu.test.c",
        "exchange"  => 'ex.adu',
        "routeKey"  => 'rk.adu.test.c'
    ];
    public $rabbitMqPublish;
    public $rabbitMqConsume;
    public $logger;

    public function __construct() {
        $this->rabbitMqPublish = new YdRabbitMq($this->config, $this->queueConfa, $this->options);
        $this->logger          = new \Monolog\Logger('ydrabbitmqc');   //测试使用 实际代码中使用loges相关
        $this->logger->pushHandler(new \Monolog\Handler\StreamHandler('/tmp/ydrabbitmqa.log', \Monolog\Logger::INFO));
        $this->rabbitMqPublish->setLogger($this->logger, true);


        $this->rabbitMqConsume = new YdRabbitMq(
            $this->config,
            $this->queueConf
        );
        $this->rabbitMqConsume->setLogger($this->logger);
    }

    public function main() {
        $i = 0;
        while (1) {
            echo "开始" .$i. "\r\n";
            $this->rabbitMqConsume->consume([$this, "handelMsg"], "handelMsga");
            sleep(1);
            $i++;
            echo "结束" . $i . "\r\n";
        }
    }

    public function handelMsg($msg) {
        $this->logger->info("队列数据" . $msg->body);
        $info       = json_decode($msg->body, 1);
        $queue_name = trim($info['queue']);

        $this->rabbitMqPublish->setQueueConf($this->{$queue_name});
        $this->rabbitMqPublish->publish($info);

        $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
        return true;

    }
}

$test2 = new test2();
$test2->main();











