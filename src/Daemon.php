<?php
/**
 * @Author Liam<ritoyan@163.com>
 * @Date 2018-05-15
 * @Desc PHP多进程管理，多任务多进程，类似nginx的master worker进程模型
 */
namespace Aizuyan\Daemon;

class Daemon
{
    /**
     * 根据配置建立多个独立的任务
     *
     * @var array
     */
    public $config = [];

    /**
     * 保存主进程id（master pid）的文件
     * @var string
     */
    public $pid_file = "";

    /**
     * 标准输出、标准错误定向位置
     * @var string
     */
    public $std_file = "/dev/null";

    /**
     * 所有子进程id列表（slaver pid）
     * @var array
     */
    protected $_workers_pid = [];

    /**
     * 主进程的状态，STATUS_RUNNING 或者 STATUS_STOP
     * @var integer
     */
    protected $_status = 0;

    /**
     * 运行状态
     */
    const STATUS_RUNNING = 1;

    /**
     * 结束状态
     */
    const STATUS_STOP = 2;

    /**
     * 状态统计信息存储文件
     */
    public $statistics_file = "";

    /**
     * 进程额外的信息
     * @var array
     */
    protected $_extend_info = [
        "start_timestamp" => 0
    ];


    /*************************** slaver进程用到的变量 ***************************/
    /**
     * 进程是否准备停止
     * @var boolean
     */
    protected $_stoping = false;

    /**
     * 子进程循环次数
     * @var integer
     */
    protected $_run_times = 0;

    /**
     * 最大运行次数 ,子进程最大循环次数
     * @var integer
     */
    public $max_run_times = 100000;

    /**
     * 子进程最大运行秒数，默认一小时
     *
     * @var integer
     */
    public $max_run_seconds = 3600;

    /**
     * slaver进程处理的时候暂停时间，防止写的时候漏掉 默认1/10秒
     * @var integer
     */
    public $sleep_micro_seconds = 10000;

    /**
     * 构造函数
     */
    public function __construct()
    {
    }

    /**
     * 初始化
     */
    protected function init()
    {
        if (empty($this->statistics_file)) {
            echo "请配置状态查看文件\n";
            exit(250);
        }

        if (!is_dir(dirname($this->statistics_file))) {
            mkdir(dirname($this->statistics_file), 0755, true);
        }

        if (empty($this->pid_file)) {
            echo "请配置pid_file来存储master进程的pid\n";
            exit(250);
        }

        if (!is_dir(dirname($this->pid_file))) {
            mkdir(dirname($this->pid_file), 0755, true);
        }

        $this->_extend_info["start_timestamp"] = time();
    }

    /**
     * 检查运行脚本的环境是否在cli下面，不在抛出异常
     *
     * 	- 脚本仅支持在CLI模式下运行
     */
    protected function check_sapi_env()
    {
        if (php_sapi_name() != "cli") {
            throw new Exception("仅在CLI模式下运行");
        }
    }


    /**
     * 解析命令，根据命令做出不同的操作
     * 	- start  : 启动进程
     *  - restart: 暴力关闭所有进程重启
     *  - reload : 拼花关闭所有进程重启
     * 	- stop 	 : 平滑停止所有进程，slaver进程循环中，会处理完正在处理的逻辑然后退出
     * 	- quit 	 : 暴力退出进程，直接退出slaver所有进程
     * 	- status : 展示master、slaver进程的信息
     */
    protected function parse_command()
    {
        global $argv;
        $commands = [
            "start",
            "restart",
            "stop",
            "reload",
            "quit",
            "status"
        ];
        $command = @trim($argv[1]);

        if (!in_array($command, $commands)) {
            echo "请输入正确的命令\n";
            echo "使用方法：[script] [start | restart | stop | reload | quit | status]\n";
            exit(250);
        }

        switch ($command) {
            case "stop":
            {
                $master_pid = $this->get_master_pid();
                if ($master_pid <= 0) {
                    echo "获取主进程号失败，请确认主进程已启动，配置文件读取未知正确\n";
                    exit(250);
                }
                posix_kill($master_pid, SIGINT);
                while (1) {
                    $master_is_alive = posix_kill($master_pid, 0);
                    if ($master_is_alive) {
                        usleep(100000);
                        continue;
                    }
                    break;
                }
                echo "平滑停止成功\n";
                exit(0);
            }
                break;

            case "quit":
            {
                $master_pid = $this->get_master_pid();
                if ($master_pid <= 0) {
                    echo "获取主进程号失败，请确认主进程已启动，配置文件读取未知正确\n";
                    exit(250);
                }
                posix_kill($master_pid, SIGTERM);
                while (1) {
                    $master_is_alive = posix_kill($master_pid, 0);
                    if ($master_is_alive) {
                        usleep(100000);
                        continue;
                    }
                    break;
                }
                echo "暴力停止成功\n";
                exit(0);
            }
                break;

            case "status":
            {
                $master_pid = $this->get_master_pid();
                if ($master_pid <= 0) {
                    echo "获取主进程号失败，请确认主进程已启动，配置文件读取未知正确\n";
                    exit(250);
                }
                posix_kill($master_pid, SIGUSR1);
                // 默认的7行数据加每个子进程的数据
                $total_line = 6 + $this->get_worker_pid_count(false);
                while (!is_file($this->statistics_file) || count(file($this->statistics_file)) < $total_line) {
                    sleep(1);
                }
                // 格式化处理数据
                $lines = file($this->statistics_file);
                $master_lines = array_slice($lines, 0, 5);
                echo implode("", $master_lines);
                echo "-------------------------------- slaver进程状态 --------------------------------\n";

                $_workers_pid = trim($lines[5]);
                $_workers_pid = json_decode($_workers_pid, true);
                $slaver_lines = array_slice($lines, - $this->get_worker_pid_count(false));
                $slaver_lines_map = [];
                foreach ($slaver_lines as $slaver_line) {
                    $pid = intval($slaver_line);
                    $slaver_lines_map[$pid] = $slaver_line;
                }

                foreach ($_workers_pid as $task_name => $worker_pids) {
                    echo "任务{$task_name}: \n";
                    foreach ($worker_pids as $worker_pid) {
                        echo $slaver_lines_map[$worker_pid];
                    }
                    echo "--------------------------------------------------------------------------------\n";
                }
                unlink($this->statistics_file);
                exit(0);
            }
                break;

            case "start":
            {
                $master_pid = $this->get_master_pid();
                $master_is_alive = $master_pid && posix_kill($master_pid, 0);
                if ($master_is_alive) {
                    echo "进程{$master_pid}已经在运行了\n";
                    exit(250);
                }
            }
                break;

            case "restart":
            {
                $master_pid = $this->get_master_pid();
                if ($master_pid <= 0) {
                    echo "获取主进程号失败，请确认主进程已启动，配置文件读取未知正确\n";
                    exit(250);
                }
                posix_kill($master_pid, SIGTERM);
                while (1) {
                    $master_is_alive = posix_kill($master_pid, 0);
                    if ($master_is_alive) {
                        usleep(100000);
                        continue;
                    }
                    break;
                }
                echo "暴力停止成功\n";
                echo "重启中...\n";
            }
                break;

            case "reload":
            {
                $master_pid = $this->get_master_pid();
                if ($master_pid <= 0) {
                    echo "获取主进程号失败，请确认主进程已启动，配置文件读取未知正确\n";
                    exit(250);
                }
                posix_kill($master_pid, SIGINT);
                while (1) {
                    $master_is_alive = posix_kill($master_pid, 0);
                    if ($master_is_alive) {
                        usleep(100000);
                        continue;
                    }
                    break;
                }
                echo "平滑停止成功\n";
                echo "重启中...\n";
            }
                break;

            default:
                # code...
                break;
        }
    }


    /**
     * daemon化
     *
     * 将进程脱离终端，建立新的会话、进程组
     */
    protected function daemonize()
    {
        // 建立新的session，不受当前session控制
        // fork是因为posix_setsid执行的进程不能是进程组组长（group leader）
        $pid = pcntl_fork();
        if (-1 == $pid) {
            throw new Exception("fork进程失败");
        } elseif ($pid != 0) {
            exit(0);
        }

        // 创建新的会话，当前进程即是会话组长（sid=pid），也是进程组组长(gpid=pid)
        if (-1 == posix_setsid()) {
            throw new Exception("新建立session会话失败");
        }

        // 再次fork，使用非会话组长进程，因为打开一个终端的前提是该进程必须是会话组长
        $pid = pcntl_fork();
        if (-1 == $pid) {
            throw new Exception("fork进程失败");
        } else if($pid != 0) {
            exit(0);
        }

        // 恢复默认的文件掩码，避免继承自
        umask(0);
        // 修改工作目录，让进程脱离原来的目录
        chdir("/");
    }

    /**
     * 保存进程号文件到制定文件中
     * @return [type] [description]
     */
    protected function save_master_pid()
    {
        $master_pid = posix_getpid();
        if (false === @file_put_contents($this->pid_file, $master_pid)) {
            throw new Exception("主进程id（master pid）写入文件[".$this->pid_file."]失败.");
        }
    }

    /**
     * 获取写入文件中的master进程号
     * @return int 进程号
     */
    protected function get_master_pid()
    {
        $master_pid = intval(@file_get_contents($this->pid_file));

        return $master_pid;
    }

    /**
     * 根据配置的slaver进程数量和已有slaver进程，fork新的slaver进程
     */
    protected function fork_workers()
    {
        foreach ($this->config as $task_name => $task_config) {
            $count  = $task_config["count"];
            $handle = $task_config["handle"];

            if (!isset($this->_workers_pid[$task_name])) {
                $this->_workers_pid[$task_name] = [];
            }

            while (count($this->_workers_pid[$task_name]) < $count) {
                $this->fork_one_worker($task_name, $handle);
            }
        }
    }

    /**
     * fork一个新的进程
     */
    //protected function fork_one_worker()
    protected function fork_one_worker($task_name, $handle)
    {
        $pid = pcntl_fork();
        if (-1 == $pid) {
            throw new Exception("fork进程失败.");
        }
        if (0 != $pid) {
            $this->_workers_pid[$task_name][$pid] = $pid;
        } elseif( 0 == $pid) {
            $this->run($handle);
        }
    }

    /**
     * 主进程安装信号
     */
    protected function install_master_signal()
    {
        // 平滑退出
        pcntl_signal(SIGINT, [__CLASS__, "handle_master_signal"], false);
        // 暴力退出
        pcntl_signal(SIGTERM, [__CLASS__, "handle_master_signal"], false);
        // 查看进程状态
        pcntl_signal(SIGUSR1, [__CLASS__, "handle_master_signal"], false);
    }

    /**
     * 处理主进程的信号
     * @param  int 信号触发回调的时候传递过来的信号标示值
     */
    public function handle_master_signal($signal)
    {
        switch ($signal) {
            case SIGINT:
            {
                $this->_status = static::STATUS_STOP;
                // 给所有slaver进程发送平滑退出信号
                foreach ($this->_workers_pid as $task_name => $worker_pids) {
                    foreach ($worker_pids as $worker_pid) {
                        posix_kill($worker_pid, SIGINT);
                    }
                }
            }
                break;

            case SIGTERM:
            {
                $this->_status = static::STATUS_STOP;
                // 给所有slaver进程发送退出信号
                foreach ($this->_workers_pid as $task_name => $worker_pids) {
                    foreach ($worker_pids as $worker_pid) {
                        posix_kill($worker_pid, SIGKILL);
                    }
                }
            }
                break;
            case SIGUSR1:
            {
                // 获取master进程状态
                $pid = posix_getpid();
                $memory = round(memory_get_usage(true) / (1024 * 1024), 2) . "M";
                $time = time();
                $class_name = get_class($this);
                $start_time = date("Y-m-d H:i:s", $this->_extend_info["start_timestamp"]);
                $run_day = floor(($time - $this->_extend_info["start_timestamp"]) / (24 * 60 * 60));
                $run_hour = floor((($time - $this->_extend_info["start_timestamp"]) % (24 * 60 * 60)) / (60 * 60));
                $run_min = floor(((($time - $this->_extend_info["start_timestamp"]) % (24 * 60 * 60)) % (60 * 60)) / 60);
                $status = "Daemon [{$class_name}] 信息: \n"
                    ."-------------------------------- master进程状态 --------------------------------\n"
                    .str_pad("pid", 10)
                    .str_pad("占用内存", 19)
                    .str_pad("处理次数", 19)
                    .str_pad("开始时间", 29)
                    .str_pad("运行时间", 34)
                    ."\n"
                    .str_pad($pid, 10)
                    .str_pad($memory, 15)
                    .str_pad("--", 15)
                    .str_pad($start_time, 25)
                    .str_pad("{$run_day} 天 {$run_hour} 时 {$run_min} 分", 30)
                    ."\n"
                    . $this->get_worker_pid_count(false) . " slaver\n";
                    /*."-------------------------------- slaver进程状态 --------------------------------\n"
                    .str_pad("pid", 10)
                    .str_pad("占用内存", 19)
                    .str_pad("处理次数", 19)
                    .str_pad("开始时间", 29)
                    .str_pad("运行时间", 34)
                    ."\n";*/
                file_put_contents($this->statistics_file, $status);
                // 写入slaver task进程关系
                $json_workers_pid = json_encode($this->_workers_pid);
                file_put_contents($this->statistics_file, $json_workers_pid."\n", FILE_APPEND);
                foreach ($this->_workers_pid as $task_name => $worker_pids) {
                    foreach ($worker_pids as $worker_pid) {
                        posix_kill($worker_pid, SIGUSR1);
                    }
                }
            }
                break;

            default:
                # code...
                break;
        }
    }

    /**
     * 标准输出、标准错误出定向
     * 	@see https://stackoverflow.com/questions/937627/how-to-redirect-stdout-to-a-file-in-php
     *
     *       这里关闭标准输出、标准错误之后，新建两个文件描述符将分别代替他们，取名为`$STDOUT`、`$STDERR`
     *       无特别意义，其他名字也可以
     *
     * 关闭标准输入、输出、错误后，未关闭的跳过，重新打开的顺序：标准输入->标准输出->标准错误
     *
     */
    protected function reset_std()
    {
        global $STDOUT, $STDERR, $STDIN;
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        $STDIN  = fopen($this->std_file, "r");
        $STDOUT = fopen($this->std_file, "a");
        $STDERR = fopen($this->std_file, "a");
    }

    /**
     * 删除work pid列表中的一个pid
     *
     * @param $pid
     */
    protected function remove_one_worker_pid($pid)
    {
        foreach ($this->_workers_pid as $task_name => $worker_pids) {
            foreach ($worker_pids as $key => $worker_pid) {
                if ($worker_pid == $pid) {
                    unset($this->_workers_pid[$task_name][$key]);
                    break;
                }
            }
        }
    }

    /**
     * 获取子进程pid的数量
     *
     * @return int
     */
    protected function get_worker_pid_count($real = true)
    {
        $count = 0;
        if ($real) {
            foreach ($this->_workers_pid as $task_name => $worker_pids) {
                $count += count($worker_pids);
            }
        } else {
            foreach ($this->config as $task_name) {
                $count += intval($task_name["count"]);
            }
        }

        return $count;
    }

    /**
     * master开始进程监控slaver进程
     */
    public function monitor_workers()
    {
        // master进程状态标记为运行中
        $this->_status = static::STATUS_RUNNING;
        while (1) {
            $status = 0;
            // 等待子进程退出信号，或者触发信号
            $pid    = pcntl_wait($status);
            // 分发执行信号动作
            pcntl_signal_dispatch();

            if ($pid > 0) {
                $this->remove_one_worker_pid($pid);
                if ($this->_status == static::STATUS_RUNNING) {
                    $this->fork_workers();
                }
            } else {
                if ($this->_status == static::STATUS_STOP && $this->get_worker_pid_count() == 0) {
                    unlink($this->pid_file);
                    exit(0);
                }
            }
        }
    }

    /**
     * slaver进程逻辑，slaver进程运行开始
     */
    public function run($handle)
    {
        $this->_extend_info["start_timestamp"] = time();
        $this->install_slaver_signal();
        $this->_stoping = false;
        $this->_run_times = 0;
        while (1) {
            pcntl_signal_dispatch();
            // 信号停止
            if (true == $this->_stoping) {
                break;
            }
            // 达到最大运行次数停止
            if ($this->_run_times == $this->max_run_times) {
                break;
            }
            // 达到最大运行时间推出
            if (time() - $this->_extend_info["start_timestamp"] >= $this->max_run_seconds) {
                break;
            }
            call_user_func($handle);
            $this->_run_times++;
            usleep($this->sleep_micro_seconds);
        }
        exit(250);
    }
    /**
     * slaver进程安装信号
     */
    protected function install_slaver_signal()
    {
        // slaver进程平滑退出信号
        pcntl_signal(SIGINT, [__CLASS__, "handle_slaver_signal"], false);
        // 查看进程状态
        pcntl_signal(SIGUSR1, [__CLASS__, "handle_slaver_signal"], false);
    }

    /**
     * slaver进程处理信号
     * @return int 回调时候传入的进程标识
     */
    public function handle_slaver_signal($signal)
    {
        switch ($signal) {
            case SIGINT:
            {
                $this->_stoping = true;
            }
                break;
            case SIGUSR1:
            {
                // 获取master进程状态
                $pid = posix_getpid();
                $memory = round(memory_get_usage(true) / (1024 * 1024), 2) . "M";
                $run_times = $this->_run_times;
                $time = time();
                $start_time = date("Y-m-d H:i:s", $this->_extend_info["start_timestamp"]);
                $run_day = floor(($time - $this->_extend_info["start_timestamp"]) / (24 * 60 * 60));
                $run_hour = floor((($time - $this->_extend_info["start_timestamp"]) % (24 * 60 * 60)) / (60 * 60));
                $run_min = floor(((($time - $this->_extend_info["start_timestamp"]) % (24 * 60 * 60)) % (60 * 60)) / 60);
                $status = str_pad($pid, 10)
                    .str_pad($memory, 15)
                    .str_pad($run_times, 15)
                    .str_pad($start_time, 25)
                    .str_pad("{$run_day} 天 {$run_hour} 时 {$run_min} 分", 30)
                    ."\n";
                file_put_contents($this->statistics_file, $status, FILE_APPEND);
            }
                break;

            default:
                break;
        }
    }

    public function run_all()

    {
        $this->check_sapi_env();
        $this->init();
        $this->parse_command();
        $this->daemonize();
        $this->reset_std();
        $this->save_master_pid();
        $this->fork_workers();
        $this->install_master_signal();
        $this->monitor_workers();
    }
}
