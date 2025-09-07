<?php
// Test patterns we saw: '\'active\'' and '\'0\''
$patterns = [
    "'active'" => "normal single quotes",
    "'\\'active\\''" => "escaped single quotes (what we see)",
    "'0'" => "normal numeric",
    "'\\'0\\''" => "escaped numeric (what we see)",
];

foreach ($patterns as $input => $desc) {
    echo "Input: $input ($desc)\n";
    
    // Try the new regex
    if (preg_match("/^'\\\\?'(.*)\\\\?''$/", $input, $matches)) {
        echo "  Matched double-quoted: " . $matches[1] . "\n";
    } elseif (preg_match("/^'(.*)'$/", $input, $matches)) {
        echo "  Matched single-quoted: " . $matches[1] . "\n";
    } else {
        echo "  No match\n";
    }
}

// What we actually get from SQLite
echo "\nActual SQLite values:\n";
$actual = ["'\\'active\\''", "'\\'0\\''"]; 
foreach ($actual as $val) {
    // Need to unescape for PHP
    $test = stripslashes($val);
    echo "Testing: $test\n";
    if (preg_match("/^''(.*)''$/", $test, $matches)) {
        echo "  Matched: " . $matches[1] . "\n";
    } elseif (preg_match("/^'(.*)'$/", $test, $matches)) {
        echo "  Matched: " . $matches[1] . "\n";
    }
}
