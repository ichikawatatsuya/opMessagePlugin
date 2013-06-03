<?php

/**
 * This file is part of the OpenPNE package.
 * (c) OpenPNE Project (http://www.openpne.jp/)
 *
 * For the full copyright and license information, please view the LICENSE
 * file and the NOTICE file that were distributed with this source code.
 */

/**
 * PluginSendMessageDataTable
 *
 * @package    opMessagePlugin
 * @subpackage model
 * @author     Maki Takahashi <maki@jobweb.jp>
 * @author     Kousuke Ebihara <ebihara@tejimaya.com>
 */
class PluginSendMessageDataTable extends Doctrine_Table
{
 /**
  * add send message query
  *
  * @param Doctrine_Query $q
  * @param integer  $memberId
  */
  public function addSendMessageQuery($q, $memberId = null)
  {
    if (is_null($memberId))
    {
      $memberId = sfContext::getInstance()->getUser()->getMemberId();
    }
    $q = $q->where('member_id = ?', $memberId)
      ->andWhere('is_deleted = ?', false)
      ->andWhere('is_send = ?', true);
    return $q;
  }

  public function getHensinMassage($memberId, $messageId)
  {
    $obj = $this->createQuery()
      ->where('member_id = ?', $memberId)
      ->andWhere('is_send = ?', true)
      ->andWhere('return_message_id = ?', $messageId)
      ->fetchOne();
    if (!$obj) {
      return null;
    }
    return $obj;
  }

  /**
   * 送信メッセージ一覧
   * @param $memberId
   * @param $page
   * @param $size
   * @return Message object（の配列）
   */
  public function getSendMessagePager($memberId = null, $page = 1, $size = 20)
  {
    $q = $this->addSendMessageQuery($this->createQuery(), $memberId);
    $q->orderBy('created_at DESC');
    $pager = new sfDoctrinePager('SendMessageData', $size);
    $pager->setQuery($q);
    $pager->setPage($page);
    $pager->init();

    return $pager;
  }

  /**
   * 下書きメッセージ一覧
   * @param $member_id
   * @param $page
   * @param $size
   * @return Message object（の配列）
   */
  public function getDraftMessagePager($member_id, $page = 1, $size = 20)
  {
    $q = $this->createQuery()
      ->andWhere('member_id = ?', $member_id)
      ->andWhere('is_deleted = ?', false)
      ->andWhere('is_send = ?', false)
      ->orderBy('created_at DESC');

    $pager = new sfDoctrinePager('SendMessageData', $size);
    $pager->setQuery($q);
    $pager->setPage($page);
    $pager->init();

    return $pager;
  }

  public function getPreviousSendMessageData(SendMessageData $message, $myMemberId)
  {
    $q = $this->addSendMessageQuery($this->createQuery(), $myMemberId);
    $q->andWhere('id < ?', $message->id)
      ->orderBy('id DESC');

    return $q->fetchOne();
  }

  public function getNextSendMessageData(SendMessageData $message, $myMemberId)
  {
    $q = $this->addSendMessageQuery($this->createQuery(), $myMemberId);
    $q->andWhere('id > ?', $message->id)
      ->orderBy('id ASC');

    return $q->fetchOne();
  }

 /**
  * send message
  *
  * Available options:
  *
  *  * type      : The message type   (default: 'message')
  *  * identifier: The identifier of foreign table (default: 0)
  *  * is_read   : A default value of is_read flag (default: false)
  *  * fromMember: The message sender (default: my member object)
  *
  * @param mixed   $toMembers  a Member instance or array of Member instance
  * @param string  $subject    a subject of the message
  * @param string  $body       a body of the message
  * @param array   $options    options
  * @return SendMessageData
  */
  public static function sendMessage($toMembers, $subject, $body, $options = array())
  {
    $options = array_merge(array(
      'type'       => 'message',
      'identifier' => 0,
      'is_read'    => false,
    ), $options);

    if ($toMembers instanceof Member)
    {
      $toMembers = array($toMembers);
    }
    elseif (!is_array($toMembers))
    {
      throw new InvalidArgumentException();
    }

    $sendMessageData = new SendMessageData();
    if (!isset($options['fromMember']))
    {
      $options['fromMember'] = sfContext::getInstance()->getUser()->getMember();;
    }
    $sendMessageData->setMember($options['fromMember']);
    $sendMessageData->setSubject($subject);
    $sendMessageData->setBody($body);
    $sendMessageData->setForeignId($options['identifier']);
    $sendMessageData->setMessageType(Doctrine::getTable('MessageType')->getMessageTypeIdByName($options['type']));
    $sendMessageData->setIsSend(1);

    foreach ($toMembers as $member)
    {
      $send = new MessageSendList();
      $send->setSendMessageData($sendMessageData);
      $send->setMember($member);
      $send->setIsRead($options['is_read']);
      $send->save();
    }

    return $sendMessageData;
  }

  public function getMessageByTypeAndIdentifier($memberIdFrom, $memberIdTo, $messageTypeName = 'message', $identifier = 0)
  {
    $type = Doctrine::getTable('MessageType')->getMessageTypeIdByName($messageTypeName);
    if (!$type)
    {
      return false;
    }

    $q = $this->createQuery('m')
      ->select('m.id')
      ->where('m.message_type_id = ?')
      ->andWhere('m.member_id = ?')
      ->andWhere('m.foreign_id = ?');

    $obj = Doctrine::getTable('MessageSendList')->createQuery('ms')
      ->where('ms.member_id = ?', $memberIdTo)
      ->andWhere('ms.message_id IN ('.$q->getDql().')', array($type->id, $memberIdFrom, $identifier))
      ->orderBy('ms.created_at DESC')
      ->fetchOne();

    if (!$obj)
    {
      return false;
    }

    return $obj->getSendMessageData();
  }

  /** * メッセージ送受信者一覧
   * @param $member_id
   * @return member object（の配列）
   */
  public function getSenderList($memberId)
  {
    $con = $this->getConnection();
    $sql = 'select member_id from message_send_list where message_id in (select id from message where member_id = ?) and created_at in (select max(created_at) from message_send_list group by member_id) order by created_at desc';
    $memberIdList = $con->fetchAll($sql, array($memberId));

    $sql2 = 'select member_id from message where id in (select message_id from message_send_list where member_id = ?) and created_at in (select max(created_at) from message group by member_id) order by created_at desc';
    $memberIdList2 = $con->fetchAll($sql2, array($memberId));

    $members = array();
    $existIds = array();
    foreach ($memberIdList as $id)
    {
      foreach ($memberIdList2 as $id2)
      {
        if (!in_array($id2, $existIds))
        {
          $members[] = Doctrine::getTable('Member')->find($id2);
          $existIds[] = $id2;
        }
      }

      if (!in_array($id, $existIds))
      {
        $members[] = Doctrine::getTable('Member')->find($id);
        $existIds[] = $id;
      }
    }

    return $members;
  }

  /** * メンバーとの最新のメッセージ
   * @param $memberId
   * @return Message object
   */
  public function getLatestMemberMessage($memberId)
  {
    $myMemberId = sfContext::getInstance()->getUser()->getMemberId();

    $con = $this->getConnection();
    $sql = 'select * from message where id in (select message_id from message_send_list where member_id = ?) and member_id = ? order by created_at desc limit 1';
    $message = $con->fetchAll($sql, array($memberId, $myMemberId));

    $sql2 = 'select * from message where id in (select message_id from message_send_list where member_id = ?) and member_id = ? order by created_at desc limit 1';
    $message2 = $con->fetchAll($sql2, array($myMemberId, $memberId));

    $result = null;
    if (0 < count($message))
    {
      $result = $message;
    }
    elseif (0 < count($message2))
    {
      $result = $message2;
    }

    if (0 < count($message) && 0 < count($message2) && $message[0]['created_at'] < $message2[0]['created_at'])
    {
      $result = $message2;
    }

    return $result;
  }

  /** * メンバーとのメッセージを25件取得
   * @param $memberId
   * @param SmaxId
   * @return Message object list
   */
  public function getMemberMessages($memberId, $maxId = -1)
  {
    $myMemberId = sfContext::getInstance()->getUser()->getMemberId();

    $q = $this->createQuery()
      ->where('(member_id = ? OR member_id = ?)', array($memberId, $myMemberId))
      ->andWhere('id in (SELECT m.message_id FROM MessageSendList m WHERE m.member_id = ? or m.member_id = ?)', array($memberId, $myMemberId));

    if (0 <= $maxId)
    {
      $q->andWhere('id < ?', $maxId);
    }

    return $q->orderBy('created_at desc')
      ->limit(25)
      ->execute();
  }
}
