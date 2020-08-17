<?php
/**
 * beanstalk消费者程序
 * 采用swoole多进程处理
 * 参考：https://wiki.swoole.com/#/process_pool
 */
//引入beanstalk类文件
require_once(__DIR__.'/../lib/Beanstalk.php');
require_once(__DIR__.'/../lib/Common.php');
//定义api返回code常量
define('CODE',[
    1,//接口正常
    -1000//接口异常
]);

//获取当前文件名即为beanstalk的tube

$tube = explode('.',end(explode('/',$_SERVER['PHP_SELF'])))[0];
//读取对应消费程序配置
$consumerConfig = parse_ini_file(__DIR__.'/../config/worker.ini',true)[$tube];

//开启工作进程数量
$workerNum = (int)$consumerConfig['workerNum'];

$pool = new Swoole\Process\Pool($workerNum);

// beanstalkd 配置
$beanstalkConfig = parse_ini_file(__DIR__.'/../config/beanstalk.ini');


$pool->on("WorkerStart", function ($pool, $workerId)use($tube,$consumerConfig,$beanstalkConfig){
    $taskTitle = $consumerConfig['title'];
    echo '['.date('Y-m-d H:i:s').']'.$taskTitle.'任务的worker程序已开启' . PHP_EOL;
    $beanstalk = new Beanstalk($beanstalkConfig);
    //连接beanstalk
    $beanstalk->connect();
    $beanstalk->watch($tube);
     while (true) {
        # 当前tube的数据
        $data = $beanstalk->reserve();
        $beanstalk->ignore('default');
         try {
            $sendData = json_decode($data['body'], true);
            $method = strtoupper($sendData['method']) ?? '';
            switch ($method){
                case 'POST':
                    $res = Common::https_post($sendData['url'], json_encode($sendData['data']), true);
                    break;
                case 'GET':
                    $res = Common::https_get($sendData['url']);
                    break;
                default:
                    $beanstalk->delete($data['id']);
                    $res = false;
                    break;
            }
            //curl调用时结果返回为false，说明接口服务器挂掉或服务不可用
            if (false == $res) {
                $beanstalk->release($data['id'], 1024, 5);
                echo '['.date('Y-m-d H:i:s').']'.$taskTitle.'任务接口服务不可用' . PHP_EOL;
            } else {
                //接口返回code
                $code = json_decode($res, true)['code'];
                if (CODE[0] == $code) {
                    $beanstalk->delete($data['id']);
                    echo '['.date('Y-m-d H:i:s').']'.$taskTitle.'任务接口执行成功' . PHP_EOL;
                } else {
                    $beanstalk->release($data['id'], 1024, 10);
                    echo '['.date('Y-m-d H:i:s').']'.$taskTitle.'任务接口返回错误' . PHP_EOL;
                }
            }
        } catch (Throwable $e) {
            $beanstalk->release($data['id'], 1024, 10);
            echo '['.date('Y-m-d H:i:s').']分销升级出现异常错误';
        }

        //防止一个废弃任务一直保留在管道中
         if(isset($consumerConfig['isDel']) && $consumerConfig['isDel'] === true){
             $runNum = $consumerConfig['runNum'] ?? 1;
             # 任务执行次数
             $releasesNum = $beanstalk->statsJob($data['id'])['releases'];
             if($releasesNum > $runNum){
                 $beanstalk->delete($data['id']);
                 echo '['.date('Y-m-d H:i:s').']'.$taskTitle.'任务执行超过'.$runNum.'次,已将该任务给删除' . PHP_EOL;
             }
         }
    }
});

$pool->on("WorkerStop", function ($pool, $workerId)use($consumerConfig){
    echo '['.date('Y-m-d H:i:s').']'.$consumerConfig['title'].'任务的worker程序已停止工作' . PHP_EOL;
});

$pool->start();

