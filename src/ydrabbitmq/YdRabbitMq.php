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

class YdRabbitMqConn extends AMQPStreamConnection {

    public function __destruct() {
        //如果有连接，自动关闭
        if($this->isConnected()) {
          $this->close();
        }
    }

}

class YdRabbitMq {
    const  MAX_ATTEMPTS = 3;
    const  CONSUMER_TAG = "consumer";
    protected        $config;
    protected        $options;
    protected        $connection         = null;
    protected        $channel            = null;
    protected        $queueName          = '';      //队列名称
    protected        $exchange           = '';      //交换机名称
    protected        $routeKey           = '';      //路由名称
    protected static $logger             = null;
    protected static $connectionAttempts = 0;
    protected static $logDebug           = false;
    static public    $conns              = [];
    static public    $channels           = [];
    //发送任务时的重试次数, 默认100, 同时每次重试会休眠100毫秒，最终默认异常休眠总时间为 $replyTotalPublish * 100 / 1000 = 10 秒
    static public    $replyTotalPublish  = 100;

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
        $key = isset($params[1]) ? self::md5sum($params[1]) : 'default';
        if (!isset(self::$_objs[$key])) {
            $class             = new \ReflectionClass(__CLASS__);
            self::$_objs[$key] = $class->newInstanceArgs($params);
        }
        return self::$_objs[$key];
    }

    public static function md5sum($params) {
        return md5(json_encode($params));
    }

    //发送任务时的重试次数, 默认100, 同时每次重试会休眠100毫秒，最终默认异常休眠总时间为 $replyTotalPublish * 100 / 1000 = 10 秒
    public static function setReplayTotalPublish($replyTotalPublish = 100) {
        self::$replyTotalPublish = $replyTotalPublish;
    }
    public static function getReplayTotalPublish() {
        return self::$replyTotalPublish;
    }

    public static function connect($config, $options) {
        $md5KeyConn = self::md5sum($config);
        if (!isset(self::$conns[$md5KeyConn])) {
            try {
                $Connection               = new YdRabbitMqConn(
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
                self::$conns[$md5KeyConn] = $Connection;
            } catch (\Exception $e) {
                self::logInfo("rabbitmq连接异常" . var_export($e->getMessage(), 1));
                while (self::$connectionAttempts < self::MAX_ATTEMPTS && !self::isConnected($md5KeyConn)) {
                    self::close($md5KeyConn);
                    self::logInfo("重试连接第" . (self::$connectionAttempts + 1) . "次");
                    self::$connectionAttempts++;
                    self::connect($config, $options);
                }
                if (!self::isConnected($md5KeyConn)) {
                    //发送告警??? todo
                    self::logInfo("连接三次失败" . var_export($e->getMessage(), 1));
                    throw $e;
                }
            }
        }
        return self::$conns[$md5KeyConn];
    }

    /**
     * $cfg 配置
     * $options 可选项
     * $queue 队列
     * $useType 使用类型，publish/consumer，不同的使用方式，channel不共用
     */
    public static function getChannel($cfg, $options, $queue, $channelUseType) {
        $md5KeyConn = self::md5sum($cfg);
        $md5KeyChannel = self::md5sum([$cfg, $queue, ['use_type' => $channelUseType]]);
        //channel或连接不存在
        if(!isset(self::$channels[$md5KeyConn]) || !isset(self::$channels[$md5KeyConn][$md5KeyChannel])) {
            try {
                $conn = self::connect($cfg, $options);
                $channel = $conn->channel();
                $channel->set_ack_handler(
                    function($message) {
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
                if(!isset(self::$channels[$md5KeyConn])) self::$channels[$md5KeyConn] = [];
                self::$channels[$md5KeyConn][$md5KeyChannel] = $channel;
            } catch (\PhpAmqpLib\Exception\AMQPConnectionClosedException $e) {
                self::logInfo("getChannel AMQPConnectionClosedException:" . $e->getMessage());
                unset(self::$channels[$md5KeyConn], self::$conns[$md5KeyConn]);
                throw $e;
            } catch (\Exception $e) {
                self::logInfo("getChannel Exception:" . $e->getMessage());
                throw  $e;
            }
        }
        return self::$channels[$md5KeyConn][$md5KeyChannel];
    }

    public function publish($data) {
        $channelUseType = 'publish';
        $message = $data;
        if (!is_array($message) && !is_string($message)) {
            return false;
        }
        if (is_array($message)) {
            $message = json_encode($message);
        }
        //默认设置重试200次，每次休眠100毫秒

        $usleep = 100 * 1000;   //每次重试，休眠100毫秒
        $i = self::$replyTotalPublish;
        $flag    = true;
        while($i--) {
            try {
                $channel = self::getChannel($this->config, $this->options, $this->queueName, $channelUseType);
                $msg     = new AMQPMessage($message);
                $channel->basic_publish($msg, $this->exchange, $this->routeKey, true);
                $channel->wait_for_pending_acks_returns();
                $flag = true;
                break;
            } catch (\PhpAmqpLib\Exception\AMQPConnectionClosedException $e) {
                $flag = false;
                //重试，最后一次抛异常
                self::logInfo("RabbitMQ发送数据失败，共重试 ".self::$replyTotalPublish." 次，已重试 {$i} 次，exchange[{$this->exchange}] route[{$this->routeKey}] queue[{$this->queueName}] body: ".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."   AMQPConnectionClosedException: ".$e->getMessage()."    trace: ".$e->getTraceAsString());
                usleep($usleep);
                if($i > 0) {
                    continue;
                }
                // 超过了重试次数
                throw $e;
            } catch (\Exception $e) {
                $flag = false;
                self::logInfo("RabbitMQ发送数据失败，共重试 ".self::$replyTotalPublish." 次，已重试 {$i} 次，exchange[{$this->exchange}] route[{$this->routeKey}] queue[{$this->queueName}] body: ".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."   Exception: ".$e->getMessage()."    trace: ".$e->getTraceAsString());
                $connMd5Key = self::md5sum($this->config);
                $channelMd5Key = self::md5sum([$this->config, $this->queueName, ['use_type' => $channelUseType]]);
                //连接正常时，抛出异常
                if(self::isConnected($connMd5Key)) {
                    throw $e;
                }
                //连接不正常时，关闭连接
                self::close($connMd5Key, $channelMd5Key);
                usleep($usleep);
                if($i > 0) {
                    continue;
                }
                // 超过了重试次数
                throw $e;
            }
        }

        return $flag;
    }

    public function batchPublish($messages) {
        if(!is_array($messages)) return false;
        $channelUseType = 'publish';
        //默认设置重试200次，每次休眠100毫秒
        $usleep = 100 * 1000;   //每次重试，休眠100毫秒
        $i = self::$replyTotalPublish;
        $flag    = true;
        while($i--) {
            try {
                $channel = self::getChannel($this->config, $this->options, $this->queueName, $channelUseType);
                foreach($messages as $message) {
                    if (!is_array($message) && !is_string($message)) continue;
                    if (is_array($message)) $message = json_encode($message);
                    $msg = new AMQPMessage($message);
                    $channel->basic_publish($msg, $this->exchange, $this->routeKey, true);
                }
                $channel->wait_for_pending_acks_returns();
                $flag = true;
                break;
            } catch (\PhpAmqpLib\Exception\AMQPConnectionClosedException $e) {
                $flag = false;
                //重试，最后一次抛异常
                self::logInfo("RabbitMQ发送数据失败，共重试 ".self::$replyTotalPublish." 次，已重试 {$i} 次，exchange[{$this->exchange}] route[{$this->routeKey}] queue[{$this->queueName}] body: ".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."   AMQPConnectionClosedException: ".$e->getMessage()."    trace: ".$e->getTraceAsString());
                usleep($usleep);
                if($i > 0) {
                    continue;
                }
                // 超过了重试次数
                throw $e;
            } catch (\Exception $e) {
                $flag = false;
                self::logInfo("RabbitMQ发送数据失败，共重试 ".self::$replyTotalPublish." 次，已重试 {$i} 次，exchange[{$this->exchange}] route[{$this->routeKey}] queue[{$this->queueName}] body: ".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."   Exception: ".$e->getMessage()."    trace: ".$e->getTraceAsString());
                $connMd5Key = self::md5sum($this->config);
                $channelMd5Key = self::md5sum([$this->config, $this->queueName, ['use_type' => $channelUseType]]);
                //连接正常时，抛出异常
                if(self::isConnected($connMd5Key)) {
                    throw $e;
                }
                //连接不正常时，关闭连接
                self::close($connMd5Key, $channelMd5Key);
                usleep($usleep);
                if($i > 0) {
                    continue;
                }
                // 超过了重试次数
                throw $e;
            }
        }

        return $flag;
    }

    public function consume($callback, $consumerTag = '', $prefetch_count = 1) {
        $channelUseType = 'consumer';
        //消费者没有自动重连，需要业务中自己处理
        $channel = self::getChannel($this->config, $this->options, $this->queueName, $channelUseType);
        try {
            if(empty($consumerTag)) {
                $consumerTag = self::CONSUMER_TAG . "_" . mb_substr(md5(time()), 0, 5);
            }
            $channel->basic_qos(null, $prefetch_count, null);
            $channel->basic_consume($this->queueName, $consumerTag, false, false, false, false, $callback);

            while($channel->is_consuming()) {
                $channel->wait();
            }
        } catch(\Exception $e) {
            $connMd5Key = self::md5sum($this->config);
            if(self::isConnected($connMd5Key)) {
                self::logInfo("rabbitmq操作失败:" . $e->getMessage());
                throw $e;
            }
            $channelMd5Key = self::md5sum([$this->config, $this->queueName, ['use_type' => $channelUseType]]);
            self::close($connMd5Key, $channelMd5Key);
        }
    }

    public function batchGet($limit = 200) {
        $channelUseType = 'batchGet';
        $channel      = self::getChannel($this->config, $this->options, $this->queueName, $channelUseType);
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
            $connMd5Key = self::md5sum($this->config);
            if (self::isConnected($connMd5Key)) {
                self::logInfo("rabbitmq操作失败:" . $e->getMessage());
                throw $e;
            }
            $channelMd5Key = self::md5sum([$this->config, $this->queueName, ['use_type' => $channelUseType]]);
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

    //$connMd5Key 必须指明，不能一次性关闭所有连接；如果连接类不主动关闭，则连接类在销毁时会自动关闭
    //$channelMd5Key 操作无异常时，推荐指明要关闭的连接；不指明，会关闭此连接下所有的channel, 关闭全部channel，只应该发生在在连接异常时
    //关闭一个连接及其下的channel，不得影响其他的连接及其channel
    public static function close($connMd5Key, $channelMd5Key = '') {
        self::logInfo("断开连接");
        $connMd5Key = trim($connMd5Key);
        $channelMd5Key = trim($channelMd5Key);
        try {
            //连接不存在，直接返回
            if(!isset(self::$conns[$connMd5Key])) {
                self::logInfo("RabbitMQ连接[{$connMd5Key}]不存在，无法关闭");
                return false;
            }
            //channel不存在，直接返回
            if($channelMd5Key && !(isset(self::$channels[$connMd5Key]) && isset(self::$channels[$connMd5Key][$channelMd5Key]))) {
                self::logInfo("RabbitMQ连接[{$connMd5Key}]存在，但channel[{$channelMd5Key}]不存在，无法关闭channel");
                return false;
            }
            //关闭指定channel
            if($channelMd5Key && isset(self::$channels[$connMd5Key][$channelMd5Key])) {
                self::$channels[$connMd5Key][$channelMd5Key]->close();
                self::logInfo("关闭RabbitMQ连接[{$connMd5Key}]下指定的channel[{$channelMd5Key}]成功");
                unset(self::$channels[$connMd5Key][$channelMd5Key]);
                return true;
            }
            //关闭连接下所有的channel
            if(isset(self::$channels[$connMd5Key]) && is_array(self::$channels[$connMd5Key])) {
                foreach(self::$channels[$connMd5Key] as $md5Key => $channel) {
                    if(self::$channels[$channelMd5Key] instanceof AMQPChannel) {
                        self::$channels[$connMd5Key][$md5Key]->close();
                        self::logInfo("关闭RabbitMQ连接[{$connMd5Key}]下channel[{$channelMd5Key}]成功");
                    } else {
                        self::logInfo("RabbitMQ连接[{$connMd5Key}]下channel[{$channelMd5Key}]类型不正确，将直接销毁变量");
                    }
                    unset(self::$channels[$connMd5Key][$md5Key]);
                }
            } else {
                self::logInfo("RabbitMQ连接[{$connMd5Key}]下没有channel");
            }
            //关闭连接
            //if(self::$conns[$connMd5Key] instanceof AMQPStreamConnection) {
            if(self::$conns[$connMd5Key] instanceof YdRabbitMqConn) {
                self::$conns[$connMd5Key]->close();
                self::logInfo("关闭RabbitMQ连接[{$connMd5Key}]成功");
            } else {
                self::logInfo("RabbitMQ连接[{$connMd5Key}]类型不正确，将直接销毁变量");
            }
            unset(self::$conns[$connMd5Key]);
            return true;
        } catch (\Exception $e) {
            self::logInfo("rabbitmq关闭连接异常");
            return false;
        }
    }

    public function __destruct() {
        //连接类销毁时会自动关闭
        //self::close();
        //self::logInfo("销毁");
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
