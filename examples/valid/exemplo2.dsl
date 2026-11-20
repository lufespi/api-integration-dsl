// Exemplo 2: cabeçalhos e estrutura condicional.
// Encaminha ao sistema financeiro apenas os pedidos pagos de valor relevante
// (ou marcados como prioritários) que não foram cancelados.
//
// Demonstra: cabeçalhos com expressões e chamada de função (env), alias do
// registro de origem ("as pedido"), cláusula condicional "when" com
// operadores relacionais, de igualdade e lógicos, parênteses e literais de
// texto, decimal e booleano.

api Loja {
    base "https://loja.example.com/api/";   // a barra final é removida pelo Parser
    header "Accept" = "application/json";
    header "Authorization" = "Bearer " + env("LOJA_TOKEN");
    endpoint listarPedidos GET "/orders";
    endpoint cancelarPedido DELETE "/orders/{id}";
}

api Financeiro {
    base "https://financeiro.example.com/v2";
    header "X-Api-Key" = env("FINANCEIRO_KEY");
    endpoint registrarRecebivel POST "/receivables";
}

integration faturarPedidos {
    from Loja.listarPedidos as pedido;
    to Financeiro.registrarRecebivel;
    when pedido.status == "paid"
        && (pedido.total >= 100.0 || pedido.priority == true)
        && !pedido.cancelled;
}
