<?php
if (!defined("WOO_CU_FN")) {
    require_once("woo_cu_channelunity_fn.php");
}
/*
 * ChannelUnity WooCommerce Integration
 * Ajax calls to ChannelUnity
 *
 */

//Sanitise request
if(isset($data)){
    $_POST=$data;
}
$request=preg_replace('/[^a-zA-Z0-9 .-]/','',$_POST['request']);

//Check for spam
if($request=='create') {
    $spamcheck = file_get_contents("http://www.stopforumspam.com/api?email=".$_POST['emailaddress']);
    $spamcheck = simplexml_load_string($spamcheck);
    if((string) $spamcheck->appears == "yes"){
        $result=array('result'=>'error','xml'=>'<Info>Email detected as spambot by Stop Forum Spam.com</Info>');
        echo json_encode($result);
        exit();
    }
}

//Generate XML for CU API
$auth = channelunity_getAlternateUserAuth();
$mn = preg_replace('/[^a-zA-Z0-9 .-]/','',$_POST['merchantname']);
$tosend = "<?xml version=\"1.0\" ?>".
          "<ChannelUnity>".
          "<MerchantName>".$mn."</MerchantName>".
          "<Authorization>$auth</Authorization>";
switch($request) {
    case "validate":
    case "channelunity":
        $tosend .= "<RequestType>ValidateUser</RequestType>";
        break;
    
    case "create":
        $tosend .= "<RequestType>CreateMerchantAsync</RequestType>".
                   "<Payload>".
                   "<Name>".htmlspecialchars($_POST['contactname'])."</Name>".
                   "<Company>".htmlspecialchars($_POST['merchantname'])."</Company>".
                   "<Country>".htmlspecialchars($_POST['country'])."</Country>".
                   "<EmailAddress>".htmlspecialchars($_POST['emailaddress'])."</EmailAddress>".
                   "<MobileNumber>".htmlspecialchars($_POST['telephone'])."</MobileNumber>".
                   "<InviteCode></InviteCode>".
                   "</Payload>";
        break;
    case "custom":
        $tosend .= $_POST['xml'];
        break;
    default:
        $result=array('result'=>'error','xml'=>'<Info>Invalid data</Info>');
        exit();
}
$tosend.="</ChannelUnity>";

//Make the call and get response
$recvString = channelunity_sendMessage($tosend);

$xml2 = simplexml_load_string($recvString);
if (!isset($_POST['silent'])) {
    $cu=(in_array($xml2->AccountStatus,explode(',',base64_decode('bGl2ZSx0cmlhbA=='))));
}

//Return response to AJAX caller
if ($xml2->Status == "OK") {
    $result=array('result'=>'ok','xml'=>$recvString);
} else {
    $result=array('result'=>'error','xml'=>$xml2->Status);
}
if($request!='channelunity' &&  !isset($_POST['silent'])){
    echo json_encode($result);
    exit();
}

if (isset($_POST['response'])) {
    if (!is_array($_POST['response'])) {
        $_POST['response'] = array($_POST['response']);
    }
    
    foreach ($_POST['response'] as $returnKey) {
        $data[$returnKey] = (string) $xml2->$returnKey;
    }
}

