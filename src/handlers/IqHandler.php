<?php
require_once 'Handler.php';
if (extension_loaded('curve25519') && extension_loaded('protobuf'))
{
  require_once __DIR__."/../libaxolotl-php/protocol/SenderKeyDistributionMessage.php";
  require_once __DIR__."/../libaxolotl-php/groups/GroupSessionBuilder.php";
  require_once __DIR__."/../pb_wa_messages.php";
}
require_once __DIR__."/../protocol.class.php";
require_once __DIR__."/../Constants.php";
require_once __DIR__."/../func.php";


class IqHandler implements Handler
{
  protected $node;
  protected $parent;
  protected $phoneNumber;

  public function IqHandler($parent, $node)
  {
    $this->node         = $node;
    $this->parent       = $parent;
    $this->phoneNumber  = $this->parent->getMyNumber();
  }

  public function Process()
  {
    if ($this->node->getChild("query") != null) {
        if (isset($this->parent->getNodeId()['privacy']) && ($this->parent->getNodeId()['privacy'] == $this->node->getAttribute('id'))) {
            $listChild = $this->node->getChild(0)->getChild(0);
            foreach ($listChild->getChildren() as $child) {
                $blockedJids[] = $child->getAttribute('value');
            }
            $this->parent->eventManager()->fire("onGetPrivacyBlockedList",
                array(
                    $this->phoneNumber,
                    $blockedJids
                ));
            return;
        }
        $this->parent->eventManager()->fire("onGetRequestLastSeen",
            array(
                $this->phoneNumber,
                $this->node->getAttribute('from'),
                $this->node->getAttribute('id'),
                $this->node->getChild(0)->getAttribute('seconds')
            ));
    }

    if ($this->node->getAttribute('type') == "get"
    && $this->node->getAttribute('xmlns') == "urn:xmpp:ping") {
    $this->parent->eventManager()->fire("onPing",
        array(
            $this->phoneNumber,
            $this->node->getAttribute('id')
        ));
    $this->parent->sendPong($this->node->getAttribute('id'));
    }

    if ($this->node->getChild("sync") != null) {

    //sync result
    $sync = $this->node->getChild('sync');
    $existing = $sync->getChild("in");
    $nonexisting = $sync->getChild("out");

    //process existing first
    $existingUsers = array();
    if (!empty($existing)) {
        foreach ($existing->getChildren() as $child) {
            $existingUsers[$child->getData()] = $child->getAttribute("jid");
        }
    }

    //now process failed numbers
    $failedNumbers = array();
    if (!empty($nonexisting)) {
        foreach ($nonexisting->getChildren() as $child) {
            $failedNumbers[] = str_replace('+', '', $child->getData());
        }
    }

    $index = $sync->getAttribute("index");

    $result = new SyncResult($index, $sync->getAttribute("sid"), $existingUsers, $failedNumbers);

    $this->parent->eventManager()->fire("onGetSyncResult",
        array(
            $result
        ));
    }

    if ($this->node->getChild("props") != null) {
        //server properties
        $props = array();
        foreach($this->node->getChild(0)->getChildren() as $child) {
            $props[$child->getAttribute("name")] = $child->getAttribute("value");
        }
        $this->parent->eventManager()->fire("onGetServerProperties",
            array(
                $this->phoneNumber,
                $this->node->getChild(0)->getAttribute("version"),
                $props
            ));
    }
    if ($this->node->getChild("picture") != null) {
        $this->parent->eventManager()->fire("onGetProfilePicture",
            array(
                $this->phoneNumber,
                $this->node->getAttribute("from"),
                $this->node->getChild("picture")->getAttribute("type"),
                $this->node->getChild("picture")->getData()
            ));
    }
    if ($this->node->getChild("media") != null || $this->node->getChild("duplicate") != null) {
        $this->parent->processUploadResponse($this->node);
    }
    if (strpos($this->node->getAttribute("from"), Constants::WHATSAPP_GROUP_SERVER) !== false)  {
        //There are multiple types of Group reponses. Also a valid group response can have NO children.
        //Events fired depend on text in the ID field.
        $groupList = array();
        $groupNodes = array();
        if ($this->node->getChild(0) != null && $this->node->getChild(0)->getChildren() != null) {
            foreach ($this->node->getChild(0)->getChildren() as $child) {
                $groupList[] = $child->getAttributes();
                $groupNodes[] = $child;
            }
        }
        if (isset($this->parent->getNodeId()['groupcreate']) && ($this->parent->getNodeId()['groupcreate'] == $this->node->getAttribute('id'))) {
            $this->parent->setGroupId($this->node->getChild(0)->getAttribute('id'));
            $this->parent->eventManager()->fire("onGroupsChatCreate",
                array(
                    $this->phoneNumber,
                    $this->node->getChild(0)->getAttribute('id')
                ));
        }
        if (isset($this->parent->getNodeId()['leavegroup']) && ($this->parent->getNodeId()['leavegroup'] == $this->node->getAttribute('id'))) {
            $$this->parent->setGroupId($this->node->getChild(0)->getChild(0)->getAttribute('id'));
            $this->parent->eventManager()->fire("onGroupsChatEnd",
                array(
                    $this->phoneNumber,
                    $this->node->getChild(0)->getChild(0)->getAttribute('id')
                ));
        }
        if (isset($this->parent->getNodeId()['getgroups']) && ($this->parent->getNodeId()['getgroups'] == $this->node->getAttribute('id'))) {
            $this->parent->eventManager()->fire("onGetGroups",
                array(
                    $this->phoneNumber,
                    $groupList
                ));
            //getGroups returns a array of nodes which are exactly the same as from getGroupV2Info
            //so lets call this event, we have all data at hand, no need to call getGroupV2Info for every
            //group we are interested
            foreach ($groupNodes AS $groupNode) {
                $this->handleGroupV2InfoResponse($groupNode, true);
            }

        }
    if (isset($this->parent->getNodeId()['get_groupv2_info']) && ($this->parent->getNodeId()['get_groupv2_info'] == $this->node->getAttribute('id'))) {
        $groupChild = $this->node->getChild(0);
        if ($groupChild != null) {
            $this->handleGroupV2InfoResponse($groupChild);
        }
    }
  }
    if (isset($this->parent->getNodeId()['get_lists']) && ($this->parent->getNodeId()['get_lists'] == $this->node->getAttribute('id'))) {
        $broadcastLists = array();
        if ($this->node->getChild(0) != null) {
            $childArray = $this->node->getChildren();
            foreach ($childArray as $list) {
                if ($list->getChildren() != null) {
                    foreach ( $list->getChildren() as $sublist) {
                        $id = $sublist->getAttribute("id");
                        $name = $sublist->getAttribute("name");
                        $broadcastLists[$id]['name'] = $name;
                        $recipients = array();
                        foreach ($sublist->getChildren() as $recipient) {
                            array_push($recipients, $recipient->getAttribute('jid'));
                        }
                        $broadcastLists[$id]['recipients'] = $recipients;
                    }
                }
            }
        }
        $this->parent->eventManager()->fire("onGetBroadcastLists",
            array(
                $this->phoneNumber,
                $broadcastLists
            ));
    }
    if ($this->node->getChild("pricing") != null) {
        $this->parent->eventManager()->fire("onGetServicePricing",
            array(
                $this->phoneNumber,
                $this->node->getChild(0)->getAttribute("price"),
                $this->node->getChild(0)->getAttribute("cost"),
                $this->node->getChild(0)->getAttribute("currency"),
                $this->node->getChild(0)->getAttribute("expiration")
            ));
    }
    if ($this->node->getChild("extend") != null) {
        $this->parent->eventManager()->fire("onGetExtendAccount",
            array(
                $this->phoneNumber,
                $this->node->getChild("account")->getAttribute("kind"),
                $this->node->getChild("account")->getAttribute("status"),
                $this->node->getChild("account")->getAttribute("creation"),
                $this->node->getChild("account")->getAttribute("expiration")
            ));
    }
    if ($this->node->getChild("normalize") != null) {
        $this->parent->eventManager()->fire("onGetNormalizedJid",
            array(
                $this->phoneNumber,
                $this->node->getChild(0)->getAttribute("result")
            ));
    }
    if ($this->node->getChild("status") != null) {
        $child = $this->node->getChild("status");
        foreach($child->getChildren() as $status)
        {
            $this->parent->eventManager()->fire("onGetStatus",
                array(
                    $this->phoneNumber,
                    $status->getAttribute("jid"),
                    "requested",
                    $this->node->getAttribute("id"),
                    $status->getAttribute("t"),
                    $status->getData()
                ));
        }
    }

    if (($this->node->getAttribute('type') == "error") && ($this->node->getChild("error") != null)) {
        $errorType=null;
        $this->parent->logFile('error', 'Iq error with {id} id', array('id' => $this->node->getAttribute('id')));
        foreach ($this->parent->getNodeId() AS $type => $nodeID) {
            if ($nodeID == $this->node->getAttribute('id')) {
                $errorType = $type;
                break;
            }
        }
        $this->parent->eventManager()->fire("onGetError",
            array(
                $this->phoneNumber,
                $this->node->getAttribute('from'),
                $this->node->getAttribute('id'),
                $this->node->getChild(0),
                $errorType
            ));
    }

    if (isset($this->parent->getNodeId()['cipherKeys']) && ($this->parent->getNodeId()['cipherKeys'] == $this->node->getAttribute('id'))) {
            $user_node = $this->node;
            if(!is_null($user_node))
                {
                    $user_node_child = $user_node->getChild(0);
                    if(!is_null($user_node_child))
                        {
                            $users = $user_node_child->getChildren();
                            if($users)
                                {
                                    foreach ($users as $user)
                                        {
                                            if(!is_null($user))
                                                {
                                                    $jid = $user->getAttribute('jid');
                                                    if(!is_null($user->getChild('registration')))
                                                        $registrationId = deAdjustId($user->getChild('registration')->getData());
                                                    if(!is_null($user->getChild('identity')))
                                                        $identityKey = new  IdentityKey(new DjbECPublicKey($user->getChild('identity')->getData()));
                                                    if(!is_null($user->getChild('skey')))
                                                        {
                                                            if(!is_null($user->getChild('skey')->getChild('id')))
                                                                {
                                                                    $signedPreKeyId = deAdjustId($user->getChild('skey')->getChild('id')->getData());
                                                                }
                                                            if(!is_null($user->getChild('skey')->getChild('value')))
                                                                {
                                                                    $signedPreKeyPub = new DjbECPublicKey($user->getChild('skey')->getChild('value')->getData());
                                                                }
                                                            if(!is_null($user->getChild('skey')->getChild('signature')))
                                                                {
                                                                    $signedPreKeySig = $user->getChild('skey')->getChild('signature')->getData();
                                                                }
                                                        }
                                                    if(!is_null($user->getChild('key')->getChild('id')))
                                                        $preKeyId = deAdjustId($user->getChild('key')->getChild('id')->getData());
                                                    if(!is_null($user->getChild('key')->getChild('value')))
                                                        $preKeyPublic = new DjbECPublicKey($user->getChild('key')->getChild('value')->getData());

                                                    if(isset($registrationId,$preKeyId,$preKeyPublic,$signedPreKeyId,$signedPreKeyPub,$signedPreKeySig,$identityKey))
                                                        {
                                                            $preKeyBundle = new PreKeyBundle($registrationId, 1, $preKeyId, $preKeyPublic, $signedPreKeyId, $signedPreKeyPub, $signedPreKeySig, $identityKey);
                                                            $sessionBuilder = new SessionBuilder($this->parent->getAxolotlStore(), $this->parent->getAxolotlStore(), $this->parent->getAxolotlStore(), $this->parent->getAxolotlStore(), ExtractNumber($jid), 1);

                                                            $sessionBuilder->processPreKeyBundle($preKeyBundle);
                                                            if (isset($this->parent->getPendingNodes()[ExtractNumber($jid)])) {
                                                                foreach ($this->parent->getPendingNodes()[ExtractNumber($jid)] as $pendingNode) {
                                                                    $msgHandler = new MessageHandler($this->parent, $pendingNode);
                                                                    $msgHandler->Process();
                                                                }
                                                                $this->parent->unsetPendingNode($jid);
                                                            }
                                                            $this->parent->sendPendingMessages($jid);
                                                        }
                                                }
                                        }
                                }
                        }
                }
        }
  }

  /**
   * @param ProtocolNode $groupNode
   * @param mixed        $fromGetGroups
   */
  protected function handleGroupV2InfoResponse(ProtocolNode $groupNode, $fromGetGroups = false)
  {
      $creator = $groupNode->getAttribute('creator');
      $creation = $groupNode->getAttribute('creation');
      $subject = $groupNode->getAttribute('subject');
      $groupID = $groupNode->getAttribute('id');
      $participants = array();
      $admins = array();
      if ($groupNode->getChild(0) != null) {
          foreach ($groupNode->getChildren() as $child) {
              $participants[] = $child->getAttribute('jid');
              if ($child->getAttribute('type') == "admin")
                  $admins[] = $child->getAttribute('jid');
          }
      }
      $this->parent->eventManager()->fire("onGetGroupV2Info",
          array(
              $this->phoneNumber,
              $groupID,
              $creator,
              $creation,
              $subject,
              $participants,
              $admins,
              $fromGetGroups
          )
      );
  }
}

class SyncResult
{
    public $index;
    public $syncId;
    /** @var array $existing */
    public $existing;
    /** @var array $nonExisting */
    public $nonExisting;

    public function __construct($index, $syncId, $existing, $nonExisting)
    {
        $this->index = $index;
        $this->syncId = $syncId;
        $this->existing = $existing;
        $this->nonExisting = $nonExisting;
    }
}
