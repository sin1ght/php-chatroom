var ws = new WebSocket("ws://127.0.0.1:1234");
ws.onmessage = function (e){
    var msg = JSON.parse(e.data);
    switch (msg.type){
        case 1:
            //匹配成功
            match_success(msg);
            break;
        case 2:
            //有人发消息
            add_other_msg(msg);
            break;
        case 3:
            //匹配需要等待
            $('.match .loading .msg').text('加入等待队列中...');
            $('.match .btn_cancel').show();
            break;
        case 4:
            //普通消息推送(有人离开)
            $('.room .content .sys_msg').text(msg.data.msg);
            $('.room .content .sys_msg').show();
            setTimeout("$('.room .content .sys_msg').hide();",1500);
            console.log(msg.data.msg);
            break;
        case 5:
            //普通消息(取消匹配成功)
            console.log(msg.data.msg);
            break;
        case 6:
            //在线人数
            $('.info>p>span').text(msg.data.users);
            break;
        default:
            break;
    }
};

//添加其他人的信息
function add_other_msg(msg) {
    var user=msg.data.user;
    var re_msg=msg.data.msg;
    $('.room .content .msg_contain').append('<div class="other">\n' +
        '                <img src="'+user.a_url+'" alt="">\n' +
        '                <div>\n' +
        '                    <p class="nickname">'+user.nickname+'</p>\n' +
        '                    <div class="msg">\n' +
        '                        <div class="arrow"></div>\n' +
        '                        <div class="detail_msg">\n' +
        '                            '+re_msg+'\n' +
        '                        </div>\n' +
        '                    </div>\n' +
        '                </div>\n' +
        '            </div><div class="clear" style="clear: both;height: 10px;"></div>');
    scroll_bottom();
}

//保持滚动条在最底部
function scroll_bottom(){
    $('.room .content').scrollTop($('.msg_contain').height());
}

//匹配成功
function match_success(msg) {
    reset();
    $('.match').hide();
    $('#room_id').val(msg.data.room_id);
    var index=msg.data.index;
    $('.room .own_info .a_url').val(msg.data.users[index].a_url);
    $('.room .own_info .nickname').val(msg.data.users[index].nickname);
    $('.room header .avatars .one').attr('src',msg.data.users[0].a_url);
    $('.room header .avatars .two').attr('src',msg.data.users[1].a_url);
    $('.room header .avatars .three').attr('src',msg.data.users[2].a_url);
    for(var i=0;i<3;i++){
        if(i==index){
            $('.room .content .msg .one').text(msg.data.users[index].nickname);
        }else{
            if($('.room .content .msg .two').text()==''){
                $('.room .content .msg .two').text(msg.data.users[i].nickname);
            }else{
                $('.room .content .msg .three').text(msg.data.users[i].nickname);
            }
        }
    }
    $('.room').show();
}

//回到初始状态
function reset() {
    $('.match .btn_cancel').hide();
    $('.match .loading').css('opacity',0);
    $('.match .btn_confirm').show();
    $('.room').hide();
    $('.match').show();
    $('.room .content .other,.me').remove();
    $('.room .content .clear').remove();
}


//开始匹配
$('.match .btn_confirm').click(function () {
    $(this).hide();
    $('.match .loading').css('opacity',1);
    var data={
        type:0
    };
    ws.send(JSON.stringify(data));
});

//取消匹配
$('.match .btn_cancel').click(function () {
    //取消匹配
    $(this).hide();
    $('.match .btn_confirm').show();
    $('.match .loading').css('opacity',0);
    var data={
        type:1
    };
    ws.send(JSON.stringify(data));
});

//发送消息
function send_msg(){
    var a_url=$('.room .own_info .a_url').val();
    var nickname=$('.room .own_info .nickname').val();
    var msg=$('.room footer .input').val();
    $('.room footer .input').val('');
    var room_id=$('#room_id').val();
    $('.room .content .msg_contain').append('<div class="me">\n' +
        '                <img src="'+a_url+'" alt="">\n' +
        '                <div class="msg">\n' +
        '                    <div class="arrow"></div>\n' +
        '                    <div class="detail_msg">\n' +
        '                        '+msg+'\n' +
        '                    </div>\n' +
        '                </div>\n' +
        '            </div><div class="clear" style="clear: both;height: 10px;"></div>');
    scroll_bottom();
    var data={
        type:2,
        data:{
            msg:msg,
            room_id:room_id,
            user:{
                a_url:a_url,
                nickname:nickname
            }
        }
    };
    ws.send(JSON.stringify(data));
}

$('.room footer .btn_send').click(function () {
   send_msg();
});

$('.room footer .input').keydown(function (e) {
    if(e.keyCode ==13){
        send_msg();
    }
});

//离开房间
$('.room header .btn').click(function () {
   reset();
   var nickname=$('.room .own_info .nickname').val();
   var room_id=$('#room_id').val();
   var data={
       type:3,
       data:{
           name:nickname,
           room_id:room_id
       }
   };
   ws.send(JSON.stringify(data));
});

//提建议
$('.info .suggest').click(function () {
    $('#suggest_modal').modal('toggle');
});

//确定提建议
$('#suggest_modal .modal-footer .btn_confirm').click(function () {
   console.log('已经接受你宝贵的意见');
   var msg=$('#suggest_modal textarea').val();
    $('#suggest_modal textarea').val('');
   $.post('server/suggestion.php',{msg:msg},function (data,status) {
      if(status){

      }
   });
});