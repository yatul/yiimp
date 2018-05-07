<?php

// https://graviex.net/api/v2/tickers.json

function graviex_api_query($method, $params='')
{
	$uri = "https://graviex.net/api/v2/{$method}";
	if (!empty($params)) $uri .= "?". http_build_query($params,'','&');

	$ch = curl_init($uri);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

	$execResult = strip_tags(curl_exec($ch));

	// array required for ticker "foreach"
	$obj = json_decode($execResult, true);

	return $obj;
}


function graviex_api_query_get($method, $req = array())
{
    require_once('/etc/yiimp/keys.php');

    $reqStr=print_r( $req, true);
    //sleep(1);

    debuglog("graviex api post: $method with params: $reqStr");
    // optional secret key
    if (empty(EXCH_GRAVIEX_SECRET) && strpos($method, 'public') === FALSE) return FALSE;
    if (empty(EXCH_GRAVIEX_KEY) && strpos($method, 'public') === FALSE) return FALSE;

    $apikey = EXCH_GRAVIEX_KEY; // your API-key
    $apisecret = EXCH_GRAVIEX_SECRET; // your Secret-key

    $req['access_key'] = $apikey;
    $req['tonce'] = (time()+20)*1000;

    ksort($req);

    $postData = http_build_query($req,'','&');

    # canonical_verb is HTTP verb like GET/POST in upcase.
    # canonical_uri is request path like /api/v2/markets.
    # canonical_query is the request query sorted in alphabetica order, including access_key and tonce, e.g. access_key=xxx&foo=bar&tonce=123456789
    # The combined string looks like: GET|/api/v2/markets|access_key=xxx&foo=bar&tonce=123456789
    #def payload
    #  "#{canonical_verb}|#{canonical_uri}|#{canonical_query}"
    #end

    $payload = "GET|/api/v2/$method|$postData";


    $sign = hash_hmac('sha256', $payload, $apisecret);

    $req['signature'] = $sign;
    $uri="https://graviex.net/api/v2/$method?" . http_build_query($req,'','&');
    $ch = curl_init($uri);
    /*$verbose = fopen('/var/log/yaamp/curl', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);
    curl_setopt($ch, CURLOPT_VERBOSE, true);*/

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    //curl_setopt($ch, CURLOPT_URL, $uri);

    if (defined('CURL_IPRESOLVE_V4')){
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }
    $execResult = curl_exec($ch);
    $resData = json_decode($execResult);

    $balancesStr = print_r($execResult, true);

    debuglog("graviex api call result: $balancesStr");
    return $resData;
}

function graviex_api_query_post($method, $req = array())
{
    require_once('/etc/yiimp/keys.php');
    $uri = "https://graviex.net/api/v2/$method";
   // $uri = "http://localhost:12345/api/v2/$method";

    $reqStr=print_r($req, true);
    sleep(1);

    debuglog("graviex api post: $method with params: $reqStr");
    // optional secret key
    if (empty(EXCH_GRAVIEX_SECRET) && strpos($method, 'public') === FALSE) return FALSE;
    if (empty(EXCH_GRAVIEX_KEY) && strpos($method, 'public') === FALSE) return FALSE;

    $apikey = EXCH_GRAVIEX_KEY; // your API-key
    $apisecret = EXCH_GRAVIEX_SECRET; // your Secret-key

    $req['access_key'] = $apikey;
    $req['tonce'] = (time()+20)*1000;

    ksort($req);
    $postData = http_build_query($req,'','&');

    # canonical_verb is HTTP verb like GET/POST in upcase.
    # canonical_uri is request path like /api/v2/markets.
    # canonical_query is the request query sorted in alphabetica order, including access_key and tonce, e.g. access_key=xxx&foo=bar&tonce=123456789
    # The combined string looks like: GET|/api/v2/markets|access_key=xxx&foo=bar&tonce=123456789
    #def payload
    #  "#{canonical_verb}|#{canonical_uri}|#{canonical_query}"
    #end

    $payload = "POST|/api/v2/$method|$postData";

    $sign = hash_hmac('sha256', $payload, $apisecret);

    //$uri="https://graviex.net//api/v2/$method?" . http_build_query($req,'','&');
    $ch = curl_init($uri);

    $req['signature'] = $sign;
    $postData = http_build_query($req,'',"&");

    /*$verbose = fopen('/var/log/yaamp/curl', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);*/
    curl_setopt($ch, CURLOPT_VERBOSE, true);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    //curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch,CURLINFO_HEADER_OUT,true);
  //  curl_setopt($ch, CURLOPT_URL, $uri);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    if (defined('CURL_IPRESOLVE_V4')){
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }
    $execResult = curl_exec($ch);
    $resData = json_decode($execResult);

    $balancesStr = print_r($execResult, true);

    debuglog("graviex api call result: $balancesStr");
    return $resData;
}

//////////////////////////////////////////////////////////////////////////////////////////////////////////////

// manual update of one market
function graviex_update_market($market)
{
    $exchange = 'graviex';
    $pair=null;
    if (is_string($market))
    {
        $symbol = $market;
        $coin = getdbosql('db_coins', "symbol=:sym", array(':sym'=>$symbol));
        if(!$coin) return false;
        $pair = strtolower($symbol).'btc';
        $market = getdbosql('db_markets', "coinid={$coin->id} AND name='$exchange'");
        if(!$market) return false;

    } else if (is_object($market)) {

        $coin = getdbo('db_coins', $market->coinid);
        if(!$coin) return false;
        $symbol = $coin->getOfficialSymbol();
        $pair = strtolower($symbol).'btc';
        if (!empty($market->base_coin)) $pair = strtolower($symbol.$market->base_coin);
    }

    $t1 = microtime(true);
    $ticker = graviex_api_query("tickers/$pair.json")["ticker"];

    if (!isset($ticker)) return false;
    $price2 = ($ticker["sell"] + $ticker["buy"])/2;
    $market->price = AverageIncrement($market->price, $ticker["buy"]);
    $market->price2 = AverageIncrement($market->price2, $price2);
    $market->pricetime = time();
    $market->save();

    $apims = round((microtime(true) - $t1)*1000,3);
    user()->setFlash('message', "$exchange $symbol price updated in $apims ms");

    return true;
}


