// Exemplo 1: integração mínima.
// Lista os usuários da API de origem e envia cada registro, sem
// transformação, para o CRM. Corresponde à AST de exemplo de
// docs/grammar.md (seção 10).

api Users {
    base "https://users.example.com/api";
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
