<?php
/**
 * 生成文件http服务端
 */
// 读取配置文件
$httpConfig = parse_ini_file(__DIR__.'/config/http.ini');
Co\run(function () use($httpConfig) {
    $server = new Co\Http\Server('0.0.0.0', (int)$httpConfig['port'], false);
    $server->handle('/', function ($request, $response) {
        $requestData = $request->post;
        if(empty($requestData)){
            $response->end(json_encode(['code'=>'-1000','msg'=>'参数不能为空'],JSON_UNESCAPED_UNICODE));
            return;
        }
        $title = $requestData['title'] ?? '';
        $tube = $requestData['tube'] ?? '';
        $runNum = $requestData['runNum'] ?? 1;
        $isDel = $requestData['isDel'] ?? 'false';
        $workerNum = $requestData['workerNum'] ?? 2;
        if(empty($tube) || empty($title)){
            $response->end(json_encode(['code'=>'-1000','msg'=>'缺少必要参数title或tube'],JSON_UNESCAPED_UNICODE));
            return;
        }
        $chan = new Swoole\Coroutine\Channel(1);
        //生成配置
        go(function ()use ($title,$tube,$runNum,$isDel,$workerNum){
            $configFile = fopen(__DIR__."/config/worker.ini", "a+") or die("Unable to open file!");
            $txt = "\n";
            $txt .= "[".$tube."]\n";
            $txt .= "title = ".$title."\n";
            $txt .= "workerNum = ".$workerNum."\n";
            $txt .= "runNum = ".$runNum."\n";
            $txt .= "isDel = ".$isDel."\n";
            fwrite($configFile, $txt);
            fclose($configFile);
        });
        //生成php文件
        go(function ()use($chan,$tube){
            $creatTaskFile =  fopen(__DIR__."/app/".$tube.".php", "w") or die("Unable to open file!");
            $content = file_get_contents('script.txt');
            fwrite($creatTaskFile, $content);
            fclose($creatTaskFile);
            $chan->push(1);
        });
        //所有完成之后进行重启消费程序
        go(function ()use($chan){
            if($chan->pop() === 1){
                exec('php start.php restart');
            }
        });
        $response->end(json_encode(['code'=>'1','msg'=>'创建消费程序成功'],JSON_UNESCAPED_UNICODE));
        return;
    });
    $server->start();
});









