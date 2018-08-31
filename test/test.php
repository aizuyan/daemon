<?php
include dirname(dirname(__FILE__)) . "/vendor/autoload.php";


use Aizuyan\Daemon\Daemon;

class Test extends Daemon
{
    public function __construct()
    {
        $this->config = [
            // 第一个处理任务的配置
            "task-mcq" => [
                // slaver进程个数
                "count"         => 2,
                // 处理函数 callable
                "handle"        => [$this, "handle_mcq"]
            ],
            "other" => [
                // slaver进程个数
                "count"         => 4,
                // 处理函数 callable
                "handle"        => function() {
                	sleep(5);
    				file_put_contents("/tmp/empty-test.txt", "other:" . time()."\n", FILE_APPEND);
                }
            ]
        ];

        parent::__construct();
    }

    public function handle_mcq()
    {
        $this->log("WWWW");
    	sleep(10);
    	file_put_contents("/tmp/empty-test.txt", __FUNCTION__ .":". time()."\n", FILE_APPEND);
    }
}

$obj = new Test();

// 主进程id保存位置
$obj->pid_file = "/tmp/master.pid";
$obj->log_file = "/tmp/yyy";

// 信息统计文件位置
$obj->statistics_file = "/tmp/statistics.txt";

$obj->run_all();
