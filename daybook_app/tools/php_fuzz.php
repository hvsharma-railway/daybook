<?php
/**
 * Reference values from real PHP 7.4 for tests/test_phpcompat.py.
 * Run inside the PHP container: php tools/php_fuzz.php > tests/golden/php_fuzz.json
 */
mt_srand(20261006);
$inputs = array('0', '-0', '0.00', '-0.00', '2801', '-2801', '62288.1', '62288.10', '0.005', '-0.005', '1.235', '-1.235',
    '1.239', '-1.239', '1e3', '1.5e-7', ' 12', '12abc', 'abc', '', '.5', '5.', '+7', '00012', '9223372036854775807',
    '9223372036854775808', '216708594.96', '1100344878.76', '-0.001', '0.0049999', '123456789012345.678');
for ($i = 0; $i < 400; $i++) {
    $v = mt_rand(-99999999, 99999999) / 100;
    if ($i % 3 == 0) $v = mt_rand(-999999, 999999) / 1000;
    if ($i % 7 == 0) $v = mt_rand(-9999999, 9999999) * 1000.0 + mt_rand(0, 99) / 100;
    $inputs[] = (string) $v;
}
$floats = array();
for ($i = 0; $i < 300; $i++) {
    $a = mt_rand(-99999999, 99999999) / 100;
    $b = mt_rand(-99999999, 99999999) / 100;
    $floats[] = $a + $b;
    $floats[] = $a - $a + ($i / 1e9);
    $floats[] = $a * 1e-12;
    $floats[] = $a * 1e12;
}
$floats = array_merge($floats, array(0.1 + 0.2, 1e25, 1e-5, 0.0001, -0.0, 1.0E+15, 123456789012345.0, 1.4551915228367E-11, 2.5, -2.5, 1.005, 2.675, 1.955));
$out = array('strings' => array(), 'floats' => array());
foreach ($inputs as $s) {
    $out['strings'][] = array(
        'in' => $s,
        'neg' => (string) (-$s),
        'neg_type' => gettype(-$s),
        'ne0' => $s != 0,
        'bcadd' => bcadd($s, '0', 2),
        'add0' => (string) (0 + $s),
        'round2' => (string) round($s, 2),
        'nf2' => number_format((float) $s, 2),
        'empty' => empty($s),
    );
}
foreach ($floats as $f) {
    $out['floats'][] = array(
        'in' => sprintf('%.17g', $f),
        'str' => (string) $f,
        'round2' => (string) round($f, 2),
        'round2_repr' => sprintf('%.17g', round($f, 2)),
        'nf2' => number_format($f, 2),
        'bcadd' => bcadd($f, '0', 2),
    );
}
echo json_encode($out, JSON_PRETTY_PRINT);
