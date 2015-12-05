<?php

use Workerman\Worker;
use Workerman\WebServer;
use Workerman\Lib\Timer;
use PHPSocketIO\SocketIO;

// Include autoload.php
include __DIR__ . '/vendor/autoload.php';
include __DIR__ . '/vendor/autoload.php';
include __DIR__ . '/config/Config.php';


// Save users' id
$uidConnectionMap = array();
// Save the count of online users
$lastOnlineCount = 0;
// Save the count of online pages
$lastOnlinePageCount = 0;

// PHPSocketIO
$senderIO = new SocketIO(SERVER_SOCKET_PORT);
$senderIO->on('connection', function($socket){
    // Login event
    $socket->on('start_login', function($uid) use ($socket){
        global $uidConnectionMap, $lastOnlineCount, $lastOnlinePageCount;
        if(isset($socket->uid)){
            return;
        }
        // Refresh user id
        $uid = (string)$uid;
        if(!isset($uidConnectionMap[$uid]))
        {
            $uidConnectionMap[$uid] = 0;
        }
        // Save count of links to each user
        ++ $uidConnectionMap[$uid];
        // For uid push
        $socket->join($uid);
        $socket->uid = $uid;
        // Send updated data
        $socket->emit("update_online_user_counts", json_encode(array("data" => $lastOnlineCount)));
    });
    
    // On disconnect
    $socket->on('disconnect', function () use($socket) {
        if(!isset($socket->uid))
        {
             return;
        }
        global $uidConnectionMap, $senderIO;
        // No users online
        if(-- $uidConnectionMap[$socket->uid] <= 0)
        {
            unset($uidConnectionMap[$socket->uid]);
        }
    });
});

// New http service to send data to user
$senderIO->on('workerStart', function(){
    // Listening to a port
    $innerHttpWorker = new Worker('http://0.0.0.0:' . SERVER_API_PORT);
    // On msg receiving
    $innerHttpWorker->onMessage = function($httpConnection, $data){
        if(!isset($_REQUEST) && count($_REQUEST) <= 0) 
            return $httpConnection->send(json_encode(array("result" => false)));

        // Send msg url like: "type=sendTextMsg&sendTo=xxxx&sendContent=xxxx"
        switch($_REQUEST['type']){
            case 'sendTextMsg':
                global $senderIO;

                if(isset($_REQUEST['sendTo']) && !empty($_REQUEST['sendTo'])) $SendTo = $_REQUEST['sendTo'];
                $SendContent = json_encode(array("data" => htmlspecialchars($_REQUEST['sendContent'])));
                if(!empty($SendTo)){
                    $senderIO->to($SendTo)->emit('new_msg_receive', $SendContent);
                }else{
                    $senderIO->emit('new_msg_receive', $SendContent);
                }
                // return status
                return $httpConnection->send(json_encode(array("result" => true)));
        }
        return $httpConnection->send(json_encode(array("result" => false)));
    };
    // Start listening
    $innerHttpWorker->listen();

    // Init a timer
    Timer::add(1, function(){
        global $uidConnectionMap, $senderIO, $lastOnlineCount, $lastOnlinePageCount;
        $onlineCountNow = count($uidConnectionMap);
        $onlinePageCountNow = array_sum($uidConnectionMap);
        // Send updated data when client counts changes
        if($lastOnlineCount != $onlineCountNow || $lastOnlinePageCount != $onlinePageCountNow)
        {
            $senderIO->emit("update_online_user_counts", json_encode(array("data" => $onlineCountNow)));
            $lastOnlineCount = $onlineCountNow;
            $lastOnlinePageCount = $onlinePageCountNow;
        }
    });
});

// Start service
Worker::runAll();
