<?php
/**
 * Created by PhpStorm.
 * User: si
 * Date: 2018/4/8
 * Time: 15:49
 */

require_once 'websocket.php';

$ws=new WebSocketServer('0.0.0.0',1234);
$ws->run();
