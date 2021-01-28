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
    protected        $config;
    protected        $options;
    protected        $connection         = null;
    protected        $channel            = null;
    protected        $queueName          = '';   //队列名称
    protected        $exchange           = '';    //交换机名称
    protected        $routeKey           = '';    //路由名称
    protected static $logger             = null;
    protected static $connectionAttempts = 0;
    protected static $logDebug           = false;
    static public    $conns              = [];
    static public    $channels           = [];

    protected      $defaultsConfig  = [
        'host'     => '127.0.0.1',
        'port'     => 5672,
        'username' => 'guest',
        'password' => 'guest',
        'vhost'    => '/'
    ];
    protected      $defaultsOptions = [
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
    private static $_objs;

    public function __construct($connectionConfig = [], $queueConf = [], $options = []) {
        $this->config    = array_merge($this->defaultsConfig, $connectionConfig);
        $this->options   = array_merge($this->defaultsOptions, $options);
        $this->exchange  = $queueConf['exchange'];
        $this->routeKey  = $queueConf['routeKey'];
        $this->queueName = $queueConf['queueName'];
    }

    static public function obj(...$params) {
        $key = isset($params[1]) ? md5(json_encode($params[1])) : 'default';
        if (!isset(self::$_objs[$key])) {
            $class             = new \ReflectionClass(__CLASS__);
            self::$_objs[$key] = $class->newInstanceArgs($params);
        }
        return self::$_objs[$key];
    }

    public static function connect($config, $options) {
        $connMd5Key = md5(json_encode($config));
        if (!isset(self::$conns[$connMd5Key])) {
            try {
                $Connection               = new AMQPStreamConnection(
                    $config['host'],
                    $config['port'],
                    $config['username'],
                    $config['password'],
                    $config['vhost'],
                    $options['insist'],
                    $options['login_method'],
                    $options['login_response'],
                    $options['locale'],
                    $options['connection_timeout'],
                    $options['read_write_timeout'],
                    $options['context'],
                    $options['keepalive'],
                    $options['heartbeat']
                );
                self::$conns[$connMd5Key] = $Connection;
            } catch (\Exception $e) {
                var_dump(self::$connectionAttempts);
                self::logInfo("rabbitmq连接异常" . var_export($e->getMessage(), 1));
                var_dump(self::$connectionAttempts < self::MAX_ATTEMPTS);
                while (self::$connectionAttempts < self::MAX_ATTEMPTS && !self::isConnected($connMd5Key)) {
                    self::close($connMd5Key);
                    self::logInfo("重试连接第" . (self::$connectionAttempts + 1) . "次");
                    self::$connectionAttempts++;
                    self::connect($config, $options);
                }
                if (!self::isConnected($connMd5Key)) {
                    //发送告警??? todo
                    self::logInfo("连接三次失败" . var_export($e->getMessage(), 1));
                    throw $e;
                }
            }
        }
        return self::$conns[$connMd5Key];
    }

    public static function getChannel($cfg, $options, $queue) {
        $md5Key = md5(json_encode([$cfg, $queue]));
        if (!isset(self::$channels[$md5Key])) {
            $conn = self::connect($cfg, $options);
            try {
                $channel = $conn->channel();
                $channel->set_ack_handler(
                    function ($message) {

                        self::logInfo("Message ack with content" . $message->getBody());
                    }
                );
                $channel->set_nack_handler(
                    function ($message) {
                        self::logInfo("Message nack with content" . $message->getBody());
                    }
                );
                $channel->set_return_listener(
                    function ($replyCode, $replyText, $exchange, $routingKey, $message) {
                        self::logInfo("投递异常返回数据set_return_listener");
                        self::logInfo(var_export($replyCode, 1));
                        self::logInfo(var_export($replyText, 1));
                        self::logInfo(var_export($exchange, 1));
                        self::logInfo(var_export($routingKey, 1));
                        self::logInfo(var_export($message->getBody(), 1));
                    }
                );
                $channel->confirm_select();
                self::$channels[$md5Key] = $channel;
            } catch (\Exception $e) {
                self::logInfo("getChannel Exception:" . $e->getMessage());
                throw  $e;
            }
        }
        return self::$channels[$md5Key];
    }

    public function publish($data) {
        $message = $data;
        if (!is_array($message) && !is_string($message)) {
            return false;
        }
        if (is_array($message)) {
            $message = json_encode($message);
        }
        $channel = self::getChannel($this->config, $this->options, $this->queueName);
        $flag    = true;
        $msg     = new AMQPMessage($message);
        try {
            $channel->basic_publish($msg, $this->exchange, $this->routeKey, true);
            $channel->wait_for_pending_acks_returns();
        } catch (\Exception $e) {
            $connMd5Key = md5(json_encode($this->config));
            if (self::isConnected($connMd5Key)) {
                var_dump(1);
                throw $e;
            }
            $channelMd5Key = md5(json_encode([$this->config, $this->queueName]));
            self::close($connMd5Key, $channelMd5Key);
            return $this->publish($data);
        }

        return $flag;
    }

    public function consume($callback, $consumerTag = '', $prefetch_count = 1) {
        $channel = self::getChannel($this->config, $this->options, $this->queueName);
        try {
            if (empty($consumerTag)) {
                $consumerTag = self::CONSUMER_TAG . "_" . mb_substr(md5(time()), 0, 5);
            }
            $channel->basic_qos(null, $prefetch_count, null);
            $channel->basic_consume($this->queueName, $consumerTag, false, false, false, false, $callback);

            while ($channel->is_consuming()) {
                $channel->wait();
            }
        } catch (\Exception $e) {
            $connMd5Key = md5(json_encode($this->config));
            if (self::isConnected($connMd5Key)) {
                self::logInfo("rabbitmq操作失败:" . $e->getMessage());
                throw $e;
            }
            $channelMd5Key = md5(json_encode([$this->config, $this->queueName]));
            self::close($connMd5Key, $channelMd5Key);
        }
    }

    public function batchGet($limit = 200) {
        $channel      = self::getChannel($this->config, $this->options, $this->queueName);
        $messageCount = $channel->queue_declare($this->queueName, false, true, false, false);
        if (!$messageCount) {
            return [];
        }
        try {
            $i    = 0;
            $max  = $limit < 200 ? $limit : 200;
            $data = [];
            while ($i < $messageCount[1] && $i < $max) {
                $msg = $channel->basic_get($this->queueName);
                $channel->basic_ack($msg->delivery_info['delivery_tag']);
                $data[] = json_decode($msg->body, true);
                $i++;
            }
        } catch (\Exception $e) {
            $connMd5Key = md5(json_encode($this->config));
            if (self::isConnected($connMd5Key)) {
                self::logInfo("rabbitmq操作失败:" . $e->getMessage());
                throw $e;
            }
            $channelMd5Key = md5(json_encode([$this->config, $this->queueName]));
            self::close($connMd5Key, $channelMd5Key);
        }

        return $data;
    }

    public static function isConnected($connMd5Key) {
        if (isset(self::$conns[$connMd5Key]) &&
            !is_null(self::$conns[$connMd5Key])
            && self::$conns[$connMd5Key]->isConnected()) {
            self::logInfo("连接正常");
            return true;
        }
        self::logInfo("连接异常");
        return false;
    }

    public function setLogger($logger = null, $logDebug = true) {
        if ($logger) {
            self::$logger   = $logger;
            self::$logDebug = $logDebug;
        }
    }

    public function setQueueConf($queueConf = []) {
        if (isset($queueConf['exchange']) && !empty($queueConf['exchange'])) {
            $this->exchange = $queueConf['exchange'];
        }
        if (isset($queueConf['routeKey']) && !empty($queueConf['routeKey'])) {
            $this->routeKey = $queueConf['routeKey'];
        }
        if (isset($queueConf['queueName']) && !empty($queueConf['queueName'])) {
            $this->queueName = $queueConf['queueName'];
        }
    }

    public static function close($connMd5Key = '', $channelMd5Key = '') {
        self::logInfo("断开连接");
        try {
            if ($channelMd5Key
                && isset(self::$channels[$channelMd5Key])
                && is_object(self::$channels[$channelMd5Key])
                && self::$channels[$channelMd5Key] instanceof AMQPChannel) {
                self::$channels[$channelMd5Key]->close();
                unset(self::$channels[$channelMd5Key]);
            }
            if ($connMd5Key
                && isset(self::$conns[$connMd5Key])
                && is_object(self::$conns[$connMd5Key])
                && self::$conns[$connMd5Key] instanceof AMQPStreamConnection) {
                self::$conns[$connMd5Key]->close();
                unset(self::$conns[$connMd5Key]);
            }
            if (!$connMd5Key && !$channelMd5Key) {
                if (count(self::$channels)) {
                    foreach (self::$channels as $k => $v) {
                        if (is_object($v)
                            && $v instanceof AMQPChannel) {
                            $v->close();
                        }
                        unset(self::$channels[$k]);
                    }
                }
                if (count(self::$conns)) {
                    foreach (self::$conns as $k => $v) {
                        if (is_object($v)
                            && $v instanceof AMQPStreamConnection) {
                            $v->close();
                        }
                        unset(self::$conns[$k]);
                    }
                }
            }
            self::logInfo("rabbitmq关闭连接正常");
        } catch (\Exception $e) {
            self::logInfo("rabbitmq关闭连接异常");
        }
    }

    public function __destruct() {
        self::close();
        self::logInfo("销毁");
    }


    public static function logInfo($msg = '', $type = 'info') {
        if (!self::$logDebug) {
            return false;
        }
        if (self::$logger) {
            $logger = self::$logger;
            $logger->$type($msg);
        } else {
            $log_type   = [
                'info'  => 'E_USER_NOTICE',
                'error' => 'E_USER_ERROR',
            ];
            $error_type = isset($log_type[$type]) ? $log_type[$type] : E_USER_WARNING;
            trigger_error("YdRabbitMq" . $msg, $error_type);
        }
    }

}
