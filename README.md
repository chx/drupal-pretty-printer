This is a drop in replacement for `PhpParser\PrettyPrinter\Standard` printing
Drupal coding style.

Recommended usage:

````
[$printer, $stmts] = DrupalPrettyPrinter::getPrinterAndParse($code);
````

This parses the code and returns the printer and the parsed statements. It recognizes PHP 8.2 syntax for parsing
or later with the `nikic/php-parser` v5. The printer is ready for a `$printer->printFormatPreserving($stmts)` call,
unlike with the standard printer, no other arguments are needed.

Of course, a simple

```
$printer = new DrupalPrettyPrinter();
$printer->prettyPrint($stmts);
```

also works. The factory method is just a convenience.
