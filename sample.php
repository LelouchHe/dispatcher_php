<?php

require_once("da_helper.php");

// 配置,可单独文件
$program_name = "sample";   // 服务名称
$program_cmd = 0;           // 服务命令号(目前只支持单命令号)
$main_hosts = "123.123.123.123:12345;127.0.0.1:25836";
$main_retry = 0;            // 重试次数(包括第一次),默认(0)是host个数
$back_hosts = "123.123.123.123:12346;127.0.0.1:25837";
$back_retry = 0;            // 重试次数(包括第一次),默认(0)是host个数
$send_timeout_ms = 1000;
$recv_timeout_ms = 1000;
// 配置结束

$da = new DAHelper($program_name, $program_cmd);
$da->set_main($main_hosts);
$da->set_back($back_hosts);

$msg = "hello world";
if (!$da->send($msg, strlen($msg), $send_timeout_ms))
{
    echo "send error";
    exit;
}

if (!$da->recv($ret, $size, $recv_timeout_ms))
{
    echo "recv error";
    exit;
}

if ($size <= 0)
{
    echo "size error";
}

echo $ret;

?>
