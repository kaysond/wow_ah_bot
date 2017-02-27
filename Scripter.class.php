<?php
//http://nikic.github.io/2011/10/23/Improving-lexing-performance-in-PHP.html


namespace Scripter;

$tokenMap = array(
	"\s+"                                                             => "T_WHITESPACE",
	"==|!=|<|>|<=|>="                                                 => "T_COMPARISON",
	"\("                                                              => "(",
	"\)"                                                              => ")",
	"\+|-|\*|\/"                                                      => "T_OPERATOR",
	"&&"	                                                          => "T_AND",
	"\|\|"                                                            => "T_OR",
	"\."                                                              => "T_DOT",
	"where"                                                           => "T_WHERE",
	"sum|count|mean|min|max|stddev"                                   => "T_CALC",
	"auctions"                                                        => "T_AUCTIONS",
	"item_id|item|seller|quantity|time|bid|unitBid|buyout|unitBuyout" => "T_AUCTION_PARAM",
	"inventory_items"                                                 => "T_INVENTORY",
	"id|name|quality|maxQty|numTotal|numBags|numBank|numMail"         => "T_INVENTORY_PARAM",
	"[0-9]+"                                                          => "T_NUMBER",
	"'\w+'"                                                           => "T_STRING"
);

$ruleset = array();

$s = new Scripter($tokenMap, $ruleset);

$s->parse_script("name == 'asdf' && time == 'asdf' && unitBuyout < mean(auctions.unitBuyout where name == 'asdf') && quantity > 40 && (inventory_items.numTotal where name == 'asdf') < 10");

class Scripter {
	protected $lexer;
	protected $parser;
	protected $interpreter;

	protected $script;

	public function __construct(array $tokenMap, array $ruleset) {
		$this->lexer = new SimpleLexer($tokenMap);
		$this->parser = new SimpleParser($ruleset);
		$this->interpreter = new SimpleInterpreter();
	}

	public function parse_script($script) {
		$this->script = $this->parser->parse($this->lexer->lex($script));
	}

	public function interpret(array $arguments) {
		$this->interpreter->interpret($script, $arguments);
	}

}

class SimpleInterpreter {
	protected $script;

	public function interpret ($scripts, $arguments) {

	}
}

class SimpleParser {
	protected $ruleset[];

	public function __construct(array $ruleset) {
		$this->$ruleset = $ruleset;
	}

	public function generate_ruleset(array $rules) {
		foreach($rules as $rule) {

		}
	}

	public function parse(array $tokens) {
		var_dump($tokens);
	}

}

class SimpleParserRule {
	public $name;
	public $rule;
	public $function;
}

class SimpleLexer {
    protected $regex;
    protected $offsetToToken;

    public function __construct(array $tokenMap) {
        $this->regex = '#(' . implode(')|(', array_keys($tokenMap)) . ')#A';
        $this->offsetToToken = array_values($tokenMap);
    }

    public function lex($string) {
        $tokens = array();

        $offset = 0;
        while (isset($string[$offset])) {
            if (!preg_match($this->regex, $string, $matches, null, $offset)) {
                throw new LexingException(sprintf('Unexpected character "%s"', $string[$offset]));
            }

            // find the first non-empty element (but skipping $matches[0]) using a quick for loop
            for ($i = 1; '' === $matches[$i]; ++$i);

            $tokens[] = array($matches[0], $this->offsetToToken[$i - 1]);

            $offset += strlen($matches[0]);
        }

        return $tokens;
    }
}

class LexingException extends \Exception {}


class metric extends object_from_array() {
	protected static $optional = array("function");

	public $function = "";
	public $parameter = "";
}

class comparison extends object_from_array {
	protected static $optional = array("AND", "OR");
	protected static $objects = array("metric1" => "metric", "metric2" => "metric", "AND" => "comparison", "OR" => "comparison");

	public $metric1;
	public $metric2;
	public $operator = "";
	public $AND;
	public $OR;

	public function is_true() {
		$comparison = false;
		switch ($this->operator) {
			case "<":
				if ($this->metric1->value() < $this->metric2->value())
					$comparison = true;
				else
					$comparison = false;
				break;
			case "<=":
				if ($this->metric1->value() <= $this->metric2->value())
					$comparison = true;
				else
					$comparison = false;
				break;
			case ">":
				if ($this->metric1->value() > $this->metric2->value())
					$comparison = true;
				else
					$comparison = false;
				break;
			case ">=":
				if ($this->metric1->value() >= $this->metric2->value())
					$comparison = true;
				else
					$comparison = false;
				break;
			case "==":
				if ($this->metric1->value() == $this->metric2->value())
					$comparison = true;
				else
					$comparison = false;
				break;
			default:
				$comparison = false
		}
		if (!empty((array) $this->AND))
			$comparison = $comparison && $this->AND->is_true();
		if (!empty((array) $this->OR)
			$comparison = $comparison || $this->OR->is_true();

		return $comparison;
	}
}

?>