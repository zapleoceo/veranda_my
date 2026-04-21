<?php
// Test mapping
$rawType = 10;
$type = $rawType;
if ($rawType === 10) $type = 4;
elseif ($rawType === 8) $type = 5;
elseif ($rawType === 2 || $rawType === 3) $type = 1;
echo "raw: $rawType, new: $type\n";
