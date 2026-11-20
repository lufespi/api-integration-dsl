// Exemplo de erro sintático 3: operadores relacionais encadeados.
// Os operadores relacionais são não associativos na gramática: a expressão
// "18 <= cliente.idade < 65" não pertence à linguagem. A forma correta é
// "cliente.idade >= 18 && cliente.idade < 65".
// Erro esperado: Erro sintático na linha 20, coluna 30: operador '<' inesperado: operadores relacionais não são associativos; use parênteses, por exemplo (a <= b) < c

api Clientes {
    base "https://clientes.example.com";
    endpoint listar GET "/clientes";
}

api Seguros {
    base "https://seguros.example.com";
    endpoint cotar POST "/cotacoes";
}

integration cotarSeguros {
    from Clientes.listar as cliente;
    to Seguros.cotar;
    when 18 <= cliente.idade < 65;
}
