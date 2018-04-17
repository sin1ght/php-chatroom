<?php
/**
 * Created by PhpStorm.
 * User: si
 * Date: 2018/4/6
 * Time: 23:09
 */

require_once 'Log.php';

class WebSocketServer{
    private $sockets;//所以socket连接池
    private $users;//所有连接用户
    private $server;//服务端 socket
    private $chat_rooms;//所有聊天室
    private $wait_users;//等待匹配的用户

    public function __construct($ip,$port){
        $this->server=socket_create(AF_INET,SOCK_STREAM,0);
        $this->sockets=array($this->server);
        $this->chat_rooms=array();
        $this->wait_users=array();
        $this->users=array();
        socket_bind($this->server,$ip,$port);
        socket_listen($this->server,3);
        echo "[*]Listening:".$ip.":".$port."\n";
    }

    //数据库连接
    private function connect_db(){
        $con=mysqli_connect("localhost","root","root","chatroom");
        // 检查连接
        if (!$con) {
            die("连接错误: " . mysqli_connect_error());
        }
        return $con;
    }

    public function run(){
        $write=NULL;
        $except=NULL;
        while (true){
            $active_sockets=$this->sockets;
            socket_select($active_sockets,$write,$except,NULL);
            //tv_sec 参数作用
            //第一，若将NULL以形参传入，即不传入时间结构，就是将select置于阻塞状态，一定等到监视文件描述符集合中某个文件描
            //述符发生变化为止；
            //第二，若将时间值设为0秒0毫秒，就变成一个纯粹的非阻塞函数，不管文件描述符是否有变化，都立刻返回继续执行，文件无
            //变化返回0，有变化返回一个正值；
            //第三，timeout的值大于0，这就是等待的超时时间，即 select在timeout时间内阻塞，超时时间之内有事件到来就返回了，
            //否则在超时后不管怎样一定返回，返回值同上述。
            foreach ($active_sockets as $socket){
                if ($socket==$this->server){
                    //有新用户连接
                    $user=socket_accept($this->server);
                    $key=uniqid();
                    $this->sockets[]=$user;
                    $this->users[$key]=array(
                        'socket'=>$user,
                        'handshake'=>false //是否完成websocket握手
                    );
                }else{
                    //用户socket可读
                    $buffer='';
                    $bytes=socket_recv($socket,$buffer,1024,0);
                    $key=$this->find_user_by_socket($socket);
                    if ($bytes==0){
                        //没有数据 关闭连接
                        $this->disconnect($socket);
                    }else{
                        //没有握手就先握手
                        if (!$this->users[$key]['handshake']){
                            $this->handshake($key,$buffer);
                            //握手成功后推送 在线人数
                            Log::connect($socket);
                            $this->push_msg_for_all(array(
                                'type'=>6,
                                'data'=>array(
                                    'users'=>count($this->sockets)-1
                                )
                            ));
                        }else{
                            //握手后 解析消息
                            $this->parse_msg($buffer,$socket);
                        }
                    }
                }
            }
        }
    }

    //解除连接
    private function disconnect($socket){
        Log::disconnet($socket);
        //判断是否在聊天室
        foreach ($this->chat_rooms as $k=>$room){
            if(in_array($socket,$room)){
                $this->leave_room($k,$socket,'有人');
                break;
            }
        }
        //判断是否在等待队伍中
        if(in_array($socket,$this->wait_users)){
            $this->dismatch($socket);
        }

        $key=$this->find_user_by_socket($socket);
        unset($this->users[$key]);
        foreach ($this->sockets as $k=>$v){
            if ($v==$socket)
                unset($this->sockets[$k]);
        }
        socket_shutdown($socket);
        socket_close($socket);
        //推送在线人数
        $this->push_msg_for_all(array(
            'type'=>6,
            'data'=>array(
                'users'=>count($this->sockets)-1
            )
        ));
    }

    private function find_user_by_socket($socket){

        foreach ($this->users as $key=>$user){
            if ($user['socket']==$socket){
                return $key;
            }
        }
        return -1;
    }

    //广播消息 消息为数组格式
    private function push_msg_for_all($msg,$c_socket=null){
        $msg=$this->msg_encode(json_encode($msg));
        //除了 服务器和自己
        // 都发送
        foreach ($this->sockets as $socket){
            if ($socket!=$this->server and $socket!=$c_socket){
                socket_write($socket,$msg,strlen($msg));
            }
        }
    }

    private function handshake($k,$buffer){
        //截取Sec-WebSocket-Key的值并加密，其中$key后面的一部分258EAFA5-E914-47DA-95CA-C5AB0DC85B11字符串应该是固定的
        $buf  = substr($buffer,strpos($buffer,'Sec-WebSocket-Key:')+18);
        $key  = trim(substr($buf,0,strpos($buf,"\r\n")));
        $new_key = base64_encode(sha1($key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",true));

        //按照协议组合信息进行返回
        $new_message = "HTTP/1.1 101 Switching Protocols\r\n";
        $new_message .= "Upgrade: websocket\r\n";
        $new_message .= "Sec-WebSocket-Version: 13\r\n";
        $new_message .= "Connection: Upgrade\r\n";
        $new_message .= "Sec-WebSocket-Accept: " . $new_key . "\r\n\r\n";
        socket_write($this->users[$k]['socket'],$new_message,strlen($new_message));

        //对已经握手的client做标志
        $this->users[$k]['handshake']=true;
        return true;
    }


    //编码 把消息打包成websocket协议支持的格式
    private function msg_encode( $buffer ){
        $len = strlen($buffer);
        if ($len <= 125) {
            return "\x81" . chr($len) . $buffer;
        } else if ($len <= 65535) {
            return "\x81" . chr(126) . pack("n", $len) . $buffer;
        } else {
            return "\x81" . char(127) . pack("xxxxN", $len) . $buffer;
        }
    }

    //解码 解析websocket数据帧
    private function msg_decode( $buffer )
    {
        $len = $masks = $data = $decoded = null;
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        }
        else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        }
        else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        return $decoded;
    }

    //取消匹配
    private  function dismatch($socket){
        foreach ($this->wait_users as $k=>$user){
            if($socket==$user){
                unset($this->wait_users[$k]);
                break;
            }

        }
        $msg=array(
            'type'=>5,//普通消息
            'data'=>array(
                'msg'=>'取消匹配成功'
            )
        );
        $msg=$this->msg_encode(json_encode($msg));
        socket_write($socket,$msg,strlen($msg));
    }

    //同一个聊天室里的用户 广播消息
    private function push_msg_for_room($room_id,$msg,$c_socket=null){
        if($c_socket){
            // 当前用户存在 只把消息推送给其他两个人
            $msg=$this->msg_encode(json_encode($msg));
            foreach ($this->chat_rooms[$room_id] as $user){
                if($user!=$c_socket){
                    socket_write($user,$msg,strlen($msg));
                }
            }
        }else{
            //将消息推送给所有人
            $type=$msg['type'];
            if($type==1){
                //匹配成功消息推送
                //随机三个用户信息
                $usersinfo=$this->get_3_role();
                foreach ($this->chat_rooms[$room_id] as $k=>$user){
                    $data=array(
                        'type'=>1,
                        'data'=>array(
                            'room_id'=>$room_id,
                            'index'=>$k,
                            'users'=>$usersinfo
                        )
                    );
                    $data=$this->msg_encode(json_encode($data));
                    socket_write($user,$data,strlen($data));
                }
            }else{
                $msg=$this->msg_encode(json_encode($msg));
                foreach ($this->chat_rooms[$room_id] as $user){
                    socket_write($user,$msg,strlen($msg));
                }
            }
        }
    }


    //进行匹配
    private function match($user_socket){
        $user_count=count($this->wait_users);
        if($user_count>=2){
            //匹配成功
            $rands=array_rand($this->wait_users,2);
            $user_1=$this->wait_users[$rands[0]];
            $user_2=$this->wait_users[$rands[1]];
            $this->dismatch($user_1);
            $this->dismatch($user_2);
            $room_id=uniqid();//创建唯一房间号
            $this->chat_rooms[$room_id]=array($user_socket,$user_1,$user_2);
            //推送消息 创建房间成功
            $msg=array(
                'type'=>1,//1 匹配成功
                'data'=>array(
                    'room_id'=>$room_id,
                    'msg'=>'匹配成功'
                )
            );
            $this->push_msg_for_room($room_id,$msg);
        }else{
            //等待匹配...
            $this->wait_users[]=$user_socket;
            $msg=array(
                'type'=>3,//需要等待
                'data'=>array(
                    'msg'=>'等候匹配'
                )
            );
            $msg=$this->msg_encode(json_encode($msg));
            socket_write($user_socket,$msg,strlen($msg));
        }
    }

    //随机三个角色
    private function get_3_role(){
        $db=$this->connect_db();
        $sql="SELECT name,url FROM roles";
        $result=mysqli_query($db,$sql);
        $roles=mysqli_fetch_all($result,MYSQLI_ASSOC);
        mysqli_free_result($result);
        mysqli_close($db);
        $choose_indexs=array_rand($roles,3);
        $users=array();
        foreach ($choose_indexs as $index){
            $users[]=array(
                'a_url'=>$roles[$index]['url'],
                'nickname'=>$roles[$index]['name']
            );
        }
        return $users;
    }

    //离开房间
    private function leave_room($room_id,$user_socket,$name='有人'){
        foreach ($this->chat_rooms[$room_id] as $k=>$user){
            if($user==$user_socket) {
                unset($this->chat_rooms[$room_id][$k]);
                break;
            }
        }
        if(count($this->chat_rooms[$room_id])==0){
            //所有人都离开后 清除房间
            unset($this->chat_rooms[$room_id]);
        }else{
            //广播消息有人离开
            $msg=array(
                'type'=>4,
                'data'=>array(
                    'msg'=>$name."离开了房间"
                )
            );
            $this->push_msg_for_room($room_id,$msg,$user_socket);
        }

    }

    //解析消息
    // 根据type 具体操作
    private function parse_msg($buffer,$socket){
        $msg=json_decode($this->msg_decode($buffer),true);
        switch ($msg['type']){
            case 0:
                //进行3人匹配
                $this->match($socket);
                break;
            case 1:
                //取消匹配
                $this->dismatch($socket);
                break;
            case 2:
                //发送消息
                $room_id=$msg['data']['room_id'];
                if(array_key_exists($room_id,$this->chat_rooms)){
                    $data=array(
                        'type'=>2,//有人发消息
                        'data'=>array(
                            'msg'=>$msg['data']['msg'],
                            'user'=>$msg['data']['user']
                        )
                    );
                    $this->push_msg_for_room($room_id,$data,$socket);
                }
                break;
            case 3:
                //离开房间
                $room_id=$msg['data']['room_id'];
                $name=$msg['data']['name'];
                $this->leave_room($room_id,$socket,$name);
                break;
            default:
                break;
        }
    }

}
//
//服务端消息格式
//{
//    'type':1, //1 匹配成功 2 有人发消息 3 匹配需要等待 4普通消息推送(有人离开) 5普通消息(取消匹配成功) //6 在线人数通知
//    'data':{
//        'room_id' //可选
//        'msg'
//    }
//}

//客户端消息格式
//{
//    'type' 0 进行匹配 1 取消匹配 2 发送消息 3离开房间
//    'data':{
//        'room_id' 可选
//        'msg'
//    }
//}