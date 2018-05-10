<?php

function doSouthxchangeListOrders()
{
    $res = southxchange_api_query_post('listOrders');

    return $res;
}

function doSouthxchangeCancelOrder($orderID)
{
    debuglog("doSouthxchangeCancelOrder start");
	if(empty($orderID)) return false;


	$args['orderCode'] = $orderID;
	$res = southxchange_api_query_post('cancelOrder', $args);
	if($res == null) {
		$db_order = getdbosql('db_orders', "market=:market AND uuid=:uuid", array(
			':market'=>'southxchange', ':uuid'=>$orderID
		));
		if($db_order) $db_order->delete();
		return true;
	}
	return false;
}

function doSouthxchangeUpdateBalance()
{
    debuglog("doSouthxchangeUpdateBalance start");
    $exchange = 'southxchange';
    $updatebalances=true;
    if (!$updatebalances) {
        return null;
    }

    $balances = southxchange_api_query_post('listBalances');
    $savebalance = getdbosql('db_balances', "name='$exchange'");
    if (is_object($savebalance)) {
        $savebalance->balance = 0;
        $savebalance->onsell = 0;
        $savebalance->save();
    }


    if (is_array($balances) ) {
        $balancesStr = print_r($balances, true);

        debuglog("doSouthxchangeUpdateBalance balances: $balancesStr");

        foreach ($balances as $balance) {
            debuglog("doSouthxchangeUpdateBalance forEach: $balance->Currency");
            if ($balance->Currency == 'BTC') {
                if (is_object($savebalance)) {
                    $savebalance->balance = $balance->Available;
                    $savebalance->onsell = $balance->Deposited;
                    $savebalance->save();
                }
                continue;
            }

            if ($updatebalances) {
                // store available balance in market table
                $coins = getdbolist('db_coins', "symbol=:symbol OR symbol2=:symbol",
                    array(':symbol' => $balance->Currency)
                );
                if (empty($coins)) continue;
                foreach ($coins as $coin) {
                    $market = getdbosql('db_markets', "coinid=:coinid AND name='$exchange'", array(':coinid' => $coin->id));
                    if (!$market) continue;
                    $market->balance = $balance->Available;
                    $market->ontrade = $balance->Deposited;
                    if (property_exists($balance, 'Address'))
                        if (!empty($balance->Address) && $market->deposit_address != $balance->Address) {
                            debuglog("$exchange: {$coin->symbol} deposit address updated");
                            $market->deposit_address = $balance->Address;
                        }
                    $market->balancetime = time();
                    $market->save();
                }
            }
        }
    }
    return $balances;
}

function doSouthxchangeTrading($quick=false)
{
    debuglog("doSouthxchangeTrading start");
	$exchange = 'southxchange';

	if (exchange_get($exchange, 'disabled')) return;

    $balances = doSouthxchangeUpdateBalance();
    if (!is_array($balances)) {
        return;
    }

    $savebalance = getdbosql('db_balances', "name='$exchange'");

    if (!YAAMP_ALLOW_EXCHANGE) return;

    $flushall = rand(0, 8) == 0;
    if($quick) $flushall = false;

    $min_btc_trade = exchange_get($exchange, 'min_btc_trade', 0.00010000); // minimum allowed by the exchange
    $sell_ask_pct = 0.9999;        // sell on ask price - 0.01%
    $cancel_ask_pct = 1.20;      // cancel order if our price is more than ask price + 20%

    if (is_array($balances)) {
        // auto trade
        $orders = NULL;
        //check current orders
        $orders = doSouthxchangeListOrders();

        foreach ($balances as $balance) {
            if ($balance->Available == 0 && $balance->Deposited == 0) continue;
            if ($balance->Currency == 'BTC') continue;

            $coin = getdbosql('db_coins', "symbol=:symbol AND dontsell=0", array(':symbol' => $balance->Currency));
            if (!$coin) continue;
            $symbol = $coin->symbol;
            if (!empty($coin->symbol2)) $symbol = $coin->symbol2;

            $market = getdbosql('db_markets', "coinid=:coinid AND name='$exchange'", array(':coinid' => $coin->id));
            if (!$market) continue;
            $market->balance = $balance->Deposited;


            if (is_array($orders))
                foreach ($orders as $order) {
                    if ($order->ReferenceCurrency != 'BTC') continue;

                    // ignore buy orders
                    if (stripos($order->Type, 'sell') === false) continue;

                    $listingCurrency = $order->ListingCurrency;
                    $ticker = southxchange_api_query("price/$listingCurrency/BTC");

                    $ask = bitcoinvaluetoa($ticker->Ask);
                    $sellprice = bitcoinvaluetoa($order->LimitPrice);

                    // cancel orders not on the wanted ask range
                    if ($sellprice > $ask * $cancel_ask_pct || $flushall) {
                        debuglog("$exchange: cancel order $listingCurrency/BTC at $sellprice, ask price is now $ask");
                        sleep(1);
                        doSouthxchangeCancelOrder($order->Code);
                    } // store existing orders
                    else {
                        $db_order = getdbosql('db_orders', "market=:market AND uuid=:uuid", array(
                            ':market' => $exchange, ':uuid' => $order->Code
                        ));
                        if ($db_order) continue;

                        //debuglog("$exchange: store order of {$order->Amount} {$symbol} at $sellprice BTC");
                        $db_order = new db_orders;
                        $db_order->market = $exchange;
                        $db_order->coinid = $coin->id;
                        $db_order->amount = $order->Amount;
                        $db_order->price = $sellprice;
                        $db_order->ask = $ticker->Ask;
                        $db_order->bid = $ticker->Bid;
                        $db_order->uuid = $order->Code;
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
                        if (stripos($order->Type, 'sell') === false) continue;
                        if ($order->Code == $db_order->uuid) {
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
            $amount = floatval($balance->Available);
            if (!$amount) continue;

            if ($amount * $coin->price < $min_btc_trade) continue;

            sleep(1);

            $data = southxchange_api_query("book/$symbol/BTC");
            if (!$data) continue;

            if (!isset($data->BuyOrders[0])) break;

            $nextbuy = $data->BuyOrders[0];
            $ticker = southxchange_api_query("price/$symbol/BTC");

            $sellamount = min($amount, $nextbuy->Amount);
            if ($coin->sellonbid)
                $sellprice = bitcoinvaluetoa($ticker->Bid);
            else
                $sellprice = bitcoinvaluetoa($ticker->Ask * $sell_ask_pct); // lowest ask price +5%


            // if($sellamount * $sellprice < $min_btc_trade) continue;

            //debuglog("$exchange: selling $sellamount $symbol at $sellprice");
            sleep(1);
            $params = array('listingCurrency' => $symbol, 'referenceCurrency' => 'BTC', 'Type' => 'sell', 'limitPrice' => $sellprice, 'amount' => $sellamount);
            $res = southxchange_api_query_post('placeOrder', $params);
            if (!$res) {
                debuglog("$exchange SubmitTrade err: " . json_encode($res));
                break;
            } else {
                $db_order = new db_orders;
                $db_order->market = $exchange;
                $db_order->coinid = $coin->id;
                $db_order->amount = $amount;
                $db_order->price = $sellprice;
                $db_order->ask = $ticker->Ask;
                $db_order->bid = $ticker->Bid;
                $db_order->uuid = $res->result;
                $db_order->created = time();
                $db_order->save();
            }
        }
    }

    $withdraw_min = exchange_get($exchange, 'withdraw_min_btc', EXCH_AUTO_WITHDRAW);
    $withdraw_fee = exchange_get($exchange, 'withdraw_fee_btc', 0.0002);

    // auto withdraw
    if(is_object($savebalance))
        if(floatval($withdraw_min) > 0 && $savebalance->balance >= ($withdraw_min + $withdraw_fee))
        {
            // $btcaddr = exchange_get($exchange, 'withdraw_btc_address', YAAMP_BTCADDRESS);
            $btcaddr = YAAMP_BTCADDRESS;
            $amount = $savebalance->balance - $withdraw_fee;
            debuglog("$exchange: withdraw $amount BTC to $btcaddr");

            sleep(1);
            $params = array("currency"=>"BTC", "amount"=>$amount, "address"=>$btcaddr);
            southxchange_api_query_post('withdraw', $params);
            $withdraw = new db_withdraws;
            $withdraw->market = $exchange;
            $withdraw->address = $btcaddr;
            $withdraw->amount = $amount;
            $withdraw->time = time();
            $withdraw->save();

            $savebalance->balance = 0;
            $savebalance->save();
        }
}
