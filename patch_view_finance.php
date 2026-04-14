<?php
$html = file_get_contents('payday2/view.php');

// Remove the `if ($vietnamExists):` block entirely and ONLY render the form!
$start = '<?php if ($vietnamExists): ?>';
$end = '<?php endif; ?>';
// Wait, there's `<?php if ($tipsExists): ?>` too.
// Instead of regex, I'll just manually replace the lines in view.php
