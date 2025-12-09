<?php
require 'barriques_lib.php';

$tests = [1660, 1500, 1280, 950, 590, 20, 0];

foreach ($tests as $raw) {
    print_r(interpret_raw($raw));
    echo "\n";
}