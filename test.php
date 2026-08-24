<?php
$_GET['line'] = 'ALL';
ob_start();
include 'api_dashboard.php';
$output = ob_get_clean();
$data = json_decode($output, true);
if (isset($data['mesin'])) {
    foreach($data['mesin'] as $m) {
        if ($m['id_mesin'] == '2 PR45 - 032') {
            print_r($m);
        }
    }
}
?>
