<?php

App::uses('BaseLog', 'Log/Engine');

class JsonLog extends BaseLog {
  protected $_defaults = array(
    'path' => LOGS,
    'types' => null,
    'scopes' => array()
  );

  public function __construct($options = array()) {
    $config = Hash::merge($this->_defaults, $options);
    parent::__construct($config);
  }

  public function write($type, $message) {
    $obj=array();
    if(!is_object($message)) {
      $obj["message"] = $message;
    } 
    else {
      $obj = (array)$message;
    }
    $obj["type"]=$type;
    $obj["date"]=date('Y-m-d');
    $obj["time"]=date('H:i:s');

    $pathname = rtrim($this->_config['path'],"/") . "/log.json";
    file_put_contents($pathname, json_encode($obj)."\r\n", FILE_APPEND);
    return TRUE;
  }
}