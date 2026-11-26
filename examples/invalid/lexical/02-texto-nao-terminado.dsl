// Exemplo de erro léxico 2: texto sem as aspas de fechamento.
// Textos não atravessam quebra de linha. O erro aponta a aspa de abertura.
// Erro esperado: Erro léxico na linha 6, coluna 10: texto não terminado: faltou fechar as aspas

api Users {
    base "https://users.example.com/api;
    endpoint listUsers GET "/users";
}

api Crm {
    base "https://crm.example.com/v1";
    endpoint createContact POST "/contacts";
}

integration syncUsers {
    from Users.listUsers;
    to Crm.createContact;
}
