<?php

declare(strict_types=1);

namespace App\Semantic;

/**
 * Um erro semântico individual: mensagem e posição no arquivo-fonte.
 *
 * O Analyzer não para no primeiro problema: cada violação é registrada como
 * um SemanticError e todos são entregues juntos em uma SemanticException.
 */
final readonly class SemanticError
{
    /**
     * @param string $message Descrição do problema, sem a posição.
     * @param int    $line    Linha do elemento do programa onde o erro foi detectado.
     * @param int    $column  Coluna do elemento do programa onde o erro foi detectado.
     */
    public function __construct(
        public string $message,
        public int $line,
        public int $column,
    ) {
    }

    /**
     * Mensagem completa no formato padrão do compilador:
     * "Erro semântico na linha L, coluna C: <mensagem>".
     *
     * @return string
     */
    public function format(): string
    {
        return sprintf('Erro semântico na linha %d, coluna %d: %s', $this->line, $this->column, $this->message);
    }
}
