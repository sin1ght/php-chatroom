<?php
/**
 * Created by PhpStorm.
 * User: si
 * Date: 2018/4/10
 * Time: 14:10
 */

if (isset($_POST['msg'])){
    $r_msg=$_POST['msg'];
    if (!empty($r_msg)){
        date_default_timezone_set("Asia/Chongqing");
        $time=date("Y/m/d H:i:s");
        $addr=$_SERVER['REMOTE_ADDR'];
        $msg="$addr  $time\n".$r_msg."\n\n\n";
        $f=fopen(__DIR__.'\suggestion.txt','a+');
        if ($f){
            fwrite($f,$msg);
            fclose($f);
        }
        echo json_encode(array(
            'status'=>true,
            'data'=>array(
                'msg'=>'成功接受您宝贵的建议'
            )
        ));
    }
}