<?php
/**
 * Created by PhpStorm.
 * User: si
 * Date: 2018/4/10
 * Time: 13:40
 */

class Log
{
    public  static function connect($socket){
        socket_getpeername($socket,$addr);
        echo "[*]$addr connected\n";
    }

    public static function disconnet($socket){
        socket_getpeername($socket,$addr);
        echo "[*]$addr closed\n";
    }
}