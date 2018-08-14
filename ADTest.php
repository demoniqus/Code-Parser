<?php
$someva = 'text literal';



class ADFile {
    private $fileHandler = null;
    private $fileName;

    private $lines = [];
    public function __construct($fileName) {
        $this->fileName = $fileName;
        $this->fileHandler = fopen($fileName, 'rt');
    }

    public function getLine() {
        if (!feof($this->fileHandler)) {
            $line = new FileLine(fgets($this->fileHandler), count($this->lines), $this->lines[count($this->lines) - 1] ?? null);
            $this->lines[] = $line;
            return $line;
        }
        fclose($this->fileHandler);
        return false;
    }

    public function parse() {
//        $this->lines = [];
//        if (!feof($this->fileHandler)) {
//            $line = new FileLine(fgets($this->fileHandler), count($this->lines), $this->lines[count($this->lines) - 1] ?? null);
//            $this->lines[] = $line;
//        }
//
//        if (count($this->lines)) {
//            $this->lines[0]->parse();
//        }

    }
}

/** @TODO Разобраться с поведением строк, являющихся полностью продолжением текстового литерала или комментария, начинающегося на другой строке.
 * Возможно, они должны выбывать из связи строк Родитель-Потомок, связывая напрямую своего родителя и своего потомка между собой
 */


abstract class ParseableElement {
    protected $elements = [];
    /**
     * @var null|string
     */
    protected $origLineText = null;

    /**
     * ParseableElement constructor.
     * @param string $lineText
     */
    public function __construct($lineText)
    {
        $this->origLineText = $lineText;
    }

    /**
     *
     */
    public function parse() {

    }
}

class IntermediateElement extends ParseableElement {
    /** @var FileLine */
    private $parentLine;

    /**
     * IntermediateElement constructor.
     * @param string   $lineText
     * @param FileLine $parentLine
     */
    public function __construct($lineText, $parentLine)
    {
        parent::__construct($lineText);
        $this->parentLine = $parentLine;
    }
}

class FileLine extends ParseableElement {
    /**
     * @var bool
     */
    private $isEndAsTextLiteral = false;
    /**
     * @var bool
     */
    private $isEndAsComment = false;

    private $currentQuote = null;
    /**
     * @var int
     */
    private $lineIndex = 0;

    /**
     * @var null| string
     */
    private $lineText = null;

    /** @var null|FileLine */
    private $prevLine = null;

    /** @var null|FileLine */
    private $nextLine = null;
    /**
     * Данная строка является продолжением многострочной конструкции и
     * не содержит своих дочерних элементов
     * @var bool
     */
    private $isContinueOtherLine = false;

    private $clearText;



    /**
     * FileLine constructor.
     * @param string        $lineText
     * @param int           $index
     * @param null|FileLine $prevLine
     */
    public function __construct($lineText, $index, $prevLine = null)
    {
        parent::__construct($lineText);

        $this->lineIndex = $index;
        $this->prevLine = $prevLine;
        if ($prevLine) {
            $prevLine->setNextLine($this);
        }
        $this->getLineWithoutTextLiterals();
        $this->removeComments();
    }

    public function parse() {

        $text = $this->origLineText;
        // Убираем пустые строки - в них ничего нет
        if (trim($text) === '') {
            $this->elements[] = new EmptyLineElement($this->origLineText, $this->Index());
            return;
        }
        if($this->prevLine) {
            if ($this->prevLine->IsEndAsComment()) {
                // Если предыдущая строка заканчивалась на комментарий, нужно в текущей строке поискать его конец
                $multiLineCommentEndPos = mb_strpos($text, '*/');
                if ($multiLineCommentEndPos === false) {
                    // Вся строка является продолжением комментария
                    $this->isEndAsComment = true;
                    $this->prevLine->LastElement()->AddText($text, $this->Index());
                    $this->excludeFromLinesChain();
                    return;
                }
                else {


                    $this->prevLine->LastElement()->AddText(mb_substr($text, 0, $multiLineCommentEndPos + 2), $this->Index());
                    $text = mb_substr($text, $multiLineCommentEndPos + 2);
                    if (mb_strlen($text) === 0) {
                        // Больше в строке нечего разбирать
                        $this->excludeFromLinesChain();
                        return;
                    }
                }
            } else if ($this->prevLine->IsEndAsTextLiteral()) {
                // Если предыдущая строка заканчивалась текстовым литералом, нужно в текущей строке поискать его конец
                $quote = $this->prevLine->LastElement()->Quote();
                $i = 0;
                $l = mb_strlen($text);
                while ($i < $l) {
                    $char = mb_substr($text, $i, 1);
                    $escaped = false;
                    switch ($char) {
                        case $quote:
                            if (!$escaped) {
                                // Конец текстового литерала
                                $this->prevLine->LastElement()->AddText(mb_substr($text, 0, $i), $this->Index());
                                $text = mb_substr($text, $i + 1);
                                if (mb_strlen($text) === 0) {
                                    // Больше в строке нечего разбирать
                                    $this->excludeFromLinesChain();
                                    return;
                                }
                                break 2;
                            }
                            break;
                        case '\\':
                            $escaped = !$escaped;
                            break;
                        default:
                            $escaped = false;
                            break;
                    }
                    $i++;
                }
                // Достигли конца строки, но так и не нашли завершающей кавычки
                $this->prevLine->LastElement()->AddText($text, $this->Index());
                $this->isEndAsTextLiteral = true; // Текстовый литерал мы искали в элементе типа FileLine
                // Т.к. строка является продолжением текстового литерала с предыдущих строк и свое содержимое она отдала их элементам, то исключаем ее из цепочки
                $this->excludeFromLinesChain();
                return;

            }
        }

        $elementsQueue = [];
        // Ищем комментарии - однострочные и начало многострочных, а также текстовые литералы - что попадется первым.
        $i = 0;
        $l = mb_strlen($text);
        outerLoop: while ($i < $l) {
            $char = mb_substr($text, $i, 1);
            switch ($char) {
                case '/':
                    // Проверим следующий символ - возможно, что нашли комментарий
                    if ($i < $l - 1) {
                        switch(mb_substr($text, $i + 1, 1)) {
                            case '*':
                                if ($i > 0) {
                                    $this->elements[] = new IntermediateElement(mb_substr($text, 0, $i), $this);
                                    $text             = mb_substr($text, $i);
                                }
                                if (($i = mb_strpos($text, '*/')) === false) {
                                    $this->isEndAsTextLiteral = true; // Текстовый литерал мы искали в элементе типа FileLine
                                    $this->elements[] = new MultiLineCommentElement($text, $this->Index());
                                    // Т.к. комментарий тянется до конца строки, то из цикла можно полностью выйти. Для этого его условие обратим в false
                                    $i = $l;
                                    goto outerLoop;
                                }
                                else {
                                    $this->elements[] = new SingleLineCommentElement(mb_substr($text, 0, $i + 2), $this->Index());
                                    $text = mb_substr($text, $i + 2);
                                    $i = 0;
                                    $l = mb_strlen($text);
                                    goto outerLoop;
                                }
                            case '/':
                                if ($i > 0) {
                                    $this->elements[] = new IntermediateElement(mb_substr($text, 0, $i), $this);
                                    $text             = mb_substr($text, $i);
                                }
                                // Однострочный комментарий до конца строки
                                $this->elements[] = new SingleLineCommentElement($text, $this->Index());
                                // Т.к. комментарий тянется до конца строки, то из цикла можно полностью выйти. Для этого его условие обратим в false
                                $i = $l;
                                goto outerLoop;
                        }
                    }
                    break;
                case '"':
                case '\'':
                    // Нашли текстовый литерал
                    // Запомним тип кавычки
                    $quote = $char;
                    // Всё, что было в строке перед литералом, выделим в отдельный временный элемент, который распарсим следующим этапом
                    // При этом передаем ссылку на текущий элемент, поскольку текстовые литералы мы ищем исключительно в элемента типа FileLine
                    if ($i > 0) {
                        $this->elements[] = new IntermediateElement(mb_substr($text, 0, $i), $this);
                        $text             = mb_substr($text, $i);
                        $l                = mb_strlen($text);
                        $i = 0;
                    }
                    $i++;
//                    echo '>>> ' . $this->Index() . ' OriginalText ' . $this->OrigText() . PHP_EOL;
//                    echo '>>> ' . $this->Index() . ' Text ' . $text . PHP_EOL;
                    $escaped = false;
                    while ($i < $l) {
                        $char = mb_substr($text, $i, 1);
//                        echo '>>> ' . 'Escaped ' . ($escaped ? 'TRUE' : 'FALSE'). PHP_EOL;
//                        echo '>>> ' . 'Char ' . $char . PHP_EOL;

                        switch ($char) {
                            case $quote:
                                if (!$escaped) {
                                    // Конец текстового литерала
//                                    echo '>>> ' . 'Literal ' . mb_substr($text, 0, $i + 1) . PHP_EOL;
                                    $this->elements[] = new SingleLineTextLiteralElement(mb_substr($text, 0, $i + 1), $this->Index(), $quote);
                                    $text             = mb_substr($text, $i + 1);
//                                    echo '>>> ' . 'TextAL ' . $text . PHP_EOL;
                                    $l                = mb_strlen($text);
                                    $i                = 0;
                                    goto outerLoop;
                                }
                                $escaped = false;
                                break;
                            case '\\':
                                $escaped = !$escaped;
                                break;
                            default:
                                $escaped = false;
                                break;
                        }
                        $i++;
                    }
                    // Достигли конца строки, но так и не нашли завершающей кавычки
                    $this->elements[]          = new MultiLineTextLiteralElement($text, $this->Index(), $quote);
                    $this->isEndAsTextLiteral = true; // Текстовый литерал мы искали в элементе типа FileLine
                    break;
            }
            $i++;
        }
//        $somevar = "some\'var";

        parent::parse();

    }

    public function LastElement() {
        return $this->elements[count($this->elements) - 1];
    }
    
    

    /**
     * @param null|FileLine $nextLine
     */
    public function setNextLine($nextLine) {
        $this->nextLine = $nextLine;
    }

    /**
     * @param null|FileLine $prevLine
     */
    public function setPrevLine($prevLine) {
        $this->prevLine = $prevLine;
    }

    private function getLineWithoutTextLiterals() {
        $i = 0;
        $l = mb_strlen($this->origLineText);
        if ($this->prevLine !== null && $this->prevLine->IsEndAsTextLiteral()) {
            while ($i < $l) {
                $char = mb_substr($this->origLineText, $i, 1);
                if ($char === $this->prevLine->getTextLiteralQuote()) {
                    if ($i > 0) {
                        if (mb_string($this->origLineText, $i - 1, 1) !== '\\') {
                            $i++;
                            break;
                        }
                    }
                    else {
                        $i++;
                        break;
                    }
                }
                $i++;
            }
        }

        $isTextLiteral      = false;
        $this->clearText    = '';
        $this->currentQuote = '';
        while ($i < $l) {
            $char = mb_substr($this->origLineText, $i, 1);
            if ($char !== "'" && $char !== '"') {
                if (!$isTextLiteral) {
                    $this->clearText .= $char;
                }
            }
            else {

                if ($isTextLiteral) {
                    if (mb_substr($this->origLineText, $i - 1, 1) !== '\\' && $this->currentQuote === $char) {
                        $isTextLiteral = false;
                        $this->currentQuote = '';
                    }
                }
                else {
                    $isTextLiteral = true;
                    $this->currentQuote = $char;
                }
            }
            $i++;
        }
        $this->isEndAsTextLiteral = $isTextLiteral;
    }

    private function removeComments() {
        if ($this->prevLine !== null && $this->prevLine->IsEndAsComment()) {
//            echo 'prevLine->IsEndAsComment ';
//            var_dump($this->prevLine->IsEndAsComment());
//            echo PHP_EOL;
            $endOfMultirowComment = mb_strpos($this->clearText, '*/');
            if ($endOfMultirowComment === false) {
                $this->clearText = '';
                $this->isEndAsComment = true;
                return;
            }
        }
        $i = 0;
        $l = mb_strlen($this->clearText) - 1;
        while ($i < $l && mb_substr($this->clearText, $i, 1) === ' ') {
            $i++;
        }
        $l--;
        while ($i < $l) {
            $substr = mb_substr($this->clearText, $i, 2);
            if ($substr === '//') {
                $this->clearText = '';
                break;
            }
            else if ($substr === '/*') {
//                echo 'multiline comment begins in ' . $this->Index() . PHP_EOL;
                $endOfMultirowComment = mb_strpos($this->clearText, '*/', $i + 1);
//                echo 'end of multiline comment in ';
//                var_dump($endOfMultirowComment);
//                echo PHP_EOL;
                if ($endOfMultirowComment === false) {
                    $this->isEndAsComment = true;
                    $this->clearText = '';
                    break;
                }
                else {
                    $this->clearText = mb_substr($this->clearText, 0, $i) + mb_substr($this->clearText, $endOfMultirowComment + 2);
                    $l = mb_strlen($this->clearText);
                }
            }
            $i++;
        }
    }

    /**
     * @return bool
     */
    public function IsEndAsTextLiteral () {
        return $this->isEndAsTextLiteral;
    }

    public function IsEndAsComment() {
        return $this->isEndAsComment;
    }

    public function getTextLiteralQuote() {
        return $this->currentQuote;
    }

    /**
     * @return int
     */
    public function Index() {
        return $this->lineIndex;
    }

    public function testContainsIncorrectFunctionDefinition() {
        $line = $this->clearText;
        $boolRes = false;
        $getNextClosingRoundBracketPos = function($text, $startPos){
            $textLength = mb_strlen($text);
            while ($startPos < $textLength) {
                $char = mb_substr($text, $startPos, 1);
                if ($char === ')') {
                    break;
                }
                $startPos++;
            }
            return $startPos;
        };
        //Функция пока приблизительная и позволяет выявить лишь примитивные случаи ошибок
        $l = mb_strlen($line);
        if (($pos = mb_strpos($line, 'function')) > -1) {
            $boolRes = true;

            $i = $getNextClosingRoundBracketPos($line, $pos + 10) + 1;

            if ($l - $i > 1 && mb_substr($line, $i, 1) === ' ') {
                if (mb_substr($line, $i + 1, 1) === '{') {
                    $boolRes = false;
                } else if ($l - $i >= 10 && mb_substr($line, $i + 1, 3) === 'use') {
                    $i = $getNextClosingRoundBracketPos($line, $i + 3) + 1;
                    if ($l - $i > 1 && mb_substr($line, $i, 1) === ' ') {
                        if (mb_substr($line, $i + 1, 1) === '{') {
                            $boolRes = false;
                        }
                    }
                }
            }
        }

        return $boolRes;
    }

    public function testContainsDebugging() {
        return
            mb_strpos($this->origLineText, 'var_dump') > -1 ||
            mb_strpos($this->origLineText, 'echo')          ||
            mb_strpos($this->origLineText, 'die()');
    }

    public function testContainsIncorrectIfConitionDefinition() {
        $line = $this->clearText;
        $boolRes = false;
        //Функция пока приблизительная и позволяет выявить лишь примитивные случаи ошибок
        $pos = -1;
        if (($pos = mb_strpos($line, 'if')) > -1) {
            if (mb_strpos($line, 'if(', $pos) === $pos) {
                $boolRes = true;
            }
        }

        return $boolRes;

    }

    public function testContainsIncorrectSwitchConitionDefinition() {
        $line = $this->clearText;
        $boolRes = false;
        //Функция пока приблизительная и позволяет выявить лишь примитивные случаи ошибок
        if (($pos = mb_strpos($line, 'switch')) > -1) {
            if (mb_strpos($line, 'switch(', $pos) === $pos) {
                $boolRes = true;
            }
        }

        return $boolRes;

    }

    public function testContainsIncorrectElseDefinition() {
        $line = $this->clearText;
        $boolRes = false;
        //Функция пока приблизительная и позволяет выявить лишь примитивные случаи ошибок
        $pos = -1;
        if (($pos = mb_strpos($line, 'else')) > -1) {
//            $bracketPos = mb_strpos($line, '}');
//            if ($bracketPos === -1 || ($bracketPos > -1 && $bracketPos < $pos)) {
//                $boolRes = true;
//            }
//            $bracketPos = mb_strpos($line, '{', $pos);
//            if ($bracketPos < $pos) {
//                $boolRes = true;
//            }
            if (mb_strpos($line, '} else') !== $pos - 2) {

                $boolRes = true;
            }
        }

        return $boolRes;

    }

    public function testContainsIncorrectCatchDefinition() {
        $line = $this->clearText;
        $boolRes = false;
        //Функция пока приблизительная и позволяет выявить лишь примитивные случаи ошибок
        $pos = -1;
        if (($pos = mb_strpos($line, 'catch')) > -1) {
//            $bracketPos = mb_strpos($line, '}');
//            if ($bracketPos === -1 || ($bracketPos > -1 && $bracketPos < $pos)) {
//                $boolRes = true;
//            }
//            $bracketPos = mb_strpos($line, '{', $pos);
//            if ($bracketPos < $pos) {
//                $boolRes = true;
//            }
            if (mb_strpos($line, '} catch (') !== $pos - 2) {

                $boolRes = true;
            }
        }

        return $boolRes;

    }

    /**
     * Функция проверяет, содержит ли строка определение класса
     *
     * @param $line string
     * @return bool
     */
    public function testContainsIncorrectClassDefinition() {
        $line = $this->clearText;
        $boolRes = false;
        //Функция пока приблизительная и позволяет выявить лишь примитивные случаи ошибок
        $pos = -1;
        if (($pos = mb_strpos($line, 'class')) > -1 && (mb_strpos($line, ':class') !== $pos - 1)) {
            if (mb_strpos($line, '{', $pos) <= $pos) {
                $boolRes = true;
            }
        }

        return $boolRes;
    }

    public function testContainsUnderscoreInVariableNames() {
        return !!preg_match('/\$_*[a-z][a-z0-9]*_+([a-z0-9]+_*)+/u', $this->clearText);
    }

    public function OrigText() {
        return $this->origLineText . PHP_EOL;
    }

    protected function excludeFromLinesChain() {
        if ($this->prevLine) {
            $this->prevLine->setNextLine($this->nextLine);
        }
        if ($this->nextLine) {
            $this->nextLine->setPrevLine($this->prevLine);
        }
    }
}





abstract class ElementAbstract {
    /** @var string */
    private $origText;

    /** @var ElementAbstract[] */
    private $children = [];

    /** @var ElementAbstract */
    private $parent;

    /** @var ElementAbstract */
    private $prev;

    /** @var ElementAbstract */
    private $next;

    /** @var integer */
    private $codeLineIndex;

    /**
     * CodeElement constructor.
     * @param string $text
     */
    public function __construct($text, $codeLineIndex)
    {
        $this->origText = $text;
        $this->codeLineIndex = $codeLineIndex;
    }

    public function parseMethod() {
        foreach ($this->children as $child) {
            $child->parseMethod();
        }
    }

    /**
     * @param $children ElementAbstract
     */
    public function AddChildren($children) {
        $this->children[] = $children;
        $children->parent = $this;
    }

    /**
     * @param ElementAbstract|null $parent
     * @return ElementAbstract|null
     */
    public function Parent($parent = null) {
        $this->parent = $parent;
        return $this->parent;
    }

    /**
     * @param ElementAbstract|null $next
     * @return ElementAbstract|null
     */
    public function NextElement($next = null) {
        $this->next = $next;
        return $this->next;
    }

    /**
     * @param ElementAbstract|null $prev
     * @return ElementAbstract|null
     */
    public function PrevElement($prev = null) {
        $this->prev = $prev;
        return $this->prev;
    }

    /**
     * @return string
     */
    public function Text() {
        return $this->origText;
    }

    public function excludeFromChain() {
        if ($this->prev) {
            $this->prev->NextElement($this->next);
        }
        if ($this->next) {
            $this->next->PrevElement($this->prev);
        }
    }

}

class CodeLineElement extends ElementAbstract {

    public function parseMethod()
    {
        // TODO: Implement parseMethod() method.
        parent::parseMethod();
    }
}

class SpaceElement extends  ElementAbstract {

}

class PhpVariableElement extends ElementAbstract {

}

abstract class CommentElement extends ElementAbstract {
    private $textFragments = [];
    public function __construct(string $text, $codeLineIndex)
    {
        $this->textFragments[$codeLineIndex] = $text;
        parent::__construct($text, $codeLineIndex);
    }

    public function AddText($text, $codeLineIndex) {
        $this->textFragments[$codeLineIndex] = $text;
    }

    public function Text()
    {
        return join(__CLASS__ == MultiLineCommentElement::class ? PHP_EOL : '', $this->textFragments);
    }
}
class SingleLineCommentElement extends CommentElement {

}
class MultiLineCommentElement extends CommentElement {

}
class ClassDefinitionElement extends ElementAbstract {

}

class EmptyLineElement extends ElementAbstract {

}
abstract class TextLiteralElement extends ElementAbstract {
    private $textFragments = [];
    private $quote = null;
    public function __construct(string $text, $codeLineIndex, $quote)
    {
        $this->quote = $quote;
        $this->textFragments[$codeLineIndex] = $text;
        parent::__construct($text, $codeLineIndex);
//        echo __CLASS__ . ' text : ' . $this->Text() . PHP_EOL;
    }

    public function AddText($text, $codeLineIndex) {
        $this->textFragments[$codeLineIndex] = $text;
//        echo __CLASS__ . ' text : ' . $this->Text() . PHP_EOL;
    }

    public function Text()
    {
        return join(__CLASS__ == MultiLineTextLiteralElement::class ? PHP_EOL : '', $this->textFragments);
    }
    public function Quote() {
        return $this->quote;
    }
}
class MultiLineTextLiteralElement extends TextLiteralElement {

}
class SingleLineTextLiteralElement extends TextLiteralElement {

}





class CodeFile {
    /** @var string[] */
    private $textRows = [];

    /** @var FileLine[] */
    private $fileLines = [];

    /**
     * CodeFile constructor.
     * @param string[] $rows
     */
    public function __construct($rows)
    {
        $this->textRows = $rows;
    }

    public function parse() {
        /** @var FileLine $currentFileLine */
        $currentFileLine = null;
        /** @var FileLine $prevFileLine */
        $prevFileLine = null;
        // Сначала просто создадим список взаимосвязанных элементов типа FileLine
        foreach ($this->textRows as $textRow) {
            $currentFileLine = new FileLine($textRow, count($this->fileLines), $this->fileLines[count($this->fileLines) - 1] ?? null);
            $this->fileLines[] = $currentFileLine;
            if ($prevFileLine) {
                $prevFileLine->setNextLine($currentFileLine);
            }
            $prevFileLine = $currentFileLine;

        }

        foreach ($this->fileLines as $fileLine) {
            $fileLine->parse();
        }


    }
}

$lines = file('ADTest.php');
$cf = new CodeFile(array_slice($lines, 265, 100));
$cf->parse();