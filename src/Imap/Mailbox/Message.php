<?php

namespace Rsi\Imap\Mailbox;

class Message extends \Rsi\Object{

  const SUB_PLAIN = 'PLAIN';
  const SUB_HTML = 'HTML';
  const SUB_ALTERNATIVE = 'ALTERNATIVE';
  const SUB_MIXED = 'MIXED';

  const HEADER_KEYS = [ //properties te retrieve from the header (property name => header name)
    'replyTo' => 'Reply-To',
    'returnTo' => 'Return-path',
    'to' => 'To'
  ];
  const MESSAGE_KEYS = [ //properties to retrieve from the message (property name => message property; empty = same)
    'answered' => null,
    'date' => null, //RFC date
    'deleted' => null,
    'draft' => null,
    'flagged' => null,
    'from' => null,
    'id' => 'message_id',
    'inReplyTo' => 'in_reply_to',
    'recent' => null,
    'references' => null,
    'seen' => null,
    'seq' => 'msgno',
    'size' => null,
    'subject' => null,
    'time' => 'udate', //Unix time
    'uid' => null,
  ];
  const FLAG_KEYS = [ //available flags (property name => flag name (empty = same, but with ucfirst)
    'answered' => null,
    'deleted' => null,
    'draft' => null,
    'flagged' => null,
    'seen' => null
  ];

  protected $_mailbox = null;
  protected $_message = null;

  protected $_sections = []; //!<  Read sections (key = ID, value = raw data).

  protected $_headers = null; //!<  Parsed header (key => value).
  protected $_structure = null; //!<  Message strcture as returned by imap_fetchstructure.
  protected $_attachments = null; //!<  Attachments (array of \Rsi\Imap\Mailbox\Message\Attachment).

  public function __construct($mailbox,$message){
    $this->_mailbox = $mailbox;
    $this->_message = $message;
  }
  /**
   *  Converts MIME-encoded text to UTF-8.
   *  @param string $str  Encoded string.
   *  @return string
   */
  public function utf8($str){
    $result = '';
    foreach(imap_mime_header_decode($str) as $part) $result .= $part->text;
    return $result;
  }
  /**
   *  Read a section.
   *  @param int|array $id  Section ID (aray will be imploded with a dot).
   *  @param int $encoding  Encoding used for this section.
   *  @return mixed  Decoded data.
   */
  public function section($id,$encoding = null){
    if(is_array($id)) $id = implode('.',$id);
    if(!array_key_exists($id,$this->_sections))
      $this->_sections[$id] = imap_fetchbody($this->_mailbox->connection,$this->uid,$id,FT_UID | FT_PEEK);
    $section = $this->_sections[$id];
    switch($encoding){
      case ENCBASE64: return base64_decode($section);
      case ENCQUOTEDPRINTABLE: return quoted_printable_decode($section);
      default: return $section;
    }
  }
  /**
   *  Retrieve a parameter value from a parameter array.
   *  @param object $part  Part to read the parameter from.
   *  @param string $attribute  Parameter name.
   *  @param mixed $default  Default value if parameter does not exist.
   *  @param string $prefix  Prefix to add for the default parameter property name.
   *  @return mixed  Value of the found parameter, or the default if not found.
   */
  public function parameter($part,$attribute,$default = null,$prefix = null){
    $if_key = 'if' . ($key = $prefix . 'parameters');
    if($part->$if_key) foreach($part->$key as $parameter)
      if(!strcasecmp($parameter->attribute,$attribute)) return $parameter->value;
    return $default;
  }
  /**
   *  Retrieve a text section from a part, or one of its sub-parts.
   *  @param object part  Part to retrieve the text from.
   *  @param string $sub_type  Part sub type to look for (SUB_PLAIN or SUB_HTML).
   *  @param int|array $id  Id of the part (1 if empty).
   *  @return string  The text, or false if not found.
   */
  protected function text($part,$sub_type = self::SUB_PLAIN,$id = null){
    $text = false;
    switch($part->type){
      case TYPETEXT:
        if($part->ifsubtype && !strcasecmp($part->subtype,$sub_type)){
          $text = $this->section($id ?: 1,$part->encoding);
          if($charset = $this->parameter($part,'charset')) $text = mb_convert_encoding($text,'UTF-8',$charset);
        }
        break;
      case TYPEMULTIPART:
        foreach($part->parts as $index => $sub_part){
          $text = $this->text($sub_part,$sub_type,array_merge($id ?: [],[$index + 1]));
          if($text !== false) break 2;
        }
        break;
    }
    return $text;
  }
  /**
   *  Retrieve all attachments from a part.
   *  @param object part  Part to attachments the text from.
   *  @param int|array $id  Id of the part.
   *  @return array  Array of \Rsi\Imap\Mailbox\Message\Attachment.
   *
   */
  protected function attachments($part,$id = null){
    $attachments = [];
    switch($part->type){
      case TYPETEXT: break;
      case TYPEMULTIPART:
        foreach($part->parts as $index => $sub_part)
          $attachments = array_merge($attachments,$this->attachments($sub_part,array_merge($id ?: [],[$index + 1])));
        break;
      default:
        $attachments[] = new Message\Attachment($this,$part,$id);
    }
    return $attachments;
  }
  /**
   *  Copy the message to another mailbox.
   *  @param string $mailbox  Mailbox to copy the message to.
   *  @return bool  True on success.
   */
  public function copy($mailbox){
    if($result = imap_mail_copy($this->_mailbox->connection,$this->uid,$mailbox,CP_UID)) $this->_mailbox->changed();
    return $result;
  }
  /**
   *  Move the message to another mailbox.
   *  @param string $mailbox  Mailbox to move the message to.
   *  @return bool  True on success.
   */
  public function move($mailbox){
    if($result = imap_mail_move($this->_mailbox->connection,$this->uid,$mailbox,CP_UID)) $this->_mailbox->changed();
    return $result;
  }
  /**
   *  Mark the message as deleted.
   *  @return bool  True on success.
   */
  public function delete(){
    if($result = imap_delete($this->_mailbox->connection,$this->uid,FT_UID)) $this->_mailbox->changed();
    return $result;
  }

  protected function getAttachments(){
    if($this->_attachments === null) $this->_attachments = $this->attachments($this->structure);
    return $this->_attachments;
  }

  protected function getHeaders(){
    if($this->_headers === null){
      $this->_headers = [];
      if(preg_match_all('/(^|\\n)([\\w\\-]+):\\s*(.*?)(\\n(?=[\\w]))/sm',trim($this->section(0)) . "\n_",$matches,PREG_SET_ORDER))
        foreach($matches as $match) $this->_headers[$match[2]] = $this->utf8($match[3]);
    }
    return $this->_headers;
  }

  protected function getHtml(){
    return $this->text($this->structure,self::SUB_HTML);
  }

  protected function getPlain(){
    return $this->text($this->structure);
  }

  protected function getStructure(){
    if(!$this->_structure) $this->_structure = imap_fetchstructure($this->_mailbox->connection,$this->uid,FT_UID);
    return $this->_structure;
  }

  protected function _get($key){
    return array_key_exists($key,self::MESSAGE_KEYS)
      ? (property_exists($this->_message,$key = self::MESSAGE_KEYS[$key] ?: $key) ? $this->utf8($this->_message->$key) : null)
      : \Rsi\Record::iget($this->headers,\Rsi\Record::get(self::HEADER_KEYS,$key,$key));
  }

  protected function _set($key,$value){
    if(!array_key_exists($key,self::FLAG_KEYS)) parent::_set($key,$value);
    elseif(call_user_func('imap_' . ($value ? 'set' : 'clear') . 'flag_full',$this->_mailbox->connection,$this->uid,'\\' . (self::FLAG_KEYS[$key] ?: ucfirst($key)),ST_UID)){
      $key = self::MESSAGE_KEYS[$key] ?: $key;
      $this->_message->$key = $value;
      $this->_mailbox->changed();
    }
  }

}