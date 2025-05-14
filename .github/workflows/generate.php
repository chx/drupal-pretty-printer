<?php

use DrupalPrettyPrinter\DrupalPrettyPrinter;
use PhpParser\ParserFactory;

require_once './vendor/autoload.php';

const DRUPAL_PRETTY_PRINTER_TEST = 'R6DVmdwxcy';

$code = file_get_contents(__DIR__ . '/user.module');

// First, let's do normal printing.
$printer = new DrupalPrettyPrinter();
$parser = (new ParserFactory())->createForHostVersion();
$stmts = $parser->parse($code);
// Do something with these statements. For testing purposes, we do not need to
// do anything.
$newCode = $printer->prettyPrintFile($stmts);
file_put_contents('user1.module', $newCode);

// This is how you prepare a format preserving printer.
[$printer, $stmts] = DrupalPrettyPrinter::createForFormatPreserving($code);

// Change statements. Replace this with actual logic.
changeStmtsForTesting($stmts);

// This is how to print the code.
$newCode = $printer->printFormatPreserving($stmts);

// This is again for testing only.
$count = substr_count($newCode, DRUPAL_PRETTY_PRINTER_TEST);
printf("Changed %d nodes.\n", $count);
exit($count && $code === str_replace(DRUPAL_PRETTY_PRINTER_TEST, '', $newCode) ? 0 :1);

function changeStmtsForTesting($stmts): void {
  $traverser = new \PhpParser\NodeTraverser(new class extends \PhpParser\NodeVisitorAbstract {
    public function enterNode(\PhpParser\Node $node) {
      if (isset($node->name)) {
        if (is_string($node->name)) {
          $node->name .= DRUPAL_PRETTY_PRINTER_TEST;
        }
        else {
          $node->name = new (get_class($node->name))($node->name . DRUPAL_PRETTY_PRINTER_TEST);
        }
      }
      return parent::enterNode($node);
    }
  });
  $traverser->traverse($stmts);
}
