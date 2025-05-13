<?php

use DrupalPrettyPrinter\DrupalPrettyPrinterBase;
use PhpParser\ParserFactory;

require_once './vendor/autoload.php';

$file = file_get_contents(__DIR__ . '/user.module');
$parser = (new ParserFactory())->createForHostVersion();
$stmts = $parser->parse($file);
$printer = DrupalPrettyPrinterBase::getPrinter(['html' => FALSE]);
$output = $printer->prettyPrintFile($stmts);
file_put_contents('user1.module', $output);
