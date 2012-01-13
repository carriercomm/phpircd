<?php

class ircd {

var $version = "phpircd0.4.14";
var $config;
var $address;
var $port;
var $_clients = array();
var $_sockets = array();
var $_ssl_sockets = array();
var $_channels = array();
var $client_num = 0;
var $channel_num = 0;
var $servname;
var $network;
var $allowed = array("join","part","lusers","mode","motd","names","nick","oper","ping","pong","privmsg","quit","topic","protoctl","user","who");
var $nickRegex = "/^[a-zA-Z\[\]\\\|^\`_\{\}]{1}[a-zA-Z0-9\[\]\\|^\`_\{\}]{0,}\$/";
var $rnRegex = "/^[a-zA-Z\[\]\\\|^\`_\{\} \.]{1}[a-zA-Z0-9\[\]\\|^\`_\{\} \.]{0,}\$/";
var $ipv4Regex = "/^[0-9]{1,3}.[0-9]{1,3}.[0-9]{1,3}.[0-9]{1,3}\$/";

function newConnection($in, $user){
    $e = explode(" ", $in);
        $command = strtolower($e['0']);
    switch(@$command){
        case 'quit':
        $p = $e;
        unset($p['0']);
        if(count($p) > 1){
            $p = implode(" ", $p);
        } elseif(count($p) == 0){
            $p = "";
        } else {
            $p = $p['1'];
        }
        if($p[0] == ":"){
            $p = substr($p, 1);
        }
        $this->quit($user, $p);
        break;
        case 'pass':

        break;
        case 'user':
        //USER nick mode unused :Real Name
        if(count($e) < 5){
            $this->error('461', $user, 'USER');
            break;
        }
        $err = FALSE;
        while($err == FALSE){
            if(!$this->checkNick($e['1'])){
                $err = "{$e['1']}:Illegal characters.";
                continue;
            }
            //if(modes n shit){
                
            //}
            if(count($e) == 5){
                $rn = $e['4'];
                if($rn[0] == ":"){
                    $rn = substr($rn, 1);
                }
            } else {
                for($i=4;$i < count($e);$i++){
                    $rn[] = $e[$i];
                }
                $rn = implode(" ", $rn);
                if($rn[0] == ":"){
                    $rn = substr($rn, 1);
                }
            }
            if(!$this->checkRealName($rn)){
                $err = "$rn:Illegal characters.";
                continue;
            }
            break;
        }
        if($err){
            $this->error('432', $user,"$err");
        } else {
            unset($e['3']); //unused
            $user->username = $e['1'];
            $user->usermode = "";
            $user->realname = $rn;
            $user->namesx = FALSE;
            if($user->regbit ^ 1){
                $user->regbit += 1;
            }
            if($user->regbit == 3){
                $user->lastping = $user->lastpong = time();
                $user->registered = TRUE;
                $user->prefix = $user->nick."!".$user->username."@".$user->address;
                $this->welcome($user);
                        }
        }

        break;
        case 'nick':
        if(count($e) < 2){
            $this->error('431', $user);
            break;
        }
        $this->stripColon($e['1']);
        if($this->nickInUse($e['1'])){
            $this->error('433', $user, $e['1']);
            break;
        }
        if($this->checkNick(@$e['1'])){
            $user->nick = substr($e['1'], 0, $this->config['ircd']['nicklen']);
            if($user->regbit ^ 2){
                $user->regbit +=2;
            }
            if($user->regbit == 3){
                $user->lastping = $user->lastpong = time();
                $user->registered = TRUE;
                $user->prefix = $user->nick."!".$user->username."@".$user->address;
                $this->welcome($user);
            }
        } else {
            $this->error('432', $user, $e['1'].":Illegal characters.");
        }
        break;
        default:
        $this->error('451', $user, $e['0']);
    }

}

function process($in, $user){
    $e = explode(" ", $in);
    $command = strtolower($e['0']);
    unset($e['0']);
    $params = implode (" ", $e);
    $user->lastpong = time();
    if(method_exists(__CLASS__,$command) && array_search($command, $this->allowed) !== FALSE){
        $this->$command($user, $params);
    } else {
        $this->error('421', $user, $command);
    }
}

function error($numeric, $user, $extra="", $override=false){
    $target = (empty($user->nick)?"*":$user->nick);
    $prefix = ":".$this->servname." ".$numeric." ".$target." ";
    switch($numeric){
    case 401:
    $message = "$extra :No such nick/channel.";
    break;
    case 402:
    $message = "$extra :No such server.";
    break;
    case 403:
    $message = "$extra :No such channel.";
    break;
    case 404:
    $message = "$extra ".(!$override?":Cannot send to channel.":$override);
    break;
    case 405:
    $message = "$extra :You have joined too many channels.";
    break;
    case 406:
    $message = "$extra :There was no such nickname.";
    break;
    case 407:
    $message = "$extra :Too many targets.";
    break;
    case 408:
    $message = "$extra :No such service.";
    break;
    case 409:
    $message = ":No origin specified.";
    break;
    //410 doesn't exist
    case 411:
    $message = ":No recipient given.";
    break;
    case 412:
    $message = ":No text to send.";
    break;
    case 421:
    $message = strtoupper($extra)." :Unknown command.";
    break;
    case 422:
    $message = ":MOTD file missing.";
    break;
    case 431:
    $message = ":No nickname given.";
    break;
    case 432:
    $extra = explode(":",$extra);
    $message = "{$extra['0']} :Erroneous nickname".($extra['1']?": ".$extra['1']:"");
    break;
    case 433:
    $message = "$extra :Nickname already in use.";
    break;
    case 442:
    $message = "$extra :You're not in that channel.";
    break;
    case 451:
    $message = $extra." :You have not registered.";
    break;
    case '461':
    $message = strtoupper($extra)." :Not enough parameters.";
    break;
    case '462':
    $message = ":You may not register more than once.";
    break;
    case '472':
    $message = "$extra :is unknown mode char to me";
    break;
    case '482':
    $message = ":You do not have the proper channel privileges to do that.".(isset($extra)?" ($extra)":"");
    break;
    case '485':
    $message = ":You are not the channel owner.";
    break;
    case '499':
    $message = ":You're not a channel owner.";
    }
    $user->send($prefix.$message);
}

function welcome($user){
    $user->send(":{$this->servname} 001 {$user->nick} :Welcome to the {$this->network} IRC network, {$user->prefix}");
    $user->send(":{$this->servname} 002 {$user->nick} :Your host is {$this->servname}, running {$this->version}");
    $user->send(":{$this->servname} 003 {$user->nick} :This server was created {$this->createdate}");
    $user->send(":{$this->servname} 004 {$user->nick} {$this->servname} {$this->version} ".implode(array_keys($this->userModes))." ".implode(array_keys($this->chanModes)));
    $this->isupport($user);
    $this->lusers($user);
    $this->motd($user);
    $user->setModes("+iw");
    $d = array('ircd'=>&$this, 'user'=>&$user);
    $this->runUserHooks('connect', $d);
}

function join($user, $p=""){
    $joins = array();
    if(empty($p)){
        $this->error(461, $user, 'join');
        return;
    }
    $ps = explode(" ", $p);
    if(count($ps) > 1){ //we have channel keys
        $chans = explode(",", $ps['0']);
        $keys = explode(",", $ps['1']);
        foreach($chans as $k => $v){
            $joins[] = array($v, @$keys[$k]);
        }
    } else {
        $chans = explode(",", $p);
        foreach($chans as $k => $v){
            $joins[] = array($v, '');
        }
    }
    foreach($joins as $value){
        $chan = $value['0'];
        $kee = @$value['1'];
        if(array_search($chan[0], str_split($this->config['ircd']['chantypes'])) === FALSE){
            $this->error(403, $user, $chan);
            continue;
        }
        if(array_search($chan, $user->channels) !== FALSE)
            return;
        if(array_key_exists($chan, $this->_channels) === FALSE){
            $nchan = new Channel($this->channel_num++, $chan);
            $d = array('user'=>&$user, 'chan'=>&$nchan);
            if(!$this->runChannelHooks('join', $d)){
                $this->error($d['errno'], $user, $chan, $d['errstr']);
                return false;
            }
            $user->send(":{$user->prefix} JOIN $chan");
            $nchan->addUser($user, true);
            $nchan->setTopic($user, "default topic!");
            $this->_channels[$nchan->name] = $nchan;
            $user->addChannel($nchan);
        } else {
            $d = array('user'=>&$user, 'chan'=>&$this->_channels[$chan]);
            if(!$this->runChannelHooks('join', $d)){
                $this->error($d['errno'], $user, $chan, $d['errstr']);
                return false;
            }
            $this->_channels[$chan]->addUser($user);
            $user->addChannel($this->_channels[$chan]);
            $this->_channels[$chan]->send(":{$user->prefix} JOIN $chan");
       }
        $this->topic($user, $chan);
        $this->names($user, $chan);
    }
}

function lusers($user, $p=""){
    $nick = $user->nick;
    $users = count($this->_clients);
    $lusers = ":{$this->servname} 251 $nick :There are $users users and 0 invisible on 1 servers\n".
              ":{$this->servname} 252 $nick ".count($this->operList())." :operator(s) online\n".
              ":{$this->servname} 254 $nick ".count($this->_channels)." :channels formed\n".
              ":{$this->servname} 255 $nick :I have $users clients and 1 servers\n".
              ":{$this->servname} 265 $nick :Current Local Users: $users  Max: TODO\n".
              ":{$this->servname} 266 $nick :Current Global Users: $users  Max: TODO";
    foreach(explode("\n", trim($lusers)) as $s){
        $user->send(trim($s));
    }
}

function mode($user, $p){
    if(empty($p)){
        $this->error(461, $user, 'MODE');
        return;
    }
    $p = explode(" ", $p);
    $target = $p['0'];
    if($this->channelExists($target)){
        //chan mode(s)
        $channel = $this->_channels[$target];
        if(count($p) == 1){
            $user->send(":{$this->servname} 324 {$user->nick} {$channel->name} ".$channel->getModes());
            $user->send(":{$this->servname} 329 {$user->nick} {$channel->name} ".$channel->created);
        } else {
            unset($p['0']);
            $modes = implode(" ", $p);
            $channel->setModes($user, $modes);
        }
    } else {
        //user mode(s)
        //1: mask (+a, +aa-o, +a-ooa, etc)  2...: params
        if(!$this->getUserByNick($target)){
            $this->error(401, $user, $target);
            return false;
        }
        if($target != $user->nick)
            return false;
        if(count($p) == 1){
            $user->send(":{$this->servname} 221 {$user->nick} ".$user->getModes());
        } else {
            unset($p['0']);
            $modes = implode(" ", $p);
            $user->setModes($modes);
        }
    }
}

function motd($user, $p=""){
    if(empty($p)){
        if(file_exists("motd.txt")){
            $user->send(":{$this->servname} 375 {$user->nick} :- {$this->servname} Message of the day -");
            $motd = file("motd.txt");
            foreach($motd as $value){
                $user->send(":{$this->servname} 372 {$user->nick} :- ".rtrim($value));
            }
            $user->send(":{$this->servname} 376 {$user->nick} :End of MOTD");
        } else {
            $this->error('422', $user);
        }
    }
}

function names($user, $p){
    $prefix = ":{$this->servname} 353 {$user->nick} ";
    if(empty($p)){
        foreach($this->_clients as $val){
            if(count($val->channels) == "0"){
                $names[] = $val->nick;
            }
        }
        $prefix .= "= * :";
    } else {
        $p = explode(" ", $p);
        if(array_key_exists($p['0'], $this->_channels) === FALSE){
            $user->send(":{$this->servname} 366 {$user->nick} {$p['0']} :End of /NAMES list.");
            return;
        }
        $chan = $this->_channels[$p['0']];
        $names = $chan->users;
        foreach($names as $k => $v){
                $pfx = $chan->getUserPrefix($this->_clients[$k]);
                $names[$k] = str_replace("@@", "@", $pfx).$this->_clients[$k]->nick;
        }
        if(!$user->namesx){
        
        }
        if(array_key_exists("p",$chan->modes) !== FALSE){
            $prefix .= "* {$chan->name} :";
        } elseif(array_key_exists("s",$chan->modes) !== FALSE){
            $prefix .= "@ {$chan->name} :";
        } else {
            $prefix .= "= {$chan->name} :";
        }
    }
    if(count($names) == 0){
        $user->send(":{$this->servname} 366 {$user->nick} * :End of /NAMES list.");
        return;
    }
    $names = implode(" ", $names);
    $len = strlen($prefix);
    if($len+strlen($names) <= 510){
        $user->send($prefix.$names);
    } else {
        $max = 510 - $len;
        while(strlen($names) > 510){
        $nsub = substr($names, 0, $max-1);
        if($names[strlen($nsub)-1] != " " || !empty($names[strlen($nsub)-1])){
            $pos = strrpos($nsub, " ");
            $nsub = substr($nsub, 0, $pos);
        }
        $names = substr($names, strlen($nsub)+1);
        $user->send($prefix.$nsub);
        }
        $user->send($prefix.$names);
    }
    $user->send(":{$this->servname} 366 {$user->nick} ".(empty($chan->name)?"*":$chan->name)." :End of /NAMES list.");
}

function nick($user, $p){
    $this->stripColon($p);
    if(empty($p)){
        $this->error('461', $user, 'NICK');
        return;
    }
    if($user->nick == $p)
        return;
    if($this->nickInUse($p)){
        $this->error('433', $user, $p);
        return;
    }
    if($this->checkNick($p)){
        $p = substr($p, 0, $this->config['ircd']['nicklen']);
        $user->send(":{$user->prefix} NICK $p");
        $oldprefix = $user->prefix;
        $oldnick = $user->nick;
        $user->nick = $p;
        $user->prefix = $user->nick."!".$user->username."@".$user->address;
        foreach($user->channels as $chan){
                $c = $this->_channels[$chan];
                $c->nick($user, $oldnick);
                $c->send(":{$oldprefix} NICK $p", $user);
        }
    } else {
        $this->error('432', $user, $p.":"."Illegal characters.");
    }
}

function oper($user, $p){
    $user->oper = true;
}

function part($user, $p){
    $chans = explode(",", $p);
    foreach($chans as $k => $v){
        $x = explode(" ", $v, 2);
        $v = trim($x['0']);
        $reason = (empty($x['1'])?"":trim($x['1']));
        if(!array_key_exists($v, $this->_channels)){
            $this->error('403', $user, $v);
            return;
        }
        if(!array_key_exists($user->id, $this->_channels[$v]->users)){
            $this->error('442', $user, $v);
            return;
        }
        $this->_channels[$v]->send(":{$user->prefix} PART $v $reason");
        $this->_channels[$v]->removeUser($user);
        $user->removeChannel($this->_channels[$v]);
    }
}

function ping($user, $p, $e=false){
    if($e){
        $user->send("PING :$p");
        return;
    }
    if(empty($p)){
        $this->error('461', $user, 'PING');
        return;
    }
    $p = explode(" ", $p);
    if(count($p) == 1){
        $p = $p['0'];
        if(strpos($p, ":") === 0){
            $p = substr($p, 1);
        }
        $user->send(":{$this->servname} PONG {$this->servname} ".":$p");
        $user->lastpong = time();
    } else {
        //ping some server
    }
}

function pong($user, $p){
    //PONG :samecrap
    if(strpos($p, ":") === 0){
        $p = substr($p, 1);
    }
    if($p == $this->servname){ //respond to keepalive ping
        if($user->lastpong < $user->lastping){
            $user->lastpong = time();
        }
    }
}

function privmsg($user, $p){
    // target ?:message
    $e = explode(" ", $p);
    $chantypes = str_split($this->config['ircd']['chantypes']);
    $target = $e['0'];
    if($target[0] == ":"){
        //ERR_NORECIPIENT
        $this->error(411, $user);
        return;
    }
    if(count($e) < 2){
        //ERR_NOTEXTTOSEND
        $this->error(412, $user, $target);
        return;
    }
    if($target[0] == "$"){
        if($user->oper & 32){ //replace with actual oper bit
            //client is allowed to message $*
        } else {
            //ERR_NOSUCHNICK
            $this->error(401, $user, $target);
            return;
        }
    }
    $is_channel = (array_search($target[0], $chantypes) !== FALSE?TRUE:FALSE);
    if($is_channel){
        if(!preg_match("/[\x01-\x07\x08-\x09\x0B-\x0C\x0E-\x1F\x21-\x2B\x2D-\x39\x3B-\xFF]{1,}/", $target)){
            //ERR_NOSUCHNICK (illegal characters)
            $this->error(401, $user, $target);
            return;
        }
        if(array_key_exists($target, $this->_channels) === FALSE){
            //ERR_NOSUCHNICK (channel doesnt exist)
            $this->error(401, $user, $target);
            return;
        }
        if(array_search($target, $user->channels) === FALSE){
            //ERR_CANNOTSENDATOCHAN
            $this->error(404, $user, $target);
            return;
        }
        $d = array('user'=>&$user, 'chan'=>&$this->_channels[$target]);
        if(!$this->runChannelHooks('privmsg', $d)){
            $this->error($d['errno'], $user, $target, $d['errstr']);
            return false;
        }
        $message = substr($p, strlen($target)+1);
        $message = ($message[0] == ":"?substr($message, 1):$message);
        //send to whole channel minus yourself
        $this->_channels[$target]->send(":{$user->prefix} PRIVMSG $target :$message", $user);
    } else {
        if(($tuser = $this->getUserByNick($target)) == FALSE){
            //ERR_NOSUCHNICK (user doesnt exist)
            $this->error(401, $user, $target);
            return;
        }
        $message = substr($p, strlen($target)+1);
        $message = ($message[0] == ":"?substr($message, 1):$message);
        $tuser->send(":".$user->prefix." PRIVMSG ".$target." :$message");
    }
}

function protoctl($user, $p){
    if(empty($p)){
        $this->error(461, $user, 'protoctl');
        return;
    }
    if(strtolower($p) == "namesx"){
        $user->namesx = TRUE;
    }
}

function quit($user, $p="Quit: Leaving"){
    if(strpos($p, ":") === 0)
        $p = substr($p, 1);
    foreach(@$user->channels as $chan){
        $this->_channels[$chan]->send(":{$user->prefix} QUIT :$p");
        $this->_channels[$chan]->removeUser($user);
    }
    if($p !== false){
        $user->send("ERROR: Closing Link: {$user->nick}[{$user->address}] ($p)");
        $user->writeBuffer();
    }
    $user->disconnect();
}

function topic($user, $p){
    if(empty($p)){
        $this->error(461, $user, 'topic');
        return;
    }
    $p = explode(" ", $p);
    if(array_key_exists($p['0'], $this->_channels) === FALSE){
        $this->error(403, $user, $p['0']);
        return;
    }
    if(array_search($p['0'], $user->channels) === FALSE){
        $this->error(442, $user, $p['0']);
        return;
    }
    if(count($p) == 1){
        $chan = $this->_channels[$p['0']];
        if(empty($chan->topic)){
            $user->send(":{$this->servname} 331 ($chan->name} :No topic set.");
            return;
        }
        $user->send(":{$this->servname} 332 {$user->nick} {$chan->name} :{$chan->topic}");
        $user->send(":{$this->servname} 333 {$user->nick} {$chan->name} {$chan->topic_setby} {$chan->topic_seton}");
    } else {
        //change topic
    }
}

function user($user, $p){
    $this->error('462', $user);
}

function who($user, $p){
    if($this->channelExists($p)){
        $channel = $this->_channels[$p];
        foreach($channel->users as $id=>$m){
            $u = $this->_clients[$id];
            $m = str_replace('@@','@', $m);
            $user->send(":{$this->servname} 352 {$user->nick} {$p} {$u->username} {$u->address} {$this->servname} {$u->nick} H{$m} :0 {$u->realname}");
        }
    } elseif($this->nickInUse($p)) {
        $u = $this->getUserByNick($p);
        $channel = $this->_channels[current($u->channels)];
        $m = str_replace('@@','@', $channel->users[$u->id]);
        $user->send(":{$this->servname} 352 {$user->nick} {$channel->name} {$u->username} {$u->address} {$this->servname} {$u->nick} H{$m} :0 {$u->realname}");
    } elseif(empty($p)){
        $p = '*';
        foreach($user->channels as $c){
            $channel = $this->_channels[$c];
            foreach($channel->users as $id=>$m){
                $u = $this->_clients[$id];
                $m = str_replace('@@','@', $m);
                $user->send(":{$this->servname} 352 {$user->nick} {$channel->name} {$u->username} {$u->address} {$this->servname} {$u->nick} H{$m} :0 {$u->realname}");
            }
        } 
    }
    $user->send(":{$this->servname} 315 {$user->nick} $p :End of /WHO list.");
}

//net methods

function __construct($config){
    $this->config = parse_ini_file($config, true);
    if(!$this->config)
        die("Config file parse failed: check your syntax!");
    require("include/modes.php");
    $this->servname   = $this->config['me']['servername'];
    $this->network    = $this->config['me']['network'];
    $this->createdate = $this->config['me']['created'];
    $this->chanModes  = $channelModes;
    $this->userModes  = $userModes;
    $listens = (empty($this->config['core']['listen'])?array():explode(',', $this->config['core']['listen']));
    foreach($listens as $l){
        $this->debug("bind to address $l");
        $c = strrpos($l, ":") or die("...malformed address");
        $addr = substr($l, 0, $c);
        $port = substr($l, $c+1);
        $this->createSocket($addr, $port);
    }
    $listens_ssl = explode(',', $this->config['core']['listen_ssl']);
    foreach($listens_ssl as $l){
        $this->debug("bind to address $l (SSL)");
        $c = strrpos($l, ":") or die("...malformed address");
        $addr = substr($l, 0, $c);
        $port = substr($l, $c+1);
        $this->createSocket($addr, $port, true);
    }
    $this->debug("listening for new clients");
}

function __destruct(){
    foreach($this->_sockets as $socket)
        fclose($socket);
}

function accept($socket, $ssl=false){
    $new = stream_socket_accept($socket, 0);
    if($ssl){
        stream_socket_enable_crypto($new, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
        if(!$new){
            $this->debug("Closing connection: unknown (broken pipe).");
            return false;
        }
        $client = new User($new, true);
    } else {
        $client = new User($new);
    }
    stream_set_blocking($new, 0);
    if(count($this->_clients) >= $this->config['ircd']['maxusers']){
        $client->send('ERROR: Maximum clients reached. Please use a different server.');
        $this->quit($client,'Error: Server Full');
        return false;
    }
    $client->send(':'.$this->servname.' NOTICE AUTH :*** Looking up your hostname...');
    $this->debug("new client: {$client->ip}");
    $hn = gethostbyaddr($client->ip);
    if($hn == $client->ip){
        $client->address = $client->ip;
        $client->send(':'.$this->servname.' NOTICE AUTH :*** Can\'t resolve your hotsname, using your IP instead.');
    } else {
        $client->address = $hn;
        $client->send(':'.$this->servname.' NOTICE AUTH :*** Found your hostname.');
    }
    $client->id = $this->client_num++;
    $this->_clients[$client->id] = $client;
    return true;
}

function createSocket($ip, $port, $ssl=false){
    if(!preg_match($this->ipv4Regex, $ip))
        $ip = '['.$ip.']';
    if($ssl){
        $arr = array('ssl'=>
            array(
                'local_cert'=>'cert.pem',
                'verify_peer'=>false,
                'allow_self_signed'=>true,
                'passphrase'=>''
            )
        );
        $ctx = stream_context_create($arr);
        $s = stream_socket_server("tls://$ip:$port", $errno, $errstr, STREAM_SERVER_LISTEN|STREAM_SERVER_BIND, $ctx);
        if(!$s)
            die("Could not bind socket: ".$errstr."\n");
        $this->_ssl_sockets[] = $s;
    } else { 
        $s = stream_socket_server("tcp://$ip:$port", $errno, $errstr, STREAM_SERVER_LISTEN|STREAM_SERVER_BIND);
        if(!$s)
            die("Could not bind socket: ".$errstr."\n");
        $this->_sockets[] = $s;
    }
}

function read($sock){
    $buf = fread($sock, 1024);
    if($buf)
        $this->debug("<< ".trim($buf));
    return $buf;
}

function write($sock, $data){
    $this->debug(">> ".$data);
    $data = substr($data, 0, 509)."\r\n";
    @fwrite($sock, $data);
}

function close($user, $sock="legacy"){
    if(is_resource($user)){
        fclose($user);
    } else {
        @fclose($user->socket);
        unset($this->_clients[$user->id]);
    }
}

function debug($msg){
    if($this->config['core']['debug'] == true)
        echo $msg . "\n";
}

//utility methods

function channelExists($chan){
    return (array_key_exists($chan, $this->_channels)===false?false:true);
}

function checkNick(&$nick){
    if(empty($nick))
        return false;
    if(!preg_match($this->nickRegex, $nick))
        return false;
    $nick = substr($nick, 0, $this->config['ircd']['nicklen']);
    return true;
}

function checkRealName(&$nick){
    if(empty($nick))
        return false;
    if(!preg_match($this->rnRegex, $nick))
        return false;
    return true;
}

function getPendingWrites(){
    $ret = array();
    foreach($this->_clients as $user){
        if(count($user->buffer)!==0)
            $ret[] = $user;
    }
    return $ret;
}

function getUserByNick($n){
    $n = strtolower($n);
    foreach($this->_clients as $u){
        if(strtolower($u->nick)  == $n)
            return $u;
    }
    return false;
}

function getUserBySocket($s){
    foreach($this->_clients as $u){
        if($u->socket  == $s)
            return $u;
    }
    return false;
}

function isupport($user){
    $chanmodes = array();
    foreach($this->chanModes as $m)
        if($m->type != Mode::TYPE_P)
           @$chanmodes[$m->type] .= $m->letter;
    ksort($chanmodes);
    $chanmodes = implode(',', $chanmodes);
    $_005 = array(
        'MAXCHANNELS' => 20,
        'CHANLIMIT'   => $this->config['ircd']['chanlimit'],
        'MAXLIST'     => "b:{$this->config['ircd']['maxbans']},e:{$this->config['ircd']['maxexcepts']},I:{$this->config['ircd']['maxinviteexcepts']}",
        'NICKLEN'     => $this->config['ircd']['nicklen'],
        'CHANNELLEN'  => $this->config['ircd']['chanlen'],
        'TOPICLEN'    => $this->config['ircd']['topiclen'],
        'KICKLEN'     => $this->config['ircd']['kicklen'],
        'AWAYLEN'     => $this->config['ircd']['awaylen'],
        'MAXTARGETS'  => 20,
        'CHANTYPES'   => $this->config['ircd']['chantypes'],
        'PREFIX'      => "(qaohv)~&@%+",
        'NETWORK'     => $this->config['me']['network'],
        'CHANMODES'   => $chanmodes,
        'WALLCHOPS'   => '',
        'WATCH'       => $this->config['ircd']['maxwatch'],
        'WATCHOPTS'   => 'A',
        'SILENCE'     => $this->config['ircd']['maxsilence'],
        'MODES'       => $this->config['ircd']['maxmodes'],
        'CASEMAPPING' => "ascii",
        'EXTBAN'      => "~,cqnr",
        'ELIST'       => "MNUCT",
        'STATUSMSG'   => "~&@%+",
        'EXCEPTS'     => '',
        'INVEX'       => ''
    );
    $pre = ":{$this->servname} 005 {$user->nick} ";
    $post = "are supported by this server";
    while (count($_005) != 0){
        $len = $nlen = strlen($pre.$post);
        $send = "";
        for($i=0;$i<2;$i++){
            foreach($_005 as $k=>$v){
                $str = $k.(!empty($v)?"=$v":'').' ';
                if($nlen+strlen($str) <= 510){
                    $send .= $str;
                    unset($_005[$k]);
                }
            }
        }
        $user->send($pre.$send.$post);
    }
}

function nickInUse($nick){
    $nick = strtolower($nick);
    foreach($this->_clients as $id=>$user){
        if(strtolower($user->nick) == $nick)
            return true;
    }
    return false;
}

function operList(){
    $opers = array();
    foreach($this->_clients as $i=>$c)
        if($c->oper)
            $opers[] = $i;
    return $opers;
}

function runUserHooks($hook, &$data){
    foreach($this->userModes as $m)
        if(isset($m->hooks[$hook]))
            if(!$m->hooks[$hook](&$data))
                return false;
    return true;
}
function runChannelHooks($hook, &$data){
    foreach($this->chanModes as $m)
        if(isset($m->hooks[$hook]))
            if(!$m->hooks[$hook](&$data))
                return false;
    return true;
}

function stripColon(&$p){
    if($p[0] == ":")
        $p = substr($p, 1);
}

}// end class

?>
