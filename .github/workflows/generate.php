<?php

use DrupalPrettyPrinter\DrupalPrettyPrinter;

require_once './vendor/autoload.php';

const DRUPAL_PRETTY_PRINTER_TEST = 'R6DVmdwxcy';

$code = file_get_contents(__DIR__ . '/user.module');

// This is the recommended way because this always parses with PHP 8 and also
// prepares for format preserving printing.
[$printer, $stmts] = DrupalPrettyPrinter::getPrinterAndParse($code);

// Normal code would change $stmts but for testing the printer no changes
// are necessary.
$newCode = $printer->prettyPrintFile($stmts);
file_put_contents('user1.module', $newCode);

// To test the format preserving printer in v5, statements need to change
// otherwise it will just copy the original code verbatim.
changeStmtsForTesting($stmts);
$newCode = $printer->printFormatPreserving($stmts);
$count = substr_count($newCode, DRUPAL_PRETTY_PRINTER_TEST);
printf("Changed %d nodes.\n", $count);
exit($count && $code === str_replace(DRUPAL_PRETTY_PRINTER_TEST, '', $newCode) ? 0 :1);

function changeStmtsForTesting($stmts): void {
  $traverser = new \PhpParser\NodeTraverser;
  $traverser->addVisitor(new class extends \PhpParser\NodeVisitorAbstract {
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
