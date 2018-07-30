<?php
include 'config.php';

class WebSocket {
    protected $_redis;
    protected $_mysql;
    protected $_fd_prefix    = 'aq:ws:fd:';
    protected $_bs_prefix    = 'aq:ws:bs:';
    protected $_token_prefix = 'aq:ws:token:';
    protected $_ws_serv;

    public function __construct() {
        $this->clear_redis();
        $this->_ws_serv = $ws_server = new swoole_websocket_server('0.0.0.0', 9527);
        $ws_server->on('open', [$this, 'ws_open']);
        $ws_server->on('message', [$this, 'ws_message']);
        $ws_server->on('close', [$this, 'ws_close']);
        $ws_server->on('workerstart', function ($serv, $id) {
            //TODO
        });
        $ws_server->on('task', [$this, 'task']);
        $ws_server->on('finish', [$this, 'task']);
        $ws_server->set([
            'worker_num'               => 2,
            'task_worker_num'          => 8,
            'daemonize'                => 1,
            'heartbeat_check_interval' => 60, //遍历时间
            'heartbeat_idle_time'      => 100, //超时时间
            'log_file'                 => SWOOLE_LOG_FILE
        ]);

        $sub_http_server = $ws_server->listen('127.0.0.1', 9528, SWOOLE_SOCK_TCP);
        $sub_http_server->set([
            'open_http_protocol' => true
        ]);
        $sub_http_server->on('request', function ($request, $response) use ($ws_server) {
            $response->end('ok');//直接返回
            $post = json_decode($request->rawContent(), true);
            if (!isset($post['base_id'], $post['msg_type'], $post['msg_content'])) {
                return;
            }
            $task_data = [
                'task_name'   => 'push_msg',
                'fd'          => $request->fd,
                'base_id'     => $post['base_id'],
                'msg_type'    => $post['msg_type'],
                'msg_content' => $post['msg_content'],
            ];
            $ws_server->task($task_data);
        });


        $ws_server->start();
    }

    //websocket server 相关回调
    public function ws_open(swoole_websocket_server $serv, $request) {
        if (empty($request->get['token'])) {
            $serv->close($request->fd);
            return;
        }
        $task_data = [
            'task_name' => 'ws_open',
            'fd'        => $request->fd,
            'token'     => $request->get['token'],
        ];
        $serv->task($task_data);
    }

    public function ws_message(swoole_websocket_server $serv, $frame) {
        // $serv->push($frame->fd, json_encode($frame));
    }

    public function ws_close(swoole_websocket_server $serv, $fd) {
        $task_data = [
            'task_name' => 'ws_close',
            'fd'        => $fd,
        ];
        $serv->task($task_data);
    }


    //task 子进程
    public function task($serv, $task_id, $src_worker_id, $task_data) {
        if (empty($task_data['task_name'])) {
            return;
        }
        //处理登录
        if ($task_data['task_name'] == 'ws_open') {
            $res = $this->handle_login($task_data['token'], $task_data['fd']);
            if (!$res) {
                $this->_redis = null;
                $serv->close($task_data['fd']);
                return;
            }
        }

        //处理关闭连接
        if ($task_data['task_name'] == 'ws_close') {
            $this->handle_close($task_data['fd']);
            $this->_redis = null;
        }

        //HTTP调用推送功能
        if ($task_data['task_name'] == 'push_msg') {
            if ($task_data['base_id']) {                //单基地推送
                $bs_fds = $this->getRedis()->sMembers($this->_bs_prefix . $task_data['base_id']);//获取所有连接
                if (!$bs_fds) {
                    $this->_redis = null;
                    return;
                }
                foreach ($bs_fds as $fd) {
                    if (!isset($serv->connection_info($fd)['websocket_status'])) { //过滤其他协议
                        continue;
                    }
                    $serv->push($fd, json_encode([
                        'msg_type'    => $task_data['msg_type'],
                        'msg_content' => $task_data['msg_content'],
                    ]));
                }
            } else { //广播
                foreach ($serv->connections as $fd) {
                    if (!isset($serv->connection_info($fd)['websocket_status'])) { //过滤其他协议
                        continue;
                    }
                    $serv->push($fd, json_encode([
                        'msg_type'    => $task_data['msg_type'],
                        'msg_content' => $task_data['msg_content'],
                    ]));
                }
            }
            $this->_redis = null;
        }

        $this->_redis = null;
    }

    public function finish($serv, $task_id, $data) {

    }

    /**
     * 获取 redis 单例
     * @return Redis
     */
    public function getRedis() {
        if (!$this->_redis) {
            $this->_redis = new Redis();
            $this->_redis->connect(REDIS_HOST, REDIS_PORT);
            REDIS_PWD && $this->_redis->auth(REDIS_PWD);
        }
        return $this->_redis;
    }

    /**
     * 获取 PDO 单例
     * @return PDO
     */
    public function getMysql() {
        if (!$this->_mysql) {
            $dsn          = 'mysql:dbname=' . DB_NAME . ';host=' . DB_HOST;
            $user         = DB_USER;
            $pwd          = DB_PWD;
            $this->_mysql = new PDO($dsn, $user, $pwd);
        }
        return $this->_mysql;
    }

    /**
     * 处理登录
     * @param $token
     * @param $fd
     * @return bool|string
     */
    public function handle_login($token, $fd) {
        $login = $this->getRedis()->get($token);//是否登录
        if (!$login) {
            return false;
        }
        $login = unserialize($login);
        if (empty($login['base_id'])) {
            return false;
        }
        $this->getRedis()->multi(); //开启事务
        $this->getRedis()->set($this->_fd_prefix . $fd, $login['base_id']); //记录 fd 所在基地
        //记录fd所属base_id
        $this->getRedis()->sAdd($this->_bs_prefix . $login['base_id'], $fd); //保存fd到指定基地集合
        $res = $this->getRedis()->exec(); //提交事务
        if (!$res) {
            return false;
        }
        return true;
    }

    /**
     * 处理连接关闭
     * @param $fd
     */
    public function handle_close($fd) {
        $base_id = $this->getRedis()->get($this->_fd_prefix . $fd); //获取fd缓存的base_id信息
        $this->getRedis()->del($this->_fd_prefix . $fd);//删除该缓存信息
        if (!$base_id) {
            return;
        }
        $this->getRedis()->sRem($this->_bs_prefix . $base_id, $fd); //从对应的base中删除该连接信息
    }

    /**
     * 服务器刚启动清空与ws连接信息相关的 redis 信息
     */
    public function clear_redis() {
        $keys = $this->getRedis()->keys('aq:ws:*');
        if (!$keys) {
            return;
        }
        foreach ($keys as $val) {
            $this->getRedis()->del($val);
        }
    }

}

$serv = new WebSocket();
