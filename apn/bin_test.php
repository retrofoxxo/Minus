<?php
/*
 * www.ttcode.com/apn/bin_test.php  -  emulation of the "TEST_BIN" service
 *
 * Derived from /bin/ttn (APN wizard + reply parser) in
 * navcore_8_010_9369_limerick.cab (ttsystem).
 *
 * Wire format that ttn's reply parser (0x25aee8) accepts, plain variant:
 *
 *   8 chars  decimal number, must be > 7       (used here: total body length)
 *   4 chars  status code, decimal              (0, 200, 203, 204 count as success)
 *   4 chars  number of elements, decimal
 *   then that many typed elements:
 *       1 char   type  ('7' = binary blob)
 *       8 chars  decimal data length
 *       n bytes  data
 *
 * The very first byte must be below 'A'; the letters A, B, C select other
 * (wrapped / trailer) variants of the format, which are not used here.
 *
 * What the APN wizard then requires (state 0x0c):
 *   - exactly 1 element, type 7, data longer than 26 bytes
 *   - the first 27 bytes of the data equal the magic string below
 *
 * Add ?debug=1 to see the body as a readable hex dump instead.
 */

@ini_set('zlib.output_compression', '0');
@ini_set('display_errors', '0');
while (ob_get_level() > 0) { ob_end_clean(); }

$magic = 'A9PxN7O3Kv-*26_`.?/e~=XxYq@';   // 27 bytes

// data: magic + every byte value 0x00-0xFF (binary transparency test)
$data = $magic;
for ($i = 0; $i < 256; $i++) {
    $data .= chr($i);
}

$element = '7' . sprintf('%08d', strlen($data)) . $data;
$rest    = sprintf('%04d', 200)      // status
         . sprintf('%04d', 1)        // element count
         . $element;
$body    = sprintf('%08d', 8 + strlen($rest)) . $rest;

if (isset($_GET['debug'])) {
    header('Content-Type: text/plain');
    echo substr($body, 0, 40) . "\n\n" . chunk_split(bin2hex($body), 64, "\n");
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Length: ' . strlen($body));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

echo $body;
exit;
