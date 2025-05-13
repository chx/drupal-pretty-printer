<?php

// @codingStandardsIgnoreStart

namespace DrupalPrettyPrinter;

use PhpParser\Node\Param;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Scalar\Encapsed;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node;
use PhpParser\Node\Stmt\UseUse;

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
class DrupalPrettyPrinterV4 extends DrupalPrettyPrinterBase {

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
  protected function p(Node $node, $parentFormatPreserved = false) : string {
    $type = $node->getType();
    $type_pieces = explode('_', $type);

    if ($type == 'Stmt_If' || $type == 'Stmt_ElseIf' || $type == 'Stmt_Else') {
      // Override of if-type statements even if it is not HTML.
      $keyword = strtolower(array_pop($type_pieces));
      return $this->printIfLike($node, $keyword);
    }

    $easy_types = ['Expr_Isset', 'Expr_List', 'Expr_Clone',
      'Expr_Include', 'Expr_Exit', 'Expr_Empty', 'Expr_Eval',
      'Stmt_For', 'Stmt_Foreach', 'Stmt_While', 'Stmt_Do',
      'Stmt_Switch', 'Stmt_Case',
      'Stmt_TryCatch', 'Stmt_Catch', 'Stmt_Throw',
      'Stmt_Finally', 'Stmt_Break', 'Stmt_Continue',
      'Stmt_Return', 'Stmt_Goto', 'Stmt_Echo', 'Stmt_Static', 'Stmt_Global',
      'Stmt_Unset',
    ];

    if ($this->isHtml && !$this->state['in_string']) {
      // Overrides of certain simple statements if we are adding HTML and
      // not currently printing a string.
      if ($node instanceof MagicConst) {
        $output = parent::p($node, $parentFormatPreserved);
        return '<span class="php-keyword">' . $output . '</span>';
      }
      elseif ($type == 'Scalar_LNumber' || $type == 'Scalar_DNumber') {
        $output = parent::p($node, $parentFormatPreserved);
        return '<span class="php-constant">' . $output . '</span>';
      }
      elseif ($node instanceof Cast) {
        $cast_type = strtolower(array_pop($type_pieces));
        return $this->pPrefixOp(get_class($node), '(<span class="php-keyword">' . $cast_type . '</span>) ', $node->expr);
      }
      elseif ($type == 'Expr_ConstFetch') {
        $output = parent::p($node, $parentFormatPreserved);
        return '<span class="php-function-or-constant">' . $output . '</span>';
      }
      elseif (in_array($type, $easy_types)) {
        // In all of these types, the parent class output starts with a PHP
        // keyword, possibly preceded by a space. Wrap the keyword in a span.
        $output = parent::p($node, $parentFormatPreserved);
        $output = preg_replace('|^( *)([a-z]+)|', '$1<span class="php-keyword">$2</span>', $output);
        return $output;
      }

    }

    // If we have not overridden anything and returned already, use the parent.
    return parent::p($node, $parentFormatPreserved);
  }

  /**
   * Overrides string printing to add HTML spans.
   */
  protected function pScalar_Encapsed(Encapsed $node) {
    return $this->isHtml ? $this->addHtmlToEncapsed($node) : parent::pScalar_Encapsed($node);
  }

  /**
   * Overrides printing of use statement to include HTML.
   */
  protected function pStmt_UseUse(UseUse $node) {
    return $this->isHtml ? $this->addHtmlToUseItem($node) : parent::pStmt_UseUse($node);
  }



}
// @codingStandardsIgnoreEnd
