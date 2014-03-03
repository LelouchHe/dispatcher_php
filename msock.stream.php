<?php

define('EINPROGRESS', 115);
define('EAGAIN', 11);

class MSock
{
    private $ip;
    private $port;
    private $sock;
    const MSOCK_OK = 0;
    const MSOCK_TIMEOUT = -1;
    const MSOCK_INFO_ERROR = -2;
    const MSOCK_NET_ERROR = -3;

    public function __construct()
    {
        $this->ip = -1;
        $this->port = -1;
        $this->sock = -1;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    // 会自动断开已有连接
    // 但不会自动connect
    public function set_info($ip, $port)
    {
        $this->disconnect();
        $this->ip = $ip;
        $this->port = $port;
    }

    public function get_info(&$ip, &$port)
    {
        $ip = $this->ip;
        $port = $this->port;
    }

    // 必须先set_info后connect才能进行send和recv
    // 不想为了便于使用把方法搞的很纠结
    public function connect($timeout_ms)
    {
        if ($this->ip == -1 || $this->port == -1 || $timeout_ms < 0)
        {
            return self::MSOCK_INFO_ERROR;
        }

        $this->sock = fsockopen($this->ip, $this->port, $errno, $errmsg, $timeout_ms / 1000);
        if ($this->sock === false)
        {
            $this->disconnect();
            return self::MSOCK_TIMEOUT;
        }

        return self::MSOCK_OK;
    }

    public function disconnect()
    {
        if ($this->sock != -1)
        {
            fclose($this->sock);
            $this->sock = -1;
        }
    }

    public function send($data, $len, $timeout_ms)
    {
        if ($this->sock == -1 || $timeout_ms < 0)
        {
            return self::MSOCK_INFO_ERROR;
        } 

        $sec = intval($timeout_ms / 1000);
        $usec = ($timeout_ms - $sec * 1000) * 1000;

        stream_set_timeout($this->sock, $sec, $usec);

        $ret = fwrite($this->sock, $data, $len);

        $info = stream_get_meta_data($this->sock);
        if ($info["timed_out"])
            return self::MSOCK_TIMEOUT;
        else if ($ret === false || $ret != $len)
            return self::MSOCK_NET_ERROR;

        return self::MSOCK_OK;
    }

    public function recv(&$data, $len, $timeout_ms)
    {
        if ($this->sock == -1 || $timeout_ms < 0)
        {
            return self::MSOCK_INFO_ERROR;
        } 

        $data = "";

        $sec = intval($timeout_ms / 1000);
        $usec = ($timeout_ms - $sec * 1000) * 1000;

        stream_set_timeout($this->sock, $sec, $usec);

        while (!feof($this->sock) && $len > strlen($data))
        {
            $buf = fread($this->sock, $len - strlen($data)); 
            $info = stream_get_meta_data($this->sock);
            if ($info["timed_out"])
                return self::MSOCK_TIMEOUT;
            else if ($buf === false)
                return self::MSOCK_NET_ERROR;

            $data .= $buf;
        }

        return self::MSOCK_OK;
    }
}

?>
