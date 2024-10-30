<?php

define("WOO_CU_FN", true);

//Create temporary API authentication
function channelunity_getAlternateUserAuth() {
    $auth = $_POST['username']. ":" . hash("sha256", $_POST['password']);
    $auth = base64_encode($auth);
    return $auth;
}

//Send XML to CU endpoint
function channelunity_sendMessage($xmlMessage) {
    $url = "https://my.channelunity.com/event.php";

    $fields = urlencode($xmlMessage);
    //open connection
    $ch = curl_init();
    //set the url, number of POST vars, POST data
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
    curl_setopt($ch, CURLOPT_POSTREDIR, 7);
    curl_setopt($ch, CURLOPT_TIMEOUT, 400);
    curl_setopt($ch, CURLOPT_POSTFIELDS, array('message' => $fields));
    //execute post
    $result = curl_exec($ch);
    //close connection
    curl_close($ch);
    return $result;
}

