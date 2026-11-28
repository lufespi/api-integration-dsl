// Fixture dos testes da CLI: programa léxica, sintática e semanticamente
// válido que o Gerador rejeita, porque a origem de uma integração deve ser um
// endpoint GET nesta versão (restrição da geração, docs/generator.md).
// Erro esperado: Erro de geração na linha 17, coluna 10: o endpoint 'Pedidos.criarPedido' usa POST, que não é suportado como origem; use GET

api Pedidos {
    base "https://pedidos.example.com/api";
    endpoint criarPedido POST "/orders";
}

api Financeiro {
    base "https://financeiro.example.com/v2";
    endpoint registrarRecebivel POST "/receivables";
}

integration faturar {
    from Pedidos.criarPedido;
    to Financeiro.registrarRecebivel;
}
