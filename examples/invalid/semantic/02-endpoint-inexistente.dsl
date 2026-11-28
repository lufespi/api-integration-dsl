// Exemplo de erro semântico 2: a API existe, mas não declara o endpoint citado.
// "listUser" é um erro de digitação de "listUsers". O Analyzer lista os endpoints
// declarados na API para ajudar a corrigir.
// Erro esperado: Erro semântico na linha 18, coluna 10: a API 'Users' não declara o endpoint 'listUser' (cláusula 'from' da integração 'copiarUsuarios'); endpoints de 'Users': listUsers, getUser

api Users {
    base "https://users.example.com/api";
    endpoint listUsers GET "/users";
    endpoint getUser GET "/users/{id}";
}

api Crm {
    base "https://crm.example.com/v1";
    endpoint createContact POST "/contacts";
}

integration copiarUsuarios {
    from Users.listUser as user;
    to Crm.createContact;
}
