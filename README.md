This is a drop in replacement for `PhpParser\PrettyPrinter\Standard` printing
Drupal coding style.

Recommended usage:

````
[$printer, $stmts] = DrupalPrettyPrinter::getPrinterAndParse($code);
````

This parses the code and returns the printer and the parsed statements. It recognizes PHP 8.2 syntax for parsing.
The printer is ready for a `$printer->printFormatPreserving($stmts)` call, however this seems to only work well with
nikic/php-parser v5. In my testing, for v4 the output was the same as with `$printer->prettyPrintFile($stmts)`.
