<?php

use WHMCS\Carbon;
use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

# Set this to match your Goal Event name in Google Ads
/*
define('CONVERSION_NAME', 'Purchase GA4 Event');
define('TIME_ZONE', 'America/Halifax');
*/

$dateFilter = Carbon::create(
    $year,
    $month,
    1
);

$startOfMonth = $dateFilter->startOfMonth()->toDateTimeString();
$endOfMonth = $dateFilter->endOfMonth()->toDateTimeString();

$reportdata["title"] = "Monthly Orders Report for " . $months[(int) $month] . " " . $year;
$reportdata["description"] = "This report provides a summary of order activity for a given month.";

$reportdata["currencyselections"] = true;
$reportdata["monthspagination"] = true;


$reportdata["tableheadings"] = array(
    "Order ID",
    //"Conversion Name",
    "Date",
    "Source",
    "Client Name",
    "Total Amount"
);

$reportvalues = array();
$dateFormat = Capsule::raw('date_format(date, "%e")');

$result = Capsule::table('tblorders')
    ->join('tblclients', 'tblclients.id', '=', 'tblorders.userid')
    /* The next two are to connect through tblhosting to tblaffiliatesaccounts */
    ->join('tblhosting', 'tblhosting.orderid', '=', 'tblorders.id')
    ->leftJoin('tblaffiliatesaccounts', 'tblaffiliatesaccounts.relid', '=', 'tblhosting.id')
    ->whereBetween(
        'tblclients.datecreated',
        [
            $startOfMonth,
            $endOfMonth
        ]
    )
    ->where('tblclients.currency', $currencyid)
    ->where('tblorders.status', 'Active')
    ->whereBetween(
        'date',
        [
            $startOfMonth,
            $endOfMonth
        ]
    )
    ->orderBy('date')
    //->groupBy($dateFormat)
    ->get(
        [
            'tblorders.id',
            'tblorders.date',
            'tblclients.id as clientid',
            'tblclients.firstname',
            'tblclients.lastname',
            'tblorders.amount',
            Capsule::raw('date_format(date, "%e") as day_of_month'),
            'tblorders.admin_requestor_id',
            'tblaffiliatesaccounts.affiliateid',
            //Capsule::raw('SUM(amount) as sum_amount')
        ]
    )
    ->all();

// Table Data
foreach ($result as $data) {
    //SOURCE of order
    $source = '';
    if ($data->admin_requestor_id) $source = 'Admin Placed';
    if ($data->affiliateid) $source = 'Affiliate Assigned';

    if (empty($source)){
        $c_details = localAPI('GetClientsDetails', array(
            'clientid' => $data->clientid,
        ));
        if ($c_details['result'] == 'success'){
            //$source = print_r($c_details['client']['customfields'], true);
            foreach ($c_details['client']['customfields'] as $cf){
                if ($cf['id'] == 86){ //how did you find us
                    if (!empty($cf['value'])) $source = '<strong>Manually Selected:</strong> ' . $cf['value'];
                }
            }
        }
    }

    $reportdata["tablevalues"][] = array(
        "<a href='orders.php?action=view&id={$data->id}'>{$data->id}</a>",
        $data->date,
        $source,
        $data->firstname . ' ' . $data->lastname . ' [' . $data->clientid . ']',
        $data->amount
    );
    $overallbalance += $data->amount;

    //For chart, not table
    $chartvalues[$data->day_of_month] = array(
        'amount' => $chartvalues[$data->day_of_month]['amount'] += $data->amount,
    );
}



// Chart Data
for ($dayOfTheMonth = 1; $dayOfTheMonth <= $dateFilter->lastOfMonth()->day; $dayOfTheMonth++) {
    $amount = isset($chartvalues[$dayOfTheMonth]['amount']) ? $chartvalues[$dayOfTheMonth]['amount'] : '0';
    $chartdata['rows'][] = array('c'=>array(array('v'=>$dayOfTheMonth),array('v'=>$amount,'f'=>formatCurrency($amount))));
    $dayOfTheMonth = str_pad($dayOfTheMonth, 2, "0", STR_PAD_LEFT);
    /*
    $reportdata["tablevalues"][] = array(
        fromMySQLDate("$year-$month-$dayOfTheMonth"),
        $amount,
    );
    */
}


$reportdata["footertext"] = '<p align="center"><strong>Total Orders: ' . count($reportdata["tablevalues"]) . ' | Total Amount: ' . formatCurrency($overallbalance) . '</strong></p>';

$chartdata['cols'][] = array('label'=>'Days Range','type'=>'string');
$chartdata['cols'][] = array('label'=>'Amount','type'=>'number');

$args['colors'] = '#80D044,#F9D88C,#CC0000';

$reportdata["headertext"] = $chart->drawChart('Area', $chartdata, $args, '450px');
