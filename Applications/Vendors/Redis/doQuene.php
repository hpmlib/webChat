<?php 
	use \Workerman\Autoloader;
    /**
	 * 消息队列，即时从消息队列中取得数据
	 * 该聊天系统共3个队列，一个有序集合 redis中设置所有数据有效期都是10天
	 * name:message:quene  离线消息队列，只留最新n条，并且用户登录时弹出n条,该队列在re_login方法生成
	 * md5(name1_name2_...):message:quene  聊天消息队列，一路聊天只留最新n条
	 * chat:message:quene  所有消息队列，即时弹出,并存入数据库
	 * 
	 * name::recentchat:members  有序集合，保存每个用户最近10天的联系人
	 */
	require_once  '../../../Workerman/Autoloader.php';
	Autoloader::setRootPath("../../");
	use Vendors\Redis\Redisq;
	use Vendors\Redis\RedisModel;
	use Api\Model\Mmessage;
	use Api\Model\Mbroadcast;
	use Api\Model\Mcommon;
	
	//自动建表
	Api\Model\Mqueue::createQueueTable();
	//处理队列
	Vendors\Queue\Util::mqHander(array(
	    'queueType'   => 'RedisQ',             #消息队列名称 默认是MQ RedisQ
	    'serverName'  => 'webChat',            #ResysQ
	    'queueName'   => \Config\St\Storekey::MSG_CHAT_LIST,      #要监听的消息队列名
	    'jobName'     => \Config\St\Storekey::MSG_CHAT_LIST,      #当前处理的job名称
	    'cnName'      => 'itcrm聊天队列',        #中文名称
	    'function'    => 'webchatMQ',          #要运行的函数名
	    'msgNumAtm'   => 2,                    #每次处理的消息数，如果是多个会有合并处理
	    'maxSleep'    => 30,                   #没有消息的时候，deamon将sleep，如果队列消息不多，尽量设置大点，减少处理压力20+
	    'adminMail'   => 'cuihb@ifeng.com',    #接受监控报警的邮件地址，多个地址逗号分割
	    'eagleeyeDb'  => 'webChat',           #消息队列监控状态表所在库
	    'phpFile'     =>  __FILE__,            #php文件地址
	    'life'        => 0,                    #程序的生命周期，如果0表示是一直循环的Deamon处理，如果设置了时间，必须采用crontab的形式
	));
	
	//消息队列回调函数
	function webchatMQ($data){
		if(!$data) return false;
		$data = unserialize($data);
		
		if($data['type'] === \Config\St\Storekey::BROADCAST_MSG_TYPE) {
		    insertBroadcastData($data);
		    return;
		}
	    //数据库中插入消息
	    insertMsgData($data);
	    storeMessageList($data);
	    storeRecentMembers($data);
	}
	
	/**
	 * 所有广播消息，到mysql中
	 */
	function insertBroadcastData($data) {
	    //自动分表处理,每天检测一次就行
	    if(!Mcommon::isStrInFile('bdc.tbset', date('Ymd')))
	       Mbroadcast::createTable(Mbroadcast::getTbname($data['time']));
	    //广播消息入库
	    $insertData = array(
	        'fromuser'   => $data['fromuser'],
	        'touser'     => $data['touser'],
	        'touserTitle'=> addslashes($data['touserTitle']),
	        'title'      => addslashes($data['title']),
	        'content'    => addslashes($data['content']),
	        'time'       => $data['time'],
	    );
	    Mbroadcast::storeBroadcast($insertData);
	}
	/**
	 * 所有对话message数据,到mysql中
	 */
	function insertMsgData($data){
	    //自动分表处理,每天检测一次就行
	    if(!Mcommon::isStrInFile('msg.tbset', date('Ymd')))
	       Mmessage::createTable(Mmessage::getTbname($data['time']));
	    //插入聊天数据
	    $insertData = array(
	        'chatid'   => $data['chatid'],
	        'fromuser' => $data['fromuser'],
	        'message'  => addslashes($data['message']),
	        'time'     => $data['time'],
	        'type'     => $data['type'],
	        'filemd5'  => $data['filemd5']
	    );
	    Mmessage::storeMessage($insertData);
	}
	
	/**
	 * 保留每个用户最新的n个最近联系人，到redis中
	 */
	function storeRecentMembers($data){
	    if($data['type'] == \Config\St\Storekey::BROADCAST_MSG_TYPE)
	        return false;

	    $chatList = Api\Model\Muser::getChatListFromChatid($data['chatid']);
	    foreach($chatList as $username){
	        RedisModel::zAdd('webChat', $username.\Config\St\Storekey::RECENT_MEMBERS, $data['time'], $data['chatid'], 2592000);
	        //删除一个月前的最近联系人
	        RedisModel::zRemRangeByScore('webChat', $username.\Config\St\Storekey::RECENT_MEMBERS, 0,  $data['time']-2592000);
	    }
	}
	
	/**
	 * 保留每路最新的n条message(历史消息),到redis中
	 */
	function storeMessageList($data){
	    if($data['type'] == \Config\St\Storekey::BROADCAST_MSG_TYPE)
	        return false;
	    Redisq::lpush(array(
            'serverName'    => 'webChat', #服务器名，参照见Redisa的定义 ResysQ
            'key'      => $data['chatid'].\Config\St\Storekey::MSG_HISTORY,  #队列名
            'value'    => serialize($data),  #插入队列的数据
        ));
	    //保存最新20条
	    Redisq::ltrim(array(
            'serverName'  => 'webChat',     #服务器名，参照见Redis的定义 ResysQ
            'key'         => $data['chatid'].\Config\St\Storekey::MSG_HISTORY,  #队列名
            'offset'      => 0,      #开始索引值
            'len'         => 20,      #结束索引值
        ));
	}
?>
