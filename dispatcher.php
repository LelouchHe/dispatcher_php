<?php

require_once("msock.stream.php");

function host_cmp($a, $b)
{
    if ($a["info"]["req_num"] == 0)
        return -1;
    else if ($b["info"]["req_num"] == 0)
        return 1;
    else if ($a["info"]["slow_num"] / $a["info"]["req_num"] < $b["info"]["slow_num"] / $b["info"]["req_num"])
        return -1;
    else
        return 1;
    // return rand(0, 1);
}

class Dispatcher
{
    private $main;
    private $main_retry;
    private $back;
    private $back_retry;

    private $msock;
    private $host;

    private $name;

    private static function read_info($ip, $port, $name, $type)
    {
        $file = md5("$name-$type-$ip:$port");
        $filename = "/dev/shm/$file.info";
        if (file_exists($filename))
        {
            $handle = @fopen($filename, "r");
            flock($handle, LOCK_SH);
            $info = json_decode(fread($handle, 1024), true);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        else
        {
            $info = array();
            $info["start_time"] = time();
            $info["req_num"] = 0;
            $info["res_num"] = 0;
            $info["slow_num"] = 0;
            $info["fail_num"] = 0;
            $handle = @fopen($filename, "w");
            flock($handle, LOCK_EX);
            fwrite($handle, json_encode($info));
            flock($handle, LOCK_UN);
            fclose($handle);
		}

		return $info;
	}

    private static function read_infos(&$hosts, $name, $type)
    {
        foreach ($hosts as $index => &$host)
        {
            $host["info"] = self::read_info($host["ip"], $host["port"], $name, $type);
        }
    }

    private static function write_info($ip, $port, $name, $type, $info)
    {
        $file = md5("$name-$type-$ip:$port");
        $filename = "/dev/shm/$file.info";
        if (file_exists($filename))
        {
            $handle = @fopen($filename, "a+");
            flock($handle, LOCK_EX);
            $info_json = json_decode(fread($handle, 1024), true);

            // 检查间隔为1min
            if (time() - $info_json["start_time"] > 60)
            {
                $info_json["start_time"] = time();
                $info_json["req_num"] = $info["req_num"] - $info_json["req_num"];
                $info_json["res_num"] = $info["res_num"] - $info_json["res_num"];
                $info_json["slow_num"] = $info["slow_num"] - $info_json["slow_num"];
                $info_json["fail_num"] = $info["fail_num"] - $info_json["fail_num"];
            }
            else
            {
                $info_json = $info;
            }

			ftruncate($handle, 0);
            fwrite($handle, json_encode($info_json));
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function write_infos($hosts, $name, $type)
    {
        foreach ($hosts as $index => $host)
        {
            self::write_info($host["ip"], $host["port"], $name, $type, $host["info"]);
        }
    }


    public function __construct($name)
    {
        $this->main = array();
        $this->back = array();
        $this->name = $name;
        $this->msock = new MSock;
    }

    public function __destruct()
    {
        self::write_infos($this->main, $this->name, "main");
        self::write_infos($this->back, $this->name, "back");
    }

    private static function parse_hosts($hosts_str, &$hosts)
    {
        $hosts_str = explode(';', $hosts_str);
        foreach ($hosts_str as $host_str)
        {
            $host = explode(':', $host_str);
            $machine = array();
            $machine["ip"] = $host[0];
            $machine["port"] = $host[1];
            $hosts[] = $machine; 
        }
    }

    // ip:port;ip:port
    public function set_main($hosts_str = "", $retry_time = 0)
    {
        if (empty($hosts_str))
            return false;

        self::parse_hosts($hosts_str, $this->main);
        self::read_infos($this->main, $this->name, "main");
        usort($this->main, host_cmp);
        $this->main_retry = $retry_time;
        if ($this->main_retry > count($this->main) || $this->main_retry <= 0)
            $this->main_retry = count($this->main);
        return true;
    }

    public function set_back($hosts_str = "", $retry_time = 0)
    {
        if (empty($hosts_str))
            return false;

        self::parse_hosts($hosts_str, $this->back);
        self::read_infos($this->back, $this->name, "back");
        $this->back_retry = $retry_time;
        if ($this->back_retry > count($this->back) || $this->back_retry <= 0)
            $this->back_retry = count($this->back);
        return true;
    }

    public function send($data, $len, $timeout_ms)
    {
        if (empty($this->main))
            return false;

        $start_time = intval(microtime(true) * 1000);

        $i = 0;
        while ($i < $this->main_retry)
        {
            $this->host = &$this->main[$i];
            $this->host["info"]["req_num"]++;
            $this->msock->set_info($this->host["ip"], $this->host["port"]);

            $ret = $this->msock->connect(100);
            if ($ret == MSock::MSOCK_OK)
                break;
            else
                $this->host["info"]["fail_num"]++;

            $i++;
        }

        if ($ret != MSock::MSOCK_OK)
        {
            if (empty($this->back))
                return false;

            $i = 0;
            while ($i < $this->back_retry)
            {
                $this->host = &$this->back[array_rand($this->back, 1)];
                $this->host["info"]["req_num"]++;
                $this->msock->set_info($this->host["ip"], $this->host["port"]);
                $ret = $this->msock->connect(100);

                if ($ret == MSock::MSOCK_OK)
                    break;
                else if ($ret != MSock::MSOCK_OK)
                    $this->host["info"]["fail_num"]++;

                $i++;
            }
        }

        if ($ret != MSock::MSOCK_OK)
            return false;


        $elapsed_time = intval(microtime(true) * 1000) - $start_time;
        if ($elapsed_time > $timeout_ms)
        {
            $this->msock->disconnect();
            return false;
        }

        $ret = $this->msock->send($data, $len, $timeout_ms - $elapsed_time);

        return $ret == MSock::MSOCK_OK;
    }

    public function recv(&$data, $len, $timeout_ms)
    {
        $ret = $this->msock->recv($data, $len, $timeout_ms);

        if ($ret != MSock::MSOCK_OK)
            $this->host["info"]["slow_num"]++;
        else
            $this->host["info"]["res_num"]++;

        return $ret == MSock::MSOCK_OK;
    }
}

?>

