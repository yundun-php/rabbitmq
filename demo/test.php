<?php
ini_set('date.timezone','Asia/Shanghai');
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

define('_AMQP_CONN_',  ['host' => '127.0.0.1', 'port' => 5670, 'user' => 'tester', 'password' => 'tester']);
define('_AMQP_CONN_AMQPROXY_',  ['host' => '127.0.0.1', 'port' => 5680, 'user' => 'tester', 'password' => 'tester']);
define('_AMQP_CONN_AMQPROXY_NGINX_',  ['host' => '127.0.0.1', 'port' => 9988, 'user' => 'tester', 'password' => 'tester']);
define('_AMQP_EX_',  'ex.test');
define('_AMQP_RK_',  'rk.test_amqp');
define('_AMQP_MQ_',  'mq.test_amqp');
define('_AMQPLIB_RK_',  'rk.test_amqplib');
define('_AMQPLIB_MQ_',  'mq.test_amqplib');

function amqplib_send($cfg=_AMQP_CONN_, $sleep = 1) {
    $connection = new AMQPStreamConnection(
        $cfg['host'], 
        $cfg['port'], 
        $cfg['user'], 
        $cfg['password'],
        '/',              // vhost
        false,           // insist
        'AMQPLAIN',             // login_method
        null,           //login_response
        'en_US',         //locale
        1.0,            //connection_timeout
        3.0,            //read_write_timeout
        null,             //context
        true,             //keepalive
        10,                //heartbeat
    );
    $channel = $connection->channel();
    
    $i = 0;
    while(++$i) {
        $msg = new AMQPMessage("message\t{$i}");
        $channel->basic_publish($msg, _AMQPLIB_EX_, _AMQPLIB_RK_);
        echo date('Y-m-d H:i:s')."\t{$cfg['host']}:{$cfg['port']}\tamqplib发送\t{$i}\n";
        if($sleep) sleep($sleep);
    }
    $channel->close();
    $connection->close();
}

function amqplib_consumer($cfg=_AMQP_CONN_) {
    $connection = new AMQPStreamConnection($cfg['host'], $cfg['port'], $cfg['user'], $cfg['password']);
    $channel = $connection->channel();
    $callback = function ($msg) use($cfg) {
        echo date('Y-m-d H:i:s')."\t{$cfg['host']}:{$cfg['port']}\tamqplib接收\t".$msg->body."\n";
    };
    $channel->basic_consume(_AMQPLIB_MQ_, '', false, true, false, false, $callback);
    while ($channel->is_open()) {
        $channel->wait();
    }
}

function amqp_send_confirm($cfg=_AQMP_CONN_, $sleep = 1) {
    $connection = new AMQPConnection(['heartbeat' => 15]);
    $connection->setHost($cfg['host']);
    $connection->setPort($cfg['port']);
    $connection->setLogin($cfg['user']);
    $connection->setPassword($cfg['password']);
    $connection->setWriteTimeout(1.0);
    $connection->setReadTimeout(3.0);
    $connection->connect();
    $channel = new AMQPChannel($connection);
	//只有发送消息不能到达时才会返回，如路由不存在
    $returnCallback = function($reply_code, $reply_text, $exchange, $routing_key, $properties, $body) {
		echo "Message returned: {$body}", PHP_EOL;
		return false;
    };
    $ackCallback = function ($delivery_tag, $multiple) {
        echo 'Message acked', $multiple, json_encode(func_get_args());
		return false; 		//这里一定要返回false
    };
    $nackCallback = function ($delivery_tag, $multiple) { return true; };
    $channel->setReturnCallback($returnCallback);
    $channel->setConfirmCallback($ackCallback, $nackCallback);
    $channel->confirmSelect();         //确认模式

    $exchange = new AMQPExchange($channel);
    $exchange->setName(_AMQP_EX_);
    $exchange->setType(AMQP_EX_TYPE_DIRECT);

    $i = 0;
    while(++$i<100) {
        try{
            $message = "message\t{$i}";
            $result = $exchange->publish($message, _AMQP_RK_, AMQP_MANDATORY);
			var_dump("publish-result: ".var_export($result, 1));
            echo date('Y-m-d H:i:s')."\t{$cfg['host']}:{$cfg['port']}\tamqp发送\t{$i}\n";
            try {
                $channel->waitForConfirm();
		        //$channel->waitForBasicReturn(); 		//使用confirm模式后，这里将不再需要
            } catch(Exception $e) {
                echo get_class($e), "({$e->getCode()}): ", $e->getMessage(). PHP_EOL;
            }
            if($sleep) sleep($sleep);
        }catch(Exception $ex){
            print_r($ex);
        }
    }
    $channel->close();
    $connection->disconnect();
}

function amqp_consumer($cfg=_AMQP_CONN_) {
    $connection = new AMQPConnection();
    $connection->setHost($cfg['host']);
    $connection->setPort($cfg['port']);
    $connection->setLogin($cfg['user']);
    $connection->setPassword($cfg['password']);
    $connection->pconnect();

    $channel = new AMQPChannel($connection);
    $exchange = new AMQPExchange($channel);
    $callback = function(AMQPEnvelope $message, AMQPQueue $q) use (&$cfg) {
        echo date('Y-m-d H:i:s')."\t{$cfg['host']}:{$cfg['port']}\tamqp接收\t".$message->getBody()."\n";
        $q->nack($message->getDeliveryTag());
    };

    try{
        $queue = new AMQPQueue($channel);
        $queue->setName(_AMQP_MQ_);
        $queue->setFlags(AMQP_NOPARAM);
    
        $queue->consume($callback);
    } catch(Exception $e) {
        var_dump($e);
    }
    $queue->cancel();
    $connection->disconnect();
}

function help() {
    echo "请指定要执行的动作!!!\n\n";
    echo "支持的动作如下：\n";
    echo "    amqp_send        <conn_type:conn|amqproxy|amqproxy_nginx> [sleep]  用 php-amqp扩展发信; conn_type 连接方式; sleep为间隔参数, 单位秒\n";
    echo "    amqplib_send     <conn_type:conn|amqproxy|amqproxy_nginx> [sleep]  用 php-amqplib 发信; conn_type 连接方式; sleep为间隔参数, 单位秒\n";
    echo "    amqp_consumer    <conn_type:conn|amqproxy|amqproxy_nginx> 用 php-amqp 扩展消费; conn_type 连接方式\n";
    echo "    amqplib_consumer <conn_type:conn|amqproxy|amqproxy_nginx> 用 php-amqplib库消费; conn_type 连接方式\n";
    echo "连接方式说明: \n";
    echo "    conn           直连方式，直接连接到rabbitmq节点\n";
    echo "    amqproxy       使用amqproxy连接池，amqproxy再代理到rabbitmq节点\n";
    echo "    amqproxy_nginx 通过nginx负载均衡的方式代理到一个或多个 amqpproxy 节点，负载均衡中可以设置失败次数及超时时间\n";
    exit();
}

function run($argv) {
    if(count($argv) < 2) help();
    switch($argv[1]) {
    case 'amqplib_send':
        $proxy = isset($argv[2]) ? $argv[2] : '';
        $sleep = isset($argv[3]) ? intval($argv[3]) : 1;
        echo "连接方式: {$proxy}\t频率：每 {$sleep} 秒一次\n";
        if($proxy == 'amqproxy') {
            amqplib_send( _AMQP_CONN_AMQPROXY_, $sleep);
        } elseif($proxy == 'amqproxy_nginx') {
            amqplib_send( _AMQP_CONN_AMQPROXY_NGINX_, $sleep);
        } else {
            amqplib_send(_AMQP_CONN_, $sleep);
        }
        break;
    case 'amqplib_consumer':
        $proxy = isset($argv[2]) ? $argv[2] : '';
        echo "连接方式: {$proxy}\n";
        if($proxy == 'amqproxy') {
            amqplib_consumer(_AMQP_CONN_AMQPROXY_);
        } elseif($proxy == 'amqproxy_nginx') {
            amqplib_consumer(_AMQP_CONN_AMQPROXY_NGINX_);
        } else {
            amqplib_consumer(_AMQP_CONN_);
        }
        break;
    case 'amqp_send':
        $sleep = isset($argv[3]) ? intval($argv[3]) : 1;
        $proxy = isset($argv[2]) ? $argv[2] : '';
        echo "连接方式: {$proxy}\t频率：每 {$sleep} 秒一次\n";
        if($proxy == 'amqproxy') {
            amqp_send_confirm(_AMQP_CONN_AMQPROXY_, $sleep);
        } elseif($proxy == 'amqproxy_nginx') {
            amqp_send_confirm(_AMQP_CONN_AMQPROXY_NGINX_, $sleep);
        } else {
            amqp_send_confirm(_AMQP_CONN_, $sleep);
        }
        break;
    case 'amqp_consumer':
        $proxy = isset($argv[2]) ? $argv[2] : '';
        echo "连接方式: {$proxy}\n";
        if($proxy == 'amqproxy') {
            amqp_consumer(_AMQP_CONN_AMQPROXY_);
        } elseif($proxy == 'amqproxy_nginx') {
            amqp_consumer(_AMQP_CONN_AMQPROXY_NGINX_);
        } else {
            amqp_consumer(_AMQP_CONN_);
        }
        break;
    default:
        help();
    }
}

run($argv);
