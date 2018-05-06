<?php

//require_once ('../common/util.php');

function doGraviexListOrders($market)
{
    $params = array();
    $params["market"] = $market;
    $res = graviex_api_query_get('orders.json', $params);

    return $res;
}

function doGraviexCancelOrder($orderID)
{
    debuglog("doGraviexCancelOrder start");
	if(empty($orderID)) return false;

	$args['id'] = $orderID;
	$res = graviex_api_query_post('order/delete.json', $args);
	if($res == null || empty($res->error)) {
		$db_order = getdbosql('db_orders', "market=:market AND uuid=:uuid", array(
			':market'=>'graviex', ':uuid'=>$orderID
		));
		if($db_order) $db_order->delete();
		return true;
	}
	return false;
}

function doGraviexUpdateBalance()
{
    debuglog("doGraviexUpdateBalance start");

    $updatebalances = true;
    if (!$updatebalances) {
        return null;
    }

    $exchange = 'graviex';

    $myInfo = graviex_api_query_get('members/me.json');
    $savebalance = getdbosql('db_balances', "name='$exchange'");
    if (is_object($savebalance)) {
        $savebalance->balance = 0;
        $savebalance->onsell = 0;
        $savebalance->save();
    }

    $balances = $myInfo -> accounts;
    if (is_array($balances) ) {
        $balancesStr = print_r($balances, true);
        debuglog("doGraviexUpdateBalance balances: $balancesStr");

        foreach ($balances as $balance) {
            debuglog("doGraviexUpdateBalance forEach: $balance->currency");
            if ($balance->currency == 'btc') {
                if (is_object($savebalance)) {
                    $savebalance->balance = $balance->balance;
                    $savebalance->onsell = $balance->locked;
                    $savebalance->save();
                }
                continue;
            }

            if ($updatebalances) {
                // store available balance in market table
                $coins = getdbolist('db_coins', "symbol=:symbol OR symbol2=:symbol",
                    array(':symbol' => strtoupper($balance->currency))
                );
                if (empty($coins)) continue;
                foreach ($coins as $coin) {
                    $market = getdbosql('db_markets', "coinid=:coinid AND name='$exchange'", array(':coinid' => $coin->id));
                    if (!$market) continue;
                    $market->balance = $balance->balance;
                    $market->ontrade = $balance->locked;
                    if (floatval($balance->balance) > 0) {
                        //TODO: get deposit address
//                        debuglog("$exchange: {$coin->symbol} deposit address updated");
//                        $market->deposit_address = $balance->Address;
                    }
//                        if (!empty($balance->Address) && $market->deposit_address != $balance->Address) {
//                            debuglog("$exchange: {$coin->symbol} deposit address updated");
//                            $market->deposit_address = $balance->Address;
//                        }
                    $market->balancetime = time();
                    $market->save();
                }
            }
        }
    }
    return $balances;
}

function doGraviexTrading($quick=false)
{
    debuglog("doGraviexTrading start");
	$exchange = 'graviex';

	if (exchange_get($exchange, 'disabled')) return;

    $balances = doGraviexUpdateBalance();
    if (!is_array($balances)) {
        return;
    }

    if (!YAAMP_ALLOW_EXCHANGE) return;

    $flushall = rand(0, 8) == 0;
    if($quick) $flushall = false;

    $min_btc_trade = exchange_get($exchange, 'min_btc_trade', 0.00020000); // minimum allowed by the exchange
    $sell_ask_pct = 1.005;     // sell on ask price + 5%
    $cancel_ask_pct = 1.20;      // cancel order if our price is more than ask price + 20%

    if (is_array($balances)) {
        // auto trade
        $orders = NULL;


        foreach ($balances as $balance) {
            if ($balance->balance == 0 && $balance->locked == 0) continue;
            if ($balance->currency == 'btc') continue;

            $coin = getdbosql('db_coins', "symbol=:symbol AND dontsell=0", array(':symbol' => $balance->currency));
            if (!$coin) continue;
            $symbol = $coin->symbol;
            if (!empty($coin->symbol2)) $symbol = $coin->symbol2;

            $market = getdbosql('db_markets', "coinid=:coinid AND name='$exchange'", array(':coinid' => $coin->id));
            if (!$market) continue;
            $market->balance = $balance->balance;


            //check current orders
            $orders = doGraviexListOrders(strtolower($symbol).'btc');

            if (is_array($orders))
                foreach ($orders as $order) {
                    if (!endsWith($order->market, 'btc')) continue;

                    // ignore buy orders
                    if (stripos($order->side, 'sell') === false) continue;

                    $listingCurrency = $symbol;
                    $ticker = graviex_api_query("tickers/$order->market.json");

                    $ask = bitcoinvaluetoa($ticker["sell"]);
                    $sellprice = bitcoinvaluetoa($order->price);

                    // cancel orders not on the wanted ask range
                    if (true || $sellprice > $ask * $cancel_ask_pct || $flushall) {
                        debuglog("$exchange: cancel order $listingCurrency/BTC at $sellprice, ask price is now $ask");
                        sleep(1);
                        doGraviexCancelOrder($order->id);
                    } // store existing orders
                    else {
                        $db_order = getdbosql('db_orders', "market=:market AND uuid=:uuid", array(
                            ':market' => $exchange, ':uuid' => $order->id
                        ));
                        if ($db_order) continue;

                        //debuglog("$exchange: store order of {$order->Amount} {$symbol} at $sellprice BTC");
                        $db_order = new db_orders;
                        $db_order->market = $exchange;
                        $db_order->coinid = $coin->id;
                        $db_order->amount = $order->remaining_volume;
                        $db_order->price = $sellprice;
                        $db_order->ask = $ticker["sell"];
                        $db_order->bid = $ticker["buy"];
                        $db_order->uuid = $order->id;
                        $db_order->created = time();
                        $db_order->save();
                    }
                }

            // drop obsolete orders
            $list = getdbolist('db_orders', "coinid={$coin->id} AND market='$exchange'");
            foreach ($list as $db_order) {
                $found = false;
                if (is_array($orders))
                    foreach ($orders as $order) {
                        if (stripos($order->side, 'sell') === false) continue;
                        if ($order->id == $db_order->uuid) {
                            $found = true;
                            break;
                        }
                    }

                if (!$found) {
                    // debuglog("$exchange: delete db order {$db_order->amount} {$coin->symbol} at {$db_order->price} BTC");
                    $db_order->delete();
                }
            }

            if ($coin->dontsell) continue;

            $market->lasttraded = time();
            $market->save();

            // new orders
            $amount = floatval($balance->balance);
            if (!$amount) continue;

            if ($amount * $coin->price < $min_btc_trade) continue;

            $uri = strtolower($coin->symbol).'btc';

            $ticker = graviex_api_query("tickers/$uri.json")["ticker"];

            $sellamount = $amount;//min(amount, bidOrderValue)
            if ($coin->sellonbid)
                $sellprice = bitcoinvaluetoa($ticker["buy"]);
            else
                $sellprice = bitcoinvaluetoa($ticker["sell"] * $sell_ask_pct); // lowest ask price +5%

            $params = array('market' => strtolower($symbol).'btc', 'side' => 'sell', 'price' => $sellprice, 'volume' => $sellamount);
            $res = graviex_api_query_post('orders.json', $params);
            if (!$res || !empty($res->error)) {
                debuglog("$exchange SubmitTrade err: " . print_r($res, true));
                break;
            } else {
                $db_order = new db_orders;
                $db_order->market = $exchange;
                $db_order->coinid = $coin->id;
                $db_order->amount = $amount;
                $db_order->price = $sellprice;
                $db_order->ask = $ticker["sell"];
                $db_order->bid = $ticker["buy"];
                $db_order->uuid = $res->id;
                $db_order->created = time();
                $db_order->save();
            }
        }
    }

//    $withdraw_min = exchange_get($exchange, 'withdraw_min_btc', EXCH_AUTO_WITHDRAW);
//    $withdraw_fee = exchange_get($exchange, 'withdraw_fee_btc', 0.001);

    // auto withdraw (currently not possible on graviex)
//    if(false && is_object($savebalance)) {
//        if(floatval($withdraw_min) > 0 && $savebalance->balance >= ($withdraw_min + $withdraw_fee))
//        {
//            // $btcaddr = exchange_get($exchange, 'withdraw_btc_address', YAAMP_BTCADDRESS);
//            $btcaddr = YAAMP_BTCADDRESS;
//            $amount = $savebalance->balance - $withdraw_fee;
//            debuglog("$exchange: withdraw $amount BTC to $btcaddr");
//
//            sleep(1);
//            $params = array("currency"=>"BTC", "amount"=>$amount, "address"=>$btcaddr);
//            graviex_api_query_post('withdraw', $params);
//            $withdraw = new db_withdraws;
//            $withdraw->market = $exchange;
//            $withdraw->address = $btcaddr;
//            $withdraw->amount = $amount;
//            $withdraw->time = time();
//            $withdraw->save();
//
//            $savebalance->balance = 0;
//            $savebalance->save();
//        }
//    }
}
