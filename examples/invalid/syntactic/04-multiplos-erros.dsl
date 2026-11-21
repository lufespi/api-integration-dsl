// Exemplo de erro sintático 4: vários erros no mesmo arquivo.
// Após cada erro, o Parser se recupera em modo pânico (descarta tokens até a
// próxima declaração "api" ou "integration") e continua a análise, de modo
// que erros independentes são relatados juntos em uma única execução.
// A API Crm, entre os dois erros, é analisada normalmente.
// Erro esperado: Erro sintático na linha 11, coluna 24: esperado método HTTP (GET, POST, PUT, PATCH ou DELETE, em maiúsculas) no endpoint 'listUsers', encontrado: identificador 'get'
// Erro esperado: Erro sintático na linha 23, coluna 15: esperado tipo de dado (string, int, float ou bool) para o campo 'nome', encontrado: identificador 'text'

api Users {
    base "https://users.example.com/api";
    endpoint listUsers get "/users";
}

api Crm {
    base "https://crm.example.com/v1";
    endpoint createContact POST "/contacts";
}

integration syncUsers {
    from Users.listUsers as user;
    to Crm.createContact;
    map {
        nome: text = user.name;
    }
}
