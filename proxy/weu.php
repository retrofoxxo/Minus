<?php
/*
 * t.tt1.nl/proxy/weu.php  -  emulation of the TomTom Plus "traffic" service
 *
 * Derived from /bin/ttn in navcore_8_010_9369_limerick.cab (the traffic client
 * that keeps traffic.inc; service id 17, module "nt").
 *
 * REQUESTS (same URL style as proxyColibri.php / buddy.php):
 *   weu.php?u=..&a=..&m=nt<cmd>&r=2&t=..&p1=..&p2=..&..&z=..&x=..
 *     m = ntgtmo  / ntgtuo / ntgstuo   overview: all current traffic messages
 *     m = ntgtd2                       details of one message, p3 = message id
 *     (p1 / p2 of the overview calls are two opaque tokens the device got in the
 *      previous reply and sends back; this script just hands out new ones)
 *
 * REPLY FORMAT (plain variant, same wrapper as bin_test.php / buddy.php):
 *   8 chars length (> 7) + 4 chars status (200) + 4 chars element count + elements
 *   element '1' = integer : '1' + 12 chars decimal
 *   element '4' = table   : '4' + field sep + row sep + 6 chars length + data
 *
 *   ntgtmo (the first request) = ONE element: a table with 7 fields per row
 *       id | dir | position | event | f | g | h
 *   ntgtuo / ntgstuo = 3 elements: int token1, int token2, table with 8 fields per row
 *       flag | id | dir | position | event | f | g | h
 *       (the device checks every reply type with its own format verifier; a
 *        gtmo reply in the 3-element layout is rejected as a server error)
 *     flag      1 = one kind of message, anything else = the other kind
 *     id        message id (24 bit)
 *     dir       '+' / '-'  (direction), anything else = none
 *     position  '>' + lon*1e5 + 'x' + lat*1e5      e.g. >490000x5237000
 *     event     event code (looks like a TMC / Alert-C code)
 *     f         signed number
 *     g, h      numbers (the device multiplies them by 10)
 *   details reply = 1 element: table with ONE row of 8 fields:
 *       flag, id, text2, text3, MAIN TEXT, text5, int6, int7
 *
 * The meaning of event / f / g / h and of the detail text slots is inferred
 * from the firmware; see the notes in the answer. Add ?debug=1 for a readable dump.
 */

$LOG = false;   // true = append every request to weu_requests.log

// flag, id, dir, lon, lat, event, f, g, h   |   road, loc, text, extra, n1, n2  (details)
$INCIDENTS = array(
    array('flag'=>1,'id'=>1001,'dir'=>'+','lon'=>4.3800,'lat'=>51.9900,'event'=>101,'f'=>15,'g'=>30,'h'=>12,'road'=>'A13','loc'=>'Delft - Rotterdam','text'=>'Stationary traffic','extra'=>'Queue length 3 km','n1'=>3,'n2'=>15),
    array('flag'=>1,'id'=>1002,'dir'=>'-','lon'=>4.2500,'lat'=>51.9200,'event'=>201,'f'=>-5,'g'=>20,'h'=>6,'road'=>'A20','loc'=>'Vlaardingen - Maassluis','text'=>'Accident','extra'=>'Right lane blocked','n1'=>1,'n2'=>20),
    array('flag'=>5,'id'=>100,'dir'=>'-','lon'=>5.40864,'lat'=>51.58238,'event'=>32,'f'=>-50,'g'=>200,'h'=>60,'road'=>'A20','loc'=>'Vlaardingen - Maassluis','text'=>'Accident','extra'=>'so because this fucking motherfucker decided to speed in a school zone were all fucked. Fuck','n1'=>10,'n2'=>200),
    array('flag'=>2,'id'=>1003,'dir'=>'+','lon'=>4.5700,'lat'=>52.0500,'event'=>105,'f'=>8,'g'=>10,'h'=>4,'road'=>'A12','loc'=>'Zoetermeer - Gouda','text'=>'Slow traffic','extra'=>'Delay about 8 minutes','n1'=>8,'n2'=>10),
    array('flag'=>2,'id'=>1004,'dir'=>'-','lon'=>4.4800,'lat'=>52.1600,'event'=>108,'f'=>0,'g'=>15,'h'=>5,'road'=>'A4','loc'=>'Leiden - Zoeterwoude','text'=>'Queuing traffic','extra'=>'Queue length 2 km','n1'=>2,'n2'=>10),
    array('flag'=>1,'id'=>1005,'dir'=>'+','lon'=>5.0400,'lat'=>52.0600,'event'=>401,'f'=>20,'g'=>40,'h'=>30,'road'=>'A2','loc'=>'Utrecht - Oudenrijn','text'=>'Road closed','extra'=>'Closed for roadworks','n1'=>0,'n2'=>45),
    array('flag'=>2,'id'=>1006,'dir'=>'','lon'=>4.9000,'lat'=>52.3700,'event'=>112,'f'=>-12,'g'=>5,'h'=>2,'road'=>'A10','loc'=>'Amsterdam ring','text'=>'Heavy traffic','extra'=>'Expect delays','n1'=>12,'n2'=>5),
);

@ini_set('zlib.output_compression', '0');
@ini_set('display_errors', '0');
while (ob_get_level() > 0) { ob_end_clean(); }

if ($LOG) {
    @file_put_contents(dirname(__FILE__) . '/weu_requests.log',
        date('c') . ' ' . $_SERVER['REMOTE_ADDR'] . ' ' . $_SERVER['REQUEST_METHOD'] . ' ' .
        $_SERVER['REQUEST_URI'] . "\n" . file_get_contents('php://input') . "\n\n", FILE_APPEND);
}

$FS = '|';       // field separator
$RS = "\n";      // row separator

function clean($s) {
    global $FS, $RS;
    return str_replace(array($FS, $RS, "\r", "\t"), ' ', (string)$s);
}
function el_int($v) {
    return '1' . sprintf('%12d', (int)$v);
}
function el_table($rows) {
    global $FS, $RS;
    $lines = array();
    foreach ($rows as $r) {
        $cells = array();
        foreach ($r as $c) { $cells[] = clean($c); }
        $lines[] = implode($FS, $cells);
    }
    $data = implode($RS, $lines);
    return '4' . $FS . $RS . sprintf('%06d', strlen($data)) . $data;
}
function message($status, $elements) {
    $rest = sprintf('%04d', $status) . sprintf('%04d', count($elements)) . implode('', $elements);
    return sprintf('%08d', 8 + strlen($rest)) . $rest;
}

$m = isset($_GET['m']) ? (string)$_GET['m'] : '';

if (strpos($m, 'gtd2') !== false) {
    // ---- details of one message: p3 is the id (fall back to any p1..p6 that matches)
    $want = isset($_GET['p3']) ? (int)$_GET['p3'] : 0;
    $found = null;
    foreach (array_merge(array('p3'), array('p1','p2','p4','p5','p6')) as $k) {
        if (!isset($_GET[$k])) continue;
        $v = (int)$_GET[$k];
        foreach ($INCIDENTS as $i) { if ($i['id'] == $v) { $found = $i; break 2; } }
    }
    if ($found === null) {
        $found = array('flag'=>1,'id'=>$want,'road'=>'','loc'=>'','text'=>'No further information','extra'=>'','n1'=>0,'n2'=>0);
    }
    $row = array($found['flag'], $found['id'], $found['road'], $found['loc'],
                 $found['text'], $found['extra'], $found['n1'], $found['n2']);
    $body = message(200, array(el_table(array($row))));
} else {
    // ---- overview
    $rows = array();
    $full = (strpos($m, 'gtuo') !== false || strpos($m, 'gstuo') !== false);
    foreach ($INCIDENTS as $i) {
        $pos = '>' . (int)round($i['lon'] * 100000) . 'x' . (int)round($i['lat'] * 100000);
        if ($full) {
            // gtuo / gstuo: 8 fields per row (with flag)
            $rows[] = array($i['flag'], $i['id'], $i['dir'], $pos, $i['event'], $i['f'], $i['g'], $i['h']);
        } else {
            // gtmo (the first request): 7 fields per row, no flag
            $rows[] = array($i['id'], $i['dir'], $pos, $i['event'], $i['f'], $i['g'], $i['h']);
        }
    }
    if ($full) {
        // two tokens + table
        $body = message(200, array(el_int(time()), el_int(1), el_table($rows)));
    } else {
        // table only
        $body = message(200, array(el_table($rows)));
    }
}

if (isset($_GET['debug'])) {
    header('Content-Type: text/plain');
    echo $body . "\n\n" . chunk_split(bin2hex($body), 64, "\n");
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Length: ' . strlen($body));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

echo $body;
exit;
