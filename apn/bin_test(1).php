<?php
/*
 * www.ttcode.com/apn/bin_test.php  -  emulation of the "TEST_BIN" service
 *
 * Derived from /bin/ttn (APN wizard, CAPNWizard state machine) in
 * navcore_8_010_9369_limerick.cab (ttsystem).
 *
 * What the device does:
 *   1. APN_LOG request  (POST, body "data=..." / "setuptype=...")  -> reply must start with "APNOK"
 *   2. TEST_BIN request (plain GET, no body)                       -> THIS FILE
 *
 * What ttn checks on the TEST_BIN reply (state 0x0c):
 *   - the reply decodes to exactly ONE element
 *   - the element type is 7  (binary blob: '7' + 8 digit length + data)
 *   - the data is longer than 26 bytes
 *   - the first 27 bytes equal the magic string below
 *   -> only then the wizard moves on to its "success" state (9)
 *
 * Anything after the first 27 bytes is not inspected. A "bin" test is meant to
 * prove the APN/proxy passes binary data untouched, so the magic is followed by
 * all byte values 0x00-0xFF.
 *
 * Add ?raw=1 to serve the bare payload without the '7'+length element header
 * (only useful for experimenting if your capture shows an unframed body).
 */

// Make sure nothing alters the bytes on the way out.
@ini_set('zlib.output_compression', '0');
@ini_set('display_errors', '0');
while (ob_get_level() > 0) { ob_end_clean(); }

$magic = 'A9PxN7O3Kv-*26_`.?/e~=XxYq@';   // 27 bytes, compared with memcmp(..., 27)

$payload = $magic;
for ($i = 0; $i < 256; $i++) {
    $payload .= chr($i);
}

if (isset($_GET['raw'])) {
    $body = $payload;
} else {
    // typed element: type char '7', 8 character decimal length, then the data
    $body = '7' . sprintf('%08d', strlen($payload)) . $payload;
}

header('Content-Type: application/octet-stream');
header('Content-Length: ' . strlen($body));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Content-Encoding: identity');

echo $body;
exit;
