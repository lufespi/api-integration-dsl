// Exemplo de erro sintático 2: cláusula obrigatória ausente.
// Toda integração deve declarar "from" e, em seguida, "to". Aqui a cláusula
// "to" foi omitida e o Parser encontra "when" em seu lugar.
// Erro esperado: Erro sintático na linha 18, coluna 5: esperado 'to' após a cláusula 'from' da integração 'faturarPedidos' (endpoint de destino), encontrado: palavra reservada 'when'

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
    when pedido.status == "paid";
}
