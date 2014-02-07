#!/usr/bin/php
<?php

/**
 * Cryptsy seller script
 * 
 * @author Nikolay Popov <nikolay@popoff.net.ua>
 */

class Config {

    public static $key = ''; // your Crypsy API-key
    public static $secret = ''; // your Cyptsy Secret-key
    public static $sellTargets = array('BTC', 'LTC');
    public static $doNotSell = array('BTC', 'Points');
    public static $minAmounts = array(
        'FLO' => 0.1, 'GLX' => 10.0, 'LTC' => 0.01,
    );

}

class SellCoins {

    public static $ch = NULL;

    public static function api_query($method, array $req = array()) {
        $req['method'] = $method;

        $mt = explode(' ', microtime());
        $req['nonce'] = $mt[1];

        $post_data = http_build_query($req, '', '&');
        $sign = hash_hmac("sha512", $post_data, Config::$secret);
        $headers = array('Sign: ' . $sign, 'Key: ' . Config::$key);

        self::$ch = null;
        if (is_null(self::$ch)) {
            self::$ch = curl_init();
            curl_setopt(self::$ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt(self::$ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; Cryptsy API PHP client; ' . php_uname('s') . '; PHP/' . phpversion() . ')');
        }

        curl_setopt(self::$ch, CURLOPT_URL, 'https://api.cryptsy.com/api');
        curl_setopt(self::$ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt(self::$ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt(self::$ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        // run the query
        $res = curl_exec(self::$ch);

        if ($res === false) {
            throw new Exception('Could not get reply: ' . curl_error($ch));
        }
        $dec = json_decode($res, true);
        if (!$dec) {
            throw new Exception('Invalid data received, please make sure connection is working and requested API exists');
        }
        return $dec;
    }

    public static function getOrder($markets, $currency, $balance) {
        foreach ($markets['return'] as $market) {
            if (!in_array($market['secondary_currency_code'], Config::$sellTargets)) {
                continue;
            }
            if (isset(Config::$minAmounts[$currency]) && $balance < Config::$minAmounts[$currency]) {
                continue;
            }
            if ($market['primary_currency_code'] == $currency) {
                if ($market['primary_currency_code'] == $currency) {
                    if (in_array($market['secondary_currency_code'], Config::$sellTargets)) {
                        $order = array(
                            'balance' => $balance,
                            'market_id' => $market['marketid'],
                            'target' => $market['secondary_currency_code'],
                            'price' => $market['low_trade']
                        );
                        return $order;
                    }
                }
            }
        }
        return false;
    }

    public static function run() {
        $balances2sell = array();
        try {
            self::api_query('cancelallorders');
            $balances = self::api_query("getinfo");
            $markets = self::api_query("getmarkets");
        } catch (Exception $e) {
            print "Error:" . $e->getMessage() . "\n";
            exit(-1);
        }
        if (isset($balances['success']) && $balances['success']) {
            foreach ($balances['return']['balances_available'] as $currency => $balance) {
                if ($balance > 0 && !in_array($currency, Config::$doNotSell)) {
                    if (FALSE !== ($order = self::getOrder($markets, $currency, $balance))) {
                        $balances2sell[$currency] = $order;
                    }
                }
            }
        }

        foreach ($balances2sell as $currency => $orders) {
            print "[" . date('d-m-Y H:i:s') . "] ";
            print $currency . " -> " . $orders['balance'] . " on " . $orders['target'];
            print " = " . $orders['price'] * $orders['balance'] . " ... ";
            try {
                $order = array(
                    "marketid" => $orders['market_id'],
                    "ordertype" => 'Sell',
                    "quantity" => $orders['balance'],
                    "price" => $orders['price']
                );
                $trade_result = self::api_query('createorder', $order);
                if (isset($trade_result['success']) && $trade_result['success']) {
                    print "#" . $trade_result['orderid'];
                } elseif (isset($trade_result['error']) && $trade_result['error']) {
                    print "! " . $trade_result['error'];
                } else {
                    print "?";
                }
            } catch (Exception $e) {
                print "API_ERR";
            }
            print "\n";
        }
    }

}

SellCoins::run();
