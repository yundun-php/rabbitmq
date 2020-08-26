<?php
/**
 * @node_name 导航权限节点描述
 * Desc: 功能描述
 * Created by PhpStorm.
 * User: 杜一凡 | <duyifan@yundun.com>
 * Date: 2020/7/3 16:31
 */

namespace Yd;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;

class YdRabbitMq {
    const  MAX_ATTEMPTS = 3;
    const  CONSUMER_TAG = "consumer";
    protected $config;
    protected $options;
    protected $connection         = null;
    protected $channel            = null;
    protected $queueName          = '';   //队列名称
    protected $exchange           = '';    //交换机名称
    protected $routeKey           = '';    //路由名称
    protected $logger             = null;
    protected $connectionAttempts = 0;
    protected $logDebug           = false;

    protected $defaultsConfig  = [
        'host'     => '127.0.0.1',
        'port'     => 5672,
        'username' => 'guest',
        'password' => 'guest',
        'vhost'    => '/'
    ];
    protected $defaultsOptions = [
        'insist'             => false,
        'login_method'       => 'AMQPLAIN',
        'login_response'     => null,
        'locale'             => 'en_US',
        'connection_timeout' => 1.0,
        'read_write_timeout' => 3.0,
        'context'            => null,
        'keepalive'          => true,
        'heartbeat'          => 0
    ];

    public function __construct($connectionConfig = [], $queueConf = [], $options = []) {
        $this->config    = array_merge($this->defaultsConfig, $connectionConfig);
        $this->options   = array_merge($this->defaultsOptions, $options);
        $this->exchange  = $queueConf['exchange'];
        $this->routeKey  = $queueConf['routeKey'];
        $this->queueName = $queueConf['queueName'];
    }


    public function initConnection() {
        if ((!$this->connection && !($this->connection instanceof AMQPStreamConnection))
            || (!$this->channel && !($this->channel instanceof AMQPChannel))
        ) {
            try {
                $this->connection = new AMQPStreamConnection(
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['username'],
                    $this->config['password'],
                    $this->config['vhost'],
                    $this->options['insist'],
                    $this->options['login_method'],
                    $this->options['login_response'],
                    $this->options['locale'],
                    $this->options['connection_timeout'],
                    $this->options['read_write_timeout'],
                    $this->options['keepalive'],
                    $this->options['heartbeat']
                );
                $this->channel    = $this->connection->channel();
                $this->channel->set_ack_handler(
                    function ($message) {
                        $this->logInfo("Message ack with content" . $message->getBody());
                    }
                );
                $this->channel->set_nack_handler(
                    function ($message) {
                        $this->logInfo("Message nack with content" . $message->getBody());
                    }
                );
                $this->channel->set_return_listener(
                    function ($replyCode, $replyText, $exchange, $routingKey, $message) {
                        $this->logInfo("投递异常返回数据set_return_listener");
                        $this->logInfo(var_export($replyCode, 1));
                        $this->logInfo(var_export($replyText, 1));
                        $this->logInfo(var_export($exchange, 1));
                        $this->logInfo(var_export($routingKey, 1));
                        $this->logInfo(var_export($message->getBody(), 1));
                    }
                );
                $this->channel->confirm_select();
            } catch (\Exception $e) {
                $this->logInfo("rabbitmq连接异常" . var_export($e->getMessage(), 1));
                while ($this->connectionAttempts < self::MAX_ATTEMPTS && !$this->isConnected()) {
                    $this->close();
                    $this->logInfo("重试连接第" . ($this->connectionAttempts + 1) . "次");
                    $this->connectionAttempts++;
                    $this->initConnection();
                }
                if (!$this->isConnected()) {
                    //发送告警??? todo
                    $this->logInfo("连接三次失败" . var_export($e->getMessage(), 1));
                    throw new \Exception($e->getMessage());
                }

            }
        }
        if ($this->connection && ($this->connection instanceof AMQPStreamConnection) &&
            $this->channel && ($this->channel instanceof AMQPChannel)
        ) {
            try {
                $this->connection->checkHeartBeat();
            } catch (\Exception $e) {
                $this->logInfo("checkHeartBeatException:" . $e->getMessage());
                throw new \Exception($e->getMessage());
            }
        }
        $this->logInfo("连接结束");
    }

    public function publish($data) {
        $message = $data;
        if (!is_array($message) && !is_string($message)) {
            return false;
        }
        if (is_array($message)) {
            $message = json_encode($message);
        }
        $this->initConnection();
        $flag = true;
        $msg  = new AMQPMessage($message);
        try {
            $this->channel->basic_publish($msg, $this->exchange, $this->routeKey, true);
            $this->channel->wait_for_pending_acks_returns();
        } catch (\Exception $e) {
            if ($this->isConnected()) {
                throw new \Exception($e->getMessage());
            }
            $this->close();
            return $this->publish($data);
        }

        return $flag;
    }

    public function consume($callback, $consumerTag = '') {
        $this->initConnection();
        try {
            if (empty($consumerTag)) {
                $consumerTag = self::CONSUMER_TAG . "_" . mb_substr(md5(time()), 0, 5);
            }
            $this->channel->basic_qos(null, 1, null);
            $this->channel->basic_consume($this->queueName, $consumerTag, false, false, false, false, $callback);

            while ($this->channel->is_consuming()) {
                $this->channel->wait();
            }
        } catch (\Exception $e) {
            if ($this->isConnected()) {
                $this->logInfo("rabbitmq操作失败:" . $e->getMessage());
                throw new \Exception($e->getMessage());
            }
            $this->close();
        }
    }

    public function isConnected() {
        if (!is_null($this->connection) && $this->connection->isConnected()) {
            $this->logInfo("连接正常");
            return true;
        }
        $this->logInfo("连接异常");
        return false;
    }

    public function setLogger($logger = null, $logDebug = true) {
        if ($logger) {
            $this->logger   = $logger;
            $this->logDebug = $logDebug;
        }

    }

    public function close() {
        $this->logInfo("断开连接");
        try{
            if(is_object($this->connection)
                && $this->connection instanceof AMQPStreamConnection){
                $this->connection->close();
            }
            if(is_object($this->channel) && $this->channel instanceof AMQPChannel){
                $this->channel->close();
            }
            $this->logInfo("rabbitmq关闭连接正常");
        }catch (\Exception $e){
            $this->logInfo("rabbitmq关闭连接异常");
        }

        $this->connection = null;
        $this->channel    = null;
    }

    public function __destruct() {
        $this->close();
        $this->logInfo("销毁");
    }

    public function logInfo($msg = '', $type = 'info') {
        if (!$this->logDebug) {
            return false;
        }
        if ($this->logger) {
            $this->logger->$type($msg);
        } else {
            $log_type   = [
                'info'  => 'E_USER_NOTICE',
                'error' => 'E_USER_ERROR',
            ];
            $error_type = isset($log_type[$type]) ? $log_type[$type] : E_USER_WARNING;
            trigger_error("YdRabbitMq".$msg, $error_type);
        }
    }

}
