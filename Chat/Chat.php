<?php
namespace Chat;

class Chat
{
    // 服务端
    protected $master = null;

    //socket链接池
    protected $connectPool = [];

    //http升级websocket池
    protected $handPool = [];

    public function __construct($ip, $port)
    {
        echo "socket runing at $ip:$port";
        $this->startServer($ip, $port);
    }

    private function startServer($ip, $port)
    {
        $this->connectPool[] = $this->master = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        \socket_bind($this->master, $ip, $port);
        \socket_listen($this->master, 1000);

        // 阻塞 等待客户连接
        while (true) {
            $sockets = $this->connectPool;
            $write = $except = null;
            \socket_select($sockets, $write, $except, 60);

            //处理请求
            foreach ($sockets as $socket) {
                if ($socket == $this->master) {
                    $this->connectPool[] = $client = \socket_accept($this->master);
                    $keyArr = \array_keys($this->connectPool, $client);
                    $key = end($keyArr);
                    $this->handPool[$key] = false;
                } else {
                    $length = \socket_recv($socket, $buffer, 1024, 0);
                    if ($length < 1) {
                        $this->close($socket);
                    } else {
                        $key = \array_search($socket, $this->connectPool);
                        if ($this->handPool[$key] == false) {
                            $this->handShake($socket, $buffer, $key);
                        } else {
                            $message = $this->deFrame($buffer);
                            $message = $this->enFrame($message);
                            $this->send($message);
                        }
                    }
                }
            }
        }
    }

    // 客户端断开链接
    private function close($socket)
    {
        $key = \array_search($socket, $this->connectPool);
        unset($this->connectPool[$key]);
        unset($this->handPool[$key]);
        \socket_close($socket);
    }

    // http升级websocket
    private function handShake($socket, $buffer, $key)
    {
        if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $buffer, $match)) {
            $responseKey = base64_encode(sha1($match[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            $upgrade  = "HTTP/1.1 101 Switching Protocol\r\n" .
                "Upgrade: websocket\r\n" .
                "Connection: Upgrade\r\n" .
                "Sec-WebSocket-Accept: " . $responseKey . "\r\n\r\n";
            socket_write($socket, $upgrade, strlen($upgrade));
            $this->handPool[$key] = true;
        }
    }

    // 数据解帧
    private function deFrame($buffer)
    {
        $len = $masks = $data = $decoded = null;
        $len = ord($buffer[1]) & 127;

        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } elseif ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        return $decoded;
    }

    // 数据封帧
    private function enFrame($message)
    {
        $len = strlen($message);
        if ($len <= 125) {
            return "\x81" . chr($len) . $message;
        } else if ($len <= 65535) {
            return "\x81" . chr(126) . pack("n", $len) . $message;
        } else {
            return "\x81" . char(127) . pack("xxxxN", $len) . $message;
        }
    }

    // 群聊发送给所以客户端
    private function send($message)
    {
        foreach ($this->connectPool as $socket) {
            if ($socket != $this->master) {
                socket_write($socket, $message, strlen($message));
            }
        }
    }
}