// Exemplo de erro semântico 1: referência a uma API que não foi declarada.
// O programa é sintaticamente correto (o Parser o aceita), mas a cláusula "to"
// aponta para a API Crm, que não existe. O Analyzer informa quais APIs existem.
// Erro esperado: Erro semântico na linha 13, coluna 8: API 'Crm' não declarada na cláusula 'to' da integração 'copiarUsuarios'; APIs declaradas: Users

api Users {
    base "https://users.example.com/api";
    endpoint listUsers GET "/users";
}

integration copiarUsuarios {
    from Users.listUsers;
    to Crm.createContact;
}
