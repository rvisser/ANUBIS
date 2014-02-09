<?php
/**
 * Created by PhpStorm.
 * User: Ruben
 * Date: 8-2-14
 * Time: 16:25
 */

// Usage:
// Add this script to crontab (or another scheduler) to run every 5 minutes
// Example for crontab:
// */5 * * * * /usr/bin/php /srv/www/htdocs/anubis/cron_buildgraphs.php

require("config.inc.php");
require("func.inc.php");
require "libchart/classes/libchart.php";

$path = dirname(__FILE__) . '/';

$dbh = anubis_db_connect();

$config = get_config_data();

$binSize = 5;

$now = time();
$start = $now - 24 * 60 * 60;

function timeToBin($time, $binSize = 5){
    global $start;
    return (int) floor(($time - $start)/(60 * $binSize));
}

function emptyBins(){
    $res = array();
    for($i = 0; $i < 288; $i++){
        $res[$i] = 0;
    }
    return $res;
}

$acceptanceRate = emptyBins();
$rejectedRate = emptyBins();
$hashRateData = emptyBins();

$host_hash = array();
$host_accepted = array();
$host_rejected = array();

$host_data_sql = "SELECT * FROM host_stats WHERE stamp > date_sub(now(), interval 1 day)";

$result = $dbh->query($host_data_sql);

while ($host_data = $result->fetch(PDO::FETCH_ASSOC)){
    $hid = $host_data['host_id'];
    if(!isset($host_hash[$hid])){
        //Host not seen on previous iterations
        $host_hash[$hid] = emptyBins();
        $host_accepted[$hid] = emptyBins();
        $host_rejected[$hid] = emptyBins();
        $acc_prev[$hid] = 0;
        $rej_prev[$hid] = 0;
    }
    $time = strtotime($host_data['stamp']);
    $bin = timeToBin($time, $binSize);
    $hashRateData[$bin] += $host_data['h_5s'] / $binSize;
    $host_hash[$hid][$bin] += $host_data['h_5s'] / $binSize;
    $acc = $host_data['accepted'];
    $rej = $host_data['rejected'];
    if($acc_prev[$hid] < $acc && $acc_prev[$hid] != 0){
        $accD = $acc - $acc_prev[$hid];
    }else $accD = 0;
    if($rej_prev[$hid] < $rej && $rej_prev[$hid] != 0){
        $rejD = $rej - $rej_prev[$hid];
    } else $rejD = 0;
    $rej_prev[$hid] = $rej;
    $acc_prev[$hid] = $acc;
    $acceptanceRate[$bin] += $accD / $binSize;
    $rejectedRate[$bin] += $rejD / $binSize;
    $host_accepted[$hid][$bin] += $accD / $binSize;
    $host_rejected[$hid][$bin] +=  $rejD / $binSize;
}

$chart_global_hashrate = new LineChart(570);
$chart_global_shares = new LineChart(570);

$dataSet_global_hashrate = new XYDataSet();
$dataSet_global_accepted = new XYDataSet();
$dataSet_global_rejected = new XYDataSet();
$host_ids = array_keys($host_hash);
foreach($host_ids as $hid){
    $chart_perhost_hashes[$hid] = new LineChart(570);
    $data_perhost_hashes[$hid] = new XYDataSet();

    $chart_perhost_shares[$hid] = new LineChart(570);
    $data_perhost_accepted[$hid] = new XYDataSet();
    $data_perhost_rejected[$hid] = new XYDataSet();
}

for($i = 0; $i < 288; $i++){
    $label = '';
    if(($i+1) % 24 == 0){
        $label = date("H:i", $start + ($i+1) * 5 * 60);
    }
    $dataSet_global_hashrate->addPoint(new Point($label, $hashRateData[$i]));
    $dataSet_global_accepted->addPoint(new Point($label, $acceptanceRate[$i]));
    $dataSet_global_rejected->addPoint(new Point($label, $rejectedRate[$i]));
    foreach($host_ids as $hid){
        $data_perhost_hashes[$hid]->addPoint(new Point($label, $host_hash[$hid][$i]));
        $data_perhost_accepted[$hid]->addPoint(new Point($label, $host_accepted[$hid][$i]));
        $data_perhost_rejected[$hid]->addPoint(new Point($label, $host_rejected[$hid][$i]));
    }
}

$chart_global_hashrate->setDataSet($dataSet_global_hashrate);
$chart_global_hashrate->setTitle('KH/s 5 min average, all hosts');
$chart_global_hashrate->render($path.'charts/global_hash.png');

$chart_global_shares->getPlot()->getPalette()->setLineColor(array(
    new Color(0, 255, 0),
    new Color(255, 0, 0)
));
$dataSeries_global_shares = new XYSeriesDataSet();
$dataSeries_global_shares->addSerie("Accepted", $dataSet_global_accepted);
$dataSeries_global_shares->addSerie("Rejected", $dataSet_global_rejected);
$chart_global_shares->setDataSet($dataSeries_global_shares);
$chart_global_shares->setTitle('Shares/min, all hosts');
$chart_global_shares->render($path.'charts/global_shares.png');

foreach($host_ids as $hid){
    $chart_perhost_hashes[$hid]->setDataSet( $data_perhost_hashes[$hid]);
    $chart_perhost_hashes[$hid]->setTitle('KH/s 5 min average, single host');
    $chart_perhost_hashes[$hid]->render($path.'charts/host_'.$hid.'_hash.png');

    $chart_perhost_shares[$hid]->getPlot()->getPalette()->setLineColor(array(
        new Color(0, 255, 0),
        new Color(255, 0, 0)
    ));
    $dataSeries_host_shares = new XYSeriesDataSet();
    $dataSeries_host_shares->addSerie("Accepted",  $data_perhost_accepted[$hid]);
    $dataSeries_host_shares->addSerie("Rejected", $data_perhost_rejected[$hid]);
    $chart_perhost_shares[$hid]->setDataSet($dataSeries_host_shares);
    $chart_perhost_shares[$hid]->setTitle('Shares/min, single host');
    $chart_perhost_shares[$hid]->render($path.'charts/host_sharestotal_'.$hid.'.png');
}

$dev_data_sql = "SELECT * FROM dev_stats WHERE stamp > date_sub(now(), interval 1 day)";

$result = $dbh->query($dev_data_sql);

$dev_graphs = array();

$prev_acc = array();
$prev_rej = array();
$prev_hw = array();

while ($dev_data = $result->fetch(PDO::FETCH_ASSOC)){
    $hid = $dev_data['host'];
    $did = $dev_data['dev'];
    $time = strtotime($dev_data['stamp']);
    $bin = timeToBin($time, $binSize);
    /*if($bin < 0 || $bin > 288){
        continue;
    }*/
    if(!isset($dev_graphs[$hid])){
        $dev_graphs[$hid][$did]['temp'] = emptyBins();
        $dev_graphs[$hid][$did]['h_5s'] = emptyBins();
        $dev_graphs[$hid][$did]['accepted'] = emptyBins();
        $dev_graphs[$hid][$did]['rejected'] = emptyBins();
        $dev_graphs[$hid][$did]['hw_err'] = emptyBins();
        $prev_acc[$hid][$did] = 0;
        $prev_rej[$hid][$did] = 0;
        $prev_hw[$hid][$did] = 0;
    }
    $acc = $dev_data['accepted'];
    $rej = $dev_data['rejected'];
    $hwe = $dev_data['hw_error'];
    if($prev_acc[$hid][$did] < $acc && $prev_acc[$hid][$did] != 0){
        $accD = $acc - $prev_acc[$hid][$did];
    }else $accD = 0;
    if($prev_rej[$hid][$did] < $rej && $prev_rej[$hid][$did] != 0){
        $rejD = $rej - $prev_rej[$hid][$did];
    } else $rejD = 0;
    if($prev_hw[$hid][$did] < $hwe && $prev_hw[$hid][$did] != 0){
        $hweD = $hwe - $prev_hw[$hid][$did];
    } else $hweD = 0;
    $prev_rej[$hid][$did] = $rej;
    $prev_acc[$hid][$did] = $acc;
    $dev_graphs[$hid][$did]['accepted'][$bin] += $accD / $binSize;
    $dev_graphs[$hid][$did]['rejected'][$bin] += $rejD / $binSize;
    $dev_graphs[$hid][$did]['hw_err'][$bin] += $hweD / $binSize;

    $dev_graphs[$hid][$did]['temp'][$bin] += $dev_data['temp'] / $binSize;
    $dev_graphs[$hid][$did]['h_5s'][$bin] += $dev_data['h_5s'] / $binSize;
}

$dev_chart = array('temp', 'accepted', 'rejected', 'hw_err', 'h_5s');

$labels = array();

for($i = 0; $i < 288; $i++){
    $label = '';
    if(($i+1) % 24 == 0){
        $label = date("H:i", $start + ($i+1) * 5 * 60);
    }
    $labels[$i] = $label;
}

$dev_sets = array();
//Global temp graph
$temp_global_series = new XYSeriesDataSet();
foreach($dev_graphs as $hid => $host){
    //Local graph
    $host_temp_series = new XYSeriesDataSet();
    $host_hash_series = new XYSeriesDataSet();;
    foreach($host as $did => $dev){
        foreach($dev_chart as $chart){
            $dev_sets[$hid][$did][$chart] = new XYDataSet();
            foreach($dev_graphs[$hid][$did][$chart] as $bin => $value){
                $dev_sets[$hid][$did][$chart]->addPoint(new Point($labels[$bin], $value));
            }
            if($chart == 'temp'){
                $temp_global_series->addSerie($hid . ' - ' . $did, $dev_sets[$hid][$did][$chart]);
                $g = new LineChart(570);
                $g->setTitle('Temperature on '.$did);
                $g->setDataSet($dev_sets[$hid][$did][$chart]);
                $g->render($path.'/charts/dev_temp_'.$hid.'_'.$did.'.png');
            }elseif($chart == 'h_5s'){
                $g = new LineChart(570);
                $g->setTitle('KH/s 5 min average on '.$did);
                $g->setDataSet($dev_sets[$hid][$did][$chart]);
                $g->render($path.'/charts/dev_hash_'.$hid.'_'.$did.'.png');
            }
        }
        $shares_local_series = new XYSeriesDataSet();
        $shares_local_series->addSerie('Accepted', $dev_sets[$hid][$did]['accepted']);
        $shares_local_series->addSerie('Rejected', $dev_sets[$hid][$did]['rejected']);
        $shares_local_series->addSerie('Hardware error', $dev_sets[$hid][$did]['hw_err']);
        $g = new LineChart(570);
        $g->setTitle('Shares by '.$did);
        $g->getPlot()->getPalette()->setLineColor(array(
            new Color(0, 255, 0),
            new Color(255, 255, 0),
            new Color(255, 0, 0)
        ));
        $g->setDataSet($shares_local_series);
        $g->render($path.'/charts/dev_shares_'.$hid.'_'.$did.'.png');

        $host_temp_series->addSerie($did, $dev_sets[$hid][$did]['temp']);
        $host_hash_series->addSerie($did, $dev_sets[$hid][$did]['h_5s']);
    }
    //Host specific graphs
    $host_temp = new LineChart(570);
    $host_temp->setTitle('Temperatures on ' . $hid);
    $host_temp->setDataSet($host_temp_series);
    $host_temp->render($path.'/charts/host_temp_'.$hid.'.png');

    $host_hash = new LineChart(570);
    $host_hash->setTitle('KH/s 5min average per device on '.$hid);
    $host_hash->setDataSet($host_hash_series);
    $host_hash->render($path.'/charts/host_hashes_'.$hid.'.png');

}
$global_temp = new LineChart();
$global_temp->setTitle('Temperatures');
$global_temp->setDataSet($temp_global_series);
$global_temp->render($path.'/charts/global_temp.png');
