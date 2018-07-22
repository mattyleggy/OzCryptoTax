<?php
//First In, First Out method
class CoinspotData {
  private $csvData;
  private $portfolio;
  private $capitalGain;
  private $capitalLoss;
  public function __construct($ausDollars) {
    $this->csvData = array();
    $this->portfolio = array();
    $this->capitalGain = 0;
    $this->capitalLoss = 0;
    $this->portfolio["AUD"][0] = array(
        "amount" => $ausDollars,
        "aud-cost-per-coin" => 1,
        "aud-total" => $ausDollars
    );
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
        $this->removeFromPortfolio($coinSold,$order);

        //add the coin which it was sold for to the portfolio
        $total = explode(" ",$order["total"]);
        $totalCoin = $total[0];
        $this->addToPortfolio(
          $coinPurchased,
          array(
            "timestamp" => $order["timestamp"],
            "amount" => $totalCoin,
            "aud" => $order["aud"]
          )
        );
      }
    }
  }

  private function addToPortfolio($coin,$order) {
    $this->portfolio[$coin][$order["timestamp"]] = array(
      "amount" => $order["amount"],
      "aud-cost-per-coin" => $order["aud"] / $order["amount"],
      "aud-total" => $order["aud"]
    );
  }

  private function removeFromPortfolio($coin,$order) {
    $keys = array_keys($this->portfolio[$coin]);
    $totalToSell = $order["amount"]; //the amount to be removed from portfolio
    foreach($keys as $k=>$v) {      
      if ($totalToSell >= $this->portfolio[$coin][$v]["amount"]) {
        $this->portfolio[$coin][$v]["amount"] = 0;
        $totalToSell -= $v["amount"];
        unset($this->portfolio[$coin][$v]);
      } else {
        $this->portfolio[$coin][$v]["amount"] -= $totalToSell;
        break 1;
      }
    }
    if (!count($this->portfolio[$coin])) {
      unset($this->portfolio[$coin]);
    }
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

  public function calculateCapitalGains($csvLocation) {
    //import CSV data into array
    $this->importCSV($csvLocation);

    //sort ASC order to work forwards
    $this->sortAsc();

    //analyse each row
    $this->analyseRows();

    $data = $this->getData();
    print_r($this->portfolio);

    $portfolio = $this->getPortfolio();
    //print_r($portfolio);
  }
}

$coinspot = new CoinspotData(4000);
$coinspot->calculateCapitalGains("orders.csv");
?>
