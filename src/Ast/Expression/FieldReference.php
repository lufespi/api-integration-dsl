<?php

declare(strict_types=1);

namespace App\Ast\Expression;

/**
 * Referência a um valor por caminho de identificadores separados por ponto
 * (ex.: user, user.age, pedido.cliente.email).
 *
 * Normalmente o primeiro segmento é o alias declarado em "from ... as alias"
 * e os demais são campos do registro JSON recebido da origem. Validar isso é
 * uma verificação semântica (docs/ast-contract.md, P2).
 *
 * Produção correspondente: primario → IDENT acesso (docs/grammar.md).
 */
final readonly class FieldReference implements Expression
{
    /**
     * @param list<string> $path   Segmentos do caminho, na ordem (ao menos um).
     * @param int          $line   Linha do primeiro identificador.
     * @param int          $column Coluna do primeiro identificador.
     */
    public function __construct(
        public array $path,
        public int $line,
        public int $column,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'FieldReference',
            'path' => $this->path,
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
