<?php
// Fork-only shim. Production already recorded this fork's own 000060/000061
// (now renamed to 900001/900002), so the runner skips upstream's 000060/000061
// there by filename. Re-run them here; both are idempotent (IF NOT EXISTS,
// INSERT OR IGNORE), so on a fresh DB where they already ran this is a no-op.

require_once __DIR__ . '/000060.php';
require_once __DIR__ . '/000061.php';
