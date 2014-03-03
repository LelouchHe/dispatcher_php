<?php

require_once("dispatcher.php");

class DAHelper
{
    var $name;
    var $dp;
    var $cmd;
	var $seqid;
    const COMMON_HEAD_SIZE = 32;
    const CMD_HEAD_SIZE = 8;
    const MAGIC = 0xa3b9f14d;

    public function __construct($name, $cmd)
    {
        $this->name = $name;
        $this->dp = new Dispatcher($this->name);
        $this->cmd = $cmd;
		$this->seqid = 0;
    }

    public function __destruct()
    {
    }

    // "ip:host;ip:host"
	public function set_main($main_hosts, $retry_times = 0)
	{
        return $this->dp->set_main($main_hosts);
	}

    // "ip:host;ip:host"
	public function set_back($back_hosts, $retry_times = 0)
	{
        return $this->dp->set_back($back_hosts);
	}

    public function send($content, $size, $timeout_ms)
    {
        $start_time = intval(microtime(true) * 1000);

		$common_head["version"] = 2;
		$common_head["flag"] = 0;
		$common_head["cbit"] = 0;
		$common_head["pad"] = 0;
		$this->seqid = self::gen_seqid($this->name, $this->cmd);
		$common_head["seqid"] = $this->seqid;
		$common_head["asyn_seqid"] = 0;
		$common_head["bodylen"] = self::CMD_HEAD_SIZE + $size;

        $now = gettimeofday();
        $timestamp = $now["sec"] * 1000000 + $now["usec"];
        $common_head["time_high"] = $timestamp >> 32;
        $common_head["time_low"] = $timestamp & 0xFFFFFFFF;

		$common_head["magic"] = self::MAGIC;
		$common_head["reserved"] = 0;

		$cmd_head["cmd"] = $this->cmd & 0xFFFFFFF;
		$cmd_head["len"] = $size;
        $msg = pack("C4L7", $common_head["version"], $common_head["flag"], $common_head["cbit"], $common_head["pad"],
				$common_head["seqid"], $common_head["asyn_seqid"], $common_head["bodylen"], $common_head["time_high"],
				$common_head["time_low"], $common_head["magic"], $common_head["reserved"]);
        $msg .= pack("L2", $cmd_head["cmd"], $cmd_head["len"]);
        $msg .= $content;

        $len = $common_head["bodylen"] + self::COMMON_HEAD_SIZE;

        $now = intval(microtime(true) * 1000);
        return $this->dp->send($msg, $len, $timeout_ms - ($now - $start_time));
    }

    public function recv(&$content, &$size, $timeout_ms)
    {
        $start_time = intval(microtime(true) * 1000);

        $ret = $this->dp->recv($common_head_str, self::COMMON_HEAD_SIZE, $timeout_ms);
        if ($ret == false)
            return false;
		
        $common_head = unpack("Cversion/Cflag/Ccbit/Cpad/Lseqid/Lasyn_seqid/Lbodylen/Ltime_high/Ltime_low/Lmagic/Lreserved", $common_head_str);

        if ($common_head["seqid"] != $this->seqid || $common_head["magic"] != self::MAGIC)
            return false;

        $now = intval(microtime(true) * 1000);
        $ret = $this->dp->recv($cmd_head_str, self::CMD_HEAD_SIZE, $timeout_ms - ($now - $start_time));
        if ($ret == false)
            return false;


        $cmd_head = unpack("Lcmd/Llen", $cmd_head_str);
        $cmd = $cmd_head["cmd"] & 0xFFFFFFF;
        $size = $cmd_head["len"];

        $now = intval(microtime(true) * 1000);
        return $this->dp->recv($content, $size, $timeout_ms - ($now - $start_time));
    }

	private static function gen_seqid($name, $cmd)
	{
		$file = md5("$name:$cmd:da_helper:lelouch");
		$filename = "/dev/shm/$file.id";
		if(file_exists($filename))
		{
			$fp = @fopen($filename, "r+");
			if($fp === false)return 0;
			flock($fp, LOCK_EX);
			$id = fread($fp, 10);
			if(!is_numeric($id))
				$id = rand();
		}
		else
		{
			$fp = @fopen($filename, "w");
			if($fp === false)
				return 0;
			flock($fp, LOCK_EX);
			$id = rand();
		}
		$tid = ($id + 1) & 0xffffffff;
		if($tid == 0)
		{
			$tid = 1;
		}
		fseek($fp, 0);
		fwrite($fp, "$tid");
		flock($fp, LOCK_UN);
		fclose($fp);
		return $id;
	}
}

?>
