<?php

// @codingStandardsIgnoreStart

namespace DrupalPrettyPrinter;

use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Scalar\Encapsed;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PhpParser\Node\Stmt\TraitUseAdaptation\Precedence;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\Node\Scalar;

/**
 * Pretty-prints code for Drupal coding standards and HTML output.
 *
 * This class overrides the standard PrettyPrinter class from the PhpParser
 * project, so that the output conforms more closely with the Drupal project
 * coding standards. Modifications:
 * - In class and function declarations, the { is on the same line instead of
 *   the next line.
 * - There is a blank line before the closing } in class declarations.
 * - There is a line of vertical whitespace before each comment block.
 * - Individual single-line '//' comments are combined into blocks.
 * - Arrays are printed out multi-line instead of single-line, and comments are
 *   printed inside arrays. Exception: empty arrays on one line.
 * - HTML spans are added for highlighting and linking.
 * - Chained methods are split into lines.
 * - Space at ends of lines is removed.
 */
class DrupalPrettyPrinter extends Standard {

  /**
   * Current state, to keep track of certain function calls and the like.
   *
   * This is only tracked if HTML spans are turned on.
   *
   * Array elements:
   * - last_string: The most recent literal '' or "" string to be printed.
   * - function_calls: Stack of names of method and function calls we are
   *   currently in.
   * - first_argument: TRUE if printing the first argument of the deepest-level
   *   function in the function_calls stack.
   * - in_array: TRUE if we are currently printing an array element.
   * - in_string: TRUE if we are currently printing the content of a literal
   *   string.
   * - array_key: Key of the array element we are printing, if it is a string.
   *
   * @var array
   */
  protected $state = [
    'function_calls' => [],
    'in_array' => FALSE,
    'in_string' => FALSE,
  ];

  /**
   * TRUE to add HTML spans to the code.
   */
  protected bool $isHtml;

  /**
   * TRUE (default) if this is Drupal code.
   */
  protected bool $isDrupal;

  protected array $origStmts;

  protected array $oldTokens;

  /**
   * Constructs an ApiPrettyPrinter object.
   *
   * @param array $options
   *   Array of options, including:
   *   - shortArraySyntax: TRUE to use [] when printing arrays instead of
   *     array(), for unspecified arrays. Default is TRUE, unlike the base
   *     class.
   *   - html: TRUE to add HTML spans to the code.
   *   - isDrupal: TRUE (default) if this is Drupal code. Note this has no
   *     effect if the "html" option is FALSE.
   */
  public function __construct(array $options = []) {
    $this->isHtml = $options['html'] ?? FALSE;
    $this->isDrupal = $options['isDrupal'] ?? TRUE;

    $options += [
      'shortArraySyntax' => TRUE,
    ];

    parent::__construct($options);
  }

  /**
   * Creates a format preserving printer.
   *
   * @param string $code
   *   The code to be parsed.
   * @param array $options
   *   Array of options, including:
   *   - shortArraySyntax: TRUE to use [] when printing arrays instead of
   *     array(), for unspecified arrays. Default is TRUE, unlike the base
   *     class.
   *   - html: TRUE to add HTML spans to the code.
   *   - isDrupal: TRUE (default) if this is Drupal code. Note this has no
   *     effect if the "html" option is FALSE.
   *
   * @return array
   *   A list of two elements. The first element is the printer. The second
   *   element is the list of statement objects.
   *
   * @phpstan-return array{\DrupalPrettyPrinter\DrupalPrettyPrinter, \PhpParser\Node\Stmt[]}
   */
  public static function getPrinterAndParse(string $code, array $options = []): array {
    $printer = new static($options);
    if (class_exists(\PhpParser\Parser\Php8::class)) {
      $parser = (new ParserFactory())->createForNewestSupportedVersion();
      $printer->origStmts = $parser->parse($code);
      $printer->oldTokens = $parser->getTokens();
    }
    else {
      $lexer = new \PhpParser\Lexer\Emulative([
        'usedAttributes' => [
          'comments',
          'startLine', 'endLine',
          'startTokenPos', 'endTokenPos',
        ],
      ]);
      $parser = new \PhpParser\Parser\Php7($lexer);
      $printer->origStmts = $parser->parse($code);
      $printer->oldTokens = $lexer->getTokens();
    }
    // Run CloningVisitor before making changes to the AST.
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new CloningVisitor());
    return [$printer, $traverser->traverse($printer->origStmts)];
  }

  /**
   * {inheritdoc}
   *
   * Unlike parent, this method can be called with just $stmts if the printer
   * was created with the ::getPrinterAndParse factory method.
   */
  public function printFormatPreserving(array $stmts, ?array $origStmts = NULL, ?array $origTokens = NULL): string {
    return parent::printFormatPreserving($stmts, $origStmts ?? $this->origStmts, $origTokens ?? $this->oldTokens);
  }

  /**
   * Returns information about invoke function names for Drupal code.
   *
   * @return array[]
   *   Associative array whose keys are function or method names, and whose
   *   values are an associative array of information:
   *   - type: The type of Drupal hook invocation they represent, such as
   *     'hook', 'fieldhook', 'entityhook', 'userhook', 'theme', or 'alter'.
   *   - position: The position of the hook name parameter in the function
   *     parameter list, counting the first parameter as 0.
   */
  public static function invokeFunctions() {
    return [
      '_field_invoke' => ['fieldhook', 1],
      '_field_invoke_default' => ['fieldhook', 1],
      '_field_invoke_multiple' => ['fieldhook', 1],
      '_field_invoke_multiple_default' => ['fieldhook', 1],
      'bootstrap_invoke_all' => ['hook', 1],
      'getImplementations' => ['hook', 1],
      'implementsHook' => ['hook', 2],
      'invoke' => ['hook', 2],
      'invokeAll' => ['hook', 1],
      'invokeHook' => ['entityhook', 1],
      'module_hook' => ['hook', 2],
      'module_implements' => ['hook', 1],
      'module_invoke' => ['hook', 2],
      'module_invoke_all' => ['hook', 1],
      'node_invoke' => ['hook', 1],
      'user_module_invoke' => ['userhook', 1],
      'theme' => ['theme', 1],
      'drupal_alter' => ['alter', 1],
      'alter' => ['alter', 1],
      'alterInfo' => ['alter', 1],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prettyPrintFile(array $stmts) : string {
    if (!$this->isHtml) {
      return parent::prettyPrintFile($stmts) . "\n";
    }

    // Override to use HTML entities in output.
    $phptag = '<span class="php-boundary">' . htmlentities('<?php') . '</span>';
    $phpendtag = '<span class="php-boundary">' . htmlentities('?>') . '</span>';

    if (!$stmts) {
      return $phptag . "\n\n";
    }

    $p = $phptag . "\n\n" . $this->prettyPrint($stmts);

    if ($stmts[0] instanceof Stmt\InlineHTML) {
      $p = preg_replace('/^' . preg_quote($phptag, '/') . '\s+' . preg_quote($phpendtag, '/') . '\n?/', '', $p);
    }

    if ($stmts[count($stmts) - 1] instanceof Stmt\InlineHTML) {
      $p = preg_replace('/' . preg_quote($phptag, '/') . '$/', '', rtrim($p));
    }

    return $p;
  }

  /**
   * Returns spaces according to the number given.
   *
   * @param int $number
   *   Number of spaces to return. Defaults to 2.
   *
   * @return string
   *   String of spaces.
   */
  protected function spaces($number = 2) {
    return \str_repeat(' ', $number);
  }

  /**
   * Overrides pretty-printing of nodes to add HTML in some cases.
   *
   * @param \PhpParser\Node $node
   *   Node to be pretty printed.
   * @param bool $parentFormatPreserved
   *   Preserve parent format or not.
   *
   * @return string
   *   Pretty printed node.
   */
  protected function p(
    Node $node, $precedence = 1000, int $lhsPrecedence = 1000,
    bool $parentFormatPreserved = false
  ): string {
    $args = func_get_args();
    if (!$this->isHtml) {
      return parent::p(... $args);
    }
    $type = $node->getType();
    $type_pieces = explode('_', $type);

    $easy_types = ['Expr_Isset', 'Expr_List', 'Expr_Clone',
      'Expr_Include', 'Expr_Exit', 'Expr_Empty', 'Expr_Eval',
      'Stmt_For', 'Stmt_Foreach', 'Stmt_While', 'Stmt_Do',
      'Stmt_Switch', 'Stmt_Case',
      'Stmt_TryCatch', 'Stmt_Catch', 'Stmt_Throw',
      'Stmt_Finally', 'Stmt_Break', 'Stmt_Continue',
      'Stmt_Return', 'Stmt_Goto', 'Stmt_Echo', 'Stmt_Static', 'Stmt_Global',
      'Stmt_Unset',
    ];

    if (!$this->state['in_string']) {
      // Overrides of certain simple statements if we are adding HTML and
      // not currently printing a string.
      if ($node instanceof MagicConst) {
        $output = parent::p(... $args);
        return '<span class="php-keyword">' . $output . '</span>';
      }
      elseif ($type == 'Scalar_LNumber' || $type == 'Scalar_DNumber') {
        $output = parent::p(... $args);
        return '<span class="php-constant">' . $output . '</span>';
      }
      elseif ($node instanceof Cast) {
        $cast_type = strtolower(array_pop($type_pieces));
        return $this->pPrefixOp(get_class($node), '(<span class="php-keyword">' . $cast_type . '</span>) ', $node->expr);
      }
      elseif ($type == 'Expr_ConstFetch') {
        $output = parent::p(... $args);
        return '<span class="php-function-or-constant">' . $output . '</span>';
      }
      elseif (in_array($type, $easy_types)) {
        // In all of these types, the parent class output starts with a PHP
        // keyword, possibly preceded by a space. Wrap the keyword in a span.
        $output = parent::p(... $args);
        return preg_replace('|^( *)([a-z]+)|', '$1<span class="php-keyword">$2</span>', $output);
      }

    }

    // If we have not overridden anything and returned already, use the parent.
    return parent::p(... $args);
  }

  /**
   * Overrides basic statement printing.
   *
   * Modifications:
   * - Indentation is from the option.
   * - Extra vertical whitespace.
   */
  protected function pStmts(array $nodes, bool $indent = TRUE) : string {
    if ($indent) {
      $this->indent();
    }

    $result = '';
    foreach ($nodes as $node) {
      $comments = $node->getComments();
      if ($comments) {
        $result .= $this->nl . $this->pComments($comments);
        if ($node instanceof Nop) {
          continue;
        }
      }

      $result .= $this->nl . $this->p($node) . ($node instanceof Expr ? ';' : '');
    }

    if ($indent) {
      $this->outdent();
    }

    return $result;
  }

  /**
   * Overrides constant printing to add HTML.
   */
  protected function pConst(Node\Const_ $node): string {
    if (!$this->isHtml) {
      return parent::pConst($node);
    }

    return '<span class="php-function-or-constant-declared">' . $node->name . '</span> = ' . $this->p($node->value);
  }

  /**
   * Overrides comment printing.
   *
   * Adjoining single-line '//' comments are combined into blocks together,
   * and there are empty lines between comment blocks. HTML spans are also
   * added.
   *
   * @param \PhpParser\Comment[] $comments
   *   List of comments.
   *
   * @return string
   *   Reformatted text of comments.
   */
  protected function pComments(array $comments) : string {
    $formattedComments = [];
    foreach ($comments as $comment) {
      $reformattedText = $comment->getReformattedText();
      $comment_text = str_replace("\n", $this->nl, $reformattedText);
      if (str_starts_with($reformattedText, "/**\n * @file")) {
        $comment_text .= "\n";
      }
      if ($this->isHtml) {
        $comment_text = htmlentities($comment_text);
      }

      if (preg_match("/[\r\n]/", $comment_text)) {
        $comment_text = $this->nl . $comment_text;
      }

      $formattedComments[] = $comment_text;
    }

    if ($this->isHtml) {
      $span = '<span class="php-comment">';
      $endspan = '</span>';
    }
    else {
      $span = '';
      $endspan = '';
    }

    return $span . implode($endspan . $this->nl . $span, $formattedComments) . $endspan;
  }

  /**
   * Overrides string printing to add HTML spans and keep track of state.
   */
  protected function pScalar_String(String_ $node): string {
    if (!$this->isHtml || $this->state['in_string']) {
      return parent::pScalar_String($node);
    }

    unset($this->state['last_string']);

    $span = $this->getStringSpan($node->value);
    $endspan = '</span>';

    $kind = $node->getAttribute('kind', String_::KIND_SINGLE_QUOTED);
    switch ($kind) {
      case String_::KIND_SINGLE_QUOTED:
        $this->state['last_string'] = $node->value;
        return $span . "'" . htmlentities(addcslashes($node->value, '\'\\')) . "'" . $endspan;

      case String_::KIND_DOUBLE_QUOTED:
        $this->state['last_string'] = $node->value;
        return $span . '"' . htmlentities($this->escapeString($node->value, '"')) . '"' . $endspan;

      default:
        return htmlentities(parent::pScalar_String($node));
    }
  }

  /**
   * Overrides string printing to add HTML spans.
   */
  protected function pScalar_Encapsed(Encapsed $node) {
    return $this->isHtml ? $this->addHtmlToEncapsed($node) : parent::pScalar_Encapsed($node);
  }

  protected function pScalar_InterpolatedString(Scalar\InterpolatedString $node): string {
    return $this->isHtml ? $this->addHtmlToEncapsed($node) : parent::pScalar_InterpolatedString($node);
  }

  protected function addHtmlToEncapsed(Node $node) {
    unset($this->state['last_string']);

    if ($node->getAttribute('kind') === String_::KIND_HEREDOC) {
      return htmlentities(parent::pScalar_Encapsed($node));
    }

    // Change the state to record we are inside a string.
    $this->state['in_string'] = TRUE;

    $string = $this->pEncapsList($node->parts, '"');
    $span = $this->getStringSpan($string);
    $endspan = '</span>';
    $this->state['last_string'] = $string;
    $this->state['in_string'] = FALSE;
    return $span . '"' . htmlentities($string) . '"' . $endspan;
  }

  /**
   * Overrides variable printing to add HTML spans.
   */
  protected function pExpr_Variable(Variable $node): string {
    $output = parent::pExpr_Variable($node);

    // Add the HTML spans only if we aren't inside a string.
    if ($this->isHtml && !$this->state['in_string']) {
      $output = '<span class="php-variable">' . $output . '</span>';
    }

    return $output;
  }

  /**
   * Calculates the string span to use, depending on the current state.
   *
   * @param string $string
   *   String to be spanned.
   *
   * @return string
   *   Span open tag for this string and the current state.
   */
  protected function getStringSpan($string) {
    $class = 'php-string';
    $invoke_functions_info = static::invokeFunctions();

    // If the string consists only of legal function characters, it could
    // be a theme hook name, element name, or hook name, if this is Drupal
    // code.
    if ($string && $this->isDrupal &&
      preg_match('|^[a-zA-Z0-9_\x7f-\xff]+$|', $string)) {
      if ($this->state['in_array'] && isset($this->state['array_key'])) {
        if ($this->state['array_key'] == '#theme') {
          $class .= ' potential-theme';
        }
        elseif ($this->state['array_key'] == '#type') {
          $class .= ' potential-element';
        }
      }
      elseif (count($this->state['function_calls'])) {
        $last = ($this->state['function_calls'][count($this->state['function_calls']) - 1]);
        if (is_object($last) && method_exists($last, 'toString')) {
          $last = $last->toString();
        }
        if (!isset($last) || !is_string($last)) {
          $last = '';
        }
        if (isset($invoke_functions_info[$last])) {
          $class .= ' potential-' . $invoke_functions_info[$last][0];
        }
      }
    }

    return '<span class="' . $class . '">';
  }

  /**
   * Overrides function call printing to add HTML spans and keep track of state.
   */
  protected function pExpr_FuncCall(FuncCall $node): string {
    if (!$this->isHtml || $this->state['in_string']) {
      return parent::pExpr_FuncCall($node);
    }

    $name = $this->pCallLhs($node->name);

    $this->state['function_calls'][] = $name;
    $this->state['first_argument'] = TRUE;
    $args = $this->pCommaSeparated($node->args);
    array_pop($this->state['function_calls']);

    return '<span class="php-function-or-constant">' . $name . '</span>(' .
      $args . ')';
  }

  /**
   * Overrides method call printing to add HTML spans and split into lines.
   */
  protected function pExpr_MethodCall(Expr\MethodCall $node): string {
    if (!$this->isHtml) {
      return parent::pExpr_MethodCall($node);
    }
    if ($this->state['in_string']) {
      return $this->pDereferenceLhs($node->var) .
        '->' .
        $this->pObjectProperty($node->name) .
        '(' . $this->pMaybeMultiline($node->args) . ')';
    }

    $variable = $this->pDereferenceLhs($node->var);
    $variable_name = strip_tags($variable);

    $method_name = $this->pObjectProperty($node->name);
    $span_class = 'php-function-or-constant function';
    if ($variable_name == '$this') {
      $span_class .= ' member-of-self';
    }
    else {
      $span_class .= ' member-of-variable';
    }

    $this->state['function_calls'][] = $method_name;
    $this->state['first_argument'] = TRUE;
    $args = $this->pCommaSeparated($node->args);
    array_pop($this->state['function_calls']);

    $arrow = htmlentities('->');
    // Don't line-break in the very first method if methods are chained.
    $spacing = (strpos($variable, $arrow) === FALSE) ? '' : $this->nl . $this->spaces();
    return $variable . $spacing .
      $arrow .
      '<span class="' . $span_class . '">' .
      $method_name . '</span>(' . $args . ')';
  }

  /**
   * Overrides static method call printing to add HTML spans.
   */
  protected function pExpr_StaticCall(Expr\StaticCall $node): string {
    if (!$this->isHtml || $this->state['in_string']) {
      return parent::pExpr_StaticCall($node);
    }

    $class_name = strip_tags($this->pDereferenceLhs($node->class));

    $method_name = ($node->name instanceof Expr ?
      ($node->name instanceof Variable ?
        $this->p($node->name) : '{' . $this->p($node->name) . '}')
      : $node->name);
    $span_class = 'php-function-or-constant function';
    if ($class_name == 'static' || $class_name == 'self') {
      $span_class .= ' member-of-self';
    }
    elseif ($class_name == 'parent') {
      $span_class .= ' member-of-parent';
    }
    else {
      $span_class .= ' member-of-class-' . $class_name;
    }

    $this->state['function_calls'][] = $method_name;
    $this->state['first_argument'] = TRUE;
    $args = $this->pCommaSeparated($node->args);
    array_pop($this->state['function_calls']);

    return '<span class="php-function-or-constant">' . $class_name .
      '</span>::<span class="' . $span_class . '">' . $method_name .
      '</span>(' . $args . ')';
  }

  /**
   * Overrides function argument printing to keep track of state.
   */
  protected function pArg(Node\Arg $node): string {
    $output = parent::pArg($node);
    $this->state['first_argument'] = FALSE;
    return $output;
  }

  /**
   * Overrides function parameter printing to add HTML.
   */
  protected function pParam(Node\Param $node): string {
    if (!$this->isHtml || $this->state['in_string']) {
      return parent::pParam($node);
    }

    $name = $node->name ?? FALSE;
    if (!$name && !empty($node->var->name)) {
      $name = $node->var->name;
    }

    return ($node->type ? $this->p($node->type) . ' ' : '') .
      ($node->byRef ? htmlentities('&') : '') .
      ($node->variadic ? '...' : '') .
      ($name ? '<span class="php-variable">$' . $name . '</span>' : '') .
      ($node->default ? ' = ' . $this->p($node->default) : '');
  }

  /**
   * Overrides property fetch printing to add HTML.
   */
  protected function pExpr_PropertyFetch(PropertyFetch $node): string {
    if (!$this->isHtml || $this->state['in_string']) {
      return parent::pExpr_PropertyFetch($node);
    }

    $variable = $this->pDereferenceLhs($node->var);
    $variable_name = strip_tags($variable);

    $property_name = $this->pObjectProperty($node->name);
    $span_class = 'php-function-or-constant property';
    if ($variable_name == '$this') {
      $span_class .= ' member-of-self';
    }
    else {
      $span_class .= ' member-of-variable';
    }

    return $variable . htmlentities('->') .
      '<span class="' . $span_class . '">' . $property_name . '</span>';
  }

  /**
   * Overrides static property fetch printing to add HTML.
   */
  protected function pExpr_StaticPropertyFetch(StaticPropertyFetch $node): string {
    if (!$this->isHtml || $this->state['in_string']) {
      return parent::pExpr_StaticPropertyFetch($node);
    }

    $class_name = strip_tags($this->pDereferenceLhs($node->class));

    $property_name = $this->pObjectProperty($node->name);
    $span_class = 'php-function-or-constant property';
    if ($class_name == 'static') {
      $span_class .= ' member-of-self';
    }
    elseif ($class_name == 'parent') {
      $span_class .= ' member-of-parent';
    }
    else {
      $span_class .= ' member-of-class-' . $class_name;
    }

    return '<span class="php-function-or-constant">' . $class_name .
      '</span>::$<span class="' . $span_class . '">' . $property_name .
      '</span>';
  }

  /**
   * Pretty-prints an array of nodes, comma separated, on separate lines.
   *
   * @param \PhpParser\Node[] $nodes
   *   Array of Nodes to be printed.
   * @param bool $comma_at_end
   *   (optional) TRUE (default) if there should be a comma at the end of the
   *   last element too.
   *
   * @return string
   *   Comma separated pretty printed nodes, with each on its own line,
   *   indented.
   */
  protected function pCommaSeparatedMultiLine(array $nodes, bool $comma_at_end) : string {
    $this->indent();

    // Implode with commas and newlines.
    $result = $this->spaces() . $this->pImplode($nodes, "," . $this->nl);
    if ($comma_at_end && count($nodes)) {
      $result .= ',';
    }

    $this->outdent();
    $result .= $this->nl;
    return $result;
  }

  /**
   * Overrides array printing to use multiple lines.
   *
   * @see self::pExprArrayItem
   */
  protected function pExpr_Array(Array_ $node): string {
    $isShortArraySyntax = $this->shortArraySyntax ?? $this->options['shortArraySyntax'];
    $syntax = $node->getAttribute('kind',
      $isShortArraySyntax ? Array_::KIND_SHORT : Array_::KIND_LONG);
    if (empty($node->items)) {
      $items = '';
    }
    else {
      $items = $this->nl . $this->pCommaSeparatedMultiLine($node->items, TRUE);
    }

    if ($syntax === Array_::KIND_SHORT) {
      return '[' . $items . ']';
    }
    else {
      if ($this->isHtml && !$this->state['in_string']) {
        return '<span class="php-keyword">array</span>(' . $items . ')';
      }
      else {
        return 'array(' . $items . ")";
      }
    }
  }

  /**
   * Overrides printing of array items to include comments and track state.
   */
  protected function pExpr_ArrayItem(Expr\ArrayItem $node) {
    $result = '';
    $comments = $node->getAttribute('comments', []);
    if ($comments) {
      $result .= $this->pComments($comments) . "\n";
    }

    unset($this->state['last_string']);
    $key = (is_null($node->key)) ? NULL : $this->p($node->key);
    if (isset($this->state['last_string'])) {
      $this->state['array_key'] = $this->state['last_string'];
    }
    $this->state['in_array'] = TRUE;

    $amp = (($this->isHtml && !$this->state['in_string']) ? htmlentities('&') : '&');

    $result .= (NULL !== $key ? $key . ' => ' : '')
      . ($node->byRef ? $amp : '') . $this->p($node->value);

    $this->state['in_array'] = FALSE;
    unset($this->state['array_key']);

    return $result;
  }

  /**
   * Prints out an if/else/elseif statement.
   *
   * Overrides the default to have the { on the same line, and add HTML.
   *
   * @param \PhpParser\Node\Stmt\If_|\PhpParser\Node\Stmt\Else_|\PhpParser\Node\Stmt\ElseIf_ $node
   *   If, else, or elseif statement to print.
   * @param string $keyword
   *   Keyword to print, 'if', 'else', or 'elseif'.
   *
   * @return string
   *   Formatted statement.
   */
  protected function printIfLike(Node $node, $keyword) {
    $output = '';
    if ($keyword != 'if') {
      $output .= $this->nl;
    }

    if ($this->isHtml) {
      $output .= '<span class="php-keyword">' . $keyword . '</span>';
    }
    else {
      $output .= $keyword;
    }

    if ($keyword != 'else') {
      $output .= ' (' . $this->p($node->cond) . ')';
    }

    $output .= ' {' . $this->pStmts($node->stmts) . $this->nl . '}';

    if ($keyword == 'if') {
      if (!is_null($node->elseifs)) {
        $output .= $this->pImplode($node->elseifs);
      }
      if (!is_null($node->else)) {
        $output .= $this->printIfLike($node->else, 'else');
      }
    }

    return $output;
  }

  protected function pStmt_If(Stmt\If_ $node): string {
    return $this->printIfLike($node, 'if');
  }

  protected function pStmt_ElseIf(Stmt\ElseIf_ $node): string {
    return $this->printIfLike($node, 'elseif');
  }

  protected function pStmt_Else(Stmt\Else_ $node): string {
    return $this->printIfLike($node, 'else');
  }


  /**
   * Overrides new printing to add HTML.
   */
  protected function pExpr_New(Expr\New_ $node): string {
    if ($this->isHtml) {
      $new = '<span class="php-keyword">new</span> ';
      $class_span = '<span class="php-function-or-constant">';
      $class_end_span = '</span>';
    }
    else {
      $new = 'new ';
      $class_span = '';
      $class_end_span = '';
    }

    if ($node->class instanceof Stmt\Class_) {
      $args = $node->args ? '(' . $this->pCommaSeparated($node->args) . ')' : '';
      return $new . $this->pClassCommon($node->class, $args);
    }

    return $new . $class_span . $this->p($node->class) . $class_end_span . '(' . $this->pCommaSeparated($node->args) . ')';
  }

  /**
   * Overrides interface printing.
   *
   * Modifications:
   * - Put the { on the same line.
   * - Leave a blank line before the }.
   * - Add HTML.
   */
  protected function pStmt_Interface(Stmt\Interface_ $node): string {
    if ($this->isHtml) {
      return '<span class="php-keyword">interface</span> <span class="php-function-or-constant">' . $node->name . '</span>' .
        (!empty($node->extends) ? ' <span class="php-keyword">extends</span> ' . $this->pCommaSeparated($node->extends) : '') .
        ' {' . $this->pStmts($node->stmts) . "\n\n" . '}';
    }
    else {
      return 'interface ' . $node->name .
        (!empty($node->extends) ? ' extends ' . $this->pCommaSeparated($node->extends) : '') .
        ' {' . $this->pStmts($node->stmts) . "\n\n" . '}';
    }
  }

  /**
   * Overrides trait printing.
   *
   * Modifications:
   * - Put the { on the same line.
   * - Leave a blank line before the }.
   * - Add HTML.
   */
  protected function pStmt_Trait(Stmt\Trait_ $node): string {
    if ($this->isHtml) {
      return '<span class="php-keyword">trait</span> <span class="php-function-or-constant">' . $node->name . '</span>' .
        ' {' . $this->pStmts($node->stmts) . "\n\n" . '}';
    }
    else {
      return 'trait ' . $node->name . ' {' . $this->pStmts($node->stmts) . "\n\n" . '}';
    }
  }

  /**
   * Overrides class printing.
   *
   * Modifications:
   * - Put the { on the same line.
   * - Leave a blank line before the }.
   * - Add HTML.
   */
  protected function pClassCommon(Stmt\Class_ $node, $afterClassToken): string {
    $afterClassToken = trim($afterClassToken);
    if ($this->isHtml) {
      return $this->pModifiers($node->flags) .
        '<span class="php-keyword">class</span> <span class="php-function-or-constant">' . $afterClassToken . '</span>' .
        (NULL !== $node->extends ? ' <span class="php-keyword">extends</span> ' . $this->p($node->extends) : '') .
        (!empty($node->implements) ? ' <span class="php-keyword">implements</span> ' . $this->pCommaSeparated($node->implements) : '') .
        ' {' . $this->pStmts($node->stmts) . "\n\n" . '}';
    }
    else {
      return $this->pModifiers($node->flags) .
        'class ' . $afterClassToken .
        (NULL !== $node->extends ? ' extends ' . $this->p($node->extends) : '') .
        (!empty($node->implements) ? ' implements ' . $this->pCommaSeparated($node->implements) : '') .
        ' {' . $this->pStmts($node->stmts) . "\n\n" . '}';
    }
  }

  /**
   * Overrides function printing to put the { on the same line and add HTML.
   */
  protected function pStmt_Function(Stmt\Function_ $node): string {
    if ($this->isHtml) {
      $function = '<span class="php-keyword">function</span> ';
      $name_span = '<span class="php-function-or-constant-declared">';
      $name_end_span = '</span>';
      $amp = htmlentities('&');
    }
    else {
      $function = 'function ';
      $name_span = '';
      $name_end_span = '';
      $amp = '&';
    }

    $returnType = NULL !== $node->returnType ? ' : ' . $this->p($node->returnType) : '';
    $output = $function . ($node->byRef ? $amp : '') .
      $name_span . $node->name . $name_end_span .
      '(' . $this->pCommaSeparated($node->params) . ')' .
      $returnType;
    if (!$this->isHtml && strlen($output) > 80) {
      $output = $function . ($node->byRef ? $amp : '') . $node->name .
        '(' . $this->nl . $this->pCommaSeparatedMultiLine($node->params, TRUE) . ')' .
        $returnType;
    }
    return $output . ' {' . $this->pStmts($node->stmts) . $this->nl . '}';
  }

  /**
   * Overrides class method printing to put the { on the same line.
   */
  protected function pStmt_ClassMethod(Stmt\ClassMethod $node): string {
    if ($this->isHtml) {
      $function = '<span class="php-keyword">function</span> ';
      $name_span = '<span class="php-function-or-constant">';
      $name_end_span = '</span>';
      $amp = htmlentities('&');
    }
    else {
      $function = 'function ';
      $name_span = '';
      $name_end_span = '';
      $amp = '&';
    }

    return $this->pModifiers($node->flags) .
      $function . ($node->byRef ? $amp : '') .
      $name_span . $node->name . $name_end_span .
      '(' . $this->pCommaSeparated($node->params) . ')' .
      (NULL !== $node->returnType ? ' : ' . $this->p($node->returnType) : '') .
      (NULL !== $node->stmts
        ? ' {' . $this->pStmts($node->stmts) . $this->nl . '}'
        : ';');
  }

  /**
   * Overrides closure printing.
   *
   * Overrides:
   * - Space between the 'use' keyword and the use statements.
   * - Add HTML spans.
   */
  protected function pExpr_Closure(Expr\Closure $node): string {
    if ($this->isHtml) {
      $span = '<span class="php-keyword">';
      $endspan = '</span>';
      $amp = htmlentities('&');
    }
    else {
      $span = '';
      $endspan = '';
      $amp = '&';
    }

    return ($node->static ? $span . 'static' . $endspan . ' ' : '')
      . $span . 'function' . $endspan . ' ' . ($node->byRef ? $amp : '')
      . '(' . $this->pCommaSeparated($node->params) . ')'
      . (!empty($node->uses) ? ' ' . $span . 'use' . $endspan . ' (' . $this->pCommaSeparated($node->uses) . ')' : '')
      . (NULL !== $node->returnType ? ' : ' . $this->p($node->returnType) : '')
      . ' {' . $this->pStmts($node->stmts) . $this->nl . '}';
  }

  /**
   * Overrides class constant printing to include HTML.
   */
  protected function pStmt_ClassConst(Stmt\ClassConst $node): string {
    if (!$this->isHtml) {
      return parent::pStmt_ClassConst($node);
    }

    return $this->pModifiers($node->flags) .
      '<span class="php-keyword">const</span> ' .
      $this->pCommaSeparated($node->consts) . ';';
  }

  /**
   * Overrides constant printing to include HTML.
   */
  protected function pStmt_Const(Stmt\Const_ $node): string {
    if (!$this->isHtml) {
      return parent::pStmt_Const($node);
    }

    return '<span class="php-keyword">const</span> ' .
      $this->pCommaSeparated($node->consts) . ';';
  }

  /**
   * Overrides printing of start/end ?php tags to include HTML.
   */
  protected function pStmt_InlineHTML(Stmt\InlineHTML $node): string {
    if (!$this->isHtml) {
      return parent::pStmt_InlineHTML($node);
    }

    $newline = $node->getAttribute('hasLeadingNewline', TRUE) ? "\n" : '';

    return '<span class="php-boundary">' . htmlentities('?>') . '</span>' .
      htmlentities($newline . $node->value) .
      '<span class="php-boundary">' . htmlentities('<?php') . '</span>' . "\n";
  }

  /**
   * Overrides namespace printing to include HTML.
   */
  protected function pStmt_Namespace(Stmt\Namespace_ $node): string {
    if (!$this->isHtml) {
      return parent::pStmt_Namespace($node);
    }

    if ($this->canUseSemicolonNamespaces) {
      return '<span class="php-keyword">namespace</span> ' .
        '<span class="php-function-or-constant">' . $this->p($node->name) . '</span>;' .
        $this->nl . $this->pStmts($node->stmts, FALSE);
    }
    else {
      return '<span class="php-keyword">namespace</span>' .
        (NULL !== $node->name ?
          ' <span class="php-function-or-constant">' . $this->p($node->name) . '</span>' : '') .
          ' {' . $this->pStmts($node->stmts) . $this->nl . '}';
    }
  }

  /**
   * Overrides printing of use statement to include HTML.
   */
  protected function pStmt_Use(Stmt\Use_ $node): string {
    if (!$this->isHtml) {
      return parent::pStmt_Use($node);
    }

    return '<span class="php-keyword">use</span> ' .
      $this->pOurUsetype($node->type) . $this->pCommaSeparated($node->uses) . ';';
  }

  /**
   * Overrides printing of use statement to include HTML.
   */
  protected function pStmt_GroupUse(Stmt\GroupUse $node): string {
    if (!$this->isHtml) {
      return parent::pStmt_GroupUse($node);
    }

    return '<span class="php-keyword">use</span> ' .
      $this->pOurUsetype($node->type) .
      '<span class="php-function-or-constant">' . $this->pName($node->prefix) . '</span>' .
      '\{' . $this->pCommaSeparated($node->uses) . '};';
  }

  /**
   * Overrides printing of use statement to include HTML.
   */
  protected function pStmt_TraitUse(Stmt\TraitUse $node): string {
    if (!$this->isHtml) {
      return parent::pStmt_TraitUse($node);
    }

    return '<span class="php-keyword">use</span> ' .
      $this->pCommaSeparated($node->traits) .
      (empty($node->adaptations) ? ';'
        : ' {' . $this->pStmts($node->adaptations) . $this->nl . '}');
  }

  /**
   * Overrides printing of use statement to include HTML.
   */
  protected function pStmt_TraitUseAdaptation_Precedence(Precedence $node): string {
    if (!$this->isHtml) {
      return parent::pStmt_TraitUseAdaptation_Precedence($node);
    }

    $trait = $this->p($node->trait);
    $span_class = 'php-function-or-constant function member-of-class-' . $trait;

    return '<span class="php-function-or-constant">' . $trait . '</span>::' .
      '<span class="' . $span_class . '">' . $node->method . '</span>' .
      ' <span class="php-keyword">insteadof</span> ' . $this->pCommaSeparated($node->insteadof) . ';';
  }

  /**
   * Overrides printing of use statement to include HTML.
   */
  protected function pStmt_TraitUseAdaptation_Alias(Alias $node): string {
    if (!$this->isHtml) {
      return parent::pStmt_TraitUseAdaptation_Alias($node);
    }

    $trait = (NULL != $node->trait ? $this->p($node->trait) : '');
    if ($trait) {
      $span_class = 'php-function-or-constant function member-of-class-' . $trait;
    }
    else {
      $span_class = 'php-function-or-constant function';
    }

    return ($trait ? '<span class="php-function-or-constant">' . $trait . '</span>::' : '') .
      '<span class="' . $span_class . '">' . $node->method . '</span> ' .
      '<span class="php-keyword">as</span>' .
      (NULL !== $node->newModifier ? ' ' . rtrim($this->pModifiers($node->newModifier), ' ') : '') .
      (NULL !== $node->newName ? ' <span class="php-function-or-constant">' . $node->newName . '</span>' : '') . ';';
  }

  /**
   * Overrides printing of use statement to include HTML.
   */
  protected function pStmt_UseUse(UseUse $node) {
    return $this->isHtml ? $this->addHtmlToUseItem($node) : parent::pStmt_UseUse($node);
  }

  /**
   * Overrides printing of use statement to include HTML.
   */
  protected function pUseItem(Node\UseItem $node): string {
    return $this->isHtml ? $this->addHtmlToUseItem($node) : parent::pUseItem($node);
  }

  /**
   * Add HTML to either UseUse or UseItem, depending on version.
   */
  protected function addHtmlToUseItem(Node $node): string {
    $alias = $node->alias ? $node->alias->toString() : '';
    $parts = $node->getParts();
    return $this->pUseType($node->type) .
      '<span class="php-function-or-constant">' . $this->p($node->name) . '</span>' .
      (($alias && array_pop($parts) !== $alias) ? ' <span class="php-keyword">as</span> <span class="php-function-or-constant">' . $alias . '</span>' : '');
  }

  /**
   * Overrides printing of use statement to include HTML.
   */
  protected function pUseType($type): string {
    if (!$this->isHtml) {
      return parent::pUseType($type);
    }
    $keyword = $type === Stmt\Use_::TYPE_FUNCTION ? 'function'
      : ($type === Stmt\Use_::TYPE_CONSTANT ? 'const' : '');
    if (!$keyword || !$this->isHtml) {
      return $keyword;
    }
    return '<span class="php-keyword">' . $keyword . '</span> ';
  }

  /**
   * Drupal uses 2 spaces to indent instead of 4.
   */
  protected function indent(): void {
    $this->indentLevel += 2;
    $this->nl .= '  ';
  }

  /**
   * Drupal uses 2 spaces to indent instead of 4.
   */
  protected function outdent(): void {
    assert($this->indentLevel >= 2);
    $this->indentLevel -= 2;
    $this->nl = "\n" . str_repeat(' ', $this->indentLevel);
  }

  /**
   * Add a line break after break;
   */
  protected function pStmt_Break(Stmt\Break_ $node): string {
    return parent::pStmt_Break($node) ."\n";
  }

}
// @codingStandardsIgnoreEnd
