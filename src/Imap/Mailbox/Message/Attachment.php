<?php

namespace Rsi\Imap\Mailbox\Message;

class Attachment extends \Rsi\Object{

  protected $_message = null;
  protected $_part = null;
  protected $_id = null;

  protected $_data = null;

  public function __construct($message,$part,$id){
    $this->_message = $message;
    $this->_part = $part;
    $this->_id = $id;
  }
  /**
   *  Save the attachment.
   *  @param string $filename
   *  @return int  Number of bytes that written, or false on failure.
   */
  public function save($filename){
    return file_put_contents($filename,$this->data);
  }

  protected function getData(){
    if($this->_data === null) $this->_data = $this->_message->section($this->_id,$this->_part->encoding);
    return $this->_data;
  }

  protected function getDisposition(){
    return $part->ifdisposition ? $part->disposition : null;
  }

  protected function getName(){
    return $this->_message->parameter($this->_part,'filename',null,'d') ?: $this->_message->parameter($this->_part,'name');
  }

  protected function getSize(){
    $size = $this->_message->parameter($this->_part,'size',false,'d');
    return $size === false ? strlen($this->data) : $size;
  }

  protected function getType(){
    return $this->_part->ifsubtype ? $this->_part->subtype : null;
  }

}