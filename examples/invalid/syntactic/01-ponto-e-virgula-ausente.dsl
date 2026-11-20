// Exemplo de erro sintático 1: ';' ausente ao final de uma declaração.
// Erro esperado: Erro sintático na linha 7, coluna 36: esperado ';' ao final da declaração do endpoint 'listUsers', encontrado: palavra reservada 'endpoint'
// O erro é posicionado logo após "/users", onde o ';' deveria estar.

api Users {
    base "https://users.example.com/api";
    endpoint listUsers GET "/users"
    endpoint getUser GET "/users/{id}";
}

api Crm {
    base "https://crm.example.com/v1";
    endpoint createContact POST "/contacts";
}

integration syncUsers {
    from Users.listUsers;
    to Crm.createContact;
}
