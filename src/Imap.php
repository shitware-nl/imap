<?php

namespace Rsi;

class Imap extends \Rsi\Object{

  const OPTION_SECURE = 'secure'; //!<  Do not transmit a plaintext password over the network
  const OPTION_SSL = 'ssl'; //!<  Use the Secure Socket Layer to encrypt the session
  const OPTION_VALIDATE = 'validate-cert'; //!<  Validate certificates from TLS/SSL server (this is the default behavior)
  const OPTION_NOVALIDATE = 'novalidate-cert'; //!<  Do not validate certificates from TLS/SSL server, needed if server uses self-signed certificates
  const OPTION_TLS = 'tls'; //!<  Force use of start-TLS to encrypt the session, and reject connection to servers that do not support it
  const OPTION_NOTLS = 'notls'; //!<  Do not do start-TLS to encrypt the session, even with servers that support it
  const OPTION_READONLY = 'readonly'; //!<  Request read-only mailbox open (IMAP only; ignored on NNTP, and an error with SMTP and POP3)

  protected $_host = null;
  protected $_options = null;
  protected $_port = null;
  protected $_username = null;
  protected $_password = null;

  protected $_connection = null; //!<  IMAP stream.

  /**
   *  Construct a new IMAP object.
   *  @param string $host  Mail host name.
   *  @param string $username  Mailbox username.
   *  @param string $password  Mailbox password for the username.
   *  @param array $options  IMAP flags without the leading slash (see OPTIONS_* constants for some of them).
   *  @param int port  TCP port number (empty = default).
   */
  public function __construct($host,$username,$password,$options = null,$port = null){
    $this->_host = $host;
    $this->_options = $options;
    $this->_port = $port;
    $this->_username = $username;
    $this->_password = $password;
    $this->publish(['host','username','options']);
  }

  public function __destruct(){
    if($this->_connection){
      imap_errors();
      imap_alerts();
      imap_close($this->_connection);
    }
  }
  /**
   *  Open a connection.
   *  @param string $mailbox  Mailbox to connect to (false = open half; empty = default).
   *  @return connection
   */
  public function openConnection($mailbox = null){
    set_error_handler(function($error_no,$message){
      if($errors = imap_errors()) $message .= ': ' . array_shift($errors);
      throw new \Exception($message);
    });
    $connection = imap_open($this->server . $mailbox,$this->_username,$this->_password,$mailbox === false ? OP_HALFOPEN : null,1);
    restore_error_handler();
    if($errors = imap_errors()) throw new \Exception(array_shift($errors));
    if(!$connection) throw new \Exception('No connection created');
    return $connection;
  }
  /**
   *  Open a mailbox.
   *  @param string $name  Mailbox to connect to (empty = default).
   *  @return \Rsi\Imap\Mailbox
   */
  public function mailbox($name = null){
    return new Imap\Mailbox($this,$name);
  }

  protected function getConnection(){
    if(!$this->_connection) $this->_connection = $this->openConnection(false);
    return $this->_connection;
  }

  protected function getNames(){
    $names = [];
    foreach(imap_list($this->connection,$this->server,'*') as $name) $names[] = substr($name,strpos($name,'}') + 1);
    return $names;
  }

  protected function getServer(){
    return '{' . $this->_host . ($this->_port ? ':' . $this->_port : '') . ($this->_options ? '/' . implode('/',$this->_options) : '') . '}';
  }

}