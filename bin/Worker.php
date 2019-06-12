<?php 

//服务容器

define("ROOT",dirname(__DIR__));
define("LOGPATH",ROOT . '/logs/');

define('COLOR_BLACK',30);
define('COLOR_RED',31);
define('COLOR_GREEN',32);
define('COLOR_YELLOW',34);
define('COLOR_WHITE',37);

define('CURSOR_DEFAULT',0); //终端默认设置
define('CURSOR_LIGHT',1); //高亮显示
define('CURSOR_UNDERLINE',4); //使用下划线
define('CURSOR_GLISTEN',5); //闪烁
define('CURSOR_HIGHLIGHT',7); //反白显示
define('CURSOR_INSIVIBLE',8); //不可见

// 1、 读取配置文件 init
// 2、 创建worker进程
// 3、 检查进程状态 分配任务
// 4、 监听任务，解析配置，执行相应的任务
// 5、 监听操作命令，对进程进行 start,stop,restart 操作

date_default_timezone_set('Asia/Shanghai'); //默认时区

class Worker{
	
    /**
     * 状态 启动中
     * @var int
     */
    const STATUS_STARTING = 1;
    
    /**
     * 状态 运行中
     * @var int
     */
    const STATUS_RUNNING = 2;
	
    /**
     * 状态 准备停止
     * @var int
     */
    const STATUS_STOPING = 3;
    
    /**
     * 状态 停止
     * @var int
     */
    const STATUS_SHUTDOWN = 4;
	
	const ONLOG = false; //是否启动日志
	const ONDEBUG = true; //是否启动调试
	
	const MASTER_HANDLE = 0x4337b700; //主进程状态
	const STATUS_HANDLE = 0x4337b800; //监听状态
	
	static $onMessage = null;
	static $onClose = null;
	static $onError = null;
	
	const Worker_CONFIG_TYPE = 'conf';
	const Worker_CONFIG_FILE = ROOT .'/conf/worker.conf'; //配置文件
	const ACTIVE_STATUS_FILE = ROOT .'/conf/worker.status'; //运行状态配置文件
	const Worker_CONFIG_UPDATE = ROOT . '/conf/worker.update'; //监听配置文件是否更新
	const Worker_VERSION_FILE = ROOT .'/conf/worker.version'; //版本配置文件
	const Worker_PROCESS_FILE = __DIR__ . '/WorkerProcess'; //主进程文件
	
	static $workerErrorLog = ROOT . '/logs/error.log'; //错误日志
	static $workerRuntimeLog = ROOT . '/logs/active.log'; //运行日志
	static $workerProcessPid = ROOT . '/conf/worker.pid'; //主进程pid
	static $workerProcessDirectory = ROOT;
	
	static $globalData = array();
	
	const Worker_UPDATE_CONFIG_HANDLE = 'shmop'; //使用共享内存 
	
	const MAX_LOG_SIZE = 10*1024*1024; //字节
	
	static $processCount = 4;
	static $taskProcessList = array();
	static $taskList = array();
	
    /**
     * 服务启动初始化
     * @return void
     */
	public static function init(){
		$acticeList = array();
		
		//获取服务进程配置数据
		$taskList = Config::getConfigList();
		if($taskList){
			foreach($taskList as $k=>$val){
				$val["running"] = 0;
				$val["runtime"] = null;
				$val["pid"] = null;
				$acticeList[$val["taskid"]] = $val;
			}
		}
		if($acticeList){
			Worker::$taskList = $acticeList;
			Worker::updateWorkerStatus($acticeList);
		}
		
		//获取全局配置数据
		$global = Config::getConfigList('global');
		if($global){
			if(isset($global['error_log'])){
				self::$workerErrorLog = ROOT .'/'. $global['error_log'];
				if(!file_exists(dirname(self::$workerErrorLog))){
					mkdir(dirname(self::$workerErrorLog),0777,true);
				}
			}
			if(isset($global['runtime_log'])){
				self::$workerRuntimeLog = ROOT .'/'.$global['runtime_log'];
				if(!file_exists(dirname(self::$workerRuntimeLog))){
					mkdir(dirname(self::$workerRuntimeLog),0777,true);
				}
			}
			if(isset($global['pid'])){
				self::$workerProcessPid = ROOT .'/'.$global['pid'];
				if(!file_exists(dirname(self::$workerProcessPid))){
					mkdir(dirname(self::$workerProcessPid),0777,true);
				}
			}
		}
		
		//标识当前服务已运行状态
		Worker::write(self::MASTER_HANDLE,self::STATUS_RUNNING);
		
		//标识配置文件是否更新状态
		Worker::workerConfigUpdate(0);

		//保存主进程pid
		file_put_contents(self::$workerProcessPid,getmypid());
	}
	
    /**
     * 启动服务
     * @return void
     */
	public static function start(){
		
		Worker::init(); //始初化
		call_user_func(Worker::$onMessage,array('title'=>sprintf("master[%d]",getmypid())));
		
		Worker::createWorkerProcess();
		
		Console::loop(function(){
			
			Console::listion(); //监听配置文件是否更新
			
			//分发任务
			if(Worker::$taskList){
				foreach(Worker::$taskList as $k=>$val){
					if($val["status"] == self::STATUS_STARTING || $val["status"] == self::STATUS_RUNNING){
						
						$rules = Worker::parseCommand($val);
						$pid = Worker::getWorkerProcess($val["taskid"]);
						if($pid == -1 || $pid == false){
							continue;
						}
						
						if($rules["sleep"] > 0){
							if(!isset(Worker::$taskProcessList[$pid]["taskList"][$val["taskid"]])){
								$val["start"] = time();
								$val["sleep"] = $rules["sleep"];
								Worker::$taskProcessList[$pid]["taskList"][$val["taskid"]] = $val;
							}
						}else{
							if(Worker::checkRule($rules) && date("s") == '00'){
								if(!isset(Worker::$taskProcessList[$pid]["taskList"][$val["taskid"]])){
									$val["sleep"] = 0;
									Worker::$taskProcessList[$pid]["taskList"][$val["taskid"]] = $val;
								}
							}
						}
					}
				}

			}
			
			//监听服务进程状态
			Worker::checkWorkerStatus();
		});
	}
	
	/**
	 * 分配worker进程 已在任务队列不分配
	 * @param int $taskid 
	 * @return int | bool
	 */
	public static function getWorkerProcess($taskid){
		if(Worker::$taskProcessList){
			
			foreach(Worker::$taskProcessList as $val){
				if($val["taskList"]){
					if(isset($val["taskList"][$taskid])){
						return -1;
					}
				}
			}
			
			$pids = array();
			$count = null;
			foreach(Worker::$taskProcessList as $pid=>$val){
				$pids[$pid] = count($val["taskList"]);
				if($count == null){
					$count = $pids[$pid];
				}else{
					if($pids[$pid] < $count){
						$count = $pids[$pid];
					}
				}
			}
			
			if($pids){
				foreach($pids as $pid=>$val){
					if($val > $count){
						unset($pids[$pid]);
					}
				}
			}
			
			return array_rand($pids,1);
		}else{
			return false;
		}
	}
	
    /**
     * 创建服务进程
	 * @param int $taskid
     * @return void
     */
	public static function createWorkerProcess(){
		
		$pid = 1;
		while($pid <= self::$processCount){
			$start_file = self::Worker_PROCESS_FILE;
			$std_file = LOGPATH . basename($start_file).".out.txt";

			$descriptorspec = array(
				0 => array('pipe', 'a'), // stdin
				1 => array('file', $std_file, 'w'), // stdout
				2 => array('file', $std_file, 'w') // stderr
			);

			$pipes       = array();
			$process     = proc_open("php \"$start_file\" -q", $descriptorspec, $pipes); //创建子进程 cmd.exe
			$std_handler = fopen($std_file, 'a+');
			stream_set_blocking($std_handler, 0);
			$status = proc_get_status($process);
			
			Worker::$taskProcessList[$status["pid"]] = array(
				'process'=>$process,
				'pipes'=>$pipes,
				'pid'=>$status["pid"],
				'taskList'=>array()
			);

			call_user_func(Worker::$onMessage,array('title'=>sprintf("|--worker[%d]",$status["pid"])));

			$pid++;
		}
		
		Worker::debugLog(Worker::$taskProcessList);
	}

    /**
     * 检测进程状态
     * @return void
     */
	public static function checkWorkerStatus(){
		
		if(self::$taskProcessList){

			foreach(self::$taskProcessList as $pid=>$val){

				$process = $val["process"];
				$pipes = $val["pipes"];
				$taskList = $val["taskList"];
				$status = proc_get_status($process);

				if(!$status['running']){
					call_user_func(Worker::$onClose,array('title'=>sprintf("|--worker[%d]",$status["pid"])));
					unset(self::$taskProcessList[$pid]);
					continue;
				}
				
				
				if($taskList){
					foreach($taskList as $taskid=>$config){
						$config["pid"] = $val["pid"];
						$config["cmd"] = 'running';
						
						if($config["sleep"] > 0){
							if((time() - $config["start"]) == $config["sleep"]){
								fwrite($pipes[0],json_encode($config));
								unset($taskList[$taskid]);
								
								if(Worker::$taskList[$taskid]["status"] == 1) Worker::$taskList[$taskid]["status"] = self::STATUS_RUNNING;
								Worker::$taskList[$taskid]["runtime"] = date("Y-m-d H:i:s");
								Worker::$taskList[$taskid]["running"] = $status['running'];
								Worker::$taskList[$taskid]["pid"] = $val["pid"];
								Worker::updateWorkerStatus(Worker::$taskList);
								
							}
						}else{
							fwrite($pipes[0],json_encode($config));
							unset($taskList[$taskid]);
							
							if(Worker::$taskList[$taskid]["status"] == 1) Worker::$taskList[$taskid]["status"] = self::STATUS_RUNNING;
							Worker::$taskList[$taskid]["runtime"] = date("Y-m-d H:i:s");
							Worker::$taskList[$taskid]["running"] = $status['running'];
							Worker::$taskList[$taskid]["pid"] = $val["pid"];
							Worker::updateWorkerStatus(Worker::$taskList);
						}
					}
					self::$taskProcessList[$pid]["taskList"] = $taskList;
				}

			}
		}else{
			if(file_exists(Worker::$workerProcessPid)){
				$pid = file_get_contents(Worker::$workerProcessPid);
				exec(sprintf("kill -9 %d -f",$pid));
			}
			if(file_exists(Worker::$workerProcessPid)) unlink(Worker::$workerProcessPid);
			if(file_exists(Worker::ACTIVE_STATUS_FILE)) unlink(Worker::ACTIVE_STATUS_FILE);
		}
		
		
	}

    /**
     * 创建服务进程成功回调
     * @return void
     */
	public static function onMessage(callable $callback){
		Worker::$onMessage = $callback;
	}
	
    /**
     * 创建服务进程失败回调
     * @return void
     */
	public static function onError(callable $callback){
		Worker::$onError = $callback;
	}
	
    /**
     * 服务进程关闭回调
     * @return void
     */
	public static function onClose(callable $callback){
		Worker::$onClose = $callback;
	}
	
    /**
     * 创建共享内存
	 * @param $key
	 * @param $value
     * @return void
     */
	public static function write($key,$value){
		$shmid = @shmop_open($key, 'c', 0644, 1);
		shmop_write($shmid, $value , 0);
	}
	
    /**
     * 读取共享内存的数据
	 * @param $key
     * @return int
     */
	public static function read($key){
		$shmid = @shmop_open($key, 'c', 0644, 1);
		return shmop_read($shmid,0,1);
	}
	
    /**
     * 配置文件更新状态读取
     * @return int
     */
	public static function workerConfigRead(){
		if(Worker::Worker_UPDATE_CONFIG_HANDLE == 'file'){
			return (int)file_get_contents(Worker::Worker_CONFIG_UPDATE);
		}else{
			return (int)Worker::read(Worker::STATUS_HANDLE);
		}
	}
	
    /**
     * 标识配置文件更新状态
	 * @param int $value 0:未更新，1：已更新
     * @return int
     */
	public static function workerConfigUpdate($value = 0){
		if(Worker::Worker_UPDATE_CONFIG_HANDLE == 'file'){
			file_put_contents(Worker::Worker_CONFIG_UPDATE,$value);
		}else{
			Worker::write(Worker::STATUS_HANDLE,$value);
		}
	}

    /**
     * 更新服务进程状态数据
	 * @param array $data
     * @return void
     */
	public static function updateWorkerStatus($data = array()){
		$data = json_encode($data);
		file_put_contents(self::ACTIVE_STATUS_FILE,$data);
	}
	
    /**
     * 获取服务进程状态数据
     * @return array
     */
	public static function getWorkerStatus(){
		if(file_exists(self::ACTIVE_STATUS_FILE)){
			$data = file_get_contents(self::ACTIVE_STATUS_FILE);
			return json_decode($data,true);
		}else{
			return array();
		}
	}
	
    /**
     * 获取指定服务进程状态数据
	 * param int $taskid
     * @return array | bool
     */
	public static function getWorkerDetail($taskid){
		$data = self::getWorkerStatus();
		if($data){
			if(isset($data[$taskid])){
				return $data[$taskid];
			}
		}
		return false;
	}
	
	
    /**
     * 解析任务规则
	 * @param array $config
     * @return array
     */
	public static function parseCommand($config = array()){
		if($config){
			
			$data = array();
			$data["sleep"] = 0;
			$data["taskUrl"] = $config["taskUrl"];
			$data["cmdFile"] = $config["cmdFile"];
			$data["worker_processes"] = $config["worker_processes"];
			$data["worker_options"] = $config["worker_options"];
			if(isset($config["pid"])) $data["pid"] = $config["pid"];
			
			if($config["minute"] != '' && $config["minute"] != '*'){
				if(strstr($config["minute"],"/")){
					$minutes = explode("/",$config["minute"]);
					$data['sleep'] = $minutes[1]*60;
					return $data;
				}else{
					if(strstr($config["minute"],",")){
						$minutes = explode(",",$config["minute"]);
					}elseif(strstr($config["minute"],"-")){
						$minutes = explode("-",$config["minute"]);
						$min = $minutes[0];
						$max = $minutes[1];
						for($minute = $min;$minute <= $max;$minute++){
							$minutes[] = $minute;
						}
					}else{
						$minutes[] = $config["minute"];
					}
					
					$data['minutes'] = $minutes;
				}
			}
			
			if($config["hour"] != '' && $config["hour"] != '*'){
				if(strstr($config["hour"],"/")){
					$hours = explode("/",$config["hour"]);
					$data['sleep'] = $hours[1]*60*60;
					return $data;
				}else{
					if(strstr($config["hour"],",")){
						$hours = explode(",",$config["hour"]);
					}elseif(strstr($config["hour"],"-")){
						$hours = explode("-",$config["hour"]);
						$min = $hours[0];
						$max = $hours[1];
						for($hour = $min;$hour <= $max;$hour++){
							$hours[] = $hour;
						}
					}else{
						$hours[] = $config["hour"];
					}
					
					$data['hours'] = $hours;
				}
			}
			
			if($config["day"] != '' && $config["day"] != '*'){
				if(strstr($config["day"],"/")){
					$days = explode("/",$config["day"]);
					$data['sleep'] = $days[1]*60*60*24;
					return $data;
				}else{
					if(strstr($config["day"],",")){
						$days = explode(",",$config["day"]);
					}elseif(strstr($config["day"],"-")){
						$days = explode("-",$config["day"]);
						$min = $days[0];
						$max = $days[1];
						for($day = $min;$day <= $max;$day++){
							$days[] = $day;
						}
					}else{
						$days[] = $config["day"];
					}
					
					$data['days'] = $days;
				}
			}
			
			if($config["month"] != '' && $config["month"] != '*'){
				if(strstr($config["month"],",")){
					$months = explode(",",$config["month"]);
				}elseif(strstr($config["month"],"-")){
					$months = explode("-",$config["month"]);
					$min = $months[0];
					$max = $months[1];
					if($months){
						for($month = $min;$month <= $maxMonth;$month++){
							$months[] = $month;
						}
					}
				}else{
					$months[] = $config["month"];
				}
				
				$data['months'] = $months;
				
			}
			
			if($config["dayofweek"] != '' && $config["dayofweek"] != '*'){
				$data['weeks'] = explode(",",$config["dayofweek"]);
			}
			
			return $data;
		}
	}
	
    /**
     * 启动任务
	 * @param array $data
     * @return void
     */
	public static function cmdCommand($data = array()){
		if($data){
			
			//读取子进程程序配置目录
			$global = Config::getConfigList('global');
			if(isset($global['worker_processes_directory'])){
				Worker::$workerProcessDirectory = ROOT .'/'. $global['worker_processes_directory'];
				if(!file_exists(Worker::$workerProcessDirectory)){
					mkdir(Worker::$workerProcessDirectory,0777,true);
				}
			}
			unset($global);
			
			$config = array();
			$config['worker_options'] = $data["worker_options"];
			$config['pid'] = $data["pid"];
			
			if($data["taskUrl"] != ''){
				Worker::httpsRequest($data["taskUrl"]);
			}else{
				
				$id = 1;
				while($id <= $data["worker_processes"]){
					
					$config['id'] = $id; //虚拟进程pid
					
					self::createTaskProcess($data["cmdFile"],$config); //创建任务进程
					
					$id++;
				}
			}

			unset($data,$config);
		}
	}
	
    /**
     * 创建任务进程
	 * @param string $cmdFile
	 * @param array $data 传递参数
     * @return void
     */
	public static function createTaskProcess($cmdFile,$data = array()){
		if($data){
			$start_file = Worker::$workerProcessDirectory ."/". $cmdFile;
			$start_file = str_replace('/',DIRECTORY_SEPARATOR,$start_file);
			
			if(file_exists($start_file)){
				$std_file = LOGPATH . $cmdFile . ".out.txt";
				if(!file_exists(dirname($std_file))){
					mkdir(dirname($std_file),0777,true);
				}

				$descriptorspec = array(
					0 => array('pipe', 'a'), // stdin
					1 => array('file', $std_file, 'w'), // stdout
					2 => array('file', $std_file, 'w') // stderr
				);

				$pipes       = array();
				$process     = proc_open("php \"$start_file\" -q", $descriptorspec, $pipes); //创建任务进程
				$std_handler = fopen($std_file, 'a+');
				stream_set_blocking($std_handler, 0);
				fwrite($pipes[0],json_encode($data)); //给子进程传递参数
			}else{
				Worker::debugLog("file : $start_file is not exists ");
			}
		}
	}
	
    /**
     * 检查规则
	 * @param array $data
     * @return bool
     */
	public static function checkRule($data = array()){
		if($data){
			if(isset($data["minutes"])){
				if(!in_array(intval(date("i")),$data["minutes"])){
					return false;
				}
			}
			
			if(isset($data["hours"])){
				if(!in_array(date("G"),$data["hours"])){
					return false;
				}
			}
			
			if(isset($data["days"])){
				if(!in_array(date("j"),$data["days"])){
					return false;
				}
			}
			
			if(isset($data["months"])){
				if(!in_array(date("n"),$data["months"])){
					return false;
				}
			}
			
			if(isset($data["weeks"])){
				if(!in_array(date("w"),$data["weeks"])){
					return false;
				}
			}
			
			return true;
		}
	}
	
	
    /**
     * 发起http请求
	 * @param string $url
	 * @param array $data
	 * @param array $option
     * @return string
     */
	public static function httpsRequest($url,$data = null,$options = array()){
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);

		
		if (!empty($data)){
			curl_setopt($curl, CURLOPT_POST, 1);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
		}
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		if(isset($options['timeout']) && $options['timeout']>0) curl_setopt($curl, CURLOPT_TIMEOUT,$options['timeout']);
		
		if(isset($options["return_header"])){
			curl_setopt($curl, CURLOPT_HEADER, $options["return_header"]);
		}
		
		if(isset($options['header']) && is_array($options['header']) && $options['header']){
			curl_setopt($curl, CURLOPT_HTTPHEADER,$options['header']);
		}elseif(isset($options['cookie']) && !empty($options['cookie'])){
			curl_setopt($curl, CURLOPT_COOKIE, $options['cookie']);
		}
		
		//保存cookie文件路径
		if(isset($options['saveCookieFile']) && $options['saveCookieFile']!=''){
			curl_setopt($curl, CURLOPT_COOKIEJAR, $options['saveCookieFile']); 
		}
		
		//读取cookie文件路径
		if(isset($options['readCookieFile']) && $options['readCookieFile']!=''){
			curl_setopt($curl, CURLOPT_COOKIEFILE, $options['readCookieFile']); 
		}

		curl_setopt ( $curl, CURLOPT_FOLLOWLOCATION, 1); 
		$output = curl_exec($curl);
		curl_close($curl);
		return $output;
	}
	
    /**
     * 获取版本号
     * @return string
     */
	public static function getVersion(){
		return file_get_contents(self::Worker_VERSION_FILE);
	}
	
    /**
     * 生成日志记录
	 * @param array $data
     * @return void
     */
	public static function addLogData($data = array()){
		if(self::ONLOG == true){
			if($data){
				self::$workerRuntimeLog = realpath(self::$workerRuntimeLog);
				$file = fopen(ROOT . '/lock.txt','w+'); //文件锁
				if(flock($file,LOCK_EX)){  //加锁
					if(filesize(self::$workerRuntimeLog) >= self::MAX_LOG_SIZE){
						$zip = new ZipArchive();
						$zipFile = LOGPATH .'runtime_'. date("YmdHis") . '.zip';
						if($zip->open($zipFile,ZipArchive::CREATE) == true){
							$zip->addFile(self::$workerRuntimeLog,basename(self::$workerRuntimeLog));
							$zip->close();
							file_put_contents(self::$workerRuntimeLog,'');
						}
					}
					file_put_contents(self::$workerRuntimeLog,implode(" | ",$data)."\n",FILE_APPEND);
					clearstatcache(); //清除文件缓存信息
					flock($file,LOCK_UN); //解锁
				}
				fclose($file);
			}
		}
	}
	
    /**
     * 生成调试日志记录
	 * @param array | string | object $message
     * @return void
     */
	public static function debugLog($message){
		if(self::ONDEBUG == true){
			$path   = LOGPATH .'/'. date("Ym").'/';
			if(!file_exists($path)){
				mkdir($path,0777,true);
			}
			$filename = $path . date("Ymd").'_debug.log';
			$e = new Exception();
			$line = array();
			$line[] = date("Y-m-d H:i:s");
			$line[] = $e->getLine();
			$line[] = $e->getFile();
			if(is_array($message)){
				$message = print_r($message,true);
			}elseif(is_object($message)){
				$message = var_export($message,true);
			}
			$line[] = $message;
			file_put_contents($filename,implode(" | ",$line) ."\n",FILE_APPEND);
			unset($line,$message);
		}
	}
}

class Config{
	
    /**
     * 解析配置文件
     * @return array('global','workers')
     */
	public static function parseConfig(){

		if(file_exists(worker::Worker_CONFIG_FILE)){
			
			if(worker::Worker_CONFIG_TYPE == 'json'){
				return json_decode(file_get_contents(worker::Worker_CONFIG_FILE),true);
			}else{
			
				$conf = file_get_contents(worker::Worker_CONFIG_FILE);
				$data = array();
				preg_match_all("/worker\s?\{(.*?)\}/ies",$conf,$rules);	
				if($rules[1]){
					foreach($rules[1] as $k=>$rule){
						
						$lines = explode("\n",$rule);
						if($lines){
							
							$row = array();
							foreach($lines as $line){
								if(trim($line) != ''){
									preg_match("/([a-zA-Z_]+)\s+(.*?);+/ie",$line,$fields);
									list($str,$key,$value) = $fields;
									if($key == 'rules'){
										$vals = explode(" ",$value);
										$row["minute"] = isset($vals[0])?$vals[0]:"*";
										$row["hour"] = isset($vals[1])?$vals[1]:"*";
										$row["day"] = isset($vals[2])?$vals[2]:"*";
										$row["month"] = isset($vals[3])?$vals[3]:"*";
										$row["dayofweek"] = isset($vals[4])?$vals[4]:"*";
									}else{
										$row[$key] = $value;
									}
								}
							}
							$data['workers'][] = $row;
						}
					}
				}
				
				if($rules[0]){
					foreach($rules[0] as $rule){
						$conf = str_replace($rule,'',$conf);
					}
				}
				
				$conf = preg_replace("/server\s?\{(.*?)\}/is","",$conf);
				$lines = explode("\n",$conf);
				foreach($lines as $line){
					preg_match("/([a-zA-Z_]+)\s+(.*?);+/ie",$line,$fields);
					if($fields){
						list($str,$key,$value) = $fields;
						$data['global'][$key] = $value;
					}
				}
				
				return $data;
			}
		}else{
			return array();
		}
	}
	
    /**
     * 更新配置文件
	 * @param array $data
     * @return bool
     */
	public static function saveConfig($data = array()){
		
		// 定义 server 结点下的字段
		$fieldList = array('taskid','title','rules'=>['minute','hour','day','month','dayofweek'],'taskUrl','cmdFile','status','worker_processes','worker_options');
		
		if(worker::Worker_CONFIG_TYPE == 'json'){
			if($data){
				return file_put_contents(worker::Worker_CONFIG_FILE,json_encode($data));
			}
		}else{
			$lines = array();
			if($data){
				
				$lines[] = "server {";
				
				foreach($data as $k=>$val){
					$lines[] = "\tworker {";
					
					if($fieldList){
						foreach($fieldList as $key=>$field){
							if(is_array($field)){
								foreach($field as $sfield){
									$val[$key][] = $val[$sfield];
								}
								$val[$key] = implode(" ",$val[$key]);
								$lines[] = sprintf("\t\t%s %s;",$key,$val[$key]);
							}else{
								$lines[] = sprintf("\t\t%s %s;",$field,$val[$field]);
							}
						}
					}
					
					$lines[] = "\t}";
					$lines[] = "\n";
				}
				
				$lines[] = "}";
				
				$conf = file_get_contents(worker::Worker_CONFIG_FILE);
				
				preg_match_all("/worker\s?\{(.*?)\}/ies",$conf,$matchs);
				if($matchs[0]){
					foreach($matchs[0] as $worker){
						$conf = str_replace($worker,'',$conf);
					}
				}

				$conf = preg_replace("/server\s?\{(.*?)\}/is",implode("\n",$lines),$conf);
				return file_put_contents(worker::Worker_CONFIG_FILE,$conf);
				
			}
		}
	}
	
    /**
     * 获取配置数据
	 * @param string $key
     * @return array
     */
	public static function getConfigList($key = 'workers'){
		$data = self::parseConfig();
		if(isset($data[$key])){
			return $data[$key];
		}else{
			return array();
		}
	}
	
    /**
     * 增新配置数据
	 * @param array $data
     * @return bool
     */
	public static function addConfig($data = array()){
		
		$taskList = self::getConfigList();
		if($data){
			$taskid = 1;
			if($taskList) {
				// 获取最大的 taskid
				foreach($taskList as $val){
					if($val["taskid"] > $taskid){
						$taskid = $val["taskid"];
					}
				}
			}
			$data["taskid"] = $taskid + 1;
			$data["status"] = 1;
			$taskList[] = $data;
			return self::saveConfig($taskList);
		}
	}
	
    /**
     * 编辑配置数据
	 * @param int $taskid
	 * @param array $data
     * @return bool
     */
	public static function editConfig($taskid,$data = array()){
		$taskList = self::getConfigList();
		if($data){
			foreach($taskList as $k=>$vals){
				if($vals["taskid"] == $taskid){
					foreach($vals as $key=>$val){
						if(isset($data[$key])){
							$taskList[$k][$key] = $data[$key];
						}
					}
				}
			}
			return self::saveConfig($taskList);
		}
	}
	
    /**
     * 删除配置数据
	 * @param int $taskid
     * @return bool
     */
	public static function removeConfig($taskid){
		$taskList = self::getConfigList();
		if($taskList){
			foreach($taskList as $k=>$val){
				if($val["taskid"] == $taskid){
					unset($taskList[$k]);
				}
			}
			return self::saveConfig($taskList);
		}
	}
}

class Console{
	
    /**
     * 获取参数
	 * @param int $index
     * @return array | string | bool
     */
	public static function getParams($index = 0){
		global $argv;
		
		if($index == 0){
			return $argv;
		}else{
			return isset($argv[$index]) ? $argv[$index] : false;
		}
	}
	
    /**
     * 启动服务 | 启动指定任务
     * @return void
     */
	public static function start(){
		
		if(Worker::read(Worker::MASTER_HANDLE) != Worker::STATUS_RUNNING){
			Worker::onMessage(function($res){
				echo sprintf("[%s] %s was running! \n",date("Y-m-d H:i:s"),$res["title"]);
			});
			Worker::onError(function($res){
				echo sprintf("[%s] %s was error! \n",date("Y-m-d H:i:s"),$res["title"]);
			});
			Worker::onClose(function($res){
				echo sprintf("[%s] %s was close! \n",date("Y-m-d H:i:s"),$res["title"]);
			});
			Worker::start();
		}else{
			$taskid = intval(self::getParams(2));
			if($taskid > 0){
				$list = Worker::getWorkerStatus();
				if($list){
					foreach($list as $id=>$val){
						if($val["taskid"] == $taskid){
							$list[$id]["status"] = Worker::STATUS_STARTING;
						}
					}
					Worker::updateWorkerStatus($list);
					Worker::workerConfigUpdate(1);
				}
			}else{
				$list = Worker::getWorkerStatus();
				if($list){
					foreach($list as $taskid=>$val){
						$list[$taskid]["status"] = Worker::STATUS_STARTING;
					}
					Worker::updateWorkerStatus($list);
					Worker::workerConfigUpdate(1);
				}
			}
		}
		
	}
	
    /**
     * 停止服务 | 停止指定任务
     * @return void
     */
	public static function stop(){
		
		$taskid = intval(self::getParams(2));
		if($taskid > 0){
			$list = Worker::getWorkerStatus();
			$row = $list[$taskid];
			if($row){
				if($row["running"] == 1){
					$list[$taskid]["status"] = Worker::STATUS_STOPING;
					Worker::updateWorkerStatus($list);
					Worker::workerConfigUpdate(1);
				}
			}
		}else{

			Worker::workerConfigUpdate(2);
			sleep(1);

		}
	}
	
    /**
     * 查看所有任务运行状态
     * @return void
     */
	public static function status(){
		if(Worker::read(Worker::MASTER_HANDLE) != Worker::STATUS_RUNNING){
			Console::write("Worker: Worker service was closed ! \n\n");
		}
		
		$list = Worker::getWorkerStatus();
		if($list){
			$line = array();
			$line[] = implode(" | ",array(self::column('taskid',11),self::column('pid',10),self::column('title',15),self::column('status',10),self::column('running',10),self::column('runtime',20),self::column('stoptime',20)));
			$line[] = "-------------------------------------------------------------------------------------------------------------";
			foreach($list as $k=>$val){
				if(!isset($val["stoptime"])) $val["stoptime"] = "";
				$line[] = implode(" | ",array(self::column($val["taskid"],10),self::column($val["pid"],10),self::column($val["title"],15),self::column($val["status"],10),self::column($val["running"],10),self::column($val["runtime"],20),self::column($val["stoptime"],20)));
			}
			$line[] = "-------------------------------------------------------------------------------------------------------------";
			if($line){
				echo "\n";
				Console::write(implode(" \n ",$line));
			}
			unset($list,$line);
		}

	}
	
    /**
     * 重启所有任务 | 重启指定任务
     * @return void
     */
	public static function restart(){
		
		self::stop();
		
		sleep(1);
		
		self::start();
	}
	
    /**
     * 循环监控
	 * @param callable $callback 回调函数
	 * @param $second 间隔秒数
     * @return void
     */
	public static function loop(callable $callback,$second = 1){
		while(true){
			if(is_callable($callback)){
				$callback();
			}
			sleep($second);
		}
	}
	
    /**
     * 监听配置文件是否更新
     * @return void
     */
	public static function listion(){
		$status = Worker::workerConfigRead();
		if($status == 1){
			Worker::$taskList = Worker::getWorkerStatus();
			Worker::workerConfigUpdate(0);
		}elseif($status == 2){
			foreach(Worker::$taskProcessList as $val){
				fwrite($val["pipes"][0],json_encode(array('cmd'=>'exit')));
			}
			Worker::workerConfigUpdate(0);
		}
	}
	
    /**
     * 输出到控制台
	 * @param string $message
	 * @param int $color
     * @return void
     */
	public static function write($message,$color = COLOR_GREEN){
		$out = sprintf("[%d;40m",$color);
		$message = chr(27) . "$out" . "$message" . chr(27) . "[0m"; 
		fwrite(STDOUT,$message);
	}
	
    /**
     * 读取控制台输入参数
     * @return void
     */
	public static function read(){
		return trim(fgets(STDIN));
	}
	
    /**
     * 操作配置文件 add|edit|list|remove|reload
     * @return void
     */
	public static function config(){
		
		$command = self::getParams(2);
		
		switch($command){
			case '--add':
		
				$data = array();
				$data["taskid"] = 0;
				
				echo "\n";
				echo "Please enter your title: ";
				$data["title"] = Console::read();
				if($data["title"] == ''){
					$data["title"] = "new task";
				}
				Console::write("The title is : ".$data["title"]."\n\n");
				
				echo "Please enter your rule: ";
				$rule = Console::read();
				if($rule == '') $rule = '*/3 * * * *';
				$rules = explode(" ",$rule);
				$data["minute"] = isset($rules[0])?$rules[0]:"*/3";
				$data["hour"] = isset($rules[1])?$rules[1]:"*";
				$data["day"] = isset($rules[2])?$rules[2]:"*";
				$data["month"] = isset($rules[3])?$rules[3]:"*";
				$data["dayofweek"] = isset($rules[4])?$rules[4]:"*";
				
				Console::write("The rule is : ".$rule."\n\n");
				
				echo "Please enter your taskUrl: ";
				$data["taskUrl"] = Console::read();
				Console::write("The taskUrl is : ".$data["taskUrl"]."\n\n");
				
				echo "Please enter your cmdFile: ";
				$data["cmdFile"] = Console::read();
				if($data["cmdFile"] == '') $data["cmdFile"] = 'task.php';
				Console::write("The cmdFile is : ".$data["cmdFile"]."\n\n");
				
				echo "Please enter your worker processes: ";
				$worker_processes = intval(Console::read());
				$data["worker_processes"] = ($worker_processes > 0)?$worker_processes:1;
				Console::write("The worker processes is : ".$data["worker_processes"]."\n\n");
				
				echo "Please enter your options: ";
				$data["worker_options"] = Console::read();
				Console::write("The options is : ".$data["worker_options"]."\n\n");
				
				if($data) Config::addConfig($data);
				
				Console::write("Worker: worker config save success !");
			break;
			
			case '--edit':
			
				$taskid = intval(self::getParams(3));
				if($taskid > 0){

					$data = array();
					$list = Config::getConfigList();
					if($list){
						foreach($list as $k=>$row){
							if($row["taskid"] == $taskid){
								$data = $row;
								break;
							}
						}
						unset($list);
					}
					
					echo "\n";
					echo "Please enter your title: ";
					$title = Console::read();
					if($title != '') $data["title"] = $title;
					Console::write("The title is : ".$data["title"]."\n\n");
					
					echo "Please enter your rule: ";
					$rule = Console::read();
					if($rule != ''){
						$rules = explode(" ",$rule);
						$data["minute"] = isset($rules[0])?$rules[0]:"*/3";
						$data["hour"] = isset($rules[1])?$rules[1]:"*";
						$data["day"] = isset($rules[2])?$rules[2]:"*";
						$data["month"] = isset($rules[3])?$rules[3]:"*";
						$data["dayofweek"] = isset($rules[4])?$rules[4]:"*";
					}else{
						$rule = implode(" ",array($data["minute"],$data["hour"],$data["day"],$data["month"],$data["dayofweek"]));
					}
					
					Console::write("The rule is : ".$rule."\n\n");
					
					echo "Please enter your taskUrl: ";
					$taskUrl = Console::read();
					if($taskUrl != '') $data["taskUrl"] = $taskUrl;
					if($taskUrl == 'NULL') $data["taskUrl"] = '';
					Console::write("The taskUrl is : ".$data["taskUrl"]."\n\n");
					
					echo "Please enter your cmdFile: ";
					$cmdFile = Console::read();
					if($cmdFile != '') $data["cmdFile"] = $cmdFile;
					Console::write("The cmdFile is : ".$data["cmdFile"]."\n\n");
					
					echo "Please enter your worker processes: ";
					$worker_processes = intval(Console::read());
					if($worker_processes > 0) $data["worker_processes"] = $worker_processes;
					Console::write("The worker processes is : ".$data["worker_processes"]."\n\n");
					
					echo "Please enter your options: ";
					$worker_options = Console::read();
					if($worker_options != '') $data["worker_options"] = $worker_options;
					Console::write("The options is : ".$data["worker_options"]."\n\n");
					
					Config::editConfig($taskid,$data);
					
					Console::write("Worker: worker config save success !");
					
				}else{
					Console::write("Worker: worker config --edit missing parameter. See 'worker --help'.");
				}
				
			break;
			
			case '--remove':
				$taskid = intval(self::getParams(3));
				if($taskid > 0){
					Worker::removeConfig($taskid);
				}else{
					Console::write("Worker: worker config --remove missing parameter. See 'worker --help'.");	
				}
			break;
			
			case '--list':
				$list = Config::getConfigList();
				$line = array();
				if($list){
					foreach($list as $k=>$val){
						$line[] = implode(" ",array_values($val));
					}
				}
				if($line){
					echo "\n";
					Console::write("Worker: worker config list: \n");
					echo "----------------------------------------------------------------------------------------------------------\n";
					Console::write(implode("\n",$line));
					echo "\n----------------------------------------------------------------------------------------------------------";
				}
			break;
			
			case '--reload':
				$taskList = Config::getConfigList();
				
				if($taskList){
					
					$taskids = array();
					$data = Worker::getWorkerStatus();
					foreach($taskList as $k=>$val){
						$taskids[] = $val["taskid"];
						if(isset($data[$val["taskid"]])){
							
							$row = $data[$val["taskid"]];
							$row["title"] = $val["title"];
							$row["taskUrl"] = $val["taskUrl"];
							$row["cmdFile"] = $val["cmdFile"];
							$row["minute"] = $val["minute"];
							$row["hour"] = $val["hour"];
							$row["day"] = $val["day"];
							$row["month"] = $val["month"];
							$row["dayofweek"] = $val["dayofweek"];
							$row["worker_options"] = $val["worker_options"];
							$row["worker_processes"] = $val["worker_processes"];
							$data[$val["taskid"]] = $row;
						}else{

							$val["running"] = 0;
							$val["runtime"] = null;
							$val["pid"] = null;
							$val["ppid"] = null;
							$data[$val["taskid"]] = $val;
						}
					}
					
					if($data){
						foreach($data as $taskid=>$val){
							if(!in_array($taskid,$taskids)){
								unset($data[$taskid]);
							}
						}
					}

					Worker::updateWorkerStatus($data);
					Worker::workerConfigUpdate(1);
			
				}
			break;
			
			default:
				Console::write("Worker: worker config missing parameter. See 'worker --help'.");	
		}	
	}
	
    /**
     * 显示操作命令
     * @return void
     */
	public static function help(){
		$lines = array();
		$lines[] = "Worker command list: \n";
		$lines[] = "\n";
		$lines[] = "worker start \n";
		$lines[] = "worker start [taskid] \n";
		$lines[] = "worker stop \n";
		$lines[] = "worker stop [taskid] \n";
		$lines[] = "worker restart \n";
		$lines[] = "worker status \n";
		
		$lines[] = "worker config --add \n";
		$lines[] = "worker config --list \n";
		$lines[] = "worker config --edit [taskid] \n";
		$lines[] = "worker config --remove [taskid] \n";
		$lines[] = "worker config --reload \n";
		$lines[] = "worker --help \n";
		$lines[] = "worker --version \n";
		$lines[] = "worker -V \n";
		$lines[] = "worker -v \n";
		Console::write(implode("",$lines));
	}
	
    /**
     * 显示版本号
     * @return void
     */
	public static function version(){
		Console::write(sprintf("worker version %s \n",Worker::getVersion()));
	}
	
    /**
     * 格式化显示列
	 * @param $str
	 * @param $length
     * @return void
     */
	public static function column($str,$length){
		return str_pad($str,$length," ");
	}
}

?>