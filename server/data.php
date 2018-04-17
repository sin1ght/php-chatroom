<?php
/**
 * Created by PhpStorm.
 * User: si
 * Date: 2018/4/8
 * Time: 16:06
 */

$db=mysqli_connect("localhost","root","root","chatroom");
// 检查连接
if (!$db) {
    die("连接错误: " . mysqli_connect_error());
}

$basedir=__DIR__.'\..\img\avatars\\';
$dir=opendir($basedir);
mysqli_query($db,"set names gb2312");
mysqli_query($db,"set character_set_client=gb2312");
mysqli_query($db,"set character_set_results=gb2312");
while ($f=readdir($dir)){
    if (is_file($basedir.$f)){
        $name=explode('.',$f)[0];
        $url="img/avatars/$f";
        $sql="insert into roles (name,url) VALUES('".$name."','".$url."')";
        if(!mysqli_query($db,$sql)){
            var_dump(mysqli_error($db));
        }
    }
}
mysqli_close($db);

