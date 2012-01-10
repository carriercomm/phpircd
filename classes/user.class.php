<?php

class user {

var $nick = NULL;
var $username;
var $realname;
var $prefix;
var $ip;
var $address;
var $socket;
var $channels = array();
var $swhois;
var $regbit = 0;
var $registered = false;
var $lastping;
var $lastpong;
var $ssl = false;
var $oper = false;
var $buffer = array();
var $readBuffer = array();
var $modes = array();

function __construct($sock, $ssl=false){
    $this->socket = $sock;
    $this->ssl = $ssl;
    $ip = stream_socket_get_name($this->socket, true);
    $c = strrpos($ip, ":");
    $this->ip = substr($ip, 0, $c);
    $this->lastping = $this->lastpong = time();
}

function __destruct(){

}

function addChannel($chan){
    $this->channels[] = $chan->name;
}

function disconnect(){
    global $ircd;
    fclose($this->socket);
    unset($ircd->_clients[$this->id]);
    unset($this);
}

function hasMode($m, $t=false){
    global $ircd;
    if(isset($this->modes[$m]))
        if($ircd->userModes[$m]->type == 'array')
            return in_array($t, $this->modes[$m]);
        else
            return true;
    return false;
}

function maskHost(){
    global $ircd;
    $address = explode('.',$this->address);
    $address['0'] = $ircd->config['ircd']['hostmask_prefix'].'-'.strtoupper(substr(hash('sha512', $address['0']), 0, 10));
    $this->prefix = $this->nick."!".$this->username."@".implode('.', $address);
    $this->setMode("x");
}

function removeChannel($chan){
    if(($k = array_search($chan->name, $this->channels)) !== FALSE)
        unset($this->channels[$k]);
}

function send($msg){
    $this->buffer[] = $msg;
}

function setMode($m){
    $this->modes[$m] = true;
    $this->send(":{$this->nick} MODE {$this->nick} +$m");
}

function writeBuffer(){
    global $ircd;
    foreach($this->buffer as $k=> $msg){
        $ircd->write($this->socket, $msg);
        unset($this->buffer[$k]);
    }
}

}

?>
