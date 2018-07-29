<?php
/**
 * This uses the First In, First Out method when calculating capital gains and losses.
 * This is for my own personal use, don't rely on it as it could be incorrect.
 * I won't be held liable if you use this tool and it give incorrect amounts.
 */
class CoinspotData {
  private $csvData;
  private $portfolio;
  private $transactions;
  private $capitalGain;
  private $capitalLoss;
  private $orderHeadings;
  public function __construct() {
    $this->csvData = array();
    $this->portfolio = array();
    $this->transactions = array();
    $this->orderHeadings = array();
    $this->capitalGain = 0;
    $this->capitalLoss = 0;
  }

  private function importCSV($location) {
    if (($handle = fopen($location, "r")) !== FALSE) {
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $num = count($data);
            if ($num == 11) { //if valid Coinspot row
              $time = strtotime($data[0]);
              //if a valid timestamp (not a heading)
              if ($time) {
                $this->add($data);
              } else {
                foreach($data as $v) {
                  array_push($this->orderHeadings,$v);
                }
              }
            }
        }
        fclose($handle);
    }
  }

  private function add($data) {
    $time = strtotime($data[0]);
    $this->csvData[$time] = array(
      "timestamp" => $time,
      "time" => $data[0],
      "type" => $data[1],
      "market" => $data[2],
      "amount" => $data[3],
      "rate-inc-fee" => $data[4],
      "rate-exc-fee" => $data[5],
      "fee" => $data[6],
      "fee-aud" => $data[7],
      "gst" => $data[8],
      "aud" => $data[9],
      "total" => $data[10],
    );
  }

  private function sortAsc() {
    ksort($this->csvData);
  }

  private function sortDesc() {
    krsort($this->csvData);
  }

  public function getData() {
    return $this->csvData;
  }

  public function analyseRows() {
    $data = $this->getData();
    foreach($data as $key=>$order) {
      //convert this to use the be modular instead of repeated code for Buy&Sell
      if ($order["type"] == "Buy") {
        $coin = explode("/",$order["market"]); //convert to RegEx at some point if bothered to
        $coinPurchased = $coin[0];
        $coinSold = $coin[1];
        $this->addToPortfolio($coinPurchased,$order);
        $order["cgt"] = 0;
        $this->addTransaction($order["timestamp"],$order);
        $total = explode(" ",$order["total"]);
        $totalCoin = $total[0];
        $this->removeFromPortfolio($coinSold, array(
          "timestamp" => $order["timestamp"],
          "amount" => $totalCoin,
          "aud" => $order["aud"]
        ));
      }

      if ($order["type"] == "Sell") {
        //remove from portfolio
        $coin = explode("/",$order["market"]);
        $coinSold = $coin[0];
        $coinPurchased = $coin[1];
        $totalGained = $this->removeFromPortfolio($coinSold,$order);
        $order["cgt"] = $totalGained;
        $this->addTransaction($order["timestamp"],$order);

        //add the coin which it was sold for to the portfolio
        $total = explode(" ",$order["total"]);
        $totalCoin = $total[0];
        $this->addToPortfolio(
          $coinPurchased,
          array(
            "timestamp" => $order["timestamp"],
            "time" => $order["time"],
            "amount" => $totalCoin,
            "aud" => $order["aud"]
          )
        );
      }
    }
  }

  private function addToPortfolio($coin,$order) {
    $this->portfolio[$coin][$order["timestamp"]] = array(
      "time" => $order["time"],
      "amount" => $order["amount"],
      "aud-cost-per-coin" => $order["aud"] / $order["amount"],
      "aud-total" => $order["aud"]
    );
  }

  private function addToGain($gain) {
    $this->capitalGain += $gain;
  }

  private function addToLoss($loss) {
    $this->capitalLoss += $loss;
  }

  private function addTaxEvent($sellPrice,$boughtAtPrice) {
    $amount = abs($sellPrice-$boughtAtPrice);
    if ($sellPrice > $boughtAtPrice) {
      $this->addToGain($amount);
    } else {
      $this->addToLoss($amount);
    }
    return $sellPrice-$boughtAtPrice;
  }

  private function addTransaction($time,$order) {
    $this->transactions["transactions"][$time] = $order;
  }

  private function getTransactions() {
    return $this->transactions;
  }

  private function removeFromPortfolio($coin,$order) {
    $keys = array_keys($this->portfolio[$coin]);
    $totalToSell = $order["amount"]; //the amount to be removed from portfolio
    $totalGained = 0;
    //echo "|||".$coin."|||\r";
    //print_r($this->portfolio[$coin]);
    foreach($keys as $k=>$v) {
      $break = 0;
      $currentAmount = $this->portfolio[$coin][$v]["amount"];
      if ($totalToSell >= $currentAmount) {
        $sellPrice = $this->portfolio[$coin][$v]["amount"]*($order["aud"]/$order["amount"]);
        $boughtAtPrice =$this->portfolio[$coin][$v]["amount"]*$this->portfolio[$coin][$v]["aud-cost-per-coin"];
        $this->portfolio[$coin][$v]["amount"] = 0;
        $totalToSell -= $currentAmount;
        unset($this->portfolio[$coin][$v]);
      } else {
        $sellPrice = $totalToSell*($order["aud"]/$order["amount"]);
        $boughtAtPrice = $totalToSell*$this->portfolio[$coin][$v]["aud-cost-per-coin"];
        $this->portfolio[$coin][$v]["amount"] -= $totalToSell;
        if ($this->portfolio[$coin][$v]["amount"] < 0.00000001) {
          unset($this->portfolio[$coin][$v]);
        }
        $break = 1;
      }

      //echo $coin."|".$totalToSell."|".$currentAmount."|".$sellPrice."|".$boughtAtPrice."\r";
      if ($coin == "AUD") {

      }
      $totalGained += $this->addTaxEvent($sellPrice,$boughtAtPrice);

      if ($break){
        break 1;
      }
    }



    if (!count($this->portfolio[$coin])) {
      unset($this->portfolio[$coin]);
    }
    return $totalGained;
  }

  public function getPortfolio() {
    $portfolio = array();
    foreach ($this->portfolio as $coin=>$allCoins) {
      foreach($this->portfolio[$coin] as $timestamp=>$v) {
        $portfolio[$coin]["amount"] += $v["amount"];
        $portfolio[$coin]["bought-with-aud"] += $v["aud-total"];
      }
    }
    return $portfolio;
  }

  public function getGainAndLoss() {
    return array(
      "gain" => $this->capitalGain,
      "loss" => $this->capitalLoss,
      "total_gain" => ($this->capitalGain-$this->capitalLoss)
    );
  }

  public function calculateCapitalGains($csvLocation) {
    //import CSV data into array
    $this->importCSV($csvLocation);

    //sort ASC order to work forwards
    $this->sortAsc();

    //analyse each row
    $this->analyseRows();

    $data = $this->getData();
    //print_r($this->portfolio);


    //print_r($portfolio);
    echo $this->displayPortfolio();
    echo $this->displayTransactions();
  }

  private function displayPortfolio() {
    $portfolio = $this->portfolio;
    $returnStr .= "<h2>Portfolio</h2><table>";
    $returnStr .= "<tr class='footer'>";
    foreach($this->orderHeadings as $v) {
      $returnStr .= "<td>$v</td>";
    }
    $returnStr .= "</tr>";
    foreach ($portfolio as $coin=>$val) {
      foreach($val as $k=>$v) {
        $returnStr .= "<tr>
          <td>{$v['time']}</td>
          <td>Buy</td>
          <td>$coin/AUD</td>
          <td>{$v['amount']}</td>
          <td>{$v['aud-cost-per-coin']}</td>
          <td>{$v['aud-cost-per-coin']}</td>
          <td>0</td>
          <td>0</td>
          <td>0</td>
          <td>{$v['aud-total']}</td>
          <td>{$v['aud-total']}</td>
        </tr>";
      }
    }
    $returnStr .= "</table>";

/*
    foreach ($portfolio as $coin=>$val) {
      foreach($val as $k=>$v) {
        $returnStr .= "
          {$v['time']},Buy,$coin/AUD,{$v['amount']},{$v['aud-cost-per-coin']},{$v['aud-cost-per-coin']},0,0,0,{$v['aud-total']},{$v['aud-total']}";
        }
    }
    $returnStr .= "</table>";
    */
    return $returnStr;
  }

  private function displayTransactions() {
    $gains = $this->getGainAndLoss();
    $transactions = $this->getTransactions();
    $returnStr = '
    <style>
      body { font-family: sans-serif; }
      table { border-collapse: collapse; width: 100%; }
      table td { border-bottom: 1px solid rgba(0,0,0,0.1); padding: 5px; }
      .positive td { background: #c5f7d3; }
      .neutral td {  }
      .negative td { background: #f7c5c5; }
      .footer td { background: #555; color: #fff; font-weight: bold; }
    </style>';
    $returnStr .= "<h2>Gains/Losses</h2><table>";
    $returnStr .= "<tr class='footer'>";
    foreach($this->orderHeadings as $v) {
      $returnStr .= "<td>$v</td>";
    }
    $returnStr .= "<td>Gains AUD</td>";
    $returnStr .= "</tr>";
    foreach ($transactions["transactions"] as $time=>$order) {
      if ($order["cgt"]>0) {
        $class = 'positive';
      } else if ($order["cgt"]==0) {
        $class = 'neutral';
      } else {
        $class = 'negative';
      }
      $returnStr .= "<tr class='$class'>";
      foreach($order as $k=>$v) {
        if ($k != "timestamp") {
          $returnStr .= "<td>$v</td>";
        }
      }
      $returnStr .= "</tr>";
    }

    $returnStr .= "<tr class='footer'>
      <td colspan='11'>Total Capital Gains</td>
      <td>".$gains["total_gain"]."</td>
    </tr>";
    $returnStr .= "</table>";
    return $returnStr;
  }
}

$coinspot = new CoinspotData();
$coinspot->calculateCapitalGains("orders.csv");
?>
