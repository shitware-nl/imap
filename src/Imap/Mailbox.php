<?php

namespace Rsi\Imap;

class Mailbox extends \Rsi\Object{

  public $autoCommit = true; //!<  Perform commit on destruction (when changed).

  protected $_imap = null;
  protected $_name = null;

  protected $_changed = false; //!<  set to true if a change was made to the mailbox.
  protected $_connection = null;

  public function __construct($imap,$name = null){
    $this->_imap = $imap;
    $this->_name = $name;
    $this->publish('name');
    if($this->_imap->options && in_array(\Rsi\Imap::OPTION_READONLY,$this->_imap->options)) $this->autoCommit = false;
  }

  public function __destruct(){
    if($this->_connection){
      if($this->_changed && $this->autoCommit) $this->commit();
      imap_errors();
      imap_alerts();
      imap_close($this->_connection);
    }
  }
  /**
   *  Get messages.
   *  @param int $offset  First message index to return (negative = from end).
   *  @param int $length  Number of messages to return (empty = to end).
   *  @return array  Array of \Rsi\Imap\Mailbox\Message.
   */
  public function messages($offset = 0,$length = null){
    $count = null;
    if($offset < 0) $offset = max(0,$offset + ($count = $this->count));
    if(!$length) $length = ($count === null ? $this->count : $count) - $offset;
    $end = $offset++ + $length;
    $messages = [];
    foreach(imap_fetch_overview($this->connection,$offset . ':' . $end) as $message)
      $messages[$message->uid] = new Mailbox\Message($this,$message);
    return $messages;
  }
  /**
   *  Search for messages.
   *  @param string $query  IMAP search query (http://php.net/manual/en/function.imap-search.php).
   *  @param int $offset  Offset in search results (negative = from end).
   *  @param int $length  Number of messages to return (empty = to end).
   *  @return array  Array of \Rsi\Imap\Mailbox\Message.
   */
  public function search($query,$offset = null,$length = null){
    $messages = [];
    if($ids = imap_search($this->connection,$query,SE_FREE | SE_UID,'UTF-8'))
      foreach(imap_fetch_overview($this->connection,implode(',',array_slice($ids,$offset,$length)),FT_UID) as $message)
        $messages[$message->uid] = new Mailbox\Message($this,$message);
    return $messages;
  }
  /**
   *  Set intenal changed flag to true.
   */
  public function changed(){
    $this->_changed = true;
  }
  /**
   *  Commit all changes.
   *  @return bool  True on success.
   */
  public function commit(){
    if(!$this->_connection) return true;
    if($result = imap_expunge($this->_connection)) $this->_changed = false;
    return $result;
  }
  /**
   *  Discard all changes.
   */
  public function rollback(){
    if(!$this->_connection) return true;
    if($result = imap_close($this->_connection)) $this->_connection = null;
    return $result;
  }

  protected function getConnection(){
    if(!$this->_connection) $this->_connection = $this->_imap->openConnection($this->_name);
    return $this->_connection;
  }

  protected function getCount(){
    return imap_num_msg($this->connection);
  }

}