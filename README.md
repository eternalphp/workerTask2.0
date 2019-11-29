# workerTask

#### 介绍
php实现的多进程定时任务管理系统

#### 软件架构
软件架构说明


#### 安装教程

1. git clone git@github.com:eternalphp/workerTask2.0.git
2. cd workerTask2.0
3. ./worker.bat start
4. ./worker.bat stop
5. ./worker.bat satus
6. ./worker.bat config --add 增加新的定时任务  规则与linux crontab 类似
7. ./worker.bat config --list 查看任务列表


#### 主要命令操作集合

worker start
worker start [taskid]
worker stop
worker stop [taskid]
worker restart
worker status
worker config --add
worker config --list
worker config --edit [taskid]
worker config --remove [taskid]
worker config --reload
worker --help
worker --version
worker -V
worker -v
		
