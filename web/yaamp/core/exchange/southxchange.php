<?php

function southxchange_api_query($method)
{
	$uri = "https://www.southxchange.com/api/$method";
    debuglog("southxchange api: $method");

	$ch = curl_init($uri);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);

	$execResult = curl_exec($ch);
	$obj = json_decode($execResult);
    debuglog("southxchange api call result: $execResult");

	return $obj;
}

function southxchange_api_query_post($method, $req = array())
{
    require_once('/etc/yiimp/keys.php');
    $uri = "https://www.southxchange.com/api/$method";

    $reqStr=implode("|", $req);

    debuglog("southxchange api post: $method with params: $reqStr");
    // optional secret key
    if (empty(EXCH_SOUTHXCHANGE_SECRET) && strpos($method, 'public') === FALSE) return FALSE;
    if (empty(EXCH_SOUTHXCHANGE_KEY) && strpos($method, 'public') === FALSE) return FALSE;

    $apikey = EXCH_SOUTHXCHANGE_KEY; // your API-key
    $apisecret = EXCH_SOUTHXCHANGE_SECRET; // your Secret-key

    $req['key'] = $apikey;
    $req['nonce'] = time();

    $postData = json_encode($req);

    $sign = hash_hmac('sha512', $postData, $apisecret);

    $ch = curl_init($uri);
    $verbose = fopen('/var/log/yaamp/curl', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);
    curl_setopt($ch, CURLOPT_VERBOSE, true);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json',   "Hash:$sign"));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_URL, $uri);

    $execResult = curl_exec($ch);
    $resData = json_decode($execResult);

    debuglog("southxchange api call result: $execResult");
    return $resData;
}

//////////////////////////////////////////////////////////////////////////////////////////////////////////////

// manual update of one market
function southxchange_update_market($market)
{
	$exchange = 'southxchange';
	if (is_string($market))
	{
		$symbol = $market;
		$coin = getdbosql('db_coins', "symbol=:sym", array(':sym'=>$symbol));
		if(!$coin) return false;
        $pair = strtolower($symbol).'/BTC';
		$market = getdbosql('db_markets', "coinid={$coin->id} AND name='$exchange'");
		if(!$market) return false;

	} else if (is_object($market)) {

		$coin = getdbo('db_coins', $market->coinid);
		if(!$coin) return false;
		$symbol = $coin->getOfficialSymbol();
		$pair = strtolower($symbol).'/BTC';
		if (!empty($market->base_coin)) $pair = $symbol.'/'.$market->base_coin;
	}

	$t1 = microtime(true);
	$ticker = southxchange_api_query("price/$pair");

    if (!isset($ticker)) return false;
	$price2 = ($ticker->Bid + $ticker->Ask)/2;
    $market->price = AverageIncrement($market->price, $ticker->Bid);
    $market->price2 = AverageIncrement($market->price2, $price2);
    $market->pricetime = time();
    $market->save();

    $apims = round((microtime(true) - $t1)*1000,3);
    user()->setFlash('message', "$exchange $symbol price updated in $apims ms");

	return true;
}


