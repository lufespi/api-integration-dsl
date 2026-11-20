<?php

declare(strict_types=1);

namespace App\Parser;

/**
 * Um erro sintático individual: mensagem e posição no arquivo-fonte.
 *
 * O Parser pode encontrar vários erros em uma mesma análise (recuperação em
 * modo pânico); cada um é registrado como um SyntaxError e todos são
 * entregues juntos em uma SyntaxException.
 */
final readonly class SyntaxError
{
    /**
     * @param string $message Descrição do problema, sem a posição.
     * @param int    $line    Linha do token onde o erro foi detectado.
     * @param int    $column  Coluna do token onde o erro foi detectado.
     */
    public function __construct(
        public string $message,
        public int $line,
        public int $column,
    ) {
    }

    /**
     * Mensagem completa no formato padrão do compilador:
     * "Erro sintático na linha L, coluna C: <mensagem>".
     *
     * @return string
     */
    public function format(): string
    {
        return sprintf('Erro sintático na linha %d, coluna %d: %s', $this->line, $this->column, $this->message);
    }
}
