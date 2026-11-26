// Exemplo de erro léxico 1: caractere que não pertence à linguagem.
// A referência a um endpoint usa ponto (Api.endpoint). O '@' não inicia
// nenhum token, então o erro aparece antes da análise sintática.
// Erro esperado: Erro léxico na linha 18, coluna 11: caractere inválido '@'

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
    to Crm@createContact;
}
