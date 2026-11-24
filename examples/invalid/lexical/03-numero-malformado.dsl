// Exemplo de erro léxico 3: número com dois pontos decimais.
// Erro esperado: Erro léxico na linha 17, coluna 26: número malformado '10.5.3'

api Loja {
    base "https://loja.example.com/api";
    endpoint listarPedidos GET "/orders";
}

api Financeiro {
    base "https://financeiro.example.com/v2";
    endpoint registrarRecebivel POST "/receivables";
}

integration faturarPedidos {
    from Loja.listarPedidos as pedido;
    to Financeiro.registrarRecebivel;
    when pedido.total >= 10.5.3;
}
