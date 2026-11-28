// Exemplo de erro semântico 3: vários erros no mesmo arquivo.
// Ao contrário do Parser, o Analyzer não precisa de recuperação em modo pânico:
// ele percorre o programa inteiro e relata todos os erros, em ordem de posição.
// Aqui: endpoint repetido, referência que não começa pelo alias "pedido" e
// chamada de função inexistente.
// Erro esperado: Erro semântico na linha 14, coluna 5: endpoint 'listarPedidos' já declarado na API 'Loja' (linha 13, coluna 5)
// Erro esperado: Erro semântico na linha 25, coluna 10: a referência 'order.status' deve começar pelo alias 'pedido' da integração 'faturarPedidos'
// Erro esperado: Erro semântico na linha 27, coluna 27: função desconhecida 'capitalize'; funções disponíveis: env

api Loja {
    base "https://loja.example.com/api";
    header "Authorization" = "Bearer " + env("LOJA_TOKEN");
    endpoint listarPedidos GET "/orders";
    endpoint listarPedidos GET "/orders/recent";
}

api Financeiro {
    base "https://financeiro.example.com/v2";
    endpoint registrarRecebivel POST "/receivables";
}

integration faturarPedidos {
    from Loja.listarPedidos as pedido;
    to Financeiro.registrarRecebivel;
    when order.status == "paid";
    map {
        cliente: string = capitalize(pedido.customerName);
        total: float = pedido.total;
    }
}
